<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DOC-1 · Carpetas del legajo y tipos documentales (confidencialidad, visibilidad, revisión y vencimiento).
 */
final class DocumentCategoryController
{
    public function __construct(private readonly DocumentCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $archived = in_array((string) $request->query('include_archived', 'false'), ['true', '1'], true);
        $tree = $this->catalog->tree($archived);

        return response()->json(['data' => $tree, 'meta' => ['total' => count($tree)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return response()->json(['data' => $this->catalog->present($this->catalog->create($data))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = $this->catalog->find($id);
        $data = $request->validate($this->rules(false));

        return response()->json(['data' => $this->catalog->present($this->catalog->update($category, $data))]);
    }

    public function archive(string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->present($this->catalog->archive($this->catalog->find($id)))]);
    }

    public function restore(string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->present($this->catalog->restore($this->catalog->find($id)))]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => [$req, 'string', 'max:120'],
            'parent_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_classification' => ['sometimes', 'in:public,internal,confidential,restricted'],
            'required_permission_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scope' => ['sometimes', 'in:employee,company'],
            'visible_to_employee' => ['sometimes', 'boolean'],
            'requires_review' => ['sometimes', 'boolean'],
            'requires_expiry' => ['sometimes', 'boolean'],
            'expiry_notice_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
