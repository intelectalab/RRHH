<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Application\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CFG-10 · Catálogos maestros y de la organización. La lectura la usan todos los formularios
 * (basta la sesión); editar exige settings.catalogs.update.
 */
final class CatalogController
{
    private const CODE_RULE = 'regex:/^[a-z][a-z0-9_]{1,59}$/';

    public function __construct(private readonly CatalogService $catalogs) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalogs->catalogs()]);
    }

    public function items(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'include_archived' => ['nullable', 'in:0,1,true,false'],
            'parent' => ['nullable', 'string', 'max:20'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();
        $r = $this->catalogs->items($code, $asOf, in_array($data['include_archived'] ?? '0', ['1', 'true'], true), $data['parent'] ?? null);

        return response()->json(['data' => $r['items'], 'meta' => ['catalog' => $r['catalog'], 'as_of' => $asOf, 'locale' => app()->getLocale()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', self::CODE_RULE],
            'name' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->catalogs->createCatalog($data['code'], $data['name'])], 201);
    }

    public function storeItem(Request $request, string $code): JsonResponse
    {
        // Un catálogo de otra organización no existe aquí: 404 antes de validar el cuerpo.
        $this->catalogs->ensureKnown($code);
        $data = $request->validate([
            'code' => ['required', 'string', self::CODE_RULE],
            'labels' => ['required', 'array'],
            'labels.es' => ['required', 'string', 'max:200'],
            'labels.en' => ['nullable', 'string', 'max:200'],
            'labels.pt' => ['nullable', 'string', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'metadata' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->catalogs->addItem($code, $data['code'], $this->labels($data['labels']), (int) ($data['sort_order'] ?? 0), $data['metadata'] ?? null)], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'labels' => ['sometimes', 'array'],
            'labels.es' => ['sometimes', 'required', 'string', 'max:200'],
            'labels.en' => ['nullable', 'string', 'max:200'],
            'labels.pt' => ['nullable', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        return response()->json(['data' => $this->catalogs->updateItem($id, isset($data['labels']) ? $this->labels($data['labels']) : null, isset($data['sort_order']) ? (int) $data['sort_order'] : null)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalogs->setActive($id, false, $this->reason($request))]);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalogs->setActive($id, true, $this->reason($request))]);
    }

    private function reason(Request $request): ?string
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $data['reason'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function labels(array $raw): array
    {
        $out = [];
        foreach (CatalogService::LOCALES as $l) {
            if (isset($raw[$l]) && is_string($raw[$l]) && trim($raw[$l]) !== '') {
                $out[$l] = trim($raw[$l]);
            }
        }

        return $out;
    }
}
