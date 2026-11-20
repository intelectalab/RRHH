<?php

namespace App\Modules\Payroll\Infrastructure\Country;

use App\Shared\Payroll\PayrollCountryPack;

/**
 * NOM-1 · Paquete de país leído de sus datos (packages/country-xx/data/motor_planilla.php). El mismo
 * lector sirve a cualquier país: lo que cambia es el archivo del paquete.
 */
final class DataPayrollCountryPack implements PayrollCountryPack
{
    /** @var array{pais: string, version: string, decimales: int, por_validar?: bool, regimenes: array<string, array{tipo: string, base_prorrateo: string, mes_completo_igual_base: bool, requiere_acreditacion: bool}>} */
    private array $data;

    public function __construct(string $country)
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/motor_planilla.php');
        /** @var array{pais: string, version: string, decimales: int, por_validar?: bool, regimenes: array<string, array{tipo: string, base_prorrateo: string, mes_completo_igual_base: bool, requiere_acreditacion: bool}>} $data */
        $data = is_file($file) ? require $file : ['pais' => strtoupper($country), 'version' => '0', 'decimales' => 2, 'regimenes' => []];
        $this->data = $data;
    }

    public static function exists(string $country): bool
    {
        return is_file(base_path('packages/country-'.strtolower($country).'/data/motor_planilla.php'));
    }

    public function country(): string
    {
        return $this->data['pais'];
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function decimals(): int
    {
        return $this->data['decimales'];
    }

    public function regime(string $code): ?array
    {
        $r = $this->data['regimenes'][$code] ?? null;

        return $r === null ? null : [
            'code' => $code, 'kind' => $r['tipo'], 'proration_parameter' => $r['base_prorrateo'],
            'full_month_equals_base' => $r['mes_completo_igual_base'], 'requires_accreditation' => $r['requiere_acreditacion'],
            'pending_validation' => (bool) ($this->data['por_validar'] ?? true),
        ];
    }

    public function regimes(): array
    {
        $out = [];
        foreach (array_keys($this->data['regimenes']) as $code) {
            $r = $this->regime((string) $code);
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
