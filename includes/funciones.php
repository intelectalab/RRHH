<?php
/**
 * funciones.php — Utilidades generales: escape de HTML, fechas en formato peruano,
 * fechas relativas («hace 2 días»), plurales, montos en soles y enlaces internos.
 */

const MESES  = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const MES3   = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Set','Oct','Nov','Dic'];
const DIAS   = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const DIAS1  = ['D','L','M','M','J','V','S'];

/** Escapa texto para HTML. */
function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/** AAAA-MM-DD → dd/mm/aaaa */
function f(?string $iso): string { return $iso ? substr($iso,8,2).'/'.substr($iso,5,2).'/'.substr($iso,0,4) : ''; }

/** dd/mm/aaaa → AAAA-MM-DD (o null si no es una fecha válida). */
function fecha_iso(?string $s): ?string {
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim((string)$s), $m)) return null;
    return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? "$m[3]-$m[2]-$m[1]" : null;
}

function add_dias(string $iso, int $n): string { return date('Y-m-d', strtotime("$iso 12:00 $n days")); }
function add_meses(string $iso, int $n): string { $d = new DateTime("$iso 12:00"); $d->modify("$n months"); return $d->format('Y-m-d'); }
function dif_dias(string $a, string $b): int { return (int)round((strtotime("$b 12:00") - strtotime("$a 12:00")) / 86400); }
function dia_sem(string $iso): int { return (int)date('w', strtotime("$iso 12:00")); }
function fin_mes(string $ym): string { return date('Y-m-t', strtotime("$ym-01 12:00")); }
function mes_nombre(int $m, bool $mayus = true): string { $t = MESES[$m-1]; return $mayus ? mb_strtoupper(mb_substr($t,0,1)).mb_substr($t,1) : $t; }

/** «Jueves 22 de octubre de 2026» */
function fecha_larga(string $iso): string {
    $d = DIAS[dia_sem($iso)];
    return mb_strtoupper(mb_substr($d,0,1)).mb_substr($d,1).' '.(int)substr($iso,8,2).' de '.MESES[(int)substr($iso,5,2)-1].' de '.substr($iso,0,4);
}

/** Fecha relativa: hoy, ayer, mañana, hace 3 días, en 2 semanas, hace 4 meses… */
function hace(string $iso): string {
    $d = dif_dias($iso, HOY);
    if ($d === 0) return 'hoy';
    if ($d === 1) return 'ayer';
    if ($d === -1) return 'mañana';
    $a = abs($d);
    $t = $a < 14 ? pl($a,'día','días') : ($a < 60 ? pl(intdiv($a,7),'semana','semanas') : ($a < 730 ? pl(intdiv($a,30),'mes','meses') : pl(intdiv($a,365),'año','años')));
    return $d > 0 ? "hace $t" : "en $t";
}

/** Fecha con su versión relativa en un tooltip: <time title="22/10/2026">hace 2 días</time> */
function cuando(string $iso, bool $relativo = true): string {
    return $relativo ? '<time datetime="'.e($iso).'" data-tip="'.f($iso).'">'.e(hace($iso)).'</time>' : '<time datetime="'.e($iso).'">'.f($iso).'</time>';
}

function pl(int $n, string $s, string $p): string { return $n.' '.($n === 1 ? $s : $p); }
function soles($n): string { return 'S/ '.number_format((float)$n, 2, '.', ','); }
function num($n, int $dec = 0): string { return number_format((float)$n, $dec, ',', ' '); }
function pct($a, $b, int $dec = 0): string { return $b ? number_format($a / $b * 100, $dec, ',', '') . ' %' : '—'; }
function min_a_hora(?string $h): ?int { if (!$h) return null; [$a,$b] = array_map('intval', explode(':', $h)); return $a*60+$b; }
function hora(int $m): string { return sprintf('%02d:%02d', intdiv($m,60), $m%60); }
function duracion(int $m): string { return intdiv($m,60).':'.sprintf('%02d', $m%60).' h'; }

/** Próxima fecha (hoy o después) en que se cumple un aniversario de $iso: cumpleaños, años en la empresa. */
function cumple_proximo(string $iso): string {
    $md = substr($iso, 5) === '02-29' ? '02-28' : substr($iso, 5); $x = substr(HOY, 0, 4).'-'.$md;
    return $x >= HOY ? $x : ((int)substr(HOY, 0, 4) + 1).'-'.$md;
}

/** Antigüedad legible: «7 años y 7 meses». */
function antiguedad(string $desde, ?string $hasta = null): string {
    $a = new DateTime("$desde 12:00"); $b = new DateTime(($hasta ?: HOY).' 12:00');
    $i = $a->diff($b); $an = $i->y; $me = $i->m;
    if (!$an && !$me) return 'menos de un mes';
    return implode(' y ', array_filter([$an ? pl($an,'año','años') : '', $me ? pl($me,'mes','meses') : '']));
}

/** Enlace interno: url('personal', ['vista' => 'activos']) → index.php?p=personal&vista=activos */
function url(string $p, array $params = []): string {
    $q = http_build_query(array_filter(['p' => $p] + $params, fn($v) => $v !== null && $v !== ''));
    return 'index.php?'.$q;
}

/** Quita tildes y la eñe (sin depender de iconv, que se comporta distinto en Windows). */
function sin_tildes(string $s): string { return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']); }

/** Texto sin tildes ni espacios para nombres de archivo: «Rosa Quispe» → rosa-quispe */
function slug(string $s): string {
    $s = sin_tildes(mb_strtolower($s));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Ícono de la librería Phosphor (incluida en assets/vendor). */
function ic(string $n, string $clase = ''): string { return '<i class="ph ph-'.$n.' '.$clase.'" aria-hidden="true"></i>'; }

/** Token de seguridad para formularios (evita envíos desde otros sitios). */
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function campo_csrf(): string { return '<input type="hidden" name="csrf" value="'.csrf().'">'; }

/** Mensajes que se muestran como aviso (toast) en la siguiente página. */
function avisar(string $msg, string $tipo = 'ok', ?array $deshacer = null): void {
    $_SESSION['avisos'][] = ['msg' => $msg, 'tipo' => $tipo, 'deshacer' => $deshacer];
}

/* ─── Cifrado AES-256-GCM para datos sensibles ───────────────────────────── */
/** Cifra un valor usando AES-256-GCM con la clave APP_CLAVE. Devuelve string base64 o '' si vacío. */
function cifrar(string $valor): string {
    if ($valor === '') return '';
    $clave = defined('APP_CLAVE') ? APP_CLAVE : '';
    if (strlen($clave) < 32) {
        error_log('AyniHR: APP_CLAVE no configurada o demasiado corta para cifrado.');
        return $valor; // degradación segura: sin cifrado si la clave no está
    }
    $key  = substr(hash('sha256', $clave, true), 0, 32);
    $iv   = random_bytes(12); // 96 bits para GCM
    $tag  = '';
    $enc  = openssl_encrypt($valor, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($enc === false) return $valor;
    return base64_encode($iv . $tag . $enc);
}

/** Descifra un valor cifrado con cifrar(). Devuelve el texto plano o '' si falla. */
function descifrar(string $cifrado): string {
    if ($cifrado === '') return '';
    $clave = defined('APP_CLAVE') ? APP_CLAVE : '';
    if (strlen($clave) < 32) return $cifrado; // sin clave → devuelve tal cual
    $raw = base64_decode($cifrado, true);
    if ($raw === false || strlen($raw) < 28) return $cifrado; // no parece cifrado válido
    $key = substr(hash('sha256', $clave, true), 0, 32);
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $enc = substr($raw, 28);
    $dec = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $dec !== false ? $dec : '';
}
