<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'position',

        'product_code',
        'unit_code',
        'unit_name',
        'description',

        'quantity',
        'unit_price',
        'discount',
        'subtotal',

        'tax_object',

        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'discount' => 'decimal:6',
            'subtotal' => 'decimal:6',
            'total' => 'decimal:6',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(
            Invoice::class
        );
    }

    public function product()
    {
        return $this->belongsTo(
            Product::class
        );
    }

    public function taxes()
    {
        return $this->hasMany(
            InvoiceItemTax::class
        );
    }
}
