<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Settings\PlatformNotices;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\JsonResponse;

/**
 * SAS-13 · Avisos de la plataforma vigentes para la organización en curso (los muestra su interfaz).
 */
final class SystemNoticesController
{
    public function __invoke(PlatformNotices $notices): JsonResponse
    {
        return response()->json(['data' => $notices->activeFor(TenancyScope::requireTenant())]);
    }
}
