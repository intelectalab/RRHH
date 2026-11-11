<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Infrastructure\Models\ContractType;
use App\Shared\LegalParameters\LegalParameters;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CON-1 · Catálogo de tipos de contrato: los del paquete de país se siembran en cada organización
 * (de solo lectura) y sus plazos se resuelven desde los parámetros legales con vigencia.
 */
final class ContractTypeCatalog
{
    public function __construct(private readonly LegalParameters $legal) {}

    /**
     * Siembra (idempotente) los tipos del paquete del país de la organización activa.
     */
    public function ensureCountryTypes(): void
    {
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? '');
        $types = self::countryTypes($country);
        if ($types === []) {
            return;
        }
        $existing = ContractType::query()->withTrashed()->whereIn('code', array_column($types, 'code'))->pluck('code')->all();
        $missing = array_filter($types, fn (array $t) => ! in_array($t['code'], $existing, true));
        foreach ($missing as $t) {
            ContractType::query()->create([
                'code' => $t['code'], 'name' => $t['name'], 'country_code' => $country, 'category' => $t['category'],
                'modality_code' => $t['modality_code'], 'requires_end_date' => $t['requires_end_date'],
                'requires_objective_cause' => $t['requires_objective_cause'], 'max_duration_parameter_code' => $t['max_duration'],
                'trial_period_parameter_code' => $t['trial_period'], 'is_system' => true,
            ]);
        }
    }

    /**
     * @return list<array{code: string, name: string, category: string, modality_code: string|null, requires_end_date: bool, requires_objective_cause: bool, max_duration: string|null, trial_period: string|null}>
     */
    public static function countryTypes(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }
        $file = base_path('packages/country-'.strtolower($country).'/data/tipos_contrato.php');
        if (! is_file($file)) {
            return [];
        }
        /** @var array{types: list<array{code: string, name: string, category: string, modality_code: string|null, requires_end_date: bool, requires_objective_cause: bool, max_duration: string|null, trial_period: string|null}>} $data */
        $data = require $file;

        return $data['types'];
    }

    /**
     * Códigos de parámetros legales del país que puede usar un tipo (para validar los tipos propios).
     *
     * @return list<string>
     */
    public function parameterCodes(string $country): array
    {
        /** @var list<string> */
        return DB::table('legal_parameters')->where('country_code', $country)->distinct()->orderBy('code')->pluck('code')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ContractType $t, CarbonInterface|string $asOf): array
    {
        $country = $t->country_code ?? (string) (DB::table('current_tenant')->value('country_code') ?? '');

        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'country_code' => $t->country_code,
            'category' => $t->category,
            'modality_code' => $t->modality_code,
            'requires_end_date' => $t->requires_end_date,
            'requires_objective_cause' => $t->requires_objective_cause,
            'max_duration_parameter_code' => $t->max_duration_parameter_code,
            'trial_period_parameter_code' => $t->trial_period_parameter_code,
            'document_template_id' => $t->document_template_id,
            'is_system' => $t->is_system,
            'max_duration' => $this->resolve($country, $t->max_duration_parameter_code, $asOf),
            'trial_period' => $this->resolve($country, $t->trial_period_parameter_code, $asOf),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * El plazo vigente a la fecha, o «por validar» si el parámetro no tiene valor verificado.
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $country, ?string $code, CarbonInterface|string $asOf): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        $p = $this->legal->at($country, $code, $asOf);
        if ($p === null) {
            return ['parameter_code' => $code, 'value' => null, 'unit' => null, 'verified' => false, 'pending_validation' => true, 'valid_from' => null, 'legal_source' => null];
        }

        return ['parameter_code' => $code, 'value' => $p['value'], 'unit' => $p['unit'], 'verified' => $p['verified'],
            'pending_validation' => $p['pending_validation'], 'valid_from' => $p['valid_from'], 'legal_source' => $p['legal_source']];
    }
}
