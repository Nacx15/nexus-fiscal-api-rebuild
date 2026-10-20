<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\FiscalFileType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentFile extends Model
{
    protected $fillable = [
        'fiscal_document_id',
        'file_type',
        'disk',
        'path',
        'mime_type',
        'size',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'file_type' => FiscalFileType::class,
            'size' => 'integer',
        ];
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(
            FiscalDocument::class
        );
    }
}
