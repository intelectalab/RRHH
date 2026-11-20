<?php

namespace App\Modules\Payroll\Infrastructure\Country;

/**
 * CPT-3 · CPT-5 · Plantilla de conceptos del paquete de país (packages/country-xx/data/conceptos_xx.json, copia
 * de kit/database/catalogos/conceptos_xx.json): las bases de cálculo a las que un concepto puede sumar y los
 * conceptos precargados con su fórmula en el lenguaje del motor, afectaciones y código de la declaración.
 */
final class CountryConceptTemplate
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, string> código de la base => descripción
     */
    public function bases(string $country): array
    {
        /** @var array<string, string> $bases */
        $bases = (array) ($this->data($country)['bases'] ?? []);

        return $bases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function concepts(string $country): array
    {
        /** @var list<array<string, mixed>> $c */
        $c = array_values((array) ($this->data($country)['conceptos'] ?? []));

        return $c;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function concept(string $country, string $code): ?array
    {
        foreach ($this->concepts($country) as $c) {
            if (($c['codigo'] ?? null) === $code) {
                return $c;
            }
        }

        return null;
    }

    /** Versión de la plantilla: la del paquete de país que la trae. */
    public function version(string $country): string
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/paquete.php');
        if (! is_file($file)) {
            return '0';
        }
        /** @var array{version?: string} $pack */
        $pack = require $file;

        return (string) ($pack['version'] ?? '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $country): array
    {
        $country = strtoupper($country);
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        if (! isset(self::$cache[$country])) {
            $file = base_path('packages/country-'.strtolower($country).'/data/conceptos_'.strtolower($country).'.json');
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$cache[$country] = is_array($decoded) ? $decoded : [];
        }

        return self::$cache[$country];
    }
}
