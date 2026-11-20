<?php

namespace App\Modules\Payroll\Domain\Formula;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * NOM-3 · Intérprete aislado del árbol de Parser. Todo número es decimal exacto (Brick BigDecimal, nunca
 * float); la división guarda una escala interna amplia y el redondeo final lo decide la regla del concepto.
 * Solo lee lo que le entrega FormulaScope.
 */
final class Evaluator
{
    /** Escala interna de las divisiones; el resultado de cada concepto se redondea después con su regla. */
    private const DIVISION_SCALE = 10;

    public function __construct(private readonly FormulaScope $scope) {}

    /**
     * @param  array<int, mixed>  $node
     */
    public function number(array $node): BigDecimal
    {
        $v = $this->eval($node);
        if (! $v instanceof BigDecimal) {
            throw new FormulaError('not_a_number', $this->position($node));
        }

        return $v;
    }

    /**
     * @param  array<int, mixed>  $node
     */
    public function eval(array $node): BigDecimal|string|bool
    {
        return match ($node[0]) {
            'num' => BigDecimal::of($node[1]),
            'str' => (string) $node[1],
            'bool' => (bool) $node[1],
            'ref' => $this->reference($node),
            'neg' => $this->num($node[1])->negated(),
            'not' => ! $this->bool($node[1]),
            'bin' => $this->binary($node),
            'call' => $this->call($node),
            default => throw new FormulaError('unexpected', 0, ['token' => (string) $node[0]]),
        };
    }

    /** @param array<int, mixed> $node */
    private function reference(array $node): BigDecimal|string|bool
    {
        return match ($node[1]) {
            'var' => $this->scope->variable($node[2]),
            'concept' => $this->scope->concept($node[2]),
            'param' => $this->scope->parameter($node[2]),
            'incident' => $this->scope->incident($node[2], 'amount'),
            'quantity' => $this->scope->incident($node[2], 'quantity'),
            default => throw new FormulaError('unknown_prefix', (int) $node[3], ['name' => (string) $node[2]]),
        };
    }

    /** @param array<int, mixed> $node */
    private function binary(array $node): BigDecimal|bool
    {
        [, $op, $l, $r, $pos] = $node;
        if ($op === 'and') {
            return $this->bool($l) && $this->bool($r);
        }
        if ($op === 'or') {
            return $this->bool($l) || $this->bool($r);
        }
        if (in_array($op, ['=', '<>'], true)) {
            $a = $this->eval($l);
            $b = $this->eval($r);
            $eq = $a instanceof BigDecimal && $b instanceof BigDecimal ? $a->isEqualTo($b) : (gettype($a) === gettype($b) && $a === $b);

            return $op === '=' ? $eq : ! $eq;
        }
        if (in_array($op, ['<', '<=', '>', '>='], true)) {
            $a = $this->eval($l);
            $b = $this->eval($r);
            if ($a instanceof BigDecimal && $b instanceof BigDecimal) {
                $cmp = $a->compareTo($b);
            } elseif (is_string($a) && is_string($b)) {
                $cmp = strcmp($a, $b) <=> 0;
            } else {
                throw new FormulaError('type_mismatch', (int) $pos);
            }

            return match ($op) {
                '<' => $cmp < 0, '<=' => $cmp <= 0, '>' => $cmp > 0, default => $cmp >= 0,
            };
        }
        $a = $this->num($l);
        $b = $this->num($r);

        return match ($op) {
            '+' => $a->plus($b),
            '-' => $a->minus($b),
            '*' => $a->multipliedBy($b),
            '/' => $b->isZero() ? throw new FormulaError('division_by_zero', (int) $pos) : $a->dividedBy($b, self::DIVISION_SCALE, RoundingMode::HALF_UP),
            default => throw new FormulaError('unexpected', (int) $pos, ['token' => (string) $op]),
        };
    }

    /** @param array<int, mixed> $node */
    private function call(array $node): BigDecimal|string|bool
    {
        [, $name, $args, $pos] = $node;

        switch ($name) {
            case 'si':
                return $this->bool($args[0]) ? $this->eval($args[1]) : $this->eval($args[2]);
            case 'min':
            case 'max':
                $values = array_map(fn ($a) => $this->num($a), $args);
                $pick = $values[0];
                foreach ($values as $v) {
                    $pick = ($name === 'min' ? $v->isLessThan($pick) : $v->isGreaterThan($pick)) ? $v : $pick;
                }

                return $pick;
            case 'abs':
                return $this->num($args[0])->abs();
            case 'redondear':
            case 'truncar':
                $scale = $this->num($args[1]);
                if (! $scale->isEqualTo($scale->toScale(0, RoundingMode::DOWN)) || $scale->isNegative()) {
                    throw new FormulaError('invalid_scale', (int) $pos);
                }

                return $this->num($args[0])->toScale($scale->toInt(), $name === 'redondear' ? RoundingMode::HALF_UP : RoundingMode::DOWN);
            case 'dias_entre':
                $a = $this->date($args[0], (int) $pos);
                $b = $this->date($args[1], (int) $pos);

                // Días calendario de a hasta b, ambos incluidos (0 si b es anterior a a).
                return BigDecimal::of(max(0, (int) $a->diffInDays($b, false) + 1));
        }
        throw new FormulaError('unknown_function', (int) $pos, ['name' => (string) $name]);
    }

    /** @param array<int, mixed> $node */
    private function num(array $node): BigDecimal
    {
        $v = $this->eval($node);
        if (! $v instanceof BigDecimal) {
            throw new FormulaError('not_a_number', $this->position($node));
        }

        return $v;
    }

    /** @param array<int, mixed> $node */
    private function bool(array $node): bool
    {
        $v = $this->eval($node);
        if (! is_bool($v)) {
            throw new FormulaError('not_a_condition', $this->position($node));
        }

        return $v;
    }

    /** @param array<int, mixed> $node */
    private function date(array $node, int $pos): CarbonImmutable
    {
        $v = $this->eval($node);
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            throw new FormulaError('not_a_date', $pos);
        }

        return CarbonImmutable::parse($v);
    }

    /** @param array<int, mixed> $node */
    private function position(array $node): int
    {
        $last = $node[count($node) - 1];

        return is_int($last) ? $last : 0;
    }
}
