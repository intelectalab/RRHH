<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Web\ModulePublication;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WEB-2 · Módulos comerciales a la venta: publicarlos o retirarlos es una acción, no un PATCH (regla 3).
 */
final class ModuleController
{
    public function __construct(private readonly ModulePublication $modules) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->modules->list()]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->modules->set($code, true, $this->actor($request))]);
    }

    public function unpublish(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->modules->set($code, false, $this->actor($request))]);
    }

    private function actor(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user('platform');
    }
}
