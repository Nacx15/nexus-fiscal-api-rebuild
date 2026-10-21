<?php

namespace App\Domain\Fiscal\Cfdi\Exceptions;

use RuntimeException;

final class InvalidCfdiXml extends RuntimeException
{
    public function __construct(
        public readonly array $issues = []
    ) {
        parent::__construct(
            'The CFDI XML did not pass validation.'
        );
    }
}
