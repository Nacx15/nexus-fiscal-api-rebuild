<?php

namespace App\Domain\Fiscal\Enums;

enum FiscalDocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
