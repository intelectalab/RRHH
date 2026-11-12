<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Subscriptions\SubscriptionService;
use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Domain\TenantStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SAS-2 · Organizaciones desde la consola: lista, alta, ficha, edición y cambios de estado por acción.
 */
final class TenantController
{
    private const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private readonly TenantAdministration $tenants,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['trial', 'active', 'grace_period', 'suspended', 'blocked', 'cancelled'])],
        ]);
        $filter = (array) $request->query('filter', []);

        return response()->json($this->tenants->list(
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '25'),
            isset($filter['status']) ? (string) $filter['status'] : null,
            $request->query('q') === null ? null : (string) $request->query('q'),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'slug' => ['required', 'string', 'max:63', 'regex:'.self::SLUG],
            'country' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:64'],
            'locale' => ['required', Rule::in(TenantAdministration::LOCALES)],
            'trial_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'admin_name' => ['required', 'string', 'min:2', 'max:200'],
            'admin_email' => ['required', 'email', 'max:254'],
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'tax_id' => ['required', 'string', 'max:30'],
            'payroll_regime' => ['required', 'string', 'max:40'],
            'module_codes' => ['sometimes', 'array', 'max:40'],
            'module_codes.*' => ['string', 'max:40', 'exists:modules,code'],
            // SAS-4: con plan, el alta crea la suscripción (en prueba si hay fecha de fin) y activa sus módulos.
            'plan_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $actor = $this->actor($request);

        $tenant = DB::transaction(function () use ($data, $actor): array {
            $tenant = $this->tenants->create($data, $actor);
            if (! empty($data['plan_id'])) {
                $this->subscriptions->create(['tenant_id' => $tenant['id'], 'plan_id' => $data['plan_id']], $actor);

                return $this->tenants->show((string) $tenant['id']);
            }

            return $tenant;
        });

        return response()->json(['data' => $tenant], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->tenants->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // El estado no se edita: cambia solo por acciones (regla 3); «status» se ignora.
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'locale' => ['sometimes', Rule::in(TenantAdministration::LOCALES)],
            'trial_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        return response()->json(['data' => $this->tenants->update($id, $data, $this->actor($request))]);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, TenantStateMachine::ACTIVATE);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, TenantStateMachine::SUSPEND);
    }

    public function block(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, TenantStateMachine::BLOCK);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, TenantStateMachine::REACTIVATE);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, TenantStateMachine::CANCEL);
    }

    public function statusEvents(string $id): JsonResponse
    {
        return response()->json(['data' => $this->tenants->statusEvents($id)]);
    }

    private function act(Request $request, string $id, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->tenants->transition($id, $action, isset($data['reason']) ? (string) $data['reason'] : null, $this->actor($request))]);
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
