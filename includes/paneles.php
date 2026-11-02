<?php
/**
 * paneles.php — Paneles laterales y ventanas que se cargan bajo demanda (index.php?panel=…).
 * Cada panel es un formulario que JavaScript envía sin recargar; los errores se muestran en el mismo panel.
 */

/* ---------- piezas de formulario ---------- */
function p_campo(string $k, string $l, string $tipo = 'text', $v = '', string $extra = '', string $ayuda = ''): string {
    return '<div class="mb-3"><label class="form-label" for="p-'.$k.'">'.$l.'</label><input class="form-control" id="p-'.$k.'" name="'.$k.'" type="'.$tipo.'" value="'.e($v).'" '.$extra.'>'.($ayuda ? '<div class="form-text">'.$ayuda.'</div>' : '').'<div class="invalid-feedback" data-error="'.$k.'"></div></div>';
}
function p_fecha(string $k, string $l, ?string $iso = '', string $ayuda = 'dd/mm/aaaa'): string {
    return '<div class="mb-3"><label class="form-label" for="p-'.$k.'">'.$l.'</label><input class="form-control" id="p-'.$k.'" name="'.$k.'" value="'.f($iso).'" data-fecha placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off">'.($ayuda ? '<div class="form-text">'.$ayuda.'</div>' : '').'<div class="invalid-feedback" data-error="'.$k.'"></div></div>';
}
function p_combo(string $k, string $l, string $opciones, string $extra = ''): string {
    return '<div class="mb-3"><label class="form-label" for="p-'.$k.'">'.$l.'</label><select class="form-select" id="p-'.$k.'" name="'.$k.'" '.$extra.'>'.$opciones.'</select><div class="invalid-feedback" data-error="'.$k.'"></div></div>';
}
function p_texto(string $k, string $l, $v = ''): string { return '<div class="mb-3"><label class="form-label" for="p-'.$k.'">'.$l.'</label><textarea class="form-control" id="p-'.$k.'" name="'.$k.'" rows="2">'.e($v).'</textarea><div class="invalid-feedback" data-error="'.$k.'"></div></div>'; }
function p_adjunto(string $k, string $l, string $ayuda): string {
    return '<div class="mb-3"><span class="form-label d-block">'.$l.'</span><label class="adj">'.ic('paperclip').'<input type="file" name="'.$k.'" accept=".pdf,.jpg,.jpeg,.png" class="visually-hidden"><span><b>Seleccionar archivo</b> · '.$ayuda.'</span></label><div class="invalid-feedback d-block" data-error="'.$k.'"></div></div>';
}
function opciones(array $lista, $sel, callable $txt, string $vacio = '', $deshab = null): string {
    $h = $vacio !== '' ? '<option value="">'.e($vacio).'</option>' : '';
    foreach ($lista as $x) $h .= '<option value="'.$x['id'].'" '.($sel == $x['id'] ? 'selected' : '').' '.($deshab !== null && $deshab == $x['id'] ? 'disabled' : '').'>'.e($txt($x)).($deshab !== null && $deshab == $x['id'] ? ' (actual)' : '').'</option>';
    return $h;
}
function opciones_txt(array $lista, $sel): string { $h = ''; foreach ($lista as $x) $h .= '<option '.($sel === $x ? 'selected' : '').'>'.e($x).'</option>'; return $h; }
function p_cab(array $c): string { return '<div class="p-cab">'.avatar($c,'m').'<div><b>'.e(nom($c)).'</b><span class="sub2">'.e(puesto($c)).' · '.e(area($c)).'</span></div></div>'; }

/** Envoltura: panel lateral (por defecto) o ventana centrada. */
function envolver(string $titulo, string $accion, string $cuerpo, string $botones, bool $modal = false, string $ocultos = ''): string {
    $form_attr = 'action="index.php?accion='.$accion.'" method="post" enctype="multipart/form-data" data-ajax novalidate';
    $err = '<div class="panel-err" role="alert"></div>';
    $ocultos = campo_csrf().$ocultos;
    if ($modal) return '<div class="modal fade" tabindex="-1" aria-labelledby="pt"><div class="modal-dialog"><form class="modal-content" '.$form_attr.'>'.$ocultos.'<div class="modal-header"><h2 class="modal-title" id="pt">'.$titulo.'</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body">'.$err.$cuerpo.'</div>'.($botones ? '<div class="modal-footer">'.$botones.'</div>' : '').'</form></div></div>';
    return '<form class="offcanvas offcanvas-end" tabindex="-1" aria-labelledby="pt" '.$form_attr.'>'.$ocultos.'<div class="offcanvas-header"><h2 class="offcanvas-title" id="pt">'.$titulo.'</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button></div><div class="offcanvas-body">'.$err.$cuerpo.'</div>'.($botones ? '<div class="offcanvas-footer">'.$botones.'</div>' : '').'</form>';
}
function botones(string $ok, string $clase = 'btn-primary', bool $modal = false): string {
    return '<button type="button" class="btn btn-ter" data-bs-dismiss="'.($modal ? 'modal' : 'offcanvas').'">Cancelar</button><button class="btn '.$clase.'">'.$ok.'</button>';
}

function render_panel(string $n): string {
    $id = (int)($_GET['id'] ?? 0); $c = por_id('colaboradores', $id);
    $oc = fn($k, $v) => '<input type="hidden" name="'.$k.'" value="'.e($v).'">';
    switch ($n) {
    case 'movimiento':
        if (!puede('personal.editar') || !$c) return '';
        $tipos = TIPOS_MOV; if (!puede('personal.sueldo')) unset($tipos['sueldo']);
        $b = p_cab($c).p_combo('tipo', 'Tipo de movimiento', implode('', array_map(fn($k, $t) => '<option value="'.$k.'">'.$t[0].'</option>', array_keys($tipos), $tipos)), 'data-alterna=".mv"');
        $i = 0;
        foreach ($tipos as $k => $t) {
            $actual = texto_mov($k, $c[$k]);
            $ctrl = $k === 'sueldo' ? p_campo('v_sueldo', 'Nuevo sueldo mensual (S/)', 'text', '', 'inputmode="decimal"')
                : p_combo('v_'.$k, 'Nuevo valor', $k === 'jefeId'
                    ? opciones(array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && $x['id'] !== $c['id']), 0, 'nom', 'Seleccione', $c['jefeId'])
                    : opciones(array_filter(tabla($t[1]), fn($x) => $x['activo'] && ($k !== 'sedeId' || $x['empresaId'] === $c['empresaId'])), 0, fn($x) => $x['nombre'], 'Seleccione', $c[$k]));
            $b .= '<div class="mv" data-valor="'.$k.'" '.($i++ ? 'hidden' : '').'><p class="sub2 mb-2">Valor actual: <b>'.e($actual).'</b></p>'.$ctrl.'</div>';
        }
        $b .= p_fecha('fecha', 'Fecha efectiva', HOY).p_campo('motivo', 'Motivo', 'text', '', 'placeholder="Por ejemplo: promoción, reorganización"').p_adjunto('adj', 'Documento de sustento (opcional)', 'PDF o imagen');
        return envolver('Registrar movimiento', 'movimiento', $b, botones('Registrar movimiento'), false, $oc('id', $c['id']));
    case 'cese':
        if (!puede('personal.editar') || !$c) return '';
        $tieneU = (bool)array_filter(tabla('usuarios'), fn($u) => $u['colabId'] === $c['id'] && $u['activo']);
        $b = p_cab($c).p_fecha('fecha', 'Fecha de cese (último día de labores)', '').p_combo('motivo', 'Motivo', '<option value="">Seleccione</option>'.opciones_txt(['Renuncia voluntaria','Término de contrato','Despido','Mutuo disenso','Jubilación','Fallecimiento'], $_GET['motivo'] ?? ''))
           .p_texto('obs', 'Observación (opcional)')
           .'<div class="alert alert-warning"><b>Al registrar el cese:</b><ul class="mb-0 ps-3"><li>El colaborador pasará a estado Cesado.</li>'.($tieneU ? '<li>Se desactivará su acceso al sistema.</li>' : '').'<li>Quedan pendientes la liquidación de beneficios sociales y la baja en T-Registro.</li></ul></div>'
           .'<div class="form-check"><input class="form-check-input" type="checkbox" id="p-conf" name="conf" value="1"><label class="form-check-label" for="p-conf">Confirmo que la fecha y el motivo son correctos</label></div>';
        return envolver('Registrar cese', 'cese', $b, botones('Registrar cese', 'btn-pel', true), true, $oc('id', $c['id']));
    case 'reingreso':
        if (!puede('personal.editar') || !$c) return '';
        $b = p_cab($c).p_fecha('fecha', 'Fecha de reingreso').p_combo('tipoContrato', 'Tipo de contrato', opciones_txt(['Plazo fijo','Indeterminado','Tiempo parcial'], 'Plazo fijo')).p_fecha('fin', 'Fin del contrato', '', 'Obligatorio salvo en contratos indeterminados');
        return envolver('Registrar reingreso', 'reingreso', $b, botones('Registrar reingreso', 'btn-primary', true), true, $oc('id', $c['id']));
    case 'renovar':
        if (!puede('personal.editar') || !$c) return '';
        $base = $c['finContrato'] && $c['finContrato'] > HOY ? $c['finContrato'] : HOY;
        $b = p_cab($c).'<p>Vencimiento actual: <b>'.f($c['finContrato']).'</b> ('.hace($c['finContrato']).')</p>'
           .p_combo('meses', 'Plazo de la renovación', implode('', array_map(fn($m) => '<option value="'.$m.'" '.($m === 6 ? 'selected' : '').'>'.$m.' meses, hasta el '.f(add_dias(add_meses($base, $m), -1)).'</option>', [3, 6, 12]))).p_campo('obs', 'Observación (opcional)');
        return envolver('Renovar contrato', 'renovar', $b, botones('Renovar contrato', 'btn-primary', true), true, $oc('id', $c['id']));
    case 'marcar':
        if (!puede('asistencia.editar') || !$c) return '';
        $fe = fecha_iso($_GET['fecha'] ?? '') ?? HOY; $m = marca($c['id'], $fe); $h = horario($c);
        $b = p_cab($c).'<p class="sub2">'.fecha_larga($fe).' · Horario de '.$h['entrada'].' a '.$h['salida'].'</p><div class="row"><div class="col">'.p_campo('entrada', 'Entrada', 'time', $m['entrada'] ?? '').'</div><div class="col">'.p_campo('salida', 'Salida', 'time', $m['salida'] ?? '').'</div></div>'
           .p_campo('obs', 'Motivo', 'text', '', 'placeholder="Por ejemplo: falla del reloj"', 'Obligatorio. Queda registrado en la auditoría.');
        return envolver($m ? 'Corregir marcación' : 'Registrar marcación', 'marcar', $b, botones($m ? 'Guardar corrección' : 'Registrar marcación', 'btn-primary', true), true, $oc('id', $c['id']).$oc('fecha', f($fe)));
    case 'solicitud':
        $propia = $c && $c['id'] === (yo()['id'] ?? -1);
        if (!$propia && !puede('personal.editar')) return '';
        $lista = $c ? [$c] : activos(); usort($lista, fn($a, $b) => strcmp(nom($a), nom($b)));
        $b = ($c ? p_cab($c).'<input type="hidden" name="colabId" value="'.$c['id'].'">' : p_combo('colabId', 'Colaborador', opciones($lista, 0, 'nom', 'Seleccione')))
           .p_combo('tipo', 'Tipo', opciones_txt(['Vacaciones','Permiso','Descanso médico'], 'Vacaciones'))
           .'<div class="row"><div class="col">'.p_fecha('desde', 'Desde').'</div><div class="col">'.p_fecha('hasta', 'Hasta').'</div></div>';
        if ($c) { $s = saldo_vac($c); $b .= '<p class="sub2">Saldo disponible: <b>'.pl($s['saldo'] - $s['pend'],'día','días').'</b>'.($s['pend'] ? ' (descontando '.$s['pend'].' por aprobar)' : '').'</p>';
                  $pares = array_filter(activos(), fn($x) => $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']);
                  if ($pares) $b .= p_combo('reemplazoId', 'Quién lo reemplaza (opcional)', opciones($pares, $c['reemplazoId'], 'nomc', 'Sin reemplazo')); }
        $b .= p_adjunto('adj', 'Sustento', 'obligatorio en descanso médico · PDF o imagen').p_texto('obs', 'Comentario (opcional)');
        return envolver('Nueva solicitud', 'solicitud', $b, botones('Registrar solicitud'));
    case 'revisar':
        if (!puede('vacaciones.aprobar')) return '';
        $v = por_id('vacaciones', $id); $c = $v ? por_id('colaboradores', $v['colabId']) : [];
        if (!$v || !$c || $v['estado'] !== 'Pendiente') return envolver('Solicitud', 'resolver', estado_vacio('La solicitud ya fue resuelta', 'Actualice la página para ver su estado.'), '<button type="button" class="btn btn-sec" data-bs-dismiss="offcanvas">Cerrar</button>');
        $s = saldo_vac($c); $otros = ausentes_area($v);
        $pares = array_filter(activos(), fn($x) => $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']);
        $b = p_cab($c).'<dl class="resumen izq mb-3"><dt>Tipo</dt><dd>'.e($v['tipo']).'</dd><dt>Fechas</dt><dd>'.f($v['desde']).' al '.f($v['hasta']).' · '.pl($v['dias'],'día','días').' · empieza '.hace($v['desde']).'</dd>'
           .($v['tipo'] === 'Vacaciones' ? '<dt>Saldo</dt><dd class="'.($s['saldo'] < $v['dias'] ? 'text-danger' : '').'">'.$s['saldo'].' días; quedarían '.($s['saldo'] - $v['dias']).'</dd>' : '')
           .'<dt>En su área</dt><dd class="'.($otros ? 'text-warning fw-bold' : '').'">'.($otros ? 'También ausentes: '.e(implode(', ', array_map('nomc', $otros))) : 'Nadie más ausente en esas fechas').'</dd>'.($v['obs'] ? '<dt>Comentario</dt><dd>'.e($v['obs']).'</dd>' : '').'</dl>'
           .tira_area($v)
           .($pares && $v['tipo'] === 'Vacaciones' ? p_combo('reemplazoId', 'Quién lo reemplaza', opciones($pares, $c['reemplazoId'], 'nomc', 'Sin reemplazo')) : '')
           .p_texto('motivo', 'Motivo del rechazo (obligatorio si rechaza)');
        $bt = '<button type="button" class="btn btn-ter" data-bs-dismiss="offcanvas">Cancelar</button><button class="btn btn-pel-sec" name="decision" value="rechazar">Rechazar</button><button class="btn btn-primary" name="decision" value="aprobar">Aprobar</button>';
        return envolver('Revisar solicitud', 'resolver', $b, $bt, false, $oc('id', $v['id']));
    case 'masivo':
        if (!puede('personal.editar')) return '';
        $campo = ($_GET['campo'] ?? '') === 'sedeId' ? 'sedeId' : 'horarioId'; $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
        $lista = array_filter(tabla($campo === 'sedeId' ? 'sedes' : 'horarios'), fn($x) => $x['activo']);
        $b = '<p>'.pl(count($ids),'colaborador seleccionado','colaboradores seleccionados').'.</p>'.p_combo('v', $campo === 'sedeId' ? 'Nueva sede' : 'Nuevo horario', opciones($lista, 0, fn($x) => $x['nombre'].($campo === 'horarioId' ? ' ('.$x['entrada'].' a '.$x['salida'].')' : ''), 'Seleccione'))
           .p_fecha('fecha', 'Fecha efectiva', HOY).p_campo('motivo', 'Motivo');
        foreach ($ids as $i) $b .= '<input type="hidden" name="ids[]" value="'.$i.'">';
        return envolver($campo === 'sedeId' ? 'Cambiar sede' : 'Asignar horario', 'masivo', $b, botones('Aplicar cambio'), false, $oc('campo', $campo));
    case 'registro':
        if (!puede('organizacion.editar')) return '';
        $k = $_GET['tabla'] ?? ''; $T = TABLAS_ORG[$k] ?? null; if (!$T) return '';
        $r = $id ? por_id($k, $id) : []; $b = '';
        foreach ($T['campos'] as [$cn, $l, $t, $req]) {
            if ($t === 'empresas') $b .= p_combo($cn, $l, opciones(array_filter(tabla('empresas'), fn($x) => $x['activo']), $r[$cn] ?? 0, fn($x) => $x['razon'], 'Seleccione'));
            elseif ($t === 'areas') $b .= p_combo($cn, $l, opciones(array_filter(tabla('areas'), fn($x) => $x['activo'] && $x['id'] !== $id), $r[$cn] ?? 0, fn($x) => $x['nombre'], $cn === 'padreId' ? 'No depende de otra área' : 'Seleccione'));
            elseif ($t === 'dias') { $dias = explode(',', $r['dias'] ?? '1,2,3,4,5'); $b .= '<fieldset class="mb-3"><legend class="form-label">'.$l.'</legend><div class="d-flex gap-3 flex-wrap">';
                foreach (['Lun','Mar','Mié','Jue','Vie','Sáb'] as $i => $dn) $b .= '<div class="form-check"><input class="form-check-input" type="checkbox" id="d'.($i+1).'" name="d'.($i+1).'" value="1" '.(in_array((string)($i+1), $dias, true) ? 'checked' : '').'><label class="form-check-label" for="d'.($i+1).'">'.$dn.'</label></div>';
                $b .= '</div></fieldset>'; }
            else $b .= p_campo($cn, $l.($req ? '' : ' <span class="op">(opcional)</span>'), $t, $r[$cn] ?? '', $k === 'empresas' && $cn === 'ruc' ? 'inputmode="numeric" maxlength="11"' : '', $k === 'empresas' && $cn === 'ruc' ? '11 dígitos; empieza con 10 o 20' : '');
        }
        return envolver($id ? 'Editar '.$T['uno'] : $T['nuevo'], 'guardar_registro', $b, botones($id ? 'Guardar cambios' : 'Registrar', 'btn-primary', true), true, $oc('tabla', $k).$oc('id', $id));
    case 'usuario':
        if (!puede('config.usuarios')) return '';
        $u = $id ? por_id('usuarios', $id) : [];
        $libres = array_filter(tabla('colaboradores'), fn($x) => $x['estado'] === 'Activo' && (!array_filter(tabla('usuarios'), fn($y) => $y['colabId'] === $x['id']) || $x['id'] === ($u['colabId'] ?? 0)));
        usort($libres, fn($a, $b) => strcmp(nom($a), nom($b)));
        $b = p_campo('usuario', 'Usuario', 'text', $u['usuario'] ?? '', $id ? 'readonly' : 'autocomplete="off"', $id ? '' : 'De 4 a 20 caracteres: minúsculas, números, punto o guion bajo')
           .p_combo('colabId', 'Colaborador vinculado', opciones($libres, $u['colabId'] ?? 0, 'nom', 'Sin vincular'))
           .p_campo('nombre', 'Nombre visible', 'text', $u['nombre'] ?? '', '', 'Si lo deja vacío se usa el nombre del colaborador')
           .p_combo('rolId', 'Perfil', opciones(tabla('roles'), $u['rolId'] ?? 4, fn($x) => $x['nombre'].' · '.mb_strtolower(ALCANCES[$x['alcance']])));
        return envolver($id ? 'Editar usuario' : 'Nuevo usuario', 'guardar_usuario', $b, botones($id ? 'Guardar cambios' : 'Crear usuario', 'btn-primary', true), true, $oc('id', $id));
    case 'perfil':
        if (!puede('config.usuarios')) return '';
        $b = p_campo('nombre', 'Nombre del perfil').p_combo('alcance', 'Alcance', implode('', array_map(fn($k, $v) => '<option value="'.$k.'">'.$v.'</option>', array_keys(ALCANCES), ALCANCES))).'<p class="sub2 mb-0">El perfil se crea sin permisos. Asígnelos después en la tabla.</p>';
        return envolver('Nuevo perfil', 'nuevo_perfil', $b, botones('Crear perfil', 'btn-primary', true), true);
    case 'subir_documento':
        if (!puede('personal.editar') || !$c) return '';
        $tipos = ['Contrato', 'Adenda', 'DNI', 'Certificado médico', 'Otro'];
        $b = p_cab($c)
           . p_combo('tipo', 'Tipo de documento', opciones_txt($tipos, 'Contrato'))
           . p_adjunto('archivo', 'Documento adjunto', 'Solo PDF, JPG o PNG. Máximo 5 MB.');
        return envolver('Subir documento', 'subir_documento', $b, botones('Subir documento', 'btn-primary'), false, $oc('id', $c['id']));
    case 'cambiar_foto':
        if (!puede('personal.editar') || !$c) return '';
        $b = '<div class="text-center mb-3">'.avatar($c, 'xl', false).'</div>'
           . p_adjunto('foto', 'Seleccionar foto', 'JPG o PNG hasta 3 MB. Se recortará cuadrada al centro (400x400 px).');
        return envolver('Cambiar foto de colaborador', 'cambiar_foto', $b, botones('Guardar foto', 'btn-primary'), false, $oc('id', $c['id']));
    case 'confirmar':
        $que = $_GET['que'] ?? '';
        $txt = ['reset_clave' => ['Restablecer contraseña', 'Se generará una contraseña temporal aleatoria de 12 caracteres y se obligará al usuario a cambiarla en su siguiente ingreso.', 'Restablecer contraseña', 'reset_clave'],
                'anular' => ['Anular solicitud', 'Se anulará la solicitud. Podrá deshacerlo durante unos segundos.', 'Anular solicitud', 'anular'],
                'estado_usuario' => ['Cambiar estado del usuario', 'El usuario no podrá ingresar mientras esté desactivado.', 'Confirmar', 'estado_usuario'],
                'archivar' => ['Archivar registro', 'Dejará de ofrecerse en los formularios. Podrá restaurarlo desde «Mostrar archivados».', 'Archivar', 'archivar']][$que] ?? null;
        if (!$txt) return '';
        return envolver($txt[0], $txt[3], '<p class="mb-0">'.$txt[1].'</p>', botones($txt[2], 'btn-pel', true), true, $oc('id', $id).$oc('tabla', $_GET['tabla'] ?? ''));
    case 'ayuda':
        $de = $_GET['de'] ?? 'inicio';
        $t = AYUDA[$de] ?? AYUDA[MODULO_DE[$de] ?? 'inicio'] ?? AYUDA['inicio'];
        $b = '<ul class="ps-3">'.implode('', array_map(fn($x) => '<li class="mb-2">'.$x.'</li>', $t)).'</ul><hr><p class="sub2 mb-0">Soporte: soporte@aynihr.pe · lunes a viernes de 9:00 a 18:00.<br>Atajo: tecla <kbd>/</kbd> para buscar a un colaborador.</p>';
        return envolver('Ayuda', '', $b, '<button type="button" class="btn btn-sec" data-bs-dismiss="offcanvas">Cerrar</button>');
    }
    return '';
}

/** Tira de 3 semanas alrededor de una solicitud: quién del área está ausente cada día. */
function tira_area(array $v): string {
    $c = por_id('colaboradores', $v['colabId']); $ini = add_dias($v['desde'], -3); $fin = min(add_dias($ini, 20), add_dias($v['hasta'], 3));
    $equipo = array_filter(activos(), fn($x) => $x['areaId'] === $c['areaId'] && $x['id'] !== $c['id']);
    $h = '<div class="tira" aria-label="Ausencias del área alrededor de la solicitud"><div class="tira-t">Ausencias en '.e(area($c)).'</div><div class="tira-dias">';
    for ($x = $ini; $x <= $fin; $x = add_dias($x, 1)) {
        $aus = array_values(array_filter($equipo, fn($o) => ausencia($o, $x)));
        $sol = $x >= $v['desde'] && $x <= $v['hasta'];
        $h .= '<span class="td '.($sol ? 'sol' : '').' '.(in_array(dia_sem($x), [0, 6]) ? 'fs' : '').'" data-tip="'.e(f($x).($sol ? ' · solicitud' : '').($aus ? ' · ausentes: '.implode(', ', array_map('nomc', $aus)) : ' · nadie ausente')).'"><small>'.DIAS1[dia_sem($x)].'</small>'.(int)substr($x, 8).'<i>'.(count($aus) ?: '').'</i></span>';
    }
    return $h.'</div><div class="leyenda mt-1"><span><i style="background:var(--g1)"></i>Días solicitados</span><span>Número: otras personas del área ausentes</span></div></div>';
}

const AYUDA = [
    'inicio' => ['«Por resolver» reúne lo que requiere acción hoy, ordenado por urgencia. Cada fila lleva a la pantalla ya filtrada.', 'Pase el mouse sobre los gráficos para ver el detalle de cada día o mes.'],
    'personal' => ['Haga clic en una fila para abrir la ficha, o clic derecho para ver las acciones.', 'Use las vistas para ver activos, cesados, contratos por vencer o fichas incompletas.', 'Las barras de «Equipo por área» filtran la lista.'],
    'colaborador' => ['La ficha agrupa los datos por pestañas; el resumen muestra asistencia, sueldo y su equipo.', 'Los cambios de puesto, área, sede, jefe, horario o sueldo se registran con «Registrar movimiento» para que queden en el historial.', 'Los datos bancarios se muestran ocultos; cada consulta queda en la auditoría.'],
    'asistencia' => ['Primero se muestran las incidencias: tardanzas, faltas, salidas sin marcar y ausencias.', 'Para corregir una marcación debe indicar el motivo; el cambio queda en la auditoría.'],
    'vacaciones' => ['Las solicitudes se resuelven desde «Revisar», donde se ve el saldo, quién más estará ausente y quién lo reemplaza.', 'El saldo se calcula por periodos de un año de servicio.'],
    'aprobaciones' => ['Puede aprobar varias solicitudes a la vez seleccionándolas.', 'Después de aprobar o rechazar tiene unos segundos para deshacer.'],
    'organizacion' => ['Los registros con colaboradores activos no se pueden archivar.'],
    'configuracion' => ['Los perfiles definen qué puede ver y hacer cada usuario, y sobre qué personas (alcance).'],
];
