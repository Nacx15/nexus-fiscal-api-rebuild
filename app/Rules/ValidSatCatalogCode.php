<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class ValidSatCatalogCode implements ValidationRule
{
    public function __construct(
        private readonly string $table
    ) {}

    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        $today = now()->toDateString();

        $exists = DB::table($this->table)
            ->where('code', $value)
            ->where('active', true)
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('valid_from')
                    ->orWhereDate(
                        'valid_from',
                        '<=',
                        $today
                    );
            })
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('valid_to')
                    ->orWhereDate(
                        'valid_to',
                        '>=',
                        $today
                    );
            })
            ->exists();

        if (! $exists) {
            $fail(
                "The {$attribute} is not a valid or active SAT catalog code."
            );
        }
    }
}
