<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-4 · Suscripción de una organización (acompaña a ME-40):
 *  - en prueba → activa (convertir) · vencida (la prueba terminó sin conversión: la organización queda
 *    en solo lectura durante el periodo de gracia, RN-SAS-04) · cancelada;
 *  - vencida → activa (convertir) · suspendida (fin de la gracia, sistema) · cancelada;
 *  - activa ↔ suspendida; activa o suspendida → cancelada; cancelada → activa (dentro de la retención).
 *
 * «past_due» (pago vencido) lo pone el cobro (SAS-11, acción de sistema «mark_past_due» sobre una activa); desde
 * aquí se regulariza (convertir, al pagarse lo vencido), suspende (mora más allá del plazo configurado) o cancela.
 */
final class SubscriptionStateMachine extends StateMachine
{
    public const CONVERT = 'convert';

    public const EXPIRE = 'expire';           // sistema: vence la prueba

    public const SUSPEND = 'suspend';

    public const REACTIVATE = 'reactivate';

    public const CANCEL = 'cancel';

    public const MARK_PAST_DUE = 'mark_past_due'; // sistema: un comprobante venció sin pagarse (SAS-11)

    public function transitions(): array
    {
        return [
            'trialing' => [self::CONVERT => 'active', self::EXPIRE => 'expired', self::CANCEL => 'cancelled'],
            'expired' => [self::CONVERT => 'active', self::SUSPEND => 'suspended', self::CANCEL => 'cancelled'],
            'active' => [self::SUSPEND => 'suspended', self::CANCEL => 'cancelled', self::MARK_PAST_DUE => 'past_due'],
            'past_due' => [self::CONVERT => 'active', self::SUSPEND => 'suspended', self::CANCEL => 'cancelled'],
            'suspended' => [self::REACTIVATE => 'active', self::CANCEL => 'cancelled'],
            'cancelled' => [self::REACTIVATE => 'active'],
        ];
    }

    /**
     * Acción de ME-40 que acompaña a la de la suscripción, según el estado actual de la organización.
     */
    public static function tenantAction(string $action, string $tenantStatus): ?string
    {
        return match ($action) {
            self::CONVERT => match ($tenantStatus) {
                'trial' => TenantStateMachine::ACTIVATE,
                'grace_period' => TenantStateMachine::REGULARIZE,
                default => null,
            },
            self::EXPIRE => TenantStateMachine::EXPIRE_TRIAL,
            self::SUSPEND => TenantStateMachine::SUSPEND,
            self::REACTIVATE => TenantStateMachine::REACTIVATE,
            self::CANCEL => TenantStateMachine::CANCEL,
            default => null,
        };
    }
}
