<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\Payslips\Payslips;
use App\Shared\Exceptions\ModuleNotEnabledException;
use App\Shared\Permissions\ModuleGate;
use App\Shared\SelfService\OwnEmployments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * BOL-2 · BOL-3 · BOL-5 · Boletas propias en el portal: historial de todas las relaciones del usuario, detalle,
 * PDF (la primera apertura y cada descarga son constancia de entrega), acuse y explicación del neto. Solo el
 * titular: la boleta de otro responde 404 (RN-ESS-01). Las boletas aún no publicadas no se muestran.
 */
final class MyPayslipController extends Controller
{
    public function __construct(
        private readonly Payslips $payslips,
        private readonly OwnEmployments $own,
        private readonly ModuleGate $modules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ids = $this->employments($request);
        $data = $request->validate(['employment_id' => ['sometimes', 'nullable', 'uuid'], 'include_voided' => ['sometimes', 'in:0,1,true,false']]);
        if (! empty($data['employment_id'])) {
            $ids = array_values(array_intersect($ids, [(string) $data['employment_id']]));
        }
        $q = DB::table('payslips AS s')->join('payroll_periods AS pp', 'pp.id', '=', 's.payroll_period_id')->whereIn('s.employment_id', $ids)
            ->where('s.status', '<>', 'generated');
        if (! in_array((string) ($data['include_voided'] ?? ''), ['1', 'true'], true)) {
            $q->where('s.status', '<>', 'voided');
        }
        $rows = $q->orderByDesc('pp.starts_on')->orderByDesc('s.version')->limit(240)->pluck('s.id')
            ->map(fn ($id) => $this->strip($this->payslips->present((string) $id, false)))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->own($request, $id);
        $this->payslips->recordView($id, false, $request->ip());

        return response()->json(['data' => $this->strip($this->payslips->present($id))]);
    }

    public function file(Request $request, string $id): Response
    {
        $this->own($request, $id);
        $f = $this->payslips->file($id, $this->userId($request));
        $this->payslips->recordView($id, true, $request->ip());

        return response($f['contents'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="'.$f['name'].'"']);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $this->own($request, $id);
        $this->payslips->acknowledge($id, $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->strip($this->payslips->present($id))]);
    }

    public function explanation(Request $request, string $id): JsonResponse
    {
        $this->own($request, $id);

        return response()->json(['data' => $this->payslips->explanation($id)]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function strip(array $row): array
    {
        unset($row['person_id']);
        if (isset($row['deliveries']) && is_array($row['deliveries'])) {
            $row['deliveries'] = array_map(fn ($d) => array_intersect_key((array) $d, array_flip(['id', 'channel', 'status', 'published_at', 'first_viewed_at', 'downloaded_at', 'acknowledged_at'])), $row['deliveries']);
        }

        return $row;
    }

    private function own(Request $request, string $id): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $employment = $this->payslips->employmentOf($id);
        $status = DB::table('payslips')->where('id', $id)->value('status');
        abort_unless($employment !== null && $status !== 'generated' && in_array($employment, $this->employments($request), true), 404);
    }

    /** @return list<string> */
    private function employments(Request $request): array
    {
        if (! $this->modules->isEnabled('payroll')) {
            throw new ModuleNotEnabledException('payroll');
        }

        return array_map(fn (array $e): string => $e['id'], $this->own->list((string) $this->userId($request)));
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
