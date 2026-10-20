<?php

namespace App\Models;

use App\Domain\Shared\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'company_id',
        'sku',
        'sat_product_code',
        'sat_unit_code',
        'unit_name',
        'description',
        'unit_price',
        'default_tax_rate',
        'tax_object',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:6',
            'default_tax_rate' => 'decimal:6',
            'status' => RecordStatus::class,
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
