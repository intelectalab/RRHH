<?php

namespace App\Shared\Money;

use Illuminate\Support\Facades\DB;

/**
 * CFG-7 · RN-CFG-06 · Consolidar montos de varias monedas en una sola para un reporte: cada monto se
 * convierte con el tipo de cambio de la fecha (CurrencyConverter) y el resultado lista los tipos que se
 * aplicaron, para que el reporte indique la moneda y el tipo usado. Los montos originales no cambian.
 */
final class MoneyConsolidator
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * @param  list<Money>  $amounts
     * @return array{total: Money, as_of: string, rates: list<array{from: string, to: string, rate: string, rate_date: string, inverse: bool, referential: bool}>, referential: bool}
     */
    public function sum(array $amounts, string $to, string $date): array
    {
        $converted = [];
        $rates = [];
        foreach ($amounts as $money) {
            $r = $this->converter->convert($money, $to, $date);
            $converted[] = $r['converted']->amount;
            if ($money->currency !== $to && ! isset($rates[$money->currency])) {
                $rates[$money->currency] = [
                    'from' => $money->currency, 'to' => $to, 'rate' => $r['rate'], 'rate_date' => (string) $r['rate_date'],
                    'inverse' => $r['inverse'], 'referential' => $r['referential'],
                ];
            }
        }
        $decimals = (int) (DB::table('currencies')->where('code', $to)->value('decimals') ?? 2);
        $total = $converted === [] ? '0'
            : (string) DB::selectOne('SELECT round(coalesce(sum(x), 0), ?)::text AS v FROM unnest(?::numeric[]) AS x', [$decimals, '{'.implode(',', $converted).'}'])->v;
        if ($converted === []) {
            $total = (string) DB::selectOne('SELECT round(0::numeric, ?)::text AS v', [$decimals])->v;
        }
        $list = array_values($rates);

        return [
            'total' => new Money($total, $to),
            'as_of' => $date,
            'rates' => $list,
            'referential' => in_array(true, array_column($list, 'referential'), true),
        ];
    }
}
