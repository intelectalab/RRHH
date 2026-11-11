<?php

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Infrastructure\Models\Catalog;
use App\Modules\Settings\Infrastructure\Models\CatalogItem;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * CFG-10 · Catálogos maestros y de la organización.
 *
 * - Maestros (master_catalogs): los mantiene la plataforma con código estable y código oficial;
 *   se actualizan con catalogs:import sin desplegar. Si es_extensible, la organización agrega
 *   elementos propios en catalogs/catalog_items con el mismo código.
 * - Del sistema: listas que ya viven en su propia tabla (monedas, entidades previsionales) y la
 *   de nacionalidades, que reutiliza la de países.
 * - Propios: catálogos creados por la organización.
 *
 * Un elemento no se elimina: se archiva y deja de ofrecerse para registros nuevos (RN-CFG-09).
 */
final class CatalogService
{
    public const LOCALES = ['es', 'en', 'pt'];

    /** Catálogos del sistema que se leen de su propia tabla (no se extienden desde aquí). */
    private const VIRTUAL = ['currencies', 'nationalities', 'pension_funds'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalogs(): array
    {
        $own = Catalog::query()->get()->keyBy('code');
        $ownCounts = CatalogItem::query()->where('is_active', true)->selectRaw('catalog_id, count(*) AS n')->groupBy('catalog_id')->pluck('n', 'catalog_id');
        $masterCounts = DB::table('master_catalog_items')->selectRaw('master_catalog_id, count(*) AS n')->groupBy('master_catalog_id')->pluck('n', 'master_catalog_id');

        $out = [];
        foreach (DB::table('master_catalogs')->orderBy('code')->get() as $m) {
            $ext = $own->get($m->code);
            $out[] = [
                'code' => $m->code,
                'name' => $this->catalogName($m->code, (string) $m->name),
                'description' => $m->description,
                'source' => 'master',
                'is_extensible' => (bool) $m->is_extensible,
                'country_code' => $m->country_code,
                'items_count' => (int) ($masterCounts[$m->id] ?? 0) + (int) ($ext !== null ? ($ownCounts[$ext->id] ?? 0) : 0),
                'own_items_count' => (int) ($ext !== null ? ($ownCounts[$ext->id] ?? 0) : 0),
            ];
        }
        $virtualCounts = [
            'currencies' => DB::table('currencies')->count(),
            'nationalities' => (int) DB::table('master_catalog_items')->join('master_catalogs', 'master_catalogs.id', '=', 'master_catalog_items.master_catalog_id')->where('master_catalogs.code', 'countries')->count(),
            'pension_funds' => DB::table('pension_funds')->count(),
        ];
        foreach (self::VIRTUAL as $code) {
            $out[] = ['code' => $code, 'name' => $this->catalogName($code, $code), 'description' => null, 'source' => 'system', 'is_extensible' => false, 'country_code' => null, 'items_count' => $virtualCounts[$code], 'own_items_count' => 0];
        }
        $masterCodes = array_column($out, 'code');
        foreach ($own as $c) {
            if (in_array($c->code, $masterCodes, true)) {
                continue;
            }
            $out[] = ['code' => $c->code, 'name' => $c->name, 'description' => null, 'source' => 'organization', 'is_extensible' => true, 'country_code' => null, 'items_count' => (int) ($ownCounts[$c->id] ?? 0), 'own_items_count' => (int) ($ownCounts[$c->id] ?? 0)];
        }

        return $out;
    }

    /**
     * Elementos de un catálogo vigentes a la fecha, con la etiqueta en el idioma de la solicitud.
     *
     * @return array{catalog: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function items(string $code, string $asOf, bool $includeArchived, ?string $parent): array
    {
        $catalog = collect($this->catalogs())->firstWhere('code', $code);
        if ($catalog === null) {
            throw new AyniException('not_found', 404);
        }

        $items = match (true) {
            $catalog['source'] === 'master' => $this->masterItems($code, $asOf, $includeArchived),
            $code === 'nationalities' => $this->masterItems('countries', $asOf, $includeArchived),
            $code === 'currencies' => DB::table('currencies')->orderBy('code')->get()->map(fn (stdClass $r) => $this->row(null, trim((string) $r->code), ['es' => (string) $r->name], trim((string) $r->code), null, 'system', true, true, null, null, 0, ['symbol' => $r->symbol, 'decimals' => (int) $r->decimals]))->all(),
            $code === 'pension_funds' => DB::table('pension_funds')->orderBy('name')->get()->map(fn (stdClass $r) => $this->row(null, (string) $r->code, ['es' => (string) $r->name], $r->statutory_code, null, 'system', $r->status === 'active', true, null, null, 0, ['kind' => $r->kind, 'country_code' => $r->country_code]))->filter(fn (array $i) => $includeArchived || $i['is_active'])->values()->all(),
            default => [],
        };

        $own = Catalog::query()->where('code', $code)->first();
        if ($own !== null) {
            $query = CatalogItem::query()->where('catalog_id', $own->id)->orderBy('sort_order')->orderBy('label');
            if (! $includeArchived) {
                $query->where('is_active', true);
            }
            foreach ($query->get() as $i) {
                $items[] = $this->ownRow($i);
            }
        }
        if ($parent !== null && $parent !== '') {
            $items = array_values(array_filter($items, fn (array $i) => $i['parent_code'] === $parent));
        }

        return ['catalog' => $catalog, 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function createCatalog(string $code, string $name): array
    {
        if ($this->isReserved($code) || Catalog::query()->where('code', $code)->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('catalogs.code_taken'));
        }
        $catalog = DB::transaction(function () use ($code, $name): Catalog {
            $c = Catalog::create(['code' => $code, 'name' => $name, 'is_system' => false]);
            $this->audit->record('catalog.created', 'catalog', $c->id, null, ['code' => $code, 'name' => $name]);

            return $c;
        });

        return collect($this->catalogs())->firstWhere('code', $catalog->code) ?? [];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public function addItem(string $catalogCode, string $code, array $labels, int $sortOrder, ?array $metadata): array
    {
        $master = DB::table('master_catalogs')->where('code', $catalogCode)->first();
        if (in_array($catalogCode, self::VIRTUAL, true) || ($master !== null && ! $master->is_extensible)) {
            throw new AyniException('catalog_not_extensible', 422, (string) trans('catalogs.not_extensible'), [['field' => 'code', 'rule' => 'catalog_not_extensible', 'message' => (string) trans('catalogs.not_extensible')]]);
        }
        $own = Catalog::query()->where('code', $catalogCode)->first();
        if ($master === null && $own === null) {
            throw new AyniException('not_found', 404);
        }
        $takenInMaster = $master !== null && DB::table('master_catalog_items')->where('master_catalog_id', $master->id)->where('code', $code)->exists();
        $takenOwn = $own !== null && CatalogItem::withTrashed()->where('catalog_id', $own->id)->where('code', $code)->exists();
        if ($takenInMaster || $takenOwn) {
            throw new AyniException('validation_failed', 422, (string) trans('catalogs.item_code_taken'), [['field' => 'code', 'rule' => 'unique', 'message' => (string) trans('catalogs.item_code_taken')]]);
        }

        // Extender un maestro crea, la primera vez, su catálogo de la organización con el mismo código.
        $extensionName = $master !== null ? (string) $master->name : $catalogCode;
        $item = DB::transaction(function () use ($extensionName, $own, $catalogCode, $code, $labels, $sortOrder, $metadata): CatalogItem {
            $own ??= Catalog::create(['code' => $catalogCode, 'name' => $extensionName, 'is_system' => true]);
            $i = CatalogItem::create(['catalog_id' => $own->id, 'code' => $code, 'label' => $labels['es'], 'labels' => $labels, 'sort_order' => $sortOrder, 'is_active' => true, 'metadata' => $metadata]);
            $this->audit->record('catalog_item.created', 'catalog_item', $i->id, null, ['catalog' => $catalogCode, 'code' => $code, 'labels' => $labels]);

            return $i;
        });

        return $this->ownRow($item->refresh());
    }

    /**
     * @param  array<string, string>|null  $labels
     * @return array<string, mixed>
     */
    public function updateItem(string $id, ?array $labels, ?int $sortOrder): array
    {
        $item = CatalogItem::query()->findOrFail($id);
        $before = $this->ownRow($item);
        DB::transaction(function () use ($item, $labels, $sortOrder, $before): void {
            if ($labels !== null) {
                $merged = array_filter(array_merge($item->labels ?? ['es' => $item->label], $labels), fn (string $v) => $v !== '');
                $item->labels = $merged;
                $item->label = $merged['es'] ?? $item->label;
            }
            if ($sortOrder !== null) {
                $item->sort_order = $sortOrder;
            }
            $item->save();
            $this->audit->record('catalog_item.updated', 'catalog_item', $item->id, $before, $this->ownRow($item));
        });

        return $this->ownRow($item->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function setActive(string $id, bool $active, ?string $reason): array
    {
        $item = CatalogItem::query()->findOrFail($id);
        if ($item->is_active === $active) {
            throw new AyniException('invalid_transition', 409, (string) trans($active ? 'catalogs.already_active' : 'catalogs.already_archived'));
        }
        DB::transaction(function () use ($item, $active, $reason): void {
            $item->is_active = $active;
            $item->save();
            $this->audit->record($active ? 'catalog_item.reactivated' : 'catalog_item.archived', 'catalog_item', $item->id, ['is_active' => ! $active], ['is_active' => $active], $reason);
        });

        return $this->ownRow($item->refresh());
    }

    public function ensureKnown(string $code): void
    {
        if (! $this->isReserved($code) && ! Catalog::query()->where('code', $code)->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    private function isReserved(string $code): bool
    {
        return in_array($code, self::VIRTUAL, true) || DB::table('master_catalogs')->where('code', $code)->exists();
    }

    private function catalogName(string $code, string $fallback): string
    {
        $key = "catalogs.names.{$code}";
        $t = (string) trans($key);

        return $t === $key ? $fallback : $t;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function masterItems(string $code, string $asOf, bool $includeArchived): array
    {
        $q = DB::table('master_catalog_items')
            ->join('master_catalogs', 'master_catalogs.id', '=', 'master_catalog_items.master_catalog_id')
            ->where('master_catalogs.code', $code)
            ->where('master_catalog_items.valid_from', '<=', $asOf)
            ->select('master_catalog_items.*')
            ->orderBy('master_catalog_items.sort_order');
        $rows = $q->get()->map(function (stdClass $r) use ($asOf): array {
            /** @var array<string, string> $labels */
            $labels = is_string($r->labels) ? (json_decode($r->labels, true) ?: []) : [];
            $active = $r->valid_to === null || (string) $r->valid_to >= $asOf;
            /** @var array<string, mixed>|null $meta */
            $meta = is_string($r->metadata) ? json_decode($r->metadata, true) : null;

            return $this->row(null, (string) $r->code, $labels + ['es' => (string) $r->label], $r->statutory_code, $r->parent_code, 'master', $active, (bool) $r->is_verified, substr((string) $r->valid_from, 0, 10), $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10), (int) $r->sort_order, $meta);
        });
        if (! $includeArchived) {
            $rows = $rows->filter(fn (array $i) => $i['is_active']);
        }
        $list = $rows->values()->all();
        if ($code === 'countries') {
            usort($list, fn (array $a, array $b) => strcmp($this->sortKey($a['label']), $this->sortKey($b['label'])));
        }

        return $list;
    }

    private function sortKey(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}/u', '', $n === false ? $s : $n));
    }

    /**
     * @return array<string, mixed>
     */
    private function ownRow(CatalogItem $i): array
    {
        return $this->row($i->id, $i->code, ($i->labels ?? []) + ['es' => $i->label], null, null, 'organization', $i->is_active, true, null, null, $i->sort_order, $i->metadata);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function row(?string $id, string $code, array $labels, ?string $statutory, ?string $parent, string $source, bool $active, bool $verified, ?string $from, ?string $to, int $sort, ?array $metadata): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $id,
            'code' => $code,
            'label' => $labels[$locale] ?? $labels['es'] ?? $code,
            'labels' => $labels,
            'statutory_code' => $statutory,
            'parent_code' => $parent,
            'source' => $source,
            'is_active' => $active,
            'is_verified' => $verified,
            'valid_from' => $from,
            'valid_to' => $to,
            'sort_order' => $sort,
            'metadata' => $metadata,
        ];
    }
}
