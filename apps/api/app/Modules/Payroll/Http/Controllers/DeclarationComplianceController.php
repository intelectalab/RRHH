<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\Declarations\DeclarationLog;
use App\Modules\Payroll\Application\Declarations\ExternalIncomeDeclarations;
use App\Modules\Payroll\Application\Declarations\FifthCategoryRefunds;
use App\Modules\Payroll\Application\Declarations\RegistryProfiles;
use App\Modules\Payroll\Application\Declarations\StatutoryFilings;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\SelfService\OwnEmployments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * DEC-7 · Bitácora de declaraciones · DEC-8 · Ficha del registro laboral y validación previa · DEC-9 · Declaración
 * jurada de rentas de otros empleadores (también desde el portal) · DEC-11 · Devoluciones de quinta (ME-49).
 * Alcance por empresa o por relación laboral: lo que está fuera responde 404 (regla 1).
 */
final class DeclarationComplianceController extends Controller
{
    public function __construct(
        private readonly DeclarationLog $log,
        private readonly StatutoryFilings $filings,
        private readonly RegistryProfiles $profiles,
        private readonly ExternalIncomeDeclarations $income,
        private readonly FifthCategoryRefunds $refunds,
        private readonly OwnEmployments $own,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    // ---- DEC-7 · Bitácora ----

    public function log(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'regex:/^\d{4}(0[1-9]|1[0-2])$/'], 'to' => ['sometimes', 'nullable', 'regex:/^\d{4}(0[1-9]|1[0-2])$/'],
        ]);
        $entities = $this->entities($request, 'payroll.declaration_files.view');
        $entity = $data['legal_entity_id'] ?? null;
        if ($entity !== null) {
            $this->entity($request, (string) $entity, 'payroll.declaration_files.view');
        }
        $last = CarbonImmutable::today()->subMonthNoOverflow();
        $to = (string) ($data['to'] ?? $last->format('Ym'));
        $from = (string) ($data['from'] ?? CarbonImmutable::createFromFormat('!Ym', $to)?->subMonthsNoOverflow(5)->format('Ym'));
        if ($from > $to) {
            throw new AyniException('validation_failed', 422, null, [['field' => 'from', 'rule' => 'before_or_equal', 'message' => 'from > to']]);
        }
        $built = $this->log->build($entities, $entity === null ? null : (string) $entity, $from, $to);

        return response()->json(['data' => $built['data'], 'meta' => ['from' => $from, 'to' => $to, 'summary' => $built['summary'],
            'total' => count($built['data']), 'page' => 1, 'per_page' => max(1, count($built['data'])), 'next_cursor' => null]]);
    }

    public function registerPayment(Request $request, string $id): JsonResponse
    {
        $this->filing($request, $id, 'payroll.declaration_files.mark_submitted');
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:120'], 'paid_at' => ['sometimes', 'nullable', 'date'], 'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->log->registerPayment($id, (string) $data['payment_reference'], $data['paid_at'] ?? null,
            $this->document($data['document_id'] ?? null), $this->userId($request))]);
    }

    public function updateFiling(Request $request, string $id): JsonResponse
    {
        $this->filing($request, $id, 'payroll.declaration_files.mark_submitted');
        if ($request->has('status')) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declaration_log.status_by_actions'),
                [['field' => 'status', 'rule' => 'prohibited', 'message' => (string) trans('payroll.declaration_log.status_by_actions')]]);
        }
        $data = $request->validate([
            'responsible_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'submission_document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        if (array_key_exists('submission_document_id', $data)) {
            $data['submission_document_id'] = $this->document($data['submission_document_id']);
        }

        return response()->json(['data' => $this->log->update($id, $data)]);
    }

    // ---- DEC-8 · Ficha del registro laboral ----

    public function profiles(Request $request, string $employmentId): JsonResponse
    {
        $this->employment($request, $employmentId, 'payroll.registry_profiles.view');
        $rows = $this->sensitive->presentMany('RegistryProfile', $this->profiles->list($employmentId), $this->permissions($request));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function profile(Request $request, string $employmentId, string $id): JsonResponse
    {
        $this->employment($request, $employmentId, 'payroll.registry_profiles.view');

        return response()->json(['data' => $this->sensitive->present('RegistryProfile', $this->profiles->show($employmentId, $id), $this->permissions($request))]);
    }

    public function saveProfile(Request $request, string $employmentId): JsonResponse
    {
        $this->employment($request, $employmentId, 'payroll.registry_profiles.create');
        $code = ['sometimes', 'nullable', 'string', 'max:10'];
        $rules = ['valid_from' => ['sometimes', 'date_format:Y-m-d'], 'initial_basic_remuneration' => ['sometimes', 'numeric', 'gt:0'],
            'sctr_health' => ['sometimes', Rule::in(['none', 'essalud', 'eps'])], 'sctr_pension' => ['sometimes', Rule::in(['none', 'onp', 'insurer'])],
            'agrarian_benefit_payment' => ['sometimes', 'nullable', Rule::in(['prorated_daily', 'semiannual'])]];
        foreach (RegistryProfiles::fields() as $f) {
            $rules[$f] ??= in_array($f, RegistryProfiles::BOOLEANS, true) ? ['sometimes', 'boolean'] : $code;
        }
        $in = $request->validate($rules);
        if (is_array($request->input('initial_basic_remuneration'))) {
            $in['initial_basic_remuneration'] = (string) ($request->input('initial_basic_remuneration.amount') ?? '');
        }
        // Datos sensibles: solo los envía quien puede verlos (regla 6).
        $p = $this->permissions($request);
        foreach ($this->sensitive->mapFor('RegistryProfile') as $field => [$permission]) {
            if (array_key_exists($field, $in) && ! $p->has($permission)) {
                throw new AyniException('forbidden', 403);
            }
        }
        $row = $this->profiles->save($employmentId, $in, $this->userId($request));

        return response()->json(['data' => $this->sensitive->present('RegistryProfile', $row, $p)], 201);
    }

    public function validateProfile(Request $request, string $employmentId, string $id): JsonResponse
    {
        $this->employment($request, $employmentId, 'payroll.registry_profiles.validate');

        return response()->json(['data' => $this->sensitive->present('RegistryProfile', $this->profiles->validate($employmentId, $id, $this->userId($request)), $this->permissions($request))]);
    }

    public function registryValidation(Request $request, string $employmentId): JsonResponse
    {
        $this->employment($request, $employmentId, 'payroll.registry_profiles.view');

        return response()->json(['data' => $this->profiles->check($employmentId)]);
    }

    // ---- DEC-9 · Rentas de otros empleadores ----

    public function incomeIndex(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $employment = self::uuidOrNull($filter['employment_id'] ?? null);
        $year = isset($filter['fiscal_year']) && is_numeric($filter['fiscal_year']) ? (int) $filter['fiscal_year'] : null;
        $rows = $this->income->list($this->employments($request, 'payroll.external_income_declarations.view'), [
            'employment_id' => $employment, 'fiscal_year' => $year, 'status' => self::str($filter['status'] ?? null), 'kind' => self::str($filter['kind'] ?? null),
        ]);
        $rows = $this->sensitive->presentMany('ExternalIncomeDeclaration', $rows, $this->permissions($request));
        $meta = ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null];
        if ($employment !== null) {
            $meta['warning'] = $this->income->warning($employment, $year ?? CarbonImmutable::today()->year);
        }

        return response()->json(['data' => $rows, 'meta' => $meta]);
    }

    public function incomeShow(Request $request, string $id): JsonResponse
    {
        $this->incomeLocate($request, $id, 'payroll.external_income_declarations.view');

        return response()->json(['data' => $this->sensitive->present('ExternalIncomeDeclaration', $this->income->show($id), $this->permissions($request))]);
    }

    public function incomeStore(Request $request): JsonResponse
    {
        $in = $this->incomeInput($request);
        $this->employment($request, (string) $in['employment_id'], 'payroll.external_income_declarations.create');
        $row = $this->income->create($in, $this->userId($request));

        return response()->json(['data' => $this->sensitive->present('ExternalIncomeDeclaration', $row, $this->permissions($request))], 201);
    }

    public function incomeValidate(Request $request, string $id): JsonResponse
    {
        $this->incomeLocate($request, $id, 'payroll.external_income_declarations.validate');

        return response()->json(['data' => $this->sensitive->present('ExternalIncomeDeclaration', $this->income->validate($id, $this->userId($request)), $this->permissions($request))]);
    }

    public function incomeReject(Request $request, string $id): JsonResponse
    {
        $this->incomeLocate($request, $id, 'payroll.external_income_declarations.reject');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->sensitive->present('ExternalIncomeDeclaration', $this->income->reject($id, (string) $data['reason'], $this->userId($request)), $this->permissions($request))]);
    }

    public function incomeSupersede(Request $request, string $id): JsonResponse
    {
        $this->incomeLocate($request, $id, 'payroll.external_income_declarations.supersede');

        return response()->json(['data' => $this->sensitive->present('ExternalIncomeDeclaration', $this->income->supersede($id, $this->userId($request)), $this->permissions($request))]);
    }

    /** Portal · Mis declaraciones (solo las relaciones propias, RN-ESS-01). */
    public function myIncome(Request $request): JsonResponse
    {
        $ids = array_column($this->own->list((string) $this->userId($request)), 'id');
        $year = (int) $request->query('fiscal_year', (string) CarbonImmutable::today()->year);
        $rows = $this->income->list($ids, ['fiscal_year' => $year]);
        $warning = null;
        foreach ($ids as $e) {
            $warning ??= $this->income->warning($e, $year);
        }

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null, 'warning' => $warning]]);
    }

    public function myIncomeStore(Request $request): JsonResponse
    {
        $own = $this->own->pick((string) $this->userId($request), $request->input('employment_id'));
        if ($own === null || $own['read_only']) {
            throw new AyniException('not_found', 404);
        }
        $request->merge(['employment_id' => $own['id']]);
        $in = $this->incomeInput($request);

        return response()->json(['data' => $this->income->create($in, $this->userId($request))], 201);
    }

    // ---- DEC-11 · Devoluciones de quinta ----

    public function refundIndex(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $rows = $this->refunds->list($this->employments($request, 'payroll.fifth_category_refunds.view'), [
            'employment_id' => self::uuidOrNull($filter['employment_id'] ?? null),
            'fiscal_year' => isset($filter['fiscal_year']) && is_numeric($filter['fiscal_year']) ? (int) $filter['fiscal_year'] : null,
            'status' => self::str($filter['status'] ?? null), 'origin' => self::str($filter['origin'] ?? null),
        ]);
        $rows = $this->sensitive->presentMany('FifthCategoryRefund', $rows, $this->permissions($request));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function refundShow(Request $request, string $id): JsonResponse
    {
        $this->refundLocate($request, $id, 'payroll.fifth_category_refunds.view');

        return $this->refundJson($request, $this->refunds->show($id));
    }

    public function refundStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'], 'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'origin' => ['required', Rule::in(FifthCategoryRefunds::ORIGINS)],
            'amount' => ['required_if:origin,worker_declaration', 'nullable', 'numeric', 'gt:0'],
            'worker_declaration_document_id' => ['required_if:origin,worker_declaration', 'nullable', 'uuid'],
        ]);
        $this->employment($request, (string) $data['employment_id'], 'payroll.fifth_category_refunds.create');
        $data['worker_declaration_document_id'] = $this->document($data['worker_declaration_document_id'] ?? null);
        $data['fiscal_year'] = (int) $data['fiscal_year'];
        $data['amount'] = isset($data['amount']) ? (string) $data['amount'] : null;

        return $this->refundJson($request, $this->refunds->create($data, $this->userId($request)), 201);
    }

    public function refundRefund(Request $request, string $id): JsonResponse
    {
        $this->refundLocate($request, $id, 'payroll.fifth_category_refunds.refund');
        $data = $request->validate(['refunded_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'payroll_run_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('payroll_runs', 'id')]]);

        return $this->refundJson($request, $this->refunds->refund($id, $data['refunded_on'] ?? null, $data['payroll_run_id'] ?? null, $this->userId($request)));
    }

    public function refundCompensate(Request $request, string $id): JsonResponse
    {
        $this->refundLocate($request, $id, 'payroll.fifth_category_refunds.compensate');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'compensation_period' => ['required', 'regex:/^\d{4}(0[1-9]|1[0-2])$/']]);

        return $this->refundJson($request, $this->refunds->compensate($id, (string) $data['amount'], (string) $data['compensation_period'], $this->userId($request)));
    }

    public function refundRequestAuthority(Request $request, string $id): JsonResponse
    {
        $this->refundLocate($request, $id, 'payroll.fifth_category_refunds.request_authority_refund');
        $data = $request->validate(['authority_refund_reference' => ['required', 'string', 'max:120']]);

        return $this->refundJson($request, $this->refunds->requestAuthority($id, (string) $data['authority_refund_reference'], $this->userId($request)));
    }

    public function refundClose(Request $request, string $id): JsonResponse
    {
        $this->refundLocate($request, $id, 'payroll.fifth_category_refunds.close');

        return $this->refundJson($request, $this->refunds->close($id, $this->userId($request)));
    }

    // ---- Ayudantes ----

    /** @param array<string, mixed> $row */
    private function refundJson(Request $request, array $row, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->sensitive->present('FifthCategoryRefund', $row, $this->permissions($request))], $status);
    }

    /** @return array<string, mixed> */
    private function incomeInput(Request $request): array
    {
        $money = fn (string $k): mixed => is_array($request->input($k)) ? $request->input($k.'.amount') : $request->input($k);
        $request->merge(array_filter(['gross_income' => $money('gross_income'), 'exempt_income' => $money('exempt_income'), 'tax_withheld' => $money('tax_withheld')],
            fn ($v) => $v !== null));

        return $request->validate([
            'employment_id' => ['required', 'uuid'], 'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'kind' => ['sometimes', Rule::in(['previous_employer', 'concurrent_employer'])],
            'employer_tax_id' => ['required', 'regex:/^\d{11}$/'], 'employer_name' => ['required', 'string', 'max:200'],
            'period_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'period_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'gross_income' => ['required', 'numeric', 'gte:0'], 'exempt_income' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'tax_withheld' => ['required', 'numeric', 'gte:0'], 'this_employer_withholds' => ['sometimes', 'nullable', 'boolean'],
            'monthly_detail' => ['sometimes', 'nullable', 'array'], 'monthly_detail.*.withheld' => ['sometimes', 'numeric', 'gte:0'],
            'document_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('documents', 'id')->whereNull('deleted_at')],
        ]);
    }

    private function filing(Request $request, string $id, string $permission): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $entity = $this->filings->entityOf($id);
        $entities = $this->entities($request, $permission);
        if ($entity === null || ($entities !== null && ! in_array($entity, $entities, true))) {
            throw new AyniException('not_found', 404);
        }
    }

    private function incomeLocate(Request $request, string $id, string $permission): void
    {
        $e = $this->income->employmentOf($id);
        if ($e === null) {
            throw new AyniException('not_found', 404);
        }
        $this->employment($request, $e, $permission);
    }

    private function refundLocate(Request $request, string $id, string $permission): void
    {
        $e = $this->refunds->employmentOf($id);
        if ($e === null) {
            throw new AyniException('not_found', 404);
        }
        $this->employment($request, $e, $permission);
    }

    private function entity(Request $request, string $id, string $permission): void
    {
        $exists = DB::table('legal_entities')->where('id', $id)->whereNull('deleted_at')->exists();
        $entities = $this->entities($request, $permission);
        if (! $exists || ($entities !== null && ! in_array($id, $entities, true))) {
            throw new AyniException('not_found', 404);
        }
    }

    private function employment(Request $request, string $id, string $permission): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new AyniException('not_found', 404);
        }
        $q = DB::table('employments')->where('id', $id)->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, $permission, 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    /** @return list<string> */
    private function employments(Request $request, string $permission): array
    {
        $q = DB::table('employments')->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, $permission, 'id');

        return array_values(array_map('strval', $q->pluck('id')->all()));
    }

    private function document(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (! DB::table('documents')->where('id', $id)->whereNull('deleted_at')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.document_missing'),
                [['field' => 'document_id', 'rule' => 'exists', 'message' => (string) trans('payroll.declarations.document_missing')]]);
        }

        return $id;
    }

    /** @return list<string>|null */
    private function entities(Request $request, string $permission): ?array
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return [];
        }
        $ids = $this->scope->legalEntityIds($userId, $permission);

        return $ids === null ? null : array_values(array_map('strval', $ids));
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }

    private static function uuidOrNull(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 ? $v : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
