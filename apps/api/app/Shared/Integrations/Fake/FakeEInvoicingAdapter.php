<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\EInvoicingPort;
use Throwable;

/**
 * Comprobante electrónico falso para desarrollo y pruebas: no envía nada a la administración tributaria.
 * Acepta con un número correlativo por serie y una constancia determinista, y rechaza como lo haría el proveedor
 * cuando el comprador de un país con RUC no trae un número de 11 dígitos. Reenviar la misma clave devuelve el
 * mismo resultado (no emite dos veces).
 */
final class FakeEInvoicingAdapter implements EInvoicingPort
{
    /** @var array<string, array{accepted: bool, series: string, number: string|null, ticket: string, hash: string|null, message: string}> */
    private array $issued = [];

    /** @var array<string, int> */
    private array $next = [];

    private ?Throwable $nextException = null;

    private ?string $rejectNext = null;

    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function rejectNext(string $message): void
    {
        $this->rejectNext = $message;
    }

    public function issue(array $document): array
    {
        $start = hrtime(true);
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record('fake_einvoicing', 'issue', (hrtime(true) - $start) / 1e6, 'failed', $e->getMessage(), ['key' => $document['key']]);

            throw $e;
        }
        if (isset($this->issued[$document['key']]) && $this->issued[$document['key']]['accepted']) {
            return $this->issued[$document['key']];
        }

        $reason = $this->rejectNext;
        $this->rejectNext = null;
        if ($reason === null && $document['buyer_country'] === 'PE' && preg_match('/^\d{11}$/', (string) $document['buyer_tax_id']) !== 1) {
            $reason = 'El número de RUC del adquiriente no es válido.';
        }
        $ticket = 'FAKE-'.substr(hash('sha256', $document['key'].'|'.count($this->issued)), 0, 12);
        if ($reason !== null) {
            $result = ['accepted' => false, 'series' => $document['series'], 'number' => null, 'ticket' => $ticket, 'hash' => null, 'message' => $reason];
        } else {
            $n = ($this->next[$document['series']] ?? 0) + 1;
            $this->next[$document['series']] = $n;
            $number = str_pad((string) $n, 8, '0', STR_PAD_LEFT);
            $result = [
                'accepted' => true, 'series' => $document['series'], 'number' => $number, 'ticket' => $ticket,
                'hash' => substr(hash('sha256', $document['series'].'-'.$number.'|'.$document['total'].'|'.$document['currency']), 0, 28),
                'message' => 'Comprobante aceptado.',
            ];
        }
        $this->issued[$document['key']] = $result;
        $this->recorder?->record('fake_einvoicing', 'issue', (hrtime(true) - $start) / 1e6, 'success', null, ['key' => $document['key'], 'accepted' => $result['accepted']]);

        return $result;
    }

    public function providerName(): string
    {
        return 'fake_einvoicing';
    }

    public function isFake(): bool
    {
        return true;
    }
}
