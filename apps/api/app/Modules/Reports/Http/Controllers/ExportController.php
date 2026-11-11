<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Reports\Application\ExportActor;
use App\Modules\Reports\Application\ExportService;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEG-12 · Exportaciones de listados. Cada usuario ve y descarga las suyas; quien además tiene
 * audit.logs.view (auditoría) ve las de toda la organización, sin poder descargarlas.
 */
final class ExportController
{
    public function __construct(private readonly ExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $query = DB::table('export_jobs as j')->leftJoin('users as u', 'u.id', '=', 'j.requested_by_user_id')
            ->select(['j.*', 'u.display_name as requested_by_name'])->orderByDesc('j.created_at')->orderByDesc('j.id');
        if (! $this->permissions($request)->has('audit.logs.view')) {
            $query->where('j.requested_by_user_id', $this->user($request)->id);
        }
        $filter = (array) $request->query('filter', []);
        if (is_string($filter['resource'] ?? null) && $filter['resource'] !== '') {
            $query->where('j.resource', $filter['resource']);
        }

        return response()->json([
            'data' => $query->limit($perPage)->get()->map(fn ($j) => $this->exports->present($j))->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => null],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->only(['resource', 'format', 'filters']);
        $job = $this->exports->create($input, $request, $this->user($request), $this->permissions($request));

        return response()->json(['data' => $this->exports->present($job)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->exports->present($this->find($request, $id, true))]);
    }

    public function download(Request $request, string $id): Response
    {
        $file = $this->exports->download($this->find($request, $id, false), $this->user($request));
        $name = str_replace('"', '', $file['name']);

        return response($file['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Solo quien la pidió la descarga; la auditoría puede verla (allowAuditor). */
    private function find(Request $request, string $id, bool $allowAuditor): \stdClass
    {
        $job = $this->exports->find($id);
        $user = $this->user($request);
        if ($job === null || ($job->requested_by_user_id !== $user->id && ! ($allowAuditor && $this->permissions($request)->has('audit.logs.view')))) {
            throw new AyniException('not_found', 404);
        }

        return $job;
    }

    private function user(Request $request): ExportActor
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }

        return ExportActor::from($user);
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $permissions = $request->attributes->get('effective_permissions');
        if (! $permissions instanceof EffectivePermissions) {
            throw new AyniException('forbidden', 403);
        }

        return $permissions;
    }
}
