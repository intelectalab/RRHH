<?php

namespace App\Shared\Vigencia;

use App\Shared\Exceptions\AyniException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HIS-1 · Patrón de registros con vigencia (regla 2 de CLAUDE.md).
 *
 * Una «dimensión» es una tabla con valid_from / valid_to (date, ambos inclusive) y una relación
 * (las columnas que identifican a quién pertenece: employment_id, parameter_code + alcance…).
 *
 * - change(): registrar un cambio desde una fecha cierra la fila vigente el día anterior e
 *   inserta otra; nunca actualiza el valor de una fila en sitio.
 * - La base impide solapes con una restricción EXCLUDE; el error de la base se traduce a 409 overlap.
 * - asOf(): a cualquier fecha devuelve exactamente una fila o ninguna.
 * - Un registro ya vigente no se modifica: se corrige con otro (sameDay: 'reject').
 */
final class EffectiveDated
{
    public const SAME_DAY_REJECT = 'reject';

    /** Solo para valores sin historia propia el mismo día (p. ej. parámetros recién programados). */
    public const SAME_DAY_REPLACE = 'replace';

    /**
     * Filtro «vigente a la fecha»: valid_from <= fecha <= valid_to (NULL = sin límite).
     *
     * @param  Builder|EloquentBuilder<Model>  $query
     */
    public static function applyAsOf(Builder|EloquentBuilder $query, CarbonInterface|string $date, string $from = 'valid_from', string $to = 'valid_to'): void
    {
        $day = self::day($date);
        $query->where(fn ($q) => $q->whereNull($from)->orWhere($from, '<=', $day))
            ->where(fn ($q) => $q->whereNull($to)->orWhere($to, '>=', $day));
    }

    /**
     * La fila vigente a la fecha para una relación, o null.
     *
     * @param  array<string, mixed>  $relation
     */
    public function rowAt(string $table, array $relation, CarbonInterface|string $date, bool $lock = false): ?\stdClass
    {
        $query = $this->scoped($table, $relation);
        self::applyAsOf($query, $date);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) {
            throw new AyniException('overlap', 409, (string) trans('validity.overlap'), [], ['table' => $table]);
        }
        /** @var \stdClass|null $row */
        $row = $rows->first();

        return $row;
    }

    /**
     * Registra un cambio desde $from. Devuelve el id de la fila nueva (o de la reemplazada el mismo día).
     *
     * @param  array<string, mixed>  $relation  columnas que identifican la relación (se copian a la fila nueva)
     * @param  array<string, mixed>  $values  columnas de valor de la fila nueva
     */
    public function change(string $table, array $relation, array $values, CarbonInterface|string $from, string $sameDay = self::SAME_DAY_REJECT): string
    {
        $day = self::day($from);

        return $this->guard(fn (): string => DB::transaction(function () use ($table, $relation, $values, $day, $sameDay): string {
            // Bloquea la historia de la relación para que dos cambios simultáneos no se crucen.
            $this->scoped($table, $relation)->lockForUpdate()->get(['id']);

            if ($this->scoped($table, $relation)->where('valid_from', '>', $day)->exists()) {
                throw new AyniException('overlap', 409, (string) trans('validity.future_exists'), [], ['reason' => 'future_exists']);
            }
            $current = $this->rowAt($table, $relation, $day);

            if ($current !== null && $current->valid_from !== null && self::day((string) $current->valid_from) === $day) {
                if ($sameDay !== self::SAME_DAY_REPLACE) {
                    throw new AyniException('overlap', 409, (string) trans('validity.same_day'), [], ['reason' => 'same_day']);
                }
                DB::table($table)->where('id', $current->id)->update($values);

                return (string) $current->id;
            }
            if ($current !== null) {
                DB::table($table)->where('id', $current->id)->update(['valid_to' => Carbon::parse($day)->subDay()->toDateString()]);
            }

            return (string) DB::table($table)->insertGetId($relation + $values + ['valid_from' => $day, 'valid_to' => null]);
        }));
    }

    /**
     * Cierra la vigencia de la relación en $lastDay (inclusive). No borra nada.
     *
     * @param  array<string, mixed>  $relation
     */
    public function close(string $table, array $relation, CarbonInterface|string $lastDay): bool
    {
        $day = self::day($lastDay);

        return $this->guard(fn (): bool => DB::transaction(function () use ($table, $relation, $day): bool {
            $current = $this->rowAt($table, $relation, $day, true);
            if ($current === null) {
                return false;
            }

            return DB::table($table)->where('id', $current->id)->update(['valid_to' => $day]) === 1;
        }));
    }

    /**
     * Línea de tiempo de la relación, de la más reciente a la más antigua.
     *
     * @param  array<string, mixed>  $relation
     * @return list<\stdClass>
     */
    public function history(string $table, array $relation): array
    {
        /** @var list<\stdClass> */
        return array_values($this->scoped($table, $relation)->orderByRaw('valid_from DESC NULLS LAST')->get()->all());
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private function scoped(string $table, array $relation): Builder
    {
        $query = DB::table($table);
        foreach ($relation as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * Traduce la violación de la restricción de exclusión (SQLSTATE 23P01) a 409 overlap.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new AyniException('overlap', 409, (string) trans('validity.overlap'), [], ['reason' => 'overlap']);
            }
            throw $e;
        }
    }

    public static function day(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }
}
