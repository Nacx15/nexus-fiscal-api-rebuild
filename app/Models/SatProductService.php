<?php

namespace App\Models;

use App\Models\Concerns\HasSatValidity;
use Illuminate\Database\Eloquent\Model;

class SatProductService extends Model
{
    use HasSatValidity;

    protected $fillable = [
        'code',
        'description',
        'valid_from',
        'valid_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
        ];
    }
}
