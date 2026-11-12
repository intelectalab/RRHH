<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\OrgChart;
use App\Modules\Organization\Application\PeopleDirectory;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ORG-6 · ORG-7 · Organigrama y directorio de personas (permiso people.directory.view).
 * RN-ORG-08: solo nombre, puesto, unidad, sede, empresa y el contacto laboral publicado.
 */
final class DirectoryController
{
    private const FILTERS = ['q', 'org_unit_id', 'site_id', 'job_profile_id', 'page', 'per_page'];

    public function __construct(
        private readonly PeopleDirectory $directory,
        private readonly OrgChart $chart,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'job_profile_id' => ['sometimes', 'nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));
        $str = fn (string $k): ?string => is_string($request->query($k)) && $request->query($k) !== '' ? strtolower((string) $request->query($k)) : null;

        $result = $this->directory->page($request, [
            'q' => is_string($request->query('q')) ? (string) $request->query('q') : null,
            'org_unit_id' => $str('org_unit_id'),
            'site_id' => $str('site_id'),
            'job_profile_id' => $str('job_profile_id'),
        ], $page, $perPage);

        return response()->json([
            'data' => $result['data'],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total'], 'contact_fields' => $this->directory->contactFields()],
        ]);
    }

    public function show(Request $request, string $employmentId): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $employmentId) === 1, 404);
        $card = $this->directory->card($request, strtolower($employmentId));
        abort_if($card === null, 404);

        return response()->json(['data' => $card]);
    }

    public function orgChart(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'view' => ['sometimes', 'in:units,people'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of', 'view']);
        }
        $day = is_string($request->query('as_of')) ? (string) $request->query('as_of') : now()->toDateString();
        $view = $request->query('view') === 'people' ? 'people' : 'units';
        $nodes = $view === 'people' ? $this->chart->people($request, $day) : $this->chart->units($request, $day);

        return response()->json([
            'data' => $nodes,
            'meta' => ['as_of' => $day, 'view' => $view, 'total' => count($nodes), 'contact_fields' => $this->directory->contactFields()],
        ]);
    }
}
