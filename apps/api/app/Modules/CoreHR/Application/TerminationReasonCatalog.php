<?php

namespace App\Modules\CoreHR\Application;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * COL-11 · Motivos de cese de la organización (termination_reasons), enlazados por statutory_code a la
 * Tabla 17 del paquete de país (catálogo maestro termination_reasons_xx de CFG-10). Se siembran la primera
 * vez que se piden; los propios de la organización conviven con ellos.
 */
final class TerminationReasonCatalog
{
    /** Categoría de cada motivo de la Tabla 17; el resto queda como «other». */
    private const CATEGORY = [
        'resignation' => 'resignation', 'resignation_incentives' => 'resignation', 'dismissal' => 'dismissal',
        'collective_termination' => 'dismissal', 'retirement' => 'retirement', 'age_limit' => 'retirement',
        'contract_end' => 'contract_end', 'mutual_agreement' => 'mutual_agreement', 'death' => 'death',
    ];

    /** Códigos de la Tabla 17 que no describen un cese («sin vínculo laboral»). */
    private const NOT_A_TERMINATION = ['no_link_plame'];

    public function ensureCountryReasons(): void
    {
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? '');
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return;
        }
        $items = DB::table('master_catalog_items as i')->join('master_catalogs as c', 'c.id', '=', 'i.master_catalog_id')
            ->where('c.code', 'termination_reasons_'.strtolower($country))->whereNull('i.valid_to')
            ->whereNotIn('i.code', self::NOT_A_TERMINATION)->orderBy('i.sort_order')
            ->get(['i.code', 'i.label', 'i.statutory_code']);
        if ($items->isEmpty()) {
            return;
        }
        $existing = DB::table('termination_reasons')->whereIn('code', $items->pluck('code')->all())->pluck('code')->all();
        $tenant = TenancyScope::requireTenant();
        foreach ($items as $item) {
            if (in_array($item->code, $existing, true)) {
                continue;
            }
            $category = self::CATEGORY[(string) $item->code] ?? 'other';
            DB::table('termination_reasons')->insertOrIgnore([
                'tenant_id' => $tenant, 'code' => (string) $item->code, 'name' => (string) $item->label, 'category' => $category,
                'is_voluntary' => $category === 'resignation', 'statutory_code' => $item->statutory_code,
            ]);
        }
    }

    /**
     * @return array<int, array{id: string, code: string, name: string, category: string, is_voluntary: bool, statutory_code: string|null, initiated_by: string}>
     */
    public function all(): array
    {
        $this->ensureCountryReasons();

        return DB::table('termination_reasons')->whereNull('deleted_at')->orderByRaw('statutory_code NULLS LAST')->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'is_voluntary', 'statutory_code'])
            ->map(fn (object $r): array => [
                'id' => (string) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'category' => (string) $r->category,
                'is_voluntary' => (bool) $r->is_voluntary, 'statutory_code' => $r->statutory_code === null ? null : (string) $r->statutory_code,
                'initiated_by' => self::initiator((string) $r->category),
            ])->values()->all();
    }

    /** Quién decide el cese según la categoría, cuando no se indica (terminations.initiated_by). */
    public static function initiator(string $category): string
    {
        return match ($category) {
            'resignation' => 'employee',
            'mutual_agreement' => 'mutual',
            default => 'employer',
        };
    }
}
