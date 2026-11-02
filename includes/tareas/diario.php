<?php
/**
 * diario.php — Tarea programada de ejecución diaria (solo CLI).
 * Detecta contratos por vencer y períodos de vacaciones acumulados sin gozar,
 * y envía notificaciones a los usuarios con los permisos correspondientes.
 *
 * Uso:  php C:\xampp\htdocs\aynihr\includes\tareas\diario.php
 * Programar (Windows Task Scheduler): acción = php.exe, argumento = "…\diario.php"
 * Programar (cron): 0 6 * * * php /var/www/aynihr/includes/tareas/diario.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Este script solo puede ejecutarse desde la línea de comandos.';
    exit(1);
}

// Bootstrap: simular el entorno mínimo de la aplicación
$_SESSION = []; // Las funciones de sesión las mockea esto; no necesitamos usuario real aquí
define('_TAREA_DIARIA', true);

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/includes/config.php';
require_once $raiz . '/includes/funciones.php';
require_once $raiz . '/includes/datos.php';
require_once $raiz . '/includes/reglas.php';
if (is_file($raiz . '/includes/correo.php')) {
    require_once $raiz . '/includes/correo.php';
}

echo '[' . date('Y-m-d H:i:s') . '] Tarea diaria iniciada.' . PHP_EOL;

$avisoContratoDias = (int)(param('avisoContratoDias') ?: 60);
$hoy               = HOY;
$limite_contrato   = date('Y-m-d', strtotime("+{$avisoContratoDias} days"));

/* ─── 1. Contratos por vencer ─────────────────────────────────── */
$notificados_hoy_contratos = db()->prepare(
    "SELECT `txt` FROM `notificaciones`
     WHERE `txt` LIKE 'Contrato de%' AND DATE(`fecha`) = ?"
);
$notificados_hoy_contratos->execute([$hoy]);
$textos_notif_contrato = $notificados_hoy_contratos->fetchAll(PDO::FETCH_COLUMN);

// Usuarios con permiso personal.editar
$receptores_contratos = [];
foreach (tabla('usuarios') as $u) {
    if (!$u['activo']) continue;
    $rol = por_id('roles', $u['rolId']);
    if ($rol && in_array('personal.editar', $rol['permisos'] ?? [], true)) {
        $receptores_contratos[] = $u['id'];
    }
}

$n_contratos = 0;
foreach (tabla('colaboradores') as $c) {
    if ($c['estado'] !== 'Activo' || empty($c['finContrato'])) continue;
    if ($c['finContrato'] < $hoy || $c['finContrato'] > $limite_contrato) continue;
    // Comprobar si ya fue notificado hoy buscando su código
    $ya_notificado = false;
    foreach ($textos_notif_contrato as $t) {
        if (str_contains($t, '(' . $c['codigo'] . ')')) { $ya_notificado = true; break; }
    }
    if ($ya_notificado) continue;
    $dias_faltan = (int)round((strtotime($c['finContrato'] . ' 12:00') - strtotime($hoy . ' 12:00')) / 86400);
    $txt = 'Contrato de ' . nom($c) . ' (' . $c['codigo'] . ') vence el ' . f($c['finContrato']) . ' (' . pl($dias_faltan, 'día', 'días') . ' restante' . ($dias_faltan === 1 ? '' : 's') . ').';
    $ruta = url('colaborador', ['id' => $c['id'], 'tab' => 'contrato']);
    foreach ($receptores_contratos as $uid) {
        // Insertar sin usar notificar() para no requerir usuario() de sesión
        insertar('notificaciones', ['usuarioId' => $uid, 'fecha' => date('Y-m-d H:i:s'), 'txt' => $txt, 'ruta' => $ruta, 'leida' => 0]);
        // Correo
        if (defined('SMTP_HOST') && SMTP_HOST !== '' && function_exists('enviar_correo')) {
            $u = por_id('usuarios', $uid);
            $col = $u['colabId'] ? por_id('colaboradores', $u['colabId']) : null;
            $email = $col['email'] ?? '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try { enviar_correo($email, $u['nombre'], APP_NOMBRE . ' — Alerta de contrato', '<p>' . htmlspecialchars($txt) . '</p>'); } catch (Throwable) {}
            }
        }
    }
    $n_contratos++;
    echo '  Contrato: ' . nom($c) . ' vence ' . $c['finContrato'] . PHP_EOL;
}
echo "[{$hoy}] Contratos notificados: {$n_contratos}" . PHP_EOL;

/* ─── 2. Vacaciones acumuladas sin gozar (período vencido) ───── */
// Buscar colaboradores con período de vacaciones que venció hace más de 90 días
// y cuyo saldo sin gozar sea > 0
$ya_avisados_vac = db()->prepare(
    "SELECT `txt` FROM `notificaciones`
     WHERE `txt` LIKE 'Vacaciones acumuladas%' AND DATE(`fecha`) = ?"
);
$ya_avisados_vac->execute([$hoy]);
$textos_notif_vac = $ya_avisados_vac->fetchAll(PDO::FETCH_COLUMN);

$receptores_vacaciones = [];
foreach (tabla('usuarios') as $u) {
    if (!$u['activo']) continue;
    $rol = por_id('roles', $u['rolId']);
    if ($rol && in_array('vacaciones.aprobar', $rol['permisos'] ?? [], true)) {
        $receptores_vacaciones[] = $u['id'];
    }
}

$n_vac = 0;
foreach (tabla('colaboradores') as $c) {
    if ($c['estado'] !== 'Activo') continue;
    $ya_notificado = false;
    foreach ($textos_notif_vac as $t) {
        if (str_contains($t, '(' . $c['codigo'] . ')')) { $ya_notificado = true; break; }
    }
    if ($ya_notificado) continue;
    // Calcular saldo; si hay período sin gozar con más de 90 días de atraso
    $desde = $c['fecIngreso'] ?? ''; if (!$desde) continue;
    // Verificar al menos un año de servicio
    if (dif_dias($desde, $hoy) < 365) continue;
    // Revisar períodos vencidos con saldo sin gozar
    $periodos = [];
    $anios = (int)floor(dif_dias($desde, $hoy) / 365);
    for ($i = 1; $i <= $anios; $i++) {
        $ini = add_dias($desde, ($i - 1) * 365);
        $fin = add_dias($desde, $i * 365 - 1);
        $periodos[] = ['ini' => $ini, 'fin' => $fin, 'dias' => (int)param('diasVacAnio')];
    }
    // Calcular gozadas
    $gozadas = array_fill(0, count($periodos), 0);
    foreach (tabla('vacaciones') as $v) {
        if ($v['colabId'] !== $c['id'] || $v['tipo'] !== 'Vacaciones' || !in_array($v['estado'], ['Aprobada'], true)) continue;
        // Asignar al período más antiguo con saldo
        $restante = $v['dias'];
        foreach ($periodos as $pi => &$per) {
            $disp = $per['dias'] - $gozadas[$pi];
            if ($disp <= 0) continue;
            $usar = min($disp, $restante);
            $gozadas[$pi] += $usar;
            $restante -= $usar;
            if ($restante <= 0) break;
        }
        unset($per);
    }
    foreach ($periodos as $pi => $per) {
        $saldo_per = $per['dias'] - $gozadas[$pi];
        // Si el período venció hace más de 90 días y hay saldo sin gozar
        if ($saldo_per > 0 && $per['fin'] < $hoy && dif_dias($per['fin'], $hoy) > 90) {
            $txt = 'Vacaciones acumuladas de ' . nom($c) . ' (' . $c['codigo'] . '): ' . pl($saldo_per, 'día sin gozar', 'días sin gozar') . ' del período ' . f($per['ini']) . ' al ' . f($per['fin']) . '.';
            $ruta = url('colaborador', ['id' => $c['id'], 'tab' => 'vacaciones']);
            foreach ($receptores_vacaciones as $uid) {
                insertar('notificaciones', ['usuarioId' => $uid, 'fecha' => date('Y-m-d H:i:s'), 'txt' => $txt, 'ruta' => $ruta, 'leida' => 0]);
            }
            $n_vac++;
            echo '  Vacaciones: ' . nom($c) . ' — ' . $saldo_per . ' días sin gozar (período hasta ' . $per['fin'] . ')' . PHP_EOL;
            break; // una notificación por colaborador por día
        }
    }
}
echo "[{$hoy}] Vacaciones pendientes notificadas: {$n_vac}" . PHP_EOL;
echo '[' . date('Y-m-d H:i:s') . '] Tarea diaria finalizada.' . PHP_EOL;

