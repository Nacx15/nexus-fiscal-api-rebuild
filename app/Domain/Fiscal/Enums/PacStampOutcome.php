<?php

namespace App\Domain\Fiscal\Enums;

enum PacStampOutcome: string
{
    case Received = 'received';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
