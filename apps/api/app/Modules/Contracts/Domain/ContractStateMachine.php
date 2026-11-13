<?php

namespace App\Modules\Contracts\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-11 · Contrato. Estados del documento → base (`contracts.status`):
 * Borrador → draft · En aprobación → pending_approval · En firma → pending_signature · Firmado → signed ·
 * Vigente → active · Vencido sin regularizar → expired · Terminado → terminated · Anulado → cancelled.
 * «Por vencer» no es un estado guardado: se calcula con la ventana de aviso (CON-4).
 *
 * - `register-signature` también se admite en Borrador cuando se carga el contrato físico escaneado y ya
 *   firmado (RN-CON-06: «salvo carga de contrato físico escaneado con marca de origen»).
 * - `renew` (y `amend`, que agrega una adenda) no mueven al contrato: `renew` crea el sucesor enlazado
 *   y no mueve al contrato renovado. Cuando el sucesor empieza a regir, el
 *   renovado pasa a Terminado con `complete` (acción del sistema).
 * - `start`, `expire` y `complete` los ejecuta la tarea diaria contracts:apply-due (o el registro de la
 *   firma, si la fecha de inicio ya llegó).
 */
final class ContractStateMachine extends StateMachine
{
    public const DRAFT = 'draft';

    public const PENDING_APPROVAL = 'pending_approval';

    public const PENDING_SIGNATURE = 'pending_signature';

    public const SIGNED = 'signed';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const TERMINATED = 'terminated';

    public const CANCELLED = 'cancelled';

    /** Acciones que solo ejecuta el sistema: no tienen ruta ni aparecen en allowed_actions. */
    public const SYSTEM_ACTIONS = ['start', 'expire', 'complete'];

    /** Estados que cuentan para la vigencia y el tiempo acumulado (ya firmados). */
    public const COUNTED = [self::SIGNED, self::ACTIVE, self::EXPIRED, self::TERMINATED];

    public function transitions(): array
    {
        return [
            self::DRAFT => ['submit' => self::PENDING_APPROVAL, 'register-signature' => self::SIGNED, 'void' => self::CANCELLED],
            self::PENDING_APPROVAL => ['approve' => self::PENDING_SIGNATURE, 'return' => self::DRAFT, 'void' => self::CANCELLED],
            self::PENDING_SIGNATURE => ['register-signature' => self::SIGNED, 'decline-signature' => self::DRAFT, 'void' => self::CANCELLED],
            self::SIGNED => ['start' => self::ACTIVE, 'void' => self::CANCELLED],
            self::ACTIVE => ['expire' => self::EXPIRED, 'complete' => self::TERMINATED, 'terminate' => self::TERMINATED, 'renew' => self::ACTIVE, 'amend' => self::ACTIVE],
            self::EXPIRED => ['complete' => self::TERMINATED, 'terminate' => self::TERMINATED, 'renew' => self::EXPIRED],
            self::TERMINATED => [],
            self::CANCELLED => [],
        ];
    }

    /**
     * Acciones que el usuario puede pedir desde el estado (sin las del sistema).
     *
     * @return array<int, string>
     */
    public function manualActions(string $state): array
    {
        return array_values(array_diff($this->allowedActions($state), self::SYSTEM_ACTIONS));
    }
}
