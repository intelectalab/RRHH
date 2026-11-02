<?php
/**
 * componentes.php — Piezas de interfaz reutilizables: fotos con respaldo de iniciales,
 * logos y fotos de sedes, estados, etiquetas, estados vacíos, barras de saldo y celdas de nombre.
 */

/* Colores fijos para las iniciales cuando falta la foto (fondo suave + texto oscuro, contraste ≥ 4.5:1). */
const TONOS = [['#D5EAEE','#0B4350'],['#F6E7C8','#6B4708'],['#DCEEDF','#25563A'],['#E2E6F3','#34407A'],['#F5DFD7','#7E3420'],['#E9E2F0','#53396F'],['#DDE9F5','#24507E'],['#ECE8DA','#5A4C25']];

function existe_img(string $ruta): bool { static $c = []; return $c[$ruta] ??= is_file(DIR_IMG.'/'.$ruta); }

/**
 * Foto de una persona con respaldo: si assets/img/personas/<archivo> no existe (o falla al cargar),
 * se ven sus iniciales sobre un color fijo. Tamaños: s (24), normal (32), m (40), l (56), xl (96).
 */
function avatar(array $c, string $tam = '', bool $decorativo = true): string {
    if (!$c) return '';
    $t = TONOS[($c['id'] * 5 + strlen($c['apPat'])) % count(TONOS)];
    $ini = mb_strtoupper(mb_substr($c['nombres'],0,1).mb_substr($c['apPat'],0,1));
    $img = $c['foto'] && existe_img($c['foto']) ? '<img src="'.URL_IMG.'/'.e($c['foto']).'" alt="'.($decorativo ? '' : e(nomc($c))).'" loading="lazy" onerror="this.remove()">' : '';
    return '<span class="av '.($tam ? "av-$tam" : '').'" style="--av-bg:'.$t[0].';--av-fg:'.$t[1].'"'.($decorativo ? ' aria-hidden="true"' : '').'><span class="ini">'.$ini.'</span>'.$img.'</span>';
}

/** Grupo de fotos superpuestas (compañeros, ausentes…). */
function grupo_fotos(array $lista, int $max = 5): string {
    $h = '<span class="grupo">'; foreach (array_slice($lista,0,$max) as $c) $h .= '<span data-tip="'.e(nomc($c)).'">'.avatar($c,'s').'</span>';
    if (count($lista) > $max) $h .= '<span class="mas">+'.(count($lista)-$max).'</span>';
    return $h.'</span>';
}

/** Logo de empresa; si falta, monograma con las iniciales de la razón social. */
function logo_empresa(array $e, string $clase = ''): string {
    $palabras = array_values(array_filter(explode(' ', preg_replace('/S\.A\.C\.|S\.A\./', '', $e['razon']))));
    $ini = mb_strtoupper(mb_substr($palabras[0] ?? '',0,1).mb_substr($palabras[1] ?? '',0,1));
    $img = !empty($e['logo']) && existe_img($e['logo']) ? '<img src="'.URL_IMG.'/'.e($e['logo']).'" alt="Logo de '.e($e['razon']).'" onerror="this.remove()">' : '';
    return '<span class="logo-emp '.$clase.'"><span class="ini">'.$ini.'</span>'.$img.'</span>';
}

/** Identificación de sede con la ciudad registrada, sin fotografía ilustrativa. */
function foto_sede(array $s, string $clase = ''): string {
    return '<span class="foto-sede sede-identidad '.e($clase).'"><span class="ini">'.ic('map-pin').'<small>'.e($s['ciudad']).'</small></span></span>';
}

const ESTADO_CLASE = ['Activo'=>'ok','Cesado'=>'gr','Pendiente'=>'wa','Aprobada'=>'ok','Rechazada'=>'er','Anulada'=>'gr'];
function estado(string $e, string $txt = ''): string { return '<span class="st '.(ESTADO_CLASE[$e] ?? 'gr').'">'.e($txt ?: $e).'</span>'; }
function tag(string $txt, string $tipo = 'gr', string $tip = ''): string { return '<span class="tag '.$tipo.'"'.($tip ? ' data-tip="'.e($tip).'"' : '').'>'.e($txt).'</span>'; }
function vacio_d(): string { return '<span class="vacio-d">—</span>'; }

/** Estado vacío con mensaje y, opcionalmente, una acción. */
function estado_vacio(string $titulo, string $texto = '', string $accion = ''): string {
    return '<div class="vacio">'.ic('tray','vacio-ic').'<b>'.e($titulo).'</b>'.($texto ? '<p>'.e($texto).'</p>' : '').$accion.'</div>';
}

/** Celda de tabla: nombre enlazado a la ficha + dato secundario; con foto opcional. */
function celda_nombre(array $c, ?string $sub = null, bool $foto = true, string $tab = ''): string {
    $s = $sub ?? e(puesto($c));
    return '<div class="who">'.($foto ? avatar($c) : '').'<div class="min-w-0"><a class="nm" href="'.url('colaborador',['id'=>$c['id'],'tab'=>$tab]).'">'.e(nom($c)).'</a><span class="sub2">'.$s.'</span></div></div>';
}

/** Dato con etiqueta (fichas y detalles). */
function dato(string $l, $v, string $cl = 'col-md-6'): string { return '<div class="'.$cl.' dato"><span class="l">'.$l.'</span><div class="v">'.(($v === '' || $v === null) ? vacio_d() : $v).'</div></div>'; }

/** Barra de saldo de vacaciones (gozados · programados · disponibles). */
function barra_saldo(array $s, bool $detalle = true): string {
    $t = max($s['ganados'], 1); $w = fn($n) => round(max(0,$n) / $t * 100, 1);
    $h = '<div class="saldo-cab"><div><span class="l">Disponibles</span><div class="cifra">'.$s['saldo'].' <small>días</small></div></div><div class="sub2 text-end">'.$s['ganados'].' días ganados<br>en '.pl($s['anios'],'año','años').' de servicio</div></div>';
    $h .= '<div class="saldo" role="img" aria-label="Gozados '.$s['gozados'].', programados '.$s['prog'].', disponibles '.$s['saldo'].'"><i style="width:'.$w($s['gozados']).'%;background:var(--g-gris)" data-tip="Gozados: '.$s['gozados'].' días"></i><i style="width:'.$w($s['prog']).'%;background:var(--oc)" data-tip="Programados: '.$s['prog'].' días"></i><i style="width:'.$w($s['saldo']).'%;background:var(--g1)" data-tip="Disponibles: '.$s['saldo'].' días"></i></div>';
    $h .= '<div class="leyenda mt-2"><span><i style="background:var(--g-gris)"></i>Gozados '.$s['gozados'].'</span><span><i style="background:var(--oc)"></i>Programados '.$s['prog'].'</span><span><i style="background:var(--g1)"></i>Disponibles '.$s['saldo'].'</span>'.($s['pend'] ? '<span>Por aprobar '.$s['pend'].'</span>' : '').'</div>';
    if ($detalle && $s['viejo']) $h .= '<div class="sub2 mt-2 '.($s['viejo']['vence'] < HOY ? 'text-danger' : '').'">Periodo pendiente más antiguo: '.f($s['viejo']['ini']).' al '.f($s['viejo']['fin']).' · '.($s['viejo']['vence'] < HOY ? 'venció' : 'vence').' '.hace($s['viejo']['vence']).'</div>';
    return $h;
}

/** Pestañas de sección (enlaces). $items = [[url, texto, activo, contador?], …] */
function pestanas(array $items): string {
    $h = '<nav class="stabs" aria-label="Secciones">';
    foreach ($items as $t) $h .= '<a href="'.e($t[0]).'" class="'.($t[2] ? 'on' : '').'"'.($t[2] ? ' aria-current="page"' : '').'>'.$t[1].(isset($t[3]) ? ' <span class="n">'.$t[3].'</span>' : '').'</a>';
    return $h.'</nav>';
}

/** Botón que abre un panel lateral o ventana cargada desde el servidor (index.php?panel=…). */
function btn_panel(string $panel, array $params, string $txt, string $clase = 'btn btn-sec btn-sm'): string {
    return '<button type="button" class="'.$clase.'" data-panel="'.e(http_build_query(['panel' => $panel] + $params)).'">'.$txt.'</button>';
}
function item_panel(string $panel, array $params, string $txt, string $clase = ''): string {
    return '<li><button type="button" class="dropdown-item '.$clase.'" data-panel="'.e(http_build_query(['panel' => $panel] + $params)).'">'.$txt.'</button></li>';
}

/** Menú de acciones de un colaborador (se usa en el botón ⋮ y en el clic derecho de la fila). */
function menu_colab(array $c): string {
    $h = '<li><a class="dropdown-item" href="'.url('colaborador',['id'=>$c['id']]).'">'.ic('identification-card').' Ver ficha</a></li>';
    if (puede('personal.editar')) {
        $h .= '<li><a class="dropdown-item" href="'.url('colaborador_form',['id'=>$c['id']]).'">'.ic('pencil-simple').' Editar datos</a></li>';
        if ($c['estado'] === 'Activo') {
            $h .= item_panel('movimiento',['id'=>$c['id']],ic('arrows-left-right').' Registrar movimiento');
            if ($c['tipoContrato'] !== 'Indeterminado') $h .= item_panel('renovar',['id'=>$c['id']],ic('arrows-clockwise').' Renovar contrato');
            $h .= '<li><hr class="dropdown-divider"></li>'.item_panel('cese',['id'=>$c['id']],ic('user-minus').' Registrar cese','text-danger');
        } else $h .= item_panel('reingreso',['id'=>$c['id']],ic('user-plus').' Registrar reingreso');
    }
    return $h;
}

/** Ícono y color de cada tipo de evento del historial. */
const TL = ['Ingreso'=>['sign-in','ok'],'Reingreso'=>['sign-in','ok'],'Cese'=>['sign-out','er'],'Cambio de puesto'=>['briefcase','pe'],'Cambio de área'=>['tree-structure','pe'],
    'Cambio de sede'=>['map-pin','pe'],'Cambio de jefe'=>['user-switch','pe'],'Cambio de sueldo'=>['currency-circle-dollar','oc'],'Cambio de horario'=>['clock','pe'],'Renovación'=>['file-text','pe'],
    'Cambio de contrato'=>['file-text','pe'],'Amonestación'=>['warning','er'],'Vacaciones'=>['airplane-tilt',''],'Descanso médico'=>['first-aid-kit',''],'Permiso'=>['calendar-blank',''],'_'=>['circle','']];

/** Pestañas del módulo Configuración (según los permisos del perfil). */
function pestanas_config(string $activa): string {
    $t = [];
    if (puede('config.usuarios')) { $t[] = [url('usuarios'), 'Usuarios', $activa === 'usuarios']; $t[] = [url('perfiles'), 'Perfiles y permisos', $activa === 'perfiles']; $t[] = [url('parametros'), 'Parámetros', $activa === 'parametros']; $t[] = [url('plan'), 'Plan y uso', $activa === 'plan']; }
    if (puede('config.auditoria')) $t[] = [url('auditoria'), 'Auditoría', $activa === 'auditoria'];
    return pestanas($t);
}
