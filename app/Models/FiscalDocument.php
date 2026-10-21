<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\FiscalDocumentStatus;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FiscalDocument extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'company_id',
        'cfdi_type',
        'version',
        'uuid',
        'status',

        'issuer_rfc',
        'issuer_name',
        'issuer_tax_regime',
        'issuer_postal_code',

        'receiver_rfc',
        'receiver_name',
        'receiver_tax_regime',
        'receiver_postal_code',

        'currency',
        'exchange_rate',
        'subtotal',
        'discount',
        'transferred_taxes',
        'withheld_taxes',
        'total',

        'stamped_at',
        'certificate_number',
        'sat_certificate_number',
        'cfdi_seal',
        'sat_seal',
        'original_string',
    ];

    protected $attributes = [
        'status' => 'pending',
        'version' => '4.0',
    ];

    protected function casts(): array
    {
        return [
            'status' => FiscalDocumentStatus::class,
            'stamped_at' => 'datetime',

            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:6',
            'discount' => 'decimal:6',
            'transferred_taxes' => 'decimal:6',
            'withheld_taxes' => 'decimal:6',
            'total' => 'decimal:6',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function files(): HasMany
    {
        return $this->hasMany(
            FiscalDocumentFile::class
        );
    }

    public function stampAttempts()
    {
        return $this->hasMany(FiscalStampAttempt::class);
    }
}
