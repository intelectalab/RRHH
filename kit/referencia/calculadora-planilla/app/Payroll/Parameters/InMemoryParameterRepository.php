<?php

declare(strict_types=1);

namespace App\Payroll\Parameters;

use Brick\Math\BigDecimal;
use DateTimeInterface;

/**
 * Repositorio en memoria para pruebas y simulaciones. Se arma con el mismo formato del catálogo del kit
 * (database/catalogos/parametros_legales_pe.json) para no duplicar valores legales.
 */
final class InMemoryParameterRepository implements ParameterRepository
{
    /** @var list<array<string, mixed>> */
    private array $params = [];

    /** @var array<string, list<PensionFundRates>> */
    private array $rates = [];

    /** @param list<array<string, mixed>> $catalogRows filas del catálogo del kit */
    public function __construct(array $catalogRows = [])
    {
        foreach ($catalogRows as $row) {
            $this->params[] = $row;
        }
    }

    public static function fromKitCatalog(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self($data['parametros']);
    }

    /** Agrega o reemplaza un valor, por ejemplo para simular un cambio de norma. */
    public function put(string $code, ?string $regime, int|float|string|null $value, string $validFrom, ?string $validTo = null, bool $verified = true, string $unit = 'amount'): self
    {
        $this->params[] = [
            'codigo' => $code, 'regimen' => $regime, 'valor' => $value, 'valor_json' => null, 'unidad' => $unit,
            'vigente_desde' => $validFrom, 'vigente_hasta' => $validTo, 'verificado' => $verified, 'base_legal' => 'prueba',
        ];

        return $this;
    }

    /** Agrega o reemplaza un valor estructurado (escala o tabla), por ejemplo una tasa por tramo. */
    public function putJson(string $code, ?string $regime, array $json, string $validFrom, ?string $validTo = null, bool $verified = true, string $unit = 'scale'): self
    {
        $this->params[] = [
            'codigo' => $code, 'regimen' => $regime, 'valor' => null, 'valor_json' => $json, 'unidad' => $unit,
            'vigente_desde' => $validFrom, 'vigente_hasta' => $validTo, 'verificado' => $verified, 'base_legal' => 'prueba',
        ];

        return $this;
    }

    public function putPensionFundRates(PensionFundRates $rates): self
    {
        $this->rates[$rates->fundCode][] = $rates;

        return $this;
    }

    public function get(string $code, ?string $regimeCode, DateTimeInterface $date): ParameterValue
    {
        return $this->find($code, $regimeCode, $date)
            ?? throw MissingParameterException::notFound($code, $regimeCode, $date->format('Y-m-d'));
    }

    public function find(string $code, ?string $regimeCode, DateTimeInterface $date): ?ParameterValue
    {
        $day = $date->format('Y-m-d');
        foreach ($regimeCode !== null ? [$regimeCode, null] : [null] as $regime) {
            // El último valor cargado gana, para que put() pueda simular una corrección.
            foreach (array_reverse($this->params) as $row) {
                if ($row['codigo'] !== $code || ($row['regimen'] ?? null) !== $regime) {
                    continue;
                }
                if ($row['vigente_desde'] > $day || (($row['vigente_hasta'] ?? null) !== null && $row['vigente_hasta'] < $day)) {
                    continue;
                }

                return new ParameterValue(
                    code: $code,
                    regimeCode: $regime,
                    value: $row['valor'] === null ? null : BigDecimal::of((string) $row['valor']),
                    unit: (string) $row['unidad'],
                    verified: (bool) $row['verificado'],
                    validFrom: (string) $row['vigente_desde'],
                    legalSource: $row['base_legal'] ?? null,
                    json: $row['valor_json'] ?? null,
                );
            }
        }

        return null;
    }

    public function pensionFundRates(string $fundCode, DateTimeInterface $date): PensionFundRates
    {
        $day = $date->format('Y-m-d');
        foreach (array_reverse($this->rates[$fundCode] ?? []) as $rates) {
            if ($rates->validFrom <= $day) {
                return $rates;
            }
        }

        throw MissingParameterException::pensionRates($fundCode, $day);
    }
}
