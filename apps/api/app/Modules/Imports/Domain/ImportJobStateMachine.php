<?php

namespace App\Modules\Imports\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-41 · Importación (import_jobs). Nunca se importa sin pasar por la validación, que genera la
 * vista previa (RN-IMP-01): «execute» solo existe desde «validated».
 *
 * Acciones del usuario: map, validate, execute, cancel (descartar), revert (deshacer).
 * Acciones del sistema: validate_with_errors (la validación encontró errores que la política no deja
 * pasar), finish y fail (fin del proceso en segundo plano).
 */
final class ImportJobStateMachine extends StateMachine
{
    /** Acciones que se piden por la API; el resto las dispara el propio motor. */
    public const USER_ACTIONS = ['map', 'validate', 'execute', 'cancel', 'revert'];

    public function transitions(): array
    {
        return [
            'uploaded' => ['map' => 'mapped', 'cancel' => 'cancelled'],
            'mapped' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors', 'cancel' => 'cancelled'],
            'with_errors' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors', 'cancel' => 'cancelled'],
            'validated' => ['execute' => 'importing', 'cancel' => 'cancelled'],
            'importing' => ['finish' => 'completed', 'fail' => 'failed'],
            'completed' => ['revert' => 'reverted'],
            'failed' => ['cancel' => 'cancelled'],
        ];
    }

    /**
     * Acciones que el usuario puede pedir desde el estado (para la interfaz).
     *
     * @return array<int, string>
     */
    public function userActions(string $state): array
    {
        $actions = array_values(array_intersect($this->allowedActions($state), self::USER_ACTIONS));
        // «validate» decide por sí misma si el resultado es «validated» o «with_errors».
        if (in_array('validate_with_errors', $this->allowedActions($state), true) && ! in_array('validate', $actions, true)) {
            $actions[] = 'validate';
        }

        return $actions;
    }
}
