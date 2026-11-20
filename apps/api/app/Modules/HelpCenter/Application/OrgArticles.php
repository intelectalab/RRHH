<?php

namespace App\Modules\HelpCenter\Application;

use App\Modules\HelpCenter\Domain\OrgArticleStateMachine;
use App\Modules\HelpCenter\Infrastructure\Models\HelpOrgArticle;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Notifications\Notifier;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AYU-4 · Artículos y políticas de la organización (reglamento interno, vacaciones, permisos).
 *
 * - El artículo guarda el borrador de trabajo; publicar copia título y texto a una versión nueva e inmutable
 *   (kb_article_versions). Los usuarios leen siempre la última versión publicada.
 * - Audiencia (RN-AYU-03): roles (código de sistema o id de rol), empresas y sedes. Cada lista vacía no
 *   restringe; con varias, la persona debe cumplir todas (rol y empresa y sede).
 * - Responsable y fecha de revisión: la tarea diaria marca «por revisar» lo que vence dentro del parámetro
 *   ayuda.revision_anticipacion_dias y avisa al responsable una sola vez (RN-AYU-04). Sigue visible.
 */
final class OrgArticles
{
    public const NOTIFY_REVIEW = 'help.article_review_due';

    private const EDITABLE = ['title', 'body', 'kb_category_id', 'locale', 'audience', 'owner_user_id', 'review_on'];

    public function __construct(
        private readonly OrgArticleStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly ParameterResolver $parameters,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{status?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, ?string $q): array
    {
        $status = $filters['status'] ?? null;
        $rows = HelpOrgArticle::query()
            ->when($status !== null, fn ($w) => $w->where('status', $status))
            ->when($q !== null && $q !== '', fn ($w) => $w->where('title', 'ilike', '%'.$q.'%'))
            ->orderByRaw("CASE status WHEN 'in_review' THEN 0 WHEN 'draft' THEN 1 WHEN 'published' THEN 2 ELSE 3 END")->orderBy('title')
            ->get()->all();
        $ids = array_values(array_map(fn (HelpOrgArticle $a): string => $a->id, $rows));
        $latest = $this->latestVersions($ids);
        $votes = $this->votes($ids);

        return array_values(array_map(fn (HelpOrgArticle $a): array => $this->present($a, $latest[$a->id] ?? null, $votes[$a->id] ?? [0, 0]), $rows));
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $a = $this->find($id);
        $versions = DB::table('kb_article_versions')->where('kb_article_id', $a->id)->orderByDesc('version_number')->get();

        return $this->present($a, $versions->first(), $this->votes([$a->id])[$a->id] ?? [0, 0]) + [
            'versions' => array_values($versions->map(fn (object $v): array => [
                'version_number' => (int) $v->version_number,
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
    public function create(array $input): array
    {
        $data = $this->clean($input);

        return DB::transaction(function () use ($data): array {
            $a = HelpOrgArticle::create($data + [
                'slug' => Str::slug(Str::limit((string) $data['title'], 60, '')).'-'.Str::lower(Str::random(6)),
                'status' => 'draft',
                'version' => 1,
            ]);
            $after = $this->show($a->id);
            $this->audit->record('help_org_article.created', 'kb_article', $a->id, null, $this->auditable($after));

            return $after;
        });
    }

    /**
     * El estado cambia solo por acciones (regla 3): «status», «version» y «slug» no se editan.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, ?string $ifMatch): array
    {
        $a = $this->find($id);
        if ($ifMatch !== null && $ifMatch !== '' && trim($ifMatch, '"W/') !== (string) $a->lock_version) {
            throw new AyniException('version_conflict', 409, (string) trans('errors.version_conflict'));
        }
        $changes = $this->clean($input, $a);

        return DB::transaction(function () use ($a, $changes): array {
            $before = $this->show($a->id);
            if ($changes !== []) {
                $a->fill($changes)->save();
            }
            $after = $this->show($a->id);
            $this->audit->record('help_org_article.updated', 'kb_article', $a->id, $this->auditable($before), $this->auditable($after));

            return $after;
        });
    }

    /**
     * Publica el borrador: con cambios desde la última versión crea otra (la anterior se conserva); sin cambios
     * solo cambia el estado (al revisar un artículo «por revisar» que sigue vigente).
     *
     * @return array<string, mixed>
     */
    public function publish(string $id, ?string $userId): array
    {
        $a = $this->find($id);
        $next = $this->machine->getNextState($a->status, OrgArticleStateMachine::PUBLISH);
        if ($a->owner_user_id === null) {
            throw ValidationException::withMessages(['owner_user_id' => [(string) trans('help.owner_required')]]);
        }

        return DB::transaction(function () use ($a, $next, $userId): array {
            $last = DB::table('kb_article_versions')->where('kb_article_id', $a->id)->orderByDesc('version_number')->first();
            $changed = $last === null || $last->title !== $a->title || $last->body !== $a->body;
            $number = $last === null ? 1 : (int) $last->version_number + ($changed ? 1 : 0);
            if ($changed) {
                DB::table('kb_article_versions')->insert([
                    'tenant_id' => $a->getAttribute('tenant_id'),
                    'kb_article_id' => $a->id,
                    'version_number' => $number,
                    'title' => $a->title,
                    'body' => $a->body,
                    'published_by_user_id' => $userId,
                    'published_at' => now(),
                ]);
            }
            $before = $a->status;
            $a->status = $next;
            $a->version = $number;
            if ($changed || $a->published_at === null) {
                $a->published_at = now();
            }
            $a->save();
            $this->audit->record('help_org_article.published', 'kb_article', $a->id, ['status' => $before], ['status' => $next, 'version' => $number]);

            return $this->show($a->id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(string $id): array
    {
        $a = $this->find($id);
        $next = $this->machine->getNextState($a->status, OrgArticleStateMachine::ARCHIVE);

        return DB::transaction(function () use ($a, $next): array {
            $before = $a->status;
            $a->status = $next;
            $a->save();
            $this->audit->record('help_org_article.archived', 'kb_article', $a->id, ['status' => $before], ['status' => $next]);

            return $this->show($a->id);
        });
    }

    /**
     * Tarea diaria (help:review-articles): marca «por revisar» los artículos publicados cuya fecha de revisión
     * cae dentro de la anticipación y avisa al responsable. Lo ya marcado no se vuelve a avisar.
     */
    public function markReviewDue(string $day): int
    {
        $days = (int) ($this->parameters->value('ayuda.revision_anticipacion_dias') ?? 7);
        $limit = Carbon::parse($day)->addDays(max(0, $days))->toDateString();
        $count = 0;
        foreach (HelpOrgArticle::query()->where('status', 'published')->whereNotNull('review_on')->where('review_on', '<=', $limit)->get() as $a) {
            DB::transaction(function () use ($a): void {
                $a->status = $this->machine->getNextState($a->status, OrgArticleStateMachine::REVIEW);
                $a->save();
                $this->audit->record('help_org_article.review_due', 'kb_article', $a->id, ['status' => 'published'], ['status' => $a->status, 'review_on' => $this->date($a->review_on)]);
            });
            if ($a->owner_user_id !== null) {
                $this->notifier->notify(self::NOTIFY_REVIEW, [$a->owner_user_id], ['title' => $a->title, 'review_on' => (string) $this->date($a->review_on)], 'kb_article', $a->id);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Artículos de la organización que ve el usuario en el centro de ayuda (publicados o por revisar), con su
     * última versión publicada y la audiencia aplicada (RN-AYU-03).
     *
     * @return list<array<string, mixed>>
     */
    public function visibleFor(?string $userId, EffectivePermissions $permissions, string $locale): array
    {
        $rows = DB::table('kb_articles as a')
            ->join('kb_article_versions as v', fn ($j) => $j->on('v.kb_article_id', '=', 'a.id')->on('v.tenant_id', '=', 'a.tenant_id'))
            ->whereRaw('v.version_number = (SELECT max(v2.version_number) FROM kb_article_versions v2 WHERE v2.kb_article_id = a.id)')
            ->whereIn('a.status', ['published', 'in_review'])->whereNull('a.deleted_at')
            ->orderBy('v.title')
            ->get(['a.id', 'a.slug', 'a.locale', 'a.status', 'a.audience', 'a.review_on', 'v.id as version_id', 'v.version_number', 'v.title', 'v.body', 'v.published_at']);
        if ($rows->isEmpty()) {
            return [];
        }
        $me = $this->audienceOf($userId, $permissions);
        $out = [];
        foreach ($rows as $row) {
            $audience = is_string($row->audience) ? (array) json_decode($row->audience, true) : [];
            if (! $this->matches($audience, $me)) {
                continue;
            }
            $out[] = [
                'id' => (string) $row->id,
                'slug' => (string) $row->slug,
                'source' => 'organization',
                'kind' => 'policy',
                'title' => (string) $row->title,
                'summary' => null,
                'body' => (string) $row->body,
                'module_code' => 'core',
                'screen_keys' => [],
                'locale' => (string) $row->locale,
                'translation_missing' => (string) $row->locale !== $locale,
                'product_version' => '',
                'version_number' => (int) $row->version_number,
                'version_id' => (string) $row->version_id,
                'review_due' => $row->status === 'in_review',
                'published_at' => Carbon::parse((string) $row->published_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Roles (códigos de sistema e ids), empresas y sedes de las relaciones vigentes del usuario.
     *
     * @return array{roles: list<string>, legal_entity_ids: list<string>, site_ids: list<string>}
     */
    private function audienceOf(?string $userId, EffectivePermissions $permissions): array
    {
        if ($userId === null) {
            return ['roles' => array_values($permissions->systemRoles), 'legal_entity_ids' => [], 'site_ids' => []];
        }
        $today = now()->toDateString();
        $roleIds = DB::table('user_roles')->where('user_id', $userId)
            ->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->pluck('role_id')->map(fn ($id): string => (string) $id)->all();
        $places = DB::table('employments as e')->join('users as u', 'u.person_id', '=', 'e.person_id')
            ->leftJoin('employment_assignments as ea', fn ($j) => $j->on('ea.employment_id', '=', 'e.id')->where('ea.valid_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('ea.valid_to')->orWhere('ea.valid_to', '>=', $today)))
            ->where('u.id', $userId)->whereNull('e.deleted_at')->whereIn('e.status', ['active', 'suspended', 'terminating', 'pending'])
            ->get(['e.legal_entity_id', 'ea.site_id']);

        return [
            'roles' => array_values(array_unique([...$permissions->systemRoles, ...$roleIds])),
            'legal_entity_ids' => array_values(array_unique(array_map(fn (object $p): string => (string) $p->legal_entity_id, $places->all()))),
            'site_ids' => array_values(array_filter(array_unique(array_map(fn (object $p): string => (string) ($p->site_id ?? ''), $places->all())))),
        ];
    }

    /**
     * @param  array<string, mixed>  $audience
     * @param  array{roles: list<string>, legal_entity_ids: list<string>, site_ids: list<string>}  $me
     */
    private function matches(array $audience, array $me): bool
    {
        foreach (['roles', 'legal_entity_ids', 'site_ids'] as $dimension) {
            $wanted = array_values(array_filter((array) ($audience[$dimension] ?? []), 'is_string'));
            if ($wanted !== [] && array_intersect($wanted, $me[$dimension]) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida y normaliza la entrada (solo campos editables).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function clean(array $input, ?HelpOrgArticle $current = null): array
    {
        if (array_key_exists('category_id', $input)) {
            $input['kb_category_id'] = $input['category_id'];
        }
        $data = array_intersect_key($input, array_flip(self::EDITABLE));
        $errors = [];
        if (array_key_exists('audience', $data)) {
            $audience = (array) ($data['audience'] ?? []);
            $roles = array_values(array_unique(array_map('strval', (array) ($audience['roles'] ?? []))));
            $entities = array_values(array_unique(array_map('strval', (array) ($audience['legal_entity_ids'] ?? []))));
            $sites = array_values(array_unique(array_map('strval', (array) ($audience['site_ids'] ?? []))));
            $uuid = fn (string $v): bool => preg_match('/^[0-9a-f-]{36}$/i', $v) === 1;
            $codes = array_filter($roles, fn (string $r): bool => ! $uuid($r));
            $ids = array_filter($roles, $uuid);
            $knownCodes = DB::table('system_roles')->where('realm', 'tenant')->whereIn('code', $codes === [] ? [''] : $codes)->count();
            $knownIds = DB::table('roles')->whereNull('deleted_at')->whereIn('id', $ids === [] ? [Str::uuid()->toString()] : $ids)->count();
            if ($knownCodes !== count($codes) || $knownIds !== count($ids)) {
                $errors['audience.roles'] = [(string) trans('help.audience_invalid')];
            }
            if (array_filter($entities, fn ($v) => ! $uuid($v)) !== [] || DB::table('legal_entities')->whereNull('deleted_at')->whereIn('id', $entities === [] ? [Str::uuid()->toString()] : $entities)->count() !== count($entities)) {
                $errors['audience.legal_entity_ids'] = [(string) trans('help.audience_invalid')];
            }
            if (array_filter($sites, fn ($v) => ! $uuid($v)) !== [] || DB::table('sites')->whereNull('deleted_at')->whereIn('id', $sites === [] ? [Str::uuid()->toString()] : $sites)->count() !== count($sites)) {
                $errors['audience.site_ids'] = [(string) trans('help.audience_invalid')];
            }
            $data['audience'] = ['roles' => $roles, 'legal_entity_ids' => $entities, 'site_ids' => $sites];
        }
        if (($data['owner_user_id'] ?? null) !== null) {
            $owner = (string) $data['owner_user_id'];
            if (preg_match('/^[0-9a-f-]{36}$/i', $owner) !== 1 || ! DB::table('users')->where('id', $owner)->whereNull('deleted_at')->where('status', 'active')->exists()) {
                $errors['owner_user_id'] = [(string) trans('help.owner_invalid')];
            }
        }
        if (($data['kb_category_id'] ?? null) !== null) {
            $category = (string) $data['kb_category_id'];
            if (preg_match('/^[0-9a-f-]{36}$/i', $category) !== 1 || ! DB::table('kb_categories')->where('id', $category)->whereNull('deleted_at')->exists()) {
                $errors['category_id'] = [(string) trans('help.category_invalid')];
            }
        }
        if (($data['review_on'] ?? null) !== null && Carbon::parse((string) $data['review_on'])->lt(now()->startOfDay()) && $current?->review_on !== $data['review_on']) {
            $errors['review_on'] = [(string) trans('help.review_on_past')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function find(string $id): HelpOrgArticle
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new ModelNotFoundException;
        }

        return HelpOrgArticle::query()->findOrFail($id);
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
        $out = [];
        foreach (DB::table('kb_article_versions')->whereIn('kb_article_id', $ids)->orderBy('version_number')->get() as $v) {
            $out[(string) $v->kb_article_id] = $v;
        }

        return $out;
    }

    /**
     * Valoraciones «¿te sirvió?» por artículo: [sí, no].
     *
     * @param  list<string>  $ids
     * @return array<string, array{0: int, 1: int}>
     */
    private function votes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('kb_article_feedback')->whereIn('kb_article_id', $ids)
            ->groupBy('kb_article_id')->selectRaw('kb_article_id, count(*) FILTER (WHERE is_helpful) AS yes, count(*) FILTER (WHERE NOT is_helpful) AS no')->get() as $r) {
            $out[(string) $r->kb_article_id] = [(int) $r->yes, (int) $r->no];
        }

        return $out;
    }

    /**
     * @param  array{0: int, 1: int}  $votes
     * @return array<string, mixed>
     */
    private function present(HelpOrgArticle $a, ?\stdClass $latest, array $votes): array
    {
        $days = (int) ($this->parameters->value('ayuda.revision_anticipacion_dias') ?? 7);
        $reviewOn = $this->date($a->review_on);
        $owner = $a->owner_user_id === null ? null : DB::table('users')->where('id', $a->owner_user_id)->value('display_name');

        return [
            'id' => $a->id,
            'title' => $a->title,
            'category_id' => $a->kb_category_id,
            'body' => $a->body,
            'locale' => $a->locale,
            'audience' => [
                'roles' => array_values((array) ($a->audience['roles'] ?? [])),
                'legal_entity_ids' => array_values((array) ($a->audience['legal_entity_ids'] ?? [])),
                'site_ids' => array_values((array) ($a->audience['site_ids'] ?? [])),
            ],
            'owner_user_id' => $a->owner_user_id,
            'owner_name' => $owner === null ? null : (string) $owner,
            'review_on' => $reviewOn,
            'version' => $latest === null ? 0 : (int) $latest->version_number,
            'status' => $a->status,
            'has_unpublished_changes' => $latest === null || $latest->title !== $a->title || $latest->body !== $a->body,
            'review_due' => $a->status === 'in_review' || ($reviewOn !== null && $a->status === 'published' && $reviewOn <= now()->addDays(max(0, $days))->toDateString()),
            'published_at' => $a->published_at?->toIso8601String(),
            'helpful_count' => $votes[0],
            'not_helpful_count' => $votes[1],
            'allowed_actions' => $this->machine->userActions($a->status),
            'lock_version' => $a->lock_version,
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private function auditable(array $article): array
    {
        unset($article['versions'], $article['helpful_count'], $article['not_helpful_count'], $article['allowed_actions']);

        return $article;
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
