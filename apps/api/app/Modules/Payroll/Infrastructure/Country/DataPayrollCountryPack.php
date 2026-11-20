<?php

namespace App\Modules\Payroll\Infrastructure\Country;

use App\Shared\Payroll\PayrollCountryPack;

/**
 * NOM-1 · Paquete de país leído de sus datos (packages/country-xx/data/motor_planilla.php). El mismo
 * lector sirve a cualquier país: lo que cambia es el archivo del paquete.
 */
final class DataPayrollCountryPack implements PayrollCountryPack
{
    /** @var array{pais: string, version: string, decimales: int, por_validar?: bool, regimen_por_defecto?: string, impuesto_renta?: array<string, mixed>, regimenes: array<string, array{tipo: string, estrategia?: string, unidad_remuneracion?: string, base_prorrateo: string, mes_completo_igual_base: bool, requiere_acreditacion: bool, procesos?: list<string>, gratificaciones?: bool, parametros_requeridos?: list<string>}>} */
    private array $data;

    public function __construct(string $country)
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/motor_planilla.php');
        /** @var array{pais: string, version: string, decimales: int, por_validar?: bool, regimen_por_defecto?: string, impuesto_renta?: array<string, mixed>, regimenes: array<string, array{tipo: string, estrategia?: string, unidad_remuneracion?: string, base_prorrateo: string, mes_completo_igual_base: bool, requiere_acreditacion: bool, procesos?: list<string>, gratificaciones?: bool, parametros_requeridos?: list<string>}>} $data */
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
            'code' => $code, 'kind' => $r['tipo'], 'strategy' => $r['estrategia'] ?? $r['tipo'], 'pay_unit' => $r['unidad_remuneracion'] ?? 'mensual',
            'proration_parameter' => $r['base_prorrateo'],
            'full_month_equals_base' => $r['mes_completo_igual_base'], 'requires_accreditation' => $r['requiere_acreditacion'],
            'processes' => $r['procesos'] ?? ['regular'], 'gratifications' => (bool) ($r['gratificaciones'] ?? false),
            'pending_validation' => (bool) ($this->data['por_validar'] ?? true),
            'required_parameters' => $r['parametros_requeridos'] ?? [],
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

    public function defaultRegime(): ?string
    {
        $code = $this->data['regimen_por_defecto'] ?? null;

        return $code !== null && isset($this->data['regimenes'][$code]) ? $code : null;
    }

    /**
     * PER-5 · Configuración del impuesto a la renta del trabajador del país (concepto que recibe la retención,
     * base de afectación, códigos de parámetros y gratificaciones que se proyectan), o null si el país no la tiene.
     *
     * @return array{concepto: string, base: string, procesos: list<string>, proceso_ajuste_final: string, parametros: array<string, string>,
     *     gratificaciones: array{aplica: string, factor: string, bonificacion_pct: string, bonificacion_eps_pct: string, proceso: string, meses_semestre: int,
     *     pagos: list<array{mes_pago: int, desde_mes: int, hasta_mes: int}>}}|null
     */
    public function incomeTax(): ?array
    {
        /** @var array{concepto: string, base: string, procesos: list<string>, proceso_ajuste_final: string, parametros: array<string, string>, gratificaciones: array{aplica: string, factor: string, bonificacion_pct: string, bonificacion_eps_pct: string, proceso: string, meses_semestre: int, pagos: list<array{mes_pago: int, desde_mes: int, hasta_mes: int}>}}|null $tax */
        $tax = $this->data['impuesto_renta'] ?? null;

        return $tax;
    }
}
