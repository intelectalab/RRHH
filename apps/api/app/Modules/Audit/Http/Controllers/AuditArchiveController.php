<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditPresenter;
use App\Modules\Audit\Application\AuditStorePresenter;
use App\Shared\Files\ColdArchiveStorage;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUD-5 · Archivo frío de la auditoría: manifiestos por mes y consulta de sus entradas mientras dure
 * el plazo de conservación (los campos sensibles siguen cifrados y se enmascaran sin su permiso).
 */
final class AuditArchiveController
{
    public function __construct(
        private readonly AuditStorePresenter $presenter,
        private readonly AuditPresenter $entries,
        private readonly ColdArchiveStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $rows = DB::table('audit_archives')->orderByDesc('period_month')->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->presenter->archive($r))->values()->all(),
            'meta' => ['per_page' => $perPage, 'next_cursor' => null],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->archive($this->find($id))]);
    }

    public function entries(Request $request, string $id): JsonResponse
    {
        $archive = $this->find($id);
        $disk = (string) $archive->storage_disk;
        $key = (string) $archive->storage_key;
        // Vencido el plazo, el archivo se borra: ya no se puede consultar.
        abort_if($archive->purged_at !== null || ! $this->storage->exists($disk, $key), 404);

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = max(1, min(100, (int) $request->query('per_page', '50')));
        $lines = array_values(array_filter(explode("\n", (string) $this->storage->get($disk, $key)), fn ($l) => $l !== ''));
        /** @var EffectivePermissions $permissions */
        $permissions = $request->attributes->get('effective_permissions');

        $data = [];
        foreach (array_slice($lines, ($page - 1) * $perPage, $perPage) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $data[] = $this->entries->present((object) ($row + ['actor_name' => null]), $permissions) + [
                    'chain_seq' => isset($row['chain_seq']) ? (int) $row['chain_seq'] : null,
                ];
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => count($lines), 'archive' => $this->presenter->archive($archive)],
        ]);
    }

    private function find(string $id): \stdClass
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $archive = DB::table('audit_archives')->where('id', $id)->first();
        abort_unless($archive instanceof \stdClass, 404);

        return $archive;
    }
}
