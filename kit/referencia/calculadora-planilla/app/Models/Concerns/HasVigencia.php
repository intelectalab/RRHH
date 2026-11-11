<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Filtra por vigencia: valid_from <= fecha y (valid_to nulo o >= fecha). */
trait HasVigencia
{
    public function scopeVigentAt(Builder $query, string $date): Builder
    {
        return $query->where('valid_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderByDesc('valid_from');
    }
}
