<?php

namespace App\Domain\Fiscal\Enums;

enum FiscalStampAttemptStatus: string
{
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Dispatching = 'dispatching';
    case Stamped = 'stamped';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
