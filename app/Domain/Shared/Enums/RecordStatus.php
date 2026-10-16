<?php

namespace App\Domain\Shared\Enums;

enum RecordStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}