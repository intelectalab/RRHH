<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\LegalEntityService;
use App\Modules\Organization\Http\Requests\LegalEntityRequest;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TEN-5 · Empresas del grupo. El listado respeta el alcance del usuario: con alcance de
 * una sola empresa, el selector solo muestra esa (se filtra en la consulta).
 */
final class LegalEntityController
{
    public function __construct(
        private readonly LegalEntityService $entities,
        private readonly DataScope $scope,
        private readonly CustomFieldValues $customFields,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = LegalEntity::query();
        $this->scope->applyLegalEntity($query, $request, 'org.legal_entities.view', 'id');

        $status = $request->query('status');
        if ($status === 'active' || $status === 'archived') {
            $query->where('status', $status === 'active' ? 'active' : 'inactive');
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('legal_name', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('tax_id', 'like', addcslashes($q, '%_\\').'%'));
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'legal_name');
        $ids = array_column($page['data'], 'id');
        $models = LegalEntity::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => array_values(array_map(
                fn (LegalEntity $e) => $this->entities->present($e),
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($e) => $e instanceof LegalEntity),
            )),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'org.legal_entities.view'));
    }

    public function store(LegalEntityRequest $request): JsonResponse
    {
        /** @var array{name: string, trade_name?: string|null, tax_id: string, country: string, currency: string, timezone: string, payroll_regime: string} $input */
        $input = $request->validated();
        $custom = $this->customFields->validate('legal_entity', $this->customInput($request), true, $this->permissions($request));

        $entity = DB::transaction(function () use ($input, $custom): LegalEntity {
            $entity = $this->entities->create($input);
            $this->customFields->save('legal_entity', $entity->id, $custom);

            return $entity;
        });

        return $this->respond($entity, 201);
    }

    public function update(LegalEntityRequest $request, string $id): JsonResponse
    {
        $entity = $this->find($request, $id, 'org.legal_entities.update');
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        /** @var array{name?: string, trade_name?: string|null, tax_id?: string, currency?: string, timezone?: string, payroll_regime?: string} $input */
        $input = $request->validated();
        $custom = $this->customFields->validate('legal_entity', $this->customInput($request), false, $this->permissions($request));

        $entity = DB::transaction(function () use ($entity, $input, $ifMatch, $custom): LegalEntity {
            $entity = $this->entities->update($entity, $input, $ifMatch === '' ? null : (int) $ifMatch);
            $this->customFields->save('legal_entity', $entity->id, $custom);

            return $entity;
        });

        return $this->respond($entity);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $entity = $this->find($request, $id, 'org.legal_entities.archive');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->entities->transition($entity, 'archive', isset($data['reason']) ? (string) $data['reason'] : null));
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $entity = $this->find($request, $id, 'org.legal_entities.restore');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->entities->transition($entity, 'restore', isset($data['reason']) ? (string) $data['reason'] : null));
    }

    /**
     * Busca la empresa dentro del alcance del permiso: fuera de alcance es «no encontrado» (RN-SEG-04).
     */
    private function find(Request $request, string $id, string $permission): LegalEntity
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = LegalEntity::query()->whereKey($id);
        $user = $request->user();
        if ($user !== null) {
            $allowed = $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
            if ($allowed !== null) {
                $query->whereIn('id', $allowed);
            }
        }

        /** @var LegalEntity */
        return $query->firstOrFail();
    }

    /**
     * CFG-4 · Campos personalizados del formulario (los sensibles exigen su permiso por campo).
     *
     * @return array<string, mixed>
     */
    private function customInput(Request $request): array
    {
        $value = $request->input('custom_fields', []);

        return is_array($value) ? $value : [];
    }

    private function permissions(Request $request): ?EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : null;
    }

    private function respond(LegalEntity $entity, int $status = 200): JsonResponse
    {
        $data = $this->entities->present($entity) + ['custom_fields' => (object) $this->customFields->read('legal_entity', $entity->id, $this->permissions(request()))];

        return response()->json(['data' => $data], $status)
            ->header('ETag', '"'.$entity->lock_version.'"');
    }
}
