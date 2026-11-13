<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\CompensationConcepts;
use App\Modules\CoreHR\Application\CompensationService;
use App\Modules\CoreHR\Http\Controllers\Concerns\ResolvesEmployment;
use App\Modules\CoreHR\Infrastructure\Models\Compensation;
use App\Modules\CoreHR\Infrastructure\Models\CompensationChange;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HIS-3 · Compensación con vigencia de una relación laboral.
 *
 * - La historia se ve con people.compensations.view; los montos solo viajan con el permiso de campo
 *   people.employments.salary.view (sin él se omiten) y cada lectura queda en el registro de accesos.
 * - Pedir un cambio exige people.compensations.create y además el permiso de campo
 *   people.employments.salary.edit (ver y editar son permisos distintos).
 */
final class CompensationController
{
    use ResolvesEmployment;

    public function __construct(
        private readonly CompensationService $compensations,
        private readonly CompensationConcepts $concepts,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.compensations.view');
        $asOf = $this->asOf($request);
        $rows = array_map(fn (Compensation $c) => $this->compensations->presentCompensation($c), $this->compensations->history($employment, $asOf));

        return response()->json([
            'data' => $this->sensitive->presentMany('Compensation', $rows, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function show(Request $request, string $employment_id, string $id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.compensations.view');
        self::uuidOr404($id);
        $row = Compensation::query()->whereKey($id)->where('employment_id', $employment->id)->first();
        abort_unless($row instanceof Compensation, 404);

        return response()->json(['data' => $this->sensitive->present('Compensation', $this->compensations->presentCompensation($row), $this->permissions($request), 'api', $employment->person_id)]);
    }

    public function changes(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.compensations.view');
        $filter = $request->query('filter');
        $status = is_array($filter) ? ($filter['status'] ?? null) : null;
        if ($status !== null && (! is_string($status) || array_diff(explode(',', $status), ['pending_approval', 'applied', 'rejected', 'cancelled']) !== [])) {
            throw new AyniException('invalid_filter', 400, (string) trans('people.compensation.invalid_filter'));
        }
        $rows = array_map(fn (CompensationChange $c) => $this->compensations->presentChange($c), $this->compensations->changes($employment, $status));

        return response()->json([
            'data' => $this->sensitive->presentMany('CompensationChange', $rows, $this->permissions($request), 'api', array_map(fn () => $employment->person_id, $rows)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function showChange(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $change] = $this->findChange($request, $employment_id, $id, 'people.compensations.view');

        return $this->respond($request, $employment, $change);
    }

    public function store(Request $request, string $employment_id): JsonResponse
    {
        $employment = $this->employment($request, $employment_id, 'people.compensations.create');
        // Editar la compensación es un permiso de campo distinto del de verla (HIS-3).
        if (! $this->permissions($request)->has('people.employments.salary.edit')) {
            throw new AyniException('forbidden', 403, (string) trans('people.salary_forbidden'), [], ['permission' => 'people.employments.salary.edit']);
        }
        $input = $request->validate([
            'base_salary' => ['required', 'array'],
            'base_salary.amount' => ['required', 'numeric', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'base_salary.currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'pay_frequency' => ['sometimes', Rule::in(CompensationService::FREQUENCIES)],
            'salary_basis' => ['sometimes', Rule::in(CompensationService::BASES)],
            'is_integral_salary' => ['sometimes', 'boolean'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(CompensationService::REASONS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'components' => ['sometimes', 'nullable', 'array', 'max:20'],
            'components.*.concept_code' => ['required', 'string', 'max:60'],
            'components.*.amount' => ['required', 'array'],
            'components.*.amount.amount' => ['required', 'numeric', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'components.*.amount.currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ]);
        $result = $this->compensations->request($employment, $input, (string) $this->userId($request));

        return $this->respond($request, $employment, $result['change'], 201, $result['warnings']);
    }

    public function cancel(Request $request, string $employment_id, string $id): JsonResponse
    {
        [$employment, $change] = $this->findChange($request, $employment_id, $id, 'people.compensations.cancel');
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($request, $employment, $this->compensations->cancel($change, (string) $input['reason']));
    }

    /** Conceptos fijos del paquete del país que se pueden asignar como componentes. */
    public function concepts(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country', (string) (DB::table('current_tenant')->value('country_code') ?? 'PE')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new AyniException('invalid_filter', 400, (string) trans('people.compensation.invalid_filter'));
        }

        return response()->json(['data' => $this->concepts->available($country)]);
    }

    /**
     * @return array{0: Employment, 1: CompensationChange}
     */
    private function findChange(Request $request, string $employmentId, string $id, string $permission): array
    {
        $employment = $this->employment($request, $employmentId, $permission);
        self::uuidOr404($id);
        $change = CompensationChange::query()->whereKey($id)->where('employment_id', $employment->id)->first();
        abort_unless($change instanceof CompensationChange, 404);

        return [$employment, $change];
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function respond(Request $request, Employment $employment, CompensationChange $change, int $status = 200, array $warnings = []): JsonResponse
    {
        $data = $this->sensitive->present('CompensationChange', $this->compensations->presentChange($change), $this->permissions($request), 'api', $employment->person_id);

        return response()->json(['data' => $data, 'meta' => ['warnings' => $warnings]], $status);
    }

    private function asOf(Request $request): ?string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null) {
            return null;
        }
        if (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false) {
            throw new AyniException('invalid_filter', 400, (string) trans('people.compensation.invalid_filter'));
        }

        return $asOf;
    }
}
