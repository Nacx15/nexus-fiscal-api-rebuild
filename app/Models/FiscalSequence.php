<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_type',
        'series',
        'current_number',
        'padding',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'current_number' => 'integer',
            'padding' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
