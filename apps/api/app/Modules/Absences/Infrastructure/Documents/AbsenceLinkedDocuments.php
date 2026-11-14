<?php

namespace App\Modules\Absences\Infrastructure\Documents;

use App\Shared\Documents\LinkedDocumentSource;
use App\Shared\Files\FileService;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOC-1 · Los sustentos de las ausencias aparecen en el legajo como referencias (el archivo sigue siendo el del
 * módulo de ausencias, no se copia). Mismas reglas que la ausencia: alcance de time.absences.view y, en los tipos
 * confidenciales (salud), el sustento solo lo ve quien tiene time.absences.medical_certificate.view o el propio
 * titular (RN-AUS-05); esos van a la carpeta de certificados y su lectura queda en el registro de accesos. Los
 * demás, a la de vacaciones y ausencias.
 */
final class AbsenceLinkedDocuments implements LinkedDocumentSource
{
    private const SUBJECT_TYPE = 'absence_request';

    public function __construct(
        private readonly DataScope $scope,
        private readonly FileService $files,
    ) {}

    public function key(): string
    {
        return 'absence';
    }

    public function forEmployment(string $employmentId, Request $request, EffectivePermissions $permissions): array
    {
        $rows = $this->query($employmentId, $request, $permissions)->orderByDesc('t.created_at')->limit(500)
            ->get(['t.id', 't.label', 't.created_at', 'f.original_name', 'f.mime_type', 'f.size_bytes', 'a.id as absence_id', 'a.starts_on', 'a.ends_on', 'ty.name as type_name', 'ty.is_confidential']);

        return array_values($rows->map(fn ($r): array => [
            'id' => (string) $r->id,
            'folder_code' => (bool) $r->is_confidential ? 'certificates' : 'vacations',
            'title' => $r->label !== null && $r->label !== '' ? (string) $r->label : (string) $r->type_name,
            'subtitle' => trim((string) $r->type_name.' · '.substr((string) $r->starts_on, 0, 10).' – '.substr((string) $r->ends_on, 0, 10)),
            'issued_on' => substr((string) $r->starts_on, 0, 10),
            'file_name' => (string) $r->original_name,
            'mime_type' => (string) $r->mime_type,
            'size_bytes' => (int) $r->size_bytes,
            'uploaded_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601String(),
            'sensitive' => (bool) $r->is_confidential,
            'link' => '/tiempo/ausencias',
        ])->all());
    }

    public function download(string $id, string $employmentId, Request $request, EffectivePermissions $permissions): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }
        $row = $this->query($employmentId, $request, $permissions)->where('t.id', $id)->first(['t.stored_file_id', 'ty.is_confidential', 'e.person_id']);
        if ($row === null) {
            return null;
        }
        $user = $request->user();
        $file = $this->files->contentsForDownload((string) $row->stored_file_id, null, $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null);

        return $file + ['sensitive' => (bool) $row->is_confidential, 'person_id' => $row->person_id === null ? null : (string) $row->person_id];
    }

    private function query(string $employmentId, Request $request, EffectivePermissions $permissions): Builder
    {
        $q = DB::table('attachments as t')
            ->join('absence_requests as a', 'a.id', '=', 't.subject_id')
            ->join('absence_types as ty', 'ty.id', '=', 'a.absence_type_id')
            ->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->join('stored_files as f', 'f.id', '=', 't.stored_file_id')
            ->where('t.subject_type', self::SUBJECT_TYPE)->where('a.employment_id', $employmentId)
            ->whereNull('a.deleted_at')->whereNull('f.deleted_at');
        if (! $permissions->has('time.absences.view')) {
            return $q->whereRaw('false');
        }
        $this->scope->applyEmployment($q, $request, 'time.absences.view', 'a.employment_id');
        if (! $permissions->has('time.absences.medical_certificate.view')) {
            $user = $request->user();
            $person = $user instanceof Authenticatable ? DB::table('users')->where('id', $user->getAuthIdentifier())->value('person_id') : null;
            $q->where(fn ($w) => $w->where('ty.is_confidential', false)->when($person !== null, fn ($o) => $o->orWhere('e.person_id', $person)));
        }

        return $q;
    }
}
