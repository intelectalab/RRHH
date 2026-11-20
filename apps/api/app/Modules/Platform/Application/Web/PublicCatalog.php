<?php

namespace App\Modules\Platform\Application\Web;

use App\Shared\LegalParameters\LegalParameters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WEB-2 · WEB-4 · Catálogo público del sitio gestiahr.com (RN-WEB-01 a 04).
 *
 *  - Productos de la web con sus módulos y el estado de cada uno: «Disponible» si el módulo está publicado en la
 *    consola (modules.is_published), «Próximamente» si no. Nunca se escribe a mano en el sitio.
 *  - Planes públicos activos (versión vigente), con su precio, límites, módulos y funciones; un plan sin precio
 *    cargado viaja con price = null y el sitio muestra «Consultar». No hay montos en el código.
 *  - Complementos activos con los módulos que habilitan.
 *  - Impuesto vigente del parámetro legal igv_tasa_pct (por validar → sin tasa).
 *
 * Solo lee tablas de la plataforma: nunca organizaciones, consumo ni datos de clientes.
 */
final class PublicCatalog
{
    public function __construct(private readonly LegalParameters $legal) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $modules = $this->modules();
        $published = array_map(fn (array $m): bool => $m['published'], $modules);

        $productModules = DB::table('web_product_modules')->get(['web_product_id', 'module_code'])->groupBy('web_product_id');
        $products = DB::table('web_products')->where('status', 'published')->orderBy('sort_order')->get()
            ->map(function ($p) use ($productModules, $published, $modules): array {
                $codes = array_values(array_map(fn ($r): string => (string) $r->module_code, ($productModules[$p->id] ?? collect())->all()));
                $on = count(array_filter($codes, fn (string $c): bool => $published[$c] ?? false));

                return [
                    'code' => (string) $p->code, 'name' => (string) $p->name, 'acronym' => (string) $p->acronym,
                    'slug' => (string) $p->slug, 'tagline' => $p->tagline === null ? null : (string) $p->tagline,
                    'is_suite' => (bool) $p->is_suite, 'sold_standalone' => (bool) $p->sold_standalone,
                    'status' => $codes === [] || $on === 0 ? 'coming_soon' : ($on === count($codes) ? 'available' : 'partial'),
                    'modules' => array_map(fn (string $c): array => ['code' => $c, 'name' => $modules[$c]['name'] ?? $c, 'published' => $published[$c] ?? false], $codes),
                ];
            })->values()->all();

        return [
            'products' => $products,
            'plans' => $this->plans(),
            'addons' => $this->addons(),
            'modules' => array_values($modules),
            'tax' => $this->tax(),
        ];
    }

    /**
     * Cotización de un plan con complementos para cierta cantidad de colaboradores (WEB-4). El plan se cobra por
     * periodo y cada complemento por colaborador al periodo, como en el cargo de SAS-11. Montos con decimal exacto.
     *
     * @param  list<string>  $addonCodes
     * @return array<string, mixed>
     */
    public function quote(string $planCode, int $employees, ?string $cycle, array $addonCodes): array
    {
        $plan = $this->planQuery()->where('code', $planCode)->first();
        if ($plan === null) {
            throw new NotFoundHttpException;
        }
        $modules = $this->modules();
        $planModules = DB::table('plan_modules')->where('plan_id', $plan->id)->pluck('module_code')->map(fn ($c): string => (string) $c)->all();
        $addons = collect($this->addons())->keyBy('code');
        $unknown = array_values(array_diff($addonCodes, $addons->keys()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['addons' => [(string) trans('platform.web.unknown_addon', ['codes' => implode(', ', $unknown)])]]);
        }

        $notices = [];
        $cycle ??= (string) $plan->billing_cycle;
        $priced = $cycle === (string) $plan->billing_cycle;
        if (! $priced) {
            $notices[] = $this->notice('cycle_unavailable');
        }
        $limit = DB::table('plan_limits')->where('plan_id', $plan->id)->where('limit_code', 'employees')->first();
        if ($limit !== null && ! $limit->is_unlimited && $limit->limit_value !== null && $employees > (float) $limit->limit_value) {
            $notices[] = $this->notice('exceeds_limit', ['limit' => (string) (int) $limit->limit_value]);
        }

        // Complementos elegidos, más los que exige alguno de ellos (RN-WEB-04).
        $chosen = [];
        $waitlist = [];
        $queue = array_values(array_unique($addonCodes));
        $covered = $planModules;
        while ($queue !== []) {
            $code = array_shift($queue);
            if (isset($chosen[$code]) || in_array($code, $waitlist, true)) {
                continue;
            }
            /** @var array{code: string, name: string, modules: list<string>, price: array{amount: string, currency: string}|null} $addon */
            $addon = $addons[$code];
            if (array_diff($addon['modules'], $covered) === []) {
                $notices[] = $this->notice('already_included', ['addon' => $addon['name']]);

                continue;
            }
            $unpublished = array_filter($addon['modules'], fn (string $m): bool => ! ($modules[$m]['published'] ?? false));
            if ($unpublished !== []) {
                // RN-WEB-02: lo que está en «Próximamente» se puede pedir como aviso, nunca se cobra.
                $waitlist[] = $code;
                $notices[] = $this->notice('waitlist', ['addon' => $addon['name']]);

                continue;
            }
            $chosen[$code] = $addon;
            $covered = array_values(array_unique([...$covered, ...$addon['modules']]));
            foreach ($addon['modules'] as $m) {
                foreach ($modules[$m]['depends_on'] ?? [] as $dep) {
                    if (in_array($dep, $covered, true)) {
                        continue;
                    }
                    $provider = $addons->first(fn (array $a): bool => in_array($dep, $a['modules'], true));
                    if ($provider !== null) {
                        $queue[] = (string) $provider['code'];
                        $notices[] = $this->notice('requirement_added', ['addon' => $addon['name'], 'required' => (string) $provider['name']]);
                    } else {
                        $notices[] = $this->notice('requirement_missing', ['addon' => $addon['name'], 'module' => $modules[$dep]['name'] ?? $dep]);
                    }
                }
            }
        }

        $currency = $plan->currency === null ? null : (string) $plan->currency;
        $lines = [[
            'kind' => 'plan', 'code' => (string) $plan->code, 'name' => (string) $plan->name, 'quantity' => '1',
            'unit_price' => $plan->price === null || ! $priced ? null : $this->money((string) $plan->price, (string) $plan->currency),
        ]];
        foreach ($chosen as $addon) {
            $lines[] = ['kind' => 'addon', 'code' => $addon['code'], 'name' => $addon['name'], 'quantity' => (string) $employees, 'unit_price' => $priced ? $addon['price'] : null];
        }

        $complete = true;
        $subtotal = '0';
        foreach ($lines as $i => $line) {
            $price = $line['unit_price'];
            if ($price === null || $currency === null || $price['currency'] !== $currency) {
                $complete = false;
                $lines[$i]['amount'] = null;

                continue;
            }
            $amount = $this->calc('round(?::numeric * ?::numeric, 2)', [$price['amount'], $line['quantity']]);
            $lines[$i]['amount'] = $this->money($amount, $currency);
            $subtotal = $this->calc('?::numeric + ?::numeric', [$subtotal, $amount]);
        }
        if (! $complete) {
            $notices[] = $this->notice('price_on_request');
        }

        $tax = $this->tax();
        $taxAmount = null;
        $total = null;
        if ($complete && $tax['rate_pct'] !== null) {
            $taxAmount = $this->calc('round(?::numeric * ?::numeric / 100, 2)', [$subtotal, $tax['rate_pct']]);
            $total = $this->money($this->calc('?::numeric + ?::numeric', [$subtotal, $taxAmount]), $currency);
        } elseif ($complete) {
            $notices[] = $this->notice('tax_pending');
        }

        return [
            'plan' => ['code' => (string) $plan->code, 'name' => (string) $plan->name, 'billing_cycle' => (string) $plan->billing_cycle],
            'cycle' => $cycle,
            'employees' => $employees,
            'lines' => $lines,
            'waitlist' => $waitlist,
            'subtotal' => $complete ? $this->money($this->calc('round(?::numeric, 2)', [$subtotal]), $currency) : null,
            'tax' => $tax + ['amount' => $taxAmount === null ? null : $this->money($taxAmount, $currency)],
            'total' => $total,
            'notices' => $notices,
        ];
    }

    /**
     * @return array<string, array{code: string, name: string, published: bool, depends_on: list<string>}>
     */
    private function modules(): array
    {
        $deps = DB::table('module_dependencies')->where('is_required', true)->get(['module_code', 'depends_on_code'])->groupBy('module_code');
        $out = [];
        foreach (DB::table('modules')->orderBy('phase')->orderBy('code')->get(['code', 'name', 'is_published']) as $m) {
            $code = (string) $m->code;
            $out[$code] = [
                'code' => $code, 'name' => (string) $m->name, 'published' => (bool) $m->is_published,
                'depends_on' => array_values(array_map(fn ($d): string => (string) $d->depends_on_code, ($deps[$code] ?? collect())->all())),
            ];
        }

        return $out;
    }

    private function planQuery(): Builder
    {
        return DB::table('plans')->where('status', 'active')->where('is_public', true)->whereNull('deleted_at')->whereNull('superseded_by_plan_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        $rows = $this->planQuery()->orderByRaw('price IS NULL')->orderBy('price')->orderBy('name')->get();
        $ids = $rows->pluck('id')->all();
        $mods = DB::table('plan_modules')->whereIn('plan_id', $ids)->get(['plan_id', 'module_code'])->groupBy('plan_id');
        $limits = DB::table('plan_limits')->whereIn('plan_id', $ids)->get()->groupBy('plan_id');
        $features = DB::table('plan_features')->whereIn('plan_id', $ids)->where('enabled', true)->get(['plan_id', 'feature_code'])->groupBy('plan_id');

        return array_values($rows->map(fn ($p): array => [
            'code' => (string) $p->code, 'name' => (string) $p->name, 'description' => $p->description === null ? null : (string) $p->description,
            'billing_cycle' => (string) $p->billing_cycle, 'trial_days' => $p->trial_days === null ? null : (int) $p->trial_days,
            'price' => $p->price === null || $p->currency === null ? null : $this->money((string) $p->price, (string) $p->currency),
            'modules' => array_values(array_map(fn ($m): string => (string) $m->module_code, ($mods[$p->id] ?? collect())->all())),
            'limits' => array_values(array_map(fn ($l): array => [
                'code' => (string) $l->limit_code, 'unlimited' => (bool) $l->is_unlimited,
                'value' => $l->limit_value === null ? null : (string) (int) $l->limit_value,
            ], ($limits[$p->id] ?? collect())->all())),
            'features' => array_values(array_map(fn ($f): string => (string) $f->feature_code, ($features[$p->id] ?? collect())->all())),
        ])->all());
    }

    /**
     * @return list<array{code: string, name: string, web_product_code: string|null, billing_cycle: string, charge_basis: string, modules: list<string>, price: array{amount: string, currency: string}|null}>
     */
    private function addons(): array
    {
        $rows = DB::table('addons')->where('status', 'active')->where('kind', 'module')->whereNull('deleted_at')->orderBy('name')->get();
        $mods = DB::table('addon_modules')->whereIn('addon_id', $rows->pluck('id')->all())->get(['addon_id', 'module_code'])->groupBy('addon_id');

        return array_values($rows->map(fn ($a): array => [
            'code' => (string) $a->code, 'name' => (string) $a->name,
            'web_product_code' => $a->web_product_code === null ? null : (string) $a->web_product_code,
            'billing_cycle' => (string) $a->billing_cycle, 'charge_basis' => 'per_employee',
            'modules' => array_values(array_map(fn ($m): string => (string) $m->module_code, ($mods[$a->id] ?? collect())->all())),
            'price' => $a->price === null || $a->currency === null ? null : $this->money((string) $a->price, (string) $a->currency),
        ])->all());
    }

    /**
     * @return array{code: string, rate_pct: string|null, pending_validation: bool}
     */
    private function tax(): array
    {
        $param = $this->legal->at('PE', 'igv_tasa_pct', now());
        $ok = $param !== null && ! $param['pending_validation'] && is_string($param['value']);

        return ['code' => 'IGV', 'rate_pct' => $ok ? (string) $param['value'] : null, 'pending_validation' => ! $ok];
    }

    /**
     * @return array{amount: string, currency: string}
     */
    private function money(string $amount, string $currency): array
    {
        return ['amount' => $this->calc('round(?::numeric, 2)::text', [$amount]), 'currency' => $currency];
    }

    /**
     * @param  list<string>  $args
     */
    private function calc(string $expr, array $args): string
    {
        return (string) DB::selectOne('SELECT ('.$expr.')::text AS v', $args)?->v;
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{code: string, message: string}
     */
    private function notice(string $code, array $replace = []): array
    {
        return ['code' => $code, 'message' => (string) trans('platform.web.notices.'.$code, $replace)];
    }
}
