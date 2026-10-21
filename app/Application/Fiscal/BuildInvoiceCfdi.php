<?php

namespace App\Application\Fiscal;

use App\Application\Company\CompanyContext;
use App\Domain\Fiscal\Cfdi\Xml\Cfdi40XmlBuilder;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;

final class BuildInvoiceCfdi
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly Cfdi40XmlBuilder $builder
    ) {}

    public function execute(
        FiscalDocument $document,
        ?CarbonImmutable $issuedAt = null
    ): string {
        /*
         * Reconsultamos utilizando la empresa
         * activa. No confiamos sólo en el modelo
         * recibido como parámetro.
         */
        $document = FiscalDocument::query()
            ->where(
                'company_id',
                $this->companyContext->id()
            )
            ->whereKey($document->id)
            ->with('company')
            ->firstOrFail();

        $issuedAt ??= CarbonImmutable::now(
            $document->company->timezone
        );

        return $this->builder->build(
            $document,
            $issuedAt
        );
    }
}
