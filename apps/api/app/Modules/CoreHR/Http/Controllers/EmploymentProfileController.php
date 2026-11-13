<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\AssignmentMovements;
use App\Modules\CoreHR\Application\EmploymentProfile;
use App\Modules\CoreHR\Http\Controllers\Concerns\ScopedAccess;
use App\Shared\Audit\ActivityLogger;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * COL-3 · COL-5 · Ficha 360: cabecera (foto, nombre, puesto, estado, antigüedad calculada y las otras
 * relaciones de la persona para cambiar de ficha), datos laborales vigentes con «desde cuándo» y la historia
 * de la asignación, que solo cambia con un movimiento con fecha efectiva.
 */
final class EmploymentProfileController
{
    use ScopedAccess;

    public function __construct(
        private readonly EmploymentProfile $profile,
        private readonly AssignmentMovements $movements,
        private readonly DataScope $scope,
    ) {}

    public function profile(Request $request, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $id, 'people.employments.view');
        app(ActivityLogger::class)->record('record.viewed', 'employment', $employment->id);

        return response()->json(['data' => $this->profile->header(
            $employment,
            $this->visibleEmploymentIds($request, $employment->person_id, 'people.employments.view'),
            $this->viewerPersonId($request),
        )]);
    }

    public function workData(Request $request, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $id, 'people.employments.view');

        return response()->json(['data' => $this->profile->workData($employment, $this->asOf($request))]);
    }

    public function assignments(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.view');
        $rows = $this->movements->history($employment, $this->asOf($request));

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows), 'next_cursor' => null]]);
    }

    public function assignment(Request $request, string $employmentId, string $id): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.view');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $row = $this->movements->find($employment, $id);
        abort_if($row === null, 404);

        return response()->json(['data' => $row]);
    }

    /** COL-5 · Registra un movimiento de la asignación desde valid_from (cierra la vigente el día anterior). */
    public function storeAssignment(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.create');
        $uuid = ['sometimes', 'nullable', 'uuid'];
        $input = $request->validate([
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'kind' => ['sometimes', Rule::in(AssignmentMovements::KINDS)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'job_profile_id' => ['sometimes', 'uuid'],
            'org_unit_id' => ['sometimes', 'uuid'],
            'site_id' => ['sometimes', 'uuid'],
            'position_id' => $uuid,
            'cost_center_id' => $uuid,
            'manager_employment_id' => $uuid,
            'work_modality' => ['sometimes', Rule::in(['onsite', 'remote', 'hybrid'])],
        ]);

        return response()->json(['data' => $this->movements->register($employment, $input, $this->userId($request))], 201);
    }

    private function asOf(Request $request): ?string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null || $asOf === '') {
            return null;
        }
        if (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return $asOf;
    }
}
