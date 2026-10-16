<?php

namespace App\Domain\Company\Enums;

enum CompanyMembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}