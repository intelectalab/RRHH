<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\LegalEntityService;
use App\Modules\Organization\Http\Requests\LegalEntityRequest;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\LegalEntityRegime;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Query\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
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
        $query = LegalEntity::query();
        $this->scope->applyLegalEntity($query, $request, 'org.legal_entities.view', 'id');

        $status = TableQuery::filter($request, 'status');
        if ($status === 'active' || $status === 'archived') {
            $query->where('status', $status === 'active' ? 'active' : 'inactive');
        }
        // UI-4: filtro por país de la tabla de empresas.
        $country = TableQuery::filter($request, 'country');
        if ($country !== null) {
            $query->where('country_code', strtoupper($country));
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('legal_name', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('tax_id', 'like', addcslashes($q, '%_\\').'%'));
        }

        $page = (new TableQuery(
            ['name' => 'legal_name', 'tax_id' => 'tax_id', 'country' => 'country_code', 'status' => 'status', 'created_at' => 'created_at'],
            ['status', 'country'],
        ))->withCustomFields('legal_entity', 'legal_entities.id', $this->permissions($request))->page($query, $request, 'name');
        $ids = $page['ids'];
        $models = LegalEntity::query()->whereIn('id', $ids)->get()->keyBy('id');
        $custom = $this->customFields->readMany('legal_entity', $ids, $this->permissions($request));

        return response()->json([
            'data' => array_values(array_map(
                fn (LegalEntity $e) => $this->entities->present($e) + ['custom_fields' => (object) ($custom[$e->id] ?? [])],
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($e) => $e instanceof LegalEntity),
            )),
            'meta' => $page['meta'],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'org.legal_entities.view'));
    }

    public function store(LegalEntityRequest $request): JsonResponse
    {
        /** @var array{name: string, trade_name?: string|null, tax_id: string, country: string, currency: string, timezone: string, payroll_regime: string, locale?: string|null, tax_regime?: string|null, economic_activity_code?: string|null, address?: string|null, district_code?: string|null, legal_representative_name?: string|null} $input */
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

        /** @var array{name?: string, trade_name?: string|null, tax_id?: string, currency?: string, timezone?: string, payroll_regime?: string, locale?: string|null, tax_regime?: string|null, economic_activity_code?: string|null, address?: string|null, district_code?: string|null, legal_representative_name?: string|null} $input */
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

    /** CFG-2 · Logo de la empresa (imagen pública de la organización; 404 si no tiene). */
    public function logo(Request $request, string $id): Response
    {
        $logo = $this->entities->logo($this->find($request, $id, 'org.legal_entities.view'));
        abort_if($logo === null, 404);

        return response($logo['contents'], 200, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        $entity = $this->find($request, $id, 'org.legal_entities.update');
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $entity = $this->entities->setLogo($entity, (string) $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(), (string) $request->user()?->getAuthIdentifier());

        return $this->respond($entity);
    }

    public function removeLogo(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->entities->removeLogo($this->find($request, $id, 'org.legal_entities.update')));
    }

    /** ORG-1 · Historia del régimen laboral de la empresa con su vigencia (la más reciente primero). */
    public function regimes(Request $request, string $id): JsonResponse
    {
        $entity = $this->find($request, $id, 'org.legal_entities.view');
        $today = now()->toDateString();
        $names = DB::table('labor_regimes')->pluck('name', 'code');
        $rows = $entity->regimes()->orderByDesc('valid_from')->get()->map(fn (LegalEntityRegime $r) => [
            'labor_regime_code' => $r->labor_regime_code,
            'labor_regime_name' => $names[$r->labor_regime_code] ?? $r->labor_regime_code,
            'valid_from' => $r->valid_from->toDateString(),
            'valid_to' => $r->valid_to?->toDateString(),
            'current' => $r->valid_from->toDateString() <= $today && ($r->valid_to === null || $r->valid_to->toDateString() >= $today),
        ]);

        return response()->json(['data' => $rows->values()->all()]);
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
