<?php
/**
 * datos.php — Capa de acceso a datos MySQL / MariaDB con PDO.
 * Prepared statements siempre, utf8mb4 y manejo de excepciones.
 */

const TABLAS = [
    'empresas', 'sedes', 'areas', 'puestos', 'ccs', 'horarios',
    'colaboradores', 'historial', 'marcaciones', 'vacaciones',
    'roles', 'usuarios', 'parametros', 'auditoria', 'notificaciones',
    'feriados', 'documentos', 'intentos_login'
];

$GLOBALS['_cache'] = [];
$GLOBALS['_indices'] = [];

/** Obtiene la instancia única de conexión PDO. */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $port = defined('DB_PUERTO') ? DB_PUERTO : 3306;
        $name = defined('DB_NOMBRE') ? DB_NOMBRE : 'aynihr';
        $user = defined('DB_USUARIO') ? DB_USUARIO : 'root';
        $pass = defined('DB_CLAVE') ? DB_CLAVE : '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Error de conexión a BD: ' . $e->getMessage());
            http_response_code(500);
            if (is_file(RAIZ . '/pages/error_db.php')) {
                include RAIZ . '/pages/error_db.php';
            } else {
                echo '<p>Servicio temporalmente no disponible.</p>';
            }
            exit;
        }
    }
    return $pdo;
}

/** Devuelve todos los registros de una tabla (en caché durante la petición). */
function tabla(string $t): array {
    if (!isset($GLOBALS['_cache'][$t])) {
        $order = in_array($t, ['auditoria', 'notificaciones', 'documentos'], true) ? 'ORDER BY id DESC' : 'ORDER BY id ASC';
        $st = db()->query("SELECT * FROM `$t` $order");
        $filas = $st->fetchAll();
        if ($t === 'roles') {
            foreach ($filas as &$r) {
                if (isset($r['permisos']) && is_string($r['permisos'])) {
                    $r['permisos'] = json_decode($r['permisos'], true) ?: [];
                }
            }
            unset($r);
        }
        $GLOBALS['_cache'][$t] = $filas;
        $GLOBALS['_indices'][$t] = [];
        foreach ($filas as $f) {
            $GLOBALS['_indices'][$t][(int)$f['id']] = $f;
        }
    }
    return $GLOBALS['_cache'][$t];
}

/** Busca un registro por id; devuelve [] si no existe. */
function por_id(string $t, $id): array {
    if (!$id) return [];
    $id = (int)$id;
    if (isset($GLOBALS['_indices'][$t][$id])) {
        return $GLOBALS['_indices'][$t][$id];
    }
    if (isset($GLOBALS['_cache'][$t])) {
        return [];
    }
    $st = db()->prepare("SELECT * FROM `$t` WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $fila = $st->fetch();
    if (!$fila) return [];
    if ($t === 'roles' && isset($fila['permisos']) && is_string($fila['permisos'])) {
        $fila['permisos'] = json_decode($fila['permisos'], true) ?: [];
    }
    $GLOBALS['_indices'][$t][$id] = $fila;
    return $fila;
}

/** Actualiza campos de un registro mediante UPDATE puntual. */
function actualizar(string $t, int $id, array $cambios): void {
    if (empty($cambios)) return;
    if ($t === 'roles' && isset($cambios['permisos']) && is_array($cambios['permisos'])) {
        $cambios['permisos'] = json_encode(array_values($cambios['permisos']), JSON_UNESCAPED_UNICODE);
    }
    $sets = [];
    $params = [];
    foreach ($cambios as $col => $val) {
        $sets[] = "`$col` = ?";
        $params[] = $val;
    }
    $params[] = $id;
    $sql = "UPDATE `$t` SET " . implode(', ', $sets) . " WHERE id = ?";
    $st = db()->prepare($sql);
    $st->execute($params);

    // Invalidar caché
    unset($GLOBALS['_cache'][$t], $GLOBALS['_indices'][$t]);
    if ($t === 'marcaciones') $GLOBALS['_reiniciar_marcas'] = 1;
    if ($t === 'vacaciones') $GLOBALS['_reiniciar_aus'] = 1;
}

/** Inserta un registro mediante INSERT puntual. Devuelve el id insertado. */
function insertar(string $t, array $fila): int {
    if ($t === 'roles' && isset($fila['permisos']) && is_array($fila['permisos'])) {
        $fila['permisos'] = json_encode(array_values($fila['permisos']), JSON_UNESCAPED_UNICODE);
    }
    $cols = [];
    $placeholders = [];
    $params = [];
    foreach ($fila as $col => $val) {
        $cols[] = "`$col`";
        $placeholders[] = '?';
        $params[] = $val;
    }
    $sql = "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $st = db()->prepare($sql);
    $st->execute($params);
    $id = isset($fila['id']) && (int)$fila['id'] > 0 ? (int)$fila['id'] : (int)db()->lastInsertId();

    // Invalidar caché
    unset($GLOBALS['_cache'][$t], $GLOBALS['_indices'][$t]);
    if ($t === 'marcaciones') $GLOBALS['_reiniciar_marcas'] = 1;
    if ($t === 'vacaciones') $GLOBALS['_reiniciar_aus'] = 1;

    return $id;
}

/** Elimina un registro por id. */
function eliminar(string $t, int $id): void {
    $st = db()->prepare("DELETE FROM `$t` WHERE id = ?");
    $st->execute([$id]);
    unset($GLOBALS['_cache'][$t], $GLOBALS['_indices'][$t]);
    if ($t === 'marcaciones') $GLOBALS['_reiniciar_marcas'] = 1;
    if ($t === 'vacaciones') $GLOBALS['_reiniciar_aus'] = 1;
}

/** Invalida la caché de petición de una tabla (útil tras DELETE externo). */
function invalidar_tabla(string $t): void {
    unset($GLOBALS['_cache'][$t], $GLOBALS['_indices'][$t]);
}

/** Devuelve el siguiente id sugerido para una tabla. */
function siguiente_id(string $t): int {
    $st = db()->query("SELECT COALESCE(MAX(id), 0) + 1 AS sig FROM `$t`");
    return (int)$st->fetchColumn();
}

/** Parámetros generales (primer registro de la tabla parametros). */
function param(string $clave) {
    $p = tabla('parametros')[0] ?? [];
    return $p[$clave] ?? null;
}
