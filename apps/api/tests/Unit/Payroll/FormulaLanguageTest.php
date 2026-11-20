<?php

/**
 * NOM-3 · NOM-4 · Lenguaje de fórmulas (ADR 0015) y orden de cálculo: casos de entrada y salida, errores
 * con posición, aislamiento (sin eval ni acceso fuera del contexto) y ciclos con los conceptos involucrados.
 */

use App\Modules\Payroll\Domain\Engine\CycleDetected;
use App\Modules\Payroll\Domain\Engine\DependencyGraph;
use App\Modules\Payroll\Domain\Formula\Evaluator;
use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\FormulaScope;
use App\Modules\Payroll\Domain\Formula\Parser;
use Brick\Math\BigDecimal;

function nomScope(array $vars = [], array $concepts = [], array $params = []): FormulaScope
{
    return new class($vars, $concepts, $params) implements FormulaScope
    {
        public function __construct(private array $vars, private array $concepts, private array $params) {}

        public function variable(string $name): BigDecimal|string|bool
        {
            $v = $this->vars[$name] ?? throw new FormulaError('unknown_variable', 0, ['name' => $name]);

            return is_int($v) || (is_string($v) && is_numeric($v)) ? BigDecimal::of($v) : $v;
        }

        public function concept(string $code): BigDecimal
        {
            return BigDecimal::of($this->concepts[$code] ?? throw new FormulaError('unknown_concept', 0, ['name' => $code]));
        }

        public function parameter(string $code): BigDecimal
        {
            return BigDecimal::of($this->params[$code] ?? throw new FormulaError('unknown_parameter', 0, ['name' => $code]));
        }

        public function incident(string $code, string $field): BigDecimal
        {
            return BigDecimal::of($field === 'amount' ? '120.50' : '3');
        }

        public function base(string $code): BigDecimal
        {
            return BigDecimal::of($code === 'pension' ? '1500' : '0');
        }
    };
}

function nomEval(string $formula, FormulaScope $scope): string
{
    $v = (new Evaluator($scope))->eval((new Parser)->parse($formula));

    return $v instanceof BigDecimal ? (string) $v : var_export($v, true);
}

test('NOM-3 · evalúa operaciones, precedencia, condiciones y funciones con decimales exactos', function (string $formula, string $expected): void {
    $scope = nomScope(['sueldo' => '3000', 'dias' => '15', 'regimen' => 'general', 'activo' => true, 'desde' => '2026-03-10', 'hasta' => '2026-03-31'],
        ['BASICO' => '1500.00'], ['tasa' => '0.10']);
    expect(nomEval($formula, $scope))->toBe($expected);
})->with([
    ['1 + 2 * 3', '7'],
    ['(1 + 2) * 3', '9'],
    ['-2 + 5', '3'],
    ['sueldo / 30 * dias', '1500.0000000000'],
    ['0.1 + 0.2', '0.3'],
    ['concepto.BASICO * param.tasa', '150.0000'],
    ['si(regimen = "general", 1, 2)', '1'],
    ['si(activo y no (dias > 20), 10, 20)', '10'],
    ['si(dias >= 15 o falso, 1, 0)', '1'],
    ['min(sueldo, 2500, 4000)', '2500'],
    ['max(1, 2.5)', '2.5'],
    ['redondear(10 / 3, 2)', '3.33'],
    ['truncar(2.999, 2)', '2.99'],
    ['abs(-4.5)', '4.5'],
    ['dias_entre(desde, hasta)', '22'],
    ['incidencia.BONO + cantidad.BONO', '123.50'],
    ['dias <> 15', 'false'],
]);

test('NOM-3 · una fórmula inválida se rechaza con el error y la posición', function (string $formula, string $code, int $position): void {
    try {
        (new Parser)->parse($formula);
        $this->fail('Debió fallar: '.$formula);
    } catch (FormulaError $e) {
        expect($e->key)->toBe($code)->and($e->position)->toBe($position)->and($e->toArray()['message'])->not->toBe('');
    }
})->with([
    ['', 'empty', 0],
    ['1 +', 'unexpected_end', 3],
    ['(1 + 2', 'unexpected_end', 6],
    ['1 + 2)', 'unexpected', 5],
    ['sueldo * $x', 'invalid_character', 9],
    ['system("ls")', 'unknown_function', 0],
    ['redondear(1)', 'arity', 0],
    ['otro.X + 1', 'unknown_prefix', 0],
    ['"sin cerrar', 'unterminated_text', 0],
]);

test('NOM-3 · el intérprete no ejecuta código ni lee nada fuera del contexto', function (): void {
    foreach (['phpinfo()', 'file_get_contents("/etc/passwd")', 'eval("1")', 'exit()'] as $f) {
        expect(fn () => (new Parser)->parse($f))->toThrow(FormulaError::class);
    }
    expect(fn () => nomEval('env_secreto + 1', nomScope()))->toThrow(FormulaError::class);
    expect(fn () => nomEval('1 / (2 - 2)', nomScope()))->toThrow(FormulaError::class);
    expect(fn () => nomEval('si(1, 2, 3)', nomScope()))->toThrow(FormulaError::class);
});

test('NOM-3 · las referencias de una fórmula se listan sin repetir', function (): void {
    $refs = Parser::references((new Parser)->parse('concepto.A + concepto.B * param.uit + si(dias > 0, concepto.A, incidencia.C) + cantidad.D'));
    expect($refs['concept'])->toBe(['A', 'B'])->and($refs['param'])->toBe(['uit'])->and($refs['var'])->toBe(['dias'])
        ->and($refs['incident'])->toBe(['C'])->and($refs['quantity'])->toBe(['D']);
});

test('NOM-4 · el orden respeta dependencias y es determinista; un ciclo indica los conceptos', function (): void {
    $deps = ['NETO' => ['BRUTO', 'DESC'], 'DESC' => ['BRUTO'], 'BRUTO' => ['BASICO', 'BONO'], 'BASICO' => [], 'BONO' => []];
    $order = DependencyGraph::order($deps, ['BONO' => 2, 'BASICO' => 1]);
    expect($order)->toBe(['BASICO', 'BONO', 'BRUTO', 'DESC', 'NETO'])
        ->and(DependencyGraph::order(array_reverse($deps, true), ['BONO' => 2, 'BASICO' => 1]))->toBe($order);

    try {
        DependencyGraph::order(['A' => ['B'], 'B' => ['C'], 'C' => ['A'], 'D' => []]);
        $this->fail('Debió detectar el ciclo');
    } catch (CycleDetected $e) {
        expect($e->concepts)->toBe(['A', 'B', 'C']);
    }
});

test('CPT-3 · una fórmula lee una base de cálculo con el prefijo base.', function (): void {
    expect(nomEval('base.pension * 2', nomScope()))->toBe('3000')
        ->and(Parser::references((new Parser)->parse('base.pension + base.essalud'))['base'])->toBe(['pension', 'essalud']);
});
