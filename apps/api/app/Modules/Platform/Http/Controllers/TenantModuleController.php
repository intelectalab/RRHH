<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Application\Tenants\TenantModules;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SAS-5 · Módulos comerciales de una organización desde la consola.
 */
final class TenantModuleController
{
    public function __construct(
        private readonly TenantAdministration $tenants,
        private readonly TenantModules $modules,
    ) {}

    public function index(string $id): JsonResponse
    {
        $tenant = $this->tenants->find($id);

        return response()->json(['data' => $this->modules->summary((string) $tenant->id)]);
    }

    public function activate(Request $request, string $id, string $code): JsonResponse
    {
        $tenant = $this->tenants->find($id);
        $activated = $this->modules->activate((string) $tenant->id, $code, $this->actor($request));

        return response()->json(['data' => $this->modules->summary((string) $tenant->id), 'meta' => ['activated' => $activated]]);
    }

    public function deactivate(Request $request, string $id, string $code): JsonResponse
    {
        $tenant = $this->tenants->find($id);
        $this->modules->deactivate((string) $tenant->id, $code, $this->actor($request));

        return response()->json(['data' => $this->modules->summary((string) $tenant->id)]);
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
