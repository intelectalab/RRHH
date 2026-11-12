<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Subscriptions\SubscriptionService;
use App\Modules\Platform\Domain\SubscriptionStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SAS-4 · Suscripciones de las organizaciones: plan, complementos, prueba, cambio de plan, renovación y
 * cancelación. El estado cambia solo por acciones (regla 3) y arrastra el de la organización (ME-40).
 */
final class SubscriptionController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'])],
            'filter.tenant_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $filter = (array) $request->query('filter', []);

        return response()->json($this->subscriptions->list(
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '25'),
            isset($filter['status']) ? (string) $filter['status'] : null,
            isset($filter['tenant_id']) ? (string) $filter['tenant_id'] : null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'plan_id' => ['required', 'uuid'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual'])],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:starts_on'],
            'trial_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        return response()->json(['data' => $this->subscriptions->create($data, $this->actor($request))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // «status» y «plan_id» no se editan aquí: cambian por acciones (change-plan, suspend…).
        $data = $request->validate([
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual'])],
            'price' => ['sometimes', 'nullable', 'array'],
            'price.amount' => ['required_with:price', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'price.currency' => ['required_with:price', 'string', 'size:3'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'trial_ends_on' => ['sometimes', 'date_format:Y-m-d', 'after:today'],
        ]);

        return response()->json(['data' => $this->subscriptions->update($id, $data, $this->actor($request))]);
    }

    public function changePlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->subscriptions->changePlan($id, (string) $data['plan_id'], $this->actor($request))]);
    }

    public function convert(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubscriptionStateMachine::CONVERT);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubscriptionStateMachine::SUSPEND);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubscriptionStateMachine::REACTIVATE);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubscriptionStateMachine::CANCEL);
    }

    public function addAddon(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'addon_id' => ['required', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json(['data' => $this->subscriptions->addAddon($id, (string) $data['addon_id'], (int) ($data['quantity'] ?? 1), $this->actor($request))]);
    }

    public function removeAddon(Request $request, string $id, string $addonId): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->removeAddon($id, $addonId, $this->actor($request))]);
    }

    public function events(string $id): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->history($id)]);
    }

    private function act(Request $request, string $id, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->subscriptions->transition($id, $action, isset($data['reason']) ? (string) $data['reason'] : null, $this->actor($request))]);
    }

    private function actor(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user('platform');
    }
}
