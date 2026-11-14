<?php

namespace App\Modules\Absences\Http\Controllers;

use App\Modules\Absences\Application\AbsenceRequestService;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AUS-2 · Ausencias y permisos (ME-16).
 *
 * El alcance se aplica en la consulta (regla 6): el titular ve y pide lo suyo, el jefe lo de su equipo y
 * RRHH (time.absences.create_for_others) registra a nombre de otros. Fuera del alcance, 404.
 * RN-AUS-05: en los tipos confidenciales el motivo y el sustento solo viajan con
 * time.absences.medical_certificate.view (o al propio titular); el jefe ve «Ausencia justificada» y fechas.
 */
final class AbsenceRequestController
{
    private const STATUSES = ['draft', 'pending', 'pending_document', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled'];

    public function __construct(
        private readonly AbsenceRequestService $absences,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.absence_type_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.mine' => ['sometimes', 'in:true,false,1,0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('absences.requests.invalid_filter'));
        }

        $query = $this->scoped($request, 'time.absences.view');
        $query->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when(isset($filter['employment_id']), fn ($q) => $q->where('employment_id', $filter['employment_id']))
            ->when(isset($filter['absence_type_id']), fn ($q) => $q->where('absence_type_id', $filter['absence_type_id']))
            ->when(isset($filter['from']), fn ($q) => $q->where('ends_on', '>=', $filter['from']))
            ->when(isset($filter['to']), fn ($q) => $q->where('starts_on', '<=', $filter['to']));
        if (in_array($filter['mine'] ?? null, ['true', '1'], true)) {
            $person = $this->viewerPerson($request);
            $query->whereIn('employment_id', DB::table('employments')->where('person_id', $person ?? '00000000-0000-0000-0000-000000000000')->select('id'));
        }
        $total = (clone $query)->count();
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $rows = $query->orderByDesc('starts_on')->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $rows->map(fn (AbsenceRequest $a): array => $this->view($request, $a))->values()->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->view($request, $this->find($request, $id, 'time.absences.view'), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{employment_id: string, absence_type_id: string, starts_on: string, ends_on?: string|null, start_time?: string|null, end_time?: string|null, reason?: string|null, exception_reason?: string|null} $input */
        $input = $this->validated($request, true);
        // La relación debe estar en el alcance de quien la registra (titular, jefe o RRHH); si no, 404.
        $this->role($request, (string) $input['employment_id'], 'time.absences.create');
        $absence = $this->absences->create($input, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $absence, true)], 201);
    }

    /** Simulación: días u horas, reglas y avisos sin escribir nada (regla 11: el navegador no calcula). */
    public function simulate(Request $request): JsonResponse
    {
        /** @var array{employment_id: string, absence_type_id: string, starts_on: string, ends_on?: string|null, start_time?: string|null, end_time?: string|null, reason?: string|null, exception_reason?: string|null} $input */
        $input = $this->validated($request, true);
        $role = $this->role($request, (string) $input['employment_id'], 'time.absences.create');
        $type = $this->absences->type((string) $input['absence_type_id']);

        return response()->json(['data' => $this->absences->evaluate($type, (string) $input['employment_id'], $input, $role) + ['role' => $role]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $absence = $this->find($request, $id, 'time.absences.update');
        $input = $this->validated($request, false);

        return response()->json(['data' => $this->view($request, $this->absences->update($absence, $input), true)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $absence = $this->find($request, $id, 'time.absences.submit');
        $role = $this->role($request, $absence->employment_id, 'time.absences.submit');
        $result = $this->absences->submit($absence, $role, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $result['absence'], true), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function register(Request $request, string $id): JsonResponse
    {
        $absence = $this->find($request, $id, 'time.absences.create_for_others');
        $result = $this->absences->register($absence, (string) $this->userId($request));

        return response()->json(['data' => $this->view($request, $result['absence'], true), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $absence = $this->find($request, $id, 'time.absences.cancel');
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $this->assertOwnerOrHr($request, $absence);

        return response()->json(['data' => $this->view($request, $this->absences->cancel($absence, $input['reason'] ?? null, (string) $this->userId($request)), true)]);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        $absence = $this->find($request, $id, 'time.absences.update');
        $input = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $label = is_string($input['label'] ?? null) && $input['label'] !== '' ? $input['label'] : (string) trans('absences.requests.document_label');

        return response()->json(['data' => $this->view($request, $this->absences->attach(
            $absence, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(), $label, (string) $this->userId($request),
        ), true)], 201);
    }

    public function document(Request $request, string $id, string $attachment_id): Response
    {
        $absence = $this->find($request, $id, 'time.absences.view');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $attachment_id) === 1, 404);
        $confidential = (bool) DB::table('absence_types')->where('id', $absence->absence_type_id)->value('is_confidential');
        // RN-AUS-05 · El sustento de un tipo confidencial no se entrega al jefe: 404 como si no existiera.
        abort_if($confidential && ! $this->isOwner($request, $absence) && ! $this->permissions($request)->has('time.absences.medical_certificate.view'), 404);
        $file = $this->absences->document($absence, $attachment_id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ------------------------------------------------------------------ Internos

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        /** @var array<string, mixed> */
        return $request->validate([
            'employment_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'absence_type_id' => [$required, 'uuid'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'exception_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            // AUS-4 · Causales de ampliación de una licencia de ley (códigos del paquete de país).
            'legal_extensions' => ['sometimes', 'nullable', 'array', 'max:10'],
            'legal_extensions.*' => ['string', 'max:60'],
        ]);
    }

    /**
     * @return Builder<AbsenceRequest>
     */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = AbsenceRequest::query();
        $this->scope->applyEmployment($query, $request, $permission, 'absence_requests.employment_id');

        return $query;
    }

    /**
     * Ausencia dentro del alcance del permiso; RRHH, además, con el de registrar a nombre de otros.
     */
    private function find(Request $request, string $id, string $permission): AbsenceRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $found = $this->scoped($request, $permission)->whereKey($id)->first();
        if (! $found instanceof AbsenceRequest && $permission !== 'time.absences.view' && $this->permissions($request)->has('time.absences.create_for_others')) {
            $found = $this->scoped($request, 'time.absences.create_for_others')->whereKey($id)->first();
        }
        abort_unless($found instanceof AbsenceRequest, 404);

        return $found;
    }

    /**
     * Quién actúa sobre la relación: el titular, RRHH o el jefe (que la ve dentro de su alcance).
     * Fuera de todo alcance, 404.
     */
    private function role(Request $request, string $employmentId, string $permission): string
    {
        $owner = DB::table('employments')->where('id', $employmentId)->value('person_id');
        if ($owner !== null && $owner === $this->viewerPerson($request)) {
            return 'employee';
        }
        $hr = $this->permissions($request)->has('time.absences.create_for_others');
        $check = $hr ? 'time.absences.create_for_others' : $permission;
        $query = DB::table('employments')->where('employments.id', $employmentId)->whereNull('deleted_at');
        $this->scope->applyEmployment($query, $request, $check, 'employments.id');
        if (! $query->exists()) {
            throw new AyniException('not_found', 404, (string) trans('absences.requests.employment_unknown'));
        }

        return $hr ? 'hr' : 'manager';
    }

    private function assertOwnerOrHr(Request $request, AbsenceRequest $absence): void
    {
        if (! $this->isOwner($request, $absence) && ! $this->permissions($request)->has('time.absences.create_for_others')) {
            throw new AyniException('forbidden', 403, (string) trans('absences.requests.cancel_forbidden'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Request $request, AbsenceRequest $absence, bool $detail = false): array
    {
        $permissions = $this->permissions($request);
        $row = $this->absences->present($absence, $detail);
        $owner = $this->isOwner($request, $absence);
        $personId = is_string($row['person_id'] ?? null) ? $row['person_id'] : null;
        if ($owner) {
            // El titular ve su propio motivo y sustento sin pasar por el registro de accesos.
            $out = $row;
        } else {
            $out = $this->sensitive->present('Absence', $row, $permissions, 'api', $personId);
            if (($row['is_confidential'] ?? false) === true && ! array_key_exists('medical_reason', $out)) {
                // RN-AUS-05 · El jefe ve «Ausencia justificada» y fechas, nunca el tipo de salud ni el sustento.
                $out['absence_type_name'] = (string) trans('absences.requests.justified');
                $out['absence_type_category'] = null;
                $out['absence_type_id'] = null;
            }
        }
        $out['allowed_actions'] = $this->absences->allowedActions($absence, fn (string $p): bool => $permissions->has($p), $owner);

        return $out;
    }

    private function isOwner(Request $request, AbsenceRequest $absence): bool
    {
        $viewer = $this->viewerPerson($request);

        return $viewer !== null && DB::table('employments')->where('id', $absence->employment_id)->value('person_id') === $viewer;
    }

    private function viewerPerson(Request $request): ?string
    {
        $user = $request->user();
        $person = $user === null ? null : DB::table('users')->where('id', $user->getAuthIdentifier())->value('person_id');

        return $person === null ? null : (string) $person;
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
