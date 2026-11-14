<?php

namespace App\Modules\Absences\Http\Controllers;

use App\Modules\Absences\Application\MedicalCertificates;
use App\Modules\Absences\Application\MedicalLeaveSplit;
use App\Modules\Absences\Application\SubsidyCaseService;
use App\Modules\Absences\Domain\SubsidyCaseStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceSubsidyCase;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUS-3 · Descansos médicos y subsidios: certificado médico (CITT o particular), acumulado del año por
 * trabajador con el umbral a cargo del empleador y seguimiento del reembolso del subsidio.
 *
 * Todo pasa por time.absences.manage_subsidy o time.absences.medical_certificate.view (datos de salud), con
 * el alcance aplicado en la consulta (regla 6): fuera del alcance, 404.
 */
final class AbsenceSubsidyController
{
    private const STATUSES = ['pending_filing', 'filed', 'observed', 'approved', 'reimbursed', 'rejected', 'cancelled'];

    public function __construct(
        private readonly SubsidyCaseService $cases,
        private readonly MedicalLeaveSplit $split,
        private readonly MedicalCertificates $certificates,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'filter.overdue' => ['sometimes', 'in:true,false,1,0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('absences.subsidies.invalid_filter'));
        }
        $query = AbsenceSubsidyCase::query();
        $this->scope->applyEmployment($query, $request, 'time.absences.manage_subsidy', 'absence_subsidy_cases.employment_id');
        $query->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when(isset($filter['employment_id']), fn ($q) => $q->where('employment_id', $filter['employment_id']))
            ->when(isset($filter['year']), fn ($q) => $q->where('year', (int) $filter['year']))
            ->when(in_array($filter['overdue'] ?? null, ['true', '1'], true), fn ($q) => $q->where('status', 'pending_filing')->where('filing_due_on', '<', now()->toDateString()));
        $total = (clone $query)->count();
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $rows = $query->orderByRaw("CASE WHEN status IN ('pending_filing','observed') THEN 0 ELSE 1 END")
            ->orderBy('filing_due_on')->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $rows->map(fn (AbsenceSubsidyCase $c): array => $this->cases->present($c))->values()->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->cases->present($this->findCase($request, $id))]);
    }

    public function file(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubsidyCaseStateMachine::FILE, [
            'filed_on' => ['required', 'date_format:Y-m-d'],
            'filing_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount_requested' => ['sometimes', 'nullable', 'array'],
            'amount_requested.amount' => ['required_with:amount_requested', 'string', 'max:20'],
            'amount_requested.currency' => ['sometimes', 'string', 'size:3'],
        ]);
    }

    public function observe(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubsidyCaseStateMachine::OBSERVE, ['reason' => ['required', 'string', 'max:1000']]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubsidyCaseStateMachine::APPROVE, [
            'amount_approved' => ['sometimes', 'nullable', 'array'],
            'amount_approved.amount' => ['required_with:amount_approved', 'string', 'max:20'],
            'amount_approved.currency' => ['sometimes', 'string', 'size:3'],
        ]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubsidyCaseStateMachine::REJECT, ['reason' => ['required', 'string', 'max:1000']]);
    }

    public function reimburse(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, SubsidyCaseStateMachine::REIMBURSE, [
            'reimbursed_on' => ['required', 'date_format:Y-m-d'],
            'amount_reimbursed' => ['required', 'array'],
            'amount_reimbursed.amount' => ['required', 'string', 'max:20'],
            'amount_reimbursed.currency' => ['sometimes', 'string', 'size:3'],
        ]);
    }

    /** Acumulado del año de una relación: umbral, días del empleador y de subsidio, y cada descanso. */
    public function summary(Request $request, string $employment_id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $employment_id) === 1, 404);
        $input = $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']]);
        $query = DB::table('employments')->where('employments.id', $employment_id)->whereNull('deleted_at');
        $this->scope->applyEmployment($query, $request, 'time.absences.manage_subsidy', 'employments.id');
        abort_unless($query->exists(), 404);
        $year = (int) ($input['year'] ?? now()->year);

        return response()->json(['data' => $this->split->summary($employment_id, $year)]);
    }

    public function certificate(Request $request, string $id): JsonResponse
    {
        $absence = $this->findAbsence($request, $id);

        return response()->json(['data' => $this->presentCertificate($request, $absence)]);
    }

    public function saveCertificate(Request $request, string $id): JsonResponse
    {
        // Registrar el certificado exige además ver datos de salud: quien no puede leer el diagnóstico no lo escribe.
        if (! $this->permissions($request)->has('time.absences.medical_certificate.view')) {
            throw new AyniException('forbidden', 403, (string) trans('absences.certificates.forbidden'));
        }
        $absence = $this->findAbsence($request, $id);
        /** @var array{kind?: string|null, certificate_number?: string|null, issuer?: string|null, diagnosis?: string|null, issued_on?: string|null, days_granted?: int|null, is_work_related?: bool|null, validated?: bool|null} $input */
        $input = $request->validate([
            'kind' => ['sometimes', 'in:'.implode(',', MedicalCertificates::KINDS)],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'issuer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'days_granted' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'is_work_related' => ['sometimes', 'boolean'],
            'validated' => ['sometimes', 'boolean'],
        ]);
        $this->certificates->save($absence, $input, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->presentCertificate($request, $absence->refresh())]);
    }

    // ------------------------------------------------------------------ Internos

    /**
     * @param  array<string, mixed>  $rules
     */
    private function act(Request $request, string $id, string $action, array $rules): JsonResponse
    {
        $case = $this->findCase($request, $id);
        /** @var array<string, mixed> $input */
        $input = $request->validate($rules);

        return response()->json(['data' => $this->cases->present($this->cases->act($case, $action, $input))]);
    }

    private function findCase(Request $request, string $id): AbsenceSubsidyCase
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = AbsenceSubsidyCase::query()->whereKey($id);
        $this->scope->applyEmployment($query, $request, 'time.absences.manage_subsidy', 'absence_subsidy_cases.employment_id');
        $case = $query->first();
        abort_unless($case instanceof AbsenceSubsidyCase, 404);

        return $case;
    }

    /** Ausencia en el alcance de quien ve datos médicos (o, al registrar, de quien registra por otros). */
    private function findAbsence(Request $request, string $id): AbsenceRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $permission = $request->isMethod('PUT') ? 'time.absences.create_for_others' : 'time.absences.medical_certificate.view';
        $query = AbsenceRequest::query()->whereKey($id);
        $this->scope->applyEmployment($query, $request, $permission, 'absence_requests.employment_id');
        $absence = $query->first();
        abort_unless($absence instanceof AbsenceRequest, 404);

        return $absence;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCertificate(Request $request, AbsenceRequest $absence): array
    {
        $row = $this->certificates->present($absence, $this->certificates->find($absence));
        $person = DB::table('employments')->where('id', $absence->employment_id)->value('person_id');
        if ($row['id'] === null) {
            // Sin certificado no hay dato sensible que leer: no se registra acceso.
            unset($row['certificate_number'], $row['diagnosis']);

            return $row + ['certificate_number' => null, 'diagnosis' => null];
        }

        return $this->sensitive->present('AbsenceMedicalCertificate', $row, $this->permissions($request), 'api', $person === null ? null : (string) $person);
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
