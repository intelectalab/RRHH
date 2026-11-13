<?php

namespace App\Modules\CoreHR\Application;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * HIS-3 · Conceptos fijos recurrentes que se asignan como componentes de la compensación (bonificación
 * regular, movilidad, gastos de teletrabajo…). Salen del paquete de país (kit/database/catalogos/
 * conceptos_<pais>.json): conceptos de ingreso cuyo monto se ingresa (no los que calcula la planilla con
 * fórmula, como la asignación familiar o la gratificación).
 *
 * El concepto se materializa en payroll_concepts de la organización la primera vez que se usa, con el
 * mismo código del paquete, para que employment_compensation_items lo referencie (FK compuesta).
 */
final class CompensationConcepts
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    /**
     * @return list<array{code: string, name: string, is_remunerative: bool, statutory_code: string|null}>
     */
    public function available(string $country): array
    {
        $out = [];
        foreach ($this->catalog($country) as $c) {
            if (($c['tipo'] ?? null) === 'earning' && ($c['metodo'] ?? null) === 'input') {
                $out[] = [
                    'code' => (string) $c['codigo'],
                    'name' => (string) $c['nombre'],
                    'is_remunerative' => (bool) ($c['remunerativo'] ?? true),
                    'statutory_code' => isset($c['codigo_plame']) ? (string) $c['codigo_plame'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Id del concepto en payroll_concepts de la organización (lo crea si aún no existe). Null si el código no
     * es un concepto fijo disponible del país.
     */
    public function idFor(string $country, string $code): ?string
    {
        $found = null;
        foreach ($this->available($country) as $c) {
            if ($c['code'] === $code) {
                $found = $c;
            }
        }
        if ($found === null) {
            return null;
        }
        $existing = DB::table('payroll_concepts')->where('code', $code)->whereNull('deleted_at')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        return (string) DB::table('payroll_concepts')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(),
            'code' => $code,
            'name' => $found['name'],
            'kind' => 'earning',
            'country_code' => $country,
            'statutory_code' => $found['statutory_code'],
            'is_system' => true,
            'is_remunerative' => $found['is_remunerative'],
            'calculation_method' => 'input',
            'status' => 'active',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        if (isset($this->cache[$country])) {
            return $this->cache[$country];
        }
        $name = 'conceptos_'.strtolower($country).'.json';
        foreach ([base_path('../../kit/database/catalogos/'.$name), base_path('../kit/database/catalogos/'.$name), '/var/www/kit/database/catalogos/'.$name] as $file) {
            if (is_file($file)) {
                /** @var array{conceptos?: list<array<string, mixed>>} $data */
                $data = json_decode((string) file_get_contents($file), true) ?: [];

                return $this->cache[$country] = $data['conceptos'] ?? [];
            }
        }

        return $this->cache[$country] = [];
    }
}
