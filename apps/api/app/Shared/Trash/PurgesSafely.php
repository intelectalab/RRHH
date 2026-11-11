<?php

namespace App\Shared\Trash;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Depuración definitiva en un punto de guardado: si otro registro todavía referencia al eliminado
 * (clave foránea, SQLSTATE 23503), se deshace solo esa depuración y el registro sigue en la papelera.
 */
trait PurgesSafely
{
    /**
     * @param  callable(): void  $delete
     * @param  (callable(): void)|null  $after
     */
    private function purgeSafely(callable $delete, ?callable $after = null): bool
    {
        try {
            DB::transaction(function () use ($delete): void {
                $delete();
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23503') {
                return false;
            }
            throw $e;
        }
        if ($after !== null) {
            $after();
        }

        return true;
    }
}
