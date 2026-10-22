<?php

namespace App\Domain\Fiscal\Enums;

enum FiscalStampAuditAction: string
{
    case Reserved = 'reserved';
    case Prepared = 'prepared';
    case DispatchStarted = 'dispatch_started';
    case BecameUnknown = 'became_unknown';
    case Stamped = 'stamped';
    case Rejected = 'rejected';
}
