<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Trash\TrashRegistry;
use App\Shared\Trash\TrashSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CFG-9 · Papelera: registros eliminados de los módulos que el usuario puede ver, con quién los
 * eliminó, cuándo y cuándo se depurarán (parámetro papelera.dias_restauracion).
 */
final class TrashController
{
    public function __construct(
        private readonly TrashRegistry $registry,
        private readonly PermissionResolver $resolver,
        private readonly ModuleGate $modules,
        private readonly ParameterResolver $parameters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $days = $this->parameters->value('papelera.dias_restauracion');
        $items = [];
        foreach ($this->sources($request) as $source) {
            foreach ($source->items($search === '' ? null : $search, 100) as $item) {
                $items[] = ['type' => $source->type()] + $item;
            }
        }
        $deleters = $this->deleters(array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['deleted_by'] = $deleters[$item['id']] ?? null;
            $item['purge_on'] = is_numeric($days) ? Carbon::parse($item['deleted_at'])->addDays((int) $days)->toDateString() : null;
        }
        unset($item);
        usort($items, fn ($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']));

        return response()->json(['data' => $items, 'meta' => ['retention_days' => is_numeric($days) ? (int) $days : null]]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        foreach ($this->sources($request) as $source) {
            if ($source->owns($id)) {
                $source->restore($id);

                return response()->json(['data' => ['id' => $id, 'type' => $source->type(), 'restored' => true]]);
            }
        }
        abort(404);
    }

    /**
     * Fuentes cuyo módulo está contratado y cuyo permiso propio tiene el usuario.
     *
     * @return array<int, TrashSource>
     */
    private function sources(Request $request): array
    {
        $perms = $this->resolver->for((string) $request->user()?->getAuthIdentifier());

        return array_values(array_filter($this->registry->all(), function (TrashSource $s) use ($perms): bool {
            $module = $this->modules->moduleOf($s->permission());

            return $perms->has($s->permission()) && $module !== null && $this->modules->isEnabled($module);
        }));
    }

    /**
     * Quién eliminó cada registro (última auditoría de borrado).
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function deleters(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('audit_events as a')->join('users as u', 'u.id', '=', 'a.actor_user_id')
            ->whereIn('a.entity_id', $ids)->where('a.action', 'like', '%.deleted')
            ->orderBy('a.occurred_at')->get(['a.entity_id', 'u.display_name'])
            ->mapWithKeys(fn ($r) => [(string) $r->entity_id => (string) $r->display_name])->all();
    }
}
