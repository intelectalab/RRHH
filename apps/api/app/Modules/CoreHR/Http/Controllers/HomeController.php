<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeRegistry;
use App\Shared\Http\Responses\ProblemDetailsResponse;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Permissions\TeamLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * COL-12 · MSS-1 · Inicio según el rol: tablero de RRHH, tablero del manager o el inicio del colaborador.
 * Cada número se calcula en el servidor con el alcance aplicado en la consulta; las tarjetas de módulos no
 * contratados no viajan.
 */
final class HomeController
{
    /** Alcances que no son de RRHH: quien solo ve a su equipo o a sí mismo usa el tablero del manager. */
    private const NARROW_SCOPES = ['team', 'own'];

    public function __construct(
        private readonly HomeRegistry $registry,
        private readonly DataScope $scope,
        private readonly TeamLine $team,
        private readonly PermissionResolver $resolver,
        private readonly ModuleGate $modules,
    ) {}

    /** GET /home · Vistas de inicio disponibles para el usuario y la que se abre por defecto. */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $permissions = $this->resolver->for($userId);
        $views = [];
        if ($this->moduleOn('people.employments.view') && $permissions->has('people.employments.view')
            && ! in_array($permissions->scopeFor('people.employments.view'), self::NARROW_SCOPES, true)) {
            $views[] = HomeCard::HR;
        }
        if ($this->moduleOn('people.team.view') && $permissions->has('people.team.view') && $this->team->hasReports($userId, $this->today()->toDateString())) {
            $views[] = HomeCard::MANAGER;
        }
        $views[] = 'employee';

        return response()->json(['data' => ['views' => $views, 'default' => $views[0]]]);
    }

    /** GET /home/hr · COL-12. */
    public function hr(Request $request): JsonResponse
    {
        if (in_array($this->permissions($request)->scopeFor('people.employments.view'), self::NARROW_SCOPES, true)) {
            // Con alcance propio o de equipo no hay tablero de RRHH: su inicio es el del colaborador o el del manager.
            return ProblemDetailsResponse::make(403, 'forbidden', null, null, [], ['permission' => 'people.employments.view']);
        }

        return $this->board($request, HomeCard::HR, []);
    }

    /** GET /home/manager · MSS-1 (solo reportes directos e indirectos, RN-MSS-01). */
    public function manager(Request $request): JsonResponse
    {
        $permissions = $this->permissions($request);
        $members = $this->team->members($this->userId($request), $this->today()->toDateString(), $permissions->has('people.team.view_indirect'));

        return $this->board($request, HomeCard::MANAGER, array_keys($members), ['team_size' => count($members), 'direct' => count(array_filter($members, fn (int $d) => $d === 1))]);
    }

    /**
     * @param  list<string>  $team
     * @param  array<string, mixed>  $meta
     */
    private function board(Request $request, string $audience, array $team, array $meta = []): JsonResponse
    {
        $today = $this->today();
        $context = new HomeContext($audience, $this->userId($request), $request, $this->permissions($request), $today, $team, $this->scope);

        return response()->json([
            'data' => ['audience' => $audience, 'as_of' => $today->toDateString(), 'cards' => $this->registry->collect($context)],
            'meta' => (object) $meta,
        ]);
    }

    private function moduleOn(string $permission): bool
    {
        $module = $this->modules->moduleOf($permission);

        return $module !== null && $this->modules->isEnabled($module);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay();
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : $this->resolver->for($this->userId($request));
    }
}
