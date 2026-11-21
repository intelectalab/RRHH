<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Domain\StatutoryFilingStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\WorkingDays;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-2 · DEC-3 · DEC-4 · ME-24 · Declaraciones de la empresa: PLAME, T-Registro y planilla de AFPnet.
 *
 * La validación previa arma los archivos con las estructuras del paquete de país y lista los errores por trabajador;
 * cada archivo generado se conserva con su huella, su tamaño, sus totales de control, la versión del generador y su
 * autor (RN-DEC-10, RN-DEC-12). La empresa presenta en la herramienta oficial (ADR 0018) y aquí se registra la
 * presentación con su constancia, la respuesta (aceptada u observada) y, si hace falta, la rectificatoria.
 * Mientras el periodo no esté cerrado o la estructura no esté verificada, el archivo solo sale como borrador y no
 * se puede marcar presentado (RN-DEC-01, RN-DEC-11).
 */
final class StatutoryFilings
{
    public const KINDS = ['plame', 't_registro', 'afpnet'];

    public function __construct(
        private readonly DeclarationGenerators $generators,
        private readonly StatutoryFilingStateMachine $machine,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly LegalParameters $legal,
        private readonly WorkingDays $workingDays,
        private readonly ParameterResolver $parameters,
    ) {}

    /**
     * @param  array{legal_entity_id: string, kind: string, period_code?: string|null, payroll_period_id?: string|null}  $in
     * @return array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}
     */
    public function normalize(array $in): array
    {
        $periodId = $in['payroll_period_id'] ?? null;
        $code = $in['period_code'] ?? null;
        if ($periodId !== null) {
            $p = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId)
                ->first(['p.period_year', 'p.period_number', 'p.statutory_period_code', 'g.legal_entity_id', 'p.kind']);
            if ($p === null || (string) $p->legal_entity_id !== $in['legal_entity_id']) {
                throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.period_mismatch'),
                    [['field' => 'payroll_period_id', 'rule' => 'exists', 'message' => (string) trans('payroll.declarations.period_mismatch')]]);
            }
            $code = $p->statutory_period_code !== null ? str_replace('-', '', (string) $p->statutory_period_code) : sprintf('%04d%02d', (int) $p->period_year, (int) $p->period_number);
        }
        if (in_array($in['kind'], ['plame', 'afpnet'], true) && $periodId === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.period_required'),
                [['field' => 'payroll_period_id', 'rule' => 'required', 'message' => (string) trans('payroll.declarations.period_required')]]);
        }
        $code ??= now()->format('Ym');
        if (preg_match('/^\d{6}$/', (string) $code) !== 1) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.period_code'),
                [['field' => 'period_code', 'rule' => 'regex', 'message' => (string) trans('payroll.declarations.period_code')]]);
        }

        return ['legal_entity_id' => $in['legal_entity_id'], 'kind' => $in['kind'], 'period_code' => (string) $code, 'payroll_period_id' => $periodId];
    }

    /**
     * Crea la declaración en borrador (una por empresa, tipo y periodo, salvo rectificatorias).
     *
     * @param  array{legal_entity_id: string, kind: string, period_code?: string|null, payroll_period_id?: string|null}  $in
     * @return array<string, mixed>
     */
    public function create(array $in, ?string $userId): array
    {
        $f = $this->normalize($in);
        $exists = DB::table('statutory_filings')->where('legal_entity_id', $f['legal_entity_id'])->where('kind', $f['kind'])
            ->where('period_code', $f['period_code'])->whereNotIn('status', ['rectified'])->exists();
        if ($exists && $f['kind'] !== 't_registro') {
            throw new AyniException('filing_exists', 409, (string) trans('payroll.declarations.exists'));
        }

        return DB::transaction(function () use ($f, $userId): array {
            $id = (string) DB::table('statutory_filings')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $f['legal_entity_id'], 'kind' => $f['kind'],
                'period_code' => $f['period_code'], 'payroll_period_id' => $f['payroll_period_id'], 'status' => 'draft',
                'due_on' => $this->dueOn($f), 'created_by_user_id' => $userId,
            ]);
            $this->audit->record('statutory_filing.created', 'statutory_filing', $id, null, ['kind' => $f['kind'], 'period_code' => $f['period_code']]);

            return $this->show($id);
        });
    }

    /**
     * Vencimiento: AFPnet en el n-ésimo día hábil del mes siguiente; T-Registro el plazo más próximo de sus eventos.
     *
     * @param  array{legal_entity_id: string, kind: string, period_code: string, payroll_period_id: string|null}  $f
     */
    private function dueOn(array $f): ?string
    {
        if ($f['kind'] === 'afpnet') {
            $start = CarbonImmutable::createFromFormat('Ym', $f['period_code'])?->startOfMonth()->addMonth();
            $n = $this->legal->at('PE', 'afpnet_plazo_dias_habiles', $start);
            if ($start === null || $n === null || ! is_numeric($n['value'])) {
                return null;
            }

            return $this->nthWorkingDay($f['legal_entity_id'], $start, (int) $n['value']);
        }
        if ($f['kind'] === 't_registro') {
            $d = DB::table('registry_events')->where('legal_entity_id', $f['legal_entity_id'])->whereIn('status', ['pending', 'overdue'])->min('due_on');

            return $d === null ? null : substr((string) $d, 0, 10);
        }

        // PLAME: el cronograma mensual de SUNAT por último dígito del RUC aún no está cargado como parámetro.
        return null;
    }

    public function nthWorkingDay(string $entityId, CarbonImmutable $start, int $n): string
    {
        $end = $start->addMonth();
        $holidays = $this->workingDays->holidays(null, $start->toDateString(), $end->toDateString());
        $rest = $this->workingDays->restDays($entityId);
        $count = 0;
        for ($d = $start; $d->lessThan($end); $d = $d->addDay()) {
            if (! in_array(WorkingDays::weekday($d), $rest, true) && ! in_array($d->toDateString(), $holidays, true) && ++$count === $n) {
                return $d->toDateString();
            }
        }

        return $end->subDay()->toDateString();
    }

    /**
     * Validación previa sin guardar: lista de errores por trabajador, avisos y totales.
     *
     * @param  array{legal_entity_id: string, kind: string, period_code?: string|null, payroll_period_id?: string|null}  $in
     * @return array<string, mixed>
     */
    public function preview(array $in): array
    {
        $built = $this->generators->build($this->normalize($in));

        return $this->report($built) + ['files' => array_map(fn (array $file): array => array_diff_key($file, ['content' => true]), $built['files'])];
    }

    /**
     * @param  array{errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, totals: array<string, mixed>, draft_only: bool, draft_reasons: list<string>}  $built
     * @return array<string, mixed>
     */
    private function report(array $built): array
    {
        return [
            'valid' => $built['errors'] === [], 'errors' => $built['errors'], 'warnings' => $built['warnings'], 'totals' => $built['totals'],
            'draft_only' => $built['draft_only'], 'draft_reasons' => $built['draft_reasons'], 'generator_version' => DeclarationGenerators::GENERATOR_VERSION,
        ];
    }

    /**
     * Valida y genera: guarda cada archivo (sustituye al anterior del mismo tipo) y deja validada o con errores.
     *
     * @return array<string, mixed>
     */
    public function validate(string $id, ?string $userId): array
    {
        $f = $this->filing($id);
        $built = $this->generators->build(['legal_entity_id' => (string) $f->legal_entity_id, 'kind' => (string) $f->kind,
            'period_code' => (string) $f->period_code, 'payroll_period_id' => $f->payroll_period_id === null ? null : (string) $f->payroll_period_id]);
        $next = $this->machine->getNextState((string) $f->status, $built['errors'] === [] ? 'validate' : 'validate_with_errors');
        $report = $this->report($built);

        return DB::transaction(function () use ($f, $id, $built, $next, $report, $userId): array {
            $tenant = TenancyScope::requireTenant();
            foreach ($built['files'] as $file) {
                $previous = DB::table('statutory_filing_files')->where('statutory_filing_id', $id)->where('file_kind', $file['file_kind'])
                    ->orderByDesc('created_at')->value('id');
                $stored = $this->files->upload($file['content'], $file['file_name'], 'text/plain', null, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
                DB::table('statutory_filing_files')->insert([
                    'tenant_id' => $tenant, 'statutory_filing_id' => $id, 'file_kind' => $file['file_kind'], 'stored_file_id' => $stored->id,
                    'record_count' => $file['record_count'], 'checksum' => $file['sha256'], 'statutory_file_layout_id' => $file['layout_id'],
                    'file_name' => $file['file_name'], 'sha256' => $file['sha256'], 'byte_size' => $file['byte_size'],
                    'control_totals' => json_encode($built['totals']), 'generator_version' => DeclarationGenerators::GENERATOR_VERSION,
                    'generated_by_user_id' => $userId, 'supersedes_file_id' => $previous,
                ]);
            }
            DB::table('statutory_filings')->where('id', $id)->update(['status' => $next, 'validation_report' => json_encode($report), 'updated_at' => now()]);
            if ((string) $f->kind === 't_registro' && isset($built['events']) && $built['events'] !== []) {
                DB::table('registry_events')->whereIn('id', $built['events'])->update(['statutory_filing_id' => $id, 'status' => 'included', 'updated_at' => now()]);
            }
            $this->transitioned($f, $next, ['status' => (string) $f->status], ['status' => $next, 'files' => count($built['files']), 'errors' => count($built['errors'])]);

            return $this->show($id);
        });
    }

    /**
     * Registra la presentación hecha por la empresa (número de orden y constancia opcional).
     *
     * @return array<string, mixed>
     */
    public function markSubmitted(string $id, string $reference, ?string $submittedAt, ?string $documentId, ?string $userId): array
    {
        $f = $this->filing($id);
        $next = $this->machine->getNextState((string) $f->status, 'mark_submitted');
        $report = $f->validation_report === null ? [] : (array) json_decode((string) $f->validation_report, true);
        if (($report['draft_only'] ?? false) === true) {
            throw new AyniException('draft_only', 409, (string) trans('payroll.declarations.draft_only'));
        }

        return DB::transaction(function () use ($f, $id, $next, $reference, $submittedAt, $documentId, $userId): array {
            DB::table('statutory_filings')->where('id', $id)->update([
                'status' => $next, 'external_reference' => $reference, 'submitted_at' => $submittedAt ?? now(), 'submitted_by_user_id' => $userId,
                'submission_document_id' => $documentId, 'updated_at' => now(),
            ]);
            if ((string) $f->kind === 't_registro') {
                DB::table('registry_events')->where('statutory_filing_id', $id)->update(['status' => 'submitted', 'updated_at' => now()]);
            }
            $this->transitioned($f, $next, ['status' => (string) $f->status], ['status' => $next, 'external_reference' => $reference]);

            return $this->show($id);
        });
    }

    /**
     * Respuesta aceptada. En AFPnet concilia el cálculo propio contra la liquidación devuelta; en T-Registro guarda lo
     * declarado por tipo de registro (peruvian_registry_periods) y confirma los eventos.
     *
     * @param  array<string, string>|null  $liquidation
     * @return array<string, mixed>
     */
    public function accept(string $id, ?array $liquidation, ?string $documentId, ?string $userId): array
    {
        $f = $this->filing($id);
        $next = $this->machine->getNextState((string) $f->status, 'accept');
        $report = $f->validation_report === null ? [] : (array) json_decode((string) $f->validation_report, true);
        if ((string) $f->kind === 'afpnet' && $liquidation !== null) {
            $report['reconciliation'] = $this->reconcile($f, $liquidation);
        }

        return DB::transaction(function () use ($f, $id, $next, $report, $documentId): array {
            DB::table('statutory_filings')->where('id', $id)->update(array_filter([
                'status' => $next, 'accepted_at' => now(), 'validation_report' => json_encode($report), 'updated_at' => now(),
                'submission_document_id' => $documentId,
            ], fn ($v) => $v !== null));
            if ((string) $f->kind === 't_registro') {
                $this->declareRegistry($id);
            }
            $this->transitioned($f, $next, ['status' => (string) $f->status], ['status' => $next, 'reconciliation' => $report['reconciliation'] ?? null]);

            return $this->show($id);
        });
    }

    /**
     * @param  array<string, string>  $liquidation
     * @return array<string, mixed>
     */
    private function reconcile(\stdClass $f, array $liquidation): array
    {
        $runId = $this->generators->closedRun($f->payroll_period_id === null ? null : (string) $f->payroll_period_id);
        $own = $runId === null ? [] : $this->generators->ownAfp($runId);
        $tolerance = $this->parameters->value('declaraciones.afpnet_tolerancia', (string) $f->legal_entity_id);
        $tolerance = is_numeric($tolerance) ? (string) $tolerance : (string) (app(DeclarationSources::class)->pack()['tolerancia_conciliacion'] ?? '0');
        $lines = [];
        $ok = true;
        foreach ($own as $key => $amount) {
            $theirs = isset($liquidation[$key]) && is_numeric($liquidation[$key]) ? BigDecimal::of((string) $liquidation[$key]) : null;
            $diff = $theirs === null ? null : $theirs->minus($amount);
            $match = $diff !== null && $diff->abs()->isLessThanOrEqualTo($tolerance);
            $ok = $ok && $match;
            $lines[] = ['item' => $key, 'own' => $amount, 'afpnet' => $theirs === null ? null : (string) $theirs->toScale(2),
                'difference' => $diff === null ? null : (string) $diff->toScale(2), 'matches' => $match];
        }

        return ['matches' => $ok, 'tolerance' => $tolerance, 'lines' => $lines];
    }

    private function declareRegistry(string $filingId): void
    {
        $tenant = TenancyScope::requireTenant();
        $events = DB::table('registry_events AS r')->join('employments AS e', 'e.id', '=', 'r.employment_id')->where('r.statutory_filing_id', $filingId)
            ->get(['r.id', 'r.kind', 'r.employment_id', 'e.hire_date', 'e.termination_date']);
        foreach ($events as $ev) {
            $current = DB::table('peruvian_registry_periods')->where('employment_id', $ev->employment_id)->where('record_type', 'period')
                ->whereNull('ends_on')->first(['id']);
            if ((string) $ev->kind === 'worker_registration' && $current === null) {
                DB::table('peruvian_registry_periods')->insert([
                    'tenant_id' => $tenant, 'employment_id' => $ev->employment_id, 'category' => 'worker', 'record_type' => 'period',
                    'starts_on' => $ev->hire_date, 'registry_event_id' => $ev->id, 'declared_at' => now(),
                ]);
            } elseif ((string) $ev->kind === 'worker_termination' && $current !== null && $ev->termination_date !== null) {
                // Cerrar el periodo declarado con la fecha de cese (la fila no se borra ni se sobrescribe su inicio).
                DB::table('peruvian_registry_periods')->where('id', $current->id)->update(['ends_on' => $ev->termination_date, 'declared_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('registry_events')->where('statutory_filing_id', $filingId)->update(['status' => 'confirmed', 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function observe(string $id, string $observation, ?string $userId): array
    {
        $f = $this->filing($id);
        $next = $this->machine->getNextState((string) $f->status, 'observe');

        return DB::transaction(function () use ($f, $id, $next, $observation): array {
            DB::table('statutory_filings')->where('id', $id)->update(['status' => $next, 'observation' => $observation, 'updated_at' => now()]);
            $this->transitioned($f, $next, ['status' => (string) $f->status], ['status' => $next, 'observation' => $observation]);

            return $this->show($id);
        });
    }

    /**
     * Rectificatoria: la presentada queda rectificada y nace otra declaración en borrador que la referencia.
     *
     * @return array<string, mixed>
     */
    public function rectify(string $id, string $reason, ?string $userId): array
    {
        $f = $this->filing($id);
        $next = $this->machine->getNextState((string) $f->status, 'rectify');

        return DB::transaction(function () use ($f, $id, $next, $reason, $userId): array {
            DB::table('statutory_filings')->where('id', $id)->update(['status' => $next, 'rectification_reason' => $reason, 'updated_at' => now()]);
            $newId = (string) DB::table('statutory_filings')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $f->legal_entity_id, 'kind' => $f->kind, 'period_code' => $f->period_code,
                'payroll_period_id' => $f->payroll_period_id, 'is_rectification' => true, 'rectifies_filing_id' => $id, 'status' => 'draft',
                'due_on' => $f->due_on, 'created_by_user_id' => $userId,
            ]);
            if ((string) $f->kind === 't_registro') {
                DB::table('registry_events')->where('statutory_filing_id', $id)->whereIn('status', ['included', 'submitted'])
                    ->update(['status' => 'pending', 'statutory_filing_id' => null, 'updated_at' => now()]);
            }
            $this->transitioned($f, $next, ['status' => (string) $f->status], ['status' => $next, 'reason' => $reason, 'rectification_id' => $newId]);

            return $this->show($newId);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function transitioned(\stdClass $f, string $next, array $before, array $after): void
    {
        $this->audit->record('statutory_filing.'.$next, 'statutory_filing', (string) $f->id, $before, $after);
        $this->outbox->record(DomainEventTypes::FILING_STATUS_CHANGED, 'statutory_filing', (string) $f->id,
            ['filing_id' => (string) $f->id, 'kind' => (string) $f->kind, 'period_code' => (string) $f->period_code, 'status' => $next]);
    }

    private function filing(string $id): \stdClass
    {
        $f = DB::table('statutory_filings')->where('id', $id)->whereIn('kind', self::KINDS)->first();
        if ($f === null) {
            throw new AyniException('not_found', 404);
        }

        return $f;
    }

    public function entityOf(string $id): ?string
    {
        $e = DB::table('statutory_filings')->where('id', $id)->whereIn('kind', self::KINDS)->value('legal_entity_id');

        return $e === null ? null : (string) $e;
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $f = $this->filing($id);
        $report = $f->validation_report === null ? null : json_decode((string) $f->validation_report, true);
        $today = CarbonImmutable::today()->toDateString();

        return [
            'id' => (string) $f->id, 'legal_entity_id' => (string) $f->legal_entity_id, 'kind' => (string) $f->kind, 'period_code' => (string) $f->period_code,
            'payroll_period_id' => $f->payroll_period_id === null ? null : (string) $f->payroll_period_id,
            'is_rectification' => (bool) $f->is_rectification, 'rectifies_filing_id' => $f->rectifies_filing_id === null ? null : (string) $f->rectifies_filing_id,
            'status' => (string) $f->status, 'validation_report' => $report,
            'draft_only' => is_array($report) && ($report['draft_only'] ?? false) === true,
            'due_on' => $f->due_on === null ? null : substr((string) $f->due_on, 0, 10),
            'overdue' => $f->due_on !== null && substr((string) $f->due_on, 0, 10) < $today && in_array((string) $f->status, ['draft', 'validated', 'with_errors'], true),
            'external_reference' => $f->external_reference === null ? null : (string) $f->external_reference,
            'submitted_at' => $f->submitted_at === null ? null : CarbonImmutable::parse((string) $f->submitted_at)->toIso8601ZuluString(),
            'accepted_at' => $f->accepted_at === null ? null : CarbonImmutable::parse((string) $f->accepted_at)->toIso8601ZuluString(),
            'observation' => $f->observation === null ? null : (string) $f->observation,
            'rectification_reason' => $f->rectification_reason === null ? null : (string) $f->rectification_reason,
            'submission_document_id' => $f->submission_document_id === null ? null : (string) $f->submission_document_id,
            // DEC-7 · Bitácora: responsable, pago y constancia de pago.
            'responsible_user_id' => $f->responsible_user_id === null ? null : (string) $f->responsible_user_id,
            'paid_at' => $f->paid_at === null ? null : CarbonImmutable::parse((string) $f->paid_at)->toIso8601ZuluString(),
            'payment_reference' => $f->payment_reference === null ? null : (string) $f->payment_reference,
            'payment_document_id' => $f->payment_document_id === null ? null : (string) $f->payment_document_id,
            'files' => $this->filesOf($id),
            'created_at' => CarbonImmutable::parse((string) $f->created_at)->toIso8601ZuluString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function filesOf(string $id): array
    {
        return array_values(DB::table('statutory_filing_files')->where('statutory_filing_id', $id)->orderBy('file_kind')->orderByDesc('created_at')->get()
            ->map(fn ($r): array => [
                'id' => (string) $r->id, 'file_kind' => (string) $r->file_kind, 'file_name' => $r->file_name === null ? null : (string) $r->file_name,
                'record_count' => $r->record_count === null ? null : (int) $r->record_count, 'sha256' => $r->sha256 === null ? null : (string) $r->sha256,
                'byte_size' => $r->byte_size === null ? null : (int) $r->byte_size, 'generator_version' => $r->generator_version === null ? null : (string) $r->generator_version,
                'generated_by_user_id' => $r->generated_by_user_id === null ? null : (string) $r->generated_by_user_id,
                'superseded' => DB::table('statutory_filing_files')->where('supersedes_file_id', $r->id)->exists(),
                'created_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601ZuluString(),
            ])->all());
    }

    /**
     * @param  list<string>|null  $entities  empresas visibles (null = todas)
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(?array $entities, array $filters): array
    {
        $q = DB::table('statutory_filings')->whereIn('kind', self::KINDS)->orderByDesc('period_code')->orderByDesc('created_at');
        if ($entities !== null) {
            $q->whereIn('legal_entity_id', $entities);
        }
        foreach (['legal_entity_id', 'kind', 'status', 'period_code'] as $k) {
            if (($filters[$k] ?? null) !== null) {
                $q->where($k, $filters[$k]);
            }
        }

        return array_values($q->limit(200)->pluck('id')->map(fn ($id): array => $this->show((string) $id))->all());
    }

    /**
     * Descarga de un archivo generado (queda en auditoría con su huella, RN-DEC-10).
     *
     * @return array{name: string, mime: string, contents: string}
     */
    public function download(string $filingId, string $fileId, ?string $userId): array
    {
        $file = DB::table('statutory_filing_files')->where('id', $fileId)->where('statutory_filing_id', $filingId)->first(['stored_file_id', 'sha256', 'file_name']);
        if ($file === null) {
            throw new AyniException('not_found', 404);
        }
        $this->audit->record('statutory_filing_file.downloaded', 'statutory_filing', $filingId, null, ['file_id' => $fileId, 'sha256' => $file->sha256]);
        $out = $this->files->contentsForDownload((string) $file->stored_file_id, null, $userId);
        $out['name'] = (string) ($file->file_name ?? $out['name']);

        return $out;
    }
}
