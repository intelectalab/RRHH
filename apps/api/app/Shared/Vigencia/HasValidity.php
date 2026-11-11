<?php

namespace App\Shared\Vigencia;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * HIS-1 · Para modelos Eloquent de dimensiones con vigencia: Modelo::query()->asOf('2026-03-01').
 */
trait HasValidity
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAsOf(Builder $query, CarbonInterface|string $date): Builder
    {
        // Sobre el constructor base: el filtro es el mismo y el tipo no depende del modelo concreto.
        EffectiveDated::applyAsOf($query->getQuery(), $date, $query->qualifyColumn('valid_from'), $query->qualifyColumn('valid_to'));

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $this->scopeAsOf($query, now());
    }
}
