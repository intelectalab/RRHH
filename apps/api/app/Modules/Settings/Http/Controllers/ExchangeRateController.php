<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\ExchangeRate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Money\CurrencyConverter;
use App\Shared\Money\Money;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
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

        return response()->json(['data' => ['original' => $r['original'], 'converted' => $r['converted'], 'rate' => $r['rate'], 'rate_date' => $r['rate_date'], 'inverse' => $r['inverse']]]);
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
