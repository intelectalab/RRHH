<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Features\FeatureFlagCatalog;
use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Domain\FeatureFlagStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * SAS-7 · Banderas de funcionalidad desde la consola.
 */
final class FeatureFlagController
{
    public function __construct(
        private readonly FeatureFlagCatalog $flags,
        private readonly TenantAdministration $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::in(['active', 'archived'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]', 'q']);
        }
        $status = $request->query('filter')['status'] ?? null;
        $q = $request->query('q');
        $rows = $this->flags->list(is_string($status) ? $status : null, is_string($q) ? $q : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,79}$/'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_enabled' => ['sometimes', 'boolean'],
            'is_beta' => ['sometimes', 'boolean'],
            ...$this->ruleRules(),
        ]);

        return response()->json(['data' => $this->flags->create($data, $this->actor($request))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->flags->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // El estado cambia solo por acciones (regla 3): «status» se ignora.
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_enabled' => ['sometimes', 'boolean'],
            'is_beta' => ['sometimes', 'boolean'],
            ...$this->ruleRules(),
        ]);

        return response()->json(['data' => $this->flags->update($id, $data, $this->actor($request))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->flags->transition($id, FeatureFlagStateMachine::ARCHIVE, $this->actor($request))]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->flags->transition($id, FeatureFlagStateMachine::RESTORE, $this->actor($request))]);
    }

    public function tenants(string $id): JsonResponse
    {
        return response()->json(['data' => $this->flags->overrides($id)]);
    }

    public function setTenant(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        return response()->json(['data' => $this->flags->setOverride($id, $data, $this->actor($request))]);
    }

    public function removeTenant(Request $request, string $id, string $tenantId): JsonResponse
    {
        return response()->json(['data' => $this->flags->removeOverride($id, $tenantId, $this->actor($request))]);
    }

    /** Resultado de las banderas para una organización y qué regla decidió cada una. */
    public function forTenant(string $id): JsonResponse
    {
        $tenant = $this->tenants->find($id);

        return response()->json(['data' => $this->flags->evaluateFor((string) $tenant->id)]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function ruleRules(): array
    {
        return [
            'rules' => ['sometimes', 'array', 'max:100'],
            'rules.*.target_kind' => ['required', Rule::in(FeatureFlagCatalog::TARGETS)],
            'rules.*.target_value' => ['required', 'string', 'max:80'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
        ];
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
