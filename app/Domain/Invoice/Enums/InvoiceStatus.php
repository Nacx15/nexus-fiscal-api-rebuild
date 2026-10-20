<?php

namespace App\Domain\Invoice\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Stamping = 'stamping';
    case Stamped = 'stamped';
    case StampFailed = 'stamp_failed';
    case CancellationPending = 'cancellation_pending';
    case Cancelled = 'cancelled';
}
