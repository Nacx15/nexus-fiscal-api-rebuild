<?php

namespace App\Domain\Fiscal\Enums;

enum PacRecoveryOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Unknown = 'unknown';
}
