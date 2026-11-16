<?php

namespace App\Shared\Imports;

use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IMP-2 · Valida una fila ejecutando el alta real del módulo dueño dentro de un punto de guardado que
 * siempre se deshace: la vista previa aplica exactamente las mismas reglas que el alta manual
 * (RN-IMP-06) sin escribir nada. Los errores del servicio se traducen a la columna del archivo.
 */
final class ImportDryRun
{
    /**
     * @param  callable(): mixed  $create
     * @param  array<string, string>  $fields  campo del servicio → columna del archivo
     */
    public static function check(RowCheck $check, callable $create, array $fields = []): RowCheck
    {
        if (! $check->ok()) {
            return $check;
        }
        DB::beginTransaction();
        try {
            $create();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $check->error($fields[$field] ?? null, 'invalid', (string) $message);
                }
            }
        } catch (AyniException $e) {
            $field = $e->getMeta()['field'] ?? null;
            $check->error(is_string($field) ? ($fields[$field] ?? null) : null, $e->getErrorCode(), $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $check->error(null, 'invalid', (string) trans('imports.failed_unexpected'));
        } finally {
            DB::rollBack();
        }

        return $check;
    }

    /**
     * Error a lanzar en import() si la fila dejó de ser válida entre la vista previa y la importación.
     */
    public static function fail(RowCheck $check): never
    {
        $first = $check->errors[0] ?? ['message' => (string) trans('imports.failed_unexpected')];

        throw new AyniException('business_rule_violated', 422, $first['message']);
    }
}
