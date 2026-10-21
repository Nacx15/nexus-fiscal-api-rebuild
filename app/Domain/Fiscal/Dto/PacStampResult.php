<?php

namespace App\Domain\Fiscal\Dto;

use App\Domain\Fiscal\Enums\PacStampOutcome;

final readonly class PacStampResult
{
    public function __construct(
        public PacStampOutcome $outcome,
        public ?string $uuid = null,
        public ?string $stampedXml = null,
        public ?string $code = null,
    ) {}

    public static function received(
        string $uuid,
        string $xml
    ): self {
        return new self(
            PacStampOutcome::Received,
            uuid: $uuid,
            stampedXml: $xml,
        );
    }

    public static function rejected(
        string $code
    ): self {
        return new self(
            PacStampOutcome::Rejected,
            code: $code,
        );
    }

    public static function unknown(
        string $code
    ): self {
        return new self(
            PacStampOutcome::Unknown,
            code: $code,
        );
    }
}
