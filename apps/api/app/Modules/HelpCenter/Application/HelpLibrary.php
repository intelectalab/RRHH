<?php

namespace App\Modules\HelpCenter\Application;

use App\Shared\Help\HelpScreens;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AYU-2 · AYU-3 · AYU-5 · AYU-7 · Centro de ayuda de la organización: muestra la última versión publicada de la
 * ayuda del producto que el usuario puede ver (RN-AYU-01) y los artículos de su organización (AYU-4, RN-AYU-03):
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

    /** Plegado de tildes y mayúsculas para la búsqueda (AYU-5): «Vacación» encuentra «vacaciones». */
    private const FOLD_FROM = 'áéíóúüñàèìòùâêîôûãõçÁÉÍÓÚÜÑÀÈÌÒÙÂÊÎÔÛÃÕÇ';

    private const FOLD_TO = 'aeiouunaeiouaeiouaocaeiouunaeiouaeiouaoc';

    public function __construct(
        private readonly HelpScreens $screens,
        private readonly OrgArticles $org,
    ) {}

    /**
     * @param  array<int, string>  $modules  módulos activos de la organización
     * @param  array{kind?: string|null, module?: string|null, screen_key?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function articles(EffectivePermissions $permissions, array $modules, string $locale, array $filters = []): array
    {
        $out = [];
        foreach ($this->all($permissions, $modules, $locale) as $article) {
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
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new ModelNotFoundException;
        }
        $slug = DB::table('help_articles')->where('id', $id)->whereNull('deleted_at')->value('slug');
        foreach ($slug === null ? [] : $this->visible($permissions, $modules, $locale) as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }
        foreach ($slug === null && in_array('core', $modules, true) ? $this->org->visibleFor($this->userId(), $permissions, $locale) : [] as $article) {
            if ($article['id'] === $id) {
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
        $config = self::TS_CONFIG[$locale] ?? 'spanish';
        $folded = $this->fold($q);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folded).'%';
        $fold = fn (string $expr): string => "translate(lower({$expr}), '".self::FOLD_FROM."', '".self::FOLD_TO."')";
        $doc = fn (string $title, string $body): string => $fold("{$title} || ' ' || {$body}");
        $scored = [];

        $byVersion = array_column($this->visible($permissions, $modules, $locale), null, 'version_id');
        if ($byVersion !== []) {
            $rows = DB::table('help_article_versions as v')->join('help_articles as a', 'a.id', '=', 'v.help_article_id')
                ->whereIn('v.id', array_keys($byVersion))
                ->where(fn ($w) => $w->whereRaw('to_tsvector(?::regconfig, '.$doc('v.title', 'v.body').') @@ websearch_to_tsquery(?::regconfig, ?)', [$config, $config, $folded])
                    ->orWhereRaw($fold('v.title').' LIKE ?', [$like])->orWhereRaw($fold("coalesce(a.summary, '')").' LIKE ?', [$like]))
                ->selectRaw('v.id, ts_rank(to_tsvector(?::regconfig, '.$doc('v.title', 'v.body').'), websearch_to_tsquery(?::regconfig, ?)) AS rank', [$config, $config, $folded])
                ->get();
            foreach ($rows as $r) {
                $scored[] = [(float) $r->rank, $byVersion[(string) $r->id]];
            }
        }

        $org = in_array('core', $modules, true) ? $this->org->visibleFor($this->userId(), $permissions, $locale) : [];
        $orgByVersion = array_column($org, null, 'version_id');
        if ($orgByVersion !== []) {
            $rows = DB::table('kb_article_versions as v')->whereIn('v.id', array_keys($orgByVersion))
                ->where(fn ($w) => $w->whereRaw("to_tsvector('spanish', ".$doc('v.title', 'v.body').") @@ websearch_to_tsquery('spanish', ?)", [$folded])
                    ->orWhereRaw($fold('v.title').' LIKE ?', [$like]))
                ->selectRaw("v.id, ts_rank(to_tsvector('spanish', ".$doc('v.title', 'v.body')."), websearch_to_tsquery('spanish', ?)) AS rank", [$folded])
                ->get();
            foreach ($rows as $r) {
                // Lo propio de la organización prevalece para sus políticas (M-AYU §13): a igual relevancia, primero.
                $scored[] = [(float) $r->rank + 0.0001, $orgByVersion[(string) $r->id]];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $ranked = array_slice(array_map(fn (array $x): array => $x[1], $scored), 0, 20);
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
     * AYU-7 · Recorridos guiados del usuario: uno por rol de sistema (y módulo contratado), con su estado.
     *
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    public function tours(EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $tours = array_values(array_filter($this->visible($permissions, $modules, $locale), fn (array $a): bool => $a['kind'] === 'tour'));
        $user = $this->userId();
        $progress = $user === null || $tours === [] ? [] : DB::table('help_tour_progress')->where('user_id', $user)
            ->whereIn('tour_key', array_column($tours, 'slug'))->pluck('status', 'tour_key')->all();

        return array_map(fn (array $t): array => $t + ['status' => (string) ($progress[$t['slug']] ?? 'pending')], $tours);
    }

    /**
     * Marca un recorrido como visto o saltado; se puede volver a abrir desde la ayuda.
     *
     * @param  array<int, string>  $modules
     * @return array{tour_key: string, status: string}
     */
    public function completeTour(string $key, string $status, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $user = $this->userId();
        foreach ($user === null ? [] : $this->tours($permissions, $modules, $locale) as $tour) {
            if ($tour['slug'] === $key) {
                DB::table('help_tour_progress')->upsert(
                    [['tenant_id' => TenancyScope::requireTenant(), 'user_id' => $user, 'tour_key' => $key, 'status' => $status, 'completed_at' => now()]],
                    ['tenant_id', 'user_id', 'tour_key'], ['status', 'completed_at', 'updated_at'],
                );

                return ['tour_key' => $key, 'status' => $status];
            }
        }
        throw new ModelNotFoundException;
    }

    /**
     * AYU-7 · Novedades de versión de los módulos contratados, de la más reciente a la más antigua.
     *
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    public function whatsNew(EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $notes = array_values(array_filter($this->visible($permissions, $modules, $locale), fn (array $a): bool => $a['kind'] === 'release_note'));
        usort($notes, fn (array $a, array $b): int => [$b['product_version'], $b['published_at']] <=> [$a['product_version'], $a['published_at']]);

        return $notes;
    }

    /**
     * AYU-5 · «¿Te sirvió?»: una valoración por usuario y artículo (la última cuenta), solo sobre artículos que
     * el usuario ve.
     *
     * @param  array<int, string>  $modules
     * @return array{article_id: string, source: string, is_helpful: bool}
     */
    public function feedback(string $id, bool $helpful, ?string $comment, ?string $screenKey, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $article = $this->article($id, $permissions, $modules, $locale);
        $user = $this->userId();
        $tenant = TenancyScope::requireTenant();
        [$table, $column] = $article['source'] === 'organization' ? ['kb_article_feedback', 'kb_article_id'] : ['help_article_feedback', 'help_article_id'];
        $values = ['is_helpful' => $helpful, 'comment' => $comment === null || $comment === '' ? null : mb_substr($comment, 0, 1000)];
        if ($table === 'help_article_feedback') {
            $values['screen_key'] = $screenKey;
        }
        DB::transaction(function () use ($table, $column, $id, $user, $tenant, $values): void {
            $existing = $user === null ? null : DB::table($table)->where($column, $id)->where('user_id', $user)->lockForUpdate()->value('id');
            if ($existing !== null) {
                DB::table($table)->where('id', $existing)->update($values);
            } else {
                DB::table($table)->insert(['tenant_id' => $tenant, $column => $id, 'user_id' => $user] + $values);
            }
        });

        return ['article_id' => $id, 'source' => (string) $article['source'], 'is_helpful' => $helpful];
    }

    /**
     * Ayuda del producto y artículos de la organización visibles.
     *
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    private function all(EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $org = in_array('core', $modules, true) ? $this->org->visibleFor($this->userId(), $permissions, $locale) : [];

        return [...$this->visible($permissions, $modules, $locale), ...$org];
    }

    private function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ã' => 'a', 'õ' => 'o', 'ç' => 'c']);
    }

    private function userId(): ?string
    {
        $id = auth('ayni')->id();

        return $id === null ? null : (string) $id;
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
                'source' => 'product',
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
                'review_due' => false,
                'published_at' => Carbon::parse((string) $row->published_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    private function hasCustomRole(): bool
    {
        $user = $this->userId();
        if ($user === null) {
            return false;
        }

        return DB::table('user_roles as ur')->join('roles as r', fn ($j) => $j->on('r.id', '=', 'ur.role_id')->on('r.tenant_id', '=', 'ur.tenant_id'))
            ->where('ur.user_id', $user)->whereNull('r.deleted_at')->whereNull('r.system_role_code')->exists();
    }
}
