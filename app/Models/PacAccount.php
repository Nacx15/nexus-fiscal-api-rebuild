<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PacAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'provider',
        'environment',
        'username',
        'password',
        'token',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'token' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
