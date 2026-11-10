<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CFG-1 · CFG-2 · CFG-3 · Parámetros de la organización agrupados por sección (prefijo del código),
 * con valor vigente, origen (empresa, organización o valor por defecto) e historial de vigencias.
 * Solo aparecen los grupos de módulos contratados.
 */
final class SettingsController
{
    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly ModuleGate $modules,
        private readonly DataScope $scope,
    ) {}

    public function groups(): JsonResponse
    {
        $rows = DB::table('parameter_definitions')->orderBy('code')->get(['code', 'module_code', 'name', 'description']);
        $groups = [];
        foreach ($rows as $r) {
            if (! $this->modules->isEnabled((string) $r->module_code)) {
                continue;
            }
            $g = explode('.', (string) $r->code)[0];
            $groups[$g] ??= ['group' => $g, 'module' => (string) $r->module_code, 'count' => 0, 'parameters' => []];
            $groups[$g]['count']++;
            // Para el buscador de ajustes del centro de configuración.
            $groups[$g]['parameters'][] = ['code' => $r->code, 'name' => $r->name, 'description' => $r->description];
        }

        return response()->json(['data' => array_values($groups)]);
    }

    public function show(Request $request, string $group): JsonResponse
    {
        $legalEntityId = $this->legalEntity($request);

        return response()->json(['data' => $this->present($group, $legalEntityId, $request->query('as_of'))]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $this->definitions($group); // 404 si el grupo no existe o su módulo no está contratado
        $data = $request->validate([
            'values' => ['required', 'array', 'min:1', 'max:50'],
            'values.*.code' => ['required', 'string', 'max:120'],
            'values.*.value' => ['present'],
            'values.*.valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $legalEntityId = $this->legalEntity($request);
        foreach ($data['values'] as $i => $v) {
            if (! str_starts_with((string) $v['code'], $group.'.')) {
                abort(404);
            }
            $this->parameters->set((string) $v['code'], $v['value'], $legalEntityId === null ? 'organization' : 'legal_entity', $legalEntityId, $v['valid_from'] ?? null, $data['reason'] ?? null);
        }

        return response()->json(['data' => $this->present($group, $legalEntityId, null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $group, ?string $legalEntityId, mixed $asOf): array
    {
        $date = is_string($asOf) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) === 1 ? $asOf : null;
        $items = [];
        foreach ($this->definitions($group) as $row) {
            /** @var array{code: string, name: string, description: string|null, data_type: string, options: string|null, default_value: string|null, allowed_scopes: string} $d */
            $d = (array) $row;
            $scopes = array_values(array_filter(explode(',', trim((string) $d['allowed_scopes'], '{}'))));
            $effective = $this->parameters->resolve((string) $d['code'], $legalEntityId, $date);
            $items[] = [
                'code' => $d['code'],
                'name' => $d['name'],
                'description' => $d['description'],
                'data_type' => $d['data_type'],
                'options' => $d['options'] === null ? null : json_decode((string) $d['options'], true),
                'default' => $d['default_value'] === null ? null : json_decode((string) $d['default_value'], true),
                'value' => $effective['value'],
                'source' => $effective['source'],
                'valid_from' => $effective['valid_from'],
                'company_scope' => in_array('legal_entity', $scopes, true),
                'organization_value' => $legalEntityId !== null ? $this->parameters->value((string) $d['code'], null, $date) : null,
                'history' => $this->parameters->history((string) $d['code'], $legalEntityId === null ? 'organization' : 'legal_entity', $legalEntityId),
            ];
        }

        return ['group' => $group, 'legal_entity_id' => $legalEntityId, 'as_of' => $date ?? now()->toDateString(), 'parameters' => $items];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function definitions(string $group): Collection
    {
        abort_unless(preg_match('/^[a-z_]{2,40}$/', $group) === 1, 404);
        $rows = DB::table('parameter_definitions')->where('code', 'like', $group.'.%')->orderBy('code')->get();
        abort_if($rows->isEmpty() || ! $this->modules->isEnabled((string) $rows->first()->module_code), 404);

        return $rows;
    }

    /**
     * Empresa elegida (CFG-2): debe estar dentro del alcance del usuario; si no, 404.
     */
    private function legalEntity(Request $request): ?string
    {
        $id = $request->input('legal_entity_id', $request->query('legal_entity_id'));
        if (! is_string($id) || $id === '') {
            return null;
        }
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $allowed = $this->scope->legalEntityIds($userId, 'settings.parameters.view');
        abort_unless(DB::table('legal_entities')->where('id', $id)->exists() && ($allowed === null || in_array($id, $allowed, true)), 404);

        return $id;
    }
}
