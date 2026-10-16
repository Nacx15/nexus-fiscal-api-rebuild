<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait HasSatValidity
{
    public function scopeValidAt(
        Builder $query,
        Carbon|string|null $date = null
    ): Builder {
        $date = $date
            ? Carbon::parse($date)->toDateString()
            : now()->toDateString();

        return $query
            ->where('active', true)
            ->where(function (Builder $query) use ($date) {
                $query
                    ->whereNull('valid_from')
                    ->orWhereDate('valid_from', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query
                    ->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $date);
            });
    }
}