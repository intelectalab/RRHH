<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\PayrollPayments;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * PER-7 · Comparación con el periodo anterior, anomalías y umbrales; PER-10 · lotes de pago.
 *
 * Los montos son sensibles: la comparación y las anomalías exigen payroll.results.view; los lotes muestran importes
 * solo con ese permiso. El alcance por empresa y por trabajador se aplica en la consulta (regla 6). Primero se ubica
 * el recurso (404 si es de otra organización o fuera del alcance) y luego se validan los datos.
 */
final class PayrollReviewController
{
    public function __construct(
        private readonly PayrollReview $review,
        private readonly PayrollPayments $payments,
        private readonly DataScope $scope,
        private readonly PermissionResolver $resolver,
    ) {}

    public function variance(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.view');
        $this->requireResults($request);
        $runId = $request->query('run_id');
        $runId = is_string($runId) && preg_match('/^[0-9a-f-]{36}$/i', $runId) === 1 ? $runId : null;

        return response()->json(['data' => $this->review->variance($p->id, $runId, $this->visible($request, 'payroll.periods.view'))]);
    }

    public function anomalies(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.view');
        $this->requireResults($request);
        $runId = $request->query('run_id');
        $runId = is_string($runId) && preg_match('/^[0-9a-f-]{36}$/i', $runId) === 1
            && DB::table('payroll_runs')->where('id', $runId)->where('payroll_period_id', $p->id)->exists() ? $runId : $this->review->currentRun($p->id);
        $rows = $runId === null ? [] : $this->review->anomalies($runId, $this->visible($request, 'payroll.periods.view'));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function reviewAnomaly(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'review');
    }

    public function dismissAnomaly(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'dismiss');
    }

    public function thresholds(Request $request): JsonResponse
    {
        $entity = $request->input('filter.legal_entity_id');
        $rows = $this->review->thresholds(is_string($entity) && preg_match('/^[0-9a-f-]{36}$/i', $entity) === 1 ? $entity : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function storeThreshold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'payroll_concept_id' => ['sometimes', 'nullable', 'uuid', 'exists:payroll_concepts,id'],
            'threshold_pct' => ['required', 'numeric', 'gt:0', 'max:1000'],
        ]);
        if (! empty($data['legal_entity_id'])) {
            $entity = DB::table('legal_entities')->where('legal_entities.id', $data['legal_entity_id']);
            $this->scope->applyLegalEntity($entity, $request, 'payroll.periods.calculate', 'legal_entities.id');
            abort_unless($entity->exists(), 404);
        }

        return response()->json(['data' => $this->review->setThreshold($data['legal_entity_id'] ?? null, $data['payroll_concept_id'] ?? null, (string) $data['threshold_pct'])], 201);
    }

    public function destroyThreshold(string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $this->review->deleteThreshold($id);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function batches(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.view');
        $rows = $this->payments->forPeriod($p->id, $this->can($request, 'payroll.results.view'));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function prepareBatches(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.mark_paid');
        $data = $request->validate(['payment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->payments->prepare($p, $this->userId($request), $data['payment_date'] ?? null)], 201);
    }

    public function batch(Request $request, string $id): JsonResponse
    {
        $this->batchPeriod($request, $id, 'payroll.periods.view');

        return response()->json(['data' => $this->payments->show($id, $this->can($request, 'payroll.results.view'))]);
    }

    public function generateBatch(Request $request, string $id): JsonResponse
    {
        $this->batchPeriod($request, $id, 'payroll.periods.mark_paid');
        $data = $request->validate(['format' => ['sometimes', 'string', 'max:40']]);

        return response()->json(['data' => $this->payments->generate($id, $this->userId($request), (string) ($data['format'] ?? 'generic_csv'))]);
    }

    public function downloadBatch(Request $request, string $id): Response
    {
        $this->batchPeriod($request, $id, 'payroll.periods.mark_paid');
        $file = $this->payments->download($id, $this->userId($request));

        return response($file['contents'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"']);
    }

    public function confirmBatch(Request $request, string $id): JsonResponse
    {
        $this->batchPeriod($request, $id, 'payroll.periods.mark_paid');
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:120'],
            'payment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rejected' => ['sometimes', 'array', 'max:5000'],
            'rejected.*.employment_id' => ['required', 'uuid'],
            'rejected.*.reason' => ['required', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->payments->confirm($id, $this->userId($request), (string) $data['external_reference'], $data['payment_date'] ?? null,
            array_values(array_map(fn (array $r): array => ['employment_id' => (string) $r['employment_id'], 'reason' => (string) $r['reason']], $data['rejected'] ?? [])))]);
    }

    public function cancelBatch(Request $request, string $id): JsonResponse
    {
        $this->batchPeriod($request, $id, 'payroll.periods.mark_paid');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->payments->cancel($id, $data['reason'] ?? null)]);
    }

    private function decide(Request $request, string $id, string $action): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $periodId = DB::table('payroll_run_anomalies AS a')->join('payroll_runs AS r', 'r.id', '=', 'a.payroll_run_id')->where('a.id', $id)->value('r.payroll_period_id');
        abort_unless($periodId !== null, 404);
        $this->period($request, (string) $periodId, 'payroll.periods.calculate');
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->review->review($id, $action, $data['note'] ?? null, $this->userId($request))]);
    }

    private function batchPeriod(Request $request, string $id, string $permission): PayrollPeriod
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $periodId = $this->payments->periodOf($id);
        abort_unless($periodId !== null, 404);

        return $this->period($request, $periodId, $permission);
    }

    private function period(Request $request, string $id, string $permission): PayrollPeriod
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollPeriod::query()->select('payroll_periods.*')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id')
            ->where('payroll_periods.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission, 'payroll_groups.legal_entity_id');
        $p = $query->first();
        abort_unless($p instanceof PayrollPeriod, 404);

        return $p;
    }

    /** @return list<string> */
    private function visible(Request $request, string $permission): array
    {
        $query = DB::table('employments')->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');

        return array_values($query->pluck('employments.id')->map(fn ($v): string => (string) $v)->all());
    }

    private function requireResults(Request $request): void
    {
        abort_unless($this->can($request, 'payroll.results.view'), 403);
    }

    private function can(Request $request, string $permission): bool
    {
        $userId = $this->userId($request);

        return $userId !== null && $this->resolver->for($userId)->has($permission);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
