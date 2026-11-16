<?php

namespace App\Modules\Certificates\Http\Controllers;

use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Certificates\Infrastructure\Models\CertificateRequest;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\SelfService\OwnEmployments;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * ESS-4 · Constancias: el colaborador pide las suyas (RN-ESS-01: solo su relación laboral) y RRHH ve,
 * emite o rechaza las de su alcance. La descarga del PDF queda registrada.
 */
final class CertificateRequestController
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly OwnEmployments $own,
        private readonly DataScope $scope,
        private readonly DocumentArchive $archive,
    ) {}

    public function types(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $picked = $this->own->pick($userId, $request->query('employment_id'));

        return response()->json(['data' => [
            'employments' => $this->own->list($userId),
            'employment' => $picked,
            'types' => $picked === null ? [] : $this->certificates->types($this->permissions($request)),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', Rule::in(['requested', 'in_progress', 'issued', 'rejected'])],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = $this->scoped($request, 'people.certificates.view');
        if (isset($input['filter']['status'])) {
            $query->where('certificate_requests.status', $input['filter']['status']);
        }
        if (isset($input['filter']['employment_id'])) {
            $query->where('certificate_requests.employment_id', $input['filter']['employment_id']);
        }
        if (isset($input['q'])) {
            $like = '%'.mb_strtolower($input['q']).'%';
            $query->whereIn('certificate_requests.employment_id', DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
                ->where(fn ($w) => $w->whereRaw('lower(e.employee_code) LIKE ?', [$like])
                    ->orWhereRaw("lower(p.first_names || ' ' || p.paternal_surname) LIKE ?", [$like]))->select('e.id'));
        }
        /** @var list<CertificateRequest> $rows */
        $rows = $query->orderByDesc('certificate_requests.created_at')->limit(200)->get()->all();
        $permissions = $this->permissions($request);

        return response()->json([
            'data' => array_map(fn (CertificateRequest $r) => $this->certificates->present($r, $permissions), $rows),
            'meta' => ['can_issue' => $permissions->has('people.certificates.issue')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'kind' => ['required', 'string', Rule::in(['employment_certificate', 'income_certificate', 'vacation_certificate', 'cts_letter', 'withholding_certificate', 'other'])],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $userId = $this->userId($request);
        // RN-ESS-01 · Solo para una relación laboral propia (una ajena responde 404). Quien ya cesó también
        // puede pedir su constancia de trabajo.
        $picked = $this->own->pick($userId, $input['employment_id']);
        abort_if($picked === null, 404);
        $r = $this->certificates->request([
            'employment_id' => $picked['id'], 'kind' => $input['kind'], 'purpose' => $input['purpose'] ?? null,
        ], $userId, $this->permissions($request));

        return response()->json(['data' => $this->certificates->present($r, $this->permissions($request))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->certificates->present($this->find($request, $id, 'people.certificates.view'), $this->permissions($request))]);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        $r = $this->certificates->issue($this->find($request, $id, 'people.certificates.issue'), $this->userId($request));

        return response()->json(['data' => $this->certificates->present($r, $this->permissions($request))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $found = $this->find($request, $id, 'people.certificates.issue');
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $r = $this->certificates->reject($found, $input['reason'], $this->userId($request));

        return response()->json(['data' => $this->certificates->present($r, $this->permissions($request))]);
    }

    public function file(Request $request, string $id): Response
    {
        $r = $this->find($request, $id, 'people.certificates.view');
        abort_if($r->document_id === null, 404);
        $file = $this->archive->download($r->document_id, $this->userId($request));
        abort_if($file === null, 404);
        $inline = in_array((string) $request->query('inline', ''), ['true', '1'], true);
        $person = DB::table('employments')->where('id', $r->employment_id)->value('person_id');
        // Regla 7 · La constancia muestra datos laborales (y el sueldo en la de remuneraciones): la apertura queda registrada.
        DB::table('data_access_logs')->insert([
            'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $this->userId($request), 'access_kind' => $inline ? 'view' : 'download',
            'data_category' => $r->kind === 'income_certificate' ? 'salary' : 'other', 'subject_person_id' => $person,
            'entity_type' => 'document', 'entity_id' => $r->document_id, 'fields' => '{file}', 'purpose' => 'certificate',
            'ip' => $request->ip(), 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($file['name'], '"\\').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return Builder<CertificateRequest> */
    private function scoped(Request $request, string $permission): Builder
    {
        $query = CertificateRequest::query()->select('certificate_requests.*');
        $this->scope->applyEmployment($query, $request, $permission, 'certificate_requests.employment_id');

        return $query;
    }

    private function find(Request $request, string $id, string $permission): CertificateRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $r = $this->scoped($request, $permission)->where('certificate_requests.id', $id)->first();
        abort_unless($r instanceof CertificateRequest, 404);

        return $r;
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return (string) $user->getAuthIdentifier();
    }
}
