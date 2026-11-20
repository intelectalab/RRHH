<?php

namespace App\Modules\Payroll\Infrastructure\Setup;

use App\Modules\Payroll\Infrastructure\Country\CountryConceptTemplate;
use App\Shared\Setup\CountryPackComponent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * CPT-5 · Conceptos de planilla del paquete de país: remuneración básica, asignación familiar, horas extra,
 * comisiones, gratificaciones, CTS, vacaciones, AFP, ONP, EsSalud, renta de quinta y descuentos, con su
 * fórmula en el lenguaje del motor (o «ingresado» hasta que su historia la complete), afectaciones por base y
 * código de la Tabla 22. Quedan publicados (versión desde siempre) y marcados del paquete (is_system): la
 * organización cambia nombre, orden y cuentas, pero no su afectación legal (RN-CPT-03).
 *
 * Aplicar solo crea los que faltan por código; nunca pisa lo que la organización editó.
 */
final class PayrollConceptsPackComponent implements CountryPackComponent
{
    /** Fecha desde la que rige la versión instalada: cubre cualquier periodo que la organización calcule. */
    private const SINCE = '2000-01-01';

    public function __construct(private readonly CountryConceptTemplate $template) {}

    public function code(): string
    {
        return 'payroll_concepts';
    }

    public function moduleCode(): string
    {
        return 'payroll';
    }

    public function items(string $country): array
    {
        return array_map(fn ($c) => (string) $c['codigo'].' · '.(string) $c['nombre'], $this->template->concepts($country));
    }

    public function present(string $country): int
    {
        return DB::table('payroll_concepts')->whereNull('deleted_at')->where('is_system', true)->where('country_code', strtoupper($country))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $country = strtoupper($country);
        $tenant = TenancyScope::requireTenant();
        $version = $this->template->version($country);
        $existing = DB::table('payroll_concepts')->pluck('code')->flip();
        DB::transaction(function () use ($country, $tenant, $version, $existing, $userId): void {
            foreach ($this->template->concepts($country) as $i => $c) {
                $code = (string) $c['codigo'];
                if (isset($existing[$code])) {
                    continue;
                }
                $formula = isset($c['formula_motor']) && $c['formula_motor'] !== '' ? (string) $c['formula_motor'] : null;
                $id = (string) DB::table('payroll_concepts')->insertGetId([
                    'tenant_id' => $tenant, 'code' => $code, 'name' => (string) $c['nombre'], 'kind' => (string) $c['tipo'],
                    'country_code' => $country, 'statutory_code' => $c['codigo_plame'] ?? null, 'statutory_code_verified' => (bool) ($c['codigo_plame_verificado'] ?? false),
                    'is_system' => true, 'is_remunerative' => (bool) ($c['remunerativo'] ?? true), 'calculation_method' => $formula === null ? 'input' : 'formula',
                    'sort_order' => (int) ($c['orden'] ?? (300 + $i)), 'status' => 'active', 'pack_version' => $version, 'created_by_user_id' => $userId,
                ]);
                $versionId = (string) DB::table('payroll_concept_versions')->insertGetId([
                    'tenant_id' => $tenant, 'payroll_concept_id' => $id, 'formula' => $formula,
                    'condition' => isset($c['condicion_motor']) && $c['condicion_motor'] !== '' ? (string) $c['condicion_motor'] : null,
                    'applies_to_regimes' => '{'.implode(',', array_map('strval', (array) ($c['regimenes'] ?? []))).'}',
                    // BSO-1 a BSO-3 · Procesos en los que corre (los del mes no entran a la gratificación, la CTS ni las utilidades).
                    'applies_to_period_kinds' => isset($c['procesos']) ? '{'.implode(',', array_map('strval', (array) $c['procesos'])).'}' : null,
                    'parameters_used' => '{'.implode(',', array_map('strval', (array) ($c['parametros_legales'] ?? []))).'}',
                    'notes' => (string) ($c['nota'] ?? '') !== '' ? (string) $c['nota'] : null, 'published_by_user_id' => $userId, 'published_at' => now(),
                    'valid_from' => self::SINCE,
                ]);
                foreach ((array) ($c['afectaciones'] ?? []) as $base => $affects) {
                    if (! $affects) {
                        continue;
                    }
                    DB::table('payroll_concept_affectations')->insert([
                        'tenant_id' => $tenant, 'payroll_concept_version_id' => $versionId, 'base_code' => (string) $base, 'affects' => true,
                        'projection_kind' => $base === 'quinta' ? ($c['proyeccion_quinta'] ?? null) : null,
                    ]);
                }
                if (($c['codigo_plame'] ?? null) !== null) {
                    DB::table('concept_statutory_mappings')->insert(['tenant_id' => $tenant, 'payroll_concept_id' => $id, 'filing_kind' => 'plame',
                        'statutory_code' => (string) $c['codigo_plame'], 'amount_role' => (string) ($c['rol_monto'] ?? 'accrued_and_paid'),
                        'verified' => (bool) ($c['codigo_plame_verificado'] ?? false), 'valid_from' => self::SINCE]);
                }
            }
        });
    }

    public function link(): string
    {
        return '/nomina/conceptos';
    }
}
