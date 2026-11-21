<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Labor\MasterData;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DEC-8 · Ficha del registro laboral con vigencia (peruvian_employee_profiles) y validación previa al alta.
 *
 * - Guardar nunca sobrescribe: cierra la fila vigente un día antes de la nueva vigencia e inserta otra (regla 2).
 *   Los campos no enviados se copian de la fila vigente.
 * - Los códigos los valida la base contra las tablas paramétricas vigentes a valid_from de la fila
 *   (pe_validate_employee_profile, AY010): un código retirado después no invalida las filas históricas.
 * - Si el trabajador ya fue dado de alta, cambiar un dato declarable crea la novedad de modificación (worker_update).
 * - La validación previa (TR-01 a TR-22, docs/18 §1.5) devuelve cada fallo con su código, campo y pantalla de
 *   origen y deja la ficha vigente «lista» o «bloqueada»; el generador del T-Registro solo toma fichas listas.
 */
final class RegistryProfiles
{
    /** Nombre en el contrato → columna. */
    private const COLUMNS = [
        'worker_type_code' => 'worker_type_code', 'labor_regime_code' => 'labor_regime_sunat_code',
        'occupational_category_code' => 'occupational_category_code', 'occupation_code' => 'occupation_code',
        'education_status_code' => 'education_status_code', 'contract_type_code' => 'contract_type_sunat_code',
        'has_disability' => 'has_disability', 'is_unionized' => 'is_unionized', 'alternative_working_regime' => 'alternative_working_regime',
        'subject_to_max_working_time' => 'subject_to_max_working_time', 'subject_to_night_shift' => 'subject_to_night_shift',
        'special_situation_code' => 'special_situation_code', 'payment_type_code' => 'payment_type_code',
        'pay_periodicity_code' => 'pay_periodicity_code', 'initial_basic_remuneration' => 'initial_basic_remuneration',
        'worker_situation_code' => 'worker_situation_code', 'fifth_category_exempt' => 'fifth_category_exempt',
        'tax_treaty_code' => 'tax_treaty_code', 'is_tax_resident' => 'is_tax_resident', 'eps_code' => 'eps_code',
        'sctr_health' => 'sctr_health', 'sctr_pension' => 'sctr_pension', 'essalud_vida' => 'essalud_vida',
        'agrarian_benefit_payment' => 'agrarian_benefit_payment',
    ];

    public const BOOLEANS = ['has_disability', 'is_unionized', 'alternative_working_regime', 'subject_to_max_working_time',
        'subject_to_night_shift', 'fifth_category_exempt', 'is_tax_resident', 'essalud_vida'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RegistryEvents $events,
        private readonly DeclarationSources $sources,
        private readonly LegalParameters $legal,
        private readonly MasterData $masterData,
    ) {}

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $employmentId): array
    {
        return array_values(DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId)->orderByDesc('valid_from')->get()
            ->map(fn ($r): array => $this->present($r))->all());
    }

    /** @return array<string, mixed> */
    public function show(string $employmentId, string $id): array
    {
        return $this->present($this->row($employmentId, $id));
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function save(string $employmentId, array $in, ?string $userId): array
    {
        $employment = DB::table('employments')->where('id', $employmentId)->first(['legal_entity_id', 'hire_date']);
        if ($employment === null) {
            throw new AyniException('not_found', 404);
        }
        $current = $this->current($employmentId);
        $from = isset($in['valid_from']) ? (string) $in['valid_from']
            : ($current === null ? substr((string) $employment->hire_date, 0, 10) : CarbonImmutable::today()->toDateString());
        if ($current !== null && $from <= substr((string) $current->valid_from, 0, 10)) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.registry_profiles.before_current'),
                [['field' => 'valid_from', 'rule' => 'after', 'message' => (string) trans('payroll.registry_profiles.before_current')]]);
        }
        $values = [];
        $changed = [];
        foreach (self::COLUMNS as $field => $column) {
            $old = $current?->{$column};
            $new = array_key_exists($field, $in) ? $in[$field] : $old;
            if (in_array($field, self::BOOLEANS, true) && $new !== null) {
                $new = (bool) $new;
            }
            if ($new !== null) {
                $values[$column] = $new;
            }
            if ($current !== null && (string) json_encode($new) !== (string) json_encode($old === null ? null : (in_array($field, self::BOOLEANS, true) ? (bool) $old : $old))) {
                $changed[] = $field;
            }
        }
        if ($current !== null && $changed === []) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.registry_profiles.no_changes'),
                [['field' => 'valid_from', 'rule' => 'changed', 'message' => (string) trans('payroll.registry_profiles.no_changes')]]);
        }

        try {
            $id = DB::transaction(function () use ($employmentId, $employment, $current, $from, $values, $changed, $userId): string {
                if ($current !== null && ($current->valid_to === null || substr((string) $current->valid_to, 0, 10) >= $from)) {
                    DB::table('peruvian_employee_profiles')->where('id', $current->id)
                        ->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
                }
                $id = (string) DB::table('peruvian_employee_profiles')->insertGetId($values + [
                    'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employmentId, 'currency' => $current->currency ?? 'PEN',
                    'valid_from' => $from, 'completeness_status' => 'draft',
                ]);
                if ($current !== null && $this->registered($employmentId)) {
                    $this->events->addUpdate((string) $employment->legal_entity_id, $employmentId, $from);
                }
                $this->audit->record('registry_profile.saved', 'registry_profile', $id,
                    $current === null ? null : ['id' => (string) $current->id, 'valid_from' => substr((string) $current->valid_from, 0, 10)],
                    ['valid_from' => $from, 'changed' => $changed, 'created_by_user_id' => $userId]);

                return $id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== 'AY010') {
                throw $e;
            }
            $flip = array_flip(self::COLUMNS);
            $list = trim((string) preg_replace('/^.*vigentes:\s*/s', '', explode("\n", $e->getPrevious()?->getMessage() ?? $e->getMessage())[0]));
            $errors = [];
            foreach (array_filter(array_map('trim', explode(',', $list))) as $column) {
                $column = (string) preg_replace('/\s.*$/', '', $column);
                $field = $flip[$column] ?? $column;
                $errors[] = ['field' => $field, 'rule' => 'parametric_table', 'message' => (string) trans('payroll.registry_profiles.invalid_code', ['field' => $field])];
            }

            throw new AyniException('validation_failed', 422, (string) trans('payroll.registry_profiles.invalid_codes'), $errors);
        }

        return $this->show($employmentId, $id);
    }

    /**
     * Validación previa al alta (TR-01 a TR-22). Cada hallazgo lleva código, campo, pantalla de origen y si bloquea.
     *
     * @return array{employment_id: string, ready: bool, extemporaneous: bool, days_late: int, checks: list<array<string, mixed>>}
     */
    public function check(string $employmentId): array
    {
        $e = DB::table('employments AS e')->join('people AS p', 'p.id', '=', 'e.person_id')->where('e.id', $employmentId)
            ->first(['e.id', 'e.person_id', 'e.legal_entity_id', 'e.hire_date', 'p.birth_date', 'p.sex', 'p.nationality_code', 'p.first_names',
                'p.paternal_surname', 'p.personal_email', 'p.mobile_phone']);
        if ($e === null) {
            throw new AyniException('not_found', 404);
        }
        $hire = substr((string) $e->hire_date, 0, 10);
        $person = '/personas/colaboradores/'.$e->person_id;
        $profileScreen = '/nomina/registro-laboral?employment_id='.$employmentId;
        $out = [];
        $add = function (string $code, string $field, string $screen, string $severity) use (&$out): void {
            $out[] = ['code' => $code, 'field' => $field, 'screen' => $screen, 'severity' => $severity, 'blocking' => $severity === 'error',
                'message' => (string) trans('payroll.registry_checks.'.str_replace('-', '_', strtolower($code)).'.'.$field)];
        };

        // TR-01 · Documento primario con tipo mapeado a la tabla 3 y número con formato.
        $doc = DB::table('person_documents')->where('person_id', $e->person_id)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('created_at')->first();
        if ($doc === null || $this->sources->code('documento_tabla3', (string) $doc->doc_type) === null) {
            $add('TR-01', 'document', $person.'?tab=documentos', 'error');
        } elseif ((string) $doc->doc_type === 'dni' && preg_match('/^\d{8}$/', (string) FieldCipher::decrypt((string) $doc->number_enc)) !== 1) {
            $add('TR-01', 'document_number', $person.'?tab=documentos', 'error');
        }
        // TR-02 · Otra relación activa de la misma persona en la misma razón social.
        if (DB::table('employments')->where('person_id', $e->person_id)->where('legal_entity_id', $e->legal_entity_id)->where('id', '<>', $employmentId)
            ->whereNull('deleted_at')->whereIn('status', ['active', 'suspended', 'terminating'])->exists()) {
            $add('TR-02', 'employment', $person.'?tab=relaciones', 'error');
        }
        // TR-03 · Fecha de nacimiento; un menor de edad exige autorización adjunta (advertencia para revisión).
        if ($e->birth_date === null) {
            $add('TR-03', 'birth_date', $person.'?tab=datos', 'error');
        } else {
            $majority = $this->legal->at('PE', 'mayoria_edad_anios', $hire);
            if ($majority !== null && is_numeric($majority['value']) && CarbonImmutable::parse((string) $e->birth_date)->diffInYears(CarbonImmutable::parse($hire)) < (int) $majority['value']) {
                $add('TR-03', 'minor_authorization', $person.'?tab=documentos', 'warning');
            }
        }
        // TR-04 · Sexo informado.
        if ($e->sex === null || (string) $e->sex === 'unspecified' || $this->sources->code('sexo_tabla', (string) $e->sex) === null) {
            $add('TR-04', 'sex', $person.'?tab=datos', 'error');
        }
        // TR-05 · Nacionalidad y país emisor cuando el documento no es DNI.
        if ($doc !== null && (string) $doc->doc_type !== 'dni' && ($e->nationality_code === null || (string) $doc->issuing_country === '')) {
            $add('TR-05', 'nationality', $person.'?tab=datos', 'error');
        }
        // TR-06 · Dirección estructurada con ubigeo.
        $address = DB::table('person_addresses AS a')->join('peruvian_person_addresses AS pa', 'pa.person_address_id', '=', 'a.id')
            ->where('a.person_id', $e->person_id)->whereNull('a.deleted_at')->exists();
        if (! $address) {
            $add('TR-06', 'address', $person.'?tab=direcciones', 'error');
        }
        // TR-07 · Teléfono y correo.
        if ($e->mobile_phone === null || trim((string) $e->mobile_phone) === '') {
            $add('TR-07', 'phone', $person.'?tab=datos', 'error');
        }
        if ($e->personal_email === null || trim((string) $e->personal_email) === '') {
            $add('TR-07', 'email', $person.'?tab=datos', 'error');
        }
        // TR-08 · Ficha del registro laboral vigente en la fecha de ingreso.
        $profile = DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId)->where('valid_from', '<=', $hire)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $hire))->first();
        if ($profile === null) {
            $add('TR-08', 'registry_profile', $profileScreen, 'error');
        }
        // TR-09 · Régimen de la ficha coherente con el régimen laboral vigente (mapa del paquete de país).
        $regime = DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->whereNull('deleted_at')->where('valid_from', '<=', $hire)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $hire))->value('labor_regime_code');
        $expected = $this->sources->code('regimen_tabla33', is_string($regime) ? $regime : null);
        if ($profile !== null && $expected !== null && (string) $profile->labor_regime_sunat_code !== $expected) {
            $add('TR-09', 'labor_regime_code', $profileScreen, 'error');
        }
        // TR-11 · Contrato vigente en la fecha de ingreso.
        if (! DB::table('contracts')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->whereNotIn('status', ['draft', 'pending_approval', 'superseded', 'cancelled'])->where('valid_from', '<=', $hire)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $hire))->exists()) {
            $add('TR-11', 'contract', $person.'?tab=contratos', 'error');
        }
        // TR-12 · Afiliación pensionaria vigente; en el SPP, CUSPP con la longitud del paquete de país.
        $pension = $this->sources->pension($employmentId, $hire);
        $length = (int) ($this->sources->pack()['cuspp_longitud'] ?? 0);
        if ($pension === null) {
            $add('TR-12', 'pension', $person.'?tab=previsional', 'error');
        } elseif ($pension['system'] === 'afp' && ($pension['cuspp'] === null || ($length > 0 && strlen($pension['cuspp']) !== $length))) {
            $add('TR-12', 'cuspp', $person.'?tab=previsional', 'error');
        }
        // TR-13 · Régimen de salud vigente; EPS informada si corresponde.
        $health = DB::table('employment_labor_regimes')->where('employment_id', $employmentId)->whereNull('deleted_at')->where('valid_from', '<=', $hire)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $hire))->value('health_scheme');
        if ($health === null) {
            $add('TR-13', 'health_regime', $person.'?tab=laboral', 'error');
        } elseif ($health === 'eps' && ($profile->eps_code ?? null) === null) {
            $add('TR-13', 'eps_code', $profileScreen, 'error');
        }
        // TR-16 · Tipo de pago con depósito ⇒ cuenta bancaria vigente.
        $withAccount = (array) ($this->sources->pack()['tipo_pago_con_cuenta'] ?? []);
        if ($profile !== null && isset($withAccount[(string) $profile->payment_type_code])
            && ! DB::table('person_bank_accounts')->where('employment_id', $employmentId)->where('purpose', 'salary')->where('status', 'active')->whereNull('deleted_at')->exists()) {
            $add('TR-16', 'bank_account', $person.'?tab=cuentas', 'error');
        }
        // TR-17 · Remuneración inicial igual a la básica vigente en la fecha de ingreso (primera compensación, HIS-6).
        $first = $this->masterData->salaryAt($employmentId, $hire)['amount'] ?? null;
        if ($profile !== null && $first !== null && ! BigDecimal::of((string) $profile->initial_basic_remuneration)->isEqualTo((string) $first)) {
            $add('TR-17', 'initial_basic_remuneration', $profileScreen, 'error');
        }
        // TR-19 · Dirección o confianza ⇒ comunicación de la calificación adjunta (advertencia).
        if ($profile !== null && ! in_array((string) $profile->special_situation_code, ['0', ''], true)) {
            $add('TR-19', 'special_situation_code', $person.'?tab=documentos', 'warning');
        }
        // TR-22 · Alta extemporánea (solo alerta).
        $today = CarbonImmutable::today();
        $late = CarbonImmutable::parse($hire)->lt($today) && ! $this->registered($employmentId) ? (int) CarbonImmutable::parse($hire)->diffInDays($today) : 0;
        if ($late > 0) {
            $add('TR-22', 'hire_date', $person.'?tab=laboral', 'alert');
        }
        $blocking = array_values(array_filter($out, fn (array $c): bool => $c['blocking']));

        return ['employment_id' => $employmentId, 'ready' => $blocking === [], 'extemporaneous' => $late > 0, 'days_late' => $late, 'checks' => $out];
    }

    /**
     * Valida la ficha vigente: la deja lista o bloqueada con la lista de faltantes.
     *
     * @return array<string, mixed>
     */
    public function validate(string $employmentId, string $id, ?string $userId): array
    {
        $row = $this->row($employmentId, $id);
        $report = $this->check($employmentId);
        $missing = array_values(array_unique(array_map(fn (array $c): string => $c['code'].':'.$c['field'], array_filter($report['checks'], fn (array $c): bool => $c['blocking']))));
        $status = $report['ready'] ? 'ready' : 'blocked';
        DB::transaction(function () use ($row, $status, $missing, $userId): void {
            DB::table('peruvian_employee_profiles')->where('id', $row->id)->update(['completeness_status' => $status, 'missing_fields' => '{'.implode(',', $missing).'}']);
            $this->audit->record('registry_profile.validated', 'registry_profile', (string) $row->id,
                ['completeness' => (string) $row->completeness_status], ['completeness' => $status, 'missing_fields' => $missing, 'validated_by_user_id' => $userId]);
        });

        return $this->show($employmentId, $id) + ['validation' => $report];
    }

    private function registered(string $employmentId): bool
    {
        return DB::table('peruvian_registry_periods')->where('employment_id', $employmentId)->where('record_type', 'period')->whereNotNull('declared_at')->exists()
            || DB::table('registry_events')->where('employment_id', $employmentId)->where('kind', 'worker_registration')->whereIn('status', ['included', 'submitted', 'confirmed'])->exists();
    }

    private function current(string $employmentId): ?\stdClass
    {
        return DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId)->orderByDesc('valid_from')->first();
    }

    private function row(string $employmentId, string $id): \stdClass
    {
        $r = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId)->where('id', $id)->first() : null;
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function present(\stdClass $r): array
    {
        $out = ['id' => (string) $r->id, 'employment_id' => (string) $r->employment_id];
        foreach (self::COLUMNS as $field => $column) {
            $v = $r->{$column};
            $out[$field] = in_array($field, self::BOOLEANS, true) ? (bool) $v : ($v === null ? null : (string) $v);
        }
        $out['initial_basic_remuneration'] = ['amount' => (string) $r->initial_basic_remuneration, 'currency' => (string) $r->currency];
        $out['completeness'] = (string) $r->completeness_status;
        $out['missing_fields'] = $r->missing_fields === null ? [] : array_values(array_filter(explode(',', trim((string) $r->missing_fields, '{}'))));
        $out['valid_from'] = substr((string) $r->valid_from, 0, 10);
        $out['valid_to'] = $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10);
        $out['created_at'] = CarbonImmutable::parse((string) $r->created_at)->toIso8601ZuluString();
        $out['updated_at'] = CarbonImmutable::parse((string) $r->updated_at)->toIso8601ZuluString();

        return $out;
    }
}
