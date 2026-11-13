<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\SocialSecurityService;
use App\Modules\CoreHR\Infrastructure\Models\HealthProvider;
use App\Modules\CoreHR\Infrastructure\Models\InsurancePolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * COL-7 · Catálogos de previsión y seguros: administradoras de pensiones (de la plataforma, por país),
 * entidades de salud de la organización (EPS, aseguradoras) y pólizas del empleador por empresa (Vida Ley,
 * SCTR). Las pólizas se ven y registran con el alcance de empresa del usuario.
 */
final class InsuranceCatalogController
{
    public function __construct(
        private readonly SocialSecurityService $service,
        private readonly DataScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function pensionFunds(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country', 'PE'));
        $rows = DB::table('pension_funds')->where('country_code', $country)->where('status', 'active')->orderBy('kind')->orderBy('name')
            ->get(['code', 'name', 'kind'])->map(fn ($r): array => ['code' => (string) $r->code, 'name' => (string) $r->name, 'kind' => (string) $r->kind])->all();

        return response()->json(['data' => $rows]);
    }

    public function healthProviders(): JsonResponse
    {
        $rows = HealthProvider::query()->orderBy('name')->get()->map(fn (HealthProvider $p): array => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'kind' => $p->kind, 'tax_id' => $p->tax_id,
        ])->all();

        return response()->json(['data' => $rows]);
    }

    public function storeHealthProvider(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{2,40}$/'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'kind' => ['required', Rule::in(['eps', 'insurer', 'public'])],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        if (HealthProvider::query()->where('code', $input['code'])->exists()) {
            throw new AyniException('conflict', 409, (string) trans('people.health.provider_exists'));
        }
        $provider = DB::transaction(function () use ($input): HealthProvider {
            $p = HealthProvider::create($input);
            $this->audit->record('health_provider.created', 'health_provider', $p->id, null, $input);

            return $p;
        });

        return response()->json(['data' => ['id' => $provider->id, 'code' => $provider->code, 'name' => $provider->name, 'kind' => $provider->kind, 'tax_id' => $provider->tax_id]], 201);
    }

    public function policies(Request $request): JsonResponse
    {
        $query = InsurancePolicy::query();
        $this->scope->applyLegalEntity($query, $request, 'org.legal_entities.view');
        foreach (['legal_entity_id', 'kind', 'status'] as $filter) {
            $value = $request->query($filter);
            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }
        $rows = $query->orderBy('kind')->orderByDesc('starts_on')->get()->map(fn (InsurancePolicy $p) => $this->service->presentPolicy($p))->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $input = $request->validate([
            'legal_entity_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(SocialSecurityService::POLICY_KINDS)],
            'insurer' => ['required', 'string', 'min:2', 'max:150'],
            'policy_number' => ['required', 'string', 'min:1', 'max:60'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'registered_with_authority_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'health_provider_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $entity = DB::table('legal_entities')->where('id', $input['legal_entity_id'])->whereNull('deleted_at');
        $this->scope->applyLegalEntity($entity, $request, 'org.legal_entities.update', 'id');
        if (! $entity->exists()) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('people.out_of_scope')]]);
        }

        return response()->json(['data' => $this->service->presentPolicy($this->service->createPolicy($input))], 201);
    }

    public function updatePolicy(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = InsurancePolicy::query()->whereKey($id);
        $this->scope->applyLegalEntity($query, $request, 'org.legal_entities.update');
        $policy = $query->first();
        abort_unless($policy instanceof InsurancePolicy, 404);
        $input = $request->validate([
            'insurer' => ['sometimes', 'string', 'min:2', 'max:150'],
            'policy_number' => ['sometimes', 'string', 'min:1', 'max:60'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$policy->starts_on->toDateString()],
            'registered_with_authority_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'health_provider_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(['active', 'expired', 'cancelled'])],
        ]);

        return response()->json(['data' => $this->service->presentPolicy($this->service->updatePolicy($policy, $input))]);
    }
}
