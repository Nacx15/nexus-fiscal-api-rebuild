<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Contracts\PacStampingGateway;
use App\Domain\Fiscal\Dto\PacStampResult;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\FiscalStampAttempt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class DispatchPreparedStampAttempt
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PacAccessTokenResolver $tokens,
        private readonly PacStampingGateway $gateway,
        private readonly PrivateStampXmlStore $xmlStore,
        private readonly MarkStampAttemptDispatching $markDispatching,
        private readonly CompleteStampAttempt $complete,
    ) {}

    public function execute(
        FiscalStampAttempt $input
    ): FiscalStampAttempt {
        /*
         * Un interruptor administrativo impide
         * el despacho accidental.
         */
        if (! config('fiscal.pac.dispatch_enabled', false)) {
            throw new ConflictHttpException(
                'PAC dispatch is disabled.'
            );
        }

        $companyId = $this->context->id();

        /*
         * Nunca confiamos únicamente en el
         * modelo recibido como argumento.
         */
        $attempt = FiscalStampAttempt::query()
            ->where('company_id', $companyId)
            ->whereKey($input->id)
            ->firstOrFail();

        /*
         * Bloquea intentos ya enviados,
         * completados, rechazados o inciertos.
         */
        if (
            $attempt->status
                !== FiscalStampAttemptStatus::Prepared
            || $attempt->provider !== 'sw_sapien'
            || $attempt->preflight_passed_at === null
        ) {
            throw new ConflictHttpException(
                'Stamp attempt is not prepared for dispatch.'
            );
        }

        /*
         * Antes de marcar dispatching podemos
         * fallar sin riesgo de contacto con PAC.
         */
        if (! $this->xmlStore->verify($attempt)) {
            throw new ConflictHttpException(
                'Prepared XML is unavailable or corrupted.'
            );
        }

        $signedXml = Storage::disk(
            $attempt->xml_disk
        )->get($attempt->xml_path);

        /*
         * Confirmamos la integridad de los
         * bytes que realmente enviaremos.
         */
        if (
            ! is_string($signedXml)
            || ! hash_equals(
                $attempt->xml_sha256,
                hash('sha256', $signedXml)
            )
            || strlen($signedXml) !== $attempt->xml_size
        ) {
            throw new ConflictHttpException(
                'Prepared XML changed while being read.'
            );
        }

        /*
         * El token se obtiene exclusivamente
         * del backend y para la empresa actual.
         *
         * Si falla, todavía NO hemos cambiado
         * el intento a dispatching.
         */
        $token = $this->tokens->resolveForCompany(
            $companyId
        );

        if (trim($token) === '') {
            throw new RuntimeException(
                'PAC token is unavailable.'
            );
        }

        /*
         * Esta Action utiliza lockForUpdate.
         *
         * Sólo una solicitud concurrente podrá
         * completar la transición a dispatching.
         *
         * El commit ocurre ANTES de la red.
         */
        $attempt = $this->markDispatching->execute(
            $attempt
        );

        /*
         * A partir de aquí el PAC pudo recibir
         * la solicitud. NO habrá retry HTTP.
         */
        try {
            $result = $this->gateway->stamp(
                $signedXml,
                $attempt->public_id,
                $token
            );
        } catch (Throwable $exception) {
            /*
             * No sabemos si la solicitud llegó
             * al proveedor.
             *
             * Una excepción de transporte,
             * timeout o ejecución se trata
             * siempre como resultado incierto.
             */
            report($exception);

            $result = PacStampResult::unknown(
                'PAC_GATEWAY_EXCEPTION'
            );
        }

        /*
         * Nunca interpretamos aquí el XML
         * timbrado ni actualizamos Invoice.
         *
         * Esa responsabilidad corresponde
         * exclusivamente a CompleteStampAttempt.
         */
        return $this->complete->execute(
            $attempt,
            $result
        );
    }
}
