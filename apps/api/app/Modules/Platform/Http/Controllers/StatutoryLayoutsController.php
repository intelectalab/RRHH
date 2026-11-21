<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\LegalUpdates\StatutoryFileLayouts;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** DEC-10 · Carga y verificación de estructuras de archivos oficiales desde la consola. */
final class StatutoryLayoutsController extends Controller
{
    public function __construct(private readonly StatutoryFileLayouts $layouts) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->layouts->load($request->all(), $this->actor($request))], 201);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->layouts->verify($id, $this->actor($request))]);
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
