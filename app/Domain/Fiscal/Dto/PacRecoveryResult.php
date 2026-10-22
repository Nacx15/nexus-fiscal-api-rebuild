<?php

namespace App\Domain\Fiscal\Dto;

use App\Domain\Fiscal\Enums\PacRecoveryOutcome;

final readonly class PacRecoveryResult
{
    private function __construct(
        public PacRecoveryOutcome $outcome,
        public ?string $uuid = null,
        public ?string $stampedXml = null,
        public ?string $reason = null,
    ) {}

    public static function found(
        string $uuid,
        string $stampedXml
    ): self {
        return new self(
            outcome: PacRecoveryOutcome::Found,
            uuid: $uuid,
            stampedXml: $stampedXml,
        );
    }

    public static function notFound(): self
    {
        return new self(
            outcome: PacRecoveryOutcome::NotFound
        );
    }

    public static function unknown(
        string $reason = 'PAC_RECOVERY_UNCERTAIN'
    ): self {
        return new self(
            outcome: PacRecoveryOutcome::Unknown,
            reason: $reason
        );
    }
}
