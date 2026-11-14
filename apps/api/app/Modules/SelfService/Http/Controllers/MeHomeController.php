<?php

namespace App\Modules\SelfService\Http\Controllers;

use App\Shared\Exceptions\ModuleNotEnabledException;
use App\Shared\Inbox\InboxRegistry;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\SelfService\HomeCardRegistry;
use App\Shared\SelfService\OwnEmployments;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ESS-1 · GET /me/home · Inicio del colaborador.
 *
 * Reúne, solo para el usuario que pregunta (RN-ESS-01), sus relaciones laborales, la elegida
 * (?employment_id, RN-ESS-04), los pendientes de su bandeja (WFL-9) y las tarjetas que registra cada módulo
 * contratado (RN-ESS-02). No acepta identificadores de otras personas: uno ajeno responde 404.
 */
final class MeHomeController
{
    /** Pendientes que muestra el inicio; el resto se ve en la bandeja. */
    private const PENDING_LIMIT = 5;

    public function __invoke(
        Request $request,
        OwnEmployments $own,
        HomeCardRegistry $cards,
        InboxRegistry $inbox,
        PermissionResolver $resolver,
        ModuleGate $modules,
    ): JsonResponse {
        if (! $modules->isEnabled('core')) {
            throw new ModuleNotEnabledException('core');
        }
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);
        $userId = (string) $user->getAuthIdentifier();
        $permissions = $resolver->for($userId);

        $employments = $own->list($userId);
        $current = $own->pick($userId, $request->query('employment_id'));
        $pending = $inbox->collect($userId, $permissions, self::PENDING_LIMIT);

        return response()->json(['data' => [
            'employments' => $employments,
            'employment' => $current,
            'pending' => [
                'total' => $pending['total'],
                'counts' => $pending['counts'],
                'items' => $pending['items'],
            ],
            // Una relación cesada solo muestra lo de lectura (RN-ESS-05): las tarjetas lo indican con read_only.
            'cards' => $current === null ? [] : $cards->collect($userId, $current['id'], $permissions),
        ]]);
    }
}
