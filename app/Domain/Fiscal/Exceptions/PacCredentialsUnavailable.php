<?php

namespace App\Domain\Fiscal\Exceptions;

use RuntimeException;

final class PacCredentialsUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'PAC credentials are unavailable for this operation.'
        );
    }
}
