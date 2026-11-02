<?php
/**
 * acciones.php — Procesa los formularios (POST a index.php?accion=…): ingreso, registro y edición
 * de colaboradores, movimientos, ceses, marcaciones, solicitudes, aprobaciones, organización y configuración.
 * Responde JSON cuando la petición viene de JavaScript (paneles) y redirige en los demás casos.
 * Cada cambio queda registrado en la auditoría.
 */

function es_ajax(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'; }
function volver(): string { $r = $_SERVER['HTTP_REFERER'] ?? ''; return $r && str_contains($r, 'index.php') ? $r : 'index.php'; }

/** Respuesta de error: JSON con el mensaje (y errores por campo) o aviso + redirección. */
function fallar(string $msg, array $errores = []): never {
    if (es_ajax()) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg, 'errores' => $errores], JSON_UNESCAPED_UNICODE); exit; }
    avisar($msg, 'er'); header('Location: '.volver()); exit;
}
/** Respuesta correcta. $deshacer = [acción interna, datos] guardados en sesión para el botón «Deshacer». */
function listo(string $msg, ?string $ir = null, ?array $deshacer = null, bool $en_linea = false): never {
    $d = null;
    if ($deshacer) { $tok = bin2hex(random_bytes(6)); $_SESSION['deshacer'][$tok] = $deshacer; $d = ['token' => $tok]; }
    if (es_ajax() && $en_linea) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'aviso' => ['msg' => $msg, 'tipo' => 'ok', 'deshacer' => $d]], JSON_UNESCAPED_UNICODE); exit; }
    avisar($msg, 'ok', $d);
    if (es_ajax()) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'ir' => $ir], JSON_UNESCAPED_UNICODE); exit; }
    header('Location: '.($ir ?: volver())); exit;
}
function pedir(string $k, $def = '') { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : ($_POST[$k] ?? $def); }
function fecha_pedida(string $k, string $etiqueta, bool $obligatoria = true): string {
    $v = pedir($k); if ($v === '') { if ($obligatoria) fallar("Ingrese la $etiqueta.", [$k => 'Ingrese la fecha (dd/mm/aaaa).']); return ''; }
    $iso = fecha_iso($v); if (!$iso) fallar("La $etiqueta no es válida. Use el formato dd/mm/aaaa.", [$k => 'Fecha no válida (dd/mm/aaaa).']);
    return $iso;
}
/** Lista de ids enviada como ids[] o como texto separado por comas. */
function ids_pedidos(): array { $v = $_POST['ids'] ?? []; return array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string)$v)))); }
function exigir(string $permiso): void { if (!puede($permiso)) fallar('Su perfil no tiene permiso para esta acción.'); }
function colab_pedido(): array { $c = por_id('colaboradores', (int)pedir('id')); if (!$c || !puede_ver_colab($c)) fallar('No se encontró al colaborador.'); return $c; }
function historial_agregar(int $cid, string $fecha, string $tipo, string $detalle, string $antes = '', string $despues = ''): void {
    insertar('historial', ['colabId' => $cid, 'fecha' => $fecha, 'tipo' => $tipo, 'detalle' => $detalle, 'antes' => $antes, 'despues' => $despues, 'usuario' => usuario()['nombre']]);
}

function generar_clave_aleatoria(int $long = 12): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = strlen($chars);
    $pass = 'abcdefghjkmnpqrstuvwxyz'[random_int(0, 22)]
          . 'ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 22)]
          . '23456789'[random_int(0, 7)];
    for ($i = 3; $i < $long; $i++) {
        $pass .= $chars[random_int(0, $len - 1)];
    }
    return str_shuffle($pass);
}

function ejecutar_accion(string $a): never {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' ? (pedir('csrf') !== csrf()) : (($_GET['csrf'] ?? '') !== csrf())) fallar('La sesión expiró. Vuelva a intentarlo.');
    if ($a !== 'login' && !usuario()) { header('Location: index.php'); exit; }
    if (usuario() && !empty(usuario()['debe_cambiar']) && !in_array($a, ['cambiar_clave', 'salir'], true)) {
        fallar('Debe cambiar su contraseña antes de continuar.');
    }
    $fn = 'accion_'.$a;
    if (!function_exists($fn)) fallar('Acción no reconocida.');
    $fn();
    exit;
}

/* ---------- sesión ---------- */
function accion_login(): never {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $usuario_nom = trim((string)pedir('usuario'));
    $clave = (string)pedir('clave');
    $recordar = !empty(pedir('recordar'));

    // Control de fuerza bruta: 5 fallos en 15 minutos para el mismo usuario o IP
    $st = db()->prepare("SELECT COUNT(*) FROM `intentos_login` WHERE (LOWER(`usuario`) = LOWER(?) OR `ip` = ?) AND `exito` = 0 AND `fecha` >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $st->execute([$usuario_nom, $ip]);
    $intentos = (int)$st->fetchColumn();

    if ($intentos >= 5) {
        $_SESSION['error_login'] = 'Demasiados intentos. Espere 15 minutos.';
        header('Location: index.php');
        exit;
    }

    $st = db()->prepare("SELECT * FROM `usuarios` WHERE LOWER(`usuario`) = LOWER(?) LIMIT 1");
    $st->execute([$usuario_nom]);
    $u = $st->fetch();

    $error_msg = 'Usuario o contraseña incorrectos.';

    if (!$u || !$u['activo']) {
        insertar('intentos_login', [
            'usuario' => $usuario_nom ?: 'desconocido',
            'ip'      => $ip,
            'fecha'   => date('Y-m-d H:i:s'),
            'exito'   => 0
        ]);
        auditar('Intento fallido de ingreso', $usuario_nom ?: 'Usuario vacío');
        $_SESSION['error_login'] = $error_msg;
        header('Location: index.php');
        exit;
    }

    if (!empty($u['bloqueado_hasta']) && strtotime($u['bloqueado_hasta']) > time()) {
        $_SESSION['error_login'] = 'Demasiados intentos. Espere 15 minutos.';
        header('Location: index.php');
        exit;
    }

    if (!password_verify($clave, $u['clave_hash'])) {
        insertar('intentos_login', [
            'usuario' => $u['usuario'],
            'ip'      => $ip,
            'fecha'   => date('Y-m-d H:i:s'),
            'exito'   => 0
        ]);
        if ($intentos + 1 >= 5) {
            actualizar('usuarios', (int)$u['id'], [
                'bloqueado_hasta' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
            ]);
        }
        auditar('Intento fallido de ingreso', $u['usuario']);
        $_SESSION['error_login'] = $error_msg;
        header('Location: index.php');
        exit;
    }

    insertar('intentos_login', [
        'usuario' => $u['usuario'],
        'ip'      => $ip,
        'fecha'   => date('Y-m-d H:i:s'),
        'exito'   => 1
    ]);

    if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
        actualizar('usuarios', (int)$u['id'], [
            'clave_hash' => password_hash($clave, PASSWORD_DEFAULT)
        ]);
    }

    session_regenerate_id(true);

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $duracion = $recordar ? 8 * 3600 : 0;
    setcookie(session_name(), session_id(), [
        'expires'  => $duracion > 0 ? time() + $duracion : 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['emp'] = 0;
    $_SESSION['recordar'] = $recordar;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    actualizar('usuarios', (int)$u['id'], [
        'ultimo'          => HOY,
        'bloqueado_hasta' => null
    ]);

    auditar('Inicio de sesión', $u['usuario']);

    if (!empty($u['debe_cambiar'])) {
        header('Location: index.php?p=cambiar_clave');
        exit;
    }

    header('Location: ' . url('inicio'));
    exit;
}
function accion_cambiar_clave(): never {
    $u = usuario();
    if (!$u) fallar('Debe iniciar sesión para cambiar su contraseña.');

    $actual = (string)pedir('actual');
    $nueva = (string)pedir('nueva');
    $confirmar = (string)pedir('confirmar');

    $err = '';
    if (!password_verify($actual, $u['clave_hash'])) {
        $err = 'La contraseña actual no es correcta.';
    } elseif (mb_strlen($nueva) < 10) {
        $err = 'La nueva contraseña debe tener al menos 10 caracteres.';
    } elseif (!preg_match('/[A-Za-z]/', $nueva) || !preg_match('/[0-9]/', $nueva)) {
        $err = 'La nueva contraseña debe incluir letras y números.';
    } elseif (password_verify($nueva, $u['clave_hash'])) {
        $err = 'La nueva contraseña no puede ser igual a la anterior.';
    } elseif ($nueva !== $confirmar) {
        $err = 'La confirmación no coincide con la nueva contraseña.';
    }

    if ($err) {
        $_SESSION['error_cambiar_clave'] = $err;
        header('Location: index.php?p=cambiar_clave');
        exit;
    }

    $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
    actualizar('usuarios', (int)$u['id'], [
        'clave_hash'   => $nuevo_hash,
        'debe_cambiar' => 0
    ]);

    auditar('Cambio de contraseña', $u['usuario']);
    unset($_SESSION['error_cambiar_clave']);
    listo('Su contraseña ha sido actualizada con éxito.', url('inicio'));
}
function accion_salir(): never { session_destroy(); header('Location: index.php'); exit; }
function accion_empresa(): never { $_SESSION['emp'] = (int)pedir('id'); header('Location: '.volver()); exit; }
function accion_leer(): never {
    $n = por_id('notificaciones', (int)($_GET['id'] ?? 0)); if ($n) actualizar('notificaciones', $n['id'], ['leida' => 1]);
    header('Location: '.url($n['ruta'] ?? 'inicio')); exit;
}
function accion_leer_todo(): never {
    $u = usuario();
    $uid = (int)($u['id'] ?? 0);
    db()->prepare("UPDATE `notificaciones` SET `leida` = 1 WHERE `usuarioId` = ? OR `usuarioId` = 0")->execute([$uid]);
    unset($GLOBALS['_cache']['notificaciones'], $GLOBALS['_indices']['notificaciones']);
    header('Location: '.volver());
    exit;
}

/** Revierte la última acción (botón «Deshacer» del aviso). */
function accion_deshacer(): never {
    $tok = pedir('token'); $d = $_SESSION['deshacer'][$tok] ?? null; unset($_SESSION['deshacer'][$tok]);
    if (!$d) fallar('Ya no es posible deshacer esta acción.');
    [$tabla, $restaurar, $txt] = $d;
    foreach ($restaurar as $id => $campos) actualizar($tabla, (int)$id, $campos);
    auditar('Reversión', $txt);
    if (es_ajax()) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'aviso' => ['msg' => 'Se deshizo la acción.', 'tipo' => 'ok']], JSON_UNESCAPED_UNICODE); exit; }
    avisar('Se deshizo la acción.'); header('Location: '.volver()); exit;
}

/* ---------- colaboradores ---------- */
function validar_colaborador(array $b, int $id): array {
    $e = [];
    foreach (['apPat' => 'Ingrese el apellido paterno.', 'apMat' => 'Ingrese el apellido materno.', 'nombres' => 'Ingrese los nombres.', 'nroDoc' => 'Ingrese el número de documento.'] as $k => $m) if ($b[$k] === '') $e[$k] = $m;
    if ($b['tipoDoc'] === 'DNI' && $b['nroDoc'] !== '' && !preg_match('/^\d{8}$/', $b['nroDoc'])) $e['nroDoc'] = 'El DNI debe tener 8 dígitos.';
    foreach (tabla('colaboradores') as $c) if ($b['nroDoc'] !== '' && $c['nroDoc'] === $b['nroDoc'] && $c['id'] !== $id) $e['nroDoc'] = 'Ya existe un colaborador con este documento ('.nomc($c).').';
    if (!$b['fecNac']) $e['fecNac'] = 'Ingrese la fecha en formato dd/mm/aaaa.';
    elseif (dif_dias($b['fecNac'], HOY) / 365.25 < 18) $e['fecNac'] = 'El colaborador debe ser mayor de edad.';
    if ($b['email'] !== '' && !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Revise el correo: falta el dominio o la @.';
    if ($b['telefono'] !== '' && !preg_match('/^9\d{8}$/', $b['telefono'])) $e['telefono'] = 'El celular tiene 9 dígitos y empieza con 9.';
    foreach (['empresaId','sedeId','areaId','puestoId','horarioId'] as $k) if (!$b[$k]) $e[$k] = 'Seleccione una opción.';
    if ($b['sedeId'] && $b['empresaId'] && (por_id('sedes', $b['sedeId'])['empresaId'] ?? 0) !== $b['empresaId']) $e['sedeId'] = 'La sede no pertenece a la empresa elegida.';
    if (!$id) {
        if (!$b['fecIngreso']) $e['fecIngreso'] = 'Ingrese la fecha de ingreso (dd/mm/aaaa).';
        if ($b['tipoContrato'] !== 'Indeterminado' && !$b['finContrato']) $e['finContrato'] = 'Ingrese la fecha de fin del contrato.';
        if ($b['finContrato'] && $b['fecIngreso'] && $b['finContrato'] <= $b['fecIngreso']) $e['finContrato'] = 'Debe ser posterior a la fecha de ingreso.';
        if (puede('personal.sueldo') && !($b['sueldo'] > 0)) $e['sueldo'] = 'Ingrese el sueldo mensual.';
    }
    if ($b['pension'] !== 'ONP' && $b['cuspp'] !== '' && !preg_match('/^[0-9A-Z]{12}$/i', $b['cuspp'])) $e['cuspp'] = 'El CUSPP tiene 12 caracteres.';
    return $e;
}
function accion_guardar_colaborador(): never {
    exigir('personal.editar');
    $id = (int)pedir('id'); $b = [];
    foreach (['apPat','apMat','nombres','tipoDoc','nroDoc','sexo','estadoCivil','telefono','email','direccion','distrito','pension','cuspp','banco','cuenta','tipoContrato'] as $k) $b[$k] = pedir($k);
    foreach (['empresaId','sedeId','areaId','puestoId','jefeId','horarioId','ccId'] as $k) $b[$k] = (int)pedir($k);
    $malas = [];
    foreach (['fecNac','fecIngreso','finContrato'] as $k) { $v = pedir($k); $b[$k] = $v === '' ? '' : (fecha_iso($v) ?? ''); if ($v !== '' && !$b[$k]) $malas[$k] = 'Fecha no válida (dd/mm/aaaa).'; }
    $b['sueldo'] = (float)str_replace(',', '', (string)pedir('sueldo', '0'));
    $err = $malas + validar_colaborador($b, $id);
    if ($err) fallar(count($err) === 1 ? 'Hay 1 dato por corregir.' : 'Hay '.count($err).' datos por corregir.', $err);
    // Cifrar datos bancarios antes de guardar
    if ($b['cuenta'] !== '') $b['cuenta'] = cifrar($b['cuenta']);
    if ($b['cuspp']  !== '') $b['cuspp']  = cifrar($b['cuspp']);
    $campos = array_diff_key($b, array_flip(['fecIngreso','finContrato','sueldo','tipoContrato']));
    if ($id) {
        $c = por_id('colaboradores', $id); actualizar('colaboradores', $id, $campos);
        auditar('Edición de datos', $c['codigo'].' '.nom($c));
        listo('Datos de '.nomc($c + $campos).' guardados.', url('colaborador', ['id' => $id]));
    }
    $nid = siguiente_id('colaboradores');
    $nuevo = $campos + ['codigo' => sprintf('E%04d', $nid), 'estado' => 'Activo', 'fecCese' => '', 'motivoCese' => '', 'fecIngreso' => $b['fecIngreso'], 'tipoContrato' => $b['tipoContrato'],
        'finContrato' => $b['tipoContrato'] === 'Indeterminado' ? '' : $b['finContrato'], 'sueldo' => $b['sueldo'], 'reemplazoId' => 0, 'foto' => 'personas/'.slug(explode(' ', $b['nombres'])[0].' '.$b['apPat']).'.jpg'];
    insertar('colaboradores', $nuevo);
    historial_agregar($nid, $b['fecIngreso'], 'Ingreso', 'Ingreso como '.(por_id('puestos', $b['puestoId'])['nombre'] ?? ''));
    auditar('Registro de colaborador', $nuevo['codigo'].' '.nom($nuevo));
    listo('Se registró a '.nomc($nuevo).' con el código '.$nuevo['codigo'].'.', url('colaborador', ['id' => $nid]));
}
const TIPOS_MOV = ['puestoId' => ['Cambio de puesto','puestos'], 'areaId' => ['Cambio de área','areas'], 'sedeId' => ['Cambio de sede','sedes'], 'jefeId' => ['Cambio de jefe',''], 'horarioId' => ['Cambio de horario','horarios'], 'sueldo' => ['Cambio de sueldo','']];
function texto_mov(string $k, $v): string {
    if ($k === 'sueldo') return soles($v);
    if ($k === 'jefeId') return $v ? nomc(por_id('colaboradores', $v)) : 'Sin jefe';
    return por_id(TIPOS_MOV[$k][1], $v)['nombre'] ?? '';
}
function accion_movimiento(): never {
    exigir('personal.editar'); $c = colab_pedido(); $k = pedir('tipo');
    if (!isset(TIPOS_MOV[$k]) || ($k === 'sueldo' && !puede('personal.sueldo'))) fallar('Seleccione el tipo de movimiento.');
    $raw = $k === 'sueldo' ? str_replace(',', '', (string)pedir('v_sueldo')) : pedir('v_'.$k);
    if ($raw === '' || $raw === '0' && $k !== 'jefeId') fallar('Seleccione o ingrese el nuevo valor.', ['v_'.$k => 'Complete este dato.']);
    $nv = $k === 'sueldo' ? (float)$raw : (int)$raw;
    if ($k === 'sueldo' && $nv <= 0) fallar('Ingrese un sueldo válido.', ['v_sueldo' => 'Ingrese un monto mayor que cero.']);
    if ($nv == $c[$k]) fallar('El nuevo valor es igual al actual.');
    $fecha = fecha_pedida('fecha', 'fecha efectiva'); $motivo = pedir('motivo');
    if ($motivo === '') fallar('Indique el motivo del movimiento.', ['motivo' => 'Indique el motivo.']);
    $cambios = [$k => $nv]; if ($k === 'puestoId') $cambios['areaId'] = por_id('puestos', $nv)['areaId'];
    historial_agregar($c['id'], $fecha, TIPOS_MOV[$k][0], $motivo, texto_mov($k, $c[$k]), texto_mov($k, $nv));
    actualizar('colaboradores', $c['id'], $cambios);
    auditar(TIPOS_MOV[$k][0], $c['codigo'].' '.nom($c), $k === 'sueldo');
    listo(TIPOS_MOV[$k][0].' de '.nomc($c).' registrado.');
}
function accion_cese(): never {
    exigir('personal.editar'); $c = colab_pedido();
    $fecha = fecha_pedida('fecha', 'fecha de cese'); $motivo = pedir('motivo');
    if ($fecha < $c['fecIngreso']) fallar('La fecha de cese no puede ser anterior al ingreso ('.f($c['fecIngreso']).').', ['fecha' => 'Anterior al ingreso.']);
    if ($motivo === '') fallar('Seleccione el motivo.', ['motivo' => 'Seleccione el motivo.']);
    if (!pedir('conf')) fallar('Marque la confirmación para continuar.');
    actualizar('colaboradores', $c['id'], ['estado' => 'Cesado', 'fecCese' => $fecha, 'motivoCese' => $motivo]);
    foreach (tabla('usuarios') as $u) if ($u['colabId'] === $c['id']) actualizar('usuarios', $u['id'], ['activo' => 0]);
    historial_agregar($c['id'], $fecha, 'Cese', $motivo.(pedir('obs') ? '. '.pedir('obs') : ''));
    auditar('Cese', $c['codigo'].' '.nom($c).': '.$motivo);
    listo('Cese de '.nomc($c).' registrado.');
}
function accion_reingreso(): never {
    exigir('personal.editar'); $c = colab_pedido();
    $fecha = fecha_pedida('fecha', 'fecha de reingreso'); $tipo = pedir('tipoContrato'); $fin = fecha_pedida('fin', 'fecha de fin del contrato', $tipo !== 'Indeterminado');
    if ($fecha <= $c['fecCese']) fallar('La fecha de reingreso debe ser posterior al cese ('.f($c['fecCese']).').', ['fecha' => 'Debe ser posterior al cese.']);
    actualizar('colaboradores', $c['id'], ['estado' => 'Activo', 'fecIngreso' => $fecha, 'fecCese' => '', 'motivoCese' => '', 'tipoContrato' => $tipo, 'finContrato' => $tipo === 'Indeterminado' ? '' : $fin]);
    historial_agregar($c['id'], $fecha, 'Reingreso', 'Contrato '.mb_strtolower($tipo));
    auditar('Reingreso', $c['codigo'].' '.nom($c));
    listo('Reingreso de '.nomc($c).' registrado.');
}
function accion_renovar(): never {
    exigir('personal.editar'); $c = colab_pedido(); $m = (int)pedir('meses');
    if (!in_array($m, [3, 6, 12], true)) fallar('Seleccione el plazo.');
    $base = $c['finContrato'] && $c['finContrato'] > HOY ? $c['finContrato'] : HOY; $nf = add_dias(add_meses($base, $m), -1);
    actualizar('colaboradores', $c['id'], ['finContrato' => $nf]);
    historial_agregar($c['id'], HOY, 'Renovación', "Renovación por $m meses".(pedir('obs') ? '. '.pedir('obs') : ''), f($c['finContrato']), f($nf));
    auditar('Renovación de contrato', $c['codigo'].' hasta el '.f($nf));
    listo('Contrato de '.nomc($c).' renovado hasta el '.f($nf).'.', null, ['colaboradores', [$c['id'] => ['finContrato' => $c['finContrato']]], 'Renovación de '.$c['codigo']]);
}
function accion_masivo(): never {
    exigir('personal.editar'); $k = pedir('campo'); $ids = ids_pedidos();
    if (!in_array($k, ['horarioId','sedeId'], true) || !$ids) fallar('Seleccione a los colaboradores.');
    $nv = (int)pedir('v'); if (!$nv) fallar($k === 'horarioId' ? 'Seleccione el horario.' : 'Seleccione la sede.', ['v' => 'Seleccione una opción.']);
    $fecha = fecha_pedida('fecha', 'fecha efectiva'); $motivo = pedir('motivo'); if ($motivo === '') fallar('Indique el motivo del cambio.', ['motivo' => 'Indique el motivo.']);
    $tabla = $k === 'horarioId' ? 'horarios' : 'sedes'; $n = 0; $antes = [];
    foreach ($ids as $id) { $c = por_id('colaboradores', $id);
        if (!$c || $c[$k] === $nv) continue;
        if ($k === 'sedeId' && por_id('sedes', $nv)['empresaId'] !== $c['empresaId']) fallar(nomc($c).' pertenece a otra empresa. La sede debe ser de su misma empresa.');
        $antes[$id] = [$k => $c[$k]];
        historial_agregar($id, $fecha, $k === 'horarioId' ? 'Cambio de horario' : 'Cambio de sede', $motivo, por_id($tabla, $c[$k])['nombre'] ?? '', por_id($tabla, $nv)['nombre']);
        actualizar('colaboradores', $id, [$k => $nv]); $n++; }
    auditar('Cambio masivo', ($k === 'horarioId' ? 'Horario ' : 'Sede ').por_id($tabla, $nv)['nombre'].' para '.pl($n,'colaborador','colaboradores'));
    listo(($k === 'horarioId' ? 'Horario' : 'Sede').' actualizado para '.pl($n,'colaborador','colaboradores').'.', null, ['colaboradores', $antes, 'Cambio masivo']);
}
function accion_ver_sensible(): never {
    exigir('personal.sensible'); $c = colab_pedido(); $_SESSION['ver_sensible'][$c['id']] = 1;
    auditar('Consulta de datos sensibles', $c['codigo'].' '.nom($c).': cuenta bancaria y CUSPP', true);
    header('Location: '.url('colaborador', ['id' => $c['id'], 'tab' => 'pension'])); exit;
}
function accion_constancia(): never {
    exigir('personal.editar'); $c = colab_pedido(); auditar('Emisión de constancia de trabajo', $c['codigo'].' '.nom($c));
    listo('Constancia de trabajo de '.nomc($c).' generada.');
}

/* ---------- asistencia ---------- */
function accion_marcar(): never {
    exigir('asistencia.editar'); $c = colab_pedido(); $fe = fecha_iso(pedir('fecha')) ?? fallar('Fecha no válida.');
    $en = pedir('entrada'); $sa = pedir('salida'); $obs = pedir('obs');
    if (!preg_match('/^\d{2}:\d{2}$/', $en)) fallar('Ingrese la hora de entrada.', ['entrada' => 'Ingrese la hora (hh:mm).']);
    if ($sa !== '' && $sa <= $en) fallar('La salida debe ser posterior a la entrada.', ['salida' => 'Posterior a la entrada.']);
    if ($obs === '') fallar('Indique el motivo de la corrección.', ['obs' => 'Indique el motivo.']);
    $m = marca($c['id'], $fe);
    if ($m) { actualizar('marcaciones', $m['id'], ['entrada' => $en, 'salida' => $sa, 'origen' => 'Web', 'obs' => $obs]);
        auditar('Corrección de marcación', $c['codigo'].' '.f($fe).': '.$m['entrada'].'–'.($m['salida'] ?: 'sin salida').' a '.$en.'–'.($sa ?: 'sin salida').'. '.$obs);
        listo('Marcación de '.nomc($c).' corregida.', null, ['marcaciones', [$m['id'] => ['entrada' => $m['entrada'], 'salida' => $m['salida'], 'origen' => $m['origen'], 'obs' => $m['obs']]], 'Corrección de marcación '.$c['codigo']]); }
    insertar('marcaciones', ['colabId' => $c['id'], 'fecha' => $fe, 'entrada' => $en, 'salida' => $sa, 'origen' => 'Web', 'obs' => $obs]);
    auditar('Registro manual de marcación', $c['codigo'].' '.f($fe).'. '.$obs);
    listo('Marcación de '.nomc($c).' registrada.');
}

/* ---------- vacaciones y aprobaciones ---------- */
function accion_solicitud(): never {
    $c = por_id('colaboradores', (int)pedir('colabId'));
    if (!$c || ($c['id'] !== (yo()['id'] ?? 0) && !puede('personal.editar'))) fallar('Seleccione el colaborador.', ['colabId' => 'Seleccione el colaborador.']);
    $tipo = pedir('tipo'); $de = fecha_pedida('desde', 'fecha de inicio'); $ha = fecha_pedida('hasta', 'fecha de fin');
    if ($ha < $de) fallar('La fecha final es anterior a la inicial.', ['hasta' => 'Anterior a la fecha de inicio.']);
    $dias = dif_dias($de, $ha) + 1;
    if ($tipo === 'Vacaciones') {
        if ($de < HOY) fallar('Las vacaciones no se pueden solicitar con fecha pasada.', ['desde' => 'Fecha pasada.']);
        if ($dias < (int)param('diasMinVac')) fallar('Cada solicitud de vacaciones debe ser de al menos '.param('diasMinVac').' días.');
        $s = saldo_vac($c); if ($dias > $s['saldo'] - $s['pend']) fallar('El saldo disponible es de '.pl($s['saldo'] - $s['pend'],'día','días').' (considerando las solicitudes por aprobar).');
    }
    if ($tipo === 'Descanso médico' && empty($_FILES['adj']['size'])) fallar('Adjunte el certificado médico.', ['adj' => 'Adjunte el certificado.']);
    foreach (tabla('vacaciones') as $v) if ($v['colabId'] === $c['id'] && in_array($v['estado'], ['Pendiente','Aprobada'], true) && $v['desde'] <= $ha && $v['hasta'] >= $de) fallar('Las fechas se cruzan con otra solicitud del colaborador ('.f($v['desde']).' al '.f($v['hasta']).').');
    $vacId = insertar('vacaciones', ['colabId' => $c['id'], 'tipo' => $tipo, 'desde' => $de, 'hasta' => $ha, 'dias' => $dias, 'estado' => 'Pendiente', 'fecSol' => HOY, 'obs' => pedir('obs'), 'resuelto' => '', 'reemplazoId' => (int)pedir('reemplazoId')]);
    // Guardar adjunto de descanso médico en documentos
    if ($tipo === 'Descanso médico' && !empty($_FILES['adj']['tmp_name'])) {
        guardar_archivo_subido($_FILES['adj'], $c['id'], 'Certificado médico', 'adj');
    }
    auditar('Solicitud de '.mb_strtolower($tipo), $c['codigo'].' del '.f($de).' al '.f($ha).' ('.pl($dias,'día','días').')');
    // Notificar a los usuarios con permiso vacaciones.aprobar dentro del alcance
    $txt_notif = nomc($c).' solicitó '.mb_strtolower($tipo).' del '.f($de).' al '.f($ha).'.';
    $ruta_notif = url('aprobaciones');
    foreach (tabla('usuarios') as $u) {
        if (!$u['activo'] || $u['id'] === usuario()['id']) continue;
        $ru = por_id('roles', $u['rolId']);
        if ($ru && in_array('vacaciones.aprobar', $ru['permisos'] ?? [], true)) {
            notificar($u['id'], $txt_notif, $ruta_notif);
        }
    }
    listo('Solicitud de '.mb_strtolower($tipo).' registrada para '.nomc($c).'.');
}
function accion_resolver(): never {
    exigir('vacaciones.aprobar');
    $v = por_id('vacaciones', (int)pedir('id')); $c = $v ? por_id('colaboradores', $v['colabId']) : [];
    if (!$v || !$c || !puede_ver_colab($c)) fallar('No se encontró la solicitud.');
    if ($v['estado'] !== 'Pendiente') fallar('La solicitud ya fue resuelta por '.$v['resuelto'].'.');
    $est = pedir('decision') === 'rechazar' ? 'Rechazada' : 'Aprobada'; $motivo = pedir('motivo');
    if ($est === 'Rechazada' && $motivo === '') fallar('Para rechazar, indique el motivo.', ['motivo' => 'Indique el motivo del rechazo.']);
    $prev = ['estado' => $v['estado'], 'resuelto' => $v['resuelto'], 'obs' => $v['obs'], 'reemplazoId' => $v['reemplazoId'] ?? 0];
    $cambios = ['estado' => $est, 'resuelto' => usuario()['nombre']];
    if ($motivo !== '') $cambios['obs'] = $motivo;
    if ($est === 'Aprobada') $cambios['reemplazoId'] = (int)pedir('reemplazoId', $v['reemplazoId'] ?? 0) ?: ($c['reemplazoId'] ?? 0);
    actualizar('vacaciones', $v['id'], $cambios);
    auditar(($est === 'Aprobada' ? 'Aprobación' : 'Rechazo').' de '.mb_strtolower($v['tipo']), $c['codigo'].' del '.f($v['desde']).' al '.f($v['hasta']).($motivo ? ': '.$motivo : ''));
    // Notificar al colaborador si tiene usuario vinculado
    foreach (tabla('usuarios') as $u) {
        if ($u['colabId'] === $c['id'] && $u['activo']) {
            $txt_n = 'Su solicitud de '.mb_strtolower($v['tipo']).' del '.f($v['desde']).' al '.f($v['hasta']).' fue '.($est === 'Aprobada' ? 'aprobada' : 'rechazada').($motivo ? ': '.$motivo : '').'.';
            notificar($u['id'], $txt_n, url('mis_vacaciones'));
            break;
        }
    }
    listo('Solicitud de '.nomc($c).' '.($est === 'Aprobada' ? 'aprobada' : 'rechazada').'.', null, ['vacaciones', [$v['id'] => $prev], 'Resolución de solicitud '.$c['codigo']], pedir('enlinea') === '1');
}
function accion_aprobar_lote(): never {
    exigir('vacaciones.aprobar'); $ids = ids_pedidos(); $prev = []; $n = 0;
    foreach ($ids as $id) { $v = por_id('vacaciones', $id); $c = $v ? por_id('colaboradores', $v['colabId']) : [];
        if (!$v || $v['estado'] !== 'Pendiente' || !$c || !puede_ver_colab($c)) continue;
        $prev[$id] = ['estado' => 'Pendiente', 'resuelto' => '']; actualizar('vacaciones', $id, ['estado' => 'Aprobada', 'resuelto' => usuario()['nombre'], 'reemplazoId' => $c['reemplazoId'] ?? 0]);
        // Notificar al colaborador
        foreach (tabla('usuarios') as $u) {
            if ($u['colabId'] === $c['id'] && $u['activo']) {
                notificar($u['id'], 'Su solicitud de '.mb_strtolower($v['tipo']).' del '.f($v['desde']).' al '.f($v['hasta']).' fue aprobada.', url('mis_vacaciones'));
                break;
            }
        }
        $n++; }
    if (!$n) fallar('Seleccione al menos una solicitud pendiente.');
    auditar('Aprobación en bloque', pl($n,'solicitud','solicitudes'));
    listo(pl($n,'solicitud aprobada','solicitudes aprobadas').'.', null, ['vacaciones', $prev, 'Aprobación en bloque']);
}
function accion_anular(): never {
    $v = por_id('vacaciones', (int)pedir('id'));
    if (!$v || $v['estado'] !== 'Pendiente' || ($v['colabId'] !== (yo()['id'] ?? 0) && !puede('personal.editar'))) fallar('No se puede anular esta solicitud.');
    actualizar('vacaciones', $v['id'], ['estado' => 'Anulada']); auditar('Anulación de solicitud', f($v['desde']).' al '.f($v['hasta']));
    listo('Solicitud anulada.', null, ['vacaciones', [$v['id'] => ['estado' => 'Pendiente']], 'Anulación de solicitud']);
}

/* ---------- organización ---------- */
function accion_guardar_registro(): never {
    exigir('organizacion.editar'); $k = pedir('tabla'); $T = TABLAS_ORG[$k] ?? fallar('Tabla no válida.'); $id = (int)pedir('id'); $d = [];
    foreach ($T['campos'] as [$c, $l, $t, $req]) {
        if ($t === 'dias') { $d['dias'] = implode(',', array_filter([1,2,3,4,5,6], fn($i) => pedir("d$i"))); if (!$d['dias']) fallar('Marque al menos un día laborable.'); continue; }
        $d[$c] = in_array($t, ['empresas','areas'], true) ? (int)pedir($c) : pedir($c);
        if ($req && ($d[$c] === '' || $d[$c] === 0)) fallar("Complete el campo «{$l}».", [$c => 'Complete este dato.']);
    }
    if ($k === 'empresas' && !preg_match('/^(10|20)\d{9}$/', $d['ruc'])) fallar('El RUC debe tener 11 dígitos y empezar con 10 o 20.', ['ruc' => '11 dígitos; empieza con 10 o 20.']);
    if ($k === 'horarios') { if ($d['salida'] <= $d['entrada']) fallar('La hora de salida debe ser posterior a la de entrada.'); $d['tolerancia'] = (int)$d['tolerancia']; }
    $cn = $T['campos'][0][0];
    foreach (tabla($k) as $r) if ($r['id'] !== $id && mb_strtolower((string)$r[$cn]) === mb_strtolower((string)$d[$cn])) fallar('Ya existe un registro con ese nombre.', [$cn => 'Nombre repetido.']);
    $era = $id;
    if ($id) actualizar($k, $id, $d); else $id = insertar($k, $d + ['activo' => 1]);
    auditar(($era ? 'Edición' : 'Registro').' en '.$T['t'], (string)$d[$cn]);
    listo('«'.$d[$cn].'» guardado en '.$T['t'].'.', url($T['pagina'], ['tabla' => $k, 'reg' => $id]));
}
function accion_archivar(): never {
    exigir('organizacion.editar'); $k = pedir('tabla'); $T = TABLAS_ORG[$k] ?? fallar('Tabla no válida.'); $r = por_id($k, (int)pedir('id'));
    if (!$r) fallar('Registro no encontrado.');
    $n = uso_registro($k, $r['id']); $nom = $r[$T['campos'][0][0]];
    if ($r['activo'] && $n) fallar('«'.$nom.'» tiene '.pl($n,'registro activo asociado','registros activos asociados').'. Reasígnelos antes de archivarlo.');
    actualizar($k, $r['id'], ['activo' => $r['activo'] ? 0 : 1]); auditar(($r['activo'] ? 'Archivo' : 'Restauración').' en '.$T['t'], $nom);
    listo('«'.$nom.'» '.($r['activo'] ? 'archivado.' : 'restaurado.'), null, [$k, [$r['id'] => ['activo' => $r['activo']]], 'Archivo de '.$nom]);
}

/* ---------- configuración ---------- */
function accion_guardar_usuario(): never {
    exigir('config.usuarios'); $id = (int)pedir('id'); $u = por_id('usuarios', $id);
    $usuario = $id ? $u['usuario'] : pedir('usuario'); $colab = (int)pedir('colabId') ?: 0; $rolId = (int)pedir('rolId'); $nombre = pedir('nombre');
    if (!$id && !preg_match('/^[a-z0-9._]{4,20}$/', $usuario)) fallar('El usuario debe tener de 4 a 20 caracteres: minúsculas, números, punto o guion bajo.', ['usuario' => 'Formato no válido.']);
    if (!$id) foreach (tabla('usuarios') as $x) if ($x['usuario'] === $usuario) fallar('Ese usuario ya existe.', ['usuario' => 'Ya existe.']);
    if ($id && $u['rolId'] === 1 && $rolId !== 1 && count(array_filter(tabla('usuarios'), fn($x) => $x['rolId'] === 1 && $x['activo'])) === 1) fallar('Debe quedar al menos un administrador activo.');
    if ($nombre === '') $nombre = $colab ? nomc(por_id('colaboradores', $colab)) : $usuario;
    if ($id) {
        actualizar('usuarios', $id, ['colabId' => $colab, 'rolId' => $rolId, 'nombre' => $nombre]);
        auditar('Edición de usuario', $usuario);
        listo('Usuario '.$usuario.' guardado.');
    } else {
        $temp = generar_clave_aleatoria(12);
        $hash = password_hash($temp, PASSWORD_DEFAULT);
        insertar('usuarios', [
            'usuario'      => $usuario,
            'nombre'       => $nombre,
            'colabId'      => $colab,
            'rolId'        => $rolId,
            'activo'       => 1,
            'ultimo'       => '',
            'clave_hash'   => $hash,
            'debe_cambiar' => 1
        ]);
        auditar('Registro de usuario', $usuario);
        listo('Usuario '.$usuario.' creado. Contraseña temporal: '.$temp.' (cópiela y entréguela al usuario; debe cambiarla al ingresar).');
    }
}
function accion_reset_clave(): never {
    exigir('config.usuarios');
    $u = por_id('usuarios', (int)pedir('id'));
    if (!$u) fallar('Usuario no encontrado.');
    $temp = generar_clave_aleatoria(12);
    $hash = password_hash($temp, PASSWORD_DEFAULT);
    actualizar('usuarios', $u['id'], [
        'clave_hash'      => $hash,
        'debe_cambiar'    => 1,
        'bloqueado_hasta' => null
    ]);
    auditar('Restablecimiento de contraseña', $u['usuario']);
    listo('Nueva contraseña temporal para '.$u['usuario'].': '.$temp.' (cópiela y entréguela al usuario; debe cambiarla al ingresar).');
}
function accion_estado_usuario(): never {
    exigir('config.usuarios'); $u = por_id('usuarios', (int)pedir('id'));
    if (!$u || $u['id'] === usuario()['id']) fallar('No puede desactivar su propio usuario.');
    if ($u['activo'] && $u['rolId'] === 1 && count(array_filter(tabla('usuarios'), fn($x) => $x['rolId'] === 1 && $x['activo'])) === 1) fallar('No se puede desactivar: debe quedar al menos un administrador activo.');
    actualizar('usuarios', $u['id'], ['activo' => $u['activo'] ? 0 : 1]); auditar(($u['activo'] ? 'Desactivación' : 'Activación').' de usuario', $u['usuario']);
    listo('Usuario '.$u['usuario'].' '.($u['activo'] ? 'desactivado.' : 'activado.'), null, ['usuarios', [$u['id'] => ['activo' => $u['activo']]], 'Estado de usuario '.$u['usuario']]);
}
function accion_permiso(): never {
    exigir('config.usuarios'); $r = por_id('roles', (int)pedir('rol')); $p = pedir('permiso'); $on = pedir('on') === '1';
    if (!$r || $r['id'] === 1 || !in_array($p, array_column(PERMISOS, 0), true)) fallar('No se puede modificar este perfil.');
    $perms = $on ? array_values(array_unique(array_merge($r['permisos'], [$p]))) : array_values(array_diff($r['permisos'], [$p]));
    actualizar('roles', $r['id'], ['permisos' => $perms]); auditar(($on ? 'Permiso otorgado' : 'Permiso retirado').' al perfil '.$r['nombre'], $p);
    listo('Permisos del perfil '.$r['nombre'].' actualizados.', null, null, true);
}
function accion_alcance(): never {
    exigir('config.usuarios'); $r = por_id('roles', (int)pedir('rol')); $a = pedir('alcance');
    if (!$r || $r['id'] === 1 || !isset(ALCANCES[$a])) fallar('No se puede modificar este perfil.');
    actualizar('roles', $r['id'], ['alcance' => $a]); auditar('Cambio de alcance del perfil '.$r['nombre'], ALCANCES[$a]);
    listo('Alcance del perfil '.$r['nombre'].': '.mb_strtolower(ALCANCES[$a]).'.', null, null, true);
}
function accion_nuevo_perfil(): never {
    exigir('config.usuarios'); $n = pedir('nombre'); $a = pedir('alcance');
    if ($n === '') fallar('Ingrese el nombre del perfil.', ['nombre' => 'Complete este dato.']);
    foreach (tabla('roles') as $r) if (mb_strtolower($r['nombre']) === mb_strtolower($n)) fallar('Ya existe un perfil con ese nombre.', ['nombre' => 'Nombre repetido.']);
    insertar('roles', ['nombre' => $n, 'alcance' => isset(ALCANCES[$a]) ? $a : 'P', 'permisos' => [], 'sistema' => 0]); auditar('Registro de perfil', $n);
    listo('Perfil '.$n.' creado.');
}
function accion_parametros(): never {
    exigir('config.usuarios'); $p = tabla('parametros')[0]; $err = [];
    foreach (['diasVacAnio','diasMinVac','avisoContratoDias','filasPagina'] as $k) { $v = (int)pedir($k); if ($v < 1 || $v > 365) $err[$k] = 'Entre 1 y 365.'; $p[$k] = $v; }
    if ($err) fallar('Revise los valores: deben estar entre 1 y 365.', $err);
    actualizar('parametros', (int)$p['id'], array_diff_key($p, ['id' => 1])); auditar('Cambio de parámetros', json_encode(array_diff_key($p, ['id' => 1])));
    listo('Parámetros guardados.');
}

/* ---------- importación desde Excel (CSV separado por «;») ---------- */
const COLUMNAS_IMPORTAR = ['Apellido paterno','Apellido materno','Nombres','DNI','Fecha de nacimiento','Sexo (F/M)','Celular','Correo','Sede','Área','Puesto','Horario','Fecha de ingreso','Tipo de contrato','Fin de contrato','Sueldo'];
/** Lee el archivo, valida cada fila y deja la vista previa en la sesión (no guarda nada todavía). */
function accion_importar(): never {
    exigir('personal.editar');
    $tmp = $_FILES['archivo']['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) fallar('Seleccione el archivo CSV.', ['archivo' => 'Seleccione el archivo.']);
    $txt = file_get_contents($tmp); $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $lineas = array_values(array_filter(preg_split('/\r\n|\r|\n/', $txt), fn($l) => trim($l, " ;\t") !== ''));
    if (count($lineas) < 2) fallar('El archivo no tiene filas de datos.');
    $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
    $buscar = function (string $t, string $v) { foreach (tabla($t) as $x) if ($x['activo'] && mb_strtolower(trim($x['nombre'])) === mb_strtolower(trim($v))) return $x; return null; };
    $filas = []; $dnis = [];
    foreach (array_slice($lineas, 1, 500) as $i => $l) {
        $c = array_map('trim', str_getcsv($l, $sep)); $c = array_pad($c, 16, '');
        $err = [];
        $sd = $buscar('sedes', $c[8]); $ar = $buscar('areas', $c[9]); $pu = $buscar('puestos', $c[10]); $ho = $buscar('horarios', $c[11]);
        if ($c[0] === '' || $c[2] === '') $err[] = 'Falta apellido o nombres';
        if (!preg_match('/^\d{8}$/', $c[3])) $err[] = 'DNI no válido';
        elseif (in_array($c[3], $dnis, true) || array_filter(tabla('colaboradores'), fn($x) => $x['nroDoc'] === $c[3])) $err[] = 'DNI ya registrado';
        $fn = fecha_iso($c[4]); if (!$fn) $err[] = 'Fecha de nacimiento no válida';
        $fi = fecha_iso($c[12]); if (!$fi) $err[] = 'Fecha de ingreso no válida';
        if (!$sd) $err[] = 'Sede «'.$c[8].'» no existe'; if (!$ar) $err[] = 'Área «'.$c[9].'» no existe';
        if (!$pu) $err[] = 'Puesto «'.$c[10].'» no existe'; if (!$ho) $err[] = 'Horario «'.$c[11].'» no existe';
        $tc = in_array($c[13], ['Plazo fijo','Indeterminado','Tiempo parcial'], true) ? $c[13] : 'Plazo fijo';
        $ff = $tc === 'Indeterminado' ? '' : fecha_iso($c[14]); if ($tc !== 'Indeterminado' && !$ff) $err[] = 'Falta fin de contrato';
        $dnis[] = $c[3];
        $filas[] = ['n' => $i + 2, 'err' => $err, 'datos' => ['apPat' => $c[0], 'apMat' => $c[1], 'nombres' => $c[2], 'tipoDoc' => 'DNI', 'nroDoc' => $c[3], 'fecNac' => $fn ?? '', 'sexo' => strtoupper($c[5]) === 'M' ? 'M' : 'F',
            'telefono' => $c[6], 'email' => $c[7], 'sedeId' => $sd['id'] ?? 0, 'empresaId' => $sd['empresaId'] ?? 0, 'areaId' => $ar['id'] ?? 0, 'puestoId' => $pu['id'] ?? 0, 'horarioId' => $ho['id'] ?? 0,
            'fecIngreso' => $fi ?? '', 'tipoContrato' => $tc, 'finContrato' => $ff ?: '', 'sueldo' => (float)str_replace(',', '', $c[15])]];
    }
    $_SESSION['importar'] = ['archivo' => $_FILES['archivo']['name'], 'filas' => $filas];
    $ok = count(array_filter($filas, fn($f) => !$f['err']));
    listo(pl(count($filas), 'fila leída', 'filas leídas').': '.$ok.' listas para importar.', url('importar'));
}
function accion_importar_confirmar(): never {
    exigir('personal.editar'); $imp = $_SESSION['importar'] ?? null; if (!$imp) fallar('Primero cargue un archivo.');
    $n = 0;
    foreach ($imp['filas'] as $f) { if ($f['err']) continue; $d = $f['datos']; $nid = siguiente_id('colaboradores');
        insertar('colaboradores', $d + ['codigo' => sprintf('E%04d', $nid), 'estado' => 'Activo', 'fecCese' => '', 'motivoCese' => '', 'estadoCivil' => 'Soltero(a)', 'direccion' => '', 'distrito' => '', 'jefeId' => 0, 'ccId' => 0,
            'pension' => 'ONP', 'cuspp' => '', 'banco' => 'BCP', 'cuenta' => '', 'reemplazoId' => 0, 'foto' => 'personas/'.slug(explode(' ', $d['nombres'])[0].' '.$d['apPat']).'.jpg']);
        historial_agregar($nid, $d['fecIngreso'], 'Ingreso', 'Ingreso como '.(por_id('puestos', $d['puestoId'])['nombre'] ?? '').' (importado)'); $n++; }
    unset($_SESSION['importar']);
    auditar('Importación de colaboradores', pl($n, 'registro', 'registros').' desde '.$imp['archivo']);
    listo(pl($n, 'colaborador importado', 'colaboradores importados').'. Sus fichas quedan como incompletas hasta registrar dirección y centro de costo.', url('personal', ['vista' => 'incompletos']));
}
function accion_importar_cancelar(): never { unset($_SESSION['importar']); header('Location: '.url('importar')); exit; }

/* ============================================================
   ETAPA 4 — FUNCIONES REALES
   ============================================================ */

/* ---------- helper: guardar archivo subido ---------- */
/**
 * Valida y guarda un archivo subido en data/archivos/AAAA/MM/.
 * Devuelve el ID del registro en la tabla documentos, o llama a fallar().
 */
function guardar_archivo_subido(array $file, int $colabId, string $tipo, string $campo = 'archivo'): int {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        fallar('No se recibió ningún archivo.', [$campo => 'Seleccione un archivo.']);
    }
    $tamaño_max = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $tamaño_max) {
        fallar('El archivo supera los 5 MB.', [$campo => 'Máximo 5 MB.']);
    }
    // Validar tipo MIME real con finfo
    $fi   = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
    $tipos_ok = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($tipos_ok[$mime])) {
        fallar('Solo se aceptan archivos PDF, JPG o PNG.', [$campo => 'Tipo no permitido.']);
    }
    $ext = $tipos_ok[$mime];
    // Crear directorio data/archivos/AAAA/MM/
    $dir = DIR_ARCHIVOS . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fallar('No se pudo crear el directorio de almacenamiento.');
    }
    $nombre_arch = bin2hex(random_bytes(16)) . '.' . $ext;
    $ruta_destino = $dir . '/' . $nombre_arch;
    if (!move_uploaded_file($file['tmp_name'], $ruta_destino)) {
        fallar('Error al guardar el archivo. Intente de nuevo.');
    }
    $ruta_relativa = date('Y') . '/' . date('m') . '/' . $nombre_arch;
    $id_doc = insertar('documentos', [
        'colabId'        => $colabId,
        'tipo'           => $tipo,
        'nombre_original' => mb_substr($file['name'] ?? 'archivo', 0, 200),
        'archivo'        => $ruta_relativa,
        'tamano'         => (int)$file['size'],
        'fecha'          => HOY,
        'usuario'        => usuario()['nombre'],
    ]);
    auditar('Carga de documento', (por_id('colaboradores', $colabId)['codigo'] ?? '') . ' · ' . $tipo . ' · ' . ($file['name'] ?? ''));
    return $id_doc;
}

/* ---------- subir documento ---------- */
function accion_subir_documento(): never {
    exigir('personal.editar');
    $c    = colab_pedido();
    $tipo = pedir('tipo');
    $tipos_validos = ['Contrato', 'Adenda', 'DNI', 'Certificado médico', 'Otro'];
    if (!in_array($tipo, $tipos_validos, true)) fallar('Tipo de documento no válido.', ['tipo' => 'Seleccione un tipo.']);
    guardar_archivo_subido($_FILES['archivo'] ?? [], $c['id'], $tipo, 'archivo');
    listo('Documento subido correctamente.', url('colaborador', ['id' => $c['id'], 'tab' => 'documentos']));
}

/* ---------- cambiar foto ---------- */
function accion_cambiar_foto(): never {
    exigir('personal.editar');
    $c = colab_pedido();
    $file = $_FILES['foto'] ?? [];
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        fallar('Seleccione una imagen.', ['foto' => 'Seleccione un archivo.']);
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        fallar('La imagen supera los 3 MB.', ['foto' => 'Máximo 3 MB.']);
    }
    $fi   = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        fallar('Solo se aceptan imágenes JPG o PNG.', ['foto' => 'Solo JPG o PNG.']);
    }
    // Cargar imagen con GD
    $src = $mime === 'image/png' ? imagecreatefrompng($file['tmp_name']) : imagecreatefromjpeg($file['tmp_name']);
    if (!$src) fallar('No se pudo procesar la imagen.');
    $aw = imagesx($src); $ah = imagesy($src);
    // Cuadrado central
    $lado = min($aw, $ah);
    $ox   = (int)(($aw - $lado) / 2);
    $oy   = (int)(($ah - $lado) / 2);
    // Canvas de 400×400
    $dst  = imagecreatetruecolor(400, 400);
    // Fondo blanco (para PNGs con transparencia)
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $ox, $oy, 400, 400, $lado, $lado);
    imagedestroy($src);
    // Guardar en assets/img/personas/
    $dir_personas = DIR_IMG . '/personas';
    if (!is_dir($dir_personas)) mkdir($dir_personas, 0755, true);
    // Reutilizar nombre existente o generar uno nuevo
    $foto_actual = $c['foto'] ?? '';
    if ($foto_actual && str_starts_with($foto_actual, 'personas/')) {
        $nombre_archivo = basename($foto_actual);
    } else {
        $nombre_archivo = bin2hex(random_bytes(8)) . '.jpg';
        actualizar('colaboradores', $c['id'], ['foto' => 'personas/' . $nombre_archivo]);
    }
    $ruta_final = $dir_personas . '/' . $nombre_archivo;
    imagejpeg($dst, $ruta_final, 88);
    imagedestroy($dst);
    auditar('Cambio de foto', $c['codigo'] . ' ' . nom($c));
    listo('Foto actualizada.', url('colaborador', ['id' => $c['id']]));
}

/* ---------- descarga segura de documentos ---------- */
function accion_descargar_documento(int $id): never {
    if (!usuario()) { header('Location: index.php'); exit; }
    $doc = por_id('documentos', $id);
    if (!$doc) { http_response_code(404); echo 'Documento no encontrado.'; exit; }
    $c = por_id('colaboradores', $doc['colabId']);
    if (!$c || !puede_ver_colab($c)) { http_response_code(403); echo 'Sin acceso.'; exit; }
    $ruta = DIR_ARCHIVOS . '/' . $doc['archivo'];
    if (!is_file($ruta)) { http_response_code(404); echo 'Archivo no encontrado en el servidor.'; exit; }
    auditar('Descarga de documento', ($c['codigo'] ?? '') . ' · ' . $doc['tipo'] . ' · ' . $doc['nombre_original']);
    $fi   = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($ruta);
    $nombre_descarga = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $doc['nombre_original']);
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $nombre_descarga . '"');
    header('Content-Length: ' . filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

/* ---------- constancia de trabajo imprimible ---------- */
function accion_imprimir(string $tipo, int $id): never {
    if (!usuario()) { header('Location: index.php'); exit; }
    if ($tipo !== 'constancia') { http_response_code(404); echo 'Tipo no válido.'; exit; }
    $c = por_id('colaboradores', $id);
    if (!$c || !puede_ver_colab($c)) { http_response_code(403); echo 'Sin acceso.'; exit; }
    $emp   = por_id('empresas', $c['empresaId'] ?? 0) ?: [];
    $puesto = por_id('puestos', $c['puestoId'] ?? 0)['nombre'] ?? '';
    auditar('Emisión de constancia de trabajo', $c['codigo'] . ' ' . nom($c));
    $fecha_emision = f(HOY);
    $nombre_completo = nom($c);
    $razon  = e($emp['razon']     ?? 'Empresa');
    $ruc    = e($emp['ruc']       ?? '');
    $dir    = e($emp['direccion'] ?? '');
    $ingreso = f($c['fecIngreso'] ?? '');
    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Constancia de trabajo — <?= e($nombre_completo) ?></title>
<style>
  body { font-family: Georgia, serif; font-size: 13pt; color: #111; margin: 0; padding: 2.5cm; max-width: 18cm; margin: 0 auto; }
  h1 { text-align: center; font-size: 16pt; text-transform: uppercase; letter-spacing: 2px; margin-bottom: .5cm; }
  .subtitulo { text-align: center; font-size: 11pt; color: #555; margin-bottom: 1.5cm; }
  .cuerpo { text-align: justify; line-height: 1.9; }
  .firma { margin-top: 3cm; display: flex; justify-content: space-around; }
  .firma-bloque { text-align: center; }
  .firma-bloque .linea { border-top: 1px solid #111; width: 7cm; margin: 0 auto .3cm; }
  .firma-bloque small { font-size: 9pt; color: #555; }
  @media print {
    body { padding: 0; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>
<div class="no-print" style="background:#fffbe6;border:1px solid #f0d060;padding:10px 16px;margin-bottom:20px;border-radius:6px;font-family:sans-serif;font-size:12px;">
  <strong>Vista previa:</strong> Esta constancia se enviará a la impresora automáticamente.
  <button onclick="window.print()" style="margin-left:16px;padding:4px 14px;cursor:pointer;">Imprimir ahora</button>
  <button onclick="window.close()" style="margin-left:8px;padding:4px 14px;cursor:pointer;">Cerrar</button>
</div>

<h1>Constancia de trabajo</h1>
<p class="subtitulo"><?= $razon ?><?= $ruc ? ' — RUC ' . $ruc : '' ?></p>

<p class="cuerpo">
  La empresa <strong><?= $razon ?></strong><?= $dir ? ', con domicilio en ' . $dir . ',' : '' ?>
  hace constar que el(la) señor(a) <strong><?= e($nombre_completo) ?></strong>,
  identificado(a) con <?= e($c['tipoDoc'] ?? 'DNI') ?> N.° <strong><?= e($c['nroDoc'] ?? '') ?></strong>,
  presta servicios en nuestra organización desde el <strong><?= $ingreso ?></strong>,
  desempeñando el cargo de <strong><?= e($puesto) ?></strong>.
</p>
<p class="cuerpo">
  Se expide la presente constancia a solicitud del (de la) interesado(a), para los fines que
  estime convenientes.
</p>
<p style="text-align:right;margin-top:1.5cm;">Lima, <?= $fecha_emision ?></p>

<div class="firma">
  <div class="firma-bloque">
    <div class="linea"></div>
    <div>Firma autorizada</div>
    <small><?= $razon ?></small>
  </div>
</div>

<script>
window.onload = function() { window.print(); };
</script>
</body>
</html>
<?php
    echo ob_get_clean();
    exit;
}

/* ---------- feriados ---------- */
function accion_agregar_feriado(): never {
    exigir('config.usuarios');
    $fecha  = fecha_pedida('fecha', 'fecha del feriado');
    $nombre = pedir('nombre');
    if ($nombre === '') fallar('Ingrese el nombre del feriado.', ['nombre' => 'Complete este dato.']);
    // verificar unicidad
    $st = db()->prepare('SELECT id FROM `feriados` WHERE `fecha` = ?');
    $st->execute([$fecha]);
    if ($st->fetchColumn()) fallar('Ya existe un feriado registrado para esa fecha.', ['fecha' => 'Fecha ya registrada.']);
    insertar('feriados', ['fecha' => $fecha, 'nombre' => $nombre]);
    auditar('Registro de feriado', f($fecha) . ' — ' . $nombre);
    listo('Feriado «' . $nombre . '» registrado para el ' . f($fecha) . '.', url('parametros'));
}

function accion_quitar_feriado(): never {
    exigir('config.usuarios');
    $id  = (int)pedir('id');
    $fer = por_id('feriados', $id);
    if (!$fer) fallar('Feriado no encontrado.');
    eliminar('feriados', $id);
    auditar('Eliminación de feriado', f($fer['fecha']) . ' — ' . $fer['nombre']);
    listo('Feriado «' . $fer['nombre'] . '» eliminado.', url('parametros'));
}

/* ---------- notificaciones ---------- */
/** Inserta una notificación para un usuario y, si hay SMTP, envía correo. */
function notificar(int $usuarioId, string $txt, string $ruta = ''): void {
    insertar('notificaciones', [
        'usuarioId' => $usuarioId,
        'fecha'     => date('Y-m-d H:i:s'),
        'txt'       => $txt,
        'ruta'      => $ruta,
        'leida'     => 0,
    ]);
    // Correo opcional
    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        $u = por_id('usuarios', $usuarioId);
        if ($u) {
            $colab = $u['colabId'] ? por_id('colaboradores', $u['colabId']) : null;
            $email = $colab['email'] ?? '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    enviar_correo($email, nomc($colab), APP_NOMBRE . ' — Notificación', '<p>' . e($txt) . '</p>');
                } catch (Throwable) { /* silenciar: el correo es complementario */ }
            }
        }
    }
}

/* ---------- importar marcaciones del reloj ---------- */
const COLUMNAS_MARCACIONES = ['DNI o código', 'Fecha (dd/mm/aaaa)', 'Entrada (hh:mm)', 'Salida (hh:mm)'];

function accion_importar_marcaciones(): never {
    exigir('asistencia.editar');
    $tmp = $_FILES['archivo']['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) fallar('Seleccione el archivo CSV.', ['archivo' => 'Seleccione el archivo.']);
    $txt = file_get_contents($tmp);
    $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $lineas = array_values(array_filter(preg_split('/\r\n|\r|\n/', $txt), fn($l) => trim($l, " ;\t") !== ''));
    if (count($lineas) < 2) fallar('El archivo no tiene filas de datos.');
    $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';

    // Índice de colaboradores por DNI y por código
    $idx_colab = [];
    foreach (tabla('colaboradores') as $x) {
        $idx_colab[strtolower($x['nroDoc'])]  = $x;
        $idx_colab[strtolower($x['codigo'])]  = $x;
    }
    // Índice de marcaciones existentes colabId+fecha
    $marc_existente = [];
    foreach (tabla('marcaciones') as $m) {
        $marc_existente[$m['colabId'] . '_' . $m['fecha']] = $m;
    }

    $filas = [];
    foreach (array_slice($lineas, 1, 1000) as $i => $l) {
        $cols = array_pad(array_map('trim', str_getcsv($l, $sep)), 4, '');
        $err  = [];
        $dni  = strtolower($cols[0] ?? '');
        $colab_f = $idx_colab[$dni] ?? null;
        if (!$colab_f) $err[] = 'DNI o código «' . $cols[0] . '» no encontrado';
        $fecha_f = fecha_iso($cols[1] ?? '');
        if (!$fecha_f) $err[] = 'Fecha «' . $cols[1] . '» no válida (dd/mm/aaaa)';
        $entrada = $cols[2] ?? '';
        if (!preg_match('/^\d{2}:\d{2}$/', $entrada)) $err[] = 'Hora de entrada «' . $entrada . '» no válida (hh:mm)';
        $salida = $cols[3] ?? '';
        if ($salida !== '' && !preg_match('/^\d{2}:\d{2}$/', $salida)) $err[] = 'Hora de salida «' . $salida . '» no válida';
        if ($salida !== '' && $salida <= $entrada) $err[] = 'La salida debe ser posterior a la entrada';
        $existente = ($colab_f && $fecha_f) ? ($marc_existente[$colab_f['id'] . '_' . $fecha_f] ?? null) : null;
        $filas[] = ['n' => $i + 2, 'err' => $err, 'datos' => [
            'colab'   => $colab_f,
            'fecha'   => $fecha_f,
            'entrada' => $entrada,
            'salida'  => $salida,
            'existe'  => $existente ? true : false,
            'marc_id' => $existente['id'] ?? null,
        ]];
    }
    $_SESSION['importar_marcaciones'] = ['archivo' => $_FILES['archivo']['name'], 'filas' => $filas];
    $ok = count(array_filter($filas, fn($f) => !$f['err']));
    listo(pl(count($filas), 'fila leída', 'filas leídas') . ': ' . $ok . ' listas para importar.', url('importar_marcaciones'));
}

function accion_importar_marcaciones_confirmar(): never {
    exigir('asistencia.editar');
    $imp = $_SESSION['importar_marcaciones'] ?? null;
    if (!$imp) fallar('Primero cargue un archivo.');
    $n = $act = 0;
    foreach ($imp['filas'] as $f) {
        if ($f['err'] || !$f['datos']['colab'] || !$f['datos']['fecha']) continue;
        $d = $f['datos'];
        if ($d['existe'] && $d['marc_id']) {
            actualizar('marcaciones', $d['marc_id'], ['entrada' => $d['entrada'], 'salida' => $d['salida'], 'origen' => 'Reloj', 'obs' => 'Importado desde CSV']);
            $act++;
        } else {
            insertar('marcaciones', ['colabId' => $d['colab']['id'], 'fecha' => $d['fecha'], 'entrada' => $d['entrada'], 'salida' => $d['salida'], 'origen' => 'Reloj', 'obs' => '']);
            $n++;
        }
    }
    unset($_SESSION['importar_marcaciones']);
    invalidar_tabla('marcaciones');
    auditar('Importación de marcaciones', pl($n + $act, 'registro', 'registros') . ' desde ' . $imp['archivo'] . ' (' . $n . ' nuevas, ' . $act . ' actualizadas)');
    listo(pl($n, 'marcación nueva', 'marcaciones nuevas') . ($act ? ', ' . $act . ' actualizadas' : '') . '.', url('asistencia'));
}

function accion_importar_marcaciones_cancelar(): never {
    unset($_SESSION['importar_marcaciones']);
    header('Location: ' . url('importar_marcaciones'));
    exit;
}

