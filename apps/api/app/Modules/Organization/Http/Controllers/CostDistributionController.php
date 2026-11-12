<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\CostDistributionService;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * ORG-4 · RN-ORG-06 · Distribución de una relación laboral entre centros de costo, con vigencia.
 * La relación debe estar dentro del alcance del usuario (SEG-10); fuera de alcance responde 404.
 */
final class CostDistributionController
{
    /** Tope técnico de líneas por distribución. */
    private const MAX_LINES = 20;

    public function __construct(
        private readonly CostDistributionService $distributions,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->employment($request, $employmentId, 'people.assignments.view');
        $v = Validator::make($request->query(), ['as_of' => ['sometimes', 'date_format:Y-m-d']]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }
        $asOf = $request->query('as_of');
        if (is_string($asOf)) {
            $current = $this->distributions->presentAt((string) $employment->id, $asOf);

            return response()->json(['data' => $current === null ? [] : [$current], 'meta' => ['as_of' => $asOf]]);
        }

        return response()->json(['data' => $this->distributions->history((string) $employment->id)]);
    }

    public function store(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->employment($request, $employmentId, 'people.assignments.create');
        $data = $request->validate([
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.cost_center_id' => ['required', 'uuid'],
            'lines.*.percentage' => ['required'],
        ]);
        /** @var array<int, array{cost_center_id: string, percentage: mixed}> $lines */
        $lines = array_values($data['lines']);
        $distribution = $this->distributions->record($employment, (string) $data['valid_from'], $lines, isset($data['reason']) ? (string) $data['reason'] : null);

        return response()->json(['data' => $this->distributions->present($distribution)], 201);
    }

    private function employment(Request $request, string $id, string $permission): \stdClass
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = DB::table('employments')->where('employments.id', $id)->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        $row = $query->first(['employments.id', 'employments.tenant_id', 'employments.legal_entity_id', 'employments.hire_date', 'employments.status']);
        abort_unless($row instanceof \stdClass, 404);

        return $row;
    }
}
