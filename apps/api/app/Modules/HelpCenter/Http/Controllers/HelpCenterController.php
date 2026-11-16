<?php

namespace App\Modules\HelpCenter\Http\Controllers;

use App\Modules\HelpCenter\Application\HelpLibrary;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AYU-2 · AYU-3 · Centro de ayuda: guías por rol y módulo, ayuda de cada pantalla y ayudas de campo.
 */
final class HelpCenterController
{
    private const KINDS = ['guide', 'screen', 'field', 'tour', 'release_note', 'faq'];

    public function __construct(
        private readonly HelpLibrary $help,
        private readonly ModuleGate $modules,
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
