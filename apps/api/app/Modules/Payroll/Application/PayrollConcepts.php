<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Engine\ConceptDefinition;
use App\Modules\Payroll\Domain\PayrollConceptStateMachine;
use App\Modules\Payroll\Infrastructure\ConceptCatalog;
use App\Modules\Payroll\Infrastructure\Country\CountryConceptTemplate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CPT-1 · CPT-2 · CPT-3 · Catálogo de conceptos de planilla (M-CPT). Un concepto tiene sus datos (código, tipo,
 * naturaleza remunerativa, código de la declaración) y versiones con vigencia: fórmula, condición, redondeo,
 * regímenes, tipos de proceso y afectaciones (a qué bases suma). Los cambios se preparan en un borrador que se
 * valida y se prueba; publicarlo cierra la versión vigente e inserta otra desde su fecha, así los periodos
 * anteriores siguen con la versión de su fecha (RN-CPT-01). Un concepto con historia no se borra: se retira
 * (RN-CPT-02). El siguiente cálculo usa lo publicado sin desplegar nada (ConceptCatalog).
 */
final class PayrollConcepts
{
    public const PERIOD_KINDS = ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'];

    public const PROJECTION_KINDS = ['ordinary', 'gratification', 'extraordinary'];

    /** Base de la proyección de quinta: un concepto que la afecta declara su naturaleza (RN-CPT-09). */
    private const FIFTH_BASE = 'quinta';

    public function __construct(
        private readonly CountryConceptTemplate $template,
        private readonly FormulaValidator $validator,
        private readonly PayrollConceptStateMachine $machine,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array{status?: string|null, kind?: string|null, search?: string|null, as_of?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $asOf = $filters['as_of'] ?? CarbonImmutable::today()->toDateString();
        $q = DB::table('payroll_concepts')->whereNull('deleted_at');
        foreach (['status', 'kind'] as $f) {
            if (($filters[$f] ?? null) !== null && $filters[$f] !== '') {
                $q->where($f, $filters[$f]);
            }
        }
        if (($filters['search'] ?? '') !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']).'%';
            $q->where(fn ($w) => $w->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }
        $rows = $q->orderBy('sort_order')->orderBy('code')->get();
        $ids = array_values(array_map(fn ($v) => (string) $v, $rows->pluck('id')->all()));
        $versions = $this->currentVersions($ids, $asOf);
        $affects = $this->affectations(array_values(array_map(fn ($v) => (string) $v->id, $versions)));
        $drafts = DB::table('payroll_concept_drafts')->whereIn('payroll_concept_id', $ids)->pluck('payroll_concept_id')->flip();

        return array_values($rows->map(fn ($c) => $this->summary($c, $versions[(string) $c->id] ?? null,
            isset($versions[(string) $c->id]) ? ($affects[(string) $versions[(string) $c->id]->id] ?? []) : [], isset($drafts[$c->id])))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id, ?string $asOf = null): array
    {
        $c = $this->find($id);
        $asOf ??= CarbonImmutable::today()->toDateString();
        $version = $this->currentVersions([$id], $asOf)[$id] ?? null;
        $affects = $version === null ? [] : ($this->affectations([(string) $version->id])[(string) $version->id] ?? []);
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        $out = $this->summary($c, $version, $affects, $draft !== null);
        $out['draft'] = $draft === null ? null : $this->draftOut($draft);
        $out['versions'] = $this->versions($id);
        $out['accounting'] = app(ConceptAccounting::class)->rules($id);
        $out['statutory_mappings'] = DB::table('concept_statutory_mappings')->where('payroll_concept_id', $id)->orderByDesc('valid_from')
            ->get(['filing_kind', 'statutory_code', 'verified', 'valid_from', 'valid_to'])
            ->map(fn ($m) => ['filing_kind' => $m->filing_kind, 'statutory_code' => $m->statutory_code, 'verified' => (bool) $m->verified,
                'valid_from' => substr((string) $m->valid_from, 0, 10), 'valid_to' => $m->valid_to === null ? null : substr((string) $m->valid_to, 0, 10)])->all();
        if ($c->is_system) {
            $tpl = $this->template->concept($this->country(), (string) $c->code);
            $out['template'] = $tpl === null ? null : ['formula' => $tpl['formula'] ?? null, 'note' => $tpl['nota'] ?? '', 'parameters' => $tpl['parametros_legales'] ?? []];
        }

        return $out;
    }

    /**
     * CPT-1 · Concepto nuevo (propio de la organización) con su borrador: se usa en el cálculo cuando se publica.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $userId): array
    {
        $code = (string) $input['code'];
        if (DB::table('payroll_concepts')->where('code', $code)->exists()) {
            throw self::invalid('code', 'unique', (string) trans('payroll.concepts.code_taken', ['code' => $code]));
        }
        $this->assertRegimes($input['applies_to_regimes'] ?? null);
        $affects = $this->normalizeAffectations((array) ($input['affectations'] ?? []));

        return DB::transaction(function () use ($input, $code, $userId, $affects): array {
            $tenant = TenancyScope::requireTenant();
            $id = (string) DB::table('payroll_concepts')->insertGetId([
                'tenant_id' => $tenant, 'code' => $code, 'name' => $input['name'], 'kind' => $input['kind'],
                'category' => $input['category'] ?? null, 'country_code' => $this->country(), 'statutory_code' => $input['statutory_code'] ?? null,
                'is_system' => false, 'is_remunerative' => (bool) ($input['is_remunerative'] ?? true),
                'calculation_method' => ($input['formula'] ?? '') !== '' ? 'formula' : 'input', 'print_on_payslip' => (bool) ($input['print_on_payslip'] ?? true),
                'sort_order' => (int) ($input['sort_order'] ?? 0), 'status' => 'draft', 'created_by_user_id' => $userId,
            ]);
            DB::table('payroll_concept_drafts')->insert([
                'tenant_id' => $tenant, 'payroll_concept_id' => $id, 'formula' => self::text($input['formula'] ?? null),
                'condition' => self::text($input['condition'] ?? null), 'rounding' => $input['rounding'] ?? 'half_up',
                'applies_to_regimes' => self::pg($input['applies_to_regimes'] ?? null), 'applies_to_period_kinds' => self::pg($input['applies_to_period_kinds'] ?? null),
                'affectations' => json_encode($affects), 'valid_from' => $input['valid_from'] ?? CarbonImmutable::today()->startOfMonth()->toDateString(),
                'notes' => $input['notes'] ?? null, 'edited_by_user_id' => $userId,
            ]);
            $this->syncStatutory($id, $input['statutory_code'] ?? null, $input['valid_from'] ?? CarbonImmutable::today()->toDateString(), false);
            $this->audit->record('payroll_concept.created', 'payroll_concept', $id, null, ['code' => $code, 'name' => $input['name'], 'kind' => $input['kind']]);
            $this->outbox->record(DomainEventTypes::PAYROLL_CONCEPT_CREATED, 'payroll_concept', $id, ['payroll_concept_id' => $id, 'code' => $code]);

            return $this->show($id);
        });
    }

    /**
     * CPT-1 · CPT-2 · Edita los datos del concepto y su borrador (si no hay, lo crea desde la versión vigente).
     * RN-CPT-03: en un concepto del paquete de país solo cambian nombre, orden, impresión y cuentas; la
     * afectación legal no (se duplica como concepto propio).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, ?string $userId): array
    {
        $c = $this->find($id);
        if ($c->status === 'inactive') {
            throw new InvalidTransitionException('inactive', $this->machine->allowedActions('inactive'), (string) trans('payroll.concepts.retired'));
        }
        $this->assertRegimes($input['applies_to_regimes'] ?? null);

        return DB::transaction(function () use ($c, $id, $input, $userId): array {
            $before = (array) $c;
            $data = array_intersect_key($input, array_flip(['name', 'category', 'print_on_payslip', 'sort_order', 'statutory_code', 'is_remunerative']));
            if ($c->is_system && array_key_exists('is_remunerative', $data) && (bool) $data['is_remunerative'] !== (bool) $c->is_remunerative) {
                throw self::invalid('is_remunerative', 'pack_locked', (string) trans('payroll.concepts.pack_locked'));
            }
            if ($data !== []) {
                DB::table('payroll_concepts')->where('id', $id)->update($data);
            }
            if (array_key_exists('statutory_code', $data)) {
                $this->syncStatutory($id, $data['statutory_code'], CarbonImmutable::today()->toDateString(), false);
            }
            $draftKeys = ['formula', 'condition', 'rounding', 'applies_to_regimes', 'applies_to_period_kinds', 'affectations', 'valid_from', 'notes'];
            if (array_intersect_key($input, array_flip($draftKeys)) !== []) {
                $draft = $this->ensureDraft($id, $userId);
                $row = ['edited_by_user_id' => $userId, 'validation' => null];
                foreach (['formula', 'condition'] as $k) {
                    if (array_key_exists($k, $input)) {
                        $row[$k] = self::text($input[$k]);
                    }
                }
                foreach (['rounding', 'valid_from', 'notes'] as $k) {
                    if (array_key_exists($k, $input)) {
                        $row[$k] = $input[$k];
                    }
                }
                foreach (['applies_to_regimes', 'applies_to_period_kinds'] as $k) {
                    if (array_key_exists($k, $input)) {
                        $row[$k] = self::pg($input[$k]);
                    }
                }
                if (array_key_exists('affectations', $input)) {
                    $new = $this->normalizeAffectations((array) $input['affectations']);
                    if ($c->is_system && self::legalShape($new) !== self::legalShape(self::draftAffectations($draft))) {
                        throw self::invalid('affectations', 'pack_locked', (string) trans('payroll.concepts.pack_locked'));
                    }
                    $row['affectations'] = json_encode($new);
                }
                DB::table('payroll_concept_drafts')->where('id', $draft->id)->update($row);
            }
            $this->audit->record('payroll_concept.updated', 'payroll_concept', $id, $before, $input);

            return $this->show($id);
        });
    }

    /**
     * CPT-2 · RN-CPT-04 · RN-CPT-06 · RN-CPT-09 · Revisión del borrador: fórmula y condición válidas (sintaxis,
     * referencias, ciclos), naturaleza en la proyección de quinta si la afecta y sustento si un concepto no
     * remunerativo suma a una base. Se guarda en el borrador para mostrarla en el editor.
     *
     * @return array<string, mixed>
     */
    public function validateDraft(string $id): array
    {
        $c = $this->find($id);
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        if ($draft === null) {
            throw new InvalidTransitionException((string) $c->status, $this->machine->allowedActions((string) $c->status), (string) trans('payroll.concepts.no_draft'));
        }
        $result = $this->review($c, $draft);
        DB::table('payroll_concept_drafts')->where('id', $draft->id)->update(['validation' => json_encode($result)]);

        return $result;
    }

    /**
     * CPT-2 · Publica el borrador con su fecha: cierra la versión vigente el día anterior e inserta la nueva con
     * sus afectaciones (RN-CPT-01). No se publica con errores (RN-CPT-04) y, con el doble control activo, no lo
     * publica quien lo editó (RN-CPT-07).
     *
     * @return array<string, mixed>
     */
    public function publish(string $id, ?string $userId): array
    {
        $c = $this->find($id);
        $this->machine->getNextState((string) $c->status, 'publish');
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        if ($draft === null) {
            throw new InvalidTransitionException((string) $c->status, $this->machine->allowedActions((string) $c->status), (string) trans('payroll.concepts.no_draft'));
        }
        $dual = $this->parameters->value('nomina.doble_control_conceptos');
        if (($dual === null || (bool) $dual) && $draft->edited_by_user_id !== null && $draft->edited_by_user_id === $userId) {
            throw new AyniException('dual_control_required', 403, (string) trans('payroll.concepts.dual_control'));
        }
        $review = $this->review($c, $draft);
        if (! $review['valid']) {
            throw new AyniException('concept_invalid', 422, (string) trans('payroll.concepts.invalid'), array_map(
                fn ($e) => ['field' => (string) ($e['part'] ?? 'formula'), 'rule' => (string) $e['code'], 'message' => (string) $e['message']], $review['errors']), ['errors' => $review['errors']]);
        }
        $validFrom = substr((string) $draft->valid_from, 0, 10);

        return DB::transaction(function () use ($c, $id, $draft, $validFrom, $userId, $review): array {
            $tenant = TenancyScope::requireTenant();
            DB::table('payroll_concepts')->where('id', $id)->lockForUpdate()->first(['id']);
            if (DB::table('payroll_concept_versions')->where('payroll_concept_id', $id)->where('valid_from', '>=', $validFrom)->exists()) {
                throw self::invalid('valid_from', 'after_current', (string) trans('payroll.concepts.valid_from_not_after'));
            }
            $prev = CarbonImmutable::parse($validFrom)->subDay()->toDateString();
            DB::table('payroll_concept_versions')->where('payroll_concept_id', $id)
                ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom))->update(['valid_to' => $prev]);
            $versionId = (string) DB::table('payroll_concept_versions')->insertGetId([
                'tenant_id' => $tenant, 'payroll_concept_id' => $id, 'formula' => $draft->formula, 'condition' => $draft->condition,
                'rounding' => $draft->rounding, 'applies_to_regimes' => $draft->applies_to_regimes, 'applies_to_period_kinds' => $draft->applies_to_period_kinds,
                'parameters_used' => self::pg($review['references']['param']), 'depends_on' => self::pg($review['depends_on']),
                'notes' => $draft->notes, 'edited_by_user_id' => $draft->edited_by_user_id, 'published_by_user_id' => $userId, 'published_at' => now(),
                'valid_from' => $validFrom,
            ]);
            foreach (self::draftAffectations($draft) as $a) {
                DB::table('payroll_concept_affectations')->insert([
                    'tenant_id' => $tenant, 'payroll_concept_version_id' => $versionId, 'base_code' => $a['base_code'], 'affects' => $a['affects'],
                    'projection_kind' => $a['projection_kind'], 'justification' => $a['justification'],
                ]);
            }
            DB::table('payroll_concept_drafts')->where('id', $draft->id)->delete();
            DB::table('payroll_concepts')->where('id', $id)->update([
                'status' => $this->machine->getNextState((string) $c->status, 'publish'),
                'calculation_method' => $draft->formula === null || trim((string) $draft->formula) === '' ? 'input' : 'formula',
            ]);
            $this->audit->record('payroll_concept.version_published', 'payroll_concept', $id, null, ['version_id' => $versionId, 'valid_from' => $validFrom, 'formula' => $draft->formula]);
            $this->outbox->record(DomainEventTypes::PAYROLL_CONCEPT_VERSION_PUBLISHED, 'payroll_concept', $id,
                ['payroll_concept_id' => $id, 'code' => (string) $c->code, 'version_id' => $versionId, 'valid_from' => $validFrom]);

            return $this->show($id);
        });
    }

    /**
     * CPT-1 · RN-CPT-02 · Retirar: deja de usarse en los cálculos desde ahora; su historia sigue consultable.
     *
     * @return array<string, mixed>
     */
    public function retire(string $id, ?string $reason): array
    {
        $c = $this->find($id);
        $next = $this->machine->getNextState((string) $c->status, 'retire');

        return DB::transaction(function () use ($c, $id, $next, $reason): array {
            DB::table('payroll_concepts')->where('id', $id)->update(['status' => $next, 'archived_at' => now(), 'archive_reason' => $reason]);
            $this->audit->record('payroll_concept.archived', 'payroll_concept', $id, ['status' => $c->status], ['status' => $next], $reason);
            $this->outbox->record(DomainEventTypes::PAYROLL_CONCEPT_ARCHIVED, 'payroll_concept', $id, ['payroll_concept_id' => $id, 'code' => (string) $c->code]);

            return $this->show($id);
        });
    }

    /**
     * CPT-1 · RN-CPT-03 · Duplicar: concepto propio con la fórmula, condición y afectaciones del original (de su
     * borrador o su versión vigente). Es la vía para cambiar la afectación legal de uno del paquete de país.
     *
     * @return array<string, mixed>
     */
    public function duplicate(string $id, string $code, string $name, ?string $userId): array
    {
        $c = $this->find($id);
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        $version = $this->currentVersions([$id], CarbonImmutable::today()->toDateString())[$id] ?? null;
        $affects = $draft !== null ? self::draftAffectations($draft) : ($version === null ? [] : ($this->affectations([(string) $version->id])[(string) $version->id] ?? []));
        $source = $draft ?? $version;

        return $this->create([
            'code' => $code, 'name' => $name, 'kind' => $c->kind, 'category' => $c->category, 'is_remunerative' => (bool) $c->is_remunerative,
            'statutory_code' => $c->statutory_code, 'print_on_payslip' => (bool) $c->print_on_payslip, 'sort_order' => (int) $c->sort_order,
            'formula' => $source->formula ?? null, 'condition' => $source->condition ?? null, 'rounding' => $source->rounding ?? 'half_up',
            'applies_to_regimes' => $source === null ? null : ConceptCatalog::pgArray($source->applies_to_regimes),
            'applies_to_period_kinds' => $source === null ? null : ConceptCatalog::pgArray($source->applies_to_period_kinds),
            'affectations' => $affects, 'notes' => (string) trans('payroll.concepts.copy_of', ['code' => (string) $c->code]),
        ], $userId);
    }

    /**
     * CPT-2 · Concepto tal como se probaría: el borrador (si lo hay) o la versión vigente, con sus afectaciones.
     */
    public function candidate(string $id): ConceptDefinition
    {
        $c = $this->find($id);
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        $src = $draft ?? ($this->currentVersions([$id], CarbonImmutable::today()->toDateString())[$id] ?? null);
        if ($src === null) {
            throw new InvalidTransitionException((string) $c->status, $this->machine->allowedActions((string) $c->status), (string) trans('payroll.concepts.no_draft'));
        }
        $affects = $draft !== null ? self::draftAffectations($draft) : ($this->affectations([(string) $src->id])[(string) $src->id] ?? []);

        return new ConceptDefinition((string) $c->code, (string) $c->name, (string) $c->kind, $src->formula === null ? null : (string) $src->formula,
            (string) $src->rounding, (int) $c->sort_order, ConceptCatalog::pgArray($src->applies_to_regimes), ConceptCatalog::pgArray($src->applies_to_period_kinds),
            $id, $draft === null ? (string) $src->id : null, substr((string) ($src->valid_from ?? ''), 0, 10) ?: null,
            $src->condition === null || trim((string) $src->condition) === '' ? null : (string) $src->condition,
            array_values(array_map(fn ($a) => $a['base_code'], array_filter($affects, fn ($a) => $a['affects']))));
    }

    /**
     * CPT-3 · Matriz conceptos × bases a una fecha (lo publicado; un borrador se marca).
     *
     * @return array{bases: list<array{code: string, name: string}>, concepts: list<array<string, mixed>>}
     */
    public function matrix(?string $asOf): array
    {
        $rows = $this->list(['as_of' => $asOf, 'status' => null]);
        $bases = [];
        foreach ($this->template->bases($this->country()) as $code => $name) {
            $bases[] = ['code' => (string) $code, 'name' => (string) trans('payroll.concepts.bases.'.$code) !== 'payroll.concepts.bases.'.$code ? (string) trans('payroll.concepts.bases.'.$code) : (string) $name];
        }

        return ['bases' => $bases, 'concepts' => array_map(fn ($r) => ['id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'],
            'is_remunerative' => $r['is_remunerative'], 'status' => $r['status'], 'has_draft' => $r['has_draft'], 'affectations' => $r['affectations']], $rows)];
    }

    /**
     * @return list<array{code: string, name: string, requires_projection: bool}>
     */
    public function bases(): array
    {
        $out = [];
        foreach ($this->template->bases($this->country()) as $code => $name) {
            $label = (string) trans('payroll.concepts.bases.'.$code);
            $out[] = ['code' => (string) $code, 'name' => $label !== 'payroll.concepts.bases.'.$code ? $label : (string) $name, 'requires_projection' => $code === self::FIFTH_BASE];
        }

        return $out;
    }

    // ───────────────────────────────────────── internos

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, references: array<string, list<string>>, depends_on: list<string>, order: list<string>}
     */
    private function review(\stdClass $c, \stdClass $draft): array
    {
        $affects = self::draftAffectations($draft);
        $active = array_values(array_map(fn ($a) => $a['base_code'], array_filter($affects, fn ($a) => $a['affects'])));
        $formula = $draft->formula === null ? '' : (string) $draft->formula;
        $asOf = substr((string) $draft->valid_from, 0, 10);
        if (trim($formula) === '') {
            // Concepto ingresado: toma el monto de sus incidencias; solo se revisa la condición.
            $r = $draft->condition === null ? ['valid' => true, 'errors' => [], 'references' => ['concept' => [], 'param' => [], 'incident' => [], 'quantity' => [], 'base' => [], 'var' => []], 'depends_on' => [], 'order' => []]
                : $this->validator->validate('0', (string) $c->code, $asOf, (string) $draft->condition, $active);
        } else {
            $r = $this->validator->validate($formula, (string) $c->code, $asOf, $draft->condition === null ? null : (string) $draft->condition, $active);
        }
        $errors = $r['errors'];
        $bases = $this->template->bases($this->country());
        foreach ($affects as $a) {
            if (! isset($bases[$a['base_code']])) {
                $errors[] = ['part' => 'affectations', 'code' => 'unknown_base', 'position' => 0, 'message' => (string) trans('payroll.formula.unknown_base', ['name' => $a['base_code']])];
            }
            if ($a['affects'] && $a['base_code'] === self::FIFTH_BASE && $a['projection_kind'] === null) {
                $errors[] = ['part' => 'affectations', 'code' => 'projection_required', 'position' => 0, 'message' => (string) trans('payroll.concepts.projection_required')];
            }
            if ($a['affects'] && ! $c->is_remunerative && ($a['justification'] === null || trim($a['justification']) === '')) {
                $errors[] = ['part' => 'affectations', 'code' => 'justification_required', 'position' => 0,
                    'message' => (string) trans('payroll.concepts.justification_required', ['base' => $a['base_code']])];
            }
        }
        $warnings = [];
        if ($c->statutory_code === null && in_array($c->kind, ['earning', 'deduction', 'employee_contribution', 'employer_contribution', 'tax'], true)) {
            $warnings[] = ['code' => 'statutory_code_missing', 'message' => (string) trans('payroll.concepts.statutory_missing')];
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'references' => $r['references'] + ['param' => []],
            'depends_on' => $r['depends_on'], 'order' => $r['order']];
    }

    private function find(string $id): \stdClass
    {
        $c = DB::table('payroll_concepts')->where('id', $id)->whereNull('deleted_at')->first();
        if ($c === null) {
            throw new AyniException('not_found', 404);
        }

        return $c;
    }

    private function ensureDraft(string $id, ?string $userId): \stdClass
    {
        $draft = DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->first();
        if ($draft !== null) {
            return $draft;
        }
        $today = CarbonImmutable::today();
        $version = $this->currentVersions([$id], $today->toDateString())[$id] ?? null;
        $affects = $version === null ? [] : ($this->affectations([(string) $version->id])[(string) $version->id] ?? []);
        // La versión nueva empieza por defecto el primer día del mes siguiente (después de la vigente).
        DB::table('payroll_concept_drafts')->insert([
            'tenant_id' => TenancyScope::requireTenant(), 'payroll_concept_id' => $id, 'formula' => $version->formula ?? null,
            'condition' => $version->condition ?? null, 'rounding' => $version->rounding ?? 'half_up',
            'applies_to_regimes' => $version->applies_to_regimes ?? null, 'applies_to_period_kinds' => $version->applies_to_period_kinds ?? null,
            'affectations' => json_encode($affects), 'valid_from' => $today->addMonthNoOverflow()->startOfMonth()->toDateString(), 'edited_by_user_id' => $userId,
        ]);

        return DB::table('payroll_concept_drafts')->where('payroll_concept_id', $id)->firstOrFail();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, \stdClass>
     */
    private function currentVersions(array $ids, string $asOf): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        // La vigente a la fecha; si no hay (concepto que empieza después), la más reciente.
        $rows = DB::table('payroll_concept_versions')->whereIn('payroll_concept_id', $ids)->orderBy('valid_from')->get();
        foreach ($rows as $v) {
            $from = substr((string) $v->valid_from, 0, 10);
            $to = $v->valid_to === null ? null : substr((string) $v->valid_to, 0, 10);
            $key = (string) $v->payroll_concept_id;
            if ($from <= $asOf && ($to === null || $to >= $asOf)) {
                $out[$key] = $v;
            } elseif (! isset($out[$key]) || (substr((string) $out[$key]->valid_from, 0, 10) > $asOf)) {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $versionIds
     * @return array<string, list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}>>
     */
    private function affectations(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('payroll_concept_affectations')->whereIn('payroll_concept_version_id', $versionIds)->orderBy('base_code')->get() as $a) {
            $out[(string) $a->payroll_concept_version_id][] = ['base_code' => (string) $a->base_code, 'affects' => (bool) $a->affects,
                'projection_kind' => $a->projection_kind === null ? null : (string) $a->projection_kind, 'justification' => $a->justification === null ? null : (string) $a->justification];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versions(string $id): array
    {
        return array_values(DB::table('payroll_concept_versions AS v')->leftJoin('users AS u', 'u.id', '=', 'v.published_by_user_id')
            ->where('v.payroll_concept_id', $id)->orderByDesc('v.valid_from')
            ->get(['v.id', 'v.formula', 'v.condition', 'v.rounding', 'v.valid_from', 'v.valid_to', 'v.published_at', 'u.display_name AS published_by_name'])
            ->map(fn ($v) => ['id' => (string) $v->id, 'formula' => $v->formula, 'condition' => $v->condition, 'rounding' => $v->rounding,
                'valid_from' => substr((string) $v->valid_from, 0, 10), 'valid_to' => $v->valid_to === null ? null : substr((string) $v->valid_to, 0, 10),
                'published_at' => $v->published_at === null ? null : CarbonImmutable::parse((string) $v->published_at)->toIso8601String(), 'published_by_name' => $v->published_by_name])
            ->all());
    }

    /**
     * @param  list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}>  $affects
     * @return array<string, mixed>
     */
    private function summary(\stdClass $c, ?\stdClass $version, array $affects, bool $hasDraft): array
    {
        $status = (string) $c->status;

        return [
            'id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'kind' => (string) $c->kind,
            'category' => $c->category, 'is_system' => (bool) $c->is_system, 'is_remunerative' => (bool) $c->is_remunerative,
            'calculation_method' => (string) $c->calculation_method, 'print_on_payslip' => (bool) $c->print_on_payslip, 'sort_order' => (int) $c->sort_order,
            'statutory_code' => $c->statutory_code, 'statutory_code_verified' => (bool) $c->statutory_code_verified, 'pack_version' => $c->pack_version,
            'status' => $status, 'formula' => $version?->formula, 'condition' => $version?->condition, 'rounding' => $version?->rounding,
            'applies_to_regimes' => $version === null ? null : ConceptCatalog::pgArray($version->applies_to_regimes),
            'applies_to_period_kinds' => $version === null ? null : ConceptCatalog::pgArray($version->applies_to_period_kinds),
            'depends_on' => $version === null ? [] : (ConceptCatalog::pgArray($version->depends_on) ?? []),
            'affectations' => $affects, 'version_id' => $version === null ? null : (string) $version->id,
            'valid_from' => $version === null ? null : substr((string) $version->valid_from, 0, 10),
            'valid_to' => $version === null || $version->valid_to === null ? null : substr((string) $version->valid_to, 0, 10),
            'has_draft' => $hasDraft, 'archived_at' => $c->archived_at === null ? null : CarbonImmutable::parse((string) $c->archived_at)->toIso8601String(),
            'archive_reason' => $c->archive_reason,
            'allowed_actions' => array_values(array_filter($this->machine->allowedActions($status), fn ($a) => $a !== 'publish' || $hasDraft)),
            'version' => (int) $c->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftOut(\stdClass $d): array
    {
        return ['formula' => $d->formula, 'condition' => $d->condition, 'rounding' => $d->rounding,
            'applies_to_regimes' => ConceptCatalog::pgArray($d->applies_to_regimes), 'applies_to_period_kinds' => ConceptCatalog::pgArray($d->applies_to_period_kinds),
            'affectations' => self::draftAffectations($d), 'valid_from' => substr((string) $d->valid_from, 0, 10), 'notes' => $d->notes,
            'validation' => $d->validation === null ? null : json_decode((string) $d->validation, true), 'edited_by_user_id' => $d->edited_by_user_id];
    }

    /**
     * @return list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}>
     */
    private static function draftAffectations(\stdClass $draft): array
    {
        /** @var list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}> $a */
        $a = json_decode((string) ($draft->affectations ?? '[]'), true) ?: [];

        return $a;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}>
     */
    private function normalizeAffectations(array $items): array
    {
        $bases = $this->template->bases($this->country());
        $out = [];
        foreach ($items as $i => $a) {
            $a = (array) $a;
            $code = (string) ($a['base_code'] ?? '');
            if (! isset($bases[$code])) {
                throw self::invalid("affectations.{$i}.base_code", 'in', (string) trans('payroll.formula.unknown_base', ['name' => $code]));
            }
            $kind = $a['projection_kind'] ?? null;
            if ($kind !== null && ! in_array($kind, self::PROJECTION_KINDS, true)) {
                throw self::invalid("affectations.{$i}.projection_kind", 'in', (string) trans('validation.in', ['attribute' => 'projection_kind']));
            }
            $out[$code] = ['base_code' => $code, 'affects' => (bool) ($a['affects'] ?? true), 'projection_kind' => $kind,
                'justification' => isset($a['justification']) && $a['justification'] !== '' ? (string) $a['justification'] : null];
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Forma legal de las afectaciones (sin el sustento): lo que el paquete de país fija (RN-CPT-03).
     *
     * @param  list<array{base_code: string, affects: bool, projection_kind: string|null, justification: string|null}>  $a
     */
    private static function legalShape(array $a): string
    {
        $s = [];
        foreach ($a as $x) {
            if ($x['affects']) {
                $s[] = $x['base_code'].':'.($x['projection_kind'] ?? '');
            }
        }
        sort($s);

        return implode(',', $s);
    }

    /**
     * @param  list<string>|null  $regimes
     */
    private function assertRegimes(mixed $regimes): void
    {
        if (! is_array($regimes) || $regimes === []) {
            return;
        }
        $known = DB::table('labor_regimes')->where('country_code', $this->country())->pluck('code')->flip();
        foreach ($regimes as $i => $r) {
            if (! isset($known[$r])) {
                throw self::invalid("applies_to_regimes.{$i}", 'in', (string) trans('payroll.regime_unknown'));
            }
        }
    }

    private function syncStatutory(string $id, mixed $code, string $from, bool $verified): void
    {
        $code = $code === null || $code === '' ? null : (string) $code;
        $current = DB::table('concept_statutory_mappings')->where('payroll_concept_id', $id)->where('filing_kind', 'plame')->whereNull('valid_to')->first();
        if ($current !== null && $current->statutory_code === $code) {
            return;
        }
        if ($current !== null) {
            if (substr((string) $current->valid_from, 0, 10) >= $from) {
                // Corrección del mismo día en que empezó: no hay tramo anterior que conservar.
                DB::table('concept_statutory_mappings')->where('id', $current->id)->update(['statutory_code' => $code ?? $current->statutory_code, 'verified' => $verified]);

                return;
            }
            DB::table('concept_statutory_mappings')->where('id', $current->id)->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
        }
        if ($code !== null) {
            DB::table('concept_statutory_mappings')->insert(['tenant_id' => TenancyScope::requireTenant(), 'payroll_concept_id' => $id,
                'filing_kind' => 'plame', 'statutory_code' => $code, 'verified' => $verified, 'valid_from' => $from]);
        }
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
    }

    private static function text(mixed $v): ?string
    {
        return $v === null || trim((string) $v) === '' ? null : (string) $v;
    }

    /**
     * @param  list<string>|null  $values
     */
    private static function pg(mixed $values): ?string
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        return '{'.implode(',', array_map(fn ($v) => '"'.str_replace('"', '', (string) $v).'"', $values)).'}';
    }

    private static function invalid(string $field, string $rule, string $message): AyniException
    {
        return new AyniException('validation_failed', 422, $message, [['field' => $field, 'rule' => $rule, 'message' => $message]]);
    }
}
