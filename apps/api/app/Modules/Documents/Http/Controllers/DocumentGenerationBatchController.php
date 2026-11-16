<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Infrastructure\Models\DocumentGenerationBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOC-5 · Lotes de generación: avance (generados y con error) y resultado por persona. Cada quien ve los lotes que
 * pidió; quien genera con alcance de organización ve todos.
 */
final class DocumentGenerationBatchController
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = $this->scoped($request);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $total = (clone $query)->count();
        /** @var list<DocumentGenerationBatch> $rows */
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get()->all();

        return response()->json(['data' => array_map(fn (DocumentGenerationBatch $b): array => self::presentBatch($b), $rows),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $batch = $this->scoped($request)->where('id', $id)->first();
        abort_unless($batch instanceof DocumentGenerationBatch, 404);

        return response()->json(['data' => self::presentBatch($batch)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentBatch(DocumentGenerationBatch $b): array
    {
        $template = DB::table('document_templates')->where('id', $b->document_template_id)->value('name');
        $version = DB::table('document_template_versions')->where('id', $b->document_template_version_id)->value('version');

        return [
            'id' => $b->id, 'template_id' => $b->document_template_id, 'template_name' => $template, 'template_version' => (int) $version,
            'status' => $b->status, 'total' => $b->total, 'generated' => $b->generated, 'failed' => $b->failed, 'items' => $b->items ?? [],
            'requested_by_user_id' => $b->requested_by_user_id,
            'requested_by_name' => $b->requested_by_user_id === null ? null : DB::table('users')->where('id', $b->requested_by_user_id)->value('display_name'),
            'started_at' => $b->started_at?->toIso8601String(), 'finished_at' => $b->finished_at?->toIso8601String(),
            'created_at' => $b->created_at->toIso8601String(), 'updated_at' => $b->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return Builder<DocumentGenerationBatch>
     */
    private function scoped(Request $request): Builder
    {
        $query = DocumentGenerationBatch::query();
        if ($this->access->permissions($request)->scopeFor('documents.templates.generate') !== 'organization') {
            $query->where('requested_by_user_id', $this->access->userId($request));
        }

        return $query;
    }
}
