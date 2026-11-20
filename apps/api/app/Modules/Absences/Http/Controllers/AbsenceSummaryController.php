<?php

namespace App\Modules\Absences\Http\Controllers;

use App\Modules\Absences\Application\AbsencePeriodSummary;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AUS-5 · AUS-6 · Resumen de ausencias del periodo por trabajador (lo que la planilla tomará como incidencias),
 * con cada día explicado y los días remotos distinguidos, y su cierre: congelar el resumen para que un cambio
 * posterior se informe como diferencia.
 */
final class AbsenceSummaryController
{
    /** Largo máximo de un periodo consultado de una vez (una planilla quincenal o mensual cabe con holgura). */
    private const MAX_DAYS = 93;

    public function __construct(
        private readonly AbsencePeriodSummary $summaries,
        private readonly DataScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, string $employment_id): JsonResponse
    {
        // Primero el alcance: otra organización o fuera del alcance es 404 aunque falten las fechas.
        $this->assertInScope($request, $employment_id, 'time.absences.view');
        [$from, $to] = $this->period($request->query('from'), $request->query('to'));
        $summary = $this->summaries->summary($employment_id, $from, $to);
        $differences = $this->summaries->differences($employment_id, $from, $to);

        return response()->json(['data' => $this->present($request, $summary) + ['closing' => $differences]]);
    }

    /**
     * Cierra (congela) el resumen del periodo para las relaciones laborales del alcance. Volver a cerrar un
     * periodo sin cambios no agrega nada; con cambios agrega un nuevo cierre (el anterior se conserva).
     */
    public function close(Request $request): JsonResponse
    {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'legal_entity_id' => ['nullable', 'uuid'],
            'employment_ids' => ['nullable', 'array', 'max:500'],
            'employment_ids.*' => ['uuid'],
        ]);
        [$from, $to] = $this->period($input['from'], $input['to']);

        $query = DB::table('employments')->whereNull('deleted_at')->whereNotIn('status', ['cancelled', 'pending'])
            ->where('hire_date', '<=', $to)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from));
        if (isset($input['legal_entity_id'])) {
            $query->where('legal_entity_id', $input['legal_entity_id']);
        }
        if (isset($input['employment_ids'])) {
            $query->whereIn('id', $input['employment_ids']);
        }
        $this->scope->applyEmployment($query, $request, 'time.absences.create_for_others', 'employments.id');
        $ids = $query->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $user = $request->user();
        $userId = $user === null ? null : (string) $user->getAuthIdentifier();
        $closed = 0;
        $unchanged = 0;
        DB::transaction(function () use ($ids, $from, $to, $userId, &$closed, &$unchanged): void {
            foreach ($ids as $id) {
                $summary = $this->summaries->summary($id, $from, $to);
                $last = DB::table('absence_period_closings')->where('employment_id', $id)->where('period_from', $from)->where('period_to', $to)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('fingerprint');
                if ($last === $summary['fingerprint']) {
                    $unchanged++;

                    continue;
                }
                $closingId = (string) Str::uuid7();
                DB::table('absence_period_closings')->insert([
                    'id' => $closingId, 'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $id,
                    'period_from' => $from, 'period_to' => $to, 'fingerprint' => $summary['fingerprint'],
                    'summary' => json_encode(['days' => $summary['days'], 'types' => $summary['types'], 'totals' => $summary['totals'], 'imported_incidents' => $summary['imported_incidents']]),
                    'closed_by_user_id' => $userId,
                ]);
                $this->audit->record('absence.period_closed', 'absence_period_closing', $closingId, $last === null ? null : ['fingerprint' => $last],
                    ['employment_id' => $id, 'from' => $from, 'to' => $to, 'fingerprint' => $summary['fingerprint']]);
                $closed++;
            }
        });

        return response()->json(['data' => ['from' => $from, 'to' => $to, 'closed' => $closed, 'unchanged' => $unchanged]]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function period(mixed $from, mixed $to): array
    {
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                $errors[$key] = [(string) trans('absences.summary.invalid_date')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        /** @var string $from */
        /** @var string $to */
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => [(string) trans('absences.summary.to_before_from')]]);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => [(string) trans('absences.summary.too_long', ['days' => self::MAX_DAYS])]]);
        }

        return [$from, $to];
    }

    private function assertInScope(Request $request, string $employmentId, string $permission): void
    {
        if (! Str::isUuid($employmentId)) {
            throw new AyniException('not_found', 404);
        }
        $query = DB::table('employments')->where('employments.id', $employmentId)->whereNull('deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        if (! $query->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    /**
     * Explica cada día en el idioma del usuario y oculta el tipo de una ausencia confidencial (salud) a quien
     * no puede ver datos de salud: la planilla la recibe por el puerto, con todo.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function present(Request $request, array $summary): array
    {
        $canSeeHealth = $this->permissions($request)->has('time.absences.medical_certificate.view');
        $mask = function (array $absence) use ($canSeeHealth): array {
            if ($absence['is_confidential'] && ! $canSeeHealth) {
                $absence['type_name'] = (string) trans('absences.summary.confidential');
                $absence['category'] = 'confidential';
                $absence['type_code'] = 'confidential';
                $absence['payroll_concept_code'] = null;
                $absence['statutory_suspension_code'] = null;
            }

            return $absence;
        };
        /** @var list<array<string, mixed>> $days */
        $days = $summary['days'];
        foreach ($days as $i => $day) {
            if (is_array($day['absence'])) {
                $day['absence'] = $mask($day['absence']);
            }
            $day['explanation'] = $this->explain($day);
            $days[$i] = $day;
        }
        /** @var list<array<string, mixed>> $types */
        $types = $summary['types'];
        foreach ($types as $i => $type) {
            $type = $mask($type);
            if (! $canSeeHealth && $type['is_confidential']) {
                $type['absence_ids'] = [];
            }
            $types[$i] = $type;
        }
        $summary['days'] = $days;
        $summary['types'] = $types;

        return $summary;
    }

    /** @param array<string, mixed> $day */
    private function explain(array $day): string
    {
        /** @var array<string, mixed>|null $absence */
        $absence = $day['absence'];
        $type = is_array($absence) ? (string) $absence['type_name'] : '';
        $effect = is_array($absence) ? (string) trans('absences.summary.effects.'.$absence['effect']) : '';

        return match ($day['kind']) {
            'absence' => (string) trans('absences.summary.days.absence', ['type' => $type, 'effect' => $effect]),
            'remote' => (string) trans('absences.summary.days.remote_'.$day['remote_source']),
            default => (string) trans('absences.summary.days.'.$day['kind']),
        }.($day['hours_absent'] !== null ? ' '.trans('absences.summary.days.hours', ['hours' => $day['hours_absent'], 'type' => $type, 'effect' => $effect]) : '');
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
