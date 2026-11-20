<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\Payslips\Payslips;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * BOL-1 a BOL-5 · Boletas de pago para Payroll y RRHH: generación y publicación del periodo, historial, PDF,
 * constancias de entrega, entrega en físico, anulación, explicación del neto y descarga masiva. El alcance se
 * aplica en la consulta; los montos y las líneas viajan solo con payroll.results.view y quedan en el registro
 * de accesos (reglas 6 y 7; RN-BOL-06).
 */
final class PayslipController extends Controller
{
    private const VIEW = 'payroll.payslips.view';

    public function __construct(
        private readonly Payslips $payslips,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = (array) $request->query('filter', []);
        $q = DB::table('payslips AS s')->select('s.id', 's.employment_id');
        $this->scope->applyEmployment($q, $request, self::VIEW, 's.employment_id');
        foreach (['payroll_period_id', 'employment_id', 'legal_entity_id', 'status'] as $k) {
            if (isset($f[$k]) && is_string($f[$k]) && $f[$k] !== '') {
                if ($k !== 'status' && preg_match('/^[0-9a-f-]{36}$/i', $f[$k]) !== 1) {
                    throw new AyniException('validation_failed', 422, null, [['field' => 'filter['.$k.']', 'rule' => 'uuid', 'message' => 'uuid']]);
                }
                $q->where('s.'.$k, $f[$k]);
            }
        }
        if (in_array((string) ($f['pending_acknowledgement'] ?? ''), ['1', 'true'], true)) {
            $q->where('s.requires_acknowledgement', true)->whereIn('s.status', ['published', 'viewed']);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $q)->count();
        $ids = $q->orderByDesc('s.created_at')->orderBy('s.number')->forPage($page, $perPage)->pluck('s.id');
        $rows = $ids->map(fn ($id) => $this->payslips->present((string) $id, false))->values()->all();
        $data = $this->sensitive->presentMany('Payslip', $rows, $this->permissions($request), 'api', array_map(fn ($r) => $r['person_id'], $rows));

        return response()->json(['data' => array_map(fn ($r) => $this->strip($r), $data), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $this->payslips->publish($this->find($request, $id, 'payroll.payslips.publish'), $this->userId($request));

        return $this->respond($request, $id);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'payroll.payslips.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->payslips->void($id, (string) $data['reason'], $this->userId($request));

        return $this->respond($request, $id);
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $this->period($request, $id, 'payroll.payslips.publish');
        $data = $request->validate(['publish' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->payslips->generate($id, $this->userId($request), isset($data['publish']) ? (bool) $data['publish'] : null)]);
    }

    public function publishPeriod(Request $request, string $id): JsonResponse
    {
        $this->period($request, $id, 'payroll.payslips.publish');

        return response()->json(['data' => $this->payslips->publishPeriod($id, $this->userId($request))]);
    }

    public function summary(Request $request, string $id): JsonResponse
    {
        $this->period($request, $id, self::VIEW);
        $q = DB::table('employments')->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, self::VIEW, 'employments.id');
        $all = $this->scope->employmentScope((string) $this->userId($request), self::VIEW) === null;

        return response()->json(['data' => $this->payslips->summary($id, $all ? null : array_values($q->pluck('id')->map(fn ($v): string => (string) $v)->all()))]);
    }

    public function file(Request $request, string $id): Response
    {
        $this->find($request, $id);
        // RN-BOL-06 · Quien no es el titular necesita además ver resultados de planilla; el acceso queda registrado.
        $this->requireResults($request);
        $f = $this->payslips->file($id, $this->userId($request));
        $this->payslips->logAccess($id, $this->userId($request), 'download', $request->ip());

        return response($f['contents'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="'.$f['name'].'"']);
    }

    public function explanation(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id);
        $this->requireResults($request);
        $this->payslips->logAccess($id, $this->userId($request), 'view', $request->ip());

        return response()->json(['data' => $this->payslips->explanation($id)]);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id);

        return response()->json(['data' => $this->payslips->deliveries($id)]);
    }

    public function deliverPrinted(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'payroll.payslips.publish');
        /** @var array{delivered_on?: string|null, evidence_document_id?: string|null, note?: string|null} $data */
        $data = $request->validate([
            'delivered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'evidence_document_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $this->payslips->deliverPrinted($id, $data, $this->userId($request));

        return $this->respond($request, $id);
    }

    public function bulkDownload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['sometimes', 'nullable', 'uuid'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'payslip_ids' => ['sometimes', 'array', 'max:2000'],
            'payslip_ids.*' => ['uuid'],
        ]);
        if (empty($data['payroll_period_id']) && empty($data['payslip_ids'])) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.payslips.bulk_filter_required'), [['field' => 'payroll_period_id', 'rule' => 'required', 'message' => (string) trans('payroll.payslips.bulk_filter_required')]]);
        }
        $this->requireResults($request);
        $q = DB::table('payslips AS s')->where('s.status', '<>', 'voided');
        $this->scope->applyEmployment($q, $request, self::VIEW, 's.employment_id');
        if (! empty($data['payroll_period_id'])) {
            $q->where('s.payroll_period_id', $data['payroll_period_id']);
        }
        if (! empty($data['payslip_ids'])) {
            $q->whereIn('s.id', $data['payslip_ids']);
        }
        if (! empty($data['org_unit_id'])) {
            $q->whereExists(fn (Builder $w) => $w->from('employment_assignments AS a')->whereColumn('a.employment_id', 's.employment_id')
                ->where('a.org_unit_id', $data['org_unit_id'])->whereNull('a.valid_to'));
        }

        return response()->json(['data' => $this->payslips->bulkDownload(array_values($q->pluck('s.id')->map(fn ($v): string => (string) $v)->all()), $this->userId($request), $request->ip())]);
    }

    private function respond(Request $request, string $id): JsonResponse
    {
        $row = $this->payslips->present($id);

        return response()->json(['data' => $this->strip($this->sensitive->present('Payslip', $row, $this->permissions($request), 'api', (string) $row['person_id']))]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function strip(array $row): array
    {
        unset($row['person_id']);

        return $row;
    }

    private function find(Request $request, string $id, string $permission = self::VIEW): string
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $q = DB::table('payslips')->where('id', $id);
        $this->scope->applyEmployment($q, $request, $permission, 'employment_id');
        abort_unless($q->exists(), 404);

        return $id;
    }

    private function period(Request $request, string $id, string $permission): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $q = DB::table('payroll_periods')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id')->where('payroll_periods.id', $id);
        $this->scope->applyLegalEntity($q, $request, $permission, 'payroll_groups.legal_entity_id');
        abort_unless($q->exists(), 404);
    }

    /** RN-BOL-06 · Ver montos de la boleta de otro exige además ver resultados de planilla. */
    private function requireResults(Request $request): void
    {
        if (! $this->permissions($request)->has('payroll.results.view')) {
            throw new AyniException('forbidden', 403);
        }
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
}
