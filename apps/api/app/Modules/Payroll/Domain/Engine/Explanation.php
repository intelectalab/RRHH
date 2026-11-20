<?php

namespace App\Modules\Payroll\Domain\Engine;

/**
 * NOM-6 · Explicación en lenguaje claro de una línea calculada, a partir de su traza (RN-NOM-04): qué
 * versión del concepto se usó, con qué fórmula, qué datos, conceptos e incidencias entraron, qué parámetros
 * con su vigencia y cómo se redondeó. Es una función pura de la traza guardada: explicar una boleta de hace
 * meses da lo mismo que el día del cálculo, aunque los parámetros hayan cambiado después.
 */
final class Explanation
{
    /**
     * @param  array{concept_code: string, concept_name?: string, kind?: string, concept_version_id?: string|null, amount: array{amount: string, currency: string}, trace: array<string, mixed>, origin?: string}  $line
     * @return array<string, mixed>
     */
    public static function of(array $line): array
    {
        $trace = $line['trace'];
        $source = (string) ($trace['source'] ?? 'formula');
        $amount = $line['amount']['amount'];
        $currency = $line['amount']['currency'];
        $steps = [];
        $inputs = [];
        $parameters = [];
        $tramos = [];

        if ($source === 'tramos') {
            foreach ((array) ($trace['tramos'] ?? []) as $t) {
                $sub = self::of(['concept_code' => $line['concept_code'], 'concept_name' => $line['concept_name'] ?? '', 'kind' => $line['kind'] ?? '',
                    'concept_version_id' => $t['concept_version_id'] ?? null, 'amount' => ['amount' => (string) $t['amount'], 'currency' => $currency], 'trace' => (array) $t['trace']]);
                $tramos[] = ['index' => $t['index'], 'from' => $t['from'], 'to' => $t['to'], 'regime' => $t['regime'], 'amount' => (string) $t['amount']] + $sub;
                $steps[] = self::t('tramo', ['index' => (string) $t['index'], 'from' => (string) $t['from'], 'to' => (string) $t['to'], 'regime' => (string) $t['regime'], 'amount' => (string) $t['amount'], 'currency' => $currency]);
            }
            $steps[] = self::t('tramos_total', ['amount' => $amount, 'currency' => $currency]);
        } elseif ($source === 'retroactive') {
            $origin = (array) ($trace['origin_period'] ?? []);
            $steps[] = self::t('retroactive', ['period' => (string) ($origin['label'] ?? ''), 'amount' => $amount, 'currency' => $currency]);
        } else {
            if ($source === 'formula') {
                $steps[] = self::t('formula', ['formula' => (string) ($trace['formula'] ?? '')]);
            } else {
                $steps[] = self::t('incidents');
            }
            foreach ((array) ($trace['variables'] ?? []) as $name => $value) {
                $inputs[] = ['type' => 'variable', 'name' => (string) $name, 'value' => is_bool($value) ? $value : (string) $value];
                $steps[] = self::t('variable', ['name' => (string) $name, 'value' => is_bool($value) ? ($value ? self::t('yes') : self::t('no')) : (string) $value]);
            }
            foreach ((array) ($trace['concepts'] ?? []) as $code => $value) {
                $inputs[] = ['type' => 'concept', 'code' => (string) $code, 'amount' => (string) $value];
                $steps[] = self::t('concept', ['code' => (string) $code, 'amount' => (string) $value, 'currency' => $currency]);
            }
            foreach ((array) ($trace['incidents'] ?? []) as $code => $row) {
                $row = (array) $row;
                $inputs[] = ['type' => 'incident', 'code' => (string) $code, 'amount' => (string) ($row['amount'] ?? '0'), 'quantity' => (string) ($row['quantity'] ?? '0')];
                $steps[] = self::t('incident', ['code' => (string) $code, 'amount' => (string) ($row['amount'] ?? '0'), 'quantity' => (string) ($row['quantity'] ?? '0')]);
            }
            foreach ((array) ($trace['parameters'] ?? []) as $p) {
                $p = (array) $p;
                $parameters[] = [
                    'code' => (string) ($p['code'] ?? ''), 'value' => $p['value'] === null ? null : (string) $p['value'],
                    'valid_from' => $p['valid_from'] ?? null, 'valid_to' => $p['valid_to'] ?? null,
                    'origin' => $p['origin'] ?? null, 'pending_validation' => (bool) ($p['pending_validation'] ?? false),
                    'legal_source' => $p['legal_source'] ?? null,
                ];
                $steps[] = self::t(($p['pending_validation'] ?? false) ? 'parameter_pending' : 'parameter', [
                    'code' => (string) ($p['code'] ?? ''), 'value' => (string) ($p['value'] ?? ''), 'from' => (string) ($p['valid_from'] ?? ''),
                    'origin' => self::t('origin_'.(($p['origin'] ?? 'country') === 'organization' ? 'organization' : 'country')),
                ]);
            }
            $steps[] = self::t('rounding', ['raw' => (string) ($trace['raw'] ?? $amount), 'decimals' => (string) ($trace['decimals'] ?? ''), 'amount' => $amount, 'currency' => $currency]);
        }

        return [
            'concept_code' => $line['concept_code'],
            'concept_name' => $line['concept_name'] ?? null,
            'kind' => $line['kind'] ?? null,
            'origin' => $line['origin'] ?? ($source === 'retroactive' ? 'retroactive' : 'calculated'),
            'version' => ['concept_version_id' => $line['concept_version_id'] ?? null, 'valid_from' => $trace['version_valid_from'] ?? null],
            'formula' => $trace['formula'] ?? null,
            'source' => $source,
            'inputs' => $inputs,
            'parameters' => $parameters,
            'result' => ['raw' => isset($trace['raw']) ? (string) $trace['raw'] : null, 'rounding' => $trace['rounding'] ?? null,
                'decimals' => $trace['decimals'] ?? null, 'amount' => ['amount' => $amount, 'currency' => $currency]],
            'tramos' => $tramos,
            'origin_period' => $trace['origin_period'] ?? null,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        return (string) trans('payroll.explain.'.$key, $replace);
    }
}
