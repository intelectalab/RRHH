<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Domain\AbsenceTypeRules;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Modules\Absences\Infrastructure\Models\AbsenceTypeRule;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * AUS-1 · Catálogo de tipos de ausencia: los del paquete de país se siembran en cada organización y
 * sus reglas se guardan con vigencia (absence_type_rules). La duración de las licencias de ley se
 * resuelve a la fecha desde los parámetros legales; nunca se guarda como número en el tipo.
 */
final class AbsenceTypeCatalog
{
    /** Columnas de regla y su valor por defecto (el mismo que la base). */
    public const RULE_DEFAULTS = [
        'is_paid' => true,
        'affects_attendance' => true,
        'unit' => 'days',
        'max_quantity' => null,
        'max_period' => 'year',
        'min_notice_days' => null,
        'requires_document' => false,
        'document_grace_days' => null,
        'requestable_by' => 'employee',
        'approval_workflow_definition_id' => null,
        'payroll_concept_code' => null,
        'statutory_suspension_code' => null,
        'subsidy_kind' => 'none',
        'counts_as_worked_for_benefits' => true,
        // AUS-2 · Cómo se cuentan los días: calendario o hábiles (sin descanso semanal ni feriados de la sede).
        'day_count' => 'calendar',
        'legal_entity_ids' => null,
        'labor_regime_codes' => null,
    ];

    private const JSON_RULES = ['legal_entity_ids', 'labor_regime_codes'];

    public function __construct(
        private readonly LegalParameters $legal,
        private readonly EffectiveDated $validity,
    ) {}

    /**
     * Siembra (idempotente) los tipos del paquete del país de la organización activa, con sus reglas
     * iniciales vigentes desde hoy.
     */
    public function ensureCountryTypes(): void
    {
        $country = $this->country();
        $types = self::countryTypes($country);
        if ($types === []) {
            return;
        }
        $existing = AbsenceType::query()->withTrashed()->whereIn('code', array_column($types, 'code'))->pluck('code')->all();
        foreach ($types as $t) {
            if (in_array($t['code'], $existing, true)) {
                continue;
            }
            DB::transaction(function () use ($t, $country): void {
                $type = AbsenceType::query()->create([
                    'code' => $t['code'], 'name' => $t['name'], 'category' => $t['category'], 'country_code' => $country,
                    'is_system' => true, 'is_confidential' => $t['is_confidential'], 'legal_duration_parameter_code' => $t['legal_duration'],
                ]);
                $this->changeRules($type, $t['rules'], now()->toDateString());
            });
        }
    }

    /**
     * @return list<array{code: string, name: string, category: string, is_confidential: bool, legal_duration: string|null, rules: array<string, mixed>}>
     */
    public static function countryTypes(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        $file = base_path('packages/country-'.strtolower($country).'/data/tipos_ausencia.php');
        if (! is_file($file)) {
            return [];
        }
        /** @var array{types: list<array{code: string, name: string, category: string, is_confidential: bool, legal_duration: string|null, rules: array<string, mixed>}>} $data */
        $data = require $file;

        return $data['types'];
    }

    public function rulesAt(AbsenceType $type, CarbonInterface|string $asOf): ?AbsenceTypeRule
    {
        /** @var AbsenceTypeRule|null */
        return AbsenceTypeRule::query()->where('absence_type_id', $type->id)->asOf($asOf)->first();
    }

    /**
     * Registra las reglas desde $from: toma las vigentes a esa fecha, aplica los cambios y cierra la
     * fila anterior (regla 2). Un cambio con la misma fecha de inicio solo reemplaza filas que aún no
     * empezaron o que empiezan hoy; la historia ya vivida no se reescribe.
     *
     * @param  array<string, mixed>  $changes
     */
    public function changeRules(AbsenceType $type, array $changes, string $from): string
    {
        $current = $this->rulesAt($type, $from);
        $values = [];
        foreach (self::RULE_DEFAULTS as $column => $default) {
            $value = array_key_exists($column, $changes) ? $changes[$column] : ($current !== null ? $current->getAttribute($column) : $default);
            if (in_array($column, self::JSON_RULES, true)) {
                $value = is_array($value) && $value !== [] ? json_encode(array_values($value)) : null;
            }
            $values[$column] = $value;
        }
        $values['tenant_id'] = TenancyScope::requireTenant();
        $sameDay = $from >= now()->toDateString() ? EffectiveDated::SAME_DAY_REPLACE : EffectiveDated::SAME_DAY_REJECT;

        return $this->validity->change('absence_type_rules', ['absence_type_id' => $type->id], $values, $from, $sameDay);
    }

    /**
     * @return list<AbsenceTypeRule>
     */
    public function history(AbsenceType $type): array
    {
        /** @var list<AbsenceTypeRule> */
        return array_values(AbsenceTypeRule::query()->where('absence_type_id', $type->id)->orderByDesc('valid_from')->get()->all());
    }

    public static function domain(AbsenceTypeRule $rule): AbsenceTypeRules
    {
        return new AbsenceTypeRules($rule->is_paid, $rule->requestable_by, $rule->legal_entity_ids, $rule->labor_regime_codes);
    }

    /**
     * Códigos de conceptos de planilla del paquete de país (kit/database/catalogos/conceptos_<pais>.json).
     * Lista vacía si el archivo no está disponible: entonces no se restringe el código.
     *
     * @return list<string>
     */
    public function payrollConceptCodes(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        $name = 'conceptos_'.strtolower($country).'.json';
        foreach ([base_path('../../kit/database/catalogos/'.$name), base_path('../kit/database/catalogos/'.$name), '/var/www/kit/database/catalogos/'.$name] as $file) {
            if (is_file($file)) {
                /** @var array{conceptos?: list<array{codigo: string}>} $data */
                $data = json_decode((string) file_get_contents($file), true) ?: [];

                return array_map(fn (array $c): string => $c['codigo'], $data['conceptos'] ?? []);
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function legalParameterCodes(string $country): array
    {
        /** @var list<string> */
        return DB::table('legal_parameters')->where('country_code', $country)->distinct()->orderBy('code')->pluck('code')->all();
    }

    public function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AbsenceType $t, CarbonInterface|string $asOf): array
    {
        $rule = $this->rulesAt($t, $asOf);

        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'category' => $t->category,
            'country_code' => $t->country_code,
            'is_system' => $t->is_system,
            'is_confidential' => $t->is_confidential,
            'legal_duration_parameter_code' => $t->legal_duration_parameter_code,
            'legal_duration' => $this->legalDuration($t, $asOf),
            'status' => $t->status,
            'rules' => $rule === null ? null : $this->presentRule($rule),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRule(AbsenceTypeRule $r): array
    {
        $out = ['id' => $r->id];
        foreach (array_keys(self::RULE_DEFAULTS) as $column) {
            $out[$column] = $r->getAttribute($column);
        }
        $out['max_quantity'] = $r->max_quantity === null ? null : self::number((string) $r->max_quantity);
        $out['deducts_pay'] = self::domain($r)->deductsPay();
        $out['valid_from'] = substr((string) $r->getRawOriginal('valid_from'), 0, 10);
        $raw = $r->getRawOriginal('valid_to');
        $out['valid_to'] = $raw === null ? null : substr((string) $raw, 0, 10);

        return $out;
    }

    /**
     * RN-AUS-04 · Duración legal vigente a la fecha, o «por validar» si el parámetro no tiene valor verificado.
     *
     * @return array<string, mixed>|null
     */
    private function legalDuration(AbsenceType $t, CarbonInterface|string $asOf): ?array
    {
        $code = $t->legal_duration_parameter_code;
        if ($code === null || $code === '') {
            return null;
        }
        $p = $this->legal->at($t->country_code ?? $this->country(), $code, $asOf);
        if ($p === null) {
            return ['parameter_code' => $code, 'value' => null, 'unit' => null, 'verified' => false, 'pending_validation' => true, 'valid_from' => null, 'legal_source' => null];
        }

        return ['parameter_code' => $code, 'value' => $p['value'], 'unit' => $p['unit'], 'verified' => $p['verified'],
            'pending_validation' => $p['pending_validation'], 'valid_from' => $p['valid_from'], 'legal_source' => $p['legal_source']];
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
