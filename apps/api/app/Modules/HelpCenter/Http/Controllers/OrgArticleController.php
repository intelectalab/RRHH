<?php

namespace App\Modules\HelpCenter\Http\Controllers;

use App\Modules\HelpCenter\Application\OrgArticles;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AYU-4 · Artículos y políticas de la organización (Configuración → Ayuda y asistente).
 */
final class OrgArticleController
{
    public function __construct(private readonly OrgArticles $articles) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::in(['draft', 'published', 'in_review', 'archived'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]', 'q']);
        }
        /** @var array<string, string> $filter */
        $filter = (array) ($request->query('filter') ?? []);
        $q = $request->query('q');
        $rows = $this->articles->list(['status' => $filter['status'] ?? null], is_string($q) ? $q : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->articles->create($request->validate($this->rules(true)))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->update($id, $request->validate($this->rules(false)), $request->header('If-Match'))]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $user = $request->user('ayni');

        return response()->json(['data' => $this->articles->publish($id, $user === null ? null : (string) $user->getAuthIdentifier())]);
    }

    public function archive(string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->archive($id)]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:2', 'max:200'],
            'body' => [$required, 'string', 'min:2', 'max:50000'],
            'category_id' => ['sometimes', 'nullable', 'string'],
            'locale' => ['sometimes', Rule::in((array) config('help.locales'))],
            'audience' => ['sometimes', 'nullable', 'array'],
            'audience.roles' => ['sometimes', 'array', 'max:30'],
            'audience.roles.*' => ['string', 'max:40'],
            'audience.legal_entity_ids' => ['sometimes', 'array', 'max:50'],
            'audience.legal_entity_ids.*' => ['string', 'max:40'],
            'audience.site_ids' => ['sometimes', 'array', 'max:200'],
            'audience.site_ids.*' => ['string', 'max:40'],
            'owner_user_id' => [$required, 'nullable', 'string'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
