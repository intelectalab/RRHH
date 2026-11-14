<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOC-1 · DOC-2 · Forma pública de un documento del legajo (esquema Document de openapi.yaml): carpeta y tipo,
 * titular y responsable, versión vigente con su archivo, «por vencer» calculado con la anticipación del tipo
 * (RN-DOC-09) y las acciones que el usuario puede pedir (la interfaz solo muestra; el servidor valida).
 */
final class DocumentPresenter
{
    public function __construct(
        private readonly DocumentCatalog $catalog,
        private readonly DocumentService $documents,
        private readonly DocumentAccess $access,
        private readonly DocumentStateMachine $machine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function one(Document $doc, Request $request): array
    {
        return $this->many([$doc], $request)[0];
    }

    /**
     * @param  list<Document>  $docs
     * @return list<array<string, mixed>>
     */
    public function many(array $docs, Request $request): array
    {
        if ($docs === []) {
            return [];
        }
        $categories = DocumentCategory::query()->withTrashed()->get()->keyBy('id');
        $people = DB::table('people')->whereIn('id', array_values(array_filter(array_unique(array_map(fn (Document $d) => $d->owner_person_id, $docs)))))
            ->get(['id', 'first_names', 'preferred_name', 'paternal_surname', 'maternal_surname'])->keyBy('id');
        $users = DB::table('users')->whereIn('id', array_values(array_filter(array_unique(array_map(fn (Document $d) => $d->responsible_user_id, $docs)))))
            ->pluck('display_name', 'id');
        $versions = DB::table('document_versions as v')->join('stored_files as f', 'f.id', '=', 'v.stored_file_id')
            ->whereIn('v.id', array_values(array_filter(array_map(fn (Document $d) => $d->current_version_id, $docs))))
            ->get(['v.id', 'v.version', 'v.uploaded_by_user_id', 'f.original_name', 'f.mime_type', 'f.size_bytes'])->keyBy('id');
        $permissions = $this->access->permissions($request);
        $userId = $this->access->userId($request);
        $today = CarbonImmutable::today();

        return array_map(function (Document $d) use ($categories, $people, $users, $versions, $permissions, $userId, $request, $today): array {
            /** @var DocumentCategory|null $category */
            $category = $categories->get($d->document_category_id);
            /** @var DocumentCategory|null $folder */
            $folder = $category === null ? null : ($category->parent_id === null ? $category : $categories->get($category->parent_id));
            $person = $d->owner_person_id === null ? null : $people->get($d->owner_person_id);
            $version = $d->current_version_id === null ? null : $versions->get($d->current_version_id);
            $expires = $d->day('expires_on');
            $days = $expires === null ? null : (int) $today->diffInDays(CarbonImmutable::parse($expires), false);
            $expiring = $d->status === 'active' && $days !== null && $days >= 0 && $days <= $this->documents->noticeDays($d);
            $managed = $d->managedBy();
            $isOwner = $this->access->isOwner($request, $d);
            $uploadedByMe = $version !== null && $userId !== null && $version->uploaded_by_user_id === $userId;

            return [
                'id' => $d->id,
                'category_id' => $d->document_category_id,
                'category_name' => $category === null ? null : $this->catalog->name($category),
                'folder_id' => $folder?->id,
                'folder_code' => $folder?->code,
                'folder_name' => $folder === null ? null : $this->catalog->name($folder),
                'title' => $d->title,
                'description' => $d->description,
                'employment_id' => $d->employment_id,
                'legal_entity_id' => $d->legal_entity_id,
                'person_id' => $d->owner_person_id,
                'person_name' => $person === null ? null : trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname.' '.($person->maternal_surname ?? '')),
                'classification' => $d->classification,
                'status' => $d->status,
                'issued_on' => $d->day('issued_on'),
                'expires_on' => $expires,
                'expiring' => $expiring,
                'days_to_expiry' => $days,
                'visible_to_employee' => $d->visible_to_employee,
                'responsible_user_id' => $d->responsible_user_id,
                'responsible_name' => $d->responsible_user_id === null ? null : ($users[$d->responsible_user_id] ?? null),
                'source' => $d->source,
                'managed_by' => $managed,
                'current_version' => $version === null ? null : (int) $version->version,
                'file_name' => $version?->original_name,
                'mime_type' => $version?->mime_type,
                'size_bytes' => $version === null ? null : (int) $version->size_bytes,
                'archived_at' => $d->archived_at?->toIso8601String(),
                'archive_reason' => $d->archive_reason,
                'version' => $d->lock_version,
                'allowed_actions' => $this->actions($d, $permissions->has(...), $isOwner, $uploadedByMe),
                'created_at' => $d->created_at->toIso8601String(),
                'updated_at' => $d->updated_at->toIso8601String(),
            ];
        }, $docs);
    }

    /**
     * @param  callable(string): bool  $can
     * @return list<string>
     */
    private function actions(Document $d, callable $can, bool $isOwner, bool $uploadedByMe): array
    {
        if ($d->managedBy() !== null) {
            return [];
        }
        $out = [];
        $titular = $isOwner && ! $can('documents.documents.update');
        foreach (['approve', 'reject', 'replace', 'archive', 'restore'] as $action) {
            if (! $this->machine->can($d->status, $action)) {
                continue;
            }
            $ok = match ($action) {
                'approve', 'reject' => $can('documents.documents.approve') && ! $isOwner,
                'replace' => $can('documents.documents.new_version') && $d->source !== 'signature' && (! $titular || $uploadedByMe),
                'archive' => $can('documents.documents.archive') && ! $titular,
                'restore' => $can('documents.documents.restore') && ! $titular,
            };
            if ($ok) {
                $out[] = $action === 'replace' ? 'new_version' : $action;
            }
        }
        if ($can('documents.documents.update') && ! $titular) {
            $out[] = 'update';
            if ($d->source === 'upload') {
                $out[] = 'delete';
            }
        }

        return $out;
    }
}
