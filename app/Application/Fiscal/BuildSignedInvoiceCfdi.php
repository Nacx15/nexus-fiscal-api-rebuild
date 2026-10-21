<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\SignedCfdiResult;
use App\Infrastructure\Fiscal\Certificates\CsdCredentialLoader;
use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;

final class BuildSignedInvoiceCfdi
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly BuildInvoiceCfdi $xmlBuilder,
        private readonly CsdCredentialLoader $credentialLoader,
        private readonly SignedCfdiXmlService $xmlSigner,
        private readonly ValidateSignedCfdiForStamping $validator,
    ) {}

    public function execute(
        FiscalDocument $document,
        FiscalCertificate $certificate,
        CarbonImmutable $issuedAt
    ): SignedCfdiResult {
        /*
         * No confiamos únicamente en el
         * documento recibido.
         */
        $document = FiscalDocument::query()
            ->where(
                'company_id',
                $this->companyContext->id()
            )
            ->whereKey($document->id)
            ->firstOrFail();

        /*
         * El loader valida el tenant, estado,
         * RFC, vigencia, número de serie y
         * correspondencia entre CSD y llave.
         */
        $credential = $this->credentialLoader->load(
            $certificate,
            $document->issuer_rfc,
            $issuedAt
        );

        /*
         * El constructor existente vuelve
         * a verificar que el documento esté
         * pending y la factura esté ready.
         */
        $unsignedXml = $this->xmlBuilder->execute(
            $document,
            $issuedAt
        );

        return $this->xmlSigner->sign(
            $unsignedXml,
            $credential
        );

        $this->validator->execute(
            $result,
            $credential
        );

        return $result;
    }
}
