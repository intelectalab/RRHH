<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Shared\Calendar\CalendarRegistry;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CFG-8 · Calendario central de RRHH: une las fuentes que registran los módulos (feriados, tareas…)
 * entre dos fechas. Solo aparecen las fuentes de módulos contratados cuyo permiso tiene el usuario,
 * y cada una filtra por su alcance. Cada elemento trae el enlace a su registro.
 */
final class CalendarController
{
    /** Rango máximo de una consulta (una vista mensual con semanas de borde cabe holgada). */
    private const MAX_DAYS = 100;

    public function __construct(
        private readonly CalendarRegistry $registry,
        private readonly PermissionResolver $resolver,
        private readonly ModuleGate $modules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $available = $this->sources($request);
        $wanted = array_filter(explode(',', (string) $request->query('sources', '')));
        foreach ($wanted as $key) {
            if (! isset($available[$key]) && ! isset($this->registry->all()[$key])) {
                throw new AyniException('invalid_filter', 400, (string) trans('settings.calendar.unknown_source', ['source' => $key]));
            }
        }

        $permissions = $request->attributes->get('effective_permissions');
        $permissions = $permissions instanceof EffectivePermissions ? $permissions : null;
        $items = [];
        foreach ($available as $key => $source) {
            if ($wanted !== [] && ! in_array($key, $wanted, true)) {
                continue;
            }
            foreach ($source->items($from, $to, $request, $permissions) as $item) {
                $items[] = ['source' => $key] + $item;
            }
        }
        usort($items, fn (array $a, array $b): int => [$a['starts_on'], $a['source'], $a['title']] <=> [$b['starts_on'], $b['source'], $b['title']]);

        return response()->json([
            'data' => $items,
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'sources' => array_keys($available)],
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $parse = function (string $name) use ($request): CarbonImmutable {
            $value = (string) $request->query($name, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ($d = CarbonImmutable::createFromFormat('!Y-m-d', $value)) === null || $d->toDateString() !== $value) {
                throw new AyniException('invalid_filter', 400, (string) trans('settings.calendar.invalid_range'));
            }

            return $d;
        };
        $from = $parse('from');
        $to = $parse('to');
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            throw new AyniException('invalid_filter', 400, (string) trans('settings.calendar.invalid_range'));
        }

        return [$from, $to];
    }

    /**
     * Fuentes de módulos contratados cuyo permiso tiene el usuario.
     *
     * @return array<string, CalendarSource>
     */
    private function sources(Request $request): array
    {
        $perms = $this->resolver->for((string) $request->user()?->getAuthIdentifier());

        return array_filter($this->registry->all(), function (CalendarSource $s) use ($perms): bool {
            $module = $this->modules->moduleOf($s->permission());

            return $perms->has($s->permission()) && $module !== null && $this->modules->isEnabled($module);
        });
    }
}
