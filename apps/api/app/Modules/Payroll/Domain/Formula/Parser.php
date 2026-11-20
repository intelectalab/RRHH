<?php

namespace App\Modules\Payroll\Domain\Formula;

/**
 * NOM-3 · ADR 0015 · Lenguaje de fórmulas propio y pequeño. Se analiza a un árbol que solo el intérprete
 * de este módulo sabe recorrer: no hay eval, ni llamadas a PHP, ni acceso a nada fuera del contexto.
 *
 *   expresión  := o
 *   o          := y { ("o" | "or") y }
 *   y          := no { ("y" | "and") no }
 *   no         := ("no" | "not") no | comparación
 *   comparación:= suma [ ("=" | "<>" | "<" | "<=" | ">" | ">=") suma ]
 *   suma       := producto { ("+" | "-") producto }
 *   producto   := unario { ("*" | "/") unario }
 *   unario     := "-" unario | primario
 *   primario   := número | "texto" | verdadero | falso | nombre | prefijo.CÓDIGO | función "(" args ")" | "(" expresión ")"
 *
 * Prefijos: concepto.X (resultado de otro concepto), param.x (parámetro legal con vigencia a la fecha del
 * periodo), incidencia.X (monto de las incidencias del periodo) y cantidad.X (cantidad de esas incidencias).
 * Un nombre sin prefijo es un dato del contexto de cálculo (ContextVariables).
 */
final class Parser
{
    public const FUNCTIONS = ['si' => [3, 3], 'min' => [1, 20], 'max' => [1, 20], 'redondear' => [2, 2], 'truncar' => [2, 2], 'abs' => [1, 1], 'dias_entre' => [2, 2]];

    public const PREFIXES = ['concepto' => 'concept', 'param' => 'param', 'incidencia' => 'incident', 'cantidad' => 'quantity', 'base' => 'base'];

    private const KEYWORDS = ['o' => 'or', 'or' => 'or', 'y' => 'and', 'and' => 'and', 'no' => 'not', 'not' => 'not'];

    /** @var list<array{0: string, 1: string, 2: int}> */
    private array $tokens = [];

    private int $i = 0;

    /**
     * @return array<int, mixed> árbol de la fórmula
     */
    public function parse(string $formula): array
    {
        $this->tokens = $this->tokenize($formula);
        $this->i = 0;
        if ($this->tokens[0][0] === 'end') {
            throw new FormulaError('empty');
        }
        $node = $this->orExpr();
        $t = $this->peek();
        if ($t[0] !== 'end') {
            throw new FormulaError('unexpected', $t[2], ['token' => $t[1]]);
        }

        return $node;
    }

    /**
     * Referencias de un árbol: concept, param, incident, quantity, base (CPT-3) y var (sin repetir, en orden de aparición).
     *
     * @param  array<int, mixed>  $node
     * @return array<string, list<string>>
     */
    public static function references(array $node): array
    {
        $out = ['concept' => [], 'param' => [], 'incident' => [], 'quantity' => [], 'base' => [], 'var' => []];
        $walk = function (array $n) use (&$walk, &$out): void {
            if ($n[0] === 'ref') {
                if (! in_array($n[2], $out[$n[1]], true)) {
                    $out[$n[1]][] = $n[2];
                }

                return;
            }
            foreach (array_slice($n, 1) as $child) {
                if (is_array($child) && isset($child[0]) && is_string($child[0])) {
                    $walk($child);
                } elseif (is_array($child)) {
                    foreach ($child as $c) {
                        if (is_array($c)) {
                            $walk($c);
                        }
                    }
                }
            }
        };
        $walk($node);

        return $out;
    }

    /** @return array<int, mixed> */
    private function orExpr(): array
    {
        $left = $this->andExpr();
        while ($this->isKeyword('or')) {
            $pos = $this->next()[2];
            $left = ['bin', 'or', $left, $this->andExpr(), $pos];
        }

        return $left;
    }

    /** @return array<int, mixed> */
    private function andExpr(): array
    {
        $left = $this->notExpr();
        while ($this->isKeyword('and')) {
            $pos = $this->next()[2];
            $left = ['bin', 'and', $left, $this->notExpr(), $pos];
        }

        return $left;
    }

    /** @return array<int, mixed> */
    private function notExpr(): array
    {
        if ($this->isKeyword('not')) {
            $pos = $this->next()[2];

            return ['not', $this->notExpr(), $pos];
        }

        return $this->comparison();
    }

    /** @return array<int, mixed> */
    private function comparison(): array
    {
        $left = $this->sum();
        $t = $this->peek();
        if ($t[0] === 'op' && in_array($t[1], ['=', '<>', '<', '<=', '>', '>='], true)) {
            $this->next();

            return ['bin', $t[1], $left, $this->sum(), $t[2]];
        }

        return $left;
    }

    /** @return array<int, mixed> */
    private function sum(): array
    {
        $left = $this->product();
        while (($t = $this->peek())[0] === 'op' && in_array($t[1], ['+', '-'], true)) {
            $this->next();
            $left = ['bin', $t[1], $left, $this->product(), $t[2]];
        }

        return $left;
    }

    /** @return array<int, mixed> */
    private function product(): array
    {
        $left = $this->unary();
        while (($t = $this->peek())[0] === 'op' && in_array($t[1], ['*', '/'], true)) {
            $this->next();
            $left = ['bin', $t[1], $left, $this->unary(), $t[2]];
        }

        return $left;
    }

    /** @return array<int, mixed> */
    private function unary(): array
    {
        $t = $this->peek();
        if ($t[0] === 'op' && $t[1] === '-') {
            $this->next();

            return ['neg', $this->unary(), $t[2]];
        }

        return $this->primary();
    }

    /** @return array<int, mixed> */
    private function primary(): array
    {
        $t = $this->next();
        if ($t[0] === 'num') {
            return ['num', $t[1]];
        }
        if ($t[0] === 'str') {
            return ['str', $t[1]];
        }
        if ($t[0] === 'op' && $t[1] === '(') {
            $node = $this->orExpr();
            $this->expect(')');

            return $node;
        }
        if ($t[0] !== 'ident') {
            throw new FormulaError($t[0] === 'end' ? 'unexpected_end' : 'unexpected', $t[2], ['token' => $t[1]]);
        }
        $name = $t[1];
        if ($this->peek()[0] === 'op' && $this->peek()[1] === '(') {
            return $this->call($name, $t[2]);
        }
        if ($name === 'verdadero' || $name === 'falso') {
            return ['bool', $name === 'verdadero'];
        }
        if (isset(self::KEYWORDS[$name])) {
            throw new FormulaError('unexpected', $t[2], ['token' => $name]);
        }
        $dot = strpos($name, '.');
        if ($dot === false) {
            return ['ref', 'var', $name, $t[2]];
        }
        $prefix = substr($name, 0, $dot);
        $code = substr($name, $dot + 1);
        if (! isset(self::PREFIXES[$prefix]) || $code === '' || str_contains($code, '.')) {
            throw new FormulaError('unknown_prefix', $t[2], ['name' => $name]);
        }

        return ['ref', self::PREFIXES[$prefix], $code, $t[2]];
    }

    /** @return array<int, mixed> */
    private function call(string $name, int $pos): array
    {
        if (! isset(self::FUNCTIONS[$name])) {
            throw new FormulaError('unknown_function', $pos, ['name' => $name]);
        }
        $this->expect('(');
        $args = [];
        if (! ($this->peek()[0] === 'op' && $this->peek()[1] === ')')) {
            $args[] = $this->orExpr();
            while ($this->peek()[0] === 'op' && $this->peek()[1] === ',') {
                $this->next();
                $args[] = $this->orExpr();
            }
        }
        $this->expect(')');
        [$min, $max] = self::FUNCTIONS[$name];
        if (count($args) < $min || count($args) > $max) {
            throw new FormulaError('arity', $pos, ['name' => $name, 'min' => $min, 'max' => $max]);
        }

        return ['call', $name, $args, $pos];
    }

    private function expect(string $op): void
    {
        $t = $this->next();
        if ($t[0] !== 'op' || $t[1] !== $op) {
            throw new FormulaError($t[0] === 'end' ? 'unexpected_end' : 'expected', $t[2], ['token' => $op]);
        }
    }

    private function isKeyword(string $kind): bool
    {
        $t = $this->peek();

        return $t[0] === 'ident' && (self::KEYWORDS[$t[1]] ?? null) === $kind;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function peek(): array
    {
        return $this->tokens[$this->i];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function next(): array
    {
        $t = $this->tokens[$this->i];
        if ($t[0] !== 'end') {
            $this->i++;
        }

        return $t;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function tokenize(string $s): array
    {
        $out = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;

                continue;
            }
            if (ctype_digit($c)) {
                preg_match('/\G\d+(\.\d+)?/', $s, $m, 0, $i);
                $num = $m[0] ?? $c;
                $out[] = ['num', $num, $i];
                $i += strlen($num);

                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]*)*/', $s, $m, 0, $i);
                $ident = $m[0] ?? $c;
                $out[] = ['ident', $ident, $i];
                $i += strlen($ident);

                continue;
            }
            if ($c === '"') {
                $end = strpos($s, '"', $i + 1);
                if ($end === false) {
                    throw new FormulaError('unterminated_text', $i);
                }
                $out[] = ['str', substr($s, $i + 1, $end - $i - 1), $i];
                $i = $end + 1;

                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['<>', '<=', '>='], true)) {
                $out[] = ['op', $two, $i];
                $i += 2;

                continue;
            }
            if (str_contains('+-*/(),=<>', $c)) {
                $out[] = ['op', $c, $i];
                $i++;

                continue;
            }
            throw new FormulaError('invalid_character', $i, ['token' => $c]);
        }
        $out[] = ['end', '', $n];

        return $out;
    }
}
