<?php

namespace App\Domain\Fiscal\Enums;

enum FiscalDocumentStatus: string
{
    case Pending = 'pending';
    case Stamping = 'stamping';
    case Stamped = 'stamped';
    case StampFailed = 'stamp_failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';
}
