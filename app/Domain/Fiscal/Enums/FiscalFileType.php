<?php

namespace App\Domain\Fiscal\Enums;

enum FiscalFileType: string
{
    case Xml = 'xml';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Xml => 'application/xml',
            self::Pdf => 'application/pdf',
        };
    }
}
