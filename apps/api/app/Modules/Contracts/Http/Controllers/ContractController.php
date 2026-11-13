<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Modules\Contracts\Application\ContractDocuments;
use App\Modules\Contracts\Application\ContractPresenter;
use App\Modules\Contracts\Application\ContractService;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CON-2 · CON-3 · Contratos de las relaciones laborales (ME-11). El alcance del usuario se aplica en la
 * consulta (regla 6): fuera de alcance o de otra organización, 404. Los estados cambian solo por acciones
 * (regla 3); el PATCH edita únicamente el Borrador.
 */
final class ContractController
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractPresenter $presenter,
        private readonly ContractDocuments $documents,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request, 'contracts.contracts.view');
        $filter = (array) $request->query('filter', []);
        foreach (['employment_id', 'contract_type_id'] as $f) {
            if (isset($filter[$f]) && is_string($filter[$f])) {
                abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $filter[$f]) === 1, 400);
                $query->where("contracts.{$f}", $filter[$f]);
            }
        }
        if (isset($filter['status']) && is_string($filter['status'])) {
            $query->whereIn('contracts.status', explode(',', $filter['status']));
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        /** @var list<Contract> $rows */
        $rows = $query->orderByDesc('contracts.valid_from')->orderByDesc('contracts.created_at')
            ->forPage($page, $perPage)->get()->all();

        return response()->json(['data' => $this->presenter->many($rows), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employment_id' => ['required', 'uuid']] + $this->rules());
        // La relación debe estar en el alcance de quien crea el contrato.
        $q = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($q, $request, 'contracts.contracts.create', 'employments.id');
        abort_unless($q->exists(), 404);
        $contract = $this->contracts->create($data, $this->userId($request));

        return response()->json(['data' => $this->presenter->one($contract)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->one($this->find($request, $id, 'contracts.contracts.view'))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.update');
        $data = $request->validate($this->rules());
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');
        $contract = $this->contracts->update($contract, $data, $ifMatch === '' ? null : (int) $ifMatch);

        return response()->json(['data' => $this->presenter->one($contract)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.create');
        $warnings = $this->contracts->submit($contract, $this->userId($request));

        return $this->respond($contract, $warnings);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.approve');
        $this->contracts->approve($contract, $this->userId($request));

        return $this->respond($contract);
    }

    public function returnToDraft(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.approve');
        $this->contracts->returnToDraft($contract, $this->reason($request), $this->userId($request));

        return $this->respond($contract);
    }

    public function registerSignature(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.issue');
        [$file, $origin, $signedOn] = $this->signedFile($request);
        $warnings = $this->contracts->registerSignature($contract, (string) $file->getContent(), $file->getClientOriginalName(),
            (string) $file->getMimeType(), $origin, $signedOn, $this->userId($request));

        return $this->respond($contract, $warnings);
    }

    public function declineSignature(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.issue');
        $this->contracts->declineSignature($contract, $this->reason($request), $this->userId($request));

        return $this->respond($contract);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.update');
        $this->contracts->void($contract, $this->reason($request), $this->userId($request));

        return $this->respond($contract);
    }

    public function terminate(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.terminate');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'effective_on' => ['nullable', 'date_format:Y-m-d']]);
        $this->contracts->terminate($contract, $data['reason'], $data['effective_on'] ?? null, $this->userId($request));

        return $this->respond($contract);
    }

    public function renew(Request $request, string $id): JsonResponse
    {
        $contract = $this->find($request, $id, 'contracts.contracts.renew');
        $data = $request->validate($this->rules());
        [$successor, $warnings] = $this->contracts->renew($contract, $data, $this->userId($request));

        return response()->json(['data' => $this->presenter->one($successor, $warnings)], 201);
    }

    public function document(Request $request, string $id): Response
    {
        $contract = $this->find($request, $id, 'contracts.contracts.view');
        $file = $this->documents->download($contract->document_id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * La cadena de contratos de una relación, en orden: contrato inicial, renovaciones y sus adendas
     * (CON-3: «la cadena de contrato, renovaciones y adendas se ve en orden»).
     */
    public function chain(Request $request, string $employment_id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $employment_id) === 1, 404);
        $q = DB::table('employments')->where('employments.id', $employment_id)->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($q, $request, 'contracts.contracts.view', 'employments.id');
        abort_unless($q->exists(), 404);
        /** @var list<Contract> $contracts */
        $contracts = Contract::query()->where('employment_id', $employment_id)->orderBy('valid_from')->orderBy('created_at')->get()->all();
        $addenda = ContractAddendum::query()->whereIn('contract_id', array_map(fn (Contract $c) => $c->id, $contracts))
            ->orderBy('effective_on')->orderBy('created_at')->get()->groupBy('contract_id');
        $canAddenda = $this->permissions($request)->has('contracts.addenda.view');
        $rows = [];
        foreach ($this->presenter->many($contracts) as $i => $row) {
            $items = $canAddenda ? ($addenda->get($contracts[$i]->id)?->all() ?? []) : [];
            $row['addenda'] = $this->sensitive->presentMany('ContractAddendum', array_map(fn (ContractAddendum $a) => $this->presenter->addendum($a, $employment_id), $items), $this->permissions($request));
            $rows[] = $row;
        }

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function respond(Contract $contract, array $warnings = []): JsonResponse
    {
        return response()->json(['data' => $this->presenter->one($contract->refresh(), $warnings)]);
    }

    /**
     * @return Builder<Contract>
     */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = Contract::query()->select('contracts.*');
        $this->scope->applyEmployment($query, $request, $permission, 'contracts.employment_id');

        return $query;
    }

    private function find(Request $request, string $id, string $permission): Contract
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $contract = $this->scoped($request, $permission)->where('contracts.id', $id)->first();
        abort_unless($contract instanceof Contract, 404);

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'contract_type_id' => ['sometimes', 'uuid'],
            'number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'objective_cause' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'trial_period_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'weekly_hours' => ['sometimes', 'nullable', 'numeric'],
            'work_modality' => ['sometimes', Rule::in(['onsite', 'remote', 'hybrid'])],
        ];
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
    }

    /**
     * @return array{0: UploadedFile, 1: string, 2: string}
     */
    private function signedFile(Request $request): array
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
            'origin' => ['sometimes', Rule::in(['physical_scan', 'electronic'])],
            'signed_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return [$file, (string) ($data['origin'] ?? 'physical_scan'), (string) $data['signed_on']];
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
