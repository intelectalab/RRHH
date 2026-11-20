<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\PaymentGatewayPort;

/**
 * Pasarela de cobro falsa para desarrollo y pruebas: confirma solo los pagos que la prueba registró con
 * {@see self::willReport()}; cualquier otra referencia vuelve sin confirmar.
 */
final class FakePaymentGatewayAdapter implements PaymentGatewayPort
{
    /** @var array<string, array{confirmed: bool, amount: string, currency: string, paid_on: string, method: string, reference: string}> */
    private array $payments = [];

    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function willReport(string $reference, string $amount, string $currency, string $paidOn, string $method = 'card'): void
    {
        $this->payments[$reference] = ['confirmed' => true, 'amount' => $amount, 'currency' => $currency, 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference];
    }

    public function verify(string $gatewayReference): array
    {
        $start = hrtime(true);
        $result = $this->payments[$gatewayReference]
            ?? ['confirmed' => false, 'amount' => '0', 'currency' => '', 'paid_on' => '', 'method' => 'other', 'reference' => $gatewayReference];
        $this->recorder?->record('fake_payment_gateway', 'verify', (hrtime(true) - $start) / 1e6, 'success', null, ['confirmed' => $result['confirmed']]);

        return $result;
    }

    public function providerName(): string
    {
        return 'fake_payment_gateway';
    }

    public function isFake(): bool
    {
        return true;
    }
}
