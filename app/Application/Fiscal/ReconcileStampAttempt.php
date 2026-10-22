<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Exceptions\InvalidStampedCfdi;
use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use App\Domain\Fiscal\Contracts\PacStampRecoveryGateway;
use App\Domain\Fiscal\Dto\PacStampResult;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Domain\Fiscal\Enums\PacRecoveryOutcome;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Infrastructure\Fiscal\Xml\StampedCfdiVerifier;
use App\Models\FiscalStampAttempt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class ReconcileStampAttempt
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PacAccessTokenResolver $tokens,
        private readonly PacStampRecoveryGateway $recovery,
        private readonly PrivateStampXmlStore $xmlStore,
        private readonly StampedCfdiVerifier $verifier,
        private readonly MarkStampAttemptUnknown $markUnknown,
        private readonly CompleteStampAttempt $complete,
    ) {}

    public function execute(
        FiscalStampAttempt $input,
        ?string $candidateUuid = null
    ): FiscalStampAttempt {
        $companyId = $this->context->id();

        $attempt = FiscalStampAttempt::query()
            ->where('company_id', $companyId)
            ->whereKey($input->id)
            ->firstOrFail();

        /*
         * Una factura ya confirmada no necesita
         * una nueva consulta ni otro timbrado.
         */
        if (
            $attempt->status
            === FiscalStampAttemptStatus::Stamped
        ) {
            return $attempt;
        }

        if (! in_array(
            $attempt->status,
            [
                FiscalStampAttemptStatus::Dispatching,
                FiscalStampAttemptStatus::Unknown,
            ],
            true
        )) {
            throw new ConflictHttpException(
                'Attempt is not awaiting reconciliation.'
            );
        }

        /*
         * Todavía no hay UUID conocido.
         *
         * No inventamos una consulta por
         * CustomID y no reenviamos el CFDI.
         */
        if ($candidateUuid === null) {
            return $this->ensureUnknown(
                $attempt
            );
        }

        if (
            ! preg_match(
                '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD',
                $candidateUuid
            )
        ) {
            throw new ConflictHttpException(
                'Invalid candidate UUID.'
            );
        }

        if (
            $attempt->provider !== 'sw_sapien'
            || $attempt->preflight_passed_at === null
        ) {
            throw new ConflictHttpException(
                'Attempt is not eligible for PAC recovery.'
            );
        }

        try {
            if (! $this->xmlStore->verify($attempt)) {
                return $this->ensureUnknown($attempt);
            }

            $originalXml = Storage::disk(
                $attempt->xml_disk
            )->get(
                $attempt->xml_path
            );

            if (
                ! is_string($originalXml)
                || ! hash_equals(
                    $attempt->xml_sha256,
                    hash('sha256', $originalXml)
                )
                || strlen($originalXml) !== $attempt->xml_size
            ) {
                return $this->ensureUnknown($attempt);
            }
        } catch (Throwable) {
            /*
            * Si falla el almacenamiento,
            * no confirmamos el timbrado.
            */
            return $this->ensureUnknown($attempt);
        }

        /*
         * La consulta se realiza con las
         * credenciales de la empresa actual.
         */
        try {
            $token = $this->tokens->resolveForCompany(
                $companyId
            );

            $result = $this->recovery->recoverByUuid(
                $candidateUuid,
                $token
            );
        } catch (Throwable $exception) {
            /*
             * No propagamos información
             * sensible de autenticación ni
             * de transporte.
             */
            return $this->ensureUnknown(
                $attempt
            );
        }

        if ($result->outcome !== PacRecoveryOutcome::Found) {
            /*
             * NotFound NO autoriza
             * un segundo timbrado.
             */
            return $this->ensureUnknown(
                $attempt
            );
        }

        if (
            $result->uuid === null
            || $result->stampedXml === null
            || strcasecmp(
                $result->uuid,
                $candidateUuid
            ) !== 0
        ) {
            return $this->ensureUnknown(
                $attempt
            );
        }

        /*
         * Verificamos el XML ANTES de
         * intentar confirmar el timbrado.
         */
        try {
            $this->verifier->verify(
                $originalXml,
                $result->stampedXml,
                $result->uuid
            );
        } catch (InvalidStampedCfdi) {
            return $this->ensureUnknown(
                $attempt
            );
        }

        /*
         * CompleteStampAttempt ya implementa:
         * - locks
         * - persistencia del XML certificado
         * - UUID
         * - estados de documento y factura
         * - idempotencia
         */
        return $this->complete->execute(
            $attempt,
            PacStampResult::received(
                $result->uuid,
                $result->stampedXml
            )
        );
    }

    private function ensureUnknown(
        FiscalStampAttempt $attempt
    ): FiscalStampAttempt {
        return $this->markUnknown->execute(
            $attempt
        );
    }
}
