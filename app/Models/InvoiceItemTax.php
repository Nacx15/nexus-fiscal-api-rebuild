<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItemTax extends Model
{
    protected $fillable = [
        'invoice_item_id',
        'tax',
        'type',
        'factor_type',
        'rate_or_quota',
        'base',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'rate_or_quota' => 'decimal:6',
            'base' => 'decimal:6',
            'amount' => 'decimal:6',
        ];
    }

    public function item()
    {
        return $this->belongsTo(
            InvoiceItem::class,
            'invoice_item_id'
        );
    }

    public function invoices()
    {
        return $this->hasMany(
            Invoice::class
        );
    }
}
