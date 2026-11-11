"""Fuente de verdad del backlog de GestiaHR.

El contenido vive en `fuente/fase*.txt` (un archivo por fase) con este formato:

    # CÓDIGO | fase | módulo comercial | Nombre de la épica | Componente | Descripción de una línea
    @ n | H o T | componente | Título de la actividad
    D Descripción (2 a 4 frases)
    - Criterio de aceptación (de 2 a 5)
    R referencias separadas por «;»  (02 04 05 06 07 08 14 18 = documentos del kit,
      §N = punto del pedido, C-xx / E-xx, ME-nn = máquina de estados,
      «RN-XXX-01 a RN-XXX-nn» = reglas de negocio de la épica, «P Menú → Pantalla» = prototipo)
    X dependencias separadas por coma (claves de otras actividades)

Este módulo solo lee y estructura; no verifica (eso lo hace generar_backlog.py).
"""
import glob
import os
import re

AQUI = os.path.dirname(os.path.abspath(__file__))

FASES = {
    "0": ("Fase 0 · Cimientos", "fase-0-cimientos"),
    "1": ("Fase 1 · Core HR", "fase-1-core-hr"),
    "2": ("Fase 2 · Tiempo", "fase-2-tiempo"),
    "3": ("Fase 3 · Nómina Perú", "fase-3-nomina-peru"),
    "4": ("Fase 4 · Talento", "fase-4-talento"),
    "5": ("Fase 5 · Desarrollo y compensación", "fase-5-desarrollo-y-compensacion"),
    "6": ("Fase 6 · Experiencia", "fase-6-experiencia"),
    "7": ("Fase 7 · Operación y cumplimiento", "fase-7-operacion-y-cumplimiento"),
    "8": ("Fase 8 · Inteligencia", "fase-8-inteligencia"),
    "9": ("Fase 9 · Ecosistema y expansión", "fase-9-ecosistema"),
    "T": ("Transversal · Calidad", "transversal"),
}
ORDEN_FASES = list(FASES)

# Primer corte comercial (MVP): plataforma, Core HR, vacaciones y ausencias, y nómina Perú para el
# régimen general y MYPE. Se marca con la etiqueta «mvp» en el CSV, el JSON y el Markdown.
# generar_backlog.py verifica que toda dependencia de una actividad mvp también lo sea.
MVP_EPICAS = {
    "PLT", "TEN", "SEG", "SAS", "AUD", "UI", "WFL", "NOT", "CFG",
    "ORG", "POS", "COL", "HIS", "CON", "DOC", "ESS", "MSS", "IMP", "AYU",
    "VAC", "AUS",
    "NOM", "CPT", "PAR", "PER", "BOL", "BSO", "DEC", "LIQ",
}
MVP_EXCLUIR = {"NOM-11", "NOM-12"}   # actividades de épicas MVP que quedan fuera del primer corte
MVP_INCLUIR = {"QA-1", "QA-2", "QA-17", "CUM-9", "IAR-1", "IAR-2", "IAR-9", "IAR-10", "IAR-11"}   # actividades de épicas no MVP que el primer corte necesita
ETIQUETA_MVP = "mvp"

# Segunda ola: lo primero que se construye después del MVP porque diferencia frente a la competencia
# (WhatsApp básico, simulador público de costo laboral y modo estudio contable). Etiqueta «ola-2»;
# toda dependencia de una actividad ola-2 es mvp u ola-2.
OLA2_INCLUIR = {"WHA-1", "WHA-2", "WHA-3", "PRV-5", "EST-1", "EST-2", "EST-3", "EST-4", "EST-5"}
ETIQUETA_OLA2 = "ola-2"


# Sitio comercial gestiahr.com (épica WEB): pendiente y separado del MVP. Etiqueta «web»; toda dependencia de
# una actividad web es mvp o web, para que el sitio pueda salir junto con el primer corte o antes de la venta.
WEB_EPICAS = {"WEB"}
ETIQUETA_WEB = "web"


def es_web(act):
    return act["epica"] in WEB_EPICAS and not es_mvp(act)


# Producto de la web al que pertenece cada épica (docs/01 §4 épica → módulo y §4.1 módulo → producto).
# Etiqueta «producto-…» en el CSV y el JSON; las épicas sin módulo contratable van a «producto-plataforma».
def _productos():
    doc = open(os.path.join(AQUI, "..", "docs", "01-vision-y-fases.md"), encoding="utf-8").read()
    epica_modulo, modulo_producto = {}, {}
    for m in re.finditer(r"^\| [^|]+ \| `([a-z_]+)` \| ([A-Z ]+) \| \d \|", doc, re.M):
        for e in m.group(2).split():
            epica_modulo[e] = m.group(1)
    for m in re.finditer(r"^\| \*{0,2}(?:Gestia|Suite) [^|]+? \| `([a-z]+)` \| `/[a-z]+` \| ([a-z_, ]+) \|", doc, re.M):
        for mod in m.group(2).split(","):
            modulo_producto[mod.strip()] = m.group(1)
    assert len(modulo_producto) >= 20, modulo_producto
    return epica_modulo, modulo_producto


EPICA_MODULO, MODULO_PRODUCTO = _productos()


def etiqueta_producto(codigo_epica):
    mod = EPICA_MODULO.get(codigo_epica)
    return "producto-" + MODULO_PRODUCTO.get(mod, "plataforma")


def es_ola2(act):
    return act["clave"] in OLA2_INCLUIR and not es_mvp(act)


def etiqueta_corte(act):
    return ETIQUETA_MVP if es_mvp(act) else (ETIQUETA_OLA2 if es_ola2(act) else (ETIQUETA_WEB if es_web(act) else ""))


def es_mvp(act):
    return (act["epica"] in MVP_EPICAS and act["clave"] not in MVP_EXCLUIR) or act["clave"] in MVP_INCLUIR


COMPONENTES = {
    "B": "Backend", "F": "Frontend", "BF": "Backend + Frontend",
    "I": "Infraestructura", "Q": "Calidad", "D": "Datos",
}
TIPOS = {"H": "Story", "T": "Task"}
DOCS = {
    "02": "docs/02-arquitectura.md", "04": "docs/04-modelo-de-datos.md",
    "05": "docs/05-maquinas-de-estado.md", "06": "docs/06-reglas-de-negocio.md",
    "07": "docs/07-permisos-y-roles.md", "08": "docs/08-api.md",
    "10": "docs/10-pantallas-y-ux.md", "12": "docs/12-seguridad-y-privacidad.md",
    "14": "docs/14-localizacion-peru.md",
    "18": "docs/18-localizacion-peru-detalle-tecnico.md",
}


def _ref(token, epica):
    t = token.strip()
    if t in DOCS:
        return DOCS[t]
    if t.startswith("§"):
        return "Pedido " + t
    if t.startswith("P "):
        return "Prototipo: " + t[2:].strip()
    if t[:2] in ("C-", "E-") or t[:3] in ("ME-", "RN-"):
        return t
    raise ValueError(f"Referencia no reconocida en {epica}: «{t}»")


def cargar():
    """Devuelve la lista de épicas, cada una con sus actividades, en orden."""
    epicas, epica, act = [], None, None
    for ruta in sorted(glob.glob(os.path.join(AQUI, "fuente", "fase*.txt"))):
        for n, linea in enumerate(open(ruta, encoding="utf-8"), 1):
            linea = linea.rstrip("\n")
            donde = f"{os.path.basename(ruta)}:{n}"
            if not linea.strip():
                continue
            marca, resto = linea[0], linea[1:].strip()
            if marca == "#":
                p = [x.strip() for x in resto.split("|")]
                if len(p) != 6:
                    raise ValueError(f"{donde}: épica mal formada")
                epica = dict(codigo=p[0], fase=p[1], modulo="modulo-" + p[2], nombre=p[3],
                             componente=p[4], descripcion=p[5], actividades=[])
                epicas.append(epica)
                act = None
            elif marca == "@":
                p = [x.strip() for x in resto.split("|")]
                if len(p) != 4:
                    raise ValueError(f"{donde}: actividad mal formada")
                act = dict(clave=f"{epica['codigo']}-{p[0]}", numero=int(p[0]), tipo=TIPOS[p[1]],
                           componente=COMPONENTES[p[2]], titulo=p[3], descripcion="",
                           criterios=[], referencias=[f"docs/modulos/M-{epica['codigo']}.md"],
                           dependencias=[], epica=epica["codigo"], fase=epica["fase"])
                epica["actividades"].append(act)
            elif marca == "D":
                act["descripcion"] = resto
            elif marca == "-":
                act["criterios"].append(resto)
            elif marca == "R":
                act["referencias"] += [_ref(t, act["clave"]) for t in resto.split(";") if t.strip()]
            elif marca == "X":
                act["dependencias"] = [d.strip() for d in resto.split(",") if d.strip()]
            else:
                raise ValueError(f"{donde}: línea no reconocida: {linea}")
    return epicas
