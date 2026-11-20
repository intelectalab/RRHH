<?php

namespace App\Modules\HelpCenter\Http\Controllers;

use App\Modules\HelpCenter\Application\HelpEscalations;
use App\Modules\HelpCenter\Application\HelpInsights;
use App\Modules\HelpCenter\Application\HelpLibrary;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AYU-2 · AYU-3 · AYU-5 · AYU-6 · AYU-7 · Centro de ayuda: guías por rol y módulo, ayuda de cada pantalla y
 * ayudas de campo, búsqueda y valoración, consultas derivadas, recorridos y novedades.
 */
final class HelpCenterController
{
    private const KINDS = ['guide', 'screen', 'field', 'tour', 'release_note', 'faq', 'policy'];

    public function __construct(
        private readonly HelpLibrary $help,
        private readonly ModuleGate $modules,
        private readonly HelpEscalations $escalations,
        private readonly HelpInsights $insights,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array'],
            'filter.kind' => ['sometimes', Rule::in(self::KINDS)],
            'filter.module' => ['sometimes', 'string', 'max:40'],
            'screen_key' => ['sometimes', 'string', 'max:80'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[kind]', 'filter[module]', 'screen_key']);
        }
        /** @var array<string, string> $filter */
        $filter = (array) ($request->query('filter') ?? []);
        $screen = $request->query('screen_key');
        $rows = $this->help->articles($this->permissions($request), $this->modules->activeModules(), $this->locale($request), [
            'kind' => $filter['kind'] ?? null,
            'module' => $filter['module'] ?? null,
            'screen_key' => is_string($screen) ? $screen : null,
        ]);

        return response()->json(['data' => array_map(fn (array $a): array => $this->strip($a), $rows), 'meta' => ['total' => count($rows)]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->strip($this->help->article($id, $this->permissions($request), $this->modules->activeModules(), $this->locale($request)))]);
    }

    public function screen(Request $request, string $screenKey): JsonResponse
    {
        $data = $this->help->screen($screenKey, $this->permissions($request), $this->modules->activeModules(), $this->locale($request));
        $data['articles'] = array_map(fn (array $a): array => $this->strip($a), $data['articles']);
        $data['fields'] = (object) array_map(fn (array $a): array => $this->strip($a), (array) $data['fields']);

        return response()->json(['data' => $data]);
    }

    public function search(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'screen_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['q', 'screen_key']);
        }
        $screen = $request->query('screen_key');
        $rows = $this->help->search((string) $request->query('q'), is_string($screen) ? $screen : null, $this->permissions($request), $this->modules->activeModules(), $this->locale($request));

        return response()->json(['data' => array_map(fn (array $a): array => $this->strip($a), $rows), 'meta' => ['total' => count($rows)]]);
    }

    public function feedback(Request $request, string $id): JsonResponse
    {
        // Primero el artículo (404 si no se ve, también el de otra organización) y después los datos.
        $this->help->article($id, $this->permissions($request), $this->modules->activeModules(), $this->locale($request));
        $data = $request->validate([
            'is_helpful' => ['required', 'boolean'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'screen_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $screen = $data['screen_key'] ?? null;

        return response()->json(['data' => $this->help->feedback($id, (bool) $data['is_helpful'], $data['comment'] ?? null, is_string($screen) ? $screen : null, $this->permissions($request), $this->modules->activeModules(), $this->locale($request))]);
    }

    public function whatsNew(Request $request): JsonResponse
    {
        $rows = $this->help->whatsNew($this->permissions($request), $this->modules->activeModules(), $this->locale($request));

        return response()->json(['data' => array_map(fn (array $a): array => $this->strip($a), $rows), 'meta' => ['total' => count($rows)]]);
    }

    public function tours(Request $request): JsonResponse
    {
        $rows = $this->help->tours($this->permissions($request), $this->modules->activeModules(), $this->locale($request));

        return response()->json(['data' => array_map(fn (array $a): array => $this->strip($a), $rows), 'meta' => ['total' => count($rows)]]);
    }

    public function completeTour(Request $request, string $tourKey): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['completed', 'skipped'])]]);

        return response()->json(['data' => $this->help->completeTour($tourKey, (string) ($data['status'] ?? 'completed'), $this->permissions($request), $this->modules->activeModules(), $this->locale($request))]);
    }

    public function escalations(Request $request): JsonResponse
    {
        $rows = $this->escalations->list($this->userId($request));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function escalate(Request $request): JsonResponse
    {
        /** @var array{question: string, target?: string|null, screen_key?: string|null, consulted_articles?: list<string>|null} $data */
        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:2000'],
            'target' => ['sometimes', 'nullable', Rule::in(['hr', 'platform_support'])],
            'screen_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'consulted_articles' => ['sometimes', 'nullable', 'array', 'max:10'],
            'consulted_articles.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => $this->escalations->create($data, $this->userId($request), $this->permissions($request), $this->modules->activeModules(), $this->locale($request))], 201);
    }

    public function insights(): JsonResponse
    {
        return response()->json(['data' => $this->insights->summary()]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user('ayni');
        abort_if($user === null, 401);

        return (string) $user->getAuthIdentifier();
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private function strip(array $article): array
    {
        unset($article['version_id']);

        return $article;
    }

    private function locale(Request $request): string
    {
        $user = $request->user();
        $locale = is_object($user) && isset($user->locale) && is_string($user->locale) ? $user->locale : app()->getLocale();

        return in_array($locale, (array) config('help.locales'), true) ? $locale : (string) config('help.fallback_locale', 'es');
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
