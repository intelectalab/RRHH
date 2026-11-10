<?php

namespace App\Modules\Identity\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-02 · Cuenta de usuario (docs/05-maquinas-de-estado.md).
 * Estados en base: invited, active, locked, alumni, disabled.
 */
final class UserAccountStateMachine extends StateMachine
{
    public const INVITE = 'invite';            // reenviar_invitacion

    public const ACTIVATE = 'activate';        // activar_cuenta (el propio usuario)

    public const SUSPEND = 'suspend';          // bloquear

    public const REACTIVATE = 'reactivate';    // desbloquear / reactivar

    public const TO_ALUMNI = 'to_alumni';      // pasar_a_ex_colaborador (sistema)

    public const DEACTIVATE = 'deactivate';    // desactivar

    public function transitions(): array
    {
        return [
            'invited' => [self::INVITE => 'invited', self::ACTIVATE => 'active', self::DEACTIVATE => 'disabled'],
            'active' => [self::SUSPEND => 'locked', self::TO_ALUMNI => 'alumni', self::DEACTIVATE => 'disabled'],
            'locked' => [self::REACTIVATE => 'active', self::TO_ALUMNI => 'alumni', self::DEACTIVATE => 'disabled'],
            'alumni' => [self::REACTIVATE => 'active', self::DEACTIVATE => 'disabled'],
            'disabled' => [],
        ];
    }
}
