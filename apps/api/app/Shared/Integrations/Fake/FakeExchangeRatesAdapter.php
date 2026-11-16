<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\ExchangeRatesPort;
use Throwable;

/**
 * Tipos de cambio ficticios y deterministas para desarrollo y pruebas (no son datos de mercado): cada
 * par tiene un valor de ejemplo y una variación pequeña según el día, para que dos fechas no coincidan.
 */
final class FakeExchangeRatesAdapter implements ExchangeRatesPort
{
    /** @var array<string, int> valores ficticios por par, en diezmilésimas */
    private array $base = ['USD:PEN' => 37000, 'EUR:PEN' => 40000, 'USD:CLP' => 9400000, 'USD:COP' => 40000000, 'USD:MXN' => 180000, 'USD:BRL' => 55000, 'USD:EUR' => 9200];

    /** @var array<string, string|null> fijados por una prueba: "FROM:TO:fecha" => tipo (null = no publicado) */
    private array $fixed = [];

    private ?Throwable $nextException = null;

    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function providerName(): string
    {
        return 'fake_exchange_rates';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function fix(string $from, string $to, string $date, ?string $rate): void
    {
        $this->fixed["{$from}:{$to}:{$date}"] = $rate;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function rate(string $from, string $to, string $date): ?string
    {
        $start = hrtime(true);
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record('fake_exchange_rates', 'rate', (hrtime(true) - $start) / 1e6, 'failed', $e->getMessage(), ['from' => $from, 'to' => $to, 'date' => $date]);

            throw $e;
        }
        $key = "{$from}:{$to}:{$date}";
        if (array_key_exists($key, $this->fixed)) {
            $rate = $this->fixed[$key];
        } elseif (isset($this->base["{$from}:{$to}"])) {
            // Variación ficticia de -0,5 % a +0,5 % según el día del año, con 4 decimales.
            $day = (int) date('z', (int) strtotime($date));
            $units = intdiv($this->base["{$from}:{$to}"] * (1000 + ($day % 11) - 5) + 500, 1000);
            $rate = intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
        } else {
            $rate = null;
        }
        $this->recorder?->record('fake_exchange_rates', 'rate', (hrtime(true) - $start) / 1e6, 'success', null, ['from' => $from, 'to' => $to, 'date' => $date, 'found' => $rate !== null]);

        return $rate;
    }
}
