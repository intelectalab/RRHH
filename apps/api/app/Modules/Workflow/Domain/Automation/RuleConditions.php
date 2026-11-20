<?php

namespace App\Modules\Workflow\Domain\Automation;

use Brick\Math\BigDecimal;

/**
 * WFL-5 · Condiciones «SI» de una regla, sin código arbitrario: una lista de comparaciones sobre
 * los datos del evento que se cumplen todas (match = all) o al menos una (match = any).
 *
 * Forma: {"match": "all", "rules": [{"field": "reason", "op": "eq", "value": "renuncia"}]}
 * «field» es una ruta con puntos dentro del payload del evento (p. ej. «employee.area»).
 */
final class RuleConditions
{
    public const OPERATORS = ['eq', 'neq', 'in', 'not_in', 'contains', 'gt', 'gte', 'lt', 'lte', 'exists', 'not_exists'];

    public const MAX_RULES = 20;

    public const FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+){0,4}$/';

    /**
     * @param  array<mixed>|null  $conditions
     * @return list<string> rutas con error (vacío si es válida)
     */
    public static function errors(?array $conditions): array
    {
        if ($conditions === null || $conditions === []) {
            return [];
        }
        $errors = [];
        if (! in_array($conditions['match'] ?? 'all', ['all', 'any'], true)) {
            $errors[] = 'conditions.match';
        }
        $rules = $conditions['rules'] ?? [];
        if (! is_array($rules) || ! array_is_list($rules) || count($rules) > self::MAX_RULES) {
            return [...$errors, 'conditions.rules'];
        }
        foreach ($rules as $i => $rule) {
            if (! is_array($rule) || ! is_string($rule['field'] ?? null) || preg_match(self::FIELD_PATTERN, $rule['field']) !== 1) {
                $errors[] = "conditions.rules.{$i}.field";

                continue;
            }
            $op = $rule['op'] ?? null;
            if (! is_string($op) || ! in_array($op, self::OPERATORS, true)) {
                $errors[] = "conditions.rules.{$i}.op";

                continue;
            }
            $value = $rule['value'] ?? null;
            $ok = match ($op) {
                'exists', 'not_exists' => true,
                'in', 'not_in' => is_array($value) && array_is_list($value) && count($value) <= 100 && array_filter($value, fn ($v) => ! is_scalar($v)) === [],
                'gt', 'gte', 'lt', 'lte' => is_numeric($value),
                default => is_scalar($value),
            };
            if (! $ok) {
                $errors[] = "conditions.rules.{$i}.value";
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>|null  $conditions
     * @param  array<mixed>  $payload
     */
    public static function matches(?array $conditions, array $payload): bool
    {
        $rules = $conditions['rules'] ?? [];
        if (! is_array($rules) || $rules === []) {
            return true;
        }
        $results = array_map(fn ($r) => is_array($r) && self::compare($r, $payload), array_values($rules));

        return ($conditions['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * WFL-7 · Qué evaluó cada condición: campo, operador, valor esperado, valor del evento y resultado.
     *
     * @param  array<mixed>|null  $conditions
     * @param  array<mixed>  $payload
     * @return list<array{field: string, op: string, value: mixed, actual: mixed, result: bool}>
     */
    public static function explain(?array $conditions, array $payload): array
    {
        $out = [];
        foreach (array_values((array) ($conditions['rules'] ?? [])) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $actual = self::get($payload, (string) ($rule['field'] ?? ''));
            $out[] = [
                'field' => (string) ($rule['field'] ?? ''),
                'op' => (string) ($rule['op'] ?? ''),
                'value' => $rule['value'] ?? null,
                'actual' => is_scalar($actual) || $actual === null ? $actual : '[…]',
                'result' => self::compare($rule, $payload),
            ];
        }

        return $out;
    }

    /**
     * Valor de una ruta con puntos dentro del payload (null si no existe).
     *
     * @param  array<mixed>  $payload
     */
    public static function get(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $rule
     * @param  array<mixed>  $payload
     */
    private static function compare(array $rule, array $payload): bool
    {
        $actual = self::get($payload, (string) ($rule['field'] ?? ''));
        $expected = $rule['value'] ?? null;

        return match ($rule['op'] ?? '') {
            'exists' => $actual !== null && $actual !== '',
            'not_exists' => $actual === null || $actual === '',
            'eq' => self::norm($actual) === self::norm($expected),
            'neq' => self::norm($actual) !== self::norm($expected),
            'in' => is_array($expected) && in_array(self::norm($actual), array_map(self::norm(...), $expected), true),
            'not_in' => is_array($expected) && ! in_array(self::norm($actual), array_map(self::norm(...), $expected), true),
            'contains' => self::contains($actual, $expected),
            'gt', 'gte', 'lt', 'lte' => is_numeric($actual) && is_numeric($expected) && self::numeric((string) $rule['op'], (string) $actual, (string) $expected),
            default => false,
        };
    }

    private static function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array(self::norm($expected), array_map(self::norm(...), $actual), true);
        }

        return is_scalar($actual) && is_scalar($expected) && (string) $expected !== ''
            && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected));
    }

    /**
     * Comparación decimal exacta (sin float): los montos y días llegan como texto o entero.
     */
    private static function numeric(string $op, string $a, string $b): bool
    {
        $cmp = BigDecimal::of(self::decimal($a))->compareTo(self::decimal($b));

        return match ($op) {
            'gt' => $cmp > 0,
            'gte' => $cmp >= 0,
            'lt' => $cmp < 0,
            default => $cmp <= 0,
        };
    }

    private static function decimal(string $value): string
    {
        $value = trim($value);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : '0';
    }

    private static function norm(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strtolower(trim($value));
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }
}
