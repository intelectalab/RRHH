<?php

namespace App\Modules\Platform\Application\Help;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Domain\HelpArticleStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Help\HelpScreens;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AYU-1 · Ayuda del producto que GestiaHR redacta y publica desde la consola. Cada artículo (uno por slug e
 * idioma) declara módulo, roles destinatarios, claves de pantalla y versión del producto. El artículo guarda
 * el borrador de trabajo; publicar copia ese borrador a una versión nueva e inmutable (help_article_versions)
 * y conserva las anteriores consultables (RN-AYU-02). Las organizaciones solo leen la última versión
 * publicada, así que editar un artículo publicado no cambia lo que ven hasta volver a publicar.
 */
final class ProductHelpArticles
{
    public const KINDS = ['guide', 'screen', 'field', 'tour', 'release_note', 'faq'];

    private const EDITABLE = ['title', 'summary', 'body', 'kind', 'module_code', 'roles', 'screen_keys', 'product_version'];

    public function __construct(
        private readonly ConsoleAudit $audit,
        private readonly HelpArticleStateMachine $machine,
        private readonly HelpScreens $screens,
    ) {}

    /**
     * @param  array{status?: string|null, module?: string|null, locale?: string|null, kind?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, ?string $q): array
    {
        $status = $filters['status'] ?? null;
        $module = $filters['module'] ?? null;
        $locale = $filters['locale'] ?? null;
        $kind = $filters['kind'] ?? null;
        $rows = DB::table('help_articles')->whereNull('deleted_at')
            ->when($status !== null, fn ($w) => $w->where('status', $status))
            ->when($module !== null, fn ($w) => $w->where('module_code', $module))
            ->when($locale !== null, fn ($w) => $w->where('locale', $locale))
            ->when($kind !== null, fn ($w) => $w->where('kind', $kind))
            ->when($q !== null && $q !== '', fn ($w) => $w->where(fn ($s) => $s->where('title', 'ilike', '%'.$q.'%')->orWhere('slug', 'ilike', '%'.$q.'%')))
            ->orderBy('module_code')->orderBy('slug')->orderBy('locale')->get();
        $latest = $this->latestVersions(array_values(array_map(fn (\stdClass $r): string => (string) $r->id, $rows->all())));

        return array_values($rows->map(fn ($row): array => $this->present($row, $latest[(string) $row->id] ?? null))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = $this->find($id);
        $versions = DB::table('help_article_versions')->where('help_article_id', $row->id)->orderByDesc('version_number')->get();

        return $this->present($row, $versions->first()) + [
            'versions' => array_values($versions->map(fn ($v): array => [
                'version_number' => (int) $v->version_number,
                'product_version' => (string) $v->product_version,
                'title' => (string) $v->title,
                'body' => (string) $v->body,
                'published_at' => Carbon::parse((string) $v->published_at)->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?PlatformUser $actor): array
    {
        $this->validateReferences($input);
        if (DB::table('help_articles')->where('slug', $input['slug'])->where('locale', $input['locale'])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['slug' => [(string) trans('platform.help.duplicate_slug')]]);
        }

        return DB::transaction(function () use ($input, $actor): array {
            $id = (string) DB::table('help_articles')->insertGetId([
                'slug' => $input['slug'],
                'locale' => $input['locale'],
                'title' => $input['title'],
                'summary' => $input['summary'] ?? null,
                'body' => $input['body'],
                'kind' => $input['kind'] ?? 'guide',
                'module_code' => $input['module_code'],
                'roles' => HelpScreens::toPg(array_values($input['roles'] ?? [])),
                'screen_keys' => HelpScreens::toPg(array_values($input['screen_keys'] ?? [])),
                'product_version' => $input['product_version'] ?? (string) config('help.product_version'),
                'status' => 'draft',
            ]);
            $after = $this->show($id);
            $this->audit->record('help_article.created', $actor, 'help_article', $id, null, $after);

            return $after;
        });
    }

    /**
     * El estado cambia solo por acciones (regla 3): «status», «slug» y «locale» no se editan.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, ?PlatformUser $actor): array
    {
        $row = $this->find($id);
        $this->validateReferences($input);
        $changes = array_intersect_key($input, array_flip(self::EDITABLE));
        foreach (['roles', 'screen_keys'] as $array) {
            if (array_key_exists($array, $changes)) {
                $changes[$array] = HelpScreens::toPg(array_values((array) $changes[$array]));
            }
        }

        return DB::transaction(function () use ($row, $changes, $actor): array {
            $before = $this->show((string) $row->id);
            if ($changes !== []) {
                DB::table('help_articles')->where('id', $row->id)->update($changes);
            }
            $after = $this->show((string) $row->id);
            $this->audit->record('help_article.updated', $actor, 'help_article', (string) $row->id, $before, $after);

            return $after;
        });
    }

    /**
     * Publica el borrador como versión nueva; la anterior queda consultable (RN-AYU-02). Sin cambios desde la
     * última versión no se crea otra (publicar dos veces es idempotente).
     *
     * @return array<string, mixed>
     */
    public function publish(string $id, ?PlatformUser $actor): array
    {
        $row = $this->find($id);
        $next = $this->machine->getNextState((string) $row->status, HelpArticleStateMachine::PUBLISH);

        return DB::transaction(function () use ($row, $next, $actor): array {
            $last = DB::table('help_article_versions')->where('help_article_id', $row->id)->orderByDesc('version_number')->first();
            $changed = $last === null || $last->title !== $row->title || $last->body !== $row->body || $last->product_version !== $row->product_version;
            $now = now();
            if ($changed) {
                DB::table('help_article_versions')->insert([
                    'help_article_id' => $row->id,
                    'version_number' => $last === null ? 1 : ((int) $last->version_number + 1),
                    'product_version' => $row->product_version,
                    'title' => $row->title,
                    'body' => $row->body,
                    'published_at' => $now,
                ]);
            }
            DB::table('help_articles')->where('id', $row->id)->update(['status' => $next, 'published_at' => $changed || $row->published_at === null ? $now : $row->published_at]);
            $after = $this->show((string) $row->id);
            $this->audit->record('help_article.published', $actor, 'help_article', (string) $row->id, ['status' => $row->status], ['status' => $next, 'version_number' => $after['version_number']]);

            return $after;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(string $id, ?PlatformUser $actor): array
    {
        $row = $this->find($id);
        $next = $this->machine->getNextState((string) $row->status, HelpArticleStateMachine::ARCHIVE);

        return DB::transaction(function () use ($row, $next, $actor): array {
            DB::table('help_articles')->where('id', $row->id)->update(['status' => $next]);
            $this->audit->record('help_article.archived', $actor, 'help_article', (string) $row->id, ['status' => $row->status], ['status' => $next]);

            return $this->show((string) $row->id);
        });
    }

    /**
     * Carga la ayuda que acompaña al producto (resources/help) como artículos publicados, solo los que
     * todavía no existen: lo que GestiaHR ya editó en la consola no se pisa. Devuelve cuántos creó.
     */
    public function syncBundled(): int
    {
        /** @var array<string, array{kind: string, module: string, roles: list<string>, screens: list<string>}> $catalog */
        $catalog = require resource_path('help/catalog.php');
        $created = 0;
        foreach ((array) config('help.locales') as $locale) {
            $file = resource_path('help/'.$locale.'.php');
            /** @var array<string, array{title: string, summary?: string, body: string}> $texts */
            $texts = is_file($file) ? require $file : [];
            foreach ($catalog as $slug => $meta) {
                $text = $texts[$slug] ?? null;
                if ($text === null || DB::table('help_articles')->where('slug', $slug)->where('locale', $locale)->whereNull('deleted_at')->exists()) {
                    continue;
                }
                DB::transaction(function () use ($slug, $locale, $meta, $text): void {
                    $now = now();
                    $id = (string) DB::table('help_articles')->insertGetId([
                        'slug' => $slug, 'locale' => $locale, 'title' => $text['title'], 'summary' => $text['summary'] ?? null,
                        'body' => $text['body'], 'kind' => $meta['kind'], 'module_code' => $meta['module'],
                        'roles' => HelpScreens::toPg($meta['roles']), 'screen_keys' => HelpScreens::toPg($meta['screens']),
                        'product_version' => (string) config('help.product_version'), 'status' => 'published', 'published_at' => $now,
                    ]);
                    DB::table('help_article_versions')->insert([
                        'help_article_id' => $id, 'version_number' => 1, 'product_version' => (string) config('help.product_version'),
                        'title' => $text['title'], 'body' => $text['body'], 'published_at' => $now,
                    ]);
                });
                $created++;
            }
        }

        return $created;
    }

    /**
     * Roles de organización, pantallas y módulo existentes (la consola no publica referencias inventadas).
     *
     * @param  array<string, mixed>  $input
     */
    private function validateReferences(array $input): void
    {
        $errors = [];
        if (array_key_exists('module_code', $input) && ! DB::table('modules')->where('code', $input['module_code'])->exists()) {
            $errors['module_code'] = [(string) trans('platform.help.unknown_module')];
        }
        if (array_key_exists('roles', $input)) {
            $valid = DB::table('system_roles')->where('realm', 'tenant')->pluck('code')->map(fn ($c): string => (string) $c)->all();
            if (array_diff((array) $input['roles'], $valid) !== []) {
                $errors['roles'] = [(string) trans('platform.help.unknown_role')];
            }
        }
        if (array_key_exists('screen_keys', $input)) {
            foreach ((array) $input['screen_keys'] as $key) {
                if (! $this->screens->exists((string) $key)) {
                    $errors['screen_keys'] = [(string) trans('platform.help.unknown_screen')];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function find(string $id): \stdClass
    {
        $row = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('help_articles')->where('id', $id)->whereNull('deleted_at')->first() : null;
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, \stdClass>
     */
    private function latestVersions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('help_article_versions')->whereIn('help_article_id', $ids)
            ->whereRaw('version_number = (SELECT max(v2.version_number) FROM help_article_versions v2 WHERE v2.help_article_id = help_article_versions.help_article_id)')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->help_article_id] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row, ?\stdClass $latest): array
    {
        return [
            'id' => (string) $row->id,
            'slug' => (string) $row->slug,
            'locale' => (string) $row->locale,
            'title' => (string) $row->title,
            'summary' => $row->summary,
            'body' => (string) $row->body,
            'kind' => (string) $row->kind,
            'module_code' => (string) $row->module_code,
            'roles' => HelpScreens::fromPg($row->roles),
            'screen_keys' => HelpScreens::fromPg($row->screen_keys),
            'product_version' => (string) $row->product_version,
            'status' => (string) $row->status,
            'version_number' => $latest === null ? null : (int) $latest->version_number,
            'has_unpublished_changes' => $latest === null || $latest->title !== $row->title || $latest->body !== $row->body || $latest->product_version !== $row->product_version,
            'published_at' => $row->published_at === null ? null : Carbon::parse((string) $row->published_at)->toIso8601String(),
            'created_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $row->updated_at)->toIso8601String(),
        ];
    }
}
