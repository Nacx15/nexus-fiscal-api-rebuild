<?php

namespace App\Domain\Fiscal\Contracts;

use App\Domain\Fiscal\Dto\PacStampResult;

interface PacStampingGateway
{
    public function stamp(
        string $signedXml,
        string $customId,
        string $token
    ): PacStampResult;
}
