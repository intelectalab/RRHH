<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DOC-1 · Quién ve qué documento del legajo. Todo se aplica en la consulta (regla 6), nunca en la vista:
 *
 * - Alcance: los del legajo de una relación laboral, con el alcance de relaciones del permiso; los de una empresa
 *   (sin relación), con el alcance de empresas.
 * - Permiso de la carpeta o del tipo (required_permission_code; p. ej. contratos exige contracts.contracts.view).
 * - RN-DOC-08 · Confidencialidad: los confidenciales y restringidos solo los ve quien tiene
 *   documents.documents.confidential.view, o el propio titular cuando están publicados para él.
 * - El titular (alcance propio) ve solo lo publicado para él (visible_to_employee, vigente o vencido) y lo que él
 *   mismo subió. RN-DOC-13: el excolaborador conserva el acceso a lo suyo.
 */
final class DocumentAccess
{
    public const CONFIDENTIAL_PERMISSION = 'documents.documents.confidential.view';

    /** @var array<string, string|null> */
    private array $personMemo = [];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * @return Builder<Document>
     */
    public function scoped(Request $request, string $permission): Builder
    {
        $permissions = $this->permissions($request);
        $query = Document::query()->select('documents.*');
        $query->where(function (Builder $w) use ($request, $permission): void {
            $w->where(function (Builder $e) use ($request, $permission): void {
                $e->whereNotNull('documents.employment_id');
                $this->scope->applyEmployment($e, $request, $permission, 'documents.employment_id');
            })->orWhere(function (Builder $c) use ($request, $permission): void {
                $c->whereNull('documents.employment_id')->whereNotNull('documents.legal_entity_id');
                $this->scope->applyLegalEntity($c, $request, $permission, 'documents.legal_entity_id');
            });
        });

        $blocked = $this->blockedCategories($permissions);
        if ($blocked !== []) {
            $query->whereNotIn('documents.document_category_id', $blocked);
        }

        $person = $this->viewerPerson($request);
        $confidential = $permissions->has(self::CONFIDENTIAL_PERMISSION);
        if (! $confidential) {
            $query->where(function (Builder $w) use ($person): void {
                $w->whereNotIn('documents.classification', ['confidential', 'restricted']);
                if ($person !== null) {
                    $w->orWhere(fn (Builder $o) => $o->where('documents.owner_person_id', $person)->where('documents.visible_to_employee', true));
                }
            });
        }

        if ($person !== null && $this->isTitularOnly($permissions, $permission)) {
            $userId = $this->userId($request);
            // Lo de otras personas (su equipo) sigue su alcance; lo propio y lo de la empresa, solo si está publicado.
            $query->where(function (Builder $w) use ($person, $userId): void {
                $w->where(fn (Builder $o) => $o->whereNotNull('documents.owner_person_id')->where('documents.owner_person_id', '<>', $person))
                    ->orWhere(fn (Builder $o) => $o->where('documents.visible_to_employee', true)
                        ->whereIn('documents.status', [DocumentStateMachine::ACTIVE, DocumentStateMachine::EXPIRED]))
                    ->orWhereExists(fn ($q) => $q->from('document_versions as dv')->whereColumn('dv.document_id', 'documents.id')
                        ->where('dv.uploaded_by_user_id', $userId));
            });
        }

        return $query;
    }

    public function find(Request $request, string $id, string $permission): Document
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $doc = $this->scoped($request, $permission)->where('documents.id', $id)->first();
        abort_unless($doc instanceof Document, 404);

        return $doc;
    }

    /** La relación laboral está en el alcance del permiso (si no, 404). */
    public function employmentInScope(Request $request, string $employmentId, string $permission): bool
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $employmentId) !== 1) {
            return false;
        }
        $q = DB::table('employments')->where('employments.id', $employmentId)->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($q, $request, $permission, 'employments.id');

        return $q->exists();
    }

    public function legalEntityInScope(Request $request, string $legalEntityId, string $permission): bool
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $legalEntityId) !== 1) {
            return false;
        }
        $q = DB::table('legal_entities')->where('legal_entities.id', $legalEntityId)->whereNull('legal_entities.deleted_at');
        $this->scope->applyLegalEntity($q, $request, $permission, 'legal_entities.id');

        return $q->exists();
    }

    /**
     * Tipos y carpetas cuyo permiso propio el usuario no tiene (los tipos heredan el de su carpeta).
     *
     * @return list<string>
     */
    public function blockedCategories(EffectivePermissions $permissions): array
    {
        $rows = DB::table('document_categories')->whereNotNull('required_permission_code')->get(['id', 'required_permission_code']);
        $blocked = [];
        foreach ($rows as $r) {
            if (! $permissions->has((string) $r->required_permission_code)) {
                $blocked[] = (string) $r->id;
            }
        }
        if ($blocked !== []) {
            $children = DB::table('document_categories')->whereIn('parent_id', $blocked)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $blocked = array_values(array_unique(array_merge($blocked, $children)));
        }

        return $blocked;
    }

    /** El usuario consulta solo como titular: su alcance para el permiso es «propio». */
    public function isTitularOnly(EffectivePermissions $permissions, string $permission): bool
    {
        $scope = $permissions->scopeFor($permission);

        return $scope === null || ! in_array($scope, ['organization', 'legal_entity', 'site', 'org_unit'], true);
    }

    public function isOwner(Request $request, Document $doc): bool
    {
        $person = $this->viewerPerson($request);

        return $person !== null && $doc->owner_person_id === $person;
    }

    public function viewerPerson(Request $request): ?string
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return null;
        }
        if (! array_key_exists($userId, $this->personMemo)) {
            $person = DB::table('users')->where('id', $userId)->value('person_id');
            $this->personMemo[$userId] = $person === null ? null : (string) $person;
        }

        return $this->personMemo[$userId];
    }

    /**
     * Regla 7 · RN-DOC-08: la lectura de un documento confidencial queda en el registro de accesos (quién, qué
     * documento, de quién y cuándo), además del registro de descargas del almacenamiento.
     */
    public function logSensitiveRead(Request $request, string $entityType, string $entityId, ?string $personId, bool $inline, string $category = 'other'): void
    {
        DB::table('data_access_logs')->insert([
            'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $this->userId($request), 'access_kind' => $inline ? 'view' : 'download',
            'data_category' => $category, 'subject_person_id' => $personId, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'fields' => '{file}', 'purpose' => 'document', 'ip' => $request->ip(), 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    public function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
    }
}
