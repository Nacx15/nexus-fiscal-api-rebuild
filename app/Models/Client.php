<?php

namespace App\Models;

use App\Domain\Shared\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'company_id',
        'business_name',
        'rfc',
        'tax_regime',
        'postal_code',
        'email',
        'phone',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
