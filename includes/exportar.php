<?php
/**
 * exportar.php — Descargas en CSV (abren directo en Excel, separador «;») y búsqueda rápida del encabezado.
 */

function enviar_csv(string $nombre, array $filas): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$nombre.'"');
    echo "\xEF\xBB\xBF";                                   // BOM para que Excel lea las tildes
    $o = fopen('php://output', 'w');
    foreach ($filas as $f) fputcsv($o, $f, ';');
    fclose($o);
}

function exportar(string $que): void {
    if ($que === 'colaboradores' && puede('personal.ver')) {
        
        $l = filtrar_personal($_GET); $sue = puede('personal.sueldo');
        if (!empty($_GET['ids'])) { $ids = array_map('intval', explode(',', $_GET['ids'])); $l = array_filter($l, fn($c) => in_array($c['id'], $ids, true)); }
        $filas = [array_merge(['Código','Apellido paterno','Apellido materno','Nombres','DNI','Puesto','Área','Sede','Ingreso','Contrato','Fin de contrato'], $sue ? ['Sueldo'] : [], ['Estado'])];
        foreach ($l as $c) $filas[] = array_merge([$c['codigo'],$c['apPat'],$c['apMat'],$c['nombres'],$c['nroDoc'],puesto($c),area($c),sede($c),f($c['fecIngreso']),$c['tipoContrato'],f($c['finContrato'])], $sue ? [$c['sueldo']] : [], [$c['estado']]);
        auditar('Exportación', 'Colaboradores: '.pl(count($l),'fila','filas'));
        enviar_csv('colaboradores_'.HOY.'.csv', $filas); return;
    }
    if ($que === 'asistencia' && puede('asistencia.ver')) {
        $fe = fecha_iso($_GET['fecha'] ?? '') ?? HOY; $filas = [['Colaborador','DNI','Área','Entrada','Salida','Tardanza (min)','Estado']];
        foreach (colabs_visibles() as $c) { if (!laborable($c, $fe) && !ausencia($c, $fe)) continue; $m = marca($c['id'], $fe);
            $filas[] = [nom($c), $c['nroDoc'], area($c), $m['entrada'] ?? '', $m['salida'] ?? '', $m ? estado_marca($m)['tard'] : '', COD[codigo_dia($c, $fe)]]; }
        auditar('Exportación', 'Asistencia del '.f($fe));
        enviar_csv('asistencia_'.$fe.'.csv', $filas); return;
    }
    if ($que === 'plantilla' && puede('personal.editar')) {
        $f = [COLUMNAS_IMPORTAR, ['Quispe','Huamán','Rosa Elena','45871236','14/05/1993','F','987654321','rosa.quispe@correo.pe','Sede Ate','Ventas','Asesor de Ventas','Oficina L-V','01/11/2026','Plazo fijo','30/04/2027','1800']];
        enviar_csv('plantilla_colaboradores.csv', $f); return;
    }
    if ($que === 'plantilla_marcaciones' && puede('asistencia.editar')) {
        $f = [COLUMNAS_MARCACIONES, ['45871236', '05/11/2026', '08:02', '17:30']];
        enviar_csv('plantilla_marcaciones.csv', $f); return;
    }
    http_response_code(403);
}

/** Búsqueda rápida (encabezado): devuelve hasta 8 coincidencias en JSON. */
function api_buscar(string $q): void {
    header('Content-Type: application/json');
    $q = mb_strtolower(trim($q)); $r = [];
    if ($q !== '' && puede('personal.ver')) foreach (colabs_visibles() as $c) {
        if (!str_contains(mb_strtolower(nom($c).' '.$c['nroDoc'].' '.$c['codigo'].' '.puesto($c)), $q)) continue;
        $r[] = ['url' => url('colaborador', ['id' => $c['id']]), 'nombre' => nom($c), 'sub' => puesto($c).' · DNI '.$c['nroDoc'].($c['estado'] === 'Cesado' ? ' · cesado' : ''), 'avatar' => avatar($c)];
        if (count($r) === 8) break;
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
}
