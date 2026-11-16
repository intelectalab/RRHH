<?php

/*
 * AYU-1 · Ayuda del producto que acompaña a GestiaHR. El comando help:sync-bundled la carga en la consola
 * como artículos publicados (versión 1) si todavía no existen; desde ahí GestiaHR la edita y publica
 * versiones nuevas. Los textos están en es.php, en.php y pt.php con la misma clave (slug).
 *
 * kind: guide (guía por rol o módulo) · screen (ayuda de la pantalla) · field (ayuda de un campo:
 * slug «campo.<pantalla>.<campo>»). roles vacío = todos los roles. screens: claves de screens.json.
 */
return [
    // Guías por rol.
    'primeros-pasos' => ['kind' => 'guide', 'module' => 'base', 'roles' => [], 'screens' => ['home']],
    'guia-administrador' => ['kind' => 'guide', 'module' => 'base', 'roles' => ['admin_empresa'], 'screens' => ['settings']],
    'guia-rrhh' => ['kind' => 'guide', 'module' => 'core', 'roles' => ['admin_empresa', 'rrhh'], 'screens' => ['people.employees']],
    'guia-jefe' => ['kind' => 'guide', 'module' => 'core', 'roles' => ['manager'], 'screens' => ['my_team']],
    'guia-colaborador' => ['kind' => 'guide', 'module' => 'core', 'roles' => ['empleado'], 'screens' => ['portal.home']],
    'guia-auditor' => ['kind' => 'guide', 'module' => 'base', 'roles' => ['auditor', 'contador'], 'screens' => []],
    // Guías por módulo.
    'guia-aprobaciones-y-tareas' => ['kind' => 'guide', 'module' => 'base', 'roles' => [], 'screens' => ['approvals', 'tasks']],
    'guia-colaboradores-y-legajo' => ['kind' => 'guide', 'module' => 'core', 'roles' => ['admin_empresa', 'rrhh', 'payroll'], 'screens' => ['people.employees', 'documents.expiry']],
    'guia-vacaciones-y-ausencias' => ['kind' => 'guide', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.vacations', 'time.absences']],
    // Ayuda de cada pantalla.
    'pantalla-inicio' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['home']],
    'pantalla-pendientes' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['inbox']],
    'pantalla-avisos' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['notifications']],
    'pantalla-aprobaciones' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['approvals']],
    'pantalla-tareas' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['tasks']],
    'pantalla-calendario' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['calendar']],
    'pantalla-seguridad-cuenta' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['account.security']],
    'pantalla-mis-notificaciones' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['account.notifications']],
    'pantalla-primeros-pasos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['setup']],
    'pantalla-configuracion' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['settings']],
    'pantalla-organizacion' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['org']],
    'pantalla-empresas' => ['kind' => 'screen', 'module' => 'base', 'roles' => [], 'screens' => ['org.companies']],
    'pantalla-posiciones' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['org.positions']],
    'pantalla-colaboradores' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.employees']],
    'pantalla-directorio' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.directory']],
    'pantalla-organigrama' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.org_chart']],
    'pantalla-contratos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.contracts']],
    'pantalla-movimientos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.movements']],
    'pantalla-cambios-de-datos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.changes']],
    'pantalla-constancias' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.certificates']],
    'pantalla-solicitudes-rrhh' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['people.requests']],
    'pantalla-mi-equipo' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['my_team']],
    'pantalla-vencimientos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['documents.expiry']],
    'pantalla-solicitudes-documentos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['documents.requests']],
    'pantalla-plantillas' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['documents.templates']],
    'pantalla-vacaciones' => ['kind' => 'screen', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.vacations']],
    'pantalla-ausencias' => ['kind' => 'screen', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.absences']],
    'pantalla-subsidios' => ['kind' => 'screen', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.subsidies']],
    'pantalla-portal' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['portal.home']],
    'pantalla-mis-documentos' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['portal.documents']],
    'pantalla-mi-perfil' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['portal.profile']],
    'pantalla-mis-tramites' => ['kind' => 'screen', 'module' => 'core', 'roles' => [], 'screens' => ['portal.requests']],
    // Ayudas de campo.
    'campo.time.vacations.dates' => ['kind' => 'field', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.vacations']],
    'campo.time.absences.absence_type' => ['kind' => 'field', 'module' => 'time_off', 'roles' => [], 'screens' => ['time.absences']],
    'campo.documents.requests.due_on' => ['kind' => 'field', 'module' => 'core', 'roles' => [], 'screens' => ['documents.requests']],
    'campo.portal.requests.category' => ['kind' => 'field', 'module' => 'core', 'roles' => [], 'screens' => ['portal.requests']],
];
