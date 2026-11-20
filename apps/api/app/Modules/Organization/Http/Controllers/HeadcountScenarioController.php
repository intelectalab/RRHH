<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\HeadcountScenarioService;
use App\Modules\Organization\Infrastructure\Models\HeadcountPlanLine;
use App\Modules\Organization\Infrastructure\Models\HeadcountScenario;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POS-5 · Escenarios de planificación de la dotación. El alcance por empresa se aplica en la consulta (regla 6): un
 * usuario limitado a algunas empresas solo ve y crea escenarios de esas empresas. El costo proyectado (del escenario y
 * de cada línea) solo viaja con org.positions.budget.view y su lectura queda en el registro de accesos (regla 7).
 */
final class HeadcountScenarioController
{
    private const SCHEMA = 'HeadcountScenario';

    private const BUDGET = 'org.positions.budget.view';

    public function __construct(
        private readonly HeadcountScenarioService $scenarios,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request, 'org.headcount_plans.view');
        $filter = (array) $request->query('filter', []);
        if (isset($filter['status']) && is_string($filter['status'])) {
            if (! in_array($filter['status'], ['draft', 'in_review', 'approved', 'applied', 'archived'], true)) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]']);
            }
            $query->where('status', $filter['status']);
        } else {
            $query->where('status', '<>', 'archived');
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get()
            ->map(fn (HeadcountScenario $s): array => $this->present($s, false, false))->all();
        $permissions = $this->permissions($request);

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $rows, $permissions),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['current', 'conservative', 'growth', 'custom'])],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('legal_entities', 'id')->whereNull('deleted_at')],
            'base_as_of' => ['sometimes', 'date_format:Y-m-d'],
            'assumptions' => ['sometimes', 'nullable', 'array'],
        ]);
        $scenario = $this->scenarios->create($data, $this->allowedEntities($request, 'org.headcount_plans.create'), $this->userId($request));

        return $this->respond($request, $scenario, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id, 'org.headcount_plans.view'));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $scenario = $this->find($request, $id, 'org.headcount_plans.update');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(['current', 'conservative', 'growth', 'custom'])],
            'assumptions' => ['sometimes', 'nullable', 'array'],
        ]);
        $version = $request->header('If-Match');
        $expected = is_string($version) && $version !== '' ? (int) trim($version, '"W/') : null;

        return $this->respond($request, $this->scenarios->update($scenario, $data, $expected));
    }

    public function storeLine(Request $request, string $id): JsonResponse
    {
        $scenario = $this->find($request, $id, 'org.headcount_plans.update');
        $data = $request->validate([
            'org_unit_id' => ['required', 'uuid', Rule::exists('org_units', 'id')->whereNull('deleted_at')],
            'job_profile_id' => ['required', 'uuid', Rule::exists('job_profiles', 'id')->whereNull('deleted_at')],
            'site_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'planned_count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'planned_start_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ...$this->costRules(),
        ]);
        $this->guardCost($request, $data);
        $this->scenarios->addLine($scenario, $data);

        return $this->respond($request, $scenario, 201);
    }

    public function updateLine(Request $request, string $id): JsonResponse
    {
        $line = HeadcountPlanLine::query()->find($id);
        abort_unless($line instanceof HeadcountPlanLine, 404);
        $scenario = $this->find($request, $line->headcount_scenario_id, 'org.headcount_plans.update');
        $data = $request->validate([
            'change' => ['sometimes', Rule::in(['baseline', 'add', 'freeze', 'remove'])],
            'planned_count' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'planned_start_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ...$this->costRules(),
        ]);
        $this->guardCost($request, $data);
        $this->scenarios->updateLine($line, $data);

        return $this->respond($request, $scenario);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'submit');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'approve');
    }

    public function returnToDraft(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'return');
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'apply');
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'archive');
    }

    private function act(Request $request, string $id, string $action): JsonResponse
    {
        $permission = match ($action) {
            'submit' => 'org.headcount_plans.submit',
            'archive' => 'org.headcount_plans.close',
            default => 'org.headcount_plans.approve',
        };
        $scenario = $this->find($request, $id, $permission);
        $reason = $request->input('reason');

        return $this->respond($request, $this->scenarios->transition($scenario, $action, is_string($reason) ? $reason : null, $this->userId($request)));
    }

    /** Comparación lado a lado de 2 a 4 escenarios. */
    public function compare(Request $request): JsonResponse
    {
        $raw = $request->query('ids');
        $ids = is_string($raw) ? array_values(array_unique(array_filter(explode(',', $raw)))) : [];
        if (count($ids) < 2 || count($ids) > 4 || array_filter($ids, fn (string $i): bool => preg_match('/^[0-9a-f-]{36}$/i', $i) !== 1) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['ids']);
        }
        $found = $this->scoped($request, 'org.headcount_plans.view')->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($found->count() === count($ids), 404);
        $withCost = $this->permissions($request)->has(self::BUDGET);
        $scenarios = [];
        $units = [];
        foreach ($ids as $sid) {
            /** @var HeadcountScenario $s */
            $s = $found->get($sid);
            $t = $this->scenarios->totals($s);
            $row = ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'status' => $s->status, 'base_as_of' => $s->base_as_of->toDateString(), 'totals' => $t['totals']];
            if ($withCost) {
                $row['projected_cost'] = $t['cost'];
                $row['costs'] = $t['costs'];
            }
            $scenarios[] = $row;
            foreach ($t['units'] as $u) {
                $key = (string) $u['org_unit_id'];
                $units[$key] ??= ['org_unit_id' => $key, 'org_unit_name' => $u['org_unit_name'], 'scenarios' => []];
                $units[$key]['scenarios'][$s->id] = ['planned' => $u['planned'], 'active' => $u['active'], 'added' => $u['added'], 'removed' => $u['removed'], 'frozen' => $u['frozen']];
            }
        }
        if ($withCost) {
            // Regla 7: leer el costo de los escenarios comparados queda en el registro de accesos.
            $this->sensitive->presentMany(self::SCHEMA, array_map(fn (array $r): array => ['id' => $r['id'], 'projected_cost' => $r['projected_cost'] ?? null], $scenarios), $this->permissions($request));
        }
        usort($units, fn (array $a, array $b): int => strcmp((string) $a['org_unit_name'], (string) $b['org_unit_name']));

        return response()->json(['data' => ['scenarios' => $scenarios, 'units' => $units]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HeadcountScenario $s, bool $withLines, bool $withCost): array
    {
        $t = $this->scenarios->totals($s);
        $users = DB::table('users')->whereIn('id', array_filter([$s->created_by_user_id, $s->approved_by_user_id]))->pluck('display_name', 'id');
        $row = [
            'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'legal_entity_id' => $s->legal_entity_id,
            'legal_entity_name' => $s->legal_entity_id === null ? null : DB::table('legal_entities')->where('id', $s->legal_entity_id)->value('legal_name'),
            'base_as_of' => $s->base_as_of->toDateString(), 'assumptions' => $s->assumptions, 'status' => $s->status,
            'created_by_name' => $s->created_by_user_id === null ? null : ($users[$s->created_by_user_id] ?? null),
            'approved_by_name' => $s->approved_by_user_id === null ? null : ($users[$s->approved_by_user_id] ?? null),
            'approved_at' => $s->approved_at?->toIso8601String(), 'applied_at' => $s->applied_at?->toIso8601String(),
            'applied_summary' => $s->applied_summary, 'totals' => $t['totals'] + ['units' => $t['units']],
            'projected_cost' => $t['cost'],
            'allowed_actions' => $this->scenarios->allowedActions($s), 'version' => $s->lock_version,
            'created_at' => $s->created_at->toIso8601String(), 'updated_at' => $s->updated_at->toIso8601String(),
        ];
        if ($withLines) {
            $row['lines'] = $this->scenarios->lines($s, $withCost);
        }

        // Los campos opcionales sin valor no viajan (el contrato no los declara anulables).
        return array_filter($row, fn ($v): bool => $v !== null);
    }

    private function respond(Request $request, HeadcountScenario $scenario, int $status = 200): JsonResponse
    {
        $scenario->refresh();
        $permissions = $this->permissions($request);
        $data = $this->sensitive->present(self::SCHEMA, $this->present($scenario, true, $permissions->has(self::BUDGET)), $permissions);

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$scenario->lock_version.'"');
    }

    /**
     * @return Builder<HeadcountScenario>
     */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = HeadcountScenario::query();
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed);
        }

        return $query;
    }

    private function find(Request $request, string $id, string $permission): HeadcountScenario
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $scenario = $this->scoped($request, $permission)->whereKey($id)->first();
        abort_unless($scenario instanceof HeadcountScenario, 404);

        return $scenario;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function costRules(): array
    {
        return [
            'projected_cost' => ['sometimes', 'nullable', 'array'],
            'projected_cost.amount' => ['required_with:projected_cost', 'decimal:0,2', 'min:0'],
            'projected_cost.currency' => ['required_with:projected_cost', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ];
    }

    /**
     * Registrar un costo exige poder verlo.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardCost(Request $request, array $data): void
    {
        abort_if(array_key_exists('projected_cost', $data) && ! $this->permissions($request)->has(self::BUDGET), 403);
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
