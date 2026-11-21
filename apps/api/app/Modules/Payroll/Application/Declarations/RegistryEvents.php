<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-3 · Pendientes de T-Registro. Cada ingreso (COL-6) sin alta declarada y cada cese sin baja quedan como evento
 * pendiente con su plazo, tomado de los parámetros treg_* a la fecha del hecho (RN-DEC-04). Un plazo cuyo valor está
 * por validar deja el evento sin vencimiento y lo marca, para que nadie lo dé por cumplido. Lo declarado se guarda
 * por tipo de registro (peruvian_registry_periods) y se concilia con la historia con vigencia del núcleo.
 */
final class RegistryEvents
{
    public function __construct(
        private readonly LegalParameters $legal,
        private readonly OutboxService $outbox,
        private readonly StatutoryFilings $filings,
    ) {}

    /**
     * Crea los eventos que faltan para la empresa. Idempotente: un hecho ya registrado no se duplica.
     *
     * @return array{created: int}
     */
    public function sync(string $legalEntityId): array
    {
        $employments = DB::table('employments')->where('legal_entity_id', $legalEntityId)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'terminated', 'terminating', 'suspended'])->get(['id', 'hire_date', 'termination_date']);
        $created = 0;
        DB::transaction(function () use ($employments, $legalEntityId, &$created): void {
            foreach ($employments as $e) {
                $declared = DB::table('peruvian_registry_periods')->where('employment_id', $e->id)->where('record_type', 'period')->whereNotNull('declared_at')->exists();
                if (! $declared && ! $this->has((string) $e->id, 'worker_registration')) {
                    $created += $this->add($legalEntityId, (string) $e->id, 'worker_registration', substr((string) $e->hire_date, 0, 10), 'treg_alta_trabajador_dias');
                }
                if ($e->termination_date !== null && ! $this->has((string) $e->id, 'worker_termination')) {
                    $created += $this->add($legalEntityId, (string) $e->id, 'worker_termination', substr((string) $e->termination_date, 0, 10), 'treg_baja_dias');
                }
            }
        });

        return ['created' => $created];
    }

    /**
     * DEC-8 · Novedad de modificación por un cambio en un dato declarable de un trabajador ya dado de alta, con el
     * plazo del parámetro treg_modificacion_dias_habiles. Una modificación pendiente de la misma fecha no se duplica.
     */
    public function addUpdate(string $legalEntityId, string $employmentId, string $date): int
    {
        if (DB::table('registry_events')->where('employment_id', $employmentId)->where('kind', 'worker_update')->where('event_date', $date)->where('status', 'pending')->exists()) {
            return 0;
        }

        return $this->add($legalEntityId, $employmentId, 'worker_update', $date, 'treg_modificacion_dias_habiles');
    }

    private function has(string $employmentId, string $kind): bool
    {
        return DB::table('registry_events')->where('employment_id', $employmentId)->where('kind', $kind)->exists();
    }

    private function add(string $entity, string $employment, string $kind, string $date, string $parameter): int
    {
        $due = $this->due($parameter, $entity, $date);
        $id = (string) DB::table('registry_events')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $entity, 'employment_id' => $employment, 'kind' => $kind,
            'event_date' => $date, 'due_on' => $due, 'status' => 'pending',
        ]);
        $this->outbox->record(DomainEventTypes::TREGISTRO_PENDING, 'registry_event', $id, ['registry_event_id' => $id, 'kind' => $kind, 'due_on' => $due]);

        return 1;
    }

    /** Plazo del parámetro a la fecha del hecho: días calendario o hábiles según su unidad; null si está por validar. */
    private function due(string $parameter, string $entity, string $date): ?string
    {
        $p = $this->legal->at('PE', $parameter, $date);
        if ($p === null || ! is_numeric($p['value'])) {
            return null;
        }
        $n = (int) $p['value'];
        $day = CarbonImmutable::parse($date);
        if ($p['unit'] === 'business_days') {
            return $n === 0 ? $day->toDateString() : $this->filings->nthWorkingDay($entity, $day->addDay(), $n);
        }

        return $day->addDays($n)->toDateString();
    }

    /**
     * @param  list<string>|null  $entities
     * @return array{data: list<array<string, mixed>>, deadlines: array<string, mixed>}
     */
    public function list(?array $entities, ?string $legalEntityId, ?string $status): array
    {
        $q = DB::table('registry_events AS r')->leftJoin('employments AS e', 'e.id', '=', 'r.employment_id')->leftJoin('people AS p', 'p.id', '=', 'e.person_id')
            ->orderBy('r.due_on')->orderBy('r.event_date')->limit(500);
        if ($entities !== null) {
            $q->whereIn('r.legal_entity_id', $entities);
        }
        if ($legalEntityId !== null) {
            $q->where('r.legal_entity_id', $legalEntityId);
        }
        $today = CarbonImmutable::today()->toDateString();
        $rows = $q->get(['r.id', 'r.legal_entity_id', 'r.employment_id', 'r.kind', 'r.event_date', 'r.due_on', 'r.status', 'r.statutory_filing_id',
            'e.employee_code', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);
        $out = [];
        foreach ($rows as $r) {
            $due = $r->due_on === null ? null : substr((string) $r->due_on, 0, 10);
            $state = (string) $r->status === 'pending' && $due !== null && $due < $today ? 'overdue' : (string) $r->status;
            if ($status !== null && $state !== $status) {
                continue;
            }
            $out[] = [
                'id' => (string) $r->id, 'legal_entity_id' => (string) $r->legal_entity_id, 'employment_id' => $r->employment_id === null ? null : (string) $r->employment_id,
                'employee_code' => $r->employee_code === null ? null : (string) $r->employee_code,
                'name' => $r->first_names === null ? null : trim($r->paternal_surname.' '.($r->maternal_surname ?? '').' '.$r->first_names),
                'kind' => (string) $r->kind, 'event_date' => substr((string) $r->event_date, 0, 10), 'due_on' => $due, 'due_pending_validation' => $due === null,
                'status' => $state, 'statutory_filing_id' => $r->statutory_filing_id === null ? null : (string) $r->statutory_filing_id,
            ];
        }
        $deadlines = [];
        foreach (['treg_alta_trabajador_dias', 'treg_modificacion_dias_habiles', 'treg_baja_dias'] as $code) {
            $p = $this->legal->at('PE', $code, $today);
            $deadlines[$code] = $p === null ? null : ['value' => $p['value'], 'unit' => $p['unit'], 'pending_validation' => $p['pending_validation']];
        }

        return ['data' => $out, 'deadlines' => $deadlines];
    }

    /**
     * Conciliación de lo declarado (peruvian_registry_periods) con la historia de la relación laboral.
     *
     * @return list<array<string, mixed>>
     */
    public function reconciliation(string $legalEntityId): array
    {
        $employments = DB::table('employments')->where('legal_entity_id', $legalEntityId)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'terminated', 'terminating', 'suspended'])->orderBy('employee_code')->get(['id', 'employee_code', 'hire_date', 'termination_date']);
        $out = [];
        foreach ($employments as $e) {
            $declared = DB::table('peruvian_registry_periods')->where('employment_id', $e->id)->where('record_type', 'period')
                ->orderByDesc('starts_on')->first(['starts_on', 'ends_on', 'declared_at']);
            $hire = substr((string) $e->hire_date, 0, 10);
            $end = $e->termination_date === null ? null : substr((string) $e->termination_date, 0, 10);
            $issues = [];
            if ($declared === null || $declared->declared_at === null) {
                $issues[] = 'not_declared';
            } else {
                if (substr((string) $declared->starts_on, 0, 10) !== $hire) {
                    $issues[] = 'start_mismatch';
                }
                $declaredEnd = $declared->ends_on === null ? null : substr((string) $declared->ends_on, 0, 10);
                if ($end !== null && $declaredEnd === null) {
                    $issues[] = 'termination_not_declared';
                } elseif ($end !== $declaredEnd) {
                    $issues[] = 'end_mismatch';
                }
            }
            $out[] = [
                'employment_id' => (string) $e->id, 'employee_code' => $e->employee_code === null ? null : (string) $e->employee_code,
                'hire_date' => $hire, 'termination_date' => $end,
                'declared_start' => $declared === null ? null : substr((string) $declared->starts_on, 0, 10),
                'declared_end' => $declared?->ends_on === null ? null : substr((string) $declared->ends_on, 0, 10),
                'matches' => $issues === [], 'issues' => $issues,
            ];
        }

        return $out;
    }
}
