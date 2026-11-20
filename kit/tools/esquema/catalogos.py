# -*- coding: utf-8 -*-
"""Catálogos de GestiaHR: una sola fuente para los JSON y para los INSERT de schema.sql.

Módulos comerciales  <- docs/01-vision-y-fases.md §4
Permisos y matriz    <- docs/07-permisos-y-roles.md §5
Parámetros, parámetros legales de Perú y conceptos de planilla: definidos en este archivo.
"""
import os, re
KIT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))

def _modules_from_doc():
    out = []
    for line in open(f'{KIT}/docs/01-vision-y-fases.md', encoding='utf-8'):
        m = re.match(r'^\| \*{0,2}([^|*]+?)\*{0,2}(?: \(([^)]*)\))? \| `([a-z_]+)` \| ([A-Z ]+) \| (\d) \| ([^|]*) \|', line)
        if not m: continue
        name, paren, key, epics, phase, req = m.groups()
        required, recommended = [], []
        for part in req.replace('—', '').split(';'):
            part = part.strip()
            if not part: continue
            rec = 'recomendado' in part
            for k in part.replace('recomendado', '').split(','):
                k = k.strip()
                if k: (recommended if rec else required).append(k)
        out.append([key, name.strip(), int(phase), bool(paren and 'siempre' in paren), epics.split(), required, recommended, ''])
    assert len(out) >= 20, len(out)
    return out

MODULES = _modules_from_doc()
_DESC = {
 'base': 'Cimientos: multiempresa, identidad, permisos, auditoría, aprobaciones, notificaciones y configuración.',
 'core': 'Estructura, posiciones, colaboradores y ficha 360, historia con vigencia, contratos, documentos y firma, portales e importador.',
}
for _m in MODULES:
    _m[7] = _DESC.get(_m[0], f'Épicas: {", ".join(_m[4])}.')
MODULES = [tuple(m) for m in MODULES]
EPIC_MODULE = {e: m[0] for m in MODULES for e in m[4]}
EPIC_MODULE.setdefault('SAS', 'base')   # la consola SaaS no es un módulo contratable
EPIC_MODULE.setdefault('PAI', 'base')   # si el documento 01 no asigna PAI, sus permisos van a la base
EPIC_MODULE.setdefault('WEB', 'base')   # el sitio comercial no es un módulo contratable


def _products_from_doc():
    """Productos de la web  <- docs/01-vision-y-fases.md §4.1: (clave, nombre, ruta, módulos, se vende solo, es suite, sigla)."""
    out = []
    for line in open(f'{KIT}/docs/01-vision-y-fases.md', encoding='utf-8'):
        m = re.match(r'^\| \*{0,2}((?:Gestia|Suite) [^|*]+?)\*{0,2} \| `([a-z]+)` \| `(/[a-z]+)` \| ([a-z_, ]+) \| ([^|]*) \| ([A-Z]{2,5}) \|', line)
        if m:
            name, key, path, mods, alone, acr = m.groups()
            out.append((key, name.strip(), path, [x.strip() for x in mods.split(',')], alone.strip().startswith('Sí'), key == 'suite', acr))
    keys = {m[0] for m in MODULES}
    assert len(out) == 6 and all(x in keys for p in out for x in p[3]), out
    return out


PRODUCTS = _products_from_doc()


def _plans_from_doc():
    """Planes y complementos iniciales  <- docs/01-vision-y-fases.md §4.2. Sin montos: el precio se fija en la consola.
    Plan: (clave, nombre, tope o None si es a la medida, módulos, funciones, público).
    Complemento: (clave, nombre, producto, módulos)."""
    plans, addons = [], []
    keys = [m[0] for m in MODULES]
    prods = {p[0] for p in PRODUCTS}
    sec = open(f'{KIT}/docs/01-vision-y-fases.md', encoding='utf-8').read().split('### 4.2', 1)[1].split('\n## ', 1)[0]
    for line in sec.splitlines():
        m = re.match(r'^\| ([^|]+?) \| `([a-z_]+)` \| (\d+|a la medida) \| ([^|]+?) \| ([^|]+?) \| (Sí|No) \|$', line)
        if m:
            name, key, lim, mods, feats, pub = m.groups()
            if mods == 'todos': mods = list(keys)
            elif mods == 'según contrato': mods = ['base', 'core']
            else: mods = [x.strip() for x in mods.split(',')]
            feats = [] if feats in ('—', 'según contrato') else [x.strip() for x in feats.split(',')]
            plans.append((key, name, None if lim == 'a la medida' else int(lim), mods, feats, pub == 'Sí'))
            continue
        m = re.match(r'^\| ([^|]+?) \| `([a-z_]+)` \| ([a-z]+) \| ([a-z_, ]+) \| [^|]+ \|$', line)
        if m:
            name, key, prod, mods = m.groups()
            addons.append((key, name, prod, [x.strip() for x in mods.split(',')]))
    deps = {m[0]: m[5] for m in MODULES}
    assert len(plans) == 4 and len(addons) == 4, (plans, addons)
    for key, name, lim, mods, feats, pub in plans:
        assert all(x in keys for x in mods), (key, mods)
        for x in mods:   # un plan no puede romper module_dependencies (ADR 0008)
            assert all(d in mods for d in deps[x]), f'plan {key}: {x} exige {deps[x]}'
    for key, name, prod, mods in addons:
        assert prod in prods and all(x in keys for x in mods), (key, prod, mods)
    return plans, addons


PLANS, ADDONS = _plans_from_doc()

SCOPES = [
 ('platform', 'Consola SaaS (fuera de toda organización)'),
 ('organization', 'Organización: todas las empresas del grupo'),
 ('legal_entity', 'Empresa: una o varias razones sociales'),
 ('site', 'Sede: una o varias sedes'),
 ('org_unit', 'Unidad organizativa y sus descendientes'),
 ('team', 'Equipo: quienes le reportan, directa o indirectamente'),
 ('own', 'Propio: solo las relaciones laborales de la propia persona'),
 ('assigned', 'Asignado: solo los registros asignados al usuario (casos, solicitudes, tareas, vacantes)'),
]

# columna de la matriz, código, nombre, ámbito, alcance por defecto, descripción
ROLE_COLS = [
 ('SA', 'superadmin_saas', 'Superadmin SaaS', 'platform', 'platform', 'Propietario del SaaS. Opera en una consola aparte; no es un rol de organización y no ve datos de RRHH salvo acceso de soporte autorizado.'),
 ('AD', 'admin_empresa', 'Administrador empresa', 'tenant', 'organization', 'Administra la organización completa. Por defecto no accede a denuncias ni a datos médicos: esos permisos se asignan aparte.'),
 ('RH', 'rrhh', 'RRHH', 'tenant', 'organization', 'Gestión de personas en toda la organización. No procesa ni cierra planilla y no ve diagnósticos médicos ni denuncias.'),
 ('PY', 'payroll', 'Payroll', 'tenant', 'organization', 'Responsable de planilla: conceptos, periodos, cálculo, boletas, beneficios sociales, declaraciones, préstamos y locadores.'),
 ('RC', 'recruiter', 'Recruiter', 'tenant', 'organization', 'Reclutamiento y selección. No ve sueldos de colaboradores.'),
 ('MG', 'manager', 'Manager', 'tenant', 'team', 'Jefe de equipo: ve y aprueba lo de quienes le reportan. Por defecto no ve sueldos (C-10). Se combina con el rol Empleado.'),
 ('EM', 'empleado', 'Empleado', 'tenant', 'own', 'Autoservicio: cada colaborador ve y gestiona solo lo propio. Sirve para personal sin correo corporativo (C-09).'),
 ('AU', 'auditor', 'Auditor', 'tenant', 'organization', 'Solo lectura sobre la organización, incluida la auditoría.'),
 ('ST', 'sst', 'SST', 'tenant', 'organization', 'Seguridad y salud en el trabajo, equipos de protección y capacitación obligatoria.'),
 ('IV', 'investigador_denuncias', 'Investigador de denuncias', 'tenant', 'assigned', 'Acceso restringido al canal de denuncias: solo los casos que tiene asignados (complaint_assignments).'),
 ('CT', 'contador', 'Contador', 'tenant', 'organization', 'Solo lectura de planilla y asientos; puede exportar asientos contables.'),
]
ROLES = [(code, name, realm, desc, None, None) for col, code, name, realm, scope, desc in ROLE_COLS]
# SEG-3 · RN-SEG-08 · ADR 0011: roles con acceso a datos sensibles que traen el segundo factor obligatorio.
MFA_REQUIRED_ROLES = {'superadmin_saas', 'admin_empresa', 'payroll', 'investigador_denuncias'}

# Permisos que valen para toda la organización aunque el rol tenga otro alcance por defecto.
ORG_WIDE_PERMS = {'people.directory.view'}

def _parse_perm_doc():
    perms, grants, seen = [], {c[1]: {} for c in ROLE_COLS}, {}
    epic = None
    for line in open(f'{KIT}/docs/07-permisos-y-roles.md', encoding='utf-8'):
        h = re.match(r'^#### ([A-Z]+) · ', line)
        if h: epic = h.group(1); continue
        m = re.match(r'^\| `([a-z0-9_]+(?:\.[a-z0-9_]+){2,})`((?: \*\*\([S+]\)\*\*)*) \| (.*?) \|((?:[^|]*\|){11})\s*$', line)
        if not m: continue
        code, marks, desc, cells = m.groups()
        cells = [c.strip() for c in cells.split('|')[:11]]
        parts = code.split('.')
        only_platform = bool(cells[0]) and not any(cells[1:])
        realm = 'plataforma' if (epic == 'SAS' or only_platform) else 'organizacion'
        if code not in seen:
            seen[code] = dict(codigo=code, epica=epic, modulo=EPIC_MODULE[epic], recurso='.'.join(parts[1:-1]), accion=parts[-1],
                              tipo='campo' if len(parts) > 3 else 'accion', sensible='(S)' in marks,
                              en_contrato_api='(+)' not in marks, ambito=realm, descripcion=desc.strip())
            perms.append(seen[code])
        for (col, rcode, rname, rrealm, rscope, rdesc), cell in zip(ROLE_COLS, cells):
            if not cell: continue
            assert cell in ('✓', '○'), (code, cell)
            if rrealm == 'tenant' and seen[code]['ambito'] == 'plataforma': continue
            # «○» = solo lo propio o lo asignado: «own» en Empleado y Manager, «assigned» en los demás roles.
            scope = rscope if cell == '✓' else ('own' if rcode in ('empleado', 'manager') else 'assigned')
            # ORG-7 · RN-ORG-08: el directorio y el organigrama son de toda la organización para quien los ve;
            # lo que se limita son los campos (contacto laboral publicado), no las personas.
            if code in ORG_WIDE_PERMS and cell == '✓': scope = 'organization'
            grants[rcode][code] = scope
    assert len(perms) > 300, len(perms)
    return perms, grants

_PERMS, _GRANTS = _parse_perm_doc()
def permissions(): return _PERMS
def role_matrix():
    return [dict(codigo=code, nombre=name, ambito=realm, alcance_por_defecto=scope, descripcion=desc,
                 permisos=[dict(codigo=p['codigo'], alcance=_GRANTS[code][p['codigo']]) for p in _PERMS if p['codigo'] in _GRANTS[code]])
            for col, code, name, realm, scope, desc in ROLE_COLS]

# codigo, modulo, nombre, tipo, defecto, alcances, descripcion, opciones
O, E, S, U = 'organization', 'legal_entity', 'site', 'org_unit'
PARAMS = [
 ('org.idioma_defecto', 'base', 'Idioma por defecto', 'select', 'es', [O, E], 'Idioma de la interfaz y de los documentos.', ['es', 'en', 'pt']),
 ('org.zona_horaria', 'base', 'Zona horaria', 'text', 'America/Lima', [O, E, S], 'Zona horaria para fechas, marcaciones y vencimientos.', None),
 ('org.moneda_defecto', 'base', 'Moneda por defecto', 'text', 'PEN', [O, E], 'Moneda ISO 4217 de los montos nuevos.', None),
 ('org.tipo_cambio_faltante', 'base', 'Sin tipo de cambio de la fecha', 'select', 'use_previous', [O], 'RN-CFG-07 · Si falta el tipo de cambio de la fecha al convertir o consolidar: usar el último anterior disponible (el resultado se marca como «tipo de cambio referencial») o bloquear la conversión.', ['use_previous', 'block']),
 ('org.inicio_semana', 'base', 'Primer día de la semana', 'select', 'lunes', [O], 'Día en que empieza la semana en calendarios.', ['lunes', 'domingo']),
 ('seguridad.acceso_con_documento', 'base', 'Acceso con documento de identidad', 'boolean', True, [O], 'Permite ingresar con documento y clave u OTP, sin correo (C-09).', None),
 ('seguridad.canal_otp', 'base', 'Canal de clave temporal', 'select', 'sms', [O], 'Canal para enviar la clave temporal.', ['sms', 'whatsapp', 'email']),
 ('seguridad.clave_longitud_minima', 'base', 'Longitud mínima de la clave', 'integer', 12, [O], 'Cantidad mínima de caracteres de una clave nueva.', {'min': 10, 'max': 64}),
 ('seguridad.clave_historial', 'base', 'Claves anteriores que no se pueden repetir', 'integer', 5, [O], 'Cuántas claves anteriores se recuerdan para impedir reutilizarlas. 0: no se controla.', {'min': 0, 'max': 24}),
 ('seguridad.clave_vencimiento_dias', 'base', 'Vencimiento de la clave (días)', 'integer', 0, [O], 'Días tras los cuales se pide cambiar la clave. 0: no vence.', {'min': 0, 'max': 365}),
 ('seguridad.intentos_maximos', 'base', 'Intentos fallidos antes del bloqueo', 'integer', 5, [O], 'Ingresos fallidos seguidos que bloquean la cuenta temporalmente.', {'min': 3, 'max': 10}),
 ('seguridad.bloqueo_minutos', 'base', 'Duración del bloqueo (minutos)', 'integer', 15, [O], 'Tiempo que la cuenta queda bloqueada tras superar los intentos.', {'min': 5, 'max': 1440}),
 ('seguridad.sesion_duracion_minutos', 'base', 'Duración máxima de la sesión (minutos)', 'integer', 480, [O], 'Tiempo tras el cual hay que volver a ingresar aunque se esté usando.', {'min': 30, 'max': 1440}),
 ('seguridad.inactividad_minutos', 'base', 'Cierre por inactividad (minutos)', 'integer', 30, [O], 'Minutos sin uso tras los cuales la sesión se cierra.', {'min': 5, 'max': 240}),
 ('seguridad.mfa_todos', 'base', 'Verificación en dos pasos para todos', 'boolean', False, [O], 'Exige el segundo factor a todos los usuarios, además de los roles que ya lo exigen.', None),
 ('seguridad.otp_minutos', 'base', 'Vigencia del código por mensaje (minutos)', 'integer', 5, [O], 'Minutos que dura el código de ingreso o de activación enviado por SMS o WhatsApp.', {'min': 2, 'max': 15}),
 ('seguridad.otp_intentos', 'base', 'Intentos por código', 'integer', 5, [O], 'Intentos fallidos que admite un código antes de anularse.', {'min': 3, 'max': 10}),
 ('seguridad.otp_reenvio_segundos', 'base', 'Espera para pedir otro código (segundos)', 'integer', 60, [O], 'Tiempo mínimo entre dos envíos de código al mismo usuario.', {'min': 30, 'max': 600}),
 ('seguridad.marca_agua_descargas', 'base', 'Marca de agua en exportaciones', 'boolean', False, [O], 'Agrega a cada archivo exportado el nombre de quien lo descargó y la fecha.', None),
 ('exportaciones.umbral_segundo_plano', 'base', 'Filas para exportar en segundo plano', 'integer', 1000, [O], 'UI-11 · RN-UI-08: una exportación con más filas que este número se genera en segundo plano y avisa con un enlace cuando está lista; las más chicas se descargan al instante.', {'min': 100, 'max': 50000}),
 ('seguridad.acceso_soporte', 'base', 'Acceso del soporte de GestiaHR', 'boolean', True, [O], 'Permite que el soporte de GestiaHR entre a la organización con motivo registrado y por tiempo limitado (RN-SAS-08). Desactivado, ninguna sesión de soporte puede abrirse.', None),
 ('seguridad.registrar_acceso_sensible', 'base', 'Registrar accesos a datos sensibles', 'boolean', True, [O], 'Deja constancia de cada consulta de sueldo, salud, denuncias, sanciones o biometría.', None),
 ('importacion.politica_errores', 'core', 'Filas con error al importar', 'select', 'only_valid', [O], 'Si una importación tiene filas con error: importar solo las válidas (las demás se descargan con su motivo) o exigir que se corrija todo antes (RN-IMP-02).', ['only_valid', 'fix_all']),
 ('importacion.tamano_lote', 'core', 'Filas por lote de importación', 'integer', 500, [O], 'Filas que se confirman juntas en una transacción; si un lote falla, se deshace la importación completa.', {'min': 50, 'max': 5000}),
 ('importacion.max_filas', 'core', 'Filas máximas por archivo', 'integer', 50000, [O], 'Filas que admite un archivo de importación; los archivos más grandes se dividen.', {'min': 100, 'max': 200000}),
 ('papelera.dias_restauracion', 'base', 'Días en papelera', 'integer', 30, [O], 'Días durante los que un registro eliminado puede restaurarse desde la papelera.', None),
 ('aprobaciones.recordatorio_horas', 'base', 'Recordatorio de aprobación', 'integer', 24, [O], 'Horas tras las que se recuerda una aprobación pendiente.', None),
 ('aprobaciones.escalar_horas', 'base', 'Escalamiento de aprobación', 'integer', 72, [O], 'Horas tras las que una aprobación pendiente sube al siguiente nivel.', None),
 ('aprobaciones.permitir_masivas', 'base', 'Aprobaciones masivas', 'boolean', True, [O], 'Permite aprobar varias solicitudes a la vez cuando el tipo lo admite.', None),
 ('automatizaciones.max_ejecuciones_hora', 'base', 'Ejecuciones por hora de una regla', 'integer', 100, [O], 'Ejecuciones de una misma regla en una hora a partir de las cuales se pausa sola y se avisa al administrador (protección contra bucles, RN-WFL-11).', {'min': 10, 'max': 10000}),
 ('automatizaciones.reintentos', 'base', 'Reintentos de una acción fallida', 'integer', 3, [O], 'Veces que se reintenta automáticamente una ejecución fallida antes de dejarla para revisión manual (RN-WFL-10).', {'min': 0, 'max': 10}),
 ('notificaciones.canales_activos', 'base', 'Canales de notificación', 'list', ['in_app', 'email'], [O], 'Canales habilitados para la organización.', ['in_app', 'email', 'push', 'whatsapp', 'sms']),
 ('notificaciones.silencio_desde', 'base', 'Inicio del horario de silencio', 'time', None, [O], 'Hora local desde la que los avisos no urgentes por correo se envían al terminar el silencio. Vacío: sin silencio.', None),
 ('notificaciones.silencio_hasta', 'base', 'Fin del horario de silencio', 'time', None, [O], 'Hora local en que se entregan los avisos que esperaron por el silencio.', None),
 ('notificaciones.obligatorias', 'base', 'Avisos obligatorios', 'list', [], [O], 'Tipos de aviso que el usuario no puede desactivar en la aplicación, además de los del producto.', ['approval.pending', 'approval.reminder', 'approval.escalated', 'approval.approved', 'approval.rejected', 'task.assigned', 'task.overdue']),
 ('notificaciones.canal_sin_correo', 'base', 'Canal para quien no tiene correo', 'select', 'sms', [O], 'Canal externo por el que salen los avisos a quien no tiene correo válido (RN-NOT-07): un mensaje corto con el enlace, sin datos sensibles. none: solo en la aplicación.', ['sms', 'whatsapp', 'none']),
 ('directorio.campos_contacto', 'core', 'Contacto visible en el directorio', 'list', ['work_email', 'work_phone'], [O], 'Datos de contacto laboral que todos ven en el directorio y el organigrama (RN-ORG-08). Nunca incluye correo ni celular personal ni datos sensibles.', ['work_email', 'work_phone', 'work_phone_ext']),
 ('limites.porcentaje_alerta', 'base', 'Aviso de consumo del plan (%)', 'integer', 80, [O], 'Porcentaje de un límite del plan (colaboradores, administradores, empresas, almacenamiento) a partir del cual se avisa a los administradores (RN-SAS-02); al 100 % se bloquean las nuevas altas y subidas.', {'min': 50, 'max': 99}),
 ('notificaciones.retencion_dias', 'base', 'Retención de avisos leídos (días)', 'integer', 180, [O], 'Días que un aviso leído se muestra en el centro de notificaciones antes de archivarse.', None),
 ('colaboradores.codigo_automatico', 'core', 'Código de colaborador automático', 'boolean', True, [O, E], 'Genera el código con el correlativo de la empresa.', None),
 ('colaboradores.registrar_sexo', 'core', 'Registrar sexo', 'boolean', True, [O, E], 'Activa el campo cuando la norma lo exige (planilla, igualdad salarial).', None),
 ('colaboradores.cambios_perfil_requieren_aprobacion', 'core', 'Cambios de perfil con aprobación', 'boolean', True, [O], 'Los cambios que pide el colaborador pasan por RRHH antes de aplicarse.', None),
 ('compensacion.cambio_requiere_aprobacion', 'core', 'Cambios de sueldo con aprobación', 'boolean', True, [O, E], 'HIS-3 · Un cambio de sueldo o de componentes fijos pasa por el flujo de aprobación publicado para salary_change antes de registrarse; con falso se registra en el acto (siempre con motivo y auditoría).', None),
 ('colaboradores.cese_bloquea_pendientes', 'core', 'Bloquear el cese con pendientes', 'boolean', False, [O, E], 'Si el cese se registra con activos sin devolver, préstamos o adelantos con saldo o vacaciones aprobadas posteriores a la fecha de cese, se rechaza en lugar de solo advertir (RN-COL-10).', None),
 ('colaboradores.relaciones_simultaneas', 'core', 'Relaciones simultáneas en empresas del grupo', 'boolean', True, [O], 'Permite que una persona tenga a la vez relaciones laborales abiertas con distintas empresas de la organización (COL-10). Con la misma empresa nunca se permite (RN-COL-02).', None),
 ('colaboradores.campos_requieren_aprobacion', 'core', 'Datos que el colaborador cambia con aprobación', 'list', ['first_names', 'paternal_surname', 'maternal_surname', 'birth_date', 'sex', 'marital_status', 'nationality_code', 'address'], [O], 'RN-COL-06 · Datos personales que, propuestos por el propio colaborador, esperan a RRHH; los demás (nombre preferido, correo y celular personales) se aplican en el acto.', ['first_names', 'paternal_surname', 'maternal_surname', 'preferred_name', 'birth_date', 'sex', 'marital_status', 'nationality_code', 'personal_email', 'mobile_phone', 'address']),
 ('colaboradores.campos_editables_portal', 'core', 'Datos que el colaborador cambia desde el portal', 'list', ['preferred_name', 'birth_date', 'marital_status', 'nationality_code', 'personal_email', 'mobile_phone', 'address'], [O], 'ESS-2 · Datos personales que el colaborador puede proponer cambiar desde su perfil; los demás solo los cambia RRHH (con sustento). Los de colaboradores.campos_requieren_aprobacion esperan a RRHH.', ['first_names', 'paternal_surname', 'maternal_surname', 'preferred_name', 'birth_date', 'sex', 'marital_status', 'nationality_code', 'personal_email', 'mobile_phone', 'address']),
 ('contratos.requiere_aprobacion', 'core', 'Contratos con aprobación antes de la firma', 'boolean', True, [O, E], 'CON-2 · ME-11: con «sí», el contrato y la renovación pasan por aprobación (Borrador → En aprobación → En firma); con «no», al enviarlos se genera el documento y quedan listos para firmar.', None),
 ('contratos.aviso_fin_prueba_dias', 'core', 'Aviso del fin del periodo de prueba (días)', 'integer', 7, [O, E], 'CON-2: días antes del fin del periodo de prueba en que se avisa al jefe y a RRHH. 0: el mismo día.', {'min': 0, 'max': 60}),
 ('celebraciones.mostrar_cumpleanos', 'core', 'Mostrar cumpleaños', 'boolean', True, [O], 'MSS-4 · RN-CIN-04: con «no», los cumpleaños no aparecen en ningún tablero, en las fechas clave del equipo ni en los recordatorios. Cuando se muestran, solo viaja el día (nunca el año ni la edad).', None),
 ('puesta_en_marcha.omitida', 'core', 'Ocultar el asistente de primeros pasos', 'boolean', False, [O], 'IMP-3: con «sí», el asistente de primeros pasos deja de mostrarse en el inicio aunque falten pasos; se vuelve a mostrar desde Primeros pasos. Se oculta solo cuando todos los pasos están hechos u omitidos.', None),
 ('equipo.aviso_fechas_clave_dias', 'core', 'Recordatorio de cumpleaños y aniversarios (días)', 'integer', 1, [O], 'MSS-4: días antes de un cumpleaños o aniversario de ingreso en que se avisa al jefe. 0: el mismo día. El fin del periodo de prueba y el vencimiento de contrato se avisan con sus propios parámetros.', {'min': 0, 'max': 30}),
 ('colaboradores.cuenta_bancaria_requiere_aprobacion', 'core', 'Cambio de cuenta de pago con aprobación', 'boolean', True, [O, E], 'COL-8 · RN-COL-11: una cuenta nueva o cambiada queda pendiente hasta que otra persona con permiso la aprueba; sin aprobación la registra activa quien la carga.', None),
 ('movimientos.tipos_con_aprobacion', 'core', 'Movimientos que pasan por aprobación', 'list', ['promotion'], [O, E], 'HIS-2: tipos de movimiento de personal que, aunque los registre RRHH, esperan el flujo de aprobación publicado para «personnel_action» antes de aplicarse. Lo que propone un jefe siempre pasa por aprobación.', ['promotion', 'transfer', 'manager_change', 'cost_center_change', 'data_correction']),
 ('posiciones.factor_carga_pct', 'core', 'Cargas del empleador para el costo proyectado (%)', 'decimal', 0, [O, E], 'Porcentaje que se suma al sueldo vigente o al punto medio de la banda para estimar el costo mensual de una posición (aportes del empleador, gratificaciones, CTS). Es una estimación de la organización, por validar con planilla; 0: el costo proyectado es solo el sueldo (RN-POS-07).', {'min': 0, 'max': 200}),
 ('posiciones.contratar_solo_con_posicion', 'core', 'Contratar solo contra posición aprobada', 'boolean', False, [O, E], 'POS-6 · RN-POS-01 · Activa: un ingreso sin una posición aprobada y libre se rechaza. Desactivada: el ingreso sin posición crea una posición implícita para la persona, así la dotación siempre cuenta plazas.', None),
 ('contratos.alerta_vencimiento_dias', 'core', 'Aviso de vencimiento de contrato', 'list', [60, 30, 15], [O, E], 'Días de anticipación con que se avisa el vencimiento.', None),
 ('teletrabajo.aviso_respuesta_dias', 'core', 'Aviso antes del plazo de respuesta a una solicitud de teletrabajo (días)', 'integer', 2, [O, E], 'CON-6: días antes de que venza el plazo legal de respuesta (parámetro legal teletrabajo_respuesta_dias_habiles) en que se avisa a RRHH de una solicitud de teletrabajo sin responder. 0: el mismo día del vencimiento.', {'min': 0, 'max': 10}),
 ('contratos.alerta_tope_acumulado_dias', 'core', 'Aviso de tope acumulado', 'integer', 90, [O, E], 'Días de anticipación con que se avisa que un contrato a modalidad se acerca a su plazo máximo.', None),
 ('documentos.firma_nivel_defecto', 'core', 'Nivel de firma por defecto', 'select', 'simple', [O, E], 'Nivel de firma electrónica para documentos laborales.', ['simple', 'advanced', 'digital_certificate']),
 ('documentos.firma_otp_obligatorio', 'core', 'OTP al firmar', 'boolean', True, [O], 'Exige clave temporal como parte de la evidencia de firma.', None),
 ('documentos.tamano_maximo_mb', 'core', 'Tamaño máximo de archivo (MB)', 'integer', 20, [O], 'Tamaño máximo por archivo subido.', None),
 ('constancias.emision_automatica', 'core', 'Constancias de emisión automática', 'list', ['employment_certificate'], [O], 'ESS-4 · Constancias que el colaborador obtiene al instante desde el portal, sin intervención de RRHH; las demás pasan por el flujo de aprobación de constancias o las emite RRHH.', ['employment_certificate', 'income_certificate', 'vacation_certificate']),
 ('documentos.aviso_vencimiento_dias', 'core', 'Aviso de vencimiento de documentos', 'integer', 30, [O, E], 'DOC-2 · RN-DOC-09: días de anticipación con que se avisa al responsable y al titular que un documento vence, cuando su tipo no fija los suyos.', None),
 ('asistencia.metodos_permitidos', 'attendance', 'Métodos de marcación', 'list', ['web', 'app'], [O, E, S, U], 'Métodos de marcación habilitados.', ['web', 'app', 'qr', 'kiosk', 'pin', 'biometric', 'crew', 'api']),
 ('asistencia.tolerancia_minutos', 'attendance', 'Tolerancia de ingreso (min)', 'integer', 5, [O, E, S, U], 'Minutos de tolerancia antes de contar tardanza.', None),
 ('asistencia.exigir_geocerca', 'attendance', 'Exigir geocerca', 'boolean', False, [O, E, S, U], 'Solo acepta marcaciones de la app dentro de una geocerca.', None),
 ('asistencia.exigir_selfie', 'attendance', 'Exigir selfie', 'boolean', False, [O, E, S, U], 'Pide foto al marcar desde la app. Requiere evaluación de impacto aprobada.', None),
 ('asistencia.modo_sin_conexion', 'attendance', 'Marcación sin conexión', 'boolean', True, [O, E, S], 'El dispositivo guarda y sincroniza después.', None),
 ('asistencia.redondeo_minutos', 'attendance', 'Redondeo (min)', 'integer', 0, [O, E], 'Redondeo del tiempo trabajado; 0 = sin redondeo.', None),
 ('vacaciones.anticipacion_minima_dias', 'time_off', 'Anticipación mínima', 'integer', 7, [O, E], 'Días de anticipación para solicitar vacaciones.', None),
 ('vacaciones.valida_rrhh', 'time_off', 'Validación de RRHH', 'boolean', False, [O, E], 'Tras el jefe, RRHH valida la solicitud.', None),
 ('vacaciones.maximo_ausentes_simultaneos', 'time_off', 'Máximo de ausentes simultáneos por unidad', 'integer', 0, [O, E], 'VAC-4 · Si más personas de una misma unidad estarán ausentes el mismo día (vacaciones y ausencias aprobadas o pendientes), el calendario del equipo y la aprobación lo alertan. 0 = sin límite.', None),
 ('vacaciones.alerta_acumulacion_dias', 'time_off', 'Aviso de vacaciones acumuladas', 'integer', 30, [O, E], 'Saldo desde el que se alerta acumulación.', None),
 ('ausencias.dias_descanso_semanal', 'time_off', 'Días de descanso semanal (cómputo en días hábiles)', 'list', ['saturday', 'sunday'], [O, E, S], 'AUS-2 · Días de la semana que no cuentan cuando un tipo de ausencia se computa en días hábiles (además de los feriados del calendario de la sede). Se reemplaza por el horario del trabajador cuando exista el módulo de Tiempo.', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
 ('horas_extra.requiere_autorizacion', 'attendance', 'Autorización previa', 'boolean', True, [O, E, S, U], 'El sobretiempo solo se reconoce si fue autorizado.', None),
 ('horas_extra.compensacion', 'attendance', 'Compensación del sobretiempo', 'select', 'pay', [O, E], 'Pago, bolsa de horas o a elección.', ['pay', 'time_bank', 'choice']),
 ('horas_extra.vencimiento_bolsa_dias', 'attendance', 'Vencimiento de la bolsa (días)', 'integer', None, [O, E], 'Días tras los que vence un abono en bolsa; vacío = según acuerdo.', None),
 ('nomina.variacion_neto_alerta_pct', 'payroll', 'Variación de neto para alertar (%)', 'decimal', 20, [O, E], 'Variación frente al periodo anterior que genera una anomalía a revisar.', None),
 ('nomina.publicar_boletas_al_cerrar', 'payroll', 'Publicar boletas al cerrar', 'boolean', True, [O, E], 'Publica las boletas en el portal al cerrar el periodo.', None),
 ('nomina.exigir_acuse_boleta', 'payroll', 'Exigir acuse de boleta', 'boolean', False, [O, E], 'El trabajador confirma la recepción de su boleta.', None),
 ('nomina.doble_aprobacion_cierre', 'payroll', 'Doble aprobación de cierre', 'boolean', True, [O, E], 'Quien procesa no puede aprobar ni cerrar.', None),
 ('prestamos.adelanto_tope_pct_devengado', 'advances', 'Tope de adelanto sobre lo devengado (%)', 'decimal', 50, [O, E], 'Porcentaje máximo de lo ya devengado que puede adelantarse (E-09).', None),
 ('prestamos.adelantos_por_periodo', 'advances', 'Adelantos por periodo', 'integer', 2, [O, E], 'Número máximo de adelantos por periodo de planilla.', None),
 ('reclutamiento.retencion_candidatos_meses', 'recruiting', 'Retención de candidatos (meses)', 'integer', 24, [O], 'Meses que se conservan los datos de candidatos no contratados, según el consentimiento.', None),
 ('reclutamiento.mostrar_rango_salarial', 'recruiting', 'Mostrar rango salarial', 'boolean', False, [O, E], 'Publica el rango salarial en el portal de empleos.', None),
 ('onboarding.dias_preingreso', 'onboarding', 'Inicio del pre-ingreso (días)', 'integer', 7, [O, E], 'Días antes del ingreso en que empieza el pre-onboarding.', None),
 ('desempeno.minimo_respuestas_anonimas', 'performance', 'Mínimo de respuestas de pares', 'integer', 3, [O], 'Menos respuestas que este mínimo no se muestran, para proteger el anonimato de pares.', None),
 ('aprendizaje.aviso_vencimiento_dias', 'learning', 'Aviso de curso por vencer', 'integer', 7, [O], 'Días de anticipación del recordatorio.', None),
 ('compensaciones.tamano_minimo_grupo', 'compensation', 'Tamaño mínimo de grupo', 'integer', 3, [O], 'Grupo mínimo para mostrar promedios salariales en el reporte de brecha.', None),
 ('encuestas.umbral_anonimato', 'engagement', 'Umbral de anonimato', 'integer', 5, [O], 'Mínimo de respuestas por segmento para mostrar resultados (C-06).', None),
 ('ayuda.revision_anticipacion_dias', 'core', 'Aviso de revisión de artículos (días)', 'integer', 7, [O], 'AYU-4 · RN-AYU-04: días antes de la fecha de revisión de un artículo de la organización en que se avisa a su responsable y el artículo pasa a «por revisar» (sigue visible).', {'min': 0, 'max': 90}),
 ('mesa_ayuda.sla_respuesta_horas', 'helpdesk', 'Primera respuesta (horas)', 'integer', 8, [O], 'Plazo por defecto de primera respuesta.', None),
 ('mesa_ayuda.sla_resolucion_horas', 'helpdesk', 'Resolución (horas)', 'integer', 48, [O], 'Plazo por defecto de resolución.', None),
 ('sst.aviso_examen_dias', 'sst', 'Aviso de examen médico', 'integer', 30, [O, E], 'Días de anticipación del aviso de examen ocupacional por vencer.', None),
 ('sst.aviso_epp_dias', 'sst', 'Aviso de equipo por vencer', 'integer', 15, [O, E], 'Días de anticipación del aviso de vencimiento de equipo de protección.', None),
 ('denuncias.permitir_anonimas', 'grievances', 'Permitir denuncias anónimas', 'boolean', True, [O, E], 'Acepta denuncias sin identificar al denunciante.', None),
 ('gastos.dias_para_rendir', 'expenses', 'Días para rendir', 'integer', 10, [O, E], 'Días para rendir un anticipo tras el viaje.', None),
 ('gastos.descontar_no_rendido', 'expenses', 'Descontar lo no rendido', 'boolean', False, [O, E], 'El saldo no rendido se descuenta por planilla, con autorización del trabajador.', None),
 ('cumplimiento.aviso_vencimiento_dias', 'compliance', 'Aviso de obligación por vencer', 'integer', 15, [O, E], 'Días de anticipación del semáforo en ámbar.', None),
 ('privacidad.retencion_actividad_dias', 'base', 'Conservación de la actividad de usuarios (días)', 'integer', 365, [O], 'Días que se conserva la actividad de los usuarios (ingresos, vistas, descargas, búsquedas). No afecta la auditoría ni los logs técnicos.', None),
 ('privacidad.retencion_auditoria_anios', 'compliance', 'Conservación de auditoría (años)', 'integer', 5, [O], 'Años de conservación de la auditoría de negocio.', None),
 ('analitica.tamano_minimo_segmento', 'analytics', 'Tamaño mínimo de segmento', 'integer', 5, [O], 'No se muestran indicadores de segmentos más pequeños.', None),
 ('ia.habilitada', 'ai', 'Asistente de IA habilitado', 'boolean', False, [O], 'Activa la capa de IA; requiere evaluación de impacto aprobada.', None),
 ('ia.casos_de_uso', 'ai', 'Casos de uso de IA', 'list', [], [O], 'Casos de uso permitidos (redacción, resumen, consulta en lenguaje natural...).', None),
 ('api.limite_por_minuto', 'integrations', 'Límite de llamadas por minuto', 'integer', 120, [O], 'Límite por token.', None),
 ('whatsapp.acciones_permitidas', 'whatsapp', 'Acciones por WhatsApp', 'list', ['boleta', 'vacaciones', 'constancia'], [O], 'Trámites habilitados en el canal.', ['boleta', 'vacaciones', 'constancia', 'marcacion']),
]

PE = 'docs/00-analisis-de-competencia.md §5.4'
# Parámetros que no cambiaron entre 2025 y 2026: su vigencia se extiende al 1-1-2025 para poder recalcular,
# liquidar y calcular CTS y quinta con periodos de 2025. La fecha real de inicio es anterior y no se fija aquí.
DESDE_2025 = {
    'asignacion_familiar_pct_rmv', 'onp_aporte_pct', 'afp_aporte_obligatorio_pct', 'essalud_aporte_pct',
    'gratificacion_bonificacion_extraordinaria_pct', 'gratificacion_meses', 'cts_meses_deposito', 'cts_fraccion_gratificacion',
    'quinta_deduccion_uit', 'quinta_tasa_minima_pct', 'quinta_tasa_maxima_pct', 'quinta_escala', 'quinta_divisores',
    'quinta_cortes_retenciones', 'quinta_no_domiciliado_tasa_pct', 'utilidades_minimo_trabajadores', 'utilidades_pct_industria',
    'utilidades_pct_comercio_restaurantes', 'utilidades_pct_otras_actividades', 'vacaciones_dias', 'cts_dias_por_anio',
    'cts_aplica', 'gratificacion_aplica', 'gratificacion_factor', 'cts_tope_dias', 'indemnizacion_dias_por_anio',
    'indemnizacion_tope_dias', 'asignacion_familiar_aplica', 'dias_base_mes', 'indemnizacion_plazo_fijo_dias_por_mes', 'indemnizacion_vacacional_aplica',
    'vacaciones_plazo_goce_meses',
}
NOTA_2025 = 'Vigencia extendida al 1-1-2025 para periodos de 2025: el valor no cambió entre 2025 y 2026; su fecha real de inicio es anterior.'
def lp(code, name, unit, value, verified, source, note='', regime=None, vfrom='2026-01-01', vfrom_ok=False, json_value=None, vto=None):
    if code in DESDE_2025 and verified and vfrom == '2026-01-01':
        vfrom = '2025-01-01'
        note = (note + ' ' if note else '') + NOTA_2025
    return dict(codigo=code, nombre=name, pais='PE', regimen=regime, unidad=unit,
                valor=value if verified else None, valor_json=json_value if verified else None,
                vigente_desde=vfrom, vigente_hasta=vto, inicio_vigencia_verificado=vfrom_ok,
                verificado=verified, base_legal=source, nota=note)

LEGAL_PE = [
 lp('uit', 'Unidad Impositiva Tributaria', 'amount', 5350, True, 'DS 260-2024-EF', 'Moneda PEN. Ejercicio 2025 (historial para recálculos, liquidaciones y CTS).', vfrom='2025-01-01', vto='2025-12-31', vfrom_ok=True),
 lp('uit', 'Unidad Impositiva Tributaria', 'amount', 5500, True, 'DS 301-2025-EF', 'Moneda PEN. Ejercicio 2026.', vfrom_ok=True),
 lp('rmv', 'Remuneración mínima vital', 'amount', 1130, True, 'DS 006-2024-TR', 'Moneda PEN. Vigente desde el 1 de enero de 2025 y sin cambios en 2026 al corte de esta versión; confirmar antes de cada ejercicio.', vfrom='2025-01-01', vfrom_ok=True),
 lp('asignacion_familiar_pct_rmv', 'Asignación familiar (% de la RMV)', 'percent', 10, True, 'Ley 25129', 'Tomar el 10 % de la RMV vigente; una fuente citaba un monto que no cuadra.'),
 lp('asignacion_familiar_aplica', 'Corresponde asignación familiar', 'count', 1, True, 'Ley 25129', 'Valor general; el régimen lo reemplaza con 0 si no corresponde.'),
 lp('asignacion_familiar_aplica', 'Corresponde asignación familiar', 'count', 0, True, 'DS 013-2013-PRODUCE', 'Fuente secundaria; confirmar con el especialista.', regime='pe_micro'),
 lp('asignacion_familiar_aplica', 'Corresponde asignación familiar', 'count', 0, True, 'DS 013-2013-PRODUCE', 'Fuente secundaria; confirmar con el especialista.', regime='pe_pequena'),
 lp('dias_base_mes', 'Días de la base mensual para prorratear y calcular truncos', 'days', 30, True, 'DS 001-97-TR', 'Remuneración diaria = mensual ÷ 30; el mes completo cuenta 30 días. Confirmar con el especialista.'),
 lp('onp_aporte_pct', 'Aporte al sistema nacional de pensiones', 'percent', 13, True, 'Fuentes secundarias 2026'),
 lp('mayoria_edad_anios', 'Edad en que un hijo derechohabiente pasa a revisión', 'count', 18, True, 'Código Civil, art. 42', 'COL-6: al cumplirla, RRHH revisa si el hijo sigue como derechohabiente (estudios o incapacidad) y su efecto en la asignación familiar.'),
 lp('afp_aporte_obligatorio_pct', 'Aporte obligatorio al fondo (AFP)', 'percent', 10, True, 'Fuentes secundarias 2026'),
 lp('afp_comision_flujo_pct', 'Comisión sobre flujo por AFP', 'percent', None, False, 'SBS', 'Varía por AFP; cargar en pension_fund_rates cada periodo.'),
 lp('afp_comision_mixta_pct', 'Comisión mixta por AFP', 'percent', None, False, 'SBS', 'Varía por AFP; cargar en pension_fund_rates cada periodo.'),
 lp('afp_prima_seguro_pct', 'Prima de seguro previsional', 'percent', None, False, 'SBS', 'Cargar desde la fuente oficial cada periodo.'),
 lp('afp_remuneracion_maxima_asegurable', 'Remuneración máxima asegurable', 'amount', None, False, 'SBS', 'Cargar desde la fuente oficial cada periodo.'),
 lp('essalud_aporte_pct', 'Aporte del empleador a EsSalud', 'percent', 9, True, 'Ley 26790'),
 lp('eps_credito_pct', 'Crédito por EPS contra el aporte de salud', 'percent', None, False, 'Ley 26790', 'Porcentaje no verificado.'),
 lp('gratificacion_bonificacion_extraordinaria_pct', 'Bonificación extraordinaria sobre la gratificación', 'percent', 9, True, 'Ley 30334'),
 lp('gratificacion_bonificacion_extraordinaria_eps_pct', 'Bonificación extraordinaria para afiliados a EPS', 'percent', None, False, 'Ley 30334', 'Porcentaje no verificado.'),
 lp('gratificacion_meses', 'Meses de pago de gratificaciones', 'text', None, True, 'Ley 27735', 'Julio y diciembre; proporcional por meses completos.', json_value=[7, 12]),
 lp('cts_meses_deposito', 'Meses de depósito de CTS', 'text', None, True, 'DS 001-97-TR', 'Primera quincena de mayo y de noviembre.', json_value=[5, 11]),
 lp('cts_fraccion_gratificacion', 'Fracción de la gratificación en la remuneración computable de CTS', 'multiple', 0.166667, True, 'DS 001-97-TR', 'Un sexto de la última gratificación.'),
 lp('quinta_deduccion_uit', 'Deducción para renta de quinta categoría (en UIT)', 'multiple', 7, True, 'Ley del Impuesto a la Renta'),
 lp('quinta_tasa_minima_pct', 'Tasa mínima de la escala de quinta categoría', 'percent', 8, True, 'Ley del Impuesto a la Renta'),
 lp('quinta_tasa_maxima_pct', 'Tasa máxima de la escala de quinta categoría', 'percent', 30, True, 'Ley del Impuesto a la Renta'),
 lp('quinta_escala', 'Escala progresiva acumulativa de quinta categoría (tramos en UIT)', 'scale', None, True, 'LIR art. 53; SUNAT, Tasas del impuesto', 'hasta_uit null = sin tope.', json_value=[{'hasta_uit': 5, 'tasa': 8}, {'hasta_uit': 20, 'tasa': 14}, {'hasta_uit': 35, 'tasa': 17}, {'hasta_uit': 45, 'tasa': 20}, {'hasta_uit': None, 'tasa': 30}]),
 lp('quinta_divisores', 'Divisor de la retención de quinta por mes', 'scale', None, True, 'Reglamento LIR art. 40; SUNAT, Cálculo del impuesto', 'Diciembre regulariza (divisor 1).', json_value={'1': 12, '2': 12, '3': 12, '4': 9, '5': 8, '6': 8, '7': 8, '8': 5, '9': 4, '10': 4, '11': 4, '12': 1}),
 lp('quinta_cortes_retenciones', 'Último mes cuyas retenciones se deducen, por mes de cálculo', 'scale', None, True, 'Reglamento LIR art. 40; SUNAT, Cálculo del impuesto', json_value={'1': 0, '2': 0, '3': 0, '4': 3, '5': 4, '6': 4, '7': 4, '8': 7, '9': 8, '10': 8, '11': 8, '12': 11}),
 lp('quinta_no_domiciliado_tasa_pct', 'Retención de quinta a no domiciliados (sobre el total, sin deducción)', 'percent', 30, True, 'SUNAT, Obligaciones por las rentas de quinta categoría'),
 lp('quinta_previas_sin_detalle', 'Tratamiento de retenciones de empleadores anteriores declaradas sin detalle mensual', 'text', None, False, 'Reglamento LIR art. 40 y 41', 'total o prorrateo; criterio por confirmar.'),
 lp('quinta_fraccionamiento_semanal', 'Fraccionamiento de la retención en pagos semanales o quincenales', 'text', None, False, 'Reglamento LIR art. 40', 'proporcional o ultimo_pago; por confirmar.'),
 lp('utilidades_minimo_trabajadores', 'Trabajadores a partir de los cuales hay participación en utilidades (más de)', 'count', 20, True, 'DL 892'),
 lp('utilidades_pct_industria', 'Participación en utilidades: industria', 'percent', 10, True, 'DL 892', 'Fuente secundaria.'),
 lp('utilidades_pct_comercio_restaurantes', 'Participación en utilidades: comercio y restaurantes', 'percent', 8, True, 'DL 892', 'Fuente secundaria.'),
 lp('utilidades_pct_otras_actividades', 'Participación en utilidades: otras actividades', 'percent', 5, True, 'DL 892', 'Fuente secundaria.'),
 lp('utilidades_pct_otros_sectores', 'Participación en utilidades: demás sectores de la norma', 'scale', None, False, 'DL 892', 'La norma tiene más sectores que los citados; verificar.'),
 lp('vacaciones_dias', 'Días de vacaciones por año', 'days', None, False, 'DL 713', 'Régimen general. No consta en la investigación; se espera 30 días, por confirmar.', regime='pe_general'),
 lp('vacaciones_dias', 'Días de vacaciones por año', 'days', 15, True, 'DS 013-2013-PRODUCE', regime='pe_micro'),
 lp('vacaciones_dias', 'Días de vacaciones por año', 'days', 15, True, 'DS 013-2013-PRODUCE', regime='pe_pequena'),
 # VAC-1 · VAC-2: plazo de goce, récord y límites de venta, fraccionamiento y adelanto (RN-VAC-04, 05, 09).
 lp('vacaciones_plazo_goce_meses', 'Plazo para gozar un periodo vacacional ganado', 'months', 12, True, 'DL 713 art. 23', 'Las vacaciones se gozan dentro del año siguiente a aquel en que se generó el derecho; vencido ese plazo, el periodo queda «vencido».'),
 lp('vacaciones_record_dias_jornada_6', 'Récord vacacional: días efectivos con jornada de 6 días a la semana', 'days', None, False, 'DL 713 art. 10', 'Se espera 260 días de labor efectiva; por confirmar.'),
 lp('vacaciones_record_dias_jornada_5', 'Récord vacacional: días efectivos con jornada de 5 días a la semana', 'days', None, False, 'DL 713 art. 10', 'Se espera 210 días de labor efectiva; por confirmar.'),
 lp('vacaciones_venta_max_dias', 'Días de vacaciones que se pueden vender (reducción del descanso)', 'days', None, False, 'DL 713 art. 19 · DL 1405', 'Tope de la reducción por acuerdo escrito; por confirmar por régimen.'),
 lp('vacaciones_fraccion_minima_dias', 'Bloque mínimo al fraccionar las vacaciones', 'days', None, False, 'DL 1405 art. 2 · DS 013-2019-TR', 'Fraccionamiento del descanso en bloques; por confirmar.'),
 lp('vacaciones_adelanto_max_dias', 'Días de vacaciones que se pueden adelantar antes de cumplir el récord', 'days', None, False, 'DL 1405 art. 3 · DS 013-2019-TR', 'Adelanto a cuenta de días generados en proporción al tiempo trabajado; por confirmar.'),
 lp('cts_dias_por_anio', 'Días de remuneración de CTS por año', 'days', 30, True, 'DS 001-97-TR', 'Valor general: una remuneración computable por año. La calculadora divide el valor del régimen entre este para obtener su proporción.'),
 lp('cts_aplica', 'Corresponde CTS', 'count', 1, True, 'DS 001-97-TR', 'Valor general; el régimen lo reemplaza con 0 si no corresponde.'),
 lp('gratificacion_aplica', 'Corresponde gratificación', 'count', 1, True, 'Ley 27735', 'Valor general; el régimen lo reemplaza con 0 si no corresponde.'),
 lp('gratificacion_factor', 'Factor de gratificación', 'multiple', 1, True, 'Ley 27735', 'Valor general: una remuneración por gratificación.'),
 lp('cts_dias_por_anio', 'Días de remuneración de CTS por año', 'days', 15, True, 'DS 013-2013-PRODUCE', regime='pe_pequena'),
 lp('cts_aplica', 'Corresponde CTS', 'count', 0, True, 'DS 013-2013-PRODUCE', '0 = no corresponde.', regime='pe_micro'),
 lp('gratificacion_aplica', 'Corresponde gratificación', 'count', 0, True, 'DS 013-2013-PRODUCE', '0 = no corresponde.', regime='pe_micro'),
 lp('gratificacion_factor', 'Factor de gratificación', 'multiple', 0.5, True, 'DS 013-2013-PRODUCE', 'Media gratificación.', regime='pe_pequena'),
 lp('mype_ventas_max_uit', 'Ventas anuales máximas (en UIT)', 'multiple', 150, True, 'DS 013-2013-PRODUCE', regime='pe_micro'),
 lp('mype_ventas_max_uit', 'Ventas anuales máximas (en UIT)', 'multiple', 1700, True, 'DS 013-2013-PRODUCE', regime='pe_pequena'),
 lp('sobretiempo_tasa_tramo1_pct', 'Sobretasa de las dos primeras horas extra', 'percent', None, False, 'DS 007-2002-TR', 'No consta en la investigación.'),
 lp('sobretiempo_tasa_tramo2_pct', 'Sobretasa de las horas extra siguientes', 'percent', None, False, 'DS 007-2002-TR', 'No consta en la investigación.'),
 lp('nocturnidad_sobretasa_pct', 'Sobretasa por trabajo nocturno', 'percent', None, False, 'DS 007-2002-TR', 'No consta en la investigación.'),
 lp('jornada_maxima_semanal_horas', 'Jornada máxima semanal', 'hours', None, False, 'DS 007-2002-TR', 'No consta en la investigación.'),
 lp('periodo_prueba_meses', 'Periodo de prueba por tipo de puesto', 'scale', None, False, 'DS 003-97-TR', 'No verificado.'),
 lp('contrato_inicio_actividad_max_anios', 'Plazo máximo: contrato por inicio de actividad', 'years', 3, True, 'DS 003-97-TR'),
 lp('contrato_necesidad_mercado_max_anios', 'Plazo máximo: contrato por necesidad de mercado', 'years', 5, True, 'DS 003-97-TR'),
 lp('contrato_reconversion_max_anios', 'Plazo máximo: contrato por reconversión empresarial', 'years', 2, True, 'DS 003-97-TR'),
 lp('contrato_ocasional_max_meses_por_anio', 'Plazo máximo: contrato ocasional (meses por año)', 'months', 6, True, 'DS 003-97-TR'),
 lp('contrato_modalidad_tope_acumulado_anios', 'Tope acumulado de contratos a modalidad', 'years', None, False, 'DS 003-97-TR', 'El tope general no consta en la investigación.'),
 lp('renuncia_preaviso_dias', 'Preaviso de renuncia (días naturales)', 'days', None, False, 'DS 003-97-TR art. 18', 'Se cita un preaviso de 30 días naturales que el empleador puede exonerar; no verificado. El cese solo advierte si el preaviso registrado es menor (COL-11).'),
 lp('liquidacion_plazo_pago_horas', 'Plazo de pago de la liquidación', 'hours', None, False, 'DS 001-97-TR', 'Las 48 horas no fueron verificadas.'),
 lp('asistencia_conservacion_anios', 'Conservación del registro de asistencia', 'years', 5, True, 'DS 004-2006-TR'),
 lp('boletas_conservacion_anios', 'Conservación de documentos de pago', 'years', None, False, 'DL 1310', 'Citado de memoria en la investigación; validar con el texto vigente.'),
 lp('legajo_conservacion_anios', 'Conservación del legajo después del cese', 'years', None, False, 'Por definir (normas de conservación de documentos laborales)', 'DOC-1 · DOC-2: plazo durante el que un documento del legajo no se depura de la papelera. Sin valor validado, nada se depura.'),
 lp('sst_conservacion_accidentes_anios', 'Conservación: accidentes e incidentes peligrosos', 'years', 10, True, 'DS 005-2012-TR'),
 lp('sst_conservacion_enfermedades_anios', 'Conservación: enfermedades ocupacionales', 'years', 20, True, 'DS 005-2012-TR'),
 lp('sst_conservacion_otros_anios', 'Conservación: demás registros de SST', 'years', 5, True, 'DS 005-2012-TR'),
 lp('sst_comite_minimo_trabajadores', 'Trabajadores desde los que se exige comité de SST (20 o más)', 'count', 20, True, 'Ley 29783 art. 29', 'Comparación mayor o igual; con menos, supervisor. Fuente secundaria.'),
 lp('hostigamiento_comite_minimo_trabajadores', 'Trabajadores por centro de trabajo desde los que se exige comité de intervención (20 o más)', 'count', 20, True, 'DS 014-2019-MIMP art. 27.1', 'Con menos, delegado.'),
 lp('host_atencion_dias_habiles', 'Hostigamiento: plazo para ofrecer atención médica y psicológica', 'business_days', 1, True, 'DS 014-2019-MIMP art. 17 y 29.1', 'Desde la recepción de la queja.', vfrom='2019-07-23'),
 lp('host_medidas_proteccion_dias_habiles', 'Hostigamiento: plazo para dictar y ejecutar medidas de protección', 'business_days', 3, True, 'DS 014-2019-MIMP art. 18 y 29.2', 'Desde la recepción de la queja.', vfrom='2019-07-23'),
 lp('host_traslado_comite_dias_habiles', 'Hostigamiento: plazo para trasladar la queja al comité', 'business_days', 1, True, 'DS 014-2019-MIMP art. 29.4', 'Desde la recepción de la queja.', vfrom='2019-07-23'),
 lp('host_comunicacion_mtpe_queja_dias_habiles', 'Hostigamiento: plazo para comunicar al MTPE la queja y las medidas', 'business_days', 6, True, 'DS 014-2019-MIMP art. 29.3', 'Desde la recepción de la queja.', vfrom='2019-07-23'),
 lp('host_informe_comite_dias_calendario', 'Hostigamiento: plazo del informe del comité', 'days', 15, True, 'DS 014-2019-MIMP art. 29.5', 'Días calendario desde la recepción de la queja.', vfrom='2019-07-23'),
 lp('host_decision_dias_calendario', 'Hostigamiento: plazo de la decisión de Recursos Humanos', 'days', 10, True, 'DS 014-2019-MIMP art. 29.6', 'Días calendario desde la recepción del informe.', vfrom='2019-07-23'),
 lp('host_comunicacion_mtpe_decision_dias_habiles', 'Hostigamiento: plazo para comunicar la decisión al MTPE', 'business_days', 6, True, 'DS 014-2019-MIMP art. 29.6', 'Desde la emisión de la decisión.', vfrom='2019-07-23'),
 lp('host_descargos_dias', 'Hostigamiento: plazo interno de descargos', 'days', None, False, 'DS 014-2019-MIMP art. 29.5', 'Lo fija el comité dentro de sus 15 días; por confirmar.'),
 lp('host_alegatos_dias', 'Hostigamiento: plazo interno de alegatos', 'days', None, False, 'DS 014-2019-MIMP art. 29.6', 'Dentro de los 10 días de la decisión; por confirmar.'),
 lp('vida_ley_registro_poliza_dias', 'Plazo para registrar la póliza de vida', 'days', 30, True, 'DS 009-2020-TR'),
 # AUS-1 · Duración de licencias de ley que proponen los tipos de ausencia (RN-AUS-04). Sin valor hasta validarlas.
 lp('licencia_maternidad_dias', 'Licencia por maternidad (días naturales, prenatal y postnatal)', 'days', None, False, 'Ley 26644', 'Por confirmar la duración vigente y las extensiones (parto múltiple, discapacidad).'),
 lp('licencia_paternidad_dias', 'Licencia por paternidad (días naturales consecutivos)', 'days', None, False, 'Ley 29409 y Ley 30807', 'Por confirmar la duración vigente y los supuestos con más días.'),
 lp('licencia_fallecimiento_familiar_dias', 'Licencia por fallecimiento de un familiar directo (días)', 'days', None, False, 'Por confirmar', 'Por confirmar si existe licencia general en el régimen privado o solo por convenio.'),
 # AUS-4 · Ampliaciones de las licencias de ley por causal (RN-AUS-04). Sin valor hasta validarlas.
 lp('licencia_maternidad_dias_adicionales_parto_multiple', 'Licencia por maternidad: días adicionales por parto múltiple', 'days', None, False, 'Ley 26644 modificada por Ley 27606', 'Por confirmar los días que se suman al descanso postnatal.'),
 lp('licencia_maternidad_dias_adicionales_discapacidad', 'Licencia por maternidad: días adicionales por nacimiento de un hijo con discapacidad', 'days', None, False, 'Ley 26644 modificada por Ley 30367', 'Por confirmar los días que se suman al descanso postnatal.'),
 lp('licencia_paternidad_dias_prematuro_multiple', 'Licencia por paternidad: duración por parto prematuro o múltiple', 'days', None, False, 'Ley 29409 modificada por Ley 30807', 'Duración total (no se suma a la general); por confirmar.'),
 lp('licencia_paternidad_dias_complicaciones', 'Licencia por paternidad: duración por enfermedad grave, discapacidad del hijo o complicaciones de la madre', 'days', None, False, 'Ley 29409 modificada por Ley 30807', 'Duración total (no se suma a la general); por confirmar.'),
 # AUS-3 · Descansos médicos: días del año a cargo del empleador antes del subsidio (RN-AUS-03) y plazo del reembolso.
 lp('descanso_medico_dias_cargo_empleador', 'Días de incapacidad temporal del año a cargo del empleador antes del subsidio', 'days', None, False, 'Ley 26790 art. 12 · DS 009-97-SA', 'Desde el día siguiente corre el subsidio por incapacidad temporal; por confirmar el número vigente y si se cuenta por año calendario.'),
 lp('subsidio_reembolso_plazo_meses', 'Plazo para solicitar el reembolso de un subsidio a la seguridad social', 'months', None, False, 'Ley 26790 · Acuerdo de EsSalud', 'Por confirmar el plazo vigente y desde cuándo se cuenta (fin del descanso).'),
 lp('teletrabajo_respuesta_dias_habiles', 'Plazo de respuesta a solicitudes de teletrabajo (días hábiles)', 'days', 10, True, 'Ley 31572'),
 lp('teletrabajo_desconexion_horas', 'Desconexión digital mínima continua', 'hours', 12, True, 'Ley 31572'),
 lp('privacidad_notificacion_incidente_horas', 'Plazo para notificar un incidente de datos personales', 'hours', 48, True, 'DS 016-2024-JUS', vfrom='2025-03-30', vfrom_ok=True),
 lp('discapacidad_cuota_pct', 'Cuota de empleo de personas con discapacidad', 'percent', None, False, 'Ley 29973', 'No confirmado en el texto.'),
 lp('discapacidad_cuota_minimo_trabajadores', 'Trabajadores desde los que aplica la cuota', 'count', None, False, 'Ley 29973', 'No confirmado en el texto.'),
 lp('lactario_minimo_mujeres_edad_fertil', 'Mujeres en edad fértil por centro de trabajo que obligan a lactario', 'count', None, False, 'Ley 29896', 'Umbral no verificado.'),
 # ---- T-Registro y AFPnet (docs/18 §1.3 y §3.5)
 lp('treg_alta_trabajador_dias', 'T-Registro: alta del trabajador (0 = dentro del día del ingreso)', 'days', 0, True, 'DS 018-2007-TR art. 4-A; SUNAT, Obligaciones del empleador'),
 lp('treg_modificacion_dias_habiles', 'T-Registro: modificación o actualización de datos', 'business_days', 5, True, 'DS 018-2007-TR art. 4-A; SUNAT'),
 lp('treg_baja_dias', 'T-Registro: baja', 'days', None, False, 'DS 018-2007-TR art. 4-A', 'SUNAT: al día siguiente; otras fuentes: primer día hábil siguiente. Tipo de día por confirmar; alertar con día calendario.'),
 lp('treg_constancia_alta_dias_habiles', 'T-Registro: entrega de la constancia de alta', 'business_days', 1, True, 'SUNAT, Obligaciones del empleador'),
 lp('treg_constancia_modificacion_dias', 'T-Registro: entrega de la constancia de modificación', 'days', 15, True, 'SUNAT, Obligaciones del empleador'),
 lp('treg_constancia_baja_dias', 'T-Registro: entrega de la constancia de baja (a solicitud)', 'days', 2, True, 'SUNAT, Obligaciones del empleador'),
 lp('treg_alta_derechohabiente_dias_habiles', 'T-Registro: alta de derechohabiente desde la comunicación', 'business_days', 1, True, 'SUNAT, cartilla de derechohabientes (2012)', 'Confirmar vigencia.'),
 lp('treg_modificacion_derechohabiente_dias_habiles', 'T-Registro: modificación de derechohabiente', 'business_days', 5, True, 'SUNAT, cartilla de derechohabientes (2012)', 'Confirmar vigencia.'),
 lp('afpnet_plazo_dias_habiles', 'AFPnet: declaración y pago, días hábiles del mes siguiente', 'business_days', 5, True, 'TUO del SPP; Profuturo AFP', 'Fuente secundaria.'),
 lp('planilla_semanal_criterio_mes', 'Criterio para asignar una semana de pago al mes tributario', 'text', None, False, 'R.S. 183-2011/SUNAT', 'fecha_pago, fin_periodo o dividir; por confirmar.'),
 # ---- MYPE (docs/18 §4.2)
 lp('mype_permanencia_anios', 'Permanencia en el régimen tras superar el tope de ventas', 'years', 1, True, 'DS 013-2013-PRODUCE art. 42; guía MTPE', regime='pe_micro'),
 lp('mype_permanencia_anios', 'Permanencia en el régimen tras superar el tope de ventas', 'years', 3, True, 'DS 013-2013-PRODUCE art. 42; guía MTPE', regime='pe_pequena'),
 lp('mype_anios_consecutivos_exceso', 'Años consecutivos de exceso de ventas que inician la permanencia', 'years', 2, True, 'DS 013-2013-PRODUCE art. 42; guía MTPE'),
 lp('mype_permite_retorno', 'Se permite volver a la categoría anterior (0 = no)', 'count', 0, True, 'Guía MTPE', 'La Ley 32353 lo permitiría cuando se reglamente.'),
 lp('mype_recontratacion_meses', 'Meses sin recontratación para aplicar el régimen especial a un extrabajador', 'months', None, False, 'Ley 32353', 'Por confirmar con el TUO vigente.'),
 lp('remype_acreditacion_dias_habiles', 'REMYPE: plazo de emisión de la acreditación', 'business_days', 7, True, 'Guía MTPE'),
 lp('mype_sis_aporte', 'Aporte al SIS de la microempresa', 'amount', None, False, 'DS 013-2013-PRODUCE', 'Lo fija el SIS; por confirmar.', regime='pe_micro'),
 lp('cts_tope_dias', 'Tope de CTS en remuneraciones diarias', 'days', 90, True, 'DS 013-2013-PRODUCE; guía MTPE', regime='pe_pequena'),
 lp('indemnizacion_dias_por_anio', 'Indemnización por despido: remuneraciones diarias por año', 'days', 10, True, 'DS 013-2013-PRODUCE; guía MTPE', regime='pe_micro'),
 lp('indemnizacion_tope_dias', 'Indemnización por despido: tope en remuneraciones diarias', 'days', 90, True, 'DS 013-2013-PRODUCE; guía MTPE', regime='pe_micro'),
 lp('indemnizacion_dias_por_anio', 'Indemnización por despido: remuneraciones diarias por año', 'days', 20, True, 'DS 013-2013-PRODUCE; guía MTPE', regime='pe_pequena'),
 lp('indemnizacion_tope_dias', 'Indemnización por despido: tope en remuneraciones diarias', 'days', 120, True, 'DS 013-2013-PRODUCE; guía MTPE', regime='pe_pequena'),
 # ---- Indemnizaciones y quinta del cese (v1.13, calculadora de referencia)
 lp('indemnizacion_dias_por_anio', 'Indemnización por despido: remuneraciones diarias por año', 'days', 45, True, 'DS 003-97-TR art. 38', 'Valor general: una remuneración y media por año completo; las fracciones se pagan por dozavos y treintavos. El régimen lo reemplaza.'),
 lp('indemnizacion_tope_dias', 'Indemnización por despido: tope en remuneraciones diarias', 'days', 360, True, 'DS 003-97-TR art. 38', 'Valor general: doce remuneraciones.'),
 lp('indemnizacion_plazo_fijo_dias_por_mes', 'Indemnización por despido en contrato a plazo fijo: remuneraciones diarias por mes que falta', 'days', 45, True, 'DS 003-97-TR art. 76', 'Una remuneración y media por mes dejado de laborar, con el tope de indemnizacion_tope_dias. Confirmar el tratamiento en micro y pequeña empresa.'),
 lp('indemnizacion_vacacional_aplica', 'Corresponde indemnización por vacaciones no gozadas en el año siguiente', 'count', 1, True, 'DL 713 art. 23', 'Una remuneración por el descanso no gozado. No alcanza a gerentes o representantes que decidieron no gozarlo (validar).'),
 lp('quinta_devuelve_exceso', 'El empleador devuelve el exceso de retención de quinta en la regularización o el cese', 'count', None, False, 'Reglamento LIR art. 40 y 45', 'Por confirmar el procedimiento y si se devuelve en la boleta o se informa a SUNAT.'),
 # ---- Construcción civil (docs/18 §4.3): tabla 2026, RM 197-2025-TR
 lp('cc_jornal_basico', 'Jornal básico diario por categoría', 'scale', None, True, 'RM 197-2025-TR; tabla FTCCP 2026', 'Moneda PEN.', regime='pe_construccion', vfrom_ok=True, vto='2026-12-31', json_value={'operario': 89.30, 'oficial': 69.75, 'peon': 62.80}),
 lp('cc_buc_pct', 'Bonificación unificada de construcción (% del jornal básico) por categoría', 'scale', None, True, 'RM 197-2025-TR; tabla FTCCP 2026', regime='pe_construccion', vfrom_ok=True, vto='2026-12-31', json_value={'operario': 32, 'oficial': 30, 'peon': 30}),
 lp('cc_movilidad_dia', 'Movilidad por día efectivamente laborado', 'amount', 8.60, True, 'RM 197-2025-TR; tabla FTCCP 2026', regime='pe_construccion', vfrom_ok=True, vto='2026-12-31'),
 lp('cc_gratificacion_jornales', 'Jornales básicos por gratificación (Fiestas Patrias y Navidad)', 'count', 40, True, 'Convenio CAPECO-FTCCP', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_gratificacion_fracciones', 'Fracciones de la gratificación por periodo (meses)', 'scale', None, True, 'Convenio CAPECO-FTCCP', 'Sétimos enero-julio, quintos agosto-diciembre. Fuente secundaria.', regime='pe_construccion', json_value={'7': 7, '12': 5}),
 lp('cc_vacaciones_pct', 'Compensación vacacional (% de los jornales básicos)', 'percent', 10, True, 'Convenio CAPECO-FTCCP', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_compensacion_pct', 'Compensación por tiempo de servicios (% de los jornales básicos; 12 % + 3 % de utilidades)', 'percent', 15, True, 'Convenio CAPECO-FTCCP', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_asignacion_escolar_jornales_anio', 'Asignación escolar: jornales básicos por año por hijo', 'count', 30, True, 'Convenio CAPECO-FTCCP; tabla FTCCP 2026', regime='pe_construccion'),
 lp('cc_asignacion_escolar_edad_max', 'Asignación escolar: edad máxima con estudios técnicos o superiores', 'years', 24, True, 'Acta 2026 FTCCP', regime='pe_construccion', vfrom_ok=True),
 lp('cc_asignacion_escolar_modo', 'Asignación escolar: devengo diario o pago en oportunidad única', 'text', None, False, 'Convenio CAPECO-FTCCP', 'Por confirmar.', regime='pe_construccion'),
 lp('cc_altura_pct', 'Bonificación por altura (% del jornal básico por día)', 'percent', 8, True, 'Acta 2026 FTCCP', 'Antes 7 %.', regime='pe_construccion', vfrom_ok=True),
 lp('cc_contacto_agua_pct', 'Bonificación por contacto directo con agua (% del jornal básico)', 'percent', 20, True, 'Tabla 22 SUNAT, código 0310', regime='pe_construccion'),
 lp('cc_nocturno_pct', 'Bonificación por trabajo nocturno', 'percent', None, False, 'Convenio CAPECO-FTCCP', 'Discrepancia: 20 % (Tabla 22) o 25 % (fuente secundaria).', regime='pe_construccion'),
 lp('cc_altas_temperaturas_dia', 'Bonificación por altas temperaturas en infraestructura vial (por día)', 'amount', 4.50, True, 'Acta 2026 FTCCP', regime='pe_construccion', vfrom_ok=True),
 lp('cc_altitud', 'Bonificación por altitud', 'amount', None, False, 'Convenio CAPECO-FTCCP', 'No verificado.', regime='pe_construccion'),
 lp('cc_bae_pct', 'Bonificación por alta especialización por especialidad', 'scale', None, False, 'Convenio CAPECO-FTCCP', 'Las lecturas de la tabla discrepan.', regime='pe_construccion'),
 lp('cc_feriado_bae_pct', 'Asignación por trabajo en feriado (% de la BAE)', 'percent', 25, True, 'Acta 2026 FTCCP', regime='pe_construccion', vfrom_ok=True),
 lp('cc_he_tasa_1_pct', 'Sobretiempo hasta la décima hora', 'percent', 60, True, 'Convenio CAPECO-FTCCP', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_he_tasa_2_pct', 'Sobretiempo después de la décima hora', 'percent', 100, True, 'Convenio CAPECO-FTCCP', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_conafovicer_pct', 'CONAFOVICER a cargo del trabajador', 'percent', 2, True, 'RS 001-95-MTC', 'Fuente secundaria; base por confirmar.', regime='pe_construccion'),
 lp('cc_fondo_capacitacion_pct', 'Fondo de capacitación a cargo del empleador', 'percent', None, False, 'Convenio CAPECO-FTCCP', 'Una sola fuente (0,45 %).', regime='pe_construccion'),
 lp('cc_costo_obra_min_uit', 'Costo de obra desde el que aplica el régimen (en UIT)', 'multiple', 50, True, 'DLeg 727', 'Fuente secundaria.', regime='pe_construccion'),
 lp('cc_liquidacion_modo', 'Oportunidad de pago de vacaciones y compensación', 'text', None, False, 'Convenio CAPECO-FTCCP', 'semanal o al término; por confirmar.', regime='pe_construccion'),
 # ---- Agrario (docs/18 §4.4): Ley 31110
 lp('agr_gratificacion_pct', 'Gratificaciones (% de la remuneración básica)', 'percent', 16.66, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_cts_pct', 'CTS (% de la remuneración básica)', 'percent', 9.72, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_beta_pct_rmv', 'Bonificación especial por trabajo agrario (% de la RMV, no remunerativa)', 'percent', 30, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_vacaciones_dias', 'Días de vacaciones por año', 'days', 30, True, 'Ley 31110 art. 3', 'Fuente secundaria.', regime='pe_agrario', vfrom='2021-01-01'),
 lp('agr_vacaciones_truncas_pct', 'Vacaciones truncas (% de la remuneración básica)', 'percent', 8.33, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_indemnizacion_dias_por_anio', 'Indemnización por despido: remuneraciones diarias por año', 'days', 45, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_indemnizacion_tope_dias', 'Indemnización por despido: tope en remuneraciones diarias', 'days', 360, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_he_tasa_1_pct', 'Sobretiempo: dos primeras horas (mínimo)', 'percent', 25, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_he_tasa_2_pct', 'Sobretiempo: horas restantes (mínimo)', 'percent', 35, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_nocturno_pct', 'Sobretasa nocturna', 'percent', 35, True, 'Ley 31110 art. 3', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_utilidades_pct', 'Participación en utilidades', 'percent', 7.5, True, 'Ley 31110', '5 % en 2021-2023; 10 % desde 2027.', regime='pe_agrario', vfrom='2024-01-01', vfrom_ok=True, vto='2026-12-31'),
 lp('agr_utilidades_pct', 'Participación en utilidades', 'percent', 10, True, 'Ley 31110', regime='pe_agrario', vfrom='2027-01-01', vfrom_ok=True),
 lp('agr_essalud_pct', 'Aporte a EsSalud por tramo de la razón social', 'scale', None, False, 'Ley 31110 art. 9', 'Tramo A (100 o más trabajadores o ventas mayores de 1 700 UIT): 9 % desde 2025, verificado. Tramo B: 6 % hasta 2027 y 9 % desde 2028, año por confirmar.', regime='pe_agrario', vfrom='2025-01-01'),
 lp('agr_preferencia_contrataciones', 'Contrataciones que dan derecho preferencial', 'count', 2, True, 'Ley 31110 art. 4', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 lp('agr_preferencia_convocatoria_dias', 'Días previos a la temporada para convocar', 'days', 15, True, 'Ley 31110 art. 4', regime='pe_agrario', vfrom='2021-01-01', vfrom_ok=True),
 # ---- Comité de SST (docs/18 §5.1); artículos leídos en fuentes secundarias
 lp('sst_comite_miembros_min', 'Comité de SST: mínimo de miembros', 'count', 4, True, 'DS 005-2012-TR art. 43', 'Fuente secundaria.'),
 lp('sst_comite_miembros_max', 'Comité de SST: máximo de miembros', 'count', 12, True, 'DS 005-2012-TR art. 43', 'Fuente secundaria.'),
 lp('sst_mandato_min_anios', 'Mandato mínimo de los representantes de los trabajadores', 'years', 1, True, 'DS 005-2012-TR art. 62', 'Fuente secundaria.'),
 lp('sst_mandato_max_anios', 'Mandato máximo de los representantes de los trabajadores', 'years', 2, True, 'DS 005-2012-TR art. 62', 'Fuente secundaria.'),
 lp('sst_eleccion_aviso_dias', 'Aviso del proceso electoral respecto del fin del mandato', 'days', 60, True, 'RM 245-2021-TR', 'Fuente secundaria; sentido del cómputo por confirmar.'),
 lp('sst_eleccion_convocatoria_sindicato_dias', 'Plazo del sindicato mayoritario para convocar', 'days', 30, True, 'RM 245-2021-TR', 'Fuente secundaria.'),
 lp('sst_eleccion_nominacion_dias_habiles', 'Nominación de candidatos antes de la convocatoria', 'business_days', 15, True, 'DS 005-2012-TR art. 49', 'Fuente secundaria.'),
 lp('sst_eleccion_tachas_horas', 'Resolución de tachas', 'hours', 24, True, 'RM 245-2021-TR', 'Fuente secundaria.'),
 lp('sst_eleccion_personeros_dias_habiles', 'Designación de personeros tras la lista de candidatos aptos', 'business_days', 5, True, 'RM 245-2021-TR', 'Fuente secundaria.'),
 lp('sst_instalacion_dias_habiles', 'Instalación del comité desde la elección', 'business_days', 10, True, 'RM 245-2021-TR', 'Fuente secundaria.'),
 lp('sst_sin_quorum_reprogramacion_dias', 'Nueva sesión tras una sin quórum', 'days', 8, True, 'DS 005-2012-TR art. 69', 'Fuente secundaria; tipo de día por confirmar.'),
 lp('sst_vacancia_inasistencias_consecutivas', 'Inasistencias consecutivas que causan vacancia', 'count', 3, True, 'DS 005-2012-TR art. 63', 'Fuente secundaria.'),
 lp('sst_vacancia_inasistencias_alternas', 'Inasistencias alternas que causan vacancia', 'count', 4, True, 'DS 005-2012-TR art. 63', 'Fuente secundaria.'),
 lp('sst_licencia_dias', 'Licencia con goce de los representantes (días naturales por año)', 'days', 30, True, 'DS 005-2012-TR art. 73', 'Fuente secundaria.'),
 lp('sst_capacitaciones_anuales_min', 'Capacitaciones mínimas al año', 'count', None, False, 'Ley 29783 art. 35', 'Por confirmar.'),
 # ---- SUNAFIL (docs/18 §5.3)
 lp('sunafil_pas_descargos_dias_habiles', 'Procedimiento sancionador: descargos a la imputación', 'business_days', None, False, 'DS 019-2006-TR', 'Por confirmar.'),
 lp('sunafil_pas_informe_final_dias_habiles', 'Procedimiento sancionador: descargos al informe final', 'business_days', None, False, 'DS 019-2006-TR', 'Por confirmar.'),
 lp('sunafil_pas_apelacion_dias_habiles', 'Procedimiento sancionador: apelación', 'business_days', None, False, 'DS 019-2006-TR', 'Por confirmar.'),
 lp('sunafil_actuaciones_dias_habiles', 'Duración de las actuaciones inspectivas', 'business_days', None, False, 'Ley 28806', 'Por confirmar.'),
]

# COL-7 · Sistemas y administradoras de pensiones del Perú (pension_funds). Los códigos oficiales
# (T-Registro, AFPnet) quedan vacíos hasta validarlos con la fuente; las tasas viven en pension_fund_rates.
PENSION_FUNDS_PE = [
 ('pe_onp', 'Oficina de Normalización Previsional (ONP)', 'public'),
 ('pe_afp_habitat', 'AFP Habitat', 'private'), ('pe_afp_integra', 'AFP Integra', 'private'),
 ('pe_afp_prima', 'Prima AFP', 'private'), ('pe_afp_profuturo', 'Profuturo AFP', 'private'),
]

REGIMES_PE = [
 ('pe_general', 'Régimen general', 'general'), ('pe_micro', 'Microempresa', 'micro'), ('pe_pequena', 'Pequeña empresa', 'small'),
 ('pe_construccion', 'Construcción civil', 'construction'), ('pe_agrario', 'Agrario', 'agrarian'),
 ('pe_formativo', 'Modalidades formativas', 'training'),
]

BASES = ['essalud', 'pension', 'quinta', 'cts', 'gratificacion', 'vacaciones', 'utilidades']
def cp(code, name, kind, method, afecta, formula=None, rem=True, sunat=None, note='', params=None, ok=False, reg=None, proy=None, rol='accrued_and_paid'):
    return dict(codigo=code, nombre=name, tipo=kind, metodo=method, remunerativo=rem,
                afectaciones={b: (b in afecta) for b in BASES}, formula=formula,
                parametros_legales=params or [], codigo_plame=sunat, codigo_plame_verificado=bool(ok and sunat), regimenes=reg or ['pe_general', 'pe_micro', 'pe_pequena', 'pe_construccion', 'pe_agrario'], proyeccion_quinta=proy, rol_monto=rol, nota=note)
ALL = BASES
CONCEPTS_PE = [
 cp('rem_basica', 'Remuneración básica', 'earning', 'formula', ALL, 'sueldo_base * dias_trabajados / dias_periodo', sunat='0121', ok=True, proy='ordinary'),
 cp('asig_familiar', 'Asignación familiar', 'earning', 'formula', ALL, 'param(rmv) * param(asignacion_familiar_pct_rmv) / 100', sunat='0201', params=['rmv', 'asignacion_familiar_pct_rmv'], ok=True, proy='ordinary'),
 cp('he_tramo1', 'Horas extra, primer tramo', 'earning', 'formula', ALL, 'valor_hora * (1 + param(sobretiempo_tasa_tramo1_pct)/100) * horas', sunat='0105', params=['sobretiempo_tasa_tramo1_pct'], ok=True, proy='ordinary'),
 cp('he_tramo2', 'Horas extra, segundo tramo', 'earning', 'formula', ALL, 'valor_hora * (1 + param(sobretiempo_tasa_tramo2_pct)/100) * horas', sunat='0106', params=['sobretiempo_tasa_tramo2_pct'], ok=True, proy='ordinary'),
 cp('trabajo_nocturno', 'Sobretasa por trabajo nocturno', 'earning', 'formula', ALL, 'incidencia', params=['nocturnidad_sobretasa_pct']),
 cp('feriado_trabajado', 'Trabajo en feriado o día de descanso', 'earning', 'input', ALL, sunat='0107', ok=True, proy='ordinary'),
 cp('comisiones', 'Comisiones', 'earning', 'input', ALL, sunat='0103', ok=True, proy='ordinary'),
 cp('bono_productividad', 'Bonificación regular', 'earning', 'input', ALL, sunat='0902', ok=True, proy='ordinary'),
 cp('bono_extraordinario', 'Bonificación extraordinaria ocasional', 'earning', 'input', ['quinta'], rem=False, note='A título de liberalidad, no regular.', proy='extraordinary'),
 cp('movilidad', 'Movilidad supeditada a la asistencia', 'earning', 'input', [], rem=False, note='Condición de trabajo; validar tratamiento tributario según el caso.', sunat='0909', ok=True),
 cp('teletrabajo_gastos', 'Compensación de gastos de teletrabajo', 'earning', 'input', [], rem=False, note='Concepto no remunerativo (Ley 31572).'),
 cp('rem_vacacional', 'Remuneración vacacional', 'earning', 'formula', ['essalud', 'pension', 'quinta', 'cts', 'gratificacion', 'utilidades'], 'remuneracion_computable * dias_vacaciones / 30', sunat='0118', ok=True, proy='ordinary'),
 cp('vacaciones_compra', 'Compensación por venta de vacaciones', 'earning', 'formula', ['essalud', 'pension', 'quinta'], 'remuneracion_computable * dias_vendidos / 30'),
 cp('subsidio_incapacidad', 'Subsidio por incapacidad temporal', 'earning', 'input', ['pension', 'quinta'], rem=False, note='A cargo de la seguridad social; el empleador adelanta y recupera.', sunat='0916', ok=True, proy='ordinary'),
 cp('subsidio_maternidad', 'Subsidio por maternidad', 'earning', 'input', ['pension', 'quinta'], rem=False, sunat='0915', ok=True, proy='ordinary'),
 cp('gratificacion', 'Gratificación legal', 'earning', 'formula', ['quinta'], 'remuneracion_computable * meses_completos / 6 * factor_regimen', sunat='0406', params=['gratificacion_factor', 'gratificacion_meses'], note='Inafecta a aportes por Ley 30334.', ok=True, proy='gratification'),
 cp('bonif_extraordinaria', 'Bonificación extraordinaria de la gratificación', 'earning', 'formula', ['quinta'], 'gratificacion * param(gratificacion_bonificacion_extraordinaria_pct) / 100', rem=False, sunat='0312', params=['gratificacion_bonificacion_extraordinaria_pct'], ok=True, proy='gratification'),
 cp('gratificacion_trunca', 'Gratificación trunca', 'earning', 'formula', ['quinta'], 'remuneracion_computable * meses_completos / 6', sunat='0407', ok=True, proy='gratification'),
 cp('cts', 'Compensación por tiempo de servicios', 'benefit', 'formula', [], '(remuneracion_computable + gratificacion * param(cts_fraccion_gratificacion)) * meses / 12', rem=False, sunat='0904', params=['cts_fraccion_gratificacion', 'cts_meses_deposito'], ok=True),
 cp('utilidades', 'Participación en utilidades', 'earning', 'formula', ['quinta'], 'monto_por_dias + monto_por_remuneraciones', rem=False, params=['utilidades_minimo_trabajadores'], sunat='0911', ok=True, proy='extraordinary'),
 cp('vacaciones_truncas', 'Vacaciones truncas', 'earning', 'formula', ['essalud', 'pension', 'quinta'], 'remuneracion_computable * meses / 12', sunat='0114', ok=True, proy='extraordinary'),
 cp('indemnizacion_vacacional', 'Indemnización por vacaciones no gozadas', 'earning', 'formula', [], 'remuneracion_computable', rem=False, sunat='0504', ok=True),
 cp('indemnizacion_despido', 'Indemnización por despido', 'earning', 'formula', [], 'segun_regimen', rem=False, sunat='0501', ok=True),
 cp('afp_aporte', 'Aporte obligatorio AFP', 'employee_contribution', 'formula', [], 'base_pension * param(afp_aporte_obligatorio_pct) / 100', sunat='0608', params=['afp_aporte_obligatorio_pct'], ok=True),
 cp('afp_comision', 'Comisión AFP', 'employee_contribution', 'formula', [], 'base_pension * tasa_afp(comision)', sunat='0601', params=['afp_comision_flujo_pct', 'afp_comision_mixta_pct'], ok=True),
 cp('afp_prima', 'Prima de seguro AFP', 'employee_contribution', 'formula', [], 'min(base_pension, param(afp_remuneracion_maxima_asegurable)) * tasa_afp(prima)', sunat='0606', params=['afp_prima_seguro_pct', 'afp_remuneracion_maxima_asegurable'], ok=True),
 cp('onp_aporte', 'Aporte ONP', 'employee_contribution', 'formula', [], 'base_pension * param(onp_aporte_pct) / 100', sunat='0607', params=['onp_aporte_pct'], ok=True),
 cp('renta_quinta', 'Retención de renta de quinta categoría', 'tax', 'formula', [], 'retencion_quinta(proyeccion_anual, param(uit), param(quinta_deduccion_uit), param(quinta_escala))', sunat='0605', params=['uit', 'quinta_deduccion_uit', 'quinta_escala'], ok=True),
 cp('essalud', 'Aporte EsSalud', 'employer_contribution', 'formula', [], 'max(base_essalud, param(rmv)) * param(essalud_aporte_pct) / 100', sunat='0804', params=['essalud_aporte_pct', 'rmv']),
 cp('sctr', 'Seguro complementario de trabajo de riesgo', 'employer_contribution', 'formula', [], 'base * tasa_poliza'),
 cp('vida_ley', 'Seguro de vida de ley', 'employer_contribution', 'formula', [], 'base * tasa_poliza'),
 cp('dscto_prestamo', 'Descuento de préstamo', 'deduction', 'input', []),
 cp('dscto_adelanto', 'Descuento de adelanto', 'deduction', 'input', [], sunat='0701', ok=True),
 cp('dscto_judicial', 'Retención judicial', 'deduction', 'formula', [], 'segun_mandato', sunat='0703', ok=True),
 cp('cuota_sindical', 'Cuota sindical', 'deduction', 'formula', [], 'segun_sindicato', sunat='0702', ok=True),
 cp('tardanzas', 'Descuento por tardanzas', 'deduction', 'formula', [], 'valor_minuto * minutos_tardanza', sunat='0704', ok=True),
 cp('faltas', 'Descuento por faltas injustificadas', 'deduction', 'formula', [], 'valor_dia * dias_falta', sunat='0705', ok=True),
 cp('eps_copago', 'Aporte del trabajador al plan de salud', 'deduction', 'input', []),
 cp('prov_cts', 'Provisión de CTS', 'provision', 'formula', [], 'provision_mensual(cts)'),
 cp('prov_gratificacion', 'Provisión de gratificaciones', 'provision', 'formula', [], 'provision_mensual(gratificacion)'),
 cp('prov_vacaciones', 'Provisión de vacaciones', 'provision', 'formula', [], 'provision_mensual(vacaciones)'),
 # ---- Devolución de quinta y bonificación proporcional (docs/18 §2.7 y §3.2)
 cp('renta_quinta_devolucion', 'Devolución de retención en exceso de quinta categoría', 'earning', 'formula', [], 'exceso_quinta(libro_de_retenciones)', rem=False, sunat='0928', ok=True, note='No es ingreso: devuelve una retención. Ver docs/18 §2.7.'),
 cp('bonif_extraordinaria_trunca', 'Bonificación extraordinaria proporcional', 'earning', 'formula', ['quinta'], 'gratificacion_trunca * param(gratificacion_bonificacion_extraordinaria_pct) / 100', rem=False, sunat='0313', ok=True, proy='gratification', params=['gratificacion_bonificacion_extraordinaria_pct']),
 # ---- Construcción civil (docs/18 §4.5). Afectaciones por confirmar con la Tabla 22.
 cp('cc_jornal', 'Jornal básico (construcción civil)', 'earning', 'formula', ALL, 'suma_dias(param(cc_jornal_basico)[categoria])', sunat='0121', ok=True, reg=['pe_construccion'], proy='ordinary', params=['cc_jornal_basico']),
 cp('cc_dominical', 'Dominical (construcción civil)', 'earning', 'formula', ALL, 'suma_dias(param(cc_jornal_basico)[categoria]) / 6', sunat='0115', ok=True, reg=['pe_construccion'], proy='ordinary', params=['cc_jornal_basico']),
 cp('cc_buc', 'Bonificación unificada de construcción', 'earning', 'formula', [], 'cc_jornal * param(cc_buc_pct)[categoria] / 100', sunat='0311', ok=True, reg=['pe_construccion'], params=['cc_buc_pct'], note='Afectaciones por confirmar.'),
 cp('cc_movilidad', 'Movilidad por día laborado (construcción civil)', 'earning', 'formula', [], 'dias * param(cc_movilidad_dia)', rem=False, reg=['pe_construccion'], params=['cc_movilidad_dia']),
 cp('cc_escolar', 'Asignación escolar (construcción civil)', 'earning', 'formula', [], 'hijos_con_sustento * cc_jornal * param(cc_asignacion_escolar_jornales_anio) / 360', sunat='0211', ok=True, reg=['pe_construccion'], params=['cc_asignacion_escolar_jornales_anio', 'cc_asignacion_escolar_edad_max'], note='Afectaciones por confirmar.'),
 cp('cc_altura', 'Bonificación por altura', 'earning', 'formula', [], 'jornal_dias_en_altura * param(cc_altura_pct) / 100', sunat='0303', ok=True, reg=['pe_construccion'], params=['cc_altura_pct'], note='Afectaciones por confirmar.'),
 cp('cc_agua', 'Bonificación por contacto directo con agua', 'earning', 'formula', [], 'jornal_dias_con_agua * param(cc_contacto_agua_pct) / 100', sunat='0310', ok=True, reg=['pe_construccion'], params=['cc_contacto_agua_pct'], note='Afectaciones por confirmar.'),
 cp('cc_nocturno', 'Bonificación por trabajo nocturno (construcción civil)', 'earning', 'formula', [], 'jornal_dias_nocturnos * param(cc_nocturno_pct) / 100', sunat='0309', ok=True, reg=['pe_construccion'], params=['cc_nocturno_pct'], note='Porcentaje por confirmar.'),
 cp('cc_altas_temperaturas', 'Bonificación por altas temperaturas', 'earning', 'formula', [], 'dias_asfalto * param(cc_altas_temperaturas_dia)', reg=['pe_construccion'], params=['cc_altas_temperaturas_dia']),
 cp('cc_bae', 'Bonificación por alta especialización', 'earning', 'formula', [], 'cc_jornal * param(cc_bae_pct)[especialidad] / 100', reg=['pe_construccion'], params=['cc_bae_pct']),
 cp('cc_gratificacion', 'Gratificación de construcción civil', 'earning', 'formula', ['quinta'], 'param(cc_gratificacion_jornales) * param(cc_jornal_basico)[categoria] * meses / fracciones', sunat='0406', ok=True, reg=['pe_construccion'], proy='gratification', params=['cc_gratificacion_jornales', 'cc_gratificacion_fracciones', 'cc_jornal_basico']),
 cp('cc_comp_vacacional', 'Compensación vacacional (construcción civil)', 'earning', 'formula', [], 'cc_jornal * param(cc_vacaciones_pct) / 100', reg=['pe_construccion'], params=['cc_vacaciones_pct', 'cc_liquidacion_modo']),
 cp('cc_comp_tiempo_servicios', 'Compensación por tiempo de servicios (construcción civil)', 'benefit', 'formula', [], 'cc_jornal * param(cc_compensacion_pct) / 100', rem=False, sunat='0904', ok=True, reg=['pe_construccion'], params=['cc_compensacion_pct', 'cc_liquidacion_modo']),
 cp('cc_conafovicer', 'CONAFOVICER', 'employee_contribution', 'formula', [], 'base_conafovicer * param(cc_conafovicer_pct) / 100', sunat='0602', ok=True, reg=['pe_construccion'], params=['cc_conafovicer_pct']),
 # ---- Agrario (docs/18 §4.5)
 cp('agr_rb', 'Remuneración básica agraria', 'earning', 'formula', ALL, 'rb_mensual * dias / 30', sunat='0121', ok=True, reg=['pe_agrario'], proy='ordinary'),
 cp('agr_gratif_pror', 'Gratificación prorrateada (agrario)', 'earning', 'formula', ['quinta'], 'agr_rb * param(agr_gratificacion_pct) / 100', sunat='0406', ok=True, reg=['pe_agrario'], proy='ordinary', params=['agr_gratificacion_pct'], note='Solo con opción de prorrateo.'),
 cp('agr_cts_pror', 'CTS prorrateada (agrario)', 'benefit', 'formula', [], 'agr_rb * param(agr_cts_pct) / 100', rem=False, sunat='0904', ok=True, reg=['pe_agrario'], params=['agr_cts_pct'], note='Solo con opción de prorrateo.'),
 cp('agr_beta', 'Bonificación especial por trabajo agrario (BETA)', 'earning', 'formula', [], 'param(rmv) * param(agr_beta_pct_rmv) / 100 * dias / 30', rem=False, sunat='0314', ok=True, reg=['pe_agrario'], params=['rmv', 'agr_beta_pct_rmv'], note='No remunerativa. Tratamiento en quinta por confirmar.'),
 cp('agr_essalud', 'Aporte a EsSalud (agrario)', 'employer_contribution', 'formula', [], 'base_essalud * param(agr_essalud_pct)[tramo]', reg=['pe_agrario'], params=['agr_essalud_pct']),
]

assert all(p[1] in {m[0] for m in MODULES} for p in PARAMS)
