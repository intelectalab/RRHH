<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Modules\Contracts\Application\RenewalBatchService;
use App\Modules\Contracts\Domain\RenewalBatchStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractRenewalBatch;
use App\Modules\Contracts\Infrastructure\Models\ContractRenewalBatchItem;
use App\Shared\Permissions\DataScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CON-5 · Lotes de renovación masiva con firma. Solo entran contratos del alcance de quien arma el lote; cada fila
 * muestra la decisión, el control del tope y el avance de firma de la renovación.
 */
final class RenewalBatchController
{
    public function __construct(
        private readonly RenewalBatchService $batches,
        private readonly RenewalBatchStateMachine $machine,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $query = ContractRenewalBatch::query();
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json(['data' => $rows->map(fn (ContractRenewalBatch $b) => $this->summary($b))->values(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'new_end_date' => ['required', 'date_format:Y-m-d'],
            'contract_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contract_ids.*' => ['required', 'uuid'],
        ]);
        $ids = array_values(array_unique(array_map('strval', $input['contract_ids'])));
        $q = Contract::query()->whereIn('contracts.id', $ids);
        $this->scope->applyEmployment($q, $request, 'contracts.contracts.bulk_renew', 'contracts.employment_id');
        if ($q->count() !== count($ids)) {
            throw ValidationException::withMessages(['contract_ids' => [(string) trans('contracts.renewal_batches.unknown_contract')]]);
        }
        $batch = $this->batches->create(['name' => $input['name'] ?? null, 'legal_entity_id' => $input['legal_entity_id'] ?? null,
            'new_end_date' => (string) $input['new_end_date'], 'contract_ids' => $ids], $this->userId($request));

        return response()->json(['data' => $this->detail($batch, $request)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $batch = $this->batches->refreshCompletion($this->find($id));

        return response()->json(['data' => $this->detail($batch, $request)]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $batch = $this->find($id);
        $this->machine->getNextState($batch->status, 'send');

        return response()->json(['data' => $this->detail($this->batches->send($batch, $this->userId($request)), $request)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->batches->cancel($this->find($id)), $request)]);
    }

    private function find(string $id): ContractRenewalBatch
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $batch = ContractRenewalBatch::query()->whereKey($id)->first();
        abort_unless($batch instanceof ContractRenewalBatch, 404);

        return $batch;
    }

    /** @return array<string, mixed> */
    private function summary(ContractRenewalBatch $b): array
    {
        $counts = ContractRenewalBatchItem::query()->where('contract_renewal_batch_id', $b->id)
            ->selectRaw("count(*) as total, count(*) filter (where decision = 'renew') as renew, count(*) filter (where exceeds_limit) as excluded")->first();

        return [
            'id' => $b->id, 'name' => $b->name, 'legal_entity_id' => $b->legal_entity_id, 'status' => $b->status,
            'new_end_date' => $b->new_end_date?->toDateString(), 'sent_at' => $b->sent_at?->toIso8601String(),
            'completed_at' => $b->completed_at?->toIso8601String(), 'created_at' => $b->created_at->toIso8601String(),
            'total' => (int) ($counts->total ?? 0), 'to_renew' => (int) ($counts->renew ?? 0), 'excluded' => (int) ($counts->excluded ?? 0),
            'allowed_actions' => $this->machine->allowedActions($b->status) === [] ? [] : array_values(array_intersect($this->machine->allowedActions($b->status), ['send', 'cancel'])),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ContractRenewalBatch $b, Request $request): array
    {
        $progress = $this->batches->progress($b);
        $items = $b->items()->get();
        $q = DB::table('contracts as c')->join('employments as e', 'e.id', '=', 'c.employment_id')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('c.id', $items->pluck('contract_id')->merge($items->pluck('new_contract_id')->filter())->all());
        $this->scope->applyEmployment($q, $request, 'contracts.contracts.view', 'c.employment_id');
        $contracts = $q->get(['c.id', 'c.number', 'c.status', 'c.valid_to', 'e.employee_code', 'p.first_names', 'p.paternal_surname'])->keyBy('id');

        return $this->summary($b) + ['items' => $items->filter(fn (ContractRenewalBatchItem $i) => isset($contracts[$i->contract_id]))
            ->map(function (ContractRenewalBatchItem $i) use ($contracts, $progress): array {
                /** @var \stdClass $c */
                $c = $contracts[$i->contract_id];
                $n = $i->new_contract_id === null ? null : ($contracts[$i->new_contract_id] ?? null);

                return [
                    'id' => $i->id, 'contract_id' => $i->contract_id, 'contract_number' => $c->number, 'employee_name' => trim($c->first_names.' '.$c->paternal_surname),
                    'employee_code' => $c->employee_code, 'current_end' => $c->valid_to, 'decision' => $i->decision, 'new_valid_to' => $i->new_valid_to?->toDateString(),
                    'accumulated_days_after' => $i->accumulated_days_after, 'exceeds_limit' => (bool) $i->exceeds_limit, 'notes' => $i->notes,
                    'new_contract_id' => $i->new_contract_id, 'new_contract_status' => $n?->status,
                    'signature' => $i->new_contract_id === null ? null : ($progress[$i->new_contract_id] ?? null),
                ];
            })->values()->all()];
    }

    private function userId(Request $request): string
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : '';
    }
}
