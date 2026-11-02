<?php
/**
 * reglas.php — Reglas de negocio: sesión y permisos, alcance de cada perfil,
 * nombres, asistencia (códigos del tareo), saldos de vacaciones y auditoría.
 */

const PERMISOS = [
    ['personal.ver','Personal','Ver colaboradores'],
    ['personal.editar','Personal','Registrar y editar colaboradores, movimientos y ceses'],
    ['personal.sueldo','Personal','Ver y modificar sueldos'],
    ['personal.sensible','Personal','Ver cuenta bancaria y datos de pensión'],
    ['organizacion.editar','Organización','Mantener empresas, sedes, áreas, puestos y horarios'],
    ['asistencia.ver','Asistencia','Ver marcaciones'],
    ['asistencia.editar','Asistencia','Registrar y corregir marcaciones'],
    ['vacaciones.ver','Vacaciones','Ver solicitudes y saldos'],
    ['vacaciones.aprobar','Vacaciones','Aprobar o rechazar solicitudes'],
    ['config.usuarios','Configuración','Usuarios, perfiles y parámetros'],
    ['config.auditoria','Configuración','Consultar la auditoría'],
];
const ALCANCES = ['T' => 'Toda la empresa', 'A' => 'Su área', 'P' => 'Solo sus datos'];
const COD = ['A'=>'Asistió','T'=>'Tardanza','F'=>'Falta','V'=>'Vacaciones','D'=>'Descanso médico','L'=>'Permiso','N'=>'No laborable','S'=>'Sin marcar','X'=>'Sin vínculo',''=>'Por venir'];

/* ---------- sesión y permisos ---------- */
function usuario(): array { return por_id('usuarios', $_SESSION['uid'] ?? 0); }
function rol(): array { return por_id('roles', usuario()['rolId'] ?? 0); }
function puede(string $p): bool { return in_array($p, rol()['permisos'] ?? [], true); }
function yo(): array { return por_id('colaboradores', usuario()['colabId'] ?? 0); }
function empresa_activa(): int { return (int)($_SESSION['emp'] ?? 0); }

function areas_bajo(int $id): array {
    $r = [$id];
    foreach (tabla('areas') as $a) if ($a['padreId'] === $id) $r = array_merge($r, areas_bajo($a['id']));
    return $r;
}
/** Filtra una lista de colaboradores según el alcance del perfil (toda la empresa, su área o solo sus datos). */
function alcance(array $lista): array {
    $al = rol()['alcance'] ?? 'P'; $me = yo();
    if ($al === 'T') return $lista;
    if ($al === 'A' && $me) { $ar = areas_bajo($me['areaId']); return array_values(array_filter($lista, fn($c) => in_array($c['areaId'], $ar, true))); }
    return array_values(array_filter($lista, fn($c) => $me && $c['id'] === $me['id']));
}
function colabs_visibles(): array {
    $emp = empresa_activa();
    return array_values(array_filter(alcance(tabla('colaboradores')), fn($c) => !$emp || $c['empresaId'] === $emp));
}
function activos(): array { return array_values(array_filter(colabs_visibles(), fn($c) => $c['estado'] === 'Activo')); }
function puede_ver_colab(array $c): bool { foreach (alcance(tabla('colaboradores')) as $x) if ($x['id'] === $c['id']) return true; return false; }

function auditar(string $accion, string $detalle, bool $sensible = false): void {
    insertar('auditoria', [
        'fecha'   => date('Y-m-d H:i'),
        'usuario' => usuario()['nombre'] ?? 'Sistema',
        'accion'  => $accion,
        'detalle' => $detalle,
        'sens'    => $sensible ? 1 : 0,
    ]);
}

/* ---------- nombres: «Apellidos, Nombres» en tablas; «Nombres Apellido» en textos ---------- */
function nom(array $c): string { return $c ? $c['apPat'].' '.$c['apMat'].', '.$c['nombres'] : ''; }
function nomc(array $c): string { return $c ? explode(' ', $c['nombres'])[0].' '.$c['apPat'] : ''; }
function puesto(array $c): string { return por_id('puestos', $c['puestoId'])['nombre'] ?? ''; }
function area(array $c): string { return por_id('areas', $c['areaId'])['nombre'] ?? ''; }
function sede(array $c): string { return por_id('sedes', $c['sedeId'])['nombre'] ?? ''; }

/* ---------- asistencia ---------- */
function marca(int $cid, string $fecha): ?array {
    static $idx = null;
    if (isset($GLOBALS['_reiniciar_marcas'])) { $idx = null; unset($GLOBALS['_reiniciar_marcas']); }
    if ($idx === null) { $idx = []; foreach (tabla('marcaciones') as $m) $idx[$m['colabId'].'|'.$m['fecha']] = $m; }
    return $idx["$cid|$fecha"] ?? null;
}
function marcas_de(int $cid): array { return array_values(array_filter(tabla('marcaciones'), fn($m) => $m['colabId'] === $cid)); }
function horario(array $c): array { return por_id('horarios', $c['horarioId']); }
function estado_marca(array $m): array {
    $h = horario(por_id('colaboradores', $m['colabId']));
    return ['tard' => max(0, min_a_hora($m['entrada']) - min_a_hora($h['entrada']) - (int)$h['tolerancia']), 'min' => $m['salida'] ? min_a_hora($m['salida']) - min_a_hora($m['entrada']) : null];
}
function feriado(string $fecha): ?array {
    static $idx = null;
    if ($idx === null || isset($GLOBALS['_reiniciar_feriados'])) {
        $idx = [];
        unset($GLOBALS['_reiniciar_feriados']);
        foreach (tabla('feriados') as $f) {
            $idx[$f['fecha']] = $f;
        }
    }
    return $idx[$fecha] ?? null;
}

function laborable(array $c, string $x): bool {
    if ($x < $c['fecIngreso'] || ($c['fecCese'] && $x > $c['fecCese'])) return false;
    if (feriado($x)) return false;
    return in_array((string)dia_sem($x), explode(',', horario($c)['dias']), true);
}
function ausencias_de(int $cid): array {
    static $idx = null;
    if (isset($GLOBALS['_reiniciar_aus'])) { $idx = null; unset($GLOBALS['_reiniciar_aus']); }
    if ($idx === null) { $idx = []; foreach (tabla('vacaciones') as $v) if ($v['estado'] === 'Aprobada') $idx[$v['colabId']][] = $v; }
    return $idx[$cid] ?? [];
}
function ausencia(array $c, string $x): ?array { foreach (ausencias_de($c['id']) as $v) if ($v['desde'] <= $x && $v['hasta'] >= $x) return $v; return null; }
/** Código de un día para el tareo: A, T, F, V, D, L, N (no laborable), S (sin marcar hoy), X (sin vínculo), '' (por venir). */
function codigo_dia(array $c, string $x): string {
    if ($x < $c['fecIngreso'] || ($c['fecCese'] && $x > $c['fecCese'])) return 'X';
    if ($a = ausencia($c, $x)) return $a['tipo'] === 'Descanso médico' ? 'D' : ($a['tipo'] === 'Permiso' ? 'L' : 'V');
    if ($x > HOY) return '';
    if (!laborable($c, $x)) return 'N';
    $m = marca($c['id'], $x);
    if (!$m) return $x < HOY ? 'F' : 'S';
    return estado_marca($m)['tard'] ? 'T' : 'A';
}
/** Resumen de asistencia de una persona en un rango. */
function resumen_asistencia(array $c, string $ini, string $fin): array {
    $r = ['A'=>0,'T'=>0,'F'=>0,'V'=>0,'D'=>0,'L'=>0,'min'=>0,'prog'=>0];
    for ($x = $ini; $x <= $fin; $x = add_dias($x, 1)) {
        $k = codigo_dia($c, $x);
        if (isset($r[$k])) $r[$k]++;
        if (in_array($k, ['A','T','F'], true)) $r['prog']++;
        if ($k === 'T') $r['min'] += estado_marca(marca($c['id'], $x))['tard'];
    }
    return $r;
}

/* ---------- vacaciones: periodos de un año de servicio; lo gozado consume primero el periodo más antiguo ---------- */
function periodos_vac(array $c): array {
    $anios = intdiv(dif_dias($c['fecIngreso'], $c['fecCese'] ?: HOY), 365); $per = [];
    for ($i = 0; $i < $anios; $i++) $per[] = ['ini' => add_meses($c['fecIngreso'], 12*$i), 'fin' => add_dias(add_meses($c['fecIngreso'], 12*($i+1)), -1), 'vence' => add_dias(add_meses($c['fecIngreso'], 12*($i+2)), -1), 'dias' => (int)param('diasVacAnio'), 'usados' => 0];
    $usar = 0; foreach (tabla('vacaciones') as $v) if ($v['colabId'] === $c['id'] && $v['tipo'] === 'Vacaciones' && $v['estado'] === 'Aprobada') $usar += $v['dias'];
    foreach ($per as &$p) { $u = min($p['dias'], $usar); $p['usados'] = $u; $usar -= $u; }
    return $per;
}
function saldo_vac(array $c): array {
    $per = periodos_vac($c); $goz = $prog = $pend = 0;
    foreach (tabla('vacaciones') as $v) {
        if ($v['colabId'] !== $c['id'] || $v['tipo'] !== 'Vacaciones') continue;
        if ($v['estado'] === 'Aprobada') { if ($v['desde'] <= HOY) $goz += $v['dias']; else $prog += $v['dias']; }
        if ($v['estado'] === 'Pendiente') $pend += $v['dias'];
    }
    $gan = count($per) * (int)param('diasVacAnio'); $viejo = null;
    foreach ($per as $p) if ($p['usados'] < $p['dias']) { $viejo = $p; break; }
    return ['anios' => count($per), 'ganados' => $gan, 'gozados' => $goz, 'prog' => $prog, 'pend' => $pend, 'saldo' => $gan - $goz - $prog, 'viejo' => $viejo, 'per' => $per];
}
/** Personas del área ausentes en el mismo rango que una solicitud. */
function ausentes_area(array $v): array {
    $c = por_id('colaboradores', $v['colabId']); $r = [];
    foreach (tabla('vacaciones') as $x) if ($x['id'] !== $v['id'] && $x['estado'] === 'Aprobada' && $x['desde'] <= $v['hasta'] && $x['hasta'] >= $v['desde']) {
        $o = por_id('colaboradores', $x['colabId']); if ($o && $o['areaId'] === $c['areaId'] && $o['id'] !== $c['id']) $r[$o['id']] = $o;
    }
    return array_values($r);
}
/** Plantilla activa en una fecha. */
function plantilla_al(string $fecha, array $lista): int { return count(array_filter($lista, fn($c) => $c['fecIngreso'] <= $fecha && !($c['fecCese'] && $c['fecCese'] < $fecha))); }
/** Fichas incompletas: falta dirección, teléfono o centro de costo. */
function incompleta(array $c): bool { return $c['estado'] === 'Activo' && (!$c['direccion'] || !$c['telefono'] || !$c['ccId']); }

/* ---------- tablas de Organización (empresas, sedes, áreas, puestos, centros de costo, horarios) ---------- */
const TABLAS_ORG = [
    'empresas' => ['t'=>'Empresas','uno'=>'empresa','nuevo'=>'Nueva empresa','pagina'=>'organizacion','campos'=>[['razon','Razón social','text',1],['ruc','RUC','text',1],['direccion','Dirección','text',0]]],
    'sedes'    => ['t'=>'Sedes','uno'=>'sede','nuevo'=>'Nueva sede','pagina'=>'organizacion','campos'=>[['nombre','Nombre','text',1],['empresaId','Empresa','empresas',1],['ciudad','Ciudad','text',1],['direccion','Dirección','text',0]]],
    'areas'    => ['t'=>'Áreas','uno'=>'área','nuevo'=>'Nueva área','pagina'=>'organizacion','campos'=>[['nombre','Nombre','text',1],['padreId','Depende de','areas',0]]],
    'puestos'  => ['t'=>'Puestos','uno'=>'puesto','nuevo'=>'Nuevo puesto','pagina'=>'organizacion','campos'=>[['nombre','Nombre','text',1],['areaId','Área','areas',1]]],
    'ccs'      => ['t'=>'Centros de costo','uno'=>'centro de costo','nuevo'=>'Nuevo centro de costo','pagina'=>'organizacion','campos'=>[['codigo','Código','text',1],['nombre','Nombre','text',1]]],
    'horarios' => ['t'=>'Horarios','uno'=>'horario','nuevo'=>'Nuevo horario','pagina'=>'horarios','campos'=>[['nombre','Nombre','text',1],['entrada','Hora de entrada','time',1],['salida','Hora de salida','time',1],['dias','Días laborables','dias',1],['tolerancia','Tolerancia (minutos)','number',1]]],
];
const CAMPO_COLAB = ['empresas'=>'empresaId','sedes'=>'sedeId','areas'=>'areaId','puestos'=>'puestoId','ccs'=>'ccId','horarios'=>'horarioId'];
/** Cantidad de registros activos que dependen de un registro de Organización (impide archivarlo). */
function uso_registro(string $k, int $id): int {
    $n = count(array_filter(tabla('colaboradores'), fn($c) => $c['estado'] === 'Activo' && $c[CAMPO_COLAB[$k]] === $id));
    if ($k === 'areas') $n += count(array_filter(tabla('areas'), fn($a) => $a['padreId'] === $id && $a['activo'])) + count(array_filter(tabla('puestos'), fn($p) => $p['areaId'] === $id && $p['activo']));
    if ($k === 'empresas') $n += count(array_filter(tabla('sedes'), fn($s) => $s['empresaId'] === $id && $s['activo']));
    return $n;
}

/** A qué módulo pertenece cada pantalla (marca el módulo activo y elige la ayuda). */
const MODULO_DE = ['colaborador'=>'personal','colaborador_form'=>'personal','mi_ficha'=>'personal','organigrama'=>'personal','contratos'=>'personal','cumpleanos'=>'personal','importar'=>'personal',
    'mis_marcaciones'=>'asistencia','asistencia_mensual'=>'asistencia','horarios'=>'asistencia','importar_marcaciones'=>'asistencia',
    'mis_vacaciones'=>'vacaciones','vacaciones_calendario'=>'vacaciones','vacaciones_saldos'=>'vacaciones',
    'usuarios'=>'configuracion','perfiles'=>'configuracion','parametros'=>'configuracion','plan'=>'configuracion','auditoria'=>'configuracion'];
