<?php

namespace App\Modules\Vacations\Http\Controllers;

use App\Modules\Vacations\Application\VacationScheduling;
use App\Modules\Vacations\Infrastructure\Models\VacationSchedule;
use App\Modules\Vacations\Infrastructure\Models\VacationScheduleLine;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * VAC-5 · Rol anual de vacaciones y alertas de vacaciones acumuladas.
 *
 * - Campañas: listar y ver (time.vacation_requests.view); abrir, cerrar y reabrir (time.vacation_requests.schedule).
 * - Líneas: listar con alcance (el trabajador ve las suyas); proponer y retirar (schedule); confirmar o declinar
 *   (titular, time.vacation_requests.create); convertir lo confirmado en solicitudes (time.vacation_requests.validate).
 * - Alertas de acumulación con alcance (time.vacation_balances.view).
 */
final class VacationScheduleController
{
    public function __construct(
        private readonly VacationScheduling $service,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:legal_entity_id,year,status'],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'filter.status' => ['sometimes', Rule::in(['open', 'closed'])],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['legal_entity_id', 'year', 'status']);
        }
        $query = VacationSchedule::query();
        $this->scope->applyLegalEntity($query, $request, 'time.vacation_requests.view');
        foreach (['legal_entity_id', 'year', 'status'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value) || is_int($value)) {
                $query->where($f, $value);
            }
        }
        $rows = $query->orderByDesc('year')->get();

        return response()->json(['data' => $rows->map(fn (VacationSchedule $s): array => $this->service->presentSchedule($s))->values()->all(),
            'meta' => ['total' => $rows->count(), 'page' => 1, 'per_page' => max(1, $rows->count()), 'next_cursor' => null]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->presentSchedule($this->find($request, $id, 'time.vacation_requests.view'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'uuid'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $entity = DB::table('legal_entities')->where('legal_entities.id', $data['legal_entity_id']);
        $this->scope->applyLegalEntity($entity, $request, 'time.vacation_requests.schedule', 'legal_entities.id');
        abort_unless($entity->exists(), 404);
        $s = $this->service->open((string) $data['legal_entity_id'], (int) $data['year'], $data['name'] ?? null, $this->userId($request));

        return response()->json(['data' => $this->service->presentSchedule($s)], 201);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->presentSchedule($this->service->transition($this->find($request, $id, 'time.vacation_requests.schedule'), 'close'))]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->presentSchedule($this->service->transition($this->find($request, $id, 'time.vacation_requests.schedule'), 'reopen'))]);
    }

    public function lines(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'time.vacation_requests.view');
        $query = VacationScheduleLine::query()->select('vacation_schedule_lines.*')->where('vacation_schedule_id', $s->id);
        $this->scope->applyEmployment($query, $request, 'time.vacation_requests.view', 'vacation_schedule_lines.employment_id');
        $rows = $query->orderBy('starts_on')->get();
        $names = $this->names($rows->pluck('employment_id')->all());

        return response()->json(['data' => $rows->map(fn (VacationScheduleLine $l): array => $this->service->presentLine($l, $names[$l->employment_id] ?? null))->values()->all(),
            'meta' => ['total' => $rows->count(), 'page' => 1, 'per_page' => max(1, $rows->count()), 'next_cursor' => null]]);
    }

    public function propose(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'time.vacation_requests.schedule');
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $query = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, 'time.vacation_requests.schedule', 'employments.id');
        abort_unless($query->exists(), 404);
        $result = $this->service->propose($s, (string) $data['employment_id'], (string) $data['starts_on'], (string) $data['ends_on'], $this->userId($request));

        return response()->json(['data' => $this->service->presentLine($result['line'], $this->names([$result['line']->employment_id])[$result['line']->employment_id] ?? null),
            'meta' => ['warnings' => $result['warnings']]], 201);
    }

    public function convert(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'time.vacation_requests.validate');

        return response()->json(['data' => $this->service->convert($s, (string) $this->userId($request))]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        return $this->respondLine($this->service->respond($this->line($request, $id, 'time.vacation_requests.create'), 'confirm', $this->note($request)));
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $line = $this->line($request, $id, 'time.vacation_requests.create');

        return $this->respondLine($this->service->respond($line, 'decline', $this->note($request)));
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        return $this->respondLine($this->service->respond($this->line($request, $id, 'time.vacation_requests.schedule'), 'withdraw', $this->note($request)));
    }

    public function alerts(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), ['filter' => ['sometimes', 'array:legal_entity_id'], 'filter.legal_entity_id' => ['sometimes', 'uuid']]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['legal_entity_id']);
        }
        $query = DB::table('employments')->whereNull('employments.deleted_at')->whereIn('employments.status', ['active', 'suspended']);
        $this->scope->applyEmployment($query, $request, 'time.vacation_balances.view', 'employments.id');
        $entity = DataScope::selectedLegalEntity($request) ?? $request->input('filter.legal_entity_id');
        if (is_string($entity)) {
            $query->where('employments.legal_entity_id', $entity);
        }
        $ids = $query->pluck('employments.id')->map(fn ($v): string => (string) $v)->all();
        $alerts = $this->service->alerts($ids, now()->toDateString());
        $names = $this->names(array_column($alerts, 'employment_id'));
        $data = array_map(fn (array $a): array => ['id' => $a['employment_id'], 'person_name' => $names[$a['employment_id']] ?? null] + $a, $alerts);

        return response()->json(['data' => $data, 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    private function respondLine(VacationScheduleLine $l): JsonResponse
    {
        return response()->json(['data' => $this->service->presentLine($l, $this->names([$l->employment_id])[$l->employment_id] ?? null)]);
    }

    private function note(Request $request): ?string
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return isset($data['note']) ? (string) $data['note'] : null;
    }

    private function find(Request $request, string $id, string $permission): VacationSchedule
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = VacationSchedule::query()->where('vacation_schedules.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission);
        $s = $query->first();
        abort_unless($s instanceof VacationSchedule, 404);

        return $s;
    }

    private function line(Request $request, string $id, string $permission): VacationScheduleLine
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = VacationScheduleLine::query()->select('vacation_schedule_lines.*')->where('vacation_schedule_lines.id', $id);
        $this->scope->applyEmployment($query, $request, $permission, 'vacation_schedule_lines.employment_id');
        $l = $query->first();
        abort_unless($l instanceof VacationScheduleLine, 404);

        return $l;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        return DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', $ids)
            ->get(['e.id', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as n")])
            ->mapWithKeys(fn ($r): array => [(string) $r->id => trim((string) $r->n)])->all();
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
