<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'certificate_number',
        'cer_path',
        'key_path',
        'key_password',
        'valid_from',
        'valid_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'key_password' => 'encrypted',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
