<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Modules\Contracts\Application\AmendmentService;
use App\Modules\Contracts\Application\ContractDocuments;
use App\Modules\Contracts\Application\ContractPresenter;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CON-3 · Adendas al contrato. Se ubican por el contrato y este por la relación laboral, con el alcance
 * del usuario aplicado en la consulta (regla 6). El sueldo pactado (salary_change) solo viaja con el
 * permiso de campo de sueldo y su lectura queda registrada (reglas 6 y 7); registrarlo exige editarlo.
 */
final class ContractAddendumController
{
    private const SCHEMA = 'ContractAddendum';

    public function __construct(
        private readonly AmendmentService $amendments,
        private readonly ContractPresenter $presenter,
        private readonly ContractDocuments $documents,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ContractAddendum::query()->select('contract_amendments.*')
            ->join('contracts', 'contracts.id', '=', 'contract_amendments.contract_id');
        $this->scope->applyEmployment($query, $request, 'contracts.addenda.view', 'contracts.employment_id');
        $filter = (array) $request->query('filter', []);
        if (isset($filter['contract_id']) && is_string($filter['contract_id'])) {
            abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $filter['contract_id']) === 1, 400);
            $query->where('contract_amendments.contract_id', $filter['contract_id']);
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('contract_amendments.effective_on')->forPage($page, $perPage)->get()
            ->map(fn (ContractAddendum $a) => $this->presenter->addendum($a))->all();

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $rows, $this->permissions($request)),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(ContractAddendum::KINDS)],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:4000'],
            'changes' => ['sometimes', 'array'],
        ]);
        $contract = $this->contract($request, (string) $data['contract_id'], 'contracts.addenda.create');
        $addendum = $this->amendments->create($contract, $data, $this->permissions($request)->has('people.employments.salary.edit'), $this->userId($request));

        return $this->respond($request, $addendum, $contract, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$addendum, $contract] = $this->find($request, $id, 'contracts.addenda.view');

        return $this->respond($request, $addendum, $contract);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        [$addendum, $contract] = $this->find($request, $id, 'contracts.addenda.issue');
        $this->amendments->issue($addendum, $contract, $this->userId($request));

        return $this->respond($request, $addendum, $contract);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        [$addendum, $contract] = $this->find($request, $id, 'contracts.addenda.cancel');
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->amendments->cancel($addendum, $reason);

        return $this->respond($request, $addendum, $contract);
    }

    public function registerSignature(Request $request, string $id): JsonResponse
    {
        [$addendum, $contract] = $this->find($request, $id, 'contracts.addenda.issue');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
            'origin' => ['sometimes', Rule::in(['physical_scan', 'electronic'])],
            'signed_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $this->amendments->registerSignature($addendum, $contract, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(),
            (string) ($data['origin'] ?? 'physical_scan'), (string) $data['signed_on'], $this->userId($request));

        return $this->respond($request, $addendum, $contract);
    }

    public function document(Request $request, string $id): Response
    {
        [$addendum] = $this->find($request, $id, 'contracts.addenda.view');
        $file = $this->documents->download($addendum->document_id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function respond(Request $request, ContractAddendum $addendum, Contract $contract, int $status = 200): JsonResponse
    {
        $personId = (string) DB::table('employments')->where('id', $contract->employment_id)->value('person_id');
        $data = $this->sensitive->present(self::SCHEMA, $this->presenter->addendum($addendum->refresh(), $contract->employment_id), $this->permissions($request), 'api', $personId);

        return response()->json(['data' => $data], $status);
    }

    private function contract(Request $request, string $id, string $permission): Contract
    {
        $query = Contract::query()->select('contracts.*')->where('contracts.id', $id);
        $this->scope->applyEmployment($query, $request, $permission, 'contracts.employment_id');
        $contract = $query->first();
        abort_unless($contract instanceof Contract, 404);

        return $contract;
    }

    /**
     * @return array{0: ContractAddendum, 1: Contract}
     */
    private function find(Request $request, string $id, string $permission): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $addendum = ContractAddendum::query()->whereKey($id)->first();
        abort_unless($addendum instanceof ContractAddendum, 404);

        return [$addendum, $this->contract($request, $addendum->contract_id, $permission)];
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
