<?php

namespace App\Modules\CoreHR\Infrastructure\Setup;

use App\Modules\CoreHR\Application\TerminationReasonCatalog;
use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Motivos de cese enlazados a la tabla oficial del país (COL-11, Tabla 17 en el Perú). La
 * organización agrega motivos propios junto a ellos.
 */
final class TerminationReasonsPackComponent implements CountryPackComponent
{
    public function __construct(private readonly TerminationReasonCatalog $catalog) {}

    public function code(): string
    {
        return 'termination_reasons';
    }

    public function moduleCode(): string
    {
        return 'core';
    }

    public function items(string $country): array
    {
        /** @var list<string> */
        return $this->official($country)->pluck('label')->map(fn ($l) => (string) $l)->all();
    }

    public function present(string $country): int
    {
        return DB::table('termination_reasons')->whereIn('code', $this->official($country)->pluck('code')->all())->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $this->catalog->ensureCountryReasons();
    }

    public function link(): string
    {
        return '/configuracion/catalogos';
    }

    /** @return Collection<int, \stdClass> */
    private function official(string $country): Collection
    {
        return DB::table('master_catalog_items as i')->join('master_catalogs as c', 'c.id', '=', 'i.master_catalog_id')
            ->where('c.code', 'termination_reasons_'.strtolower($country))->whereNull('i.valid_to')
            ->where('i.code', '!=', 'no_link_plame')->orderBy('i.sort_order')->get(['i.code', 'i.label']);
    }
}
