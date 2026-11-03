#!/usr/bin/env python3
# Genera docs/00, 05, 06, 07 y docs/modulos/M-*.md desde las fuentes de esta carpeta (una sola fuente por dato).
# Uso: python3 kit/tools/funcional/gen.py   ·   docs/14-localizacion-peru.md se edita a mano.
# Lee además api/openapi.yaml (x-permission), database/schema.sql (tablas y estados) y database/catalogos/modulos.json.
import re, os, glob, sys
S = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.abspath(os.path.join(S, '..', '..'))
OUT = KIT + '/docs'
os.makedirs(OUT + '/modulos', exist_ok=True)
ROLES = [('SA', 'Superadmin SaaS'), ('AD', 'Administrador empresa'), ('RH', 'RRHH'), ('PY', 'Payroll'),
         ('RC', 'Recruiter'), ('MG', 'Manager'), ('EM', 'Empleado'), ('AU', 'Auditor'), ('ST', 'SST'),
         ('IV', 'Investigador de denuncias'), ('CT', 'Contador')]
FASES = ['0 · Cimientos', '1 · Core HR', '2 · Tiempo', '3 · Nómina Perú', '4 · Talento',
         '5 · Desarrollo y compensación', '6 · Experiencia', '7 · Operación y cumplimiento',
         '8 · Inteligencia', '9 · Ecosistema y expansión', 'Transversal']
warn = []

def rd(p):
    return open(os.path.join(S, p), encoding='utf-8').read()

# ---------- módulos ----------
mods = {}
order = []
for f in sorted(glob.glob(S + '/mod_*.txt')):
    cur = None; tag = None
    for ln in open(f, encoding='utf-8').read().split('\n'):
        if ln.startswith('@@ '):
            c, n, fa = [x.strip() for x in ln[3:].split('|')]
            cur = {'code': c, 'name': n, 'fase': int(fa) if fa.isdigit() else 10}
            mods[c] = cur; order.append(c); tag = None
        elif ln.startswith('#') and cur is not None and re.match(r'^#[a-z]+$', ln.strip()):
            tag = ln.strip()[1:]; cur[tag] = []
        elif cur is not None and tag:
            if ln.strip(): cur[tag].append(ln.rstrip())

# ---------- trazabilidad ----------
puntos = []; corr = []; amp = []
for ln in rd('traza.txt').split('\n'):
    if not ln.strip() or ln.startswith('//'): continue
    p = [x.strip() for x in ln.split('|')]
    eps = p[2].split()
    for e in eps:
        if e not in mods: warn.append(f'traza {p[0]}: épica desconocida {e}')
    row = (p[0], p[1], eps, p[3] if len(p) > 3 else '')
    (corr if p[0].startswith('C-') else amp if p[0].startswith('E-') else puntos).append(row)

# ---------- máquinas ----------
machines = []
cur = None
for ln in rd('maquinas.txt').split('\n'):
    if ln.startswith('@@ '):
        n, ent, ep = [x.strip() for x in ln[3:].split('|')]
        cur = {'name': n, 'ent': ent, 'eps': ep.split(), 'st': [], 't': [], 'fin': [], 'nota': []}
        machines.append(cur); cur['id'] = 'ME-%02d' % len(machines)
    elif ln.startswith('st:'):
        for x in ln[3:].split(';'):
            k, v = x.strip().split('='); cur['st'].append((k.strip(), v.strip()))
    elif ln.startswith('ini:'): cur['ini'] = ln[4:].strip()
    elif ln.startswith('fin:'): cur['fin'] = [x.strip() for x in ln[4:].split(',') if x.strip()]
    elif ln.startswith('t:'):
        p = [x.strip() for x in ln[2:].split('|')]
        if len(p) != 7: warn.append(f'{cur["name"]}: transición con {len(p)} campos: {ln[:60]}')
        cur['t'].append(p)
    elif ln.startswith('nota:'): cur['nota'].append(ln[5:].strip())
for m in machines:
    ids = dict(m['st'])
    for e in m['eps']:
        if e not in mods: warn.append(f'máquina {m["name"]}: épica {e}')
    for t in m['t']:
        for s in t[0].split(',') + [t[2]]:
            if s.strip() not in ids: warn.append(f'{m["name"]}: estado desconocido «{s}»')
    if m.get('ini') not in ids: warn.append(f'{m["name"]}: ini')
    for s in m['fin']:
        if s not in ids: warn.append(f'{m["name"]}: fin {s}')

def slug(s):
    return re.sub(r'[^a-z0-9áéíóúñü -]', '', s.lower()).replace(' ', '-')

# ---------- esquema ----------
SQL = open(KIT + '/database/schema.sql', encoding='utf-8').read()
TABLES = set(re.findall(r'CREATE TABLE ([a-z_]+)', SQL))
CM = []; TM = {}
for ln in rd('codemap.txt').split('\n'):
    p = ln.split()
    if not p: continue
    if p[0] in ('TABLE', 'EVENT'): TM[p[1]] = p[2]
    else: CM.append((p[0], p[1]))
def sqlstatus(t):
    m_ = re.search(r'CREATE TABLE ' + t + r'\s*\((.*?)\n\);', SQL, re.S)
    if not m_: return None
    st = re.findall(r"\n\s*(\w*status\w*)[^\n]*?IN\s*\(([^)]*)\)", m_.group(1))
    return [(n, [v.strip().strip("'") for v in vals.split(',')]) for n, vals in st]
for m in machines:
    m['ent'] = TM.get(m['ent'], m['ent'])
    for t in m['t']: t[6] = TM.get(t[6], t[6])
    if m['ent'] not in TABLES: warn.append(f'máquina {m["name"]}: tabla {m["ent"]} no existe en schema.sql')
def fix(text):
    for a_, b_ in sorted(CM, key=lambda x: -len(x[0])):
        text = text.replace(a_, b_)
    for a_, b_ in TM.items():
        text = text.replace('`' + a_ + '`', '`' + b_ + '`')
    return text

# ---------- 05 ----------
o = [rd('05_intro.md').rstrip(), '', '## Índice', '', '| Código | Entidad | Tabla / entidad | Épica |', '|---|---|---|---|']
for m in machines:
    o.append(f'| {m["id"]} | {m["name"]} | `{m["ent"]}` | {", ".join(m["eps"])} |')
for m in machines:
    ids = dict(m['st'])
    o += ['', f'## {m["id"]} · {m["name"]}', '', f'Entidad: `{m["ent"]}` · Épica: {", ".join(m["eps"])} · Estados: ' + ', '.join(f'**{v}**' for k, v in m['st']) + '.', '', '```mermaid', 'stateDiagram-v2']
    for k, v in m['st']: o.append(f'    state "{v}" as {k}')
    o.append(f'    [*] --> {m["ini"]}')
    seen = set()
    for t in m['t']:
        for s in t[0].split(','):
            key = (s.strip(), t[2], t[1])
            if key in seen: continue
            seen.add(key)
            o.append(f'    {s.strip()} --> {t[2]}: {t[1]}')
    for s in m['fin']: o.append(f'    {s} --> [*]')
    o += ['```', '', '| Desde | Acción | Hacia | Quién puede | Validaciones | Efectos | Evento emitido |', '|---|---|---|---|---|---|---|']
    for t in m['t']:
        d = ', '.join(ids.get(s.strip(), s) for s in t[0].split(','))
        o.append(f'| {d} | `{t[1]}` | {ids.get(t[2], t[2])} | {t[3]} | {t[4]} | {t[5]} | {("`" + t[6] + "`") if t[6] and t[6] != "—" else "—"} |')
    for n in m['nota']: o += ['', n]
    ss = sqlstatus(m['ent'])
    if ss: o += ['', 'Valores en `schema.sql` (`' + m['ent'] + '`): ' + '; '.join(f'`{n}` ∈ {{' + ', '.join(v) + '}' for n, v in ss) + '.']
    else: o += ['', 'En `schema.sql` la tabla `' + m['ent'] + '` no declara una columna de estado con lista cerrada: el estado se deriva o debe añadirse.']
o += ['', '## Conciliación con `schema.sql`', '', 'Las máquinas de este documento son la especificación funcional; `schema.sql` se escribió en paralelo. La tabla lista ambos conjuntos para conciliarlos en la primera historia de cada épica: cuando la máquina tiene un estado que el esquema no lista, se añade el valor por migración o se documenta que es derivado (por ejemplo «Por vencer» se deriva de la fecha de fin).', '', '| Máquina | Tabla | Estados funcionales | Valores de estado en `schema.sql` |', '|---|---|---|---|']
for m in machines:
    ss = sqlstatus(m['ent'])
    o.append(f'| {m["id"]} {m["name"]} | `{m["ent"]}` | ' + ', '.join(v for k, v in m['st']) + ' | ' + ('; '.join(', '.join(v) for n, v in ss) if ss else 'sin lista cerrada') + ' |')
open(OUT + '/05-maquinas-de-estado.md', 'w', encoding='utf-8').write(fix('\n'.join(o) + '\n'))

# ---------- reglas ----------
def rules(c):
    r = []
    for i, ln in enumerate(mods[c].get('rn', []), 1):
        mm = re.match(r'^\[(P|F)\]\s*(.*)$', ln)
        if not mm: warn.append(f'{c}: regla sin marca: {ln[:50]}'); continue
        if '→' not in mm.group(2): warn.append(f'{c}: regla sin →: {ln[:50]}')
        r.append((f'RN-{c}-{i:02d}', mm.group(2), mm.group(1) == 'P'))
    return r

o = [rd('06_intro.md').rstrip()]
for fi, fa in enumerate(FASES):
    cs = [c for c in order if mods[c]['fase'] == fi]
    if not cs: continue
    o += ['', f'## Fase {fa}' if fi < 10 else f'## {fa}']
    for c in cs:
        o += ['', f'### {c} · {mods[c]["name"]}', '', '| Código | Regla (condición → resultado) | ¿Parámetro? |', '|---|---|---|']
        for code, tx, p in rules(c):
            o.append(f'| {code} | {tx} | {"Sí" if p else "No"} |')
open(OUT + '/06-reglas-de-negocio.md', 'w', encoding='utf-8').write(fix('\n'.join(o) + '\n'))

# ---------- permisos ----------
import json
ACT = dict(view='Ver', create='Crear', update='Editar', delete='Eliminar', archive='Archivar', restore='Restaurar', approve='Aprobar',
 reject='Rechazar', submit='Enviar', cancel='Cancelar', close='Cerrar', publish='Publicar', complete='Completar', retire='Retirar',
 activate='Activar', resolve='Resolver', mark_paid='Marcar como pagado', suspend='Suspender', assign='Asignar', test='Probar',
 reopen='Reabrir', reactivate='Reactivar', pause='Pausar', end='Finalizar', calculate='Calcular', withdraw='Retirar', void='Anular',
 validate='Validar', terminate='Terminar o cesar', start='Iniciar', simulate='Simular', sign='Firmar', send='Enviar', revoke='Revocar',
 retry='Reintentar', resume='Reanudar', launch='Lanzar', issue='Emitir', duplicate='Duplicar', dismiss='Descartar', decline='Rechazar (firmante o candidato)',
 anonymize='Anonimizar', acknowledge='Dar acuse de', verify='Verificar', use='Usar', unfreeze='Descongelar', sync='Sincronizar',
 start_calibration='Iniciar la calibración de', snooze='Posponer', share='Publicar resultados de', settle='Rendir', send_to_maintenance='Enviar a mantenimiento',
 send_message='Enviar mensajes en', run='Ejecutar', rotate_secret='Rotar el secreto de', reset_password='Restablecer la clave de', reset_mfa='Restablecer el MFA de',
 reset='Reiniciar', request_info='Pedir información en', report_lost='Marcar como perdidos', renew='Renovar', remind='Enviar recordatorio de',
 reevaluate='Reevaluar', rectify='Rectificar', record_statement='Registrar descargos en', recalculate='Recalcular', push='Recibir', post='Contabilizar',
 pay='Pagar', open='Abrir', notify='Notificar la imputación en', new_version='Subir nueva versión de', move_stage='Mover de etapa', move='Mover',
 merge='Fusionar', mark_submitted='Marcar como presentados', mark_notified='Marcar como notificados', map='Mapear columnas de', justify='Justificar',
 invite='Invitar', investigate='Investigar', hold='Pausar', hire='Contratar desde', hide='Ocultar', generate='Generar documentos desde', freeze='Congelar',
 export='Exportar', execute='Ejecutar', enter='Entrar a', enable='Habilitar', disconnect='Desconectar', disburse='Desembolsar', disable='Deshabilitar',
 delegate='Delegar', contain='Registrar la contención de', check_in='Registrar avance en', change_plan='Cambiar el plan de', cancel_termination='Revertir el cese de',
 attach_evidence='Adjuntar evidencia en', apply='Aplicar', answer='Responder', admit='Admitir', adjust='Ajustar', add_value='Añadir valores con vigencia a',
 add_protection_measure='Dictar medidas de protección en', accept='Aceptar', **{'return': 'Registrar la devolución de'}, results='Ver resultados agregados de',
 communicate='Comunicar el inicio de', call='Convocar', form_board='Conformar la junta electoral de', open_nominations='Abrir la inscripción de candidatos de',
 publish_candidates='Publicar los candidatos de', open_voting='Abrir la votación de', count='Escrutar', proclaim='Proclamar los resultados de',
 install='Instalar el órgano resultante de', annul='Anular', vote='Votar en', record_session='Registrar la sesión y la asistencia de',
 record_no_quorum='Registrar la falta de quórum de', draft_minutes='Redactar el acta de', sign_minutes='Firmar el acta de', finish='Dar por terminadas',
 supersede='Reemplazar', refund='Devolver al trabajador', compensate='Compensar', request_authority_refund='Solicitar a la autoridad la devolución de',
 mark_sent='Marcar como presentadas', offer_care='Ofrecer atención médica y psicológica en', refer_to_committee='Trasladar al comité',
 start_investigation='Iniciar la investigación de', issue_report='Emitir el informe de', decide='Decidir')
NOTE = {'recruiting.offers.accept': ' (lo ejerce el candidato por enlace seguro, sin rol)', 'recruiting.offers.decline': ' (lo ejerce el candidato por enlace seguro, sin rol)',
        'integrations.biometric.push': ' (credencial de dispositivo, sin rol)'}
oa = {}
for l in sorted(set(re.findall(r"x-permission: *['\"]?([a-z_.]+)", open(KIT + '/api/openapi.yaml', encoding='utf-8').read()))):
    p = l.strip().split('.')
    if len(p) == 3 and p[2]: oa.setdefault(p[0] + '.' + p[1], []).append(p[2])
catalog = []   # dict(code, desc, roles, sens, new, eps, res)
used_res = set()
for ln in rd('perms.txt').split('\n'):
    if not ln.strip() or ln.startswith('//'): continue
    p = [x.strip() for x in ln.split('|')]
    eps = [e.strip() for e in p[0].split(',')]
    for e in eps:
        if e not in mods: warn.append(f'perms: épica {e}')
    if p[1].startswith('*'):
        code = p[1][1:]
        catalog.append(dict(code=code, desc=p[2], roles=p[3].split(), sens=True, new=False, field=True, eps=eps, res='.'.join(code.split('.')[:2])))
        continue
    if len(p) != 6: warn.append('perms mal formado: ' + ln[:60]); continue
    sens = p[1].startswith('!'); res = p[1].lstrip('!'); used_res.add(res)
    label, V, W = p[2], p[3].split(), p[4].split()
    ov = {}; extra = []
    for o in [x.strip() for x in p[5].split(';') if x.strip()]:
        k, rs = o.rsplit(':', 1)
        if k.startswith('+'):
            act, d = k[1:].split('#', 1); extra.append((act, d, rs.split()))
        else: ov[k.strip()] = rs.split()
    acts = oa.get(res, [])
    for k in ov:
        if k not in acts: warn.append(f'perms {res}: excepción para acción inexistente {k}')
    for a_ in sorted(acts, key=lambda x: (x != 'view', x)):
        rs = ov[a_] if a_ in ov else (V if a_ == 'view' else W)
        rs = [x for x in rs if x != '—']
        code = f'{res}.{a_}'
        catalog.append(dict(code=code, desc=f'{ACT.get(a_, a_)} {label}' + NOTE.get(code, ''), roles=rs, sens=sens, new=False, field=False, eps=eps, res=res))
        if a_ not in ACT: warn.append('acción sin etiqueta: ' + a_)
    for act, d, rs in extra:
        catalog.append(dict(code=f'{res}.{act}', desc=d, roles=rs, sens=sens, new=True, field=False, eps=eps, res=res))
for r in oa:
    if r not in used_res: warn.append('recurso de openapi sin asignar: ' + r)
RS = dict(ROLES)
for c_ in catalog:
    for x in c_['roles']:
        if x.rstrip('°') not in RS: warn.append(f'{c_["code"]}: rol {x}')
allp = [c_['code'] for c_ in catalog]
dup = {x for x in allp if allp.count(x) > 1}
if dup: warn.append('permisos duplicados: ' + ', '.join(sorted(dup)))
oa_codes = {r + '.' + a_ for r, v in oa.items() for a_ in v}
miss = oa_codes - set(allp)
if miss: warn.append('faltan de openapi: ' + ', '.join(sorted(miss)))

def perms(c, owner_only=False):
    return [x for x in catalog if (x['eps'][0] == c if owner_only else c in x['eps'])]

def tag(x):
    return (' **(S)**' if x['sens'] else '') + (' **(+)**' if x['new'] or x['field'] else '')

mj = json.load(open(KIT + '/database/catalogos/modulos.json'))
MJ = mj['modulos']
mt = ['| Módulo comercial | Código | Épicas | Requiere | Recomienda |', '|---|---|---|---|---|']
seen_ep = set()
for m_ in mj['modulos']:
    seen_ep |= set(m_['epicas'])
    mt.append(f'| {m_["nombre"]}{" (siempre activo)" if m_.get("base") else ""} | `{m_["codigo"]}` | {", ".join(m_["epicas"])} | {", ".join(m_["requiere"]) or "—"} | {", ".join(m_["recomienda"]) or "—"} |')
for c in order:
    if c not in seen_ep and c not in ('QA', 'SAS'): warn.append('épica sin módulo comercial en modulos.json: ' + c)

o = [rd('07_intro.md').rstrip().replace('{{MODULOS}}', '\n'.join(mt)), '', '## 5. Catálogo de permisos y matriz de los 11 roles de sistema', '',
     'Leyenda de columnas: ' + ' · '.join(f'**{a}** {b}' for a, b in ROLES) + '.',
     '', '`✓` concedido con el alcance por defecto del rol (sección 3). `○` concedido solo sobre lo propio o lo asignado a la persona (sus solicitudes, sus casos, sus tareas, lo que debe firmar o evaluar). Vacío = no concedido. **(S)** = permiso de campo o recurso sensible: su uso queda en el registro de accesos. **(+)** = no figura aún en `api/openapi.yaml`; debe añadirse al contrato (permisos de campo y acciones funcionales).',
     '', f'Totales: {len(catalog)} permisos · {sum(1 for x in catalog if not x["new"] and not x["field"])} tomados de `api/openapi.yaml` · {sum(1 for x in catalog if x["field"])} de campo · {sum(1 for x in catalog if x["new"])} acciones funcionales por añadir al contrato · {sum(1 for x in catalog if x["sens"])} sensibles.']
for fi, fa in enumerate(FASES):
    cs = [c for c in order if mods[c]['fase'] == fi and perms(c, True)]
    if not cs: continue
    o += ['', f'### Fase {fa}' if fi < 10 else f'### {fa}']
    for c in cs:
        o += ['', f'#### {c} · {mods[c]["name"]}', '', '| Permiso | Qué permite | ' + ' | '.join(a for a, b in ROLES) + ' |', '|---|---|' + ':-:|' * len(ROLES)]
        for x in perms(c, True):
            cells = ['✓' if a in x['roles'] else '○' if a + '°' in x['roles'] else '' for a, b in ROLES]
            o.append(f'| `{x["code"]}`{tag(x)} | {x["desc"]} | ' + ' | '.join(cells) + ' |')
nop = [c for c in order if not perms(c, True)]
o += ['', '#### Épicas sin permisos propios', '', 'No definen recursos propios y usan los permisos de otras épicas: ' + ', '.join(nop) + '. En el portal del colaborador (ESS) todo se ejerce con alcance «Propio» (`○`) sobre los permisos de cada módulo; el armazón (UI) y la plataforma técnica (PLT) aplican los permisos del recurso que muestran; QA no tiene pantallas.']
o += ['', rd('07_cierre.md').rstrip()]
open(OUT + '/07-permisos-y-roles.md', 'w', encoding='utf-8').write(fix('\n'.join(o) + '\n'))

PROTO = [
 (r'^Barra superior → Procesos', 'Barra superior → Procesos'),
 (r'^Menú de usuario → Mi cuenta → Notificaciones', 'Menú de usuario → Mi cuenta → pestaña Notificaciones'),
 (r'^Menú de usuario → Mi cuenta', 'Menú de usuario → Mi cuenta → pestañas Datos y preferencias, Contraseña y seguridad'),
 (r'^Kiosko', 'Tiempo → Kiosko de marcación'),
 (r'^Personas → Colaboradores → Movimientos programados', 'Personas → Movimientos programados'),
 (r'^Personas → Colaboradores → Acciones masivas', 'Personas → Colaboradores → selección y acciones masivas'),
 (r'^Personas → Colaboradores → Ficha → pestaña (Vacaciones|Competencias)$', '='),
 (r'^Organización → Estructura → Reorganizar', 'Organización → Estructura → Reorganizar'),
 (r'^Seguridad → Auditoría → pestaña (Accesos|Actividad)', '='),
 (r'^Configuración → Catálogos', 'Configuración → Catálogos'),
 (r'^Configuración → Parámetros', 'Configuración → Parámetros'),
 (r'^Configuración → Suscripción', 'Configuración → Suscripción'),
 (r'^Configuración → Marca → Dominio', 'Configuración → Marca · Dominio'),
 (r'^Configuración → API → Registro', 'Configuración → API · Registro de llamadas'),
 (r'^Configuración → Integraciones → WhatsApp', 'Configuración → Integraciones · WhatsApp'),
 (r'^Operaciones → Solicitudes → WhatsApp', 'Experiencia → Solicitudes por WhatsApp'),
 (r'^Configuración → Usuarios → Acceso de terceros', 'Seguridad → Usuarios → Acceso de terceros'),
 (r'^Estudio → Cartera', 'Ingreso → Estudio contable (socio) → Cartera'),
 (r'^Inicio → Calendario', 'Calendario'),
 (r'^Configuración → Calendarios', 'Calendario (feriados y eventos)'),
 (r'^Inicio → Celebraciones', 'Calendario → filtros Cumpleaños y Aniversarios'),
 (r'^(Inicio → Alertas|Automatización → Reglas → Alertas)', 'Analytics → Alertas'),
 (r'^Inicio → Primeros pasos', 'Inicio → panel Primeros pasos'),
 (r'^Barra superior → Pendientes', 'Pendientes'),
 (r'^(Barra superior → Notificaciones|Configuración → Notificaciones)', 'Pendientes → Notificaciones (incluye la bandeja de envíos simulados)'),
 (r'^Barra superior → Asistente', 'Analytics → Asistente → pestañas Conversar y Redacción'),
 (r'^Configuración → IA', 'Analytics → Asistente → pestaña Política de IA'),
 (r'^Seguridad → Auditoría → pestaña IA', 'Analytics → Asistente → pestaña Bitácora de uso'),
 (r'^Barra superior → Buscar', 'Analytics → Asistente → Buscar en todo (y buscador de la barra superior)'),
 (r'^Automatización → Aprobaciones', 'Automatización → Aprobaciones'),
 (r'^Automatización → Workflows → Editor', 'Automatización → Workflows → Editor'),
 (r'^Automatización → Workflows', 'Automatización → Workflows'),
 (r'^Automatización → Reglas', 'Automatización → Automatizaciones → pestaña Reglas'),
 (r'^Automatización → Centro de automatizaciones', 'Automatización → Automatizaciones → pestaña Ejecuciones'),
 (r'^Automatización → Tareas', 'Tareas'),
 (r'^Analytics → Dashboard', 'Analytics → Tableros'),
 (r'^Analytics → People Analytics', 'Analytics → People Analytics → pestañas Resumen, Dotación, Tiempo, Costos, Talento y desarrollo, Clima, SST y diversidad'),
 (r'^Analytics → Reportes → Nuevo', 'Analytics → Reportes → Constructor'),
 (r'^Analytics → Reportes → Programados', 'Analytics → Reportes → pestañas Programados y Entregas (simulado)'),
 (r'^Analytics → Reportes', 'Analytics → Reportes'),
 (r'^Configuración$', 'Configuración → Centro de configuración'),
 (r'^Configuración → Empresas', 'Configuración → Centro de configuración → sección Empresas'),
 (r'^Configuración → Marca → Dominio', None),
 (r'^Configuración → Marca', 'Configuración → Centro de configuración → sección Marca de la organización'),
 (r'^Configuración → Monedas', 'Configuración → Centro de configuración → sección Monedas y tipos de cambio'),
 (r'^Configuración → Campos', 'Configuración → Centro de configuración → sección Campos personalizados (y pestaña Campos adicionales de la ficha)'),
 (r'^Configuración → Seguridad', 'Configuración → Centro de configuración → sección Seguridad'),
 (r'^Configuración → Plantillas', 'Configuración → Centro de configuración → sección Paquetes de país'),
 (r'^Configuración → (Asistencia|Vacaciones|Ausencias|Contratos)', 'Configuración → Centro de configuración → sección Configuración de cada módulo'),
 (r'^Configuración → Importar datos', 'Configuración → Importar datos'),
 (r'^Configuración → Papelera', 'Configuración → Papelera'),
 (r'^Configuración → Integraciones → WhatsApp', None),
 (r'^Configuración → Integraciones', 'Configuración → Integraciones y API → pestaña Conectores'),
 (r'^Configuración → API → Registro', None),
 (r'^Configuración → API', 'Configuración → Integraciones y API → pestaña Tokens de API (incluye webhooks)'),
 (r'^Consola SaaS → Tablero', 'Consola SaaS → pestaña Tablero'),
 (r'^Consola SaaS → Planes', 'Consola SaaS → pestaña Planes'),
 (r'^Consola SaaS → Funciones', 'Consola SaaS → pestaña Funciones'),
 (r'^Consola SaaS → Salud', 'Consola SaaS → pestaña Soporte y monitoreo'),
 (r'^Consola SaaS → Copias', 'Consola SaaS → pestaña Copias de seguridad'),
 (r'^Consola SaaS → Organizaciones → Detalle', 'Consola SaaS → pestañas Organizaciones y Suscripciones'),
 (r'^Estudio → Cartera$', 'Consola SaaS → pestaña Socios (alta y vínculo de estudios; la cartera vista por el estudio no existe)'),
 (r'^Portal → Mi tiempo$', 'grupo de menú Tiempo'),
 (r'^Portal → Mi desarrollo$', 'grupos de menú Talento, Aprendizaje y Compensación'),
 (r'^Inicio$', 'Inicio'),
 (r'^Inicio → (Calendario|Alertas|Primeros pasos|Celebraciones)', None),
 (r'^Personas → Colaboradores → Ficha → pestaña (Resumen|Información|Contrato|Compensación|Historial|Actividad|Asistencia|Planilla|Documentos|Activos|Desempeño|Capacitaciones|Beneficios|Reconocimientos)$', '='),
 (r'^Personas → Colaboradores → Ficha → pestañas de módulo', 'Personas → Colaboradores → Ficha → pestañas Asistencia, Planilla, Onboarding, Desempeño, Capacitaciones, Beneficios, Documentos, Reconocimientos y Activos (Vacaciones, Objetivos, Competencias y Encuestas no tienen pestaña en el prototipo)'),
 (r'^Personas → Colaboradores → Ficha → pestaña', None),
 (r'^Personas → Colaboradores → Ficha → Registrar (cese|movimiento)', '='),
 (r'^Personas → Colaboradores → Nuevo ingreso', 'Personas → Colaboradores → Nuevo colaborador'),
 (r'^Personas → Colaboradores → Exportar', 'Personas → Colaboradores → Exportar CSV'),
 (r'^Personas → Colaboradores$', '='),
 (r'^Personas → Colaboradores → ', None),
 (r'^Personas → Directorio', 'Personas → Directorio'),
 (r'^Personas → Organigrama → vista Posiciones', 'Personas → Headcount'),
 (r'^Personas → Organigrama', 'Personas → Organigrama'),
 (r'^Personas → Posiciones', 'Personas → Headcount'),
 (r'^Personas → Contratos', 'Operaciones → Contratos'),
 (r'^Organización → Estructura → pestaña Empresas', '='),
 (r'^Organización → Estructura → Reorganizar', None),
 (r'^Organización → Estructura', 'Organización → Estructura'),
 (r'^Seguridad → Auditoría → pestaña', None),
 (r'^Seguridad → (Usuarios|Roles y permisos|Auditoría)$', '='),
 (r'^Consola SaaS → Organizaciones', 'Consola SaaS → Organizaciones'),
 (r'^Consola SaaS → Paquetes de país', 'Nómina → Configuración de nómina → pestaña Paquetes de país'),
 (r'^Ingreso → (Elegir organización|Organización demo)', 'Ingreso (selector de organización y de usuario demo)'),
 (r'^Ingreso$', 'Ingreso'),
 (r'^Configuración → Empresas', 'Organización → Estructura → pestaña Empresas'),
 (r'^Tiempo → Asistencia', 'Tiempo → Asistencia'),
 (r'^Portal → Mi tiempo → (Marcar|Asistencia)', 'Tiempo → Asistencia'),
 (r'^(Tiempo → (Horarios|Turnos)|Portal → Mi tiempo → Horario)', 'Tiempo → Turnos y horarios'),
 (r'^(Tiempo → Vacaciones|Portal → Mi tiempo → Vacaciones)', 'Tiempo → Vacaciones'),
 (r'^(Tiempo → Ausencias|Portal → Mi tiempo → Permisos)', 'Tiempo → Ausencias y permisos'),
 (r'^(Tiempo → Horas extras|Portal → Mi tiempo → Horas extra)', 'Tiempo → Horas extra y bolsa'),
 (r'^(Tiempo → Hojas de tiempo|Portal → Mi tiempo → Hoja de tiempo)', 'Tiempo → Hojas de tiempo'),
 (r'^Configuración → (Asistencia|Vacaciones|Ausencias)', None),
 (r'^Nómina → (Periodos|Incidencias|Cálculo|Boletas)', 'Nómina → Planilla'),
 (r'^Nómina → Beneficios sociales → Gratificaciones', 'Nómina → Beneficios sociales → pestaña Gratificaciones'),
 (r'^Nómina → Beneficios sociales → CTS', 'Nómina → Beneficios sociales → pestaña CTS'),
 (r'^Nómina → Beneficios sociales → Utilidades', 'Nómina → Beneficios sociales → pestaña Utilidades'),
 (r'^Nómina → Beneficios sociales', 'Nómina → Beneficios sociales'),
 (r'^Nómina → Contabilidad → Provisiones', 'Nómina → Beneficios sociales → pestaña Provisiones'),
 (r'^Nómina → Contabilidad', 'Nómina → Declaraciones (asiento de centralización)'),
 (r'^Configuración → Planilla → Contabilización', 'Nómina → Configuración de nómina → pestaña Conceptos (cuentas por concepto)'),
 (r'^Nómina → Liquidaciones', 'Nómina → Beneficios sociales → pestaña Liquidación de beneficios'),
 (r'^Nómina → Préstamos', 'Nómina → Préstamos y adelantos'),
 (r'^Portal → Mis solicitudes → Adelanto', 'Nómina → Préstamos y adelantos'),
 (r'^Nómina → Declaraciones', 'Nómina → Declaraciones'),
 (r'^Nómina → Locadores', 'Nómina → Locadores'),
 (r'^Nómina → Conceptos', 'Nómina → Configuración de nómina → pestaña Conceptos'),
 (r'^Nómina → Parámetros', 'Nómina → Configuración de nómina → pestaña Parámetros legales'),
 (r'^Configuración → Paquete de país', 'Nómina → Configuración de nómina → pestaña Paquetes de país'),
 (r'^Configuración → Planilla', 'Nómina → Configuración de nómina'),
 (r'^Portal → Mis boletas', 'Nómina → Mis boletas'),
 (r'^Portal → Mis solicitudes → Gastos', 'Operaciones → Gastos'),
 (r'^Portal → Mis solicitudes', 'Experiencia → Mesa de ayuda'),
 (r'^Talento → Reclutamiento → Base de talento', 'Talento → Base de talento'),
 (r'^Portal público → Trabaja con nosotros', 'Talento → Portal de empleos'),
 (r'^Portal público → Oferta', 'Talento → Reclutamiento'),
 (r'^Portal público → Mi ingreso', 'Talento → Onboarding'),
 (r'^Portal público → Canal de denuncias', 'Cumplimiento → Canal de denuncias'),
 (r'^(Talento → Reclutamiento|Configuración → Reclutamiento|Portal → Mi desarrollo → Oportunidades)', 'Talento → Reclutamiento'),
 (r'^Talento → Onboarding → Salidas', 'Talento → Offboarding'),
 (r'^(Talento → Onboarding|Portal → Pendientes$)', 'Talento → Onboarding'),
 (r'^Talento → Desempeño → 1:1', 'Talento → 1:1 y feedback → pestaña Reuniones 1:1'),
 (r'^Talento → Desempeño → Feedback', 'Talento → 1:1 y feedback → pestaña Feedback'),
 (r'^(Talento → Desempeño|Portal → Pendientes → Evaluar|Configuración → Desempeño)', 'Talento → Evaluaciones'),
 (r'^(Talento → Objetivos|Portal → Mi desarrollo → Objetivos)', 'Talento → Objetivos'),
 (r'^Talento → Competencias → Perfiles', 'Talento → Competencias → pestaña Por puesto'),
 (r'^Talento → Competencias → Matriz', 'Talento → Competencias → pestaña Matriz de habilidades'),
 (r'^Talento → Competencias', 'Talento → Competencias'),
 (r'^(Talento → (Desarrollo|Sucesión)|Portal → Mi desarrollo → Plan)', 'Talento → Desarrollo y sucesión'),
 (r'^Aprendizaje → Planes', 'Aprendizaje → Plan anual'),
 (r'^(Aprendizaje|Portal → Mi desarrollo → Cursos)', 'Aprendizaje → Cursos'),
 (r'^Compensación → (Salarios|Bandas)', 'Compensación → Bandas salariales'),
 (r'^Compensación → Revisiones', 'Compensación → Revisión salarial'),
 (r'^Compensación → Igualdad salarial', 'Compensación → Igualdad salarial'),
 (r'^(Compensación → Beneficios|Portal → Mi desarrollo → Beneficios)', 'Compensación → Beneficios'),
 (r'^(Operaciones → Documentos|Portal → Mis documentos)', 'Operaciones → Documentos'),
 (r'^(Operaciones → Firma|Portal → Pendientes → Firmar)', 'Operaciones → Firma electrónica'),
 (r'^Operaciones → Activos', 'Operaciones → Activos'),
 (r'^(Operaciones → Gastos|Configuración → Gastos)', 'Operaciones → Gastos'),
 (r'^Operaciones → Solicitudes → WhatsApp', None),
 (r'^(Operaciones → Solicitudes|Configuración → Solicitudes)', 'Experiencia → Mesa de ayuda'),
 (r'^(Operaciones → Casos disciplinarios|Portal → Pendientes → Descargos)', 'Cumplimiento → Casos disciplinarios'),
 (r'^Operaciones → Sindicatos', 'Cumplimiento → Cumplimiento Perú → pestaña Sindicatos'),
 (r'^Experiencia → Encuestas → Resultados', 'Experiencia → Encuestas → resultados (Resumen, Mapa de calor, Comentarios)'),
 (r'^(Experiencia → Encuestas|Portal → Pendientes → Encuesta)', 'Experiencia → Encuestas'),
 (r'^Experiencia → eNPS', 'Experiencia → eNPS'),
 (r'^(Experiencia → Reconocimientos|Configuración → Reconocimientos)', 'Experiencia → Reconocimientos'),
 (r'^Experiencia → Comunicaciones', 'Experiencia → Comunicaciones'),
 (r'^SST → Tablero', 'SST → Tablero SST'),
 (r'^SST → Incidentes', 'SST → Accidentes e incidentes'),
 (r'^SST → Riesgos', 'SST → Matriz IPERC'),
 (r'^SST → Comité', 'SST → Comité y parámetros'),
 (r'^SST → (Registros|Inspecciones|Salud ocupacional|Capacitaciones)', 'SST → Registros obligatorios'),
 (r'^(Denuncias → |Configuración → Denuncias)', 'Cumplimiento → Casos de denuncia'),
 (r'^Cumplimiento → Expediente SUNAFIL', 'Cumplimiento → Cumplimiento Perú → pestaña Expediente de inspección'),
 (r'^Cumplimiento → ', 'Cumplimiento → Cumplimiento Perú'),
 (r'^(Configuración → Privacidad|Portal → Mi perfil → Privacidad)', 'Cumplimiento → Privacidad'),
 (r'^Portal → Mi perfil', 'Inicio → Ver mi ficha'),
 (r'^Portal → Mi equipo', 'Inicio (vista del jefe)'),
 (r'^Barra superior → Buscar', 'Barra superior → búsqueda global'),
 (r'^Barra superior → Empresa', 'Barra superior → selector de empresa'),
 (r'^\(todas las pantallas\)', 'armazón común a todas las pantallas'),
 (r'^\(documento del kit', 'no aplica (documento del kit)'),
]
def proto(p):
    for rx, to in PROTO:
        if re.search(rx, p):
            if to == '=': return 'Prototipo: ' + p
            if to is None: break
            return 'Prototipo: ' + to
    return 'Sin prototipo (pantalla nueva). Ubicación prevista: ' + p

# ---------- módulos ----------
def raw(m, k, empty='No aplica.'):
    return m.get(k) or [empty]

for c in order:
    m = mods[c]
    o = [f'# M-{c} · {m["name"]}', '', f'Fase {FASES[m["fase"]]} · Épica `{c}` · Reglas `RN-{c}-nn` · Módulo comercial: ' + next((f'{x["nombre"]} (`{x["codigo"]}`)' for x in MJ if c in x['epicas']), 'transversal'), '',
         '## 1. Objetivo', ''] + raw(m, 'obj')
    o += ['', '## 2. Alcance', ''] + raw(m, 'alc') + ['', '## 3. Fuera de alcance', ''] + raw(m, 'fue')
    o += ['', '## 4. Trazabilidad con el pedido original', '']
    ps = [p for p in puntos if c in p[2]]
    o.append('**Puntos del pedido (número de sección):** ' + ('; '.join(f'§{p[0]} {p[1]}' for p in ps) if ps else 'ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.') + ('.' if ps else ''))
    cs_ = [p for p in corr if c in p[2]]; es_ = [p for p in amp if c in p[2]]
    o += ['', '**Correcciones que incorpora:** ' + ('; '.join(f'{p[0]} {p[1]}' for p in cs_) if cs_ else 'ninguna específica (aplican las reglas transversales de SPEC.md).') + ('.' if cs_ else '')]
    o += ['', '**Ampliaciones que incorpora:** ' + ('; '.join(f'{p[0]} {p[1]}' for p in es_) if es_ else 'ninguna.') + ('.' if es_ else '')]
    o += ['', '## 5. Actores y permisos', ''] + raw(m, 'act')
    pr = perms(c)
    if pr:
        o += ['', '| Permiso | Qué permite | Roles de sistema que lo traen |', '|---|---|---|']
        for x in pr:
            full = [RS[r] for r in x['roles'] if not r.endswith('°')]; own_ = [RS[r[:-1]] for r in x['roles'] if r.endswith('°')]
            if len(own_) >= 8: own_ = ['todos los roles de organización']
            txt = ', '.join(full) + (('; ' if full else '') + 'solo sobre lo propio o asignado: ' + ', '.join(own_) if own_ else '')
            o.append(f'| `{x["code"]}`{tag(x)} | {x["desc"]} | {txt or "—"} |')
        o += ['', '**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.']
    else:
        o += ['', 'Este módulo no define permisos propios: aplica los de los recursos que muestra (`07-permisos-y-roles.md`).']
    o += ['', '## 6. Flujos principales']
    for ln in raw(m, 'flu'): o += ['', ln]
    o += ['', '## 7. Pantallas', '', 'Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).', '', '| Pantalla | Qué muestra y qué permite | Prototipo |', '|---|---|---|']
    for ln in m.get('pan', []):
        p = [x.strip() for x in ln.split('::')]
        if len(p) != 3: warn.append(f'{c}: pantalla mal formada: {ln[:50]}'); continue
        o.append(f'| {p[0]} | {p[1]} | {proto(p[2])} |')
    o += ['', '## 8. Datos principales', ''] + raw(m, 'dat')
    o += ['', '## 9. Reglas de negocio', '', f'Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección {c}.', '']
    rr = rules(c)
    if not rr: warn.append(f'{c}: sin reglas')
    for code, tx, p in rr: o.append(f'- **{code}**{" (parámetro)" if p else ""}: {tx}')
    o += ['', '## 10. Estados', '']
    ms = [x for x in machines if c in x['eps']]
    if ms:
        for x in ms: o.append(f'- {x["id"]} · {x["name"]} (`05-maquinas-de-estado.md`): ' + 'estados ' + ', '.join(v for k, v in x['st']) + '.')
    o += m.get('est', []) if (ms or m.get('est')) else ['Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.']
    o += ['', '## 11. Eventos', '', '**Emite:**', ''] + raw(m, 'eve', 'Ninguno propio.') + ['', '**Consume:**', ''] + raw(m, 'evc', 'Ninguno.')
    o += ['', '## 12. Integraciones', ''] + raw(m, 'int', 'Sin integraciones externas propias; usa los servicios del núcleo.')
    o += ['', '## 13. Casos borde', ''] + raw(m, 'bor')
    o += ['', '## 14. Criterios de aceptación generales', ''] + raw(m, 'acc')
    for k in ('obj', 'alc', 'fue', 'act', 'flu', 'pan', 'dat', 'bor', 'acc'):
        if not m.get(k): warn.append(f'{c}: falta #{k}')
    open(f'{OUT}/modulos/M-{c}.md', 'w', encoding='utf-8').write(fix('\n'.join(o) + '\n'))


# ---------- 00 ----------
def docs(eps):
    return ', '.join(f'`modulos/M-{e}.md`' for e in eps)
o = [rd('00_intro.md').rstrip(), '', '## 1. Los 124 puntos del pedido original', '', '| § | Punto del pedido | Épica(s) | Documento(s) | Cómo queda cubierto |', '|---|---|---|---|---|']
for p in puntos: o.append(f'| {p[0]} | {p[1]} | {", ".join(p[2])} | {docs(p[2])} | {p[3]} |')
nums = sorted(int(p[0]) for p in puntos)
if nums != list(range(1, 125)): warn.append('traza: faltan puntos ' + str(sorted(set(range(1, 125)) - set(nums))))
o += ['', '## 2. Correcciones C-01 a C-12', '', '| Código | Corrección | Épica(s) | Documento(s) | Dónde se concreta |', '|---|---|---|---|---|']
for p in corr: o.append(f'| {p[0]} | {p[1]} | {", ".join(p[2])} | {docs(p[2])} | {p[3]} |')
o += ['', '## 3. Ampliaciones E-01 a E-27', '', '| Código | Ampliación | Épica(s) | Documento(s) | Dónde se concreta |', '|---|---|---|---|---|']
for p in amp: o.append(f'| {p[0]} | {p[1]} | {", ".join(p[2])} | {docs(p[2])} | {p[3]} |')
o += ['', '## 4. Cobertura inversa: épicas y puntos que atienden', '', '| Fase | Épica | Módulo | Puntos del pedido | Correcciones | Ampliaciones |', '|---|---|---|---|---|---|']
for c in order:
    m = mods[c]
    o.append(f'| {FASES[m["fase"]].split(" · ")[0]} | {c} | {m["name"]} | ' + (', '.join('§' + p[0] for p in puntos if c in p[2]) or '—') + ' | ' + (', '.join(p[0] for p in corr if c in p[2]) or '—') + ' | ' + (', '.join(p[0] for p in amp if c in p[2]) or '—') + ' |')
open(OUT + '/00-trazabilidad.md', 'w', encoding='utf-8').write(fix('\n'.join(o) + '\n'))

print('módulos', len(order), 'máquinas', len(machines), 'reglas', sum(len(rules(c)) for c in order), 'permisos', len(allp))
import subprocess
left = subprocess.run('grep -rnoE "`[a-z]{3}\\.[a-z_]+\\.(ver|crear|editar|administrar|aprobar|cesar|ejecutar|registrar|solicitar)`" ' + OUT + '/0[0567]*.md ' + OUT + '/modulos | head -20', shell=True, capture_output=True, text=True).stdout
if left: print('CÓDIGOS ANTIGUOS RESTANTES:\n' + left)
print('\n'.join(warn) or 'sin avisos')
