<?php

namespace App\Shared\StateMachine;

use App\Shared\Exceptions\InvalidTransitionException;

abstract class StateMachine
{
    /**
     * Matriz declarada de transiciones permitidas.
     * Estructura: [ estado_origen => [ acción => estado_destino ] ]
     *
     * @return array<string, array<string, string>>
     */
    abstract public function transitions(): array;

    /**
     * Comprueba si la acción es válida desde el estado actual.
     */
    public function can(string $currentState, string $action): bool
    {
        $transitions = $this->transitions();

        return isset($transitions[$currentState][$action]);
    }

    /**
     * Obtiene el nuevo estado tras aplicar la acción o lanza InvalidTransitionException (RN-PLT-01).
     *
     * @throws InvalidTransitionException
     */
    public function getNextState(string $currentState, string $action): string
    {
        $transitions = $this->transitions();

        if (! isset($transitions[$currentState][$action])) {
            throw new InvalidTransitionException(
                currentStatus: $currentState,
                allowedActions: $this->allowedActions($currentState),
                message: "No se puede ejecutar la acción '{$action}' desde el estado '{$currentState}'."
            );
        }

        return $transitions[$currentState][$action];
    }

    /**
     * Lista de acciones permitidas desde el estado actual.
     *
     * @return array<int, string>
     */
    public function allowedActions(string $currentState): array
    {
        $transitions = $this->transitions();

        return array_keys($transitions[$currentState] ?? []);
    }
}
