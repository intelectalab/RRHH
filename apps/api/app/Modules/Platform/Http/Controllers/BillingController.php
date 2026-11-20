<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Billing\SaasBilling;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SAS-11 · Consola: cargos de la suscripción, comprobantes electrónicos, pagos y estado de cuenta por organización.
 * La organización ve su estado de cuenta con {@see self::mine()} (fuera de la consola).
 */
final class BillingController
{
    public function __construct(private readonly SaasBilling $billing) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'observed', 'issued', 'paid', 'overdue', 'void'])],
            'einvoice_status' => ['sometimes', 'nullable', Rule::in(['not_sent', 'pending', 'accepted', 'rejected'])],
        ]);

        return response()->json($this->billing->list((int) $request->query('page', '1'), (int) $request->query('per_page', '25'),
            $this->q($request, 'tenant_id'), $this->q($request, 'status'), $this->q($request, 'einvoice_status')));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->billing->show($id)]);
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->billing->generate($id, isset($data['period_start']) ? (string) $data['period_start'] : null, $this->actor($request))], 201);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->billing->issue($id, $this->actor($request))]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->billing->void($id, (string) $data['reason'], $this->actor($request))]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'gateway_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount' => ['required_without:gateway_ref', 'array'],
            'amount.amount' => ['required_with:amount', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'amount.currency' => ['required_with:amount', 'string', 'size:3'],
            'paid_on' => ['required_without:gateway_ref', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['sometimes', Rule::in(['transfer', 'card', 'wallet', 'cash', 'other'])],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->billing->recordPayment($id, $data, $this->actor($request))], 201);
    }

    public function statement(string $id): JsonResponse
    {
        if (! TenancyScope::isValidUuid($id) || ! DB::table('tenants')->where('id', $id)->exists()) {
            throw new AyniException('not_found', 404);
        }

        return response()->json(['data' => $this->billing->statementFor($id)]);
    }

    /**
     * Estado de cuenta de la organización activa (lado de la organización).
     */
    public function mine(): JsonResponse
    {
        return response()->json(['data' => $this->billing->statementForCurrentTenant()]);
    }

    private function q(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return $v === null || $v === '' ? null : (string) $v;
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
