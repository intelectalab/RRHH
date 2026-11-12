<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-2 · ME-40 · Organización: en prueba, activa, en periodo de gracia, suspendida, bloqueada (por
 * falta de pago; variante de «Suspendida» que guarda el estado del esquema), cancelada y eliminada.
 *
 * Las transiciones de cobro automáticas (vencer la prueba, entrar en gracia, regularizar) las
 * dispara la suscripción (SAS-4/SAS-11); la consola dispara las demás.
 */
final class TenantStateMachine extends StateMachine
{
    public const ACTIVATE = 'activate';          // convertir (prueba → activa)

    public const EXPIRE_TRIAL = 'expire_trial';  // vencer_prueba (sistema)

    public const ENTER_GRACE = 'enter_grace';    // entrar_en_gracia (sistema)

    public const REGULARIZE = 'regularize';      // regularizar (sistema)

    public const SUSPEND = 'suspend';

    public const BLOCK = 'block';

    public const REACTIVATE = 'reactivate';

    public const CANCEL = 'cancel';

    public const DELETE = 'delete';              // eliminar al vencer la retención (sistema)

    public function transitions(): array
    {
        return [
            'trial' => [self::ACTIVATE => 'active', self::EXPIRE_TRIAL => 'grace_period', self::CANCEL => 'cancelled'],
            'active' => [self::ENTER_GRACE => 'grace_period', self::SUSPEND => 'suspended', self::BLOCK => 'blocked', self::CANCEL => 'cancelled'],
            'grace_period' => [self::REGULARIZE => 'active', self::SUSPEND => 'suspended', self::BLOCK => 'blocked', self::CANCEL => 'cancelled'],
            'suspended' => [self::REACTIVATE => 'active', self::CANCEL => 'cancelled'],
            'blocked' => [self::REACTIVATE => 'active', self::CANCEL => 'cancelled'],
            'cancelled' => [self::REACTIVATE => 'active', self::DELETE => 'deleted'],
            'deleted' => [],
        ];
    }
}
