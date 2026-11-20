<?php

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Formula\FormulaError;
use App\Modules\Payroll\Domain\Formula\Parser;

/**
 * NOM-4 · CPT-2 · CPT-3 · De qué conceptos depende uno: los que nombra su fórmula o su condición y, por
 * cada base de cálculo que usa (base.pension, base.essalud…), todos los conceptos que afectan a esa base.
 * Lo usan el núcleo, el orden de cálculo y la revisión de fórmulas, para que los tres vean el mismo grafo.
 */
final class ConceptDependencies
{
    /**
     * @param  array<string, ConceptDefinition>  $all  conceptos aplicables por código
     * @return array{formula: array<int, mixed>|null, condition: array<int, mixed>|null, depends_on: list<string>}
     *
     * @throws FormulaError si la fórmula o la condición no se pueden leer
     */
    public static function of(ConceptDefinition $c, array $all, Parser $parser): array
    {
        $formula = $c->formula === null || trim($c->formula) === '' ? null : $parser->parse($c->formula);
        $condition = $c->condition === null || trim($c->condition) === '' ? null : $parser->parse($c->condition);
        $concepts = [];
        $bases = [];
        foreach ([$formula, $condition] as $tree) {
            if ($tree === null) {
                continue;
            }
            $refs = Parser::references($tree);
            $concepts = array_merge($concepts, $refs['concept']);
            $bases = array_merge($bases, $refs['base']);
        }

        return ['formula' => $formula, 'condition' => $condition, 'depends_on' => self::expand($c->code, $concepts, $bases, $all)];
    }

    /**
     * @param  list<string>  $concepts
     * @param  list<string>  $bases
     * @param  array<string, ConceptDefinition>  $all
     * @return list<string>
     */
    public static function expand(string $self, array $concepts, array $bases, array $all): array
    {
        $out = $concepts;
        foreach (array_unique($bases) as $b) {
            foreach ($all as $code => $other) {
                if ($code !== $self && $other->affects($b)) {
                    $out[] = $code;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
