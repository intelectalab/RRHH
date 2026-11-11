#!/usr/bin/env python3
"""Genera la entrega al equipo de desarrollo:

  kit/docs/20-entrega-al-equipo.md   estado por módulo, decisiones por épica y forma de trabajo con IA
  kit/prompts/README.md               índice de prompts por épica
  kit/prompts/<ÉPICA>.md              prompt para avanzar cada épica en una sesión nueva de Claude Code

Lee backlog/backlog.json, api/openapi.yaml, database/catalogos/modulos.json, docs/13 y docs/19.
No se edita a mano: se cambia este archivo y se vuelve a ejecutar (python3 kit/tools/gen_entrega.py).
"""
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

import yaml

KIT = Path(__file__).resolve().parent.parent
BACKLOG = json.loads((KIT / 'backlog/backlog.json').read_text(encoding='utf-8'))
OPENAPI = yaml.safe_load((KIT / 'api/openapi.yaml').read_text(encoding='utf-8'))
MODULOS = json.loads((KIT / 'database/catalogos/modulos.json').read_text(encoding='utf-8'))
# Windows no admite archivos con nombres reservados (CON, PRN, AUX, NUL, COM1…, LPT1…): esas épicas
# llevan un sufijo para que el repositorio se pueda clonar en cualquier sistema.
RESERVADOS_WINDOWS = {'CON', 'PRN', 'AUX', 'NUL', *(f'COM{i}' for i in range(1, 10)), *(f'LPT{i}' for i in range(1, 10))}


def archivo_prompt(codigo):
    return f'{codigo}-epica.md' if codigo.upper() in RESERVADOS_WINDOWS else f'{codigo}.md'


DOC13 = (KIT / 'docs/13-decisiones-abiertas.md').read_text(encoding='utf-8')
DOC19 = (KIT / 'docs/19-parametros-legales-por-validar.md').read_text(encoding='utf-8')

# Épicas a las que afecta cada decisión abierta (criterio del autor del kit; se revisa al cerrar cada una).
DECISION_EPICAS = {
    'A-05': ['COL', 'AYU'], 'A-06': ['ASI', 'QA'], 'A-07': ['ANA', 'RPT'], 'A-09': ['IAR', 'AYU'],
    'A-10': ['COL', 'COM', 'NOM'], 'A-11': ['DOC', 'BOL'], 'A-12': ['ASI', 'ESS'], 'A-13': ['PLT', 'QA'],
    'A-14': ['NOM', 'CPT'], 'D-05': ['POS', 'ATS'], 'D-06': ['NOM', 'PAR', 'BSO'], 'D-07': ['PER', 'NOM'],
    'D-09': ['DEC'], 'D-10': ['DOC', 'CON', 'BOL'], 'D-11': ['WHA', 'NOT'], 'D-12': ['DEC', 'PRV', 'INT'],
    'D-14': ['PRI', 'COL'], 'D-15': ['PAR', 'LIQ', 'SST', 'QA'], 'D-16': ['ASI'], 'D-17': ['COL', 'ONB', 'API'],
    'D-18': ['API'], 'D-19': ['API', 'SAS'], 'D-20': ['ANA', 'ALE'], 'D-21': ['API', 'IAR'],
    'D-22': ['PLT', 'QA'], 'D-23': ['ESS', 'OFF', 'SEG'], 'D-24': ['QA', 'SEG'], 'D-25': ['PRE'],
    'D-26': ['ENC', 'DEN'], 'D-27': ['PAR', 'PAI'], 'D-28': ['EST'], 'D-30': ['DEC', 'COL'],
    'D-31': ['DEN'], 'D-32': ['DEN'], 'D-33': ['SST', 'SIN'], 'D-34': ['CUM'], 'D-35': ['COL', 'DEC'],
    'D-36': ['PAR'], 'D-37': ['CUM', 'PAI'], 'D-38': ['AYU', 'IAR'], 'D-39': ['WEB'], 'D-40': ['WLB', 'WEB'],
    'D-41': ['WEB'],
}

CALC = 'kit/referencia/calculadora-planilla/'
# Lo que el kit ya trae hecho, además de la especificación, el esquema y la API que tienen todas las épicas.
AVANCE = {
    'PLT': ['`kit/referencia/arranque/`: Docker con PostgreSQL 17, Redis, S3 y correo de pruebas; `Makefile`; integración continua del kit'],
    'TEN': ['`kit/database/pruebas_aislamiento.sql`: RLS, FK compuestas y tablas inalterables, comprobado en PostgreSQL 17'],
    'AUD': ['Auditoría particionada por mes y de solo inserción en `schema.sql`, con su prueba en `pruebas_aislamiento.sql`'],
    'SEG': ['908 permisos y 11 roles en `kit/database/catalogos/permisos_y_roles.json`'],
    'SAS': ['Planes y complementos como datos iniciales (`docs/01` §4.2, ADR 0013)'],
    'UI': ['Tokens de diseño en `docs/10` y prototipo navegable en `kit/prototipo/`'],
    'WEB': ['Sitio de referencia de 47 pantallas en `kit/docs/web/sitio/`, con SEO, textos legales y blog (`kit/tools/sitio/`)'],
    'NOM': [f'`{CALC}`: pasos configurables, mes, prorrateo y regímenes agrario y de construcción civil'],
    'PAR': [f'`{CALC}`: repositorio de parámetros con vigencia; 166 parámetros de Perú en el catálogo'],
    'CPT': [f'`{CALC}`: líneas remunerativas y computables; 62 conceptos en `catalogos/conceptos_pe.json`'],
    'PER': [f'`{CALC}`: quinta proyectada, pagos extraordinarios y regularización de diciembre'],
    'BSO': [f'`{CALC}`: provisiones de gratificación, bonificación extraordinaria y CTS'],
    'LIQ': [f'`{CALC}`: liquidación con truncos, indemnizaciones y quinta del cese'],
    'PAI': ['Paquete Perú: parámetros y conceptos en `kit/database/catalogos/`'],
}

# ---- Datos -----------------------------------------------------------------------------------------

epicas, acts = [], {}
for f in BACKLOG['fases']:
    for e in f['epicas']:
        e = dict(e, fase=f['fase'], fase_nombre=f['nombre'])
        epicas.append(e)
        for a in e['actividades']:
            acts[a['clave']] = dict(a, epica=e['codigo'])

nivel = {}
def tanda(k):
    if k not in nivel:
        deps = [d for d in acts[k]['depende_de'] if d in acts]
        nivel[k] = 1 + max((tanda(d) for d in deps), default=0)
    return nivel[k]
for k in acts:
    tanda(k)

def num(k):
    return int(k.rsplit('-', 1)[1])

ops = Counter()
for p, metodos in OPENAPI['paths'].items():
    for m, o in metodos.items():
        if isinstance(o, dict) and o.get('tags'):
            ops[o['tags'][0]] += 1

modulo_de = {}
for m in MODULOS['modulos']:
    for e in m['epicas']:
        modulo_de[e] = m['nombre']

resumen = {}
for fila in re.findall(r'^\| ([AD]-\d\d) \| (.+?) \| (.+?) \|$', DOC13, re.M):
    resumen[fila[0]] = (fila[1], fila[2])
recomendacion = {}
for sec in re.split(r'\n### ', DOC13)[1:]:
    code = sec.split(' ·')[0].strip()
    m = re.search(r'\*\*Recomendación\*\*: (.+)', sec)
    if m:
        recomendacion[code] = m.group(1).strip()
abiertas = {c: v for c, v in resumen.items() if not v[1].startswith('Cerrada')}
faltan = sorted(set(abiertas) - set(DECISION_EPICAS))
assert not faltan, f'decisiones abiertas sin épicas asignadas en DECISION_EPICAS: {faltan}'
decisiones_de = defaultdict(list)
for c, eps in DECISION_EPICAS.items():
    if c in abiertas:
        for e in eps:
            assert any(x['codigo'] == e for x in epicas), f'{c}: épica {e} no existe'
            decisiones_de[e].append(c)

m19 = re.search(r'Hay \*\*(\d+) parámetros sin valor verificado\*\*; \*\*(\d+)\*\*', DOC19)
sin_valor, sin_valor_mvp = (m19.group(1), m19.group(2)) if m19 else ('?', '?')

def corte(a):
    return 'MVP' if a['mvp'] else 'ola 2' if a['ola_2'] else 'sitio' if a['web'] else '—'

def orden(e):
    return sorted(e['actividades'], key=lambda a: (tanda(a['clave']), num(a['clave'])))

# ---- Prompts por épica -------------------------------------------------------------------------------

out_prompts = KIT / 'prompts'
out_prompts.mkdir(exist_ok=True)
for viejo in out_prompts.glob('*.md'):
    viejo.unlink()

def prompt_epica(e):
    c = e['codigo']
    lista = orden(e)
    externas = sorted({d for a in lista for d in a['depende_de'] if d in acts and acts[d]['epica'] != c},
                      key=lambda k: (acts[k]['epica'], num(k)))
    dec = decisiones_de.get(c, [])
    pasos = '\n'.join(f'{i}. {a["clave"]} · {a["titulo"]}' for i, a in enumerate(lista, 1))
    dec_txt = ', '.join(dec) if dec else 'ninguna asignada'
    lineas = [
        f'# {c} · {e["nombre"]}',
        '',
        f'{e["descripcion"]}',
        '',
        f'- **Fase:** {e["fase_nombre"]}. **Módulo comercial:** {modulo_de.get(c, "—")}.',
        f'- **Actividades:** {len(lista)} ({sum(a["tipo"] == "Story" for a in lista)} historias y {sum(a["tipo"] != "Story" for a in lista)} tareas); '
        f'{sum(a["mvp"] for a in lista)} del MVP. **Operaciones de API:** {ops.get(c, 0)}.',
        f'- **Especificación:** `kit/docs/modulos/M-{c}.md`.',
    ]
    for av in AVANCE.get(c, []):
        lineas.append(f'- **Ya avanzado en el kit:** {av}.')
    lineas += ['', '## Prompt para una sesión nueva de Claude Code', '',
               'Abre Claude Code en la raíz del repositorio y pega:', '', '```',
               f'Avanzamos la épica {c} · {e["nombre"]} de GestiaHR.',
               f'Lee CLAUDE.md, kit/docs/modulos/M-{c}.md, kit/prompts/{archivo_prompt(c)} y la sección {c} de',
               f'kit/backlog/backlog.md (grep -n "^### {c}-" kit/backlog/backlog.md).',
               'Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama',
               '(feat/<clave>-<resumen>) y con su propio pull request:',
               pasos,
               'Antes de cada actividad comprueba que sus dependencias ya estén en main',
               '(git log --oneline --grep "\\[<clave>\\]"). Si falta alguna, sáltala, anótala y sigue con la',
               'siguiente que esté libre.',
               f'Decisiones abiertas que pueden tocarla: {dec_txt}. Si una actividad depende de una de ellas,',
               'aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;',
               'si la recomendación no alcanza, detente y pregunta.',
               'Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios',
               'cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.',
               '```', '', '## Actividades en orden', '',
               '| # | Clave | Actividad | Tipo | Corte | Depende de |', '|---|---|---|---|---|---|']
    for i, a in enumerate(lista, 1):
        deps = ', '.join(a['depende_de']) or '—'
        lineas.append(f'| {i} | `{a["clave"]}` | {a["titulo"]} | {"Historia" if a["tipo"] == "Story" else "Tarea"} | {corte(a)} | {deps} |')
    lineas += ['']
    if externas:
        lineas += ['## Dependencias de otras épicas', '',
                   'Deben estar integradas antes de las actividades que las piden:', '',
                   ', '.join(f'`{k}`' for k in externas), '']
    if dec:
        lineas += ['## Decisiones abiertas', '', '| Código | Decisión | Cerrar antes de | Recomendación del kit |', '|---|---|---|---|']
        for d in dec:
            rec = recomendacion.get(d, '').replace('|', '\\|')
            lineas.append(f'| {d} | {abiertas[d][0]} | {abiertas[d][1]} | {rec} |')
        lineas += ['']
    return '\n'.join(lineas)

for e in epicas:
    (out_prompts / archivo_prompt(e['codigo'])).write_text(prompt_epica(e), encoding='utf-8')

idx = ['# Prompts por épica', '',
       'Generado por `kit/tools/gen_entrega.py`. No se edita a mano.', '',
       'Cada archivo trae el prompt para avanzar una épica en una sesión nueva de Claude Code, sus actividades en el orden en que se desbloquean, '
       'las dependencias de otras épicas y las decisiones abiertas que la tocan. Para repartir el trabajo entre varias personas, sigue '
       '`kit/backlog/ruta-mvp.md`: cada tanda se puede trabajar en paralelo.', '',
       '| Fase | Épica | Actividades | MVP | Prompt |', '|---|---|---|---|---|']
for e in epicas:
    lst = e['actividades']
    idx.append(f'| {e["fase"]} | {e["codigo"]} · {e["nombre"]} | {len(lst)} | {sum(a["mvp"] for a in lst)} | [{archivo_prompt(e['codigo'])}]({archivo_prompt(e['codigo'])}) |')
(out_prompts / 'README.md').write_text('\n'.join(idx) + '\n', encoding='utf-8')

# ---- docs/20 --------------------------------------------------------------------------------------------

total = len(acts)
mvp = sum(a['mvp'] for a in acts.values())
filas = []
for e in epicas:
    c, lst = e['codigo'], e['actividades']
    av = 'Sí' if c in AVANCE else '—'
    dec = ', '.join(decisiones_de.get(c, [])) or '—'
    filas.append(f'| {e["fase"]} | [{c}](../prompts/{archivo_prompt(c)}) · {e["nombre"]} | {modulo_de.get(c, "—")} | '
                 f'{sum(a["tipo"] == "Story" for a in lst)} | {sum(a["tipo"] != "Story" for a in lst)} | '
                 f'{sum(a["mvp"] for a in lst)} | {ops.get(c, 0)} | {av} | {dec} |')

mvp_dec = sorted({d for e in epicas if any(a['mvp'] for a in e['actividades']) for d in decisiones_de.get(e['codigo'], [])},
                 key=lambda x: (x[0], int(x[2:])))
dec_mvp = '\n'.join(f'| {d} | {abiertas[d][0]} | {abiertas[d][1]} | {recomendacion.get(d, "").replace("|", chr(92) + "|")} |' for d in mvp_dec)

avance = '\n'.join(f'| {c} | {"; ".join(v)} |' for c, v in AVANCE.items())

DOC20 = f"""# 20 · Entrega al equipo de desarrollo

Generado por `kit/tools/gen_entrega.py` desde el backlog, la API, el catálogo de módulos y las decisiones. No se edita a mano.

Este documento es la puerta de entrada técnica para quienes construyen GestiaHR con asistentes de IA (Claude Code). Dice qué ya está resuelto en el kit, qué falta construir, en qué orden y cómo repartirlo. No trae tiempos, puntos ni responsables: los define el equipo al planificar.

## 1. Qué recibe el equipo

| Pieza | Dónde | Estado |
|---|---|---|
| Reglas para la IA | `CLAUDE.md` (15 reglas no negociables) y `.claude/commands/` (`/historia`, `/migracion`, `/endpoint`, `/pantalla`, `/revisar`) | Listo; se copia a la raíz del repositorio |
| Arranque del repositorio | `kit/referencia/arranque/` | Docker, `Makefile` y la integración continua del kit, comprobados con PostgreSQL 17 |
| Especificación | `kit/docs/00` a `kit/docs/19`, `kit/docs/modulos/` (una por épica) y `kit/docs/adr/` | Completa para las 72 épicas |
| Modelo de datos | `kit/database/schema.sql`, `kit/docs/04` | 495 tablas con RLS, FK compuestas y vigencias; carga en PostgreSQL 17 y pasa `pruebas_aislamiento.sql` |
| API | `kit/api/openapi.yaml`, tabla en `kit/docs/08` | OpenAPI 3.0.3 válido; cada operación con permiso y fase |
| Backlog | `kit/backlog/` y `GestiaHR_backlog_jira.csv` | {total} actividades, {mvp} del MVP, con criterios y dependencias; sin tiempos ni responsables |
| Orden de trabajo | `kit/backlog/ruta-mvp.md` | Tandas que se pueden trabajar en paralelo |
| Prompts por épica | `kit/prompts/` | Uno por épica, con las actividades en orden y sus decisiones |
| Código de referencia | `kit/referencia/calculadora-planilla/` | Núcleo de planilla en Laravel 12 con 46 pruebas |
| Diseño | `kit/prototipo/GestiaHR_prototipo.html`, `kit/docs/10`, `kit/docs/web/sitio/` | Prototipo del sistema y sitio de referencia de 47 pantallas |

**Qué no está hecho:** la aplicación. No existen todavía `apps/api`, `apps/web` ni `packages/contracts`; los crea PLT-1. El código de `kit/referencia/` muestra cómo se arma una parte, pero no reemplaza las historias: se toma como punto de partida y se lleva a la estructura de módulos de `CLAUDE.md`.

## 2. Primer día

1. Crear el repositorio y descomprimir el zip en la raíz: `CLAUDE.md`, `.claude/`, `GestiaHR_backlog_jira.csv` y `kit/`.
2. Copiar el arranque: `cp -r kit/referencia/arranque/{{docker-compose.yml,Makefile,.env.example,.gitignore,.github}} .`
3. `make up` y `make kit-check`. Si pasan, el entorno y el kit están sanos.
4. Importar el CSV en Jira (`kit/EMPEZAR-AQUI.md` §5).
5. Abrir Claude Code en la raíz y empezar por `/historia PLT-1`. Con PLT-1 integrada se abren PLT-2, PLT-3, PLT-4, PLT-12, PLT-14 y UI-1, que van en paralelo.

## 3. Cómo repartir el trabajo entre varias personas con IA

- **Una actividad, una sesión, una rama, un pull request.** Cada persona abre una sesión de Claude Code por actividad, con `/historia <clave>`, en la rama `feat/<clave>-<resumen>` (`kit/docs/03` §6).
- **Se toma de la tanda libre.** `kit/backlog/ruta-mvp.md` agrupa en tandas lo que no depende entre sí. Dos personas pueden tomar dos actividades de la misma tanda sin pisarse.
- **Por épica, con su prompt.** Quien se queda con un módulo usa `kit/prompts/<ÉPICA>.md`: trae el orden interno, las dependencias de otras épicas y las decisiones abiertas.
- **Archivos compartidos.** `kit/api/openapi.yaml`, `kit/database/schema.sql` y el backlog son generados: nadie los edita a mano. Se cambia la fuente en `kit/tools/` y se regenera en la misma rama; ante un conflicto, se resuelve en la fuente y se vuelve a generar.
- **Migraciones.** Cada migración nace de `/migracion` y respeta `schema.sql`; si una historia necesita una columna que no está, se agrega primero al modelo (`kit/tools/esquema/*.dsl`) y se regenera.
- **Revisión.** `/revisar` antes de pedir revisión humana. El pull request lista los criterios de aceptación cumplidos uno por uno. La integración continua debe estar en verde.
- **Decisiones.** Si una actividad toca una decisión abierta, se aplica la recomendación del kit detrás de un parámetro o interfaz y se avisa en el pull request. Si la recomendación no alcanza, la persona se detiene y pregunta al responsable de producto.
- **Números legales.** Ninguno en el código. Si falta un valor, se crea el parámetro sin valor y marcado «por validar»; la planilla que lo use queda con error hasta que el especialista lo confirme (`kit/docs/19`).

## 4. Definición de hecho por actividad

Una actividad se integra cuando:

1. cumple cada criterio de aceptación de su historia;
2. tiene las pruebas mínimas de `/historia`: caso feliz, 403 sin permiso, 403 con el módulo apagado, 404 desde otra organización, 422, 409 si hay estados, auditoría y outbox, y campo sensible oculto sin permiso;
3. si toca planilla, tiene su caso dorado;
4. si cambió la API, actualizó `kit/tools/gen_openapi.py`, regeneró y ejecutó `make contracts`;
5. pasa `make test lint` y la integración continua;
6. la pantalla, si la hay, sigue el prototipo y los tokens de `kit/docs/10`, funciona en el celular y no tiene botones sin función.

## 5. Estado por módulo

Todas las épicas tienen especificación, tablas, operaciones de API e historias con criterios. «Avance» indica que el kit ya trae algo construido (§6). La columna de decisiones lista las abiertas que la tocan.

| Fase | Épica | Módulo comercial | Historias | Tareas | MVP | Operaciones API | Avance | Decisiones abiertas |
|---|---|---|---|---|---|---|---|---|
{chr(10).join(filas)}

## 6. Lo que el kit ya trae construido

| Épica | Avance |
|---|---|
{avance}

## 7. Decisiones abiertas que tocan el MVP

El MVP puede empezar con todas: se aplica la recomendación detrás de un parámetro o interfaz. Cada una se cierra antes de la fase indicada y se registra como ADR en `kit/docs/adr/`.

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
{dec_mvp}

## 8. Lo que aporta el negocio

- **Parámetros legales:** {sin_valor} sin valor verificado, {sin_valor_mvp} de ellos del MVP (`kit/docs/19`). Los valida el especialista laboral y se cargan en `kit/tools/esquema/catalogos.py`.
- **Textos legales:** revisión del abogado de lo marcado [validar] y datos de la empresa marcados [por definir] (`kit/tools/sitio/legales.py`).
- **Precios definitivos**, razón social, RUC, dominio `gestiahr.com` y registro de la marca.
- **Clientes, logos y testimonios reales**, con su permiso.

## 9. Cómo se mantiene este documento

Se regenera con `python3 kit/tools/gen_entrega.py` después de cambiar el backlog, la API o las decisiones. Cuando se cierra una decisión, se actualiza `kit/docs/13` y, si cambia a qué épicas afecta, `DECISION_EPICAS` en el generador. Cuando el kit suma código de referencia, se agrega a `AVANCE`.
"""
(KIT / 'docs/20-entrega-al-equipo.md').write_text(DOC20, encoding='utf-8')
print(f'docs/20 y {len(epicas)} prompts por épica; {len(abiertas)} decisiones abiertas, {len(mvp_dec)} tocan el MVP')
