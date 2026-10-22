<?php

namespace App\Domain\Fiscal\Cfdi;

final readonly class VerifiedStamp
{
    public function __construct(
        public string $uuid,
        public string $stampedAt,
        public string $issuerCertificateNumber,
        public string $satCertificateNumber,
        public string $cfdiSeal,
        public string $satSeal,
    ) {}
}
