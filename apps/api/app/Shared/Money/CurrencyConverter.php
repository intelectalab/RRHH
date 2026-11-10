<?php

namespace App\Shared\Money;

use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;

/**
 * CFG-7 · Conversión para consolidar reportes sin alterar el monto original: usa el tipo de cambio
 * vigente a la fecha (el último publicado en o antes de esa fecha), directo o inverso, y redondea a
 * los decimales de la moneda destino. La aritmética la hace PostgreSQL con numeric (exacta).
 */
final class CurrencyConverter
{
    /**
     * @return array{original: Money, converted: Money, rate: string, rate_date: string|null, inverse: bool}
     */
    public function convert(Money $money, string $to, string $date): array
    {
        if ($money->currency === $to) {
            return ['original' => $money, 'converted' => $money, 'rate' => '1', 'rate_date' => null, 'inverse' => false];
        }
        $direct = $this->rateRow($money->currency, $to, $date);
        $inverse = $direct === null ? $this->rateRow($to, $money->currency, $date) : null;
        if ($direct === null && $inverse === null) {
            throw new AyniException('business_rule_violated', 422, (string) trans('settings.no_exchange_rate', ['from' => $money->currency, 'to' => $to, 'date' => $date]));
        }
        $decimals = (int) (DB::table('currencies')->where('code', $to)->value('decimals') ?? 2);
        $row = $direct ?? $inverse;
        $sql = $direct !== null ? 'SELECT round(?::numeric * ?::numeric, ?)::text AS v' : 'SELECT round(?::numeric / ?::numeric, ?)::text AS v';
        $converted = (string) DB::selectOne($sql, [$money->amount, $row->rate, $decimals])->v;

        return [
            'original' => $money,
            'converted' => new Money($converted, $to),
            'rate' => str_contains((string) $row->rate, '.') ? rtrim(rtrim((string) $row->rate, '0'), '.') : (string) $row->rate,
            'rate_date' => (string) $row->rate_date,
            'inverse' => $direct === null,
        ];
    }

    private function rateRow(string $from, string $to, string $date): ?\stdClass
    {
        $row = DB::table('exchange_rates')->where('from_currency', $from)->where('to_currency', $to)
            ->where('rate_date', '<=', $date)->orderByDesc('rate_date')->first(['rate', 'rate_date']);

        return $row instanceof \stdClass ? $row : null;
    }
}
