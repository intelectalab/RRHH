<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Application\Usage\TenantUsage;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * SAS-6 · Consumo de una organización desde la consola: actual, historia y comparación con otro plan.
 */
final class UsageController
{
    public function __construct(
        private readonly TenantAdministration $tenants,
        private readonly TenantUsage $usage,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $data = $this->query($request, ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $tenant = $this->tenants->find($id);

        return response()->json(['data' => $this->usage->show((string) $tenant->id, $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function planFit(Request $request, string $id): JsonResponse
    {
        $data = $this->query($request, ['plan_id' => ['required', 'uuid']]);
        $subscription = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('subscriptions')->where('id', $id)->first(['id', 'tenant_id']) : null;
        if ($subscription === null) {
            throw new ModelNotFoundException;
        }
        if (! DB::table('plans')->where('id', $data['plan_id'])->exists()) {
            throw new ModelNotFoundException;
        }
        $fit = $this->usage->planFit((string) $subscription->tenant_id, (string) $subscription->id, $data['plan_id']);

        return response()->json(['data' => $fit, 'meta' => ['over' => count(array_filter($fit, fn (array $m): bool => $m['over']))]]);
    }

    /**
     * Los GET responden 400 invalid_filter ante un parámetro de consulta inválido (contrato).
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, string>
     */
    private function query(Request $request, array $rules): array
    {
        $v = Validator::make($request->query(), $rules);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), array_keys($rules));
        }

        /** @var array<string, string> */
        return $v->validated();
    }
}
