<?php

namespace App\Domain\Fiscal\Contracts;

interface PacAccessTokenResolver
{
    public function resolveForCompany(
        int $companyId
    ): string;
}
