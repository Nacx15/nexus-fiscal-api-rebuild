<?php

namespace App\Models;

use App\Domain\Invoice\Enums\InvoiceStatus;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'company_id',
        'client_id',
        'created_by',
        'sequence_id',
        'series',
        'folio',
        'status',

        'receiver_rfc',
        'receiver_name',
        'receiver_tax_regime',
        'receiver_postal_code',

        'payment_form',
        'payment_method',
        'cfdi_use',

        'currency',
        'exchange_rate',

        'subtotal',
        'discount',
        'transferred_taxes',
        'withheld_taxes',
        'total',

        'issued_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'MXN',
        'exchange_rate' => '1.000000',
        'subtotal' => '0.000000',
        'discount' => '0.000000',
        'transferred_taxes' => '0.000000',
        'withheld_taxes' => '0.000000',
        'total' => '0.000000',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,

            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:6',
            'discount' => 'decimal:6',

            'transferred_taxes' =>
                'decimal:6',

            'withheld_taxes' =>
                'decimal:6',

            'total' => 'decimal:6',

            'issued_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function client()
    {
        return $this->belongsTo(
            Client::class
        );
    }

    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function sequence()
    {
        return $this->belongsTo(
            FiscalSequence::class,
            'sequence_id'
        );
    }

    public function items()
    {
        return $this->hasMany(
            InvoiceItem::class
        )->orderBy('position');
    }
}
