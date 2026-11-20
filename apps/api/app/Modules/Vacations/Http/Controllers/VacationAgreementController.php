<?php

namespace App\Modules\Vacations\Http\Controllers;

use App\Modules\Vacations\Application\VacationAgreements;
use App\Modules\Vacations\Infrastructure\Models\VacationAgreement;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * VAC-6 · Acuerdos de fraccionamiento, adelanto y venta de vacaciones: listar y ver (time.vacation_balances.view,
 * con alcance), crear, emitir, registrar firmado y anular (time.vacation_balances.adjust), descargar el documento.
 */
final class VacationAgreementController
{
    public function __construct(
        private readonly VacationAgreements $service,
        private readonly DataScope $scope,
        private readonly DocumentArchive $archive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:employment_id,kind,status'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.kind' => ['sometimes', Rule::in(VacationAgreement::KINDS)],
            'filter.status' => ['sometimes', Rule::in(['draft', 'issued', 'active', 'cancelled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['employment_id', 'kind', 'status', 'page', 'per_page']);
        }
        $query = VacationAgreement::query()->select('vacation_agreements.*');
        $this->scope->applyEmployment($query, $request, 'time.vacation_balances.view', 'vacation_agreements.employment_id');
        foreach (['employment_id', 'kind', 'status'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value)) {
                $query->where('vacation_agreements.'.$f, $value);
            }
        }
        $total = (clone $query)->count();
        $perPage = (int) $request->query('per_page', '25');
        $page = (int) $request->query('page', '1');
        $rows = $query->orderByDesc('vacation_agreements.created_at')->forPage($page, $perPage)->get();
        $names = $this->names($rows->pluck('employment_id')->all());

        return response()->json([
            'data' => $rows->map(fn (VacationAgreement $a): array => $this->service->present($a) + ['person_name' => $names[$a->employment_id] ?? null])->values()->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'next_cursor' => null],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'time.vacation_balances.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(VacationAgreement::KINDS)],
            'days' => ['sometimes', 'nullable', 'numeric', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'period_id' => ['sometimes', 'nullable', 'uuid'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $query = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, 'time.vacation_balances.adjust', 'employments.id');
        abort_unless($query->exists(), 404);
        $a = $this->service->create([
            'employment_id' => (string) $data['employment_id'], 'kind' => (string) $data['kind'],
            'days' => isset($data['days']) ? (string) $data['days'] : null, 'period_id' => $data['period_id'] ?? null,
            'effective_on' => (string) $data['effective_on'], 'notes' => $data['notes'] ?? null,
        ], $this->userId($request));

        return $this->respond($a, 201);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->service->issue($this->find($request, $id, 'time.vacation_balances.adjust'), $this->userId($request)));
    }

    public function registerSignature(Request $request, string $id): JsonResponse
    {
        $a = $this->find($request, $id, 'time.vacation_balances.adjust');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
            'origin' => ['sometimes', Rule::in(['physical_scan', 'electronic'])],
            'signed_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->respond($this->service->registerSignature($a, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(),
            (string) $data['signed_on'], $this->userId($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $a = $this->find($request, $id, 'time.vacation_balances.adjust');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->respond($this->service->cancel($a, (string) $data['reason']));
    }

    public function document(Request $request, string $id): Response
    {
        $a = $this->find($request, $id, 'time.vacation_balances.view');
        $file = $a->document_id === null ? null : $this->archive->download($a->document_id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function respond(VacationAgreement $a, int $status = 200): JsonResponse
    {
        $a->refresh();

        return response()->json(['data' => $this->service->present($a) + ['person_name' => $this->names([$a->employment_id])[$a->employment_id] ?? null]], $status);
    }

    private function find(Request $request, string $id, string $permission): VacationAgreement
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = VacationAgreement::query()->select('vacation_agreements.*')->where('vacation_agreements.id', $id);
        $this->scope->applyEmployment($query, $request, $permission, 'vacation_agreements.employment_id');
        $a = $query->first();
        abort_unless($a instanceof VacationAgreement, 404);

        return $a;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        return DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', $ids)
            ->get(['e.id', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as n")])
            ->mapWithKeys(fn ($r): array => [(string) $r->id => trim((string) $r->n)])->all();
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
