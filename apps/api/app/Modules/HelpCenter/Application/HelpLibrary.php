<?php

namespace App\Modules\HelpCenter\Application;

use App\Shared\Help\HelpScreens;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AYU-2 · AYU-3 · Centro de ayuda de la organización: muestra la última versión publicada de la ayuda del
 * producto que el usuario puede ver (RN-AYU-01):
 *  - del módulo contratado y activo;
 *  - dirigida a alguno de sus roles de sistema (sin roles = para todos; un rol personalizado ve los
 *    artículos dirigidos a roles solo si son de una pantalla que puede abrir);
 *  - de pantallas que puede abrir (sin pantallas = general).
 * En el idioma del usuario o, si falta la traducción, en español con aviso (RN-AYU-07). No depende del
 * asistente de IA.
 */
final class HelpLibrary
{
    private const FIELD_PREFIX = 'campo.';

    private const TS_CONFIG = ['es' => 'spanish', 'en' => 'english', 'pt' => 'portuguese'];

    public function __construct(private readonly HelpScreens $screens) {}

    /**
     * @param  array<int, string>  $modules  módulos activos de la organización
     * @param  array{kind?: string|null, module?: string|null, screen_key?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function articles(EffectivePermissions $permissions, array $modules, string $locale, array $filters = []): array
    {
        $out = [];
        foreach ($this->visible($permissions, $modules, $locale) as $article) {
            if (($filters['kind'] ?? null) !== null && $article['kind'] !== $filters['kind']) {
                continue;
            }
            if (($filters['module'] ?? null) !== null && $article['module_code'] !== $filters['module']) {
                continue;
            }
            if (($filters['screen_key'] ?? null) !== null && ! in_array($filters['screen_key'], $article['screen_keys'], true)) {
                continue;
            }
            $out[] = $article;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<string, mixed>
     */
    public function article(string $id, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $slug = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1
            ? DB::table('help_articles')->where('id', $id)->whereNull('deleted_at')->value('slug')
            : null;
        foreach ($slug === null ? [] : $this->visible($permissions, $modules, $locale) as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }
        throw new ModelNotFoundException;
    }

    /**
     * Ayuda de una pantalla: primero su ayuda propia, después las guías que la incluyen, y aparte las
     * ayudas de campo (por clave de campo). 404 si la pantalla no existe o el usuario no puede abrirla.
     *
     * @param  array<int, string>  $modules
     * @return array<string, mixed>
     */
    public function screen(string $key, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        if (! $this->screens->canOpen($key, $permissions, $modules)) {
            throw new ModelNotFoundException;
        }
        $articles = [];
        $fields = [];
        foreach ($this->articles($permissions, $modules, $locale, ['screen_key' => $key]) as $article) {
            if ($article['kind'] === 'field') {
                $prefix = self::FIELD_PREFIX.$key.'.';
                if (str_starts_with($article['slug'], $prefix)) {
                    $fields[substr($article['slug'], strlen($prefix))] = $article;
                }

                continue;
            }
            $articles[] = $article;
        }
        usort($articles, fn (array $a, array $b): int => [$a['kind'] === 'screen' ? 0 : 1, $a['title']] <=> [$b['kind'] === 'screen' ? 0 : 1, $b['title']]);

        return ['screen_key' => $key, 'articles' => $articles, 'fields' => (object) $fields];
    }

    /**
     * Búsqueda de texto completo en la ayuda visible para el usuario. Sin resultados, se registra el texto,
     * la pantalla y el rol, sin datos de la persona (RN-AYU-05).
     *
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    public function search(string $q, ?string $screenKey, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $visible = $this->visible($permissions, $modules, $locale);
        $ids = array_column($visible, 'version_id');
        $ranked = [];
        if ($ids !== []) {
            $config = self::TS_CONFIG[$locale] ?? 'spanish';
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $rows = DB::table('help_article_versions as v')->join('help_articles as a', 'a.id', '=', 'v.help_article_id')
                ->whereIn('v.id', $ids)
                ->where(fn ($w) => $w->whereRaw("to_tsvector(?::regconfig, v.title || ' ' || v.body) @@ websearch_to_tsquery(?::regconfig, ?)", [$config, $config, $q])
                    ->orWhere('v.title', 'ilike', $like)->orWhere('a.summary', 'ilike', $like))
                ->orderByRaw("ts_rank(to_tsvector(?::regconfig, v.title || ' ' || v.body), websearch_to_tsquery(?::regconfig, ?)) DESC", [$config, $config, $q])
                ->limit(20)->pluck('v.id')->map(fn ($id): string => (string) $id)->all();
            $byVersion = array_column($visible, null, 'version_id');
            foreach ($rows as $versionId) {
                $ranked[] = $byVersion[$versionId];
            }
        }
        if ($ranked === []) {
            DB::table('help_search_queries')->insert([
                'tenant_id' => TenancyScope::requireTenant(),
                'query_text' => mb_substr($q, 0, 200),
                'screen_key' => $screenKey,
                'role_code' => $permissions->systemRoles[0] ?? null,
                'result_count' => 0,
                'source' => 'search',
            ]);
        }

        return $ranked;
    }

    /**
     * Artículos visibles con la última versión publicada, uno por slug, en el idioma más cercano.
     *
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    private function visible(EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $fallback = (string) config('help.fallback_locale', 'es');
        $rows = DB::table('help_articles as a')
            ->join('help_article_versions as v', 'v.help_article_id', '=', 'a.id')
            ->whereRaw('v.version_number = (SELECT max(v2.version_number) FROM help_article_versions v2 WHERE v2.help_article_id = a.id)')
            ->where('a.status', 'published')->whereNull('a.deleted_at')
            ->whereIn('a.module_code', $modules === [] ? [''] : $modules)
            ->whereIn('a.locale', array_values(array_unique([$locale, $fallback])))
            ->orderBy('a.slug')
            ->get(['a.id', 'a.slug', 'a.locale', 'a.summary', 'a.kind', 'a.module_code', 'a.roles', 'a.screen_keys',
                'v.id as version_id', 'v.version_number', 'v.product_version', 'v.title', 'v.body', 'v.published_at']);

        $bySlug = [];
        foreach ($rows as $row) {
            $current = $bySlug[$row->slug] ?? null;
            if ($current === null || ($row->locale === $locale && $current->locale !== $locale)) {
                $bySlug[$row->slug] = $row;
            }
        }

        $hasCustomRole = $this->hasCustomRole();
        $out = [];
        foreach ($bySlug as $row) {
            $roles = HelpScreens::fromPg($row->roles);
            $screens = HelpScreens::fromPg($row->screen_keys);
            $openable = array_values(array_filter($screens, fn (string $s): bool => $this->screens->canOpen($s, $permissions, $modules)));
            if ($screens !== [] && $openable === []) {
                continue;
            }
            $roleMatch = $roles === [] || array_intersect($roles, $permissions->systemRoles) !== [] || ($hasCustomRole && $screens !== []);
            if (! $roleMatch) {
                continue;
            }
            $out[] = [
                'id' => (string) $row->id,
                'slug' => (string) $row->slug,
                'kind' => (string) $row->kind,
                'title' => (string) $row->title,
                'summary' => $row->summary,
                'body' => (string) $row->body,
                'module_code' => (string) $row->module_code,
                'screen_keys' => $openable,
                'locale' => (string) $row->locale,
                'translation_missing' => $row->locale !== $locale,
                'product_version' => (string) $row->product_version,
                'version_number' => (int) $row->version_number,
                'version_id' => (string) $row->version_id,
                'published_at' => Carbon::parse((string) $row->published_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    private function hasCustomRole(): bool
    {
        $user = auth('ayni')->id();
        if ($user === null) {
            return false;
        }

        return DB::table('user_roles as ur')->join('roles as r', fn ($j) => $j->on('r.id', '=', 'ur.role_id')->on('r.tenant_id', '=', 'ur.tenant_id'))
            ->where('ur.user_id', $user)->whereNull('r.deleted_at')->whereNull('r.system_role_code')->exists();
    }
}
