<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\VacationScheduleLineStateMachine;
use App\Modules\Vacations\Domain\VacationScheduleStateMachine;
use App\Modules\Vacations\Infrastructure\Models\VacationSchedule;
use App\Modules\Vacations\Infrastructure\Models\VacationScheduleLine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Labor\MasterData;
use App\Shared\Notifications\Notifier;
use App\Shared\Settings\ParameterResolver;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * VAC-5 · Programación anual y vacaciones acumuladas.
 *
 * Rol anual: RRHH abre la campaña de una empresa y un año; RRHH o el jefe proponen fechas por trabajador; el
 * trabajador confirma o declina; lo confirmado se convierte en solicitudes aprobadas (registradas por RRHH, con
 * las mismas reglas de saldo del libro). La campaña cerrada no recibe propuestas.
 *
 * Acumuladas (RN-VAC-09, RN-VAC-10): por trabajador, cuántos periodos ganados conservan saldo, desde cuándo, los
 * vencidos y los que vencen pronto, con una explicación legible. La tarea diaria avisa una sola vez por periodo
 * al jefe y a quienes ajustan saldos.
 */
final class VacationScheduling
{
    public const NOTICE = 'vacations.accumulation';

    public function __construct(
        private readonly VacationScheduleStateMachine $campaigns,
        private readonly VacationScheduleLineStateMachine $lines,
        private readonly VacationRequestService $requests,
        private readonly AuditLogger $audit,
        private readonly ParameterResolver $parameters,
        private readonly Notifier $notifier,
        private readonly MasterData $masterData,
    ) {}

    public function open(string $legalEntityId, int $year, ?string $name, ?string $userId): VacationSchedule
    {
        if (VacationSchedule::query()->where('legal_entity_id', $legalEntityId)->where('year', $year)->exists()) {
            throw new AyniException('conflict', 409, (string) trans('vacations.schedules.exists', ['year' => $year]));
        }
        $s = VacationSchedule::query()->create([
            'legal_entity_id' => $legalEntityId, 'year' => $year, 'opened_by_user_id' => $userId,
            'name' => $name !== null && trim($name) !== '' ? $name : (string) trans('vacations.schedules.default_name', ['year' => $year]),
        ]);
        $this->audit->record('vacation_schedule.opened', 'vacation_schedule', $s->id, null, ['legal_entity_id' => $legalEntityId, 'year' => $year]);

        return $s;
    }

    public function transition(VacationSchedule $s, string $action): VacationSchedule
    {
        $next = $this->campaigns->getNextState($s->status, $action);
        $s->fill(['status' => $next, 'closed_at' => $next === 'closed' ? now() : null])->save();
        $this->audit->record('vacation_schedule.'.$action, 'vacation_schedule', $s->id, null, ['status' => $next]);

        return $s;
    }

    /**
     * Propone fechas a un trabajador de la empresa de la campaña. Devuelve la línea y los avisos del cálculo
     * (días según la política, saldo que aún no alcanza, mínimo de fraccionamiento…).
     *
     * @return array{line: VacationScheduleLine, warnings: list<array{code: string, message: string}>}
     */
    public function propose(VacationSchedule $s, string $employmentId, string $startsOn, string $endsOn, ?string $userId): array
    {
        if ($s->status !== 'open') {
            throw new AyniException('schedule_closed', 409, (string) trans('vacations.schedules.closed'));
        }
        if ((string) DB::table('employments')->where('id', $employmentId)->value('legal_entity_id') !== $s->legal_entity_id) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('vacations.schedules.other_company')]]);
        }
        if ((int) substr($startsOn, 0, 4) !== $s->year) {
            throw ValidationException::withMessages(['starts_on' => [(string) trans('vacations.schedules.other_year', ['year' => $s->year])]]);
        }
        $overlap = VacationScheduleLine::query()->where('vacation_schedule_id', $s->id)->where('employment_id', $employmentId)
            ->whereIn('status', ['proposed', 'confirmed', 'converted'])->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => [(string) trans('vacations.schedules.overlap')]]);
        }
        $eval = $this->requests->evaluate($employmentId, $startsOn, $endsOn, 'hr', (string) trans('vacations.schedules.exception', ['year' => $s->year]));
        $warnings = $eval['warnings'];
        foreach ($eval['issues'] as $i) {
            // El saldo del año del rol aún no se ganó: se avisa y se valida al convertir.
            $warnings[] = ['code' => $i['code'], 'message' => $i['message']];
        }
        $line = VacationScheduleLine::query()->create([
            'vacation_schedule_id' => $s->id, 'employment_id' => $employmentId, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
            'days' => $eval['days'], 'proposed_by_user_id' => $userId,
        ]);
        $this->audit->record('vacation_schedule_line.proposed', 'vacation_schedule_line', $line->id, null, ['employment_id' => $employmentId, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);

        return ['line' => $line, 'warnings' => $warnings];
    }

    /** El trabajador (o RRHH por él) confirma, declina o se retira la propuesta. */
    public function respond(VacationScheduleLine $line, string $action, ?string $note): VacationScheduleLine
    {
        $next = $this->lines->getNextState($line->status, $action);
        if ($action === 'decline' && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages(['note' => [(string) trans('vacations.schedules.note_required')]]);
        }
        $before = ['status' => $line->status];
        $line->fill(['status' => $next, 'responded_at' => now(), 'response_note' => $note])->save();
        $this->audit->record('vacation_schedule_line.'.$action, 'vacation_schedule_line', $line->id, $before, ['status' => $next], $note);

        return $line;
    }

    /**
     * Convierte las líneas confirmadas en solicitudes aprobadas. Cada línea se procesa sola: si el saldo aún no
     * alcanza, queda confirmada con el motivo y las demás siguen.
     *
     * @return array{converted: int, failed: list<array{line_id: string, message: string}>}
     */
    public function convert(VacationSchedule $s, string $userId): array
    {
        $converted = 0;
        $failed = [];
        foreach (VacationScheduleLine::query()->where('vacation_schedule_id', $s->id)->where('status', 'confirmed')->orderBy('starts_on')->get() as $line) {
            try {
                DB::transaction(function () use ($line, $s, $userId, &$converted): void {
                    $request = $this->requests->create(['employment_id' => $line->employment_id, 'starts_on' => $line->day('starts_on'), 'ends_on' => $line->day('ends_on'),
                        'notes' => $s->name, 'exception_reason' => (string) trans('vacations.schedules.exception', ['year' => $s->year])], $userId);
                    $this->requests->register($request, $userId);
                    $line->fill(['status' => $this->lines->getNextState($line->status, 'convert'), 'vacation_request_id' => $request->id])->save();
                    $this->audit->record('vacation_schedule_line.converted', 'vacation_schedule_line', $line->id, null, ['vacation_request_id' => $request->id]);
                    $converted++;
                });
            } catch (Throwable $e) {
                $failed[] = ['line_id' => $line->id, 'message' => $e->getMessage()];
            }
        }

        return ['converted' => $converted, 'failed' => $failed];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSchedule(VacationSchedule $s): array
    {
        $counts = VacationScheduleLine::query()->where('vacation_schedule_id', $s->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $s->id, 'legal_entity_id' => $s->legal_entity_id, 'year' => $s->year, 'name' => $s->name, 'status' => $s->status,
            'closed_at' => $s->closed_at?->toIso8601String(),
            'counts' => collect(['proposed', 'confirmed', 'declined', 'converted', 'withdrawn'])->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all(),
            'allowed_actions' => $this->campaigns->allowedActions($s->status),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentLine(VacationScheduleLine $l, ?string $personName = null): array
    {
        return [
            'id' => $l->id, 'vacation_schedule_id' => $l->vacation_schedule_id, 'employment_id' => $l->employment_id,
            'person_name' => $personName, 'starts_on' => $l->day('starts_on'), 'ends_on' => $l->day('ends_on'),
            'days' => $l->days === null ? null : (string) BigDecimal::of($l->days)->toScale(2), 'status' => $l->status,
            'responded_at' => $l->responded_at?->toIso8601String(), 'response_note' => $l->response_note,
            'vacation_request_id' => $l->vacation_request_id, 'allowed_actions' => $this->lines->allowedActions($l->status),
        ];
    }

    /**
     * RN-VAC-09 · RN-VAC-10 · Alertas de acumulación de las relaciones dadas (ya filtradas por alcance).
     *
     * @param  array<int, string>  $employmentIds
     * @return list<array<string, mixed>>
     */
    public function alerts(array $employmentIds, string $today): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $rows = DB::table('vacation_accrual_periods as ap')
            ->leftJoinSub(DB::table('vacation_ledger_entries')->select('vacation_accrual_period_id', DB::raw('sum(days) as available'))->groupBy('vacation_accrual_period_id'), 's', 's.vacation_accrual_period_id', '=', 'ap.id')
            ->whereIn('ap.employment_id', $employmentIds)->whereIn('ap.status', ['earned', 'overdue'])->where(DB::raw('coalesce(s.available, 0)'), '>', 0)
            ->orderBy('ap.period_start')->get(['ap.id', 'ap.employment_id', 'ap.period_start', 'ap.period_end', 'ap.status', 'ap.earned_on', 'ap.enjoy_by', 's.available'])
            ->groupBy('employment_id');
        $out = [];
        foreach ($rows as $employmentId => $periods) {
            $employmentId = (string) $employmentId;
            $entity = (string) DB::table('employments')->where('id', $employmentId)->value('legal_entity_id');
            $soonDays = (int) ($this->parameters->value('vacaciones.aviso_vencimiento_dias', $entity) ?? 60);
            $threshold = $this->parameters->value('vacaciones.alerta_acumulacion_dias', $entity);
            $limit = CarbonImmutable::parse($today)->addDays($soonDays)->toDateString();
            $total = BigDecimal::zero();
            $overdue = 0;
            $expiring = 0;
            $list = [];
            foreach ($periods as $p) {
                $total = $total->plus((string) $p->available);
                $isOverdue = $p->status === 'overdue';
                $isSoon = ! $isOverdue && $p->enjoy_by !== null && (string) $p->enjoy_by <= $limit;
                $overdue += $isOverdue ? 1 : 0;
                $expiring += $isSoon ? 1 : 0;
                $list[] = ['period_id' => (string) $p->id, 'period_start' => substr((string) $p->period_start, 0, 10), 'period_end' => substr((string) $p->period_end, 0, 10),
                    'status' => (string) $p->status, 'available' => (string) BigDecimal::of((string) $p->available)->toScale(2),
                    'enjoy_by' => $p->enjoy_by === null ? null : substr((string) $p->enjoy_by, 0, 10), 'expiring_soon' => $isSoon];
            }
            $count = count($list);
            $overThreshold = is_numeric($threshold) && (int) $threshold > 0 && $total->isGreaterThanOrEqualTo((string) $threshold);
            if ($count < 2 && $overdue === 0 && $expiring === 0 && ! $overThreshold) {
                continue;
            }
            $since = $list[0]['period_end'];
            $out[] = [
                'employment_id' => $employmentId, 'periods_with_balance' => $count, 'available_days' => (string) $total->toScale(2),
                'overdue_periods' => $overdue, 'expiring_periods' => $expiring, 'since' => $since, 'periods' => $list,
                'explanation' => (string) trans('vacations.alerts.explanation', [
                    'count' => $count, 'days' => (string) $total->stripTrailingZeros(), 'since' => CarbonImmutable::parse($since)->format('d/m/Y'),
                ]).($overdue > 0 ? ' '.trans('vacations.alerts.overdue', ['count' => $overdue]) : '')
                  .($expiring > 0 ? ' '.trans('vacations.alerts.expiring', ['count' => $expiring, 'days' => $soonDays]) : ''),
            ];
        }

        return $out;
    }

    /**
     * Tarea diaria: avisa al jefe y a quienes ajustan saldos, una sola vez por periodo vencido o por vencer.
     */
    public function notifyAlerts(string $today): int
    {
        $ids = DB::table('employments')->whereIn('status', ['active', 'suspended'])->whereNull('deleted_at')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $hr = array_values(DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->where('rp.permission_code', 'time.vacation_balances.adjust')
            ->join('users as u', 'u.id', '=', 'ur.user_id')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->distinct()->pluck('ur.user_id')->map(fn ($id): string => (string) $id)->all());
        $sent = 0;
        foreach ($this->alerts($ids, $today) as $a) {
            foreach ($a['periods'] as $p) {
                if ($p['status'] !== 'overdue' && ! $p['expiring_soon']) {
                    continue;
                }
                if (DB::table('notifications')->where('category', self::NOTICE)->where('subject_id', $p['period_id'])->exists()) {
                    continue;
                }
                $recipients = $hr;
                $manager = $this->masterData->managerAt($a['employment_id'], $today);
                $managerUser = $manager === null ? null : DB::table('employments as e')->join('users as u', 'u.person_id', '=', 'e.person_id')
                    ->where('e.id', $manager)->whereNull('u.deleted_at')->value('u.id');
                if ($managerUser !== null) {
                    $recipients[] = (string) $managerUser;
                }
                $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $a['employment_id'])
                    ->first(['p.preferred_name', 'p.first_names', 'p.paternal_surname']);
                $name = $person === null ? '' : ($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname;
                $this->notifier->notify(self::NOTICE, array_values(array_unique($recipients)), [
                    'employee' => trim((string) $name), 'explanation' => (string) $a['explanation'],
                ], 'vacation_accrual_period', $p['period_id']);
                $sent++;
            }
        }

        return $sent;
    }
}
