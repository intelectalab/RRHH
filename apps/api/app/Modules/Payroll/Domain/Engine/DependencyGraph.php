<?php

namespace App\Modules\Payroll\Domain\Engine;

/**
 * NOM-4 · Orden de cálculo a partir de las dependencias entre conceptos (concepto.X en la fórmula).
 * Determinista: entre los conceptos listos se toma primero el de menor orden de impresión y luego el código
 * (RN-NOM-02). Un ciclo se rechaza indicando los conceptos que lo forman (RN-NOM-06).
 */
final class DependencyGraph
{
    /**
     * @param  array<string, list<string>>  $dependencies  código => conceptos que usa (solo los del conjunto)
     * @param  array<string, int>  $sortOrder  código => orden de impresión (desempate)
     * @return list<string>
     *
     * @throws CycleDetected
     */
    public static function order(array $dependencies, array $sortOrder = []): array
    {
        $pending = [];
        $dependents = [];
        foreach ($dependencies as $code => $deps) {
            $deps = array_values(array_unique(array_filter($deps, fn ($d) => isset($dependencies[$d]))));
            $pending[$code] = count($deps);
            foreach ($deps as $d) {
                $dependents[$d][] = $code;
            }
        }
        $key = fn (string $c): string => sprintf('%012d|%s', ($sortOrder[$c] ?? 0) + 100000000000, $c);
        $ready = [];
        foreach ($pending as $code => $n) {
            if ($n === 0) {
                $ready[$key((string) $code)] = (string) $code;
            }
        }
        $order = [];
        while ($ready !== []) {
            ksort($ready, SORT_STRING);
            $code = array_shift($ready);
            $order[] = $code;
            foreach ($dependents[$code] ?? [] as $next) {
                if (--$pending[$next] === 0) {
                    $ready[$key($next)] = $next;
                }
            }
        }
        if (count($order) < count($pending)) {
            $left = array_keys(array_filter($pending, fn ($n) => $n > 0));
            throw new CycleDetected(self::cycle($dependencies, array_map('strval', $left)));
        }

        return $order;
    }

    /**
     * Un ciclo concreto entre los conceptos que quedaron sin ordenar (A → B → A), para explicarlo.
     *
     * @param  array<string, list<string>>  $dependencies
     * @param  list<string>  $left
     * @return list<string>
     */
    private static function cycle(array $dependencies, array $left): array
    {
        sort($left);
        $inLeft = array_flip($left);
        foreach ($left as $start) {
            $path = [$start];
            $seen = [$start => 0];
            $node = $start;
            while (true) {
                $next = null;
                $deps = $dependencies[$node] ?? [];
                sort($deps);
                foreach ($deps as $d) {
                    if (isset($inLeft[$d])) {
                        $next = $d;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                if (isset($seen[$next])) {
                    return array_slice($path, $seen[$next]);
                }
                $seen[$next] = count($path);
                $path[] = $next;
                $node = $next;
            }
        }

        return $left;
    }
}
