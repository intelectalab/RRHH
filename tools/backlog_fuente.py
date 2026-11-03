"""Fuente de verdad del backlog de Ayni HR.

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

COMPONENTES = {
    "B": "Backend", "F": "Frontend", "BF": "Backend + Frontend",
    "I": "Infraestructura", "Q": "Calidad", "D": "Datos",
}
TIPOS = {"H": "Story", "T": "Task"}
DOCS = {
    "02": "docs/02-arquitectura.md", "04": "docs/04-modelo-de-datos.md",
    "05": "docs/05-maquinas-de-estado.md", "06": "docs/06-reglas-de-negocio.md",
    "07": "docs/07-permisos-y-roles.md", "08": "docs/08-api.md",
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
