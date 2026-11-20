<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Web\PublicCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WEB-2 · WEB-4 · Catálogo y cotización públicos del sitio comercial (sin sesión, solo lectura).
 */
final class PublicCatalogController
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->catalog()])->header('Cache-Control', 'public, max-age=60');
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'employees' => ['required', 'integer', 'min:1', 'max:1000000'],
            'cycle' => ['sometimes', 'nullable', Rule::in(['monthly', 'annual'])],
            'addons' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $addons = array_values(array_filter(array_map('trim', explode(',', (string) ($data['addons'] ?? ''))), fn (string $c): bool => $c !== ''));

        return response()->json(['data' => $this->catalog->quote(
            (string) $data['plan'], (int) $data['employees'], isset($data['cycle']) ? (string) $data['cycle'] : null, $addons,
        )]);
    }
}
