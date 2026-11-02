<?php
/**
 * filtros.php — Vistas y filtros de la lista de Personal (se usan en la pantalla y al exportar).
 */
function vistas_personal(): array {
    $aviso = add_dias(HOY, (int)param('avisoContratoDias'));
    return [
        'activos'     => ['Activos', fn($c) => $c['estado'] === 'Activo'],
        'vencer'      => ['Contrato por vencer', fn($c) => $c['estado'] === 'Activo' && $c['finContrato'] && $c['finContrato'] <= $aviso],
        'incompletos' => ['Datos incompletos', fn($c) => incompleta($c)],
        'cesados'     => ['Cesados', fn($c) => $c['estado'] === 'Cesado'],
    ];
}
/** Aplica vista, filtros, búsqueda y orden a los colaboradores visibles. */
function filtrar_personal(array $q): array {
    $vistas = vistas_personal(); $vista = $vistas[$q['vista'] ?? 'activos'] ?? $vistas['activos'];
    $txt = mb_strtolower(trim((string)($q['q'] ?? '')));
    $l = array_filter(colabs_visibles(), function ($c) use ($vista, $q, $txt) {
        if (!($vista[1])($c)) return false;
        if (!empty($q['area']) && $c['areaId'] !== (int)$q['area']) return false;
        if (!empty($q['sede']) && $c['sedeId'] !== (int)$q['sede']) return false;
        if (!empty($q['contrato']) && $c['tipoContrato'] !== $q['contrato']) return false;
        return $txt === '' || str_contains(mb_strtolower(nom($c).' '.$c['nroDoc'].' '.$c['codigo'].' '.puesto($c)), $txt);
    });
    $ord = $q['ord'] ?? (($q['vista'] ?? '') === 'cesados' ? 'cese' : (($q['vista'] ?? '') === 'vencer' ? 'contrato' : 'nom'));
    $dir = ($q['dir'] ?? (($q['vista'] ?? '') === 'cesados' ? 'desc' : 'asc')) === 'desc' ? -1 : 1;
    $clave = ['nom' => fn($c) => nom($c), 'area' => fn($c) => area($c).nom($c), 'ingreso' => fn($c) => $c['fecIngreso'], 'contrato' => fn($c) => ($c['finContrato'] ?: '9999').nom($c), 'cese' => fn($c) => $c['fecCese']][$ord] ?? fn($c) => nom($c);
    $norm = fn($t) => strtolower(sin_tildes((string)$t));
    usort($l, fn($a, $b) => $dir * strcmp($norm($clave($a)), $norm($clave($b))));
    return array_values($l);
}
