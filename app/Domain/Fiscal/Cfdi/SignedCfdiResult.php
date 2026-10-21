<?php

namespace App\Domain\Fiscal\Cfdi;

final readonly class SignedCfdiResult
{
    public function __construct(
        public string $xml,
        public string $originalString,
        public string $certificateNumber,
        public string $seal,
    ) {}
}
