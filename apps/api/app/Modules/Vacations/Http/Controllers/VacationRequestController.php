<?php

namespace App\Modules\Vacations\Http\Controllers;

use App\Modules\Vacations\Application\TeamCalendar;
use App\Modules\Vacations\Application\VacationRequestService;
use App\Modules\Vacations\Infrastructure\Models\VacationRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VAC-3 · Solicitudes de vacaciones (ME-15). VAC-4 · Calendario del equipo.
 *
 * El alcance se aplica en la consulta (regla 6): el titular ve y pide lo suyo, el jefe lo de su equipo y
 * RRHH (time.vacation_requests.validate) registra a nombre de otros. Fuera del alcance, 404. Aprobar y
 * rechazar se hace en el centro de aprobaciones (/approval-requests/{id}/approve|reject).
 */
final class VacationRequestController
{
    private const STATUSES = ['draft', 'pending', 'hr_review', 'approved', 'in_progress', 'taken', 'rejected', 'cancelled'];

    private const HR = 'time.vacation_requests.validate';

    public function __construct(
        private readonly VacationRequestService $requests,
        private readonly TeamCalendar $team,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.mine' => ['sometimes', 'in:true,false,1,0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('vacations.requests.invalid_filter'));
        }

        $query = $this->scoped($request, 'time.vacation_requests.view');
        $query->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when(isset($filter['employment_id']), fn ($q) => $q->where('employment_id', $filter['employment_id']))
            ->when(isset($filter['from']), fn ($q) => $q->where('ends_on', '>=', $filter['from']))
            ->when(isset($filter['to']), fn ($q) => $q->where('starts_on', '<=', $filter['to']));
        if (in_array($filter['mine'] ?? null, ['true', '1'], true)) {
            $person = $this->viewerPerson($request);
            $query->whereIn('employment_id', DB::table('employments')->where('person_id', $person ?? '00000000-0000-0000-0000-000000000000')->select('id'));
        }
        $total = (clone $query)->count();
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $rows = $query->orderByDesc('starts_on')->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $rows->map(fn (VacationRequest $v): array => $this->view($request, $v))->values()->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->view($request, $this->find($request, $id, 'time.vacation_requests.view'), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{employment_id: string, starts_on: string, ends_on: string, notes?: string|null, exception_reason?: string|null} $input */
        $input = $this->validated($request, true);
        $this->role($request, $input['employment_id'], 'time.vacation_requests.create');
        $created = $this->requests->create($input, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $created, true)], 201);
    }

    /** Simulación: días, saldo, reglas y cobertura sin escribir nada (regla 11: el navegador no calcula). */
    public function simulate(Request $request): JsonResponse
    {
        /** @var array{employment_id: string, starts_on: string, ends_on: string, notes?: string|null, exception_reason?: string|null} $input */
        $input = $this->validated($request, true);
        $role = $this->role($request, $input['employment_id'], 'time.vacation_requests.create');
        $result = $this->requests->evaluate($input['employment_id'], $input['starts_on'], $input['ends_on'], $role, $input['exception_reason'] ?? null);

        return response()->json(['data' => $result + ['role' => $role]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'time.vacation_requests.update');
        $input = $this->validated($request, false);

        return response()->json(['data' => $this->view($request, $this->requests->update($found, $input), true)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'time.vacation_requests.submit');
        $role = $this->role($request, $found->employment_id, 'time.vacation_requests.submit');
        $result = $this->requests->submit($found, $role, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $result['request'], true), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function register(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, self::HR);
        $result = $this->requests->register($found, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $result['request'], true), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'time.vacation_requests.cancel');
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        if (! $this->isOwner($request, $found) && ! $this->permissions($request)->has(self::HR)) {
            throw new AyniException('forbidden', 403, (string) trans('vacations.requests.cancel_forbidden'));
        }

        return response()->json(['data' => $this->view($request, $this->requests->cancel($found, $input['reason'] ?? null, (string) $this->userId($request)), true)]);
    }

    /**
     * VAC-4 · Cruces de la solicitud con su equipo antes de aprobar: quién más está ausente en esas fechas
     * (con el alcance de quien consulta) y si se supera el máximo de ausentes de la unidad.
     */
    public function overlaps(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'time.vacation_requests.view');
        $from = $found->day('starts_on');
        $to = $found->day('ends_on');
        $unit = DB::table('employment_assignments')->where('employment_id', $found->employment_id)->where('valid_from', '<=', $from)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->value('org_unit_id');
        $people = [];
        if ($unit !== null && (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) < TeamCalendar::MAX_RANGE_DAYS) {
            $calendar = $this->team->build($request, $this->permissions($request), $from, $to, (string) $unit);
            foreach ($calendar['people'] as $person) {
                $items = array_values(array_filter($person['items'], fn (array $i): bool => $i['id'] !== $found->id));
                if ($person['employment_id'] !== $found->employment_id && $items !== []) {
                    $people[] = ['employment_id' => $person['employment_id'], 'person_name' => $person['person_name'], 'items' => $items];
                }
            }
        }

        return response()->json(['data' => [
            'id' => $found->id,
            'starts_on' => $from,
            'ends_on' => $to,
            'org_unit_id' => $unit === null ? null : (string) $unit,
            'people' => $people,
            'coverage' => $this->team->coverageFor($found->employment_id, $from, $to, $found->id),
        ]]);
    }

    /** VAC-4 · Calendario del equipo. */
    public function teamCalendar(Request $request): JsonResponse
    {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->team->build($request, $this->permissions($request), $input['from'], $input['to'], $input['org_unit_id'] ?? null)]);
    }

    // ------------------------------------------------------------------ Internos

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        /** @var array<string, mixed> */
        return $request->validate([
            'employment_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'exception_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return Builder<VacationRequest>
     */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = VacationRequest::query();
        $this->scope->applyEmployment($query, $request, $permission, 'vacation_requests.employment_id');

        return $query;
    }

    private function find(Request $request, string $id, string $permission): VacationRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $found = $this->scoped($request, $permission)->whereKey($id)->first();
        if (! $found instanceof VacationRequest && $permission !== self::HR && $this->permissions($request)->has(self::HR)) {
            $found = $this->scoped($request, self::HR)->whereKey($id)->first();
        }
        abort_unless($found instanceof VacationRequest, 404);

        return $found;
    }

    /**
     * Quién actúa sobre la relación: el titular, RRHH o el jefe (que la ve dentro de su alcance).
     * Fuera de todo alcance, 404.
     */
    private function role(Request $request, string $employmentId, string $permission): string
    {
        $owner = DB::table('employments')->where('id', $employmentId)->value('person_id');
        if ($owner !== null && $owner === $this->viewerPerson($request)) {
            return 'employee';
        }
        $hr = $this->permissions($request)->has(self::HR);
        $query = DB::table('employments')->where('employments.id', $employmentId)->whereNull('deleted_at');
        $this->scope->applyEmployment($query, $request, $hr ? self::HR : $permission, 'employments.id');
        if (! $query->exists()) {
            throw new AyniException('not_found', 404, (string) trans('vacations.employment_not_found'));
        }

        return $hr ? 'hr' : 'manager';
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Request $request, VacationRequest $found, bool $detail = false): array
    {
        $permissions = $this->permissions($request);
        $row = $this->requests->present($found, $detail);
        $row['allowed_actions'] = $this->requests->allowedActions($found, fn (string $p): bool => $permissions->has($p), $this->isOwner($request, $found));

        return $row;
    }

    private function isOwner(Request $request, VacationRequest $found): bool
    {
        $viewer = $this->viewerPerson($request);

        return $viewer !== null && DB::table('employments')->where('id', $found->employment_id)->value('person_id') === $viewer;
    }

    private function viewerPerson(Request $request): ?string
    {
        $user = $request->user();
        $person = $user === null ? null : DB::table('users')->where('id', $user->getAuthIdentifier())->value('person_id');

        return $person === null ? null : (string) $person;
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }
}
