<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyFiscalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'business_name',
        'rfc',
        'tax_regime',
        'postal_code',
        'email',
        'logo_path',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
