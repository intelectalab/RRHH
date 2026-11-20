<?php

namespace App\Shared\Integrations\Ports;

/**
 * SAS-11 · ADR 0010 · Pasarela de cobro de la suscripción (tarjeta, billetera o transferencia con conciliación).
 * Hoy los pagos se registran a mano en la consola; la pasarela se conecta después detrás de este puerto.
 * En desarrollo y pruebas responde la implementación falsa.
 */
interface PaymentGatewayPort
{
    /**
     * Verifica un pago informado por la pasarela (referencia propia de la pasarela).
     *
     * @return array{confirmed: bool, amount: string, currency: string, paid_on: string, method: string, reference: string}
     */
    public function verify(string $gatewayReference): array;

    public function providerName(): string;

    public function isFake(): bool;
}
