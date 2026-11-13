<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\SocialSecurityService;
use App\Modules\CoreHR\Http\Controllers\Concerns\ResolvesEmployment;
use App\Modules\CoreHR\Infrastructure\Models\HealthRegime;
use App\Modules\CoreHR\Infrastructure\Models\InsuranceCoverage;
use App\Modules\CoreHR\Infrastructure\Models\PensionAffiliation;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * COL-7 · Régimen pensionario, salud y seguros de una relación laboral (con el alcance del usuario).
 * Leer exige people.employments.view y registrar cambios people.employments.update; el código de afiliado
 * (CUSPP) y los beneficiarios de Vida Ley son datos personales (people.people.personal_data.view).
 */
final class SocialSecurityController
{
    use ResolvesEmployment;

    public function __construct(
        private readonly SocialSecurityService $service,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function pensionIndex(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');
        $rows = array_map(fn (PensionAffiliation $p) => $this->service->presentPension($p), $this->service->pensionHistory($employment));

        return response()->json([
            'data' => $this->sensitive->presentMany('PensionAffiliation', $rows, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function pensionStore(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.update');
        $input = $request->validate([
            'pension_system' => ['required', Rule::in(SocialSecurityService::PENSION_SYSTEMS)],
            'pension_fund_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'commission_scheme' => ['sometimes', 'nullable', Rule::in(SocialSecurityService::COMMISSION_SCHEMES)],
            'affiliate_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'affiliated_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (! empty($input['affiliate_code']) && ! $this->permissions($request)->has('people.people.personal_data.edit')) {
            throw ValidationException::withMessages(['affiliate_code' => [(string) trans('people.personal_data_forbidden')]]);
        }
        $row = $this->service->changePension($employment, $input);

        return response()->json(['data' => $this->sensitive->present('PensionAffiliation', $this->service->presentPension($row), $this->permissions($request), 'api', $employment->person_id)], 201);
    }

    public function healthIndex(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');

        return response()->json([
            'data' => array_map(fn (HealthRegime $h) => $this->service->presentHealth($h), $this->service->healthHistory($employment)),
            'meta' => ['total' => count($this->service->healthHistory($employment))],
        ]);
    }

    public function healthStore(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.update');
        $input = $request->validate([
            'health_scheme' => ['required', Rule::in(SocialSecurityService::HEALTH_SCHEMES)],
            'health_provider_id' => ['sometimes', 'nullable', 'uuid'],
            'health_plan_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->service->presentHealth($this->service->changeHealth($employment, $input))], 201);
    }

    public function coverageIndex(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');
        $rows = array_map(fn (InsuranceCoverage $c) => $this->service->presentCoverage($c), $this->service->coverages($employment));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function coverageStore(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.update');
        $input = $request->validate([
            'insurance_policy_id' => ['required', 'uuid'],
            'covered_from' => ['required', 'date_format:Y-m-d'],
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        return response()->json(['data' => $this->service->presentCoverage($this->service->enroll($employment, $input))], 201);
    }

    public function coverageEnd(Request $request, string $employment_id, string $id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.update');
        self::uuidOr404($id);
        $coverage = InsuranceCoverage::query()->whereKey($id)->where('employment_id', $employment->id)->first();
        abort_unless($coverage instanceof InsuranceCoverage, 404);
        $input = $request->validate(['covered_to' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->service->presentCoverage($this->service->endCoverage($employment, $coverage, (string) $input['covered_to']))]);
    }

    public function declarationIndex(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');
        $rows = $this->service->declarations($employment);

        return response()->json([
            'data' => $this->sensitive->presentMany('LifeInsuranceDeclaration', $rows, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    /**
     * Multipart: beneficiaries (JSON con nombre, documento, vínculo y porcentaje), signed_on y file (la
     * declaración firmada por el trabajador).
     */
    public function declarationStore(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.update');
        if (! $this->permissions($request)->has('people.people.personal_data.edit')) {
            throw ValidationException::withMessages(['beneficiaries' => [(string) trans('people.personal_data_forbidden')]]);
        }
        $raw = $request->input('beneficiaries');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $request->merge(['beneficiaries' => is_array($decoded) ? $decoded : null]);
        $input = $request->validate([
            'beneficiaries' => ['required', 'array', 'min:1', 'max:20'],
            'beneficiaries.*.full_name' => ['required', 'string', 'min:3', 'max:200'],
            'beneficiaries.*.document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'beneficiaries.*.relationship' => ['required', Rule::in(['spouse', 'partner', 'child', 'parent', 'sibling', 'other'])],
            'beneficiaries.*.percentage' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_size_bytes', 26214400) / 1024)],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        /** @var list<array<string, mixed>> $beneficiaries */
        $beneficiaries = array_values($input['beneficiaries']);
        $id = $this->service->declare($employment, $beneficiaries, (string) $input['signed_on'], (string) $file->getContent(),
            $file->getClientOriginalName(), (string) $file->getMimeType(), $this->userId($request));
        $row = collect($this->service->declarations($employment))->firstWhere('id', $id);

        return response()->json(['data' => $this->sensitive->present('LifeInsuranceDeclaration', (array) $row, $this->permissions($request), 'api', $employment->person_id)], 201);
    }

    public function declarationFile(Request $request, string $employment_id, string $id): Response
    {
        $employment = $this->employment($request, $employment_id, 'people.employments.view');
        self::uuidOr404($id);
        abort_unless($this->permissions($request)->has('people.people.personal_data.view'), 403);
        $file = $this->service->declarationFile($employment, $id, $this->userId($request));
        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
