<?php

namespace App\Shared\Money;

use App\Shared\Exceptions\AyniException;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;

/**
 * CFG-7 · Conversión para consolidar reportes sin alterar el monto original: usa el tipo de cambio
 * vigente a la fecha (el último publicado en o antes de esa fecha), directo o inverso, y redondea a
 * los decimales de la moneda destino. La aritmética la hace PostgreSQL con numeric (exacta).
 *
 * RN-CFG-07 · Si no hay tipo publicado para esa misma fecha, el parámetro org.tipo_cambio_faltante
 * decide: use_previous (por defecto) usa el último anterior y marca el resultado como referencial;
 * block rechaza la conversión (422).
 */
final class CurrencyConverter
{
    public function __construct(private readonly ParameterResolver $parameters) {}

    /**
     * @return array{original: Money, converted: Money, rate: string, rate_date: string|null, inverse: bool, referential: bool}
     */
    public function convert(Money $money, string $to, string $date): array
    {
        if ($money->currency === $to) {
            return ['original' => $money, 'converted' => $money, 'rate' => '1', 'rate_date' => null, 'inverse' => false, 'referential' => false];
        }
        $rate = $this->rate($money->currency, $to, $date);
        $decimals = (int) (DB::table('currencies')->where('code', $to)->value('decimals') ?? 2);
        $sql = $rate['inverse'] ? 'SELECT round(?::numeric / ?::numeric, ?)::text AS v' : 'SELECT round(?::numeric * ?::numeric, ?)::text AS v';
        $converted = (string) DB::selectOne($sql, [$money->amount, $rate['stored'], $decimals])->v;

        return [
            'original' => $money,
            'converted' => new Money($converted, $to),
            'rate' => $rate['rate'],
            'rate_date' => $rate['rate_date'],
            'inverse' => $rate['inverse'],
            'referential' => $rate['referential'],
        ];
    }

    /**
     * Tipo aplicable de $from a $to en la fecha (directo, o el inverso publicado).
     *
     * @return array{rate: string, stored: string, rate_date: string, inverse: bool, referential: bool}
     */
    public function rate(string $from, string $to, string $date): array
    {
        $direct = $this->rateRow($from, $to, $date);
        $inverse = $this->rateRow($to, $from, $date);
        // El más reciente de los dos (a igual fecha, el directo).
        $row = $direct;
        $isInverse = false;
        if ($direct === null || ($inverse !== null && (string) $inverse->rate_date > (string) $direct->rate_date)) {
            $row = $inverse;
            $isInverse = $inverse !== null;
        }
        if ($row === null) {
            throw new AyniException('business_rule_violated', 422, (string) trans('settings.no_exchange_rate', ['from' => $from, 'to' => $to, 'date' => $date]), [], ['rule' => 'exchange_rate_missing']);
        }
        $rateDate = substr((string) $row->rate_date, 0, 10);
        $referential = $rateDate !== $date;
        if ($referential && $this->parameters->value('org.tipo_cambio_faltante') === 'block') {
            throw new AyniException('business_rule_violated', 422, (string) trans('settings.exchange_rate_blocked', ['from' => $from, 'to' => $to, 'date' => $date]), [], ['rule' => 'exchange_rate_missing']);
        }
        $stored = (string) $row->rate;

        return [
            'rate' => str_contains($stored, '.') ? rtrim(rtrim($stored, '0'), '.') : $stored,
            'stored' => $stored,
            'rate_date' => $rateDate,
            'inverse' => $isInverse,
            'referential' => $referential,
        ];
    }

    private function rateRow(string $from, string $to, string $date): ?\stdClass
    {
        $row = DB::table('exchange_rates')->where('from_currency', $from)->where('to_currency', $to)
            ->where('rate_date', '<=', $date)->orderByDesc('rate_date')->first(['rate', 'rate_date']);

        return $row instanceof \stdClass ? $row : null;
    }
}
