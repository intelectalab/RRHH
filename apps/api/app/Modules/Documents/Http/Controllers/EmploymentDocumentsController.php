<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\DocumentCatalog;
use App\Modules\Documents\Application\DocumentPresenter;
use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Shared\Documents\LinkedDocumentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * DOC-1 · Legajo de la relación laboral, por carpeta: los documentos del legajo (incluidos los que guarda el módulo
 * de contratos en el mismo repositorio) y las referencias que aportan otros módulos (sustentos y certificados de
 * ausencias) sin duplicar archivos. Cada parte respeta los permisos de quien consulta.
 */
final class EmploymentDocumentsController
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentCatalog $catalog,
        private readonly DocumentPresenter $presenter,
        private readonly LinkedDocumentRegistry $linked,
    ) {}

    public function index(Request $request, string $employment_id): JsonResponse
    {
        abort_unless($this->access->employmentInScope($request, $employment_id, 'documents.documents.view'), 404);
        $input = $request->validate([
            'include_archived' => ['sometimes', 'in:true,false,1,0'],
            'folder' => ['sometimes', 'string', 'max:60'],
        ]);
        $archived = in_array($input['include_archived'] ?? 'false', ['true', '1'], true);
        $this->catalog->ensureDefaults();

        /** @var list<Document> $docs */
        $docs = $this->access->scoped($request, 'documents.documents.view')->where('documents.employment_id', $employment_id)
            ->when(! $archived, fn ($q) => $q->where('documents.status', '<>', DocumentStateMachine::ARCHIVED))
            ->orderByDesc('documents.created_at')->limit(1000)->get()->all();
        $presented = $this->presenter->many($docs, $request);

        $permissions = $this->access->permissions($request);
        $linked = [];
        foreach ($this->linked->all() as $key => $source) {
            foreach ($source->forEmployment($employment_id, $request, $permissions) as $item) {
                $linked[] = ['ref' => $key.':'.$item['id'], 'source' => $key] + $item;
            }
        }

        $blocked = $this->access->blockedCategories($permissions);
        /** @var list<DocumentCategory> $folders */
        $folders = DocumentCategory::query()->whereNull('parent_id')->where('scope', 'employee')
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', array_values(array_filter(array_map(fn (array $d) => $d['folder_id'], $presented)))))
            ->orderBy('sort_order')->orderBy('name')->get()->all();
        $out = [];
        foreach ($folders as $f) {
            if (in_array($f->id, $blocked, true)) {
                continue;
            }
            if (isset($input['folder']) && $input['folder'] !== $f->code) {
                continue;
            }
            $items = array_values(array_filter($presented, fn (array $d) => $d['folder_id'] === $f->id));
            $refs = array_values(array_filter($linked, fn (array $l) => $l['folder_code'] === $f->code));
            $out[] = [
                'id' => $f->id, 'code' => $f->code, 'name' => $this->catalog->name($f), 'is_system' => $f->is_system,
                'count' => count($items) + count($refs),
                'expiring' => count(array_filter($items, fn (array $d) => $d['expiring'] === true)),
                'expired' => count(array_filter($items, fn (array $d) => $d['status'] === DocumentStateMachine::EXPIRED)),
                'documents' => $items, 'linked' => $refs,
            ];
        }
        $canUpload = $this->access->employmentInScope($request, $employment_id, 'documents.documents.create');

        return response()->json(['data' => ['employment_id' => $employment_id, 'folders' => $out], 'meta' => [
            'can_upload' => $canUpload, 'can_manage_types' => $permissions->has('documents.document_types.update'),
            'total' => count($presented) + count($linked),
        ]]);
    }

    public function linkedFile(Request $request, string $employment_id, string $ref): Response
    {
        abort_unless($this->access->employmentInScope($request, $employment_id, 'documents.documents.view'), 404);
        [$key, $id] = array_pad(explode(':', $ref, 2), 2, '');
        $source = $this->linked->get($key);
        abort_if($source === null || $id === '', 404);
        $file = $source->download($id, $employment_id, $request, $this->access->permissions($request));
        abort_if($file === null, 404);
        $inline = in_array((string) $request->query('inline', ''), ['true', '1'], true);
        if ($file['sensitive']) {
            $this->access->logSensitiveRead($request, $key.'_document', $id, $file['person_id'], $inline, 'health');
        }

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
