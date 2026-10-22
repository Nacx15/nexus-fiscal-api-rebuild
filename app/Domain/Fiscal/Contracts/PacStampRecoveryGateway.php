<?php

namespace App\Domain\Fiscal\Contracts;

use App\Domain\Fiscal\Dto\PacRecoveryResult;

interface PacStampRecoveryGateway
{
    public function recoverByUuid(
        string $uuid,
        string $token
    ): PacRecoveryResult;
}
