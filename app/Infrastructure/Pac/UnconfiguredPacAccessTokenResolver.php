<?php

namespace App\Infrastructure\Pac;

use App\Domain\Fiscal\Contracts\PacAccessTokenResolver;
use RuntimeException;

final class UnconfiguredPacAccessTokenResolver implements PacAccessTokenResolver
{
    public function resolveForCompany(
        int $companyId
    ): string {
        throw new RuntimeException(
            'PAC credentials resolver is not configured.'
        );
    }
}
