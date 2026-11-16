<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\ExchangeRate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Money\CurrencyConverter;
use App\Shared\Money\Money;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CFG-7 · Tipos de cambio con fecha. Un tipo publicado no se corrige: se publica otro con fecha
 * posterior (los reportes ya emitidos conservan el tipo que usaron).
 */
final class ExchangeRateController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrencyConverter $converter,
        private readonly IntegrationManager $integrations,
        private readonly ParameterResolver $parameters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = ExchangeRate::query();
        foreach (['from_currency', 'to_currency'] as $f) {
            $v = $request->input("filter.{$f}", $request->query($f));
            if (is_string($v) && preg_match('/^[A-Z]{3}$/', $v) === 1) {
                $query->where($f, $v);
            }
        }
        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'rate_date', 'desc');
        $ids = array_column($page['data'], 'id');
        $rows = ExchangeRate::query()->whereIn('id', $ids)->get()->keyBy('id');
        // Conserva el orden de la página (only() respetaría el orden de la consulta, no el de los ids).
        $rows = collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values();

        return response()->json([
            'data' => $rows->map(fn (ExchangeRate $r) => $this->present($r))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $codes = DB::table('currencies')->pluck('code')->map(fn ($c) => trim((string) $c))->all();
        $data = $request->validate([
            'from_currency' => ['required', Rule::in($codes)],
            'to_currency' => ['required', Rule::in($codes), 'different:from_currency'],
            'rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'valid_on' => ['required', 'date_format:Y-m-d'],
            'source' => ['required', 'string', 'max:200'],
        ]);
        if (ExchangeRate::query()->where('from_currency', $data['from_currency'])->where('to_currency', $data['to_currency'])->where('rate_date', $data['valid_on'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('settings.rate_exists'));
        }

        $rate = DB::transaction(function () use ($data): ExchangeRate {
            $r = ExchangeRate::create(['from_currency' => $data['from_currency'], 'to_currency' => $data['to_currency'], 'rate_date' => $data['valid_on'], 'rate' => $data['rate'], 'source' => $data['source']]);
            $this->audit->record('exchange_rate.created', 'exchange_rate', $r->id, null, $this->present($r->refresh()), $data['source']);

            return $r;
        });

        return response()->json(['data' => $this->present($rate)], 201);
    }

    /**
     * Vista previa de conversión (para consolidar): indica la moneda y el tipo de cambio aplicado.
     */
    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^-?\d{1,18}(\.\d{1,8})?$/'],
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $r = $this->converter->convert(new Money($data['amount'], strtoupper($data['from'])), strtoupper($data['to']), $data['date']);

        return response()->json(['data' => ['original' => $r['original'], 'converted' => $r['converted'], 'rate' => $r['rate'], 'rate_date' => $r['rate_date'], 'inverse' => $r['inverse'], 'referential' => $r['referential']]]);
    }

    /**
     * Trae de la fuente de tipos de cambio (puerto ExchangeRatesPort) los tipos de una fecha y los publica
     * con la fuente indicada. Por defecto, los pares ya registrados y los de cada empresa con moneda distinta
     * a la de la organización. Lo ya publicado para esa fecha no se toca; repetir la importación no duplica.
     */
    public function import(Request $request): JsonResponse
    {
        $codes = DB::table('currencies')->pluck('code')->map(fn ($c) => trim((string) $c))->all();
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pairs' => ['sometimes', 'array', 'min:1', 'max:30'],
            'pairs.*.from_currency' => ['required', Rule::in($codes)],
            'pairs.*.to_currency' => ['required', Rule::in($codes), 'different:pairs.*.from_currency'],
        ]);
        $pairs = isset($data['pairs']) ? array_map(fn (array $p) => [(string) $p['from_currency'], (string) $p['to_currency']], $data['pairs']) : $this->defaultPairs();
        $source = $this->integrations->exchangeRates();
        $imported = [];
        $skipped = [];
        foreach (array_unique(array_map(fn ($p) => $p[0].':'.$p[1], $pairs)) as $key) {
            [$from, $to] = explode(':', $key);
            if (ExchangeRate::query()->where('from_currency', $from)->where('to_currency', $to)->where('rate_date', $data['date'])->exists()) {
                $skipped[] = ['from_currency' => $from, 'to_currency' => $to, 'reason' => 'already_published'];

                continue;
            }
            try {
                $rate = $source->rate($from, $to, $data['date']);
            } catch (\Throwable $e) {
                report($e);
                throw new AyniException('upstream_unavailable', 503, (string) trans('settings.rate_source_unavailable'));
            }
            if ($rate === null || preg_match('/^\d{1,10}(\.\d{1,8})?$/', $rate) !== 1 || preg_match('/^0+(\.0+)?$/', $rate) === 1) {
                $skipped[] = ['from_currency' => $from, 'to_currency' => $to, 'reason' => 'not_published'];

                continue;
            }
            $label = (string) trans('settings.rate_source_label', ['provider' => $source->providerName()]);
            $imported[] = DB::transaction(function () use ($from, $to, $rate, $data, $label): array {
                $r = ExchangeRate::create(['from_currency' => $from, 'to_currency' => $to, 'rate_date' => $data['date'], 'rate' => $rate, 'source' => $label]);
                $row = $this->present($r->refresh());
                $this->audit->record('exchange_rate.created', 'exchange_rate', $r->id, null, $row, $label);

                return $row;
            });
        }

        return response()->json(['data' => ['date' => $data['date'], 'provider' => $source->providerName(), 'is_fake' => $source->isFake(), 'imported' => $imported, 'skipped' => $skipped]]);
    }

    /**
     * Pares ya registrados más los de cada empresa activa cuya moneda difiere de la de la organización.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function defaultPairs(): array
    {
        $pairs = [];
        foreach (DB::table('exchange_rates')->distinct()->get(['from_currency', 'to_currency']) as $p) {
            $pairs[] = [trim((string) $p->from_currency), trim((string) $p->to_currency)];
        }
        $default = (string) ($this->parameters->value('org.moneda_defecto') ?? 'PEN');
        foreach (DB::table('legal_entities')->whereNull('deleted_at')->whereNotNull('currency')->distinct()->pluck('currency') as $c) {
            $c = trim((string) $c);
            if ($c !== $default) {
                $pairs[] = [$c, $default];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExchangeRate $r): array
    {
        return [
            'id' => $r->id,
            'from_currency' => trim($r->from_currency),
            'to_currency' => trim($r->to_currency),
            'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'),
            'valid_on' => substr((string) $r->rate_date, 0, 10),
            'source' => (string) $r->source,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
