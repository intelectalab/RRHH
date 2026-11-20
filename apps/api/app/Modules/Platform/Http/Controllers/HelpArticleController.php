<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use App\Modules\Platform\Application\Help\ProductHelpInsights;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AYU-1 · Ayuda del producto desde la consola: artículos por módulo, rol y pantalla, versiones y publicación.
 */
final class HelpArticleController
{
    public function __construct(
        private readonly ProductHelpArticles $articles,
        private readonly ProductHelpInsights $insights,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'filter.module' => ['sometimes', 'string', 'max:40'],
            'filter.locale' => ['sometimes', Rule::in((array) config('help.locales'))],
            'filter.kind' => ['sometimes', Rule::in(ProductHelpArticles::KINDS)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]', 'filter[module]', 'filter[locale]', 'filter[kind]', 'q']);
        }
        /** @var array<string, string> $filter */
        $filter = (array) ($request->query('filter') ?? []);
        $q = $request->query('q');
        $rows = $this->articles->list($filter, is_string($q) ? $q : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,119}$/'],
            'locale' => ['required', Rule::in((array) config('help.locales'))],
            ...$this->contentRules(true),
        ]);

        return response()->json(['data' => $this->articles->create($data, $this->actor($request))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->show($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->update($id, $request->validate($this->contentRules(false)), $this->actor($request))]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->publish($id, $this->actor($request))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->articles->archive($id, $this->actor($request))]);
    }

    public function insights(): JsonResponse
    {
        return response()->json(['data' => $this->insights->summary()]);
    }

    public function escalations(): JsonResponse
    {
        $rows = $this->insights->escalations();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function contentRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:2', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => [$required, 'string', 'min:2', 'max:20000'],
            'kind' => ['sometimes', Rule::in(ProductHelpArticles::KINDS)],
            'module_code' => [$required, 'string', 'max:40'],
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['string', 'regex:/^[a-z_]{2,40}$/'],
            'screen_keys' => ['sometimes', 'array', 'max:40'],
            'screen_keys.*' => ['string', 'regex:/^[a-z_][a-z0-9_.]{1,79}$/'],
            'product_version' => ['sometimes', 'string', 'regex:/^[0-9A-Za-z._-]{1,20}$/'],
        ];
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
