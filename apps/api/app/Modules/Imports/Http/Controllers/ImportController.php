<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Application\ImportService;
use App\Modules\Imports\Infrastructure\Models\ImportJob;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * IMP-1 · Importaciones (Configuración → Importar datos). El estado cambia solo por acciones
 * (map, validate, execute, cancel, revert); no hay PATCH de «status» (regla 3).
 */
final class ImportController
{
    private const STATUSES = ['uploaded', 'mapped', 'validated', 'with_errors', 'importing', 'completed', 'failed', 'cancelled', 'reverted'];

    private const ROW_STATUSES = ['pending', 'valid', 'invalid', 'imported', 'skipped', 'failed'];

    public function __construct(private readonly ImportService $imports) {}

    public function types(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->imports->types($this->permissions($request), $this->locale($request))]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $page = max(1, (int) $request->query('page', '1'));
        $filter = (array) $request->query('filter', []);
        $query = ImportJob::query();
        $status = $filter['status'] ?? null;
        if ($status !== null) {
            if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
                throw new AyniException('invalid_filter', 400, null, [], ['filter' => 'status']);
            }
            $query->where('status', $status);
        }
        $entity = $filter['entity'] ?? null;
        if (is_string($entity) && $entity !== '') {
            $query->where('entity', $entity);
        }
        $total = (clone $query)->count();
        $jobs = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $jobs->map(fn (ImportJob $j) => $this->imports->present($j))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $request->input('entity');
        $content = $request->input('content_base64');
        $errors = [];
        if (! is_string($entity) || $entity === '') {
            $errors['entity'][] = (string) trans('imports.entity_invalid', [], $this->locale($request));
        }
        $decoded = is_string($content) ? base64_decode($content, true) : false;
        if ($decoded === false || $decoded === '') {
            $errors['content_base64'][] = (string) trans('imports.file.empty', [], $this->locale($request));
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $fileName = $request->input('file_name');
        $job = $this->imports->create((string) $entity, is_string($fileName) ? $fileName : '', (string) $decoded, $this->userId($request), $this->permissions($request), $this->locale($request));

        return response()->json(['data' => $this->imports->present($job->refresh())], 201);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function map(Request $request, string $id): JsonResponse
    {
        $job = $this->find($id);
        $mapping = $request->input('mapping');
        if (! is_array($mapping)) {
            throw ValidationException::withMessages(['mapping' => [(string) trans('imports.mapping.invalid', [], $this->locale($request))]]);
        }
        $saveAs = $request->input('save_as');
        $profile = $request->input('profile_code');

        return $this->respond($this->imports->map($job, $mapping, is_string($saveAs) ? $saveAs : null, $this->permissions($request), $this->locale($request), is_string($profile) ? $profile : null));
    }

    public function validate(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->imports->validate($this->find($id), $this->permissions($request), $this->locale($request)));
    }

    public function execute(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->imports->execute($this->find($id), $request->boolean('confirm'), $this->permissions($request), $this->locale($request)));
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->respond($this->imports->cancel($this->find($id)));
    }

    public function revert(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->imports->revert($this->find($id), $this->permissions($request), $this->locale($request)));
    }

    public function rows(Request $request, string $id): JsonResponse
    {
        $job = $this->find($id);
        $filter = (array) $request->query('filter', []);
        $status = $filter['status'] ?? null;
        if ($status !== null && (! is_string($status) || ! in_array($status, self::ROW_STATUSES, true))) {
            throw new AyniException('invalid_filter', 400, null, [], ['filter' => 'status']);
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $page = max(1, (int) $request->query('page', '1'));

        return response()->json($this->imports->rows($job, $status, $page, $perPage));
    }

    public function downloadErrors(Request $request, string $id): Response
    {
        $job = $this->find($id);
        if ($job->status === 'cancelled' || (int) $job->error_rows === 0 && ! in_array($job->status, ['failed'], true)) {
            throw new AyniException('conflict', 409, (string) trans('imports.no_errors', [], $this->locale($request)));
        }
        $csv = $this->imports->errorsCsv($job, $this->locale($request));
        $name = str_replace('"', '', pathinfo((string) $job->file_name, PATHINFO_FILENAME) ?: 'import').'-errores.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** IMP-6 · Descarga del archivo original tal como se subió. */
    public function downloadFile(Request $request, string $id): Response
    {
        $file = $this->imports->originalFile($this->find($id), $this->userId($request), $this->locale($request));
        $name = str_replace(['"', "\r", "\n"], '', $file['name'] !== '' ? $file['name'] : 'import');

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'] !== '' ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(string $id): ImportJob
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new AyniException('not_found', 404);
        }
        $job = ImportJob::query()->whereKey($id)->first();
        if ($job === null) {
            throw new AyniException('not_found', 404);
        }

        return $job;
    }

    private function respond(ImportJob $job, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->imports->present($job->refresh())], $status);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            throw new AyniException('unauthenticated', 401);
        }

        return (string) $user->getAuthIdentifier();
    }

    private function locale(Request $request): string
    {
        $user = $request->user();
        $locale = $user instanceof Authenticatable ? DB::table('users')->where('id', (string) $user->getAuthIdentifier())->value('locale') : null;

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
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
