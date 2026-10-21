<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Infrastructure\Fiscal\Certificates\CsdCredentialLoader;
use App\Infrastructure\Fiscal\Storage\PrivateStampXmlStore;
use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalStampAttempt;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PrepareSignedStampAttempt
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BeginStampAttempt $begin,
        private readonly BuildSignedInvoiceCfdi $builder,
        private readonly CsdCredentialLoader $credentialLoader,
        private readonly StorePreparedStampXml $store,
        private readonly PrivateStampXmlStore $storage
    ) {}

    public function execute(
        FiscalDocument $document,
        FiscalCertificate $certificate,
        CarbonImmutable $issuedAt
    ): FiscalStampAttempt {
        $companyId = $this->companyContext->id();

        $document = FiscalDocument::query()
            ->where('company_id', $companyId)
            ->whereKey($document->id)
            ->firstOrFail();

        /*
         * Idempotencia y bloqueo transaccional.
         */
        $attempt = $this->begin->execute(
            $document,
            $issuedAt
        );

        /*
         * Si ya se preparó el XML, no firmamos
         * de nuevo ni cambiamos su contenido.
         */
        if (
            $attempt->status
            === FiscalStampAttemptStatus::Prepared
        ) {
            if (! $this->storage->verify($attempt)) {
                throw new ConflictHttpException(
                    'Previously prepared XML is unavailable.'
                );
            }

            return $attempt;
        }

        /*
         * Recuperamos la fecha congelada
         * del primer intento, no la fecha
         * actual del servidor.
         */
        $frozenDate = CarbonImmutable::parse(
            $attempt->cfdi_date,
            $attempt->cfdi_timezone
        );

        /*
         * Motor que ya construimos:
         * XML + XSLT + firma + prevalidación.
         */
        $signed = $this->builder->execute(
            $document,
            $certificate,
            $frozenDate
        );

        /*
         * Recuperamos la credencial para
         * comprobar nuevamente el XML que
         * se guardará de forma permanente.
         */
        $credential = $this->credentialLoader->load(
            $certificate,
            $document->issuer_rfc,
            $frozenDate
        );

        return $this->store->execute(
            $attempt,
            $signed,
            $credential
        );
    }
}
