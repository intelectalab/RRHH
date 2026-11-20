<?php

namespace App\Modules\Workflow\Domain\Flow;

/**
 * WFL-6 · Grafo de un flujo del constructor visual.
 *
 * graph = {nodes: [{id, kind, label?, x?, y?, config?}], edges: [{from, to, branch?: yes|no}]}
 *
 * Nodos: start (disparador), condition (dos salidas: yes y no), approval, action, notification, wait y end.
 * El diseño se valida antes de publicar: un inicio, al menos un fin, todo nodo conectado y alcanzable,
 * toda salida definida, sin ciclos (un flujo no puede dispararse en bucle a sí mismo).
 */
final class FlowGraph
{
    public const KINDS = ['start', 'condition', 'approval', 'action', 'notification', 'wait', 'end'];

    public const MAX_NODES = 60;

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    /**
     * Normaliza la forma (lista de nodos y aristas con sus claves). No valida el diseño.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null null si la forma no es válida
     */
    public static function normalize(mixed $graph): ?array
    {
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null) || ! is_array($graph['edges'] ?? [])) {
            return null;
        }
        $nodes = [];
        foreach (array_values($graph['nodes']) as $n) {
            if (! is_array($n) || ! is_string($n['id'] ?? null) || preg_match(self::ID_PATTERN, $n['id']) !== 1 || ! in_array($n['kind'] ?? null, self::KINDS, true)) {
                return null;
            }
            $nodes[] = [
                'id' => $n['id'],
                'kind' => $n['kind'],
                'label' => mb_substr(trim((string) (is_scalar($n['label'] ?? null) ? $n['label'] : '')), 0, 120),
                'x' => is_numeric($n['x'] ?? null) ? (int) $n['x'] : 0,
                'y' => is_numeric($n['y'] ?? null) ? (int) $n['y'] : 0,
                'config' => is_array($n['config'] ?? null) ? $n['config'] : [],
            ];
        }
        $edges = [];
        foreach (array_values((array) ($graph['edges'] ?? [])) as $e) {
            if (! is_array($e) || ! is_string($e['from'] ?? null) || ! is_string($e['to'] ?? null)) {
                return null;
            }
            $branch = $e['branch'] ?? null;
            $edges[] = ['from' => $e['from'], 'to' => $e['to'], 'branch' => in_array($branch, ['yes', 'no'], true) ? $branch : null];
        }
        if (count($nodes) > self::MAX_NODES || count($edges) > self::MAX_NODES * 2) {
            return null;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Problemas de diseño que impiden publicar. $configErrors valida la configuración de cada nodo y
     * devuelve los campos con error.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  callable(array<string, mixed>): list<string>  $configErrors
     * @return list<array{code: string, node: string|null, fields?: list<string>}>
     */
    public static function issues(array $graph, callable $configErrors): array
    {
        $issues = [];
        $byId = [];
        foreach ($graph['nodes'] as $n) {
            if (isset($byId[$n['id']])) {
                $issues[] = ['code' => 'duplicate_node', 'node' => (string) $n['id']];
            }
            $byId[$n['id']] = $n;
        }
        $starts = array_values(array_filter($graph['nodes'], fn ($n) => $n['kind'] === 'start'));
        if ($starts === []) {
            $issues[] = ['code' => 'no_start', 'node' => null];
        } elseif (count($starts) > 1) {
            $issues[] = ['code' => 'many_starts', 'node' => (string) $starts[1]['id']];
        }
        if (array_filter($graph['nodes'], fn ($n) => $n['kind'] === 'end') === []) {
            $issues[] = ['code' => 'no_end', 'node' => null];
        }

        $out = [];
        $in = [];
        foreach ($graph['edges'] as $e) {
            if (! isset($byId[$e['from']], $byId[$e['to']]) || $e['from'] === $e['to']) {
                $issues[] = ['code' => 'bad_edge', 'node' => isset($byId[$e['from']]) ? (string) $e['from'] : null];

                continue;
            }
            $out[$e['from']][] = $e;
            $in[$e['to']] = true;
        }

        foreach ($graph['nodes'] as $n) {
            $id = (string) $n['id'];
            $exits = $out[$id] ?? [];
            if ($n['kind'] !== 'start' && ! isset($in[$id]) && $exits === []) {
                $issues[] = ['code' => 'disconnected', 'node' => $id];

                continue;
            }
            if ($n['kind'] === 'end') {
                if ($exits !== []) {
                    $issues[] = ['code' => 'end_has_exit', 'node' => $id];
                }
            } elseif ($exits === []) {
                $issues[] = ['code' => 'no_exit', 'node' => $id];
            } elseif ($n['kind'] === 'condition') {
                $branches = array_map(fn ($e) => $e['branch'], $exits);
                sort($branches);
                if ($branches !== ['no', 'yes']) {
                    $issues[] = ['code' => 'condition_branches', 'node' => $id];
                }
            } elseif (count($exits) > 1) {
                $issues[] = ['code' => 'many_exits', 'node' => $id];
            }
            if ($n['kind'] !== 'start' && ! isset($in[$id]) && $exits !== []) {
                $issues[] = ['code' => 'no_entry', 'node' => $id];
            }
            $fields = $configErrors($n);
            if ($fields !== []) {
                $issues[] = ['code' => 'invalid_config', 'node' => $id, 'fields' => $fields];
            }
        }

        if (count($starts) === 1) {
            $reached = self::reachable($graph, (string) $starts[0]['id']);
            foreach ($graph['nodes'] as $n) {
                if (! isset($reached[$n['id']]) && ! in_array($n['id'], array_column(array_filter($issues, fn ($i) => $i['code'] === 'disconnected'), 'node'), true)) {
                    $issues[] = ['code' => 'unreachable', 'node' => (string) $n['id']];
                }
            }
            if (self::hasCycle($graph, (string) $starts[0]['id'])) {
                $issues[] = ['code' => 'cycle', 'node' => null];
            }
        }

        return $issues;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, mixed>|null
     */
    public static function start(array $graph): ?array
    {
        foreach ($graph['nodes'] as $n) {
            if ($n['kind'] === 'start') {
                return $n;
            }
        }

        return null;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, mixed>|null
     */
    public static function node(array $graph, string $id): ?array
    {
        foreach ($graph['nodes'] as $n) {
            if ($n['id'] === $id) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Siguiente nodo: la única salida, o la de la rama indicada en una condición.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    public static function next(array $graph, string $from, ?string $branch = null): ?string
    {
        foreach ($graph['edges'] as $e) {
            if ($e['from'] === $from && ($branch === null || $e['branch'] === $branch)) {
                return (string) $e['to'];
            }
        }

        return null;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, true>
     */
    private static function reachable(array $graph, string $start): array
    {
        $seen = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($graph['edges'] as $e) {
                if ($e['from'] === $id && ! isset($seen[$e['to']])) {
                    $seen[(string) $e['to']] = true;
                    $queue[] = (string) $e['to'];
                }
            }
        }

        return $seen;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    private static function hasCycle(array $graph, string $start): bool
    {
        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $graph): bool {
            $state[$id] = 1;
            foreach ($graph['edges'] as $e) {
                if ($e['from'] !== $id) {
                    continue;
                }
                $to = (string) $e['to'];
                if (($state[$to] ?? 0) === 1) {
                    return true;
                }
                if (($state[$to] ?? 0) === 0 && $visit($to)) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };

        return $visit($start);
    }
}
