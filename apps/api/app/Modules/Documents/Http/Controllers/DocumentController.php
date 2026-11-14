<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\DocumentPresenter;
use App\Modules\Documents\Application\DocumentService;
use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Shared\Exceptions\AyniException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * DOC-1 · DOC-2 · Documentos del legajo (ME-12). El alcance, el permiso de la carpeta y la confidencialidad se
 * aplican en la consulta (DocumentAccess); fuera de ellos, 404. Los estados cambian solo por acciones.
 */
final class DocumentController
{
    private const STATUSES = ['draft', 'pending_approval', 'active', 'archived', 'expired', 'rejected', 'requested'];

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentService $documents,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.legal_entity_id' => ['sometimes', 'string'],
            'filter.category_id' => ['sometimes', 'uuid'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('documents.documents.invalid_filter'));
        }
        $query = $this->access->scoped($request, 'documents.documents.view')
            ->when($statuses !== [], fn ($q) => $q->whereIn('documents.status', $statuses))
            ->when(isset($filter['employment_id']), fn ($q) => $q->where('documents.employment_id', $filter['employment_id']))
            ->when(isset($filter['legal_entity_id']) && $filter['legal_entity_id'] !== 'all', fn ($q) => $q->where('documents.legal_entity_id', $filter['legal_entity_id']))
            ->when(isset($filter['category_id']), fn ($q) => $q->where(fn ($w) => $w->where('documents.document_category_id', $filter['category_id'])
                ->orWhereIn('documents.document_category_id', DB::table('document_categories')->where('parent_id', $filter['category_id'])->select('id'))));
        $this->search($query, $input['q'] ?? null);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $total = (clone $query)->count();
        /** @var list<Document> $rows */
        $rows = $query->orderByDesc('documents.created_at')->forPage($page, $perPage)->get()->all();

        return response()->json(['data' => $this->presenter->many($rows, $request), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'category_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'employment_id' => ['sometimes', 'nullable', 'uuid'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'classification' => ['sometimes', 'nullable', 'in:public,internal,confidential,restricted'],
            'visible_to_employee' => ['sometimes', 'in:true,false,1,0'],
            'responsible_user_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (isset($data['employment_id'])) {
            // Fuera del alcance la relación no existe para el usuario (no se revela si existe en otra parte).
            if (! $this->access->employmentInScope($request, (string) $data['employment_id'], 'documents.documents.create')) {
                throw ValidationException::withMessages(['employment_id' => [(string) trans('documents.documents.owner_unknown')]]);
            }
            unset($data['legal_entity_id']);
        } elseif (isset($data['legal_entity_id'])) {
            if (! $this->access->legalEntityInScope($request, (string) $data['legal_entity_id'], 'documents.documents.create')) {
                throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('documents.documents.owner_unknown')]]);
            }
        }
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $permissions = $this->access->permissions($request);
        $doc = $this->documents->upload($data, $file, $this->access->userId($request), $this->access->viewerPerson($request),
            $permissions->has(DocumentAccess::CONFIDENTIAL_PERMISSION));

        return response()->json(['data' => $this->presenter->one($doc, $request)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->one($this->access->find($request, $id, 'documents.documents.view'), $request)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.update');
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => ['sometimes', 'uuid'],
            'classification' => ['sometimes', 'in:public,internal,confidential,restricted'],
            'visible_to_employee' => ['sometimes', 'boolean'],
            'responsible_user_id' => ['sometimes', 'nullable', 'uuid'],
            'version' => ['sometimes', 'integer'],
        ]);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');
        $expected = $ifMatch !== '' ? (int) $ifMatch : (isset($data['version']) ? (int) $data['version'] : null);
        unset($data['version']);
        $doc = $this->documents->update($doc, $data, $expected, $this->access->permissions($request)->has(DocumentAccess::CONFIDENTIAL_PERMISSION));

        return response()->json(['data' => $this->presenter->one($doc, $request)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.approve');
        $this->assertNotOwnReview($request, $doc);

        return $this->respond($this->documents->approve($doc, $this->access->userId($request)), $request);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.approve');
        $this->assertNotOwnReview($request, $doc);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason']);

        return $this->respond($this->documents->reject($doc, $reason, $this->access->userId($request)), $request);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.archive');
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->respond($this->documents->archive($doc, is_string($reason) && $reason !== '' ? $reason : null), $request);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->documents->restore($this->access->find($request, $id, 'documents.documents.restore')), $request);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.update');
        $this->documents->trash($doc);

        return response()->json(['data' => ['id' => $doc->id, 'deleted' => true]]);
    }

    /**
     * DOC-2 · RN-DOC-09 · Por vencer (dentro de la anticipación de cada tipo, o de «within» días) y vencidos.
     */
    public function expiring(Request $request): JsonResponse
    {
        // Los filtros llegan sueltos (?within=) o como filter[within] desde la tabla de la interfaz (UI-4).
        $raw = array_merge(array_intersect_key((array) $request->query('filter', []), array_flip(['within', 'include_expired', 'category_id'])), $request->query());
        unset($raw['filter']);
        $input = Validator::make($raw, [
            'within' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'include_expired' => ['sometimes', 'in:true,false,1,0'],
            'category_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
        ])->validate();
        $today = CarbonImmutable::today();
        $within = isset($input['within']) ? (int) $input['within'] : null;
        $withExpired = ! in_array($input['include_expired'] ?? 'true', ['false', '0'], true);
        $query = $this->access->scoped($request, 'documents.documents.view')->whereNotNull('documents.expires_on')
            ->whereIn('documents.status', $withExpired ? [DocumentStateMachine::ACTIVE, DocumentStateMachine::EXPIRED] : [DocumentStateMachine::ACTIVE])
            ->where('documents.expires_on', '<=', $today->addDays($within ?? 365)->toDateString())
            ->when(isset($input['category_id']), fn ($q) => $q->where(fn ($w) => $w->where('documents.document_category_id', $input['category_id'])
                ->orWhereIn('documents.document_category_id', DB::table('document_categories')->where('parent_id', $input['category_id'])->select('id'))));
        $this->search($query, $input['q'] ?? null);
        /** @var list<Document> $rows */
        $rows = $query->orderBy('documents.expires_on')->limit(2000)->get()->all();
        $rows = array_values(array_filter($rows, function (Document $d) use ($within, $today): bool {
            if ($d->status === DocumentStateMachine::EXPIRED) {
                return true;
            }
            $days = (int) $today->diffInDays(CarbonImmutable::parse((string) $d->day('expires_on')), false);

            return $days < 0 || $days <= ($within ?? $this->documents->noticeDays($d));
        }));
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $codes = DB::table('employments')->whereIn('id', array_values(array_filter(array_map(fn (Document $d) => $d->employment_id, $slice))))->pluck('employee_code', 'id');
        $data = array_map(fn (array $row): array => $row + ['employee_code' => $row['employment_id'] === null ? null : ($codes[$row['employment_id']] ?? null)],
            $this->presenter->many($slice, $request));

        return response()->json(['data' => $data, 'meta' => [
            'page' => $page, 'per_page' => $perPage, 'total' => count($rows),
            'expired' => count(array_filter($rows, fn (Document $d) => $d->status === DocumentStateMachine::EXPIRED || (string) $d->day('expires_on') < $today->toDateString())),
        ]]);
    }

    public function versions(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.view');
        $rows = DB::table('document_versions as v')->join('stored_files as f', 'f.id', '=', 'v.stored_file_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.uploaded_by_user_id')
            ->where('v.document_id', $doc->id)->orderByDesc('v.version')
            ->get(['v.version', 'v.issued_on', 'v.expires_on', 'v.notes', 'v.content_hash', 'v.created_at', 'u.display_name', 'f.original_name', 'f.mime_type', 'f.size_bytes', 'v.id'])
            ->map(fn ($v): array => [
                'version' => (int) $v->version, 'is_current' => $v->id === $doc->current_version_id,
                'file_name' => (string) $v->original_name, 'mime_type' => (string) $v->mime_type, 'size_bytes' => (int) $v->size_bytes,
                'issued_on' => $v->issued_on === null ? null : substr((string) $v->issued_on, 0, 10),
                'expires_on' => $v->expires_on === null ? null : substr((string) $v->expires_on, 0, 10),
                'notes' => $v->notes, 'content_hash' => (string) $v->content_hash, 'uploaded_by_name' => $v->display_name,
                'uploaded_at' => CarbonImmutable::parse((string) $v->created_at)->toIso8601String(),
            ])->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function addVersion(Request $request, string $id): JsonResponse
    {
        $doc = $this->access->find($request, $id, 'documents.documents.new_version');
        $data = $request->validate([
            'file' => ['required', 'file'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $permissions = $this->access->permissions($request);
        $titular = $this->access->isOwner($request, $doc) && $this->access->isTitularOnly($permissions, 'documents.documents.new_version');
        $userId = $this->access->userId($request);
        if ($titular && ! DB::table('document_versions')->where('document_id', $doc->id)->where('uploaded_by_user_id', $userId)->exists()) {
            // El titular solo reemplaza lo que él mismo entregó; lo demás lo actualiza RRHH.
            abort(403, (string) trans('documents.documents.titular_replace'));
        }
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $doc = $this->documents->newVersion($doc, $file, self::str($data['issued_on'] ?? null), self::str($data['expires_on'] ?? null),
            self::str($data['notes'] ?? null), $userId, $titular);

        return response()->json(['data' => $this->presenter->one($doc, $request)], 201);
    }

    public function file(Request $request, string $id): Response
    {
        $doc = $this->access->find($request, $id, 'documents.documents.view');

        return $this->download($request, $doc, null);
    }

    public function versionFile(Request $request, string $id, string $version): Response
    {
        $doc = $this->access->find($request, $id, 'documents.documents.view');
        abort_unless(ctype_digit($version), 404);

        return $this->download($request, $doc, (int) $version);
    }

    private function download(Request $request, Document $doc, ?int $number): Response
    {
        $version = $this->documents->version($doc, $number);
        abort_if($version === null, 404);
        $inline = in_array((string) $request->query('inline', ''), ['true', '1'], true);
        $file = $this->documents->contents($version, $this->access->userId($request));
        if (in_array($doc->classification, ['confidential', 'restricted'], true)) {
            $this->access->logSensitiveRead($request, 'document', $doc->id, $doc->owner_person_id, $inline);
        }

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Quien entregó el documento no aprueba ni rechaza lo suyo. */
    private function assertNotOwnReview(Request $request, Document $doc): void
    {
        if ($this->access->isOwner($request, $doc)) {
            throw new AyniException('self_review', 403, (string) trans('documents.documents.self_review'));
        }
    }

    private function respond(Document $doc, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->one($doc->refresh(), $request)]);
    }

    /**
     * @param  Builder<Document>  $query
     */
    private function search($query, mixed $q): void
    {
        if (! is_string($q) || trim($q) === '') {
            return;
        }
        $like = '%'.addcslashes(trim($q), '%_\\').'%';
        $query->where(fn ($w) => $w->where('documents.title', 'ilike', $like)
            ->orWhereIn('documents.employment_id', DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
                ->where(fn ($p) => $p->where('e.employee_code', 'ilike', $like)->orWhere('p.first_names', 'ilike', $like)
                    ->orWhere('p.paternal_surname', 'ilike', $like)->orWhere('p.preferred_name', 'ilike', $like))
                ->select('e.id')));
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
