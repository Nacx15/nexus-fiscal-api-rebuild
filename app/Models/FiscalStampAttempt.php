<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\FiscalStampAttemptStatus;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalStampAttempt extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'attempt_number',
        'status',
        'cfdi_date',
        'cfdi_timezone',
        'xml_disk',
        'xml_path',
        'xml_sha256',
        'xml_size',
        'provider',
        'provider_reference',
        'failure_code',
        'failure_message',
        'dispatch_started_at',
        'completed_at',
        'preflight_passed_at',
    ];

    protected $attributes = [
        'status' => 'preparing',
        'provider' => 'sw_sapien',
    ];

    protected function casts(): array
    {
        return [
            'status' => FiscalStampAttemptStatus::class,
            'attempt_number' => 'integer',
            'xml_size' => 'integer',
            'dispatch_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'preflight_passed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
