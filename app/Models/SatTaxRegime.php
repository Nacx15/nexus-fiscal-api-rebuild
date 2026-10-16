<?php

namespace App\Models;

use App\Models\Concerns\HasSatValidity;
use Illuminate\Database\Eloquent\Model;

class SatTaxRegime extends Model
{
    use HasSatValidity;

    protected $fillable = [
        'code',
        'description',
        'applies_to_individual',
        'applies_to_legal_entity',
        'valid_from',
        'valid_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'applies_to_individual' => 'boolean',
            'applies_to_legal_entity' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
        ];
    }
}
