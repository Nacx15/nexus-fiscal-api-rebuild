<?php

namespace App\Domain\Company\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Billing = 'billing';
    case Viewer = 'viewer';
}