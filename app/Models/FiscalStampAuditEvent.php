<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\FiscalStampAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalStampAuditEvent extends Model
{
    protected $table = 'fiscal_stamp_audit_events';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'fiscal_stamp_attempt_id',
        'event_type',
        'previous_status',
        'current_status',
        'xml_sha256',
        'uuid',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => FiscalStampAuditAction::class,
            'occurred_at' => 'immutable_datetime',
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

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(
            FiscalStampAttempt::class,
            'fiscal_stamp_attempt_id'
        );
    }
}
