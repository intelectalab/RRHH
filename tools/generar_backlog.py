#!/usr/bin/env python3
"""Genera kit/backlog/backlog.{csv,md,json} desde la fuente y verifica su coherencia.

Uso:  python3 kit/tools/generar_backlog.py
Sale con código distinto de cero si alguna verificación falla.
"""
import csv
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from backlog_fuente import cargar, FASES, ORDEN_FASES  # noqa: E402

RAIZ = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
SALIDA = os.path.join(RAIZ, "backlog")
CABECERA = ["Issue ID", "Issue Type", "Summary", "Description", "Parent", "Epic Name", "Component",
            "Labels", "Labels", "Clave del kit"] + ["Bloqueada por"] * 5
MIN_ACT, MAX_ACT = 380, 480

# ---------- patrones de estimación ----------
PROHIBIDOS = [
    r"\bsprints?\b", r"story ?points?", r"puntos? de historia", r"\bsemanas?\b",
    r"horas?[- ]hombre", r"d[ií]as?[- ]hombre", r"personas?[- ](mes|d[ií]a|hora)s?\b", r"\bFTE\b",
    r"\bestimad[oa]s?\b", r"\bestimaci(ón|ones)\b", r"\besfuerzo\b",
    r"\b(tiempo|hora|d[ií]a|jornada|costo|presupuesto|precio|tarifa)s? de (desarrollo|implementaci[oó]n|construcci[oó]n|programaci[oó]n)\b",
    r"\b\d+\s+(desarrollador|programador|ingenier|tester|dise[ñn]ador|personas?\b)",
    r"\bequipo de \d+", r"\bperfil(es)? (requerido|del equipo|de desarrollo)",
    r"\bcantidad de (personas|desarrolladores)", r"(?-i:US\$|USD\b|(?<![A-Za-z])S/\.?|\$)\s?\d",
    r"\bfecha de entrega\b", r"\bdeadline\b", r"\bcronograma del proyecto\b",
    r"\b(durar[áa]|tomar[áa]|demorar[áa])\b", r"\b\d+\s*(pts?|SP)\b",
    r"\bcosto (del|de este|por) (proyecto|actividad|historia|tarea|épica)\b",
    r"(?<![-\d/])20[2-9]\d\b",  # años de calendario (las normas «DS 004-2006-TR» no cuentan)
]
NUM = r"(?:\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)"
CANTIDAD_TIEMPO = re.compile(rf"\b{NUM}\s+(?:horas?|d[ií]as?|mes(?:es)?|años?|minutos?|jornadas?)\b", re.I)
# Plazos legales o de negocio que forman parte de una regla funcional (clave, texto exacto).
PLAZOS_FUNCIONALES = {
    ("ONB-3", "siete días"), ("ONB-3", "90 días"), ("ONB-5", "90 días"),
    ("PRI-4", "48 horas"), ("CAR-4", "un año"), ("CAR-4", "dos años"),
    # Plazos del reglamento de hostigamiento sexual (docs/18 §5.2): son la regla funcional, no una estimación.
    ("DEN-6", "1 día"), ("DEN-6", "3 días"), ("DEN-6", "15 días"), ("DEN-6", "10 días"),
}


def textos(epicas):
    for e in epicas:
        yield e["codigo"], e["nombre"] + " — " + e["descripcion"]
        for a in e["actividades"]:
            refs = [r for r in a["referencias"] if r.startswith("Prototipo")]  # el resto son códigos y rutas
            yield a["clave"], " ".join([a["titulo"], a["descripcion"]] + a["criterios"] + refs)


def frases(texto):
    sin_codigo = re.sub(r"`[^`]*`", "x", texto)
    sin_codigo = re.sub(r"\([^)]*\)", "", sin_codigo)
    return len([f for f in re.split(r"(?<=[.?!])\s+(?=[A-ZÁÉÍÓÚÑ«¿])", sin_codigo.strip()) if f])


def catalogo_spec():
    """Códigos de épica por fase según la tabla de SPEC.md, y códigos C-xx / E-xx."""
    spec = open(os.path.join(RAIZ, "SPEC.md"), encoding="utf-8").read()
    cat = {}
    for m in re.finditer(r"^\| \*\*(.+?)\*\* \| (.+?) \|$", spec, re.M):
        fase = m.group(1).split("·")[0].strip()
        fase = "T" if fase.startswith("Transversal") else fase
        for trozo in m.group(2).split(" · "):
            cod = trozo.strip().split(" ")[0]
            if re.fullmatch(r"[A-Z]{2,3}", cod):
                cat[cod] = fase
    cs = sorted(set(re.findall(r"\*\*(C-\d\d)", spec)))
    es = sorted(set(re.findall(r"^\| (E-\d\d) \|", spec, re.M)))
    return cat, cs, es


def puntos_pedido():
    txt = open(os.path.join(RAIZ, "prompt-original.md"), encoding="utf-8").read()
    return sorted(int(n) for n in re.findall(r"^# (\d+)\. ", txt, re.M))


def pantallas_prototipo(mapa):
    """Pantallas reales según docs/17-mapa-del-prototipo.md: ruta → texto de sus pestañas."""
    p, grupo, consola = {}, None, False
    for linea in mapa.split("\n"):
        if linea.startswith("## "):
            consola = linea.startswith("## 2.")
            if not (linea.startswith("## 1.") or consola):
                grupo = consola = None
                if p:
                    break
        elif linea.startswith("### "):
            grupo = linea[4:].strip()
        elif linea.startswith("| ") and not linea.startswith("| ---") and not linea.startswith("|---"):
            c = [x.strip() for x in linea.strip("|").split("|")]
            if c[0] in ("Pantalla", "Pestaña"):
                continue
            if consola:
                p["Consola de la plataforma → " + c[0]] = ""
            elif grupo and grupo.startswith("Sin grupo"):
                p[c[0]] = c[1]
            elif grupo and " → " not in c[0] and " y " not in grupo and "," not in grupo:
                p[f"{grupo} → {c[0]}"] = c[1]
            else:
                p[c[0]] = c[1]
    p["Personas → Ficha del colaborador"] = p.pop("Ficha del colaborador", p.get("Personas → Ficha del colaborador", ""))
    return p


def verificar(epicas):
    res = []  # (nombre, ok, detalle)
    acts = [a for e in epicas for a in e["actividades"]]
    por_clave = {}
    err = []

    # 1. claves únicas y numeración correlativa
    for e in epicas:
        for i, a in enumerate(e["actividades"], 1):
            if a["clave"] in por_clave:
                err.append(f"clave repetida {a['clave']}")
            if a["numero"] != i:
                err.append(f"{a['clave']} rompe la numeración correlativa")
            por_clave[a["clave"]] = a
    cods = [e["codigo"] for e in epicas]
    err += [f"épica repetida {c}" for c in set(cods) if cods.count(c) > 1]
    res.append(("Claves únicas y correlativas", not err, "; ".join(err) or f"{len(por_clave)} claves"))

    # 2. dependencias existentes
    err = []
    for a in acts:
        if len(a["dependencias"]) > 5:
            err.append(f"{a['clave']} tiene más de 5 dependencias")
        if len(set(a["dependencias"])) != len(a["dependencias"]):
            err.append(f"{a['clave']} repite dependencias")
        for d in a["dependencias"]:
            if d not in por_clave:
                err.append(f"{a['clave']} depende de {d}, que no existe")
            if d == a["clave"]:
                err.append(f"{a['clave']} depende de sí misma")
    n_dep = sum(len(a["dependencias"]) for a in acts)
    res.append(("Toda dependencia existe (máximo 5 por actividad)", not err, "; ".join(err) or f"{n_dep} dependencias"))

    # 3. sin ciclos
    estado, ciclo = {}, []

    def visitar(k, pila):
        if estado.get(k) == 2 or ciclo:
            return
        if estado.get(k) == 1:
            ciclo.extend(pila[pila.index(k):] + [k])
            return
        estado[k] = 1
        for d in por_clave[k]["dependencias"]:
            if d in por_clave:
                visitar(d, pila + [k])
        estado[k] = 2

    sys.setrecursionlimit(10000)
    for k in por_clave:
        visitar(k, [])
    res.append(("Sin ciclos de dependencias", not ciclo, " → ".join(ciclo) or "grafo acíclico"))

    # 4. ninguna depende de una fase posterior
    err = []
    for a in acts:
        if a["fase"] == "T":
            continue
        for d in a["dependencias"]:
            if d in por_clave:
                fd = por_clave[d]["fase"]
                if fd == "T" or int(fd) > int(a["fase"]):
                    err.append(f"{a['clave']} (fase {a['fase']}) depende de {d} (fase {fd})")
    res.append(("Ninguna actividad depende de una fase posterior", not err, "; ".join(err) or "correcto"))

    # 5. catálogo de SPEC.md
    cat, cs, es = catalogo_spec()
    err = []
    mias = {e["codigo"]: e["fase"] for e in epicas}
    err += [f"falta la épica {c}" for c in cat if c not in mias]
    err += [f"épica {c} no está en SPEC.md" for c in mias if c not in cat]
    err += [f"épica {c} en fase {mias[c]} y SPEC dice {cat[c]}" for c in mias if c in cat and cat[c] != mias[c]]
    err += [f"épica {e['codigo']} sin actividades" for e in epicas if not e["actividades"]]
    res.append(("Toda épica del catálogo de SPEC.md tiene actividades", not err,
                "; ".join(err) or f"{len(cat)} épicas del catálogo"))

    # 6. cobertura del pedido, ampliaciones y correcciones
    todo = " ".join(r for a in acts for r in a["referencias"])
    pedido = puntos_pedido()
    citados = {int(n) for n in re.findall(r"Pedido §(\d+)", todo)}
    falt = [n for n in pedido if n not in citados]
    sobra = sorted(citados - set(pedido))
    res.append((f"Los {len(pedido)} puntos del pedido están referenciados", not falt and not sobra and len(pedido) == 124,
                (f"faltan §{falt}" if falt else "") + (f" inexistentes §{sobra}" if sobra else "") or "124 de 124"))
    refs = {r for a in acts for r in a["referencias"]}
    falt = [x for x in es if x not in refs]
    res.append((f"Ampliaciones E-01..E-{len(es):02d} referenciadas", not falt and len(es) == 27, f"faltan {falt}" if falt else f"{len(es)} de {len(es)}"))
    falt = [x for x in cs if x not in refs]
    res.append((f"Correcciones C-01..C-{len(cs):02d} referenciadas", not falt and len(cs) == 12, f"faltan {falt}" if falt else f"{len(cs)} de {len(cs)}"))
    desc = [r for r in refs if re.fullmatch(r"[CE]-\d\d", r) and r not in cs + es]
    res.append(("No se citan códigos C/E inexistentes", not desc, ", ".join(desc) or "correcto"))

    # 7. sin estimaciones
    err, plazos = [], []
    for clave, t in textos(epicas):
        for p in PROHIBIDOS:
            for m in re.finditer(p, t, re.I):
                err.append(f"{clave}: «{t[max(0, m.start() - 25):m.end() + 15]}»")
        for m in CANTIDAD_TIEMPO.finditer(t):
            if (clave, m.group(0)) in PLAZOS_FUNCIONALES:
                plazos.append(f"{clave} «{m.group(0)}»")
            else:
                err.append(f"{clave}: cantidad de tiempo sin clasificar «{t[max(0, m.start() - 30):m.end() + 20]}»")
    res.append(("Ningún texto contiene estimaciones", not err,
                "; ".join(err) or "sin coincidencias; plazos funcionales admitidos: " + ", ".join(sorted(set(plazos)))))

    # 8. forma de cada actividad
    err = []
    for a in acts:
        if not 2 <= len(a["criterios"]) <= 5:
            err.append(f"{a['clave']} tiene {len(a['criterios'])} criterios")
        if not 2 <= frases(a["descripcion"]) <= 4:
            err.append(f"{a['clave']} tiene {frases(a['descripcion'])} frases en la descripción")
        if len(a["titulo"]) > 90:
            err.append(f"{a['clave']} tiene un título demasiado largo")
        if len(a["referencias"]) < 2:
            err.append(f"{a['clave']} sin referencias")
    res.append(("Cada actividad tiene 2-4 frases, 2-5 criterios y referencias", not err, "; ".join(err) or "correcto"))

    # 9. documentos, reglas, máquinas y pantallas citadas existen
    lee = lambda r: open(os.path.join(RAIZ, r), encoding="utf-8").read()
    reglas = set(re.findall(r"RN-[A-Z]+-\d\d", lee("docs/06-reglas-de-negocio.md")))
    maquinas = set(re.findall(r"^## (ME-\d\d) ", lee("docs/05-maquinas-de-estado.md"), re.M))
    pantallas = pantallas_prototipo(lee("docs/17-mapa-del-prototipo.md"))
    e_doc, e_rn, e_me, e_pr = [], [], [], []
    n_doc = n_rn = n_me = n_pr = 0
    for a in acts:
        for r in a["referencias"]:
            if re.match(r"(docs|database|api)/", r):
                n_doc += 1
                if not os.path.isfile(os.path.join(RAIZ, r)):
                    e_doc.append(f"{a['clave']}: {r}")
            elif r.startswith("RN-"):
                n_rn += 1
                m = re.fullmatch(r"RN-([A-Z]+)-(\d\d) a RN-([A-Z]+)-(\d\d)", r)
                if not m or m.group(1) != m.group(3) or m.group(1) != a["epica"] or any(
                        f"RN-{m.group(1)}-{i:02d}" not in reglas for i in range(int(m.group(2)), int(m.group(4)) + 1)):
                    e_rn.append(f"{a['clave']}: {r}")
            elif r.startswith("ME-"):
                n_me += 1
                if r not in maquinas:
                    e_me.append(f"{a['clave']}: {r}")
            elif r.startswith("Prototipo: "):
                n_pr += 1
                ruta = r[len("Prototipo: "):]
                base = max((p for p in pantallas if ruta == p or ruta.startswith(p + " → ")), key=len, default=None)
                if base is None or (ruta != base and ruta[len(base) + 3:] not in pantallas[base]):
                    e_pr.append(f"{a['clave']}: {ruta}")
    res.append(("Toda ruta de documento citada existe", not e_doc, "; ".join(e_doc) or f"{n_doc} citas"))
    res.append(("Toda regla RN citada existe en docs/06", not e_rn, "; ".join(e_rn) or f"{n_rn} rangos"))
    res.append(("Toda máquina ME citada existe en docs/05", not e_me, "; ".join(e_me) or f"{n_me} citas"))
    res.append(("Toda pantalla del prototipo citada existe en docs/17", not e_pr, "; ".join(e_pr) or f"{n_pr} citas"))

    res.append((f"Total de actividades entre {MIN_ACT} y {MAX_ACT}", MIN_ACT <= len(acts) <= MAX_ACT, str(len(acts))))
    return res


# ---------- salidas ----------
def numerar(epicas):
    n = 0
    for e in epicas:
        n += 1
        e["issue_id"] = n
        for a in e["actividades"]:
            n += 1
            a["issue_id"] = n


def descripcion_jira(a):
    p = [a["descripcion"], "", "Criterios de aceptación:"]
    p += [f"- {c}" for c in a["criterios"]]
    p += ["", "Referencias: " + " · ".join(a["referencias"]),
          "", "Depende de: " + (", ".join(a["dependencias"]) or "ninguna")]
    return "\n".join(p)


def escribir_csv(epicas, ruta):
    ids = {a["clave"]: a["issue_id"] for e in epicas for a in e["actividades"]}
    with open(ruta, "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, quoting=csv.QUOTE_MINIMAL, lineterminator="\r\n")
        w.writerow(CABECERA)
        for e in epicas:
            etq = FASES[e["fase"]][1]
            w.writerow([e["issue_id"], "Epic", f"[{e['codigo']}] {e['nombre']}", e["descripcion"], "",
                        f"{e['codigo']} · {e['nombre']}", e["componente"], etq, e["modulo"], e["codigo"]] + [""] * 5)
            for a in e["actividades"]:
                bloq = [ids[d] for d in a["dependencias"]]
                w.writerow([a["issue_id"], a["tipo"], f"[{a['clave']}] {a['titulo']}", descripcion_jira(a),
                            e["issue_id"], "", a["componente"], etq, e["modulo"], a["clave"]]
                           + bloq + [""] * (5 - len(bloq)))


def verificar_csv(epicas, ruta):
    err = []
    with open(ruta, encoding="utf-8", newline="") as f:
        filas = list(csv.reader(f))
    if filas[0] != CABECERA:
        err.append("la cabecera no coincide")
    ids, epic_ids = set(), set()
    for i, fila in enumerate(filas, 1):
        if len(fila) != 15:
            err.append(f"fila {i} tiene {len(fila)} columnas")
    for fila in filas[1:]:
        if len(fila) != 15:
            continue
        if not fila[0].isdigit() or fila[0] in ids:
            err.append(f"Issue ID inválido o repetido: {fila[0]}")
        ids.add(fila[0])
        if fila[1] not in ("Epic", "Story", "Task"):
            err.append(f"tipo inválido en {fila[0]}")
        if fila[1] == "Epic":
            epic_ids.add(fila[0])
            if fila[4] or not fila[5]:
                err.append(f"épica {fila[0]} con padre o sin Epic Name")
        elif fila[5] or not fila[4]:
            err.append(f"actividad {fila[0]} sin padre o con Epic Name")
        if not fila[2].startswith(f"[{fila[9]}] "):
            err.append(f"resumen de {fila[0]} no empieza con su clave")
    for fila in filas[1:]:
        if len(fila) == 15:
            if fila[1] != "Epic" and fila[4] not in epic_ids:
                err.append(f"padre inexistente en {fila[0]}")
            err += [f"bloqueo inexistente {b} en {fila[0]}" for b in fila[10:] if b and b not in ids]
    esperadas = 1 + len(epicas) + sum(len(e["actividades"]) for e in epicas)
    if len(filas) != esperadas:
        err.append(f"{len(filas)} filas y se esperaban {esperadas}")
    return ("El CSV se relee con csv.reader y cada fila tiene 15 columnas", not err,
            "; ".join(err[:20]) or f"{len(filas)} filas (1 de cabecera)")


def conteos(epicas):
    acts = [a for e in epicas for a in e["actividades"]]
    c = dict(epicas=len(epicas), historias=sum(a["tipo"] == "Story" for a in acts),
             tareas=sum(a["tipo"] == "Task" for a in acts), actividades=len(acts), por_fase={})
    for f in ORDEN_FASES:
        ef = [e for e in epicas if e["fase"] == f]
        af = [a for e in ef for a in e["actividades"]]
        c["por_fase"][FASES[f][0]] = dict(epicas=len(ef), historias=sum(a["tipo"] == "Story" for a in af),
                                         tareas=sum(a["tipo"] == "Task" for a in af))
    return c


def escribir_md(epicas, ruta):
    c = conteos(epicas)
    L = ["# Ayni HR — Backlog", "",
         f"{c['epicas']} épicas · {c['historias']} historias · {c['tareas']} tareas técnicas. "
         "Sin estimaciones de tiempo ni de personas: el equipo las agrega al planificar.", "",
         "Este archivo se genera con `tools/generar_backlog.py` a partir de `tools/fuente/`. "
         "No lo edites a mano: cambia la fuente y vuelve a generar.", "",
         "## Cómo importar `backlog.csv` en Jira", "",
         "1. En Jira, crea el proyecto (plantilla Scrum o Kanban de software) y verifica que existan los tipos de incidencia Epic, Story y Task.",
         "2. Crea los componentes Backend, Frontend, Backend + Frontend, Infraestructura, Calidad y Datos, o deja que el importador los cree.",
         "3. Crea un campo personalizado de texto corto llamado «Clave del kit» y agrégalo a las pantallas del proyecto.",
         "4. Ve a Configuración → Sistema → Importación de sistema externo → CSV y sube `backlog.csv` (codificación UTF-8, delimitador coma).",
         "5. Elige el proyecto de destino y asigna las columnas según la tabla de abajo. Marca «Asignar valores de campo» solo en Issue Type si tu Jira usa otros nombres (Historia, Tarea).",
         "6. Ejecuta la importación y revisa el registro: deben crearse tantas incidencias como filas tiene el archivo sin contar la cabecera.",
         "7. Los vínculos «Bloqueada por» se crean al final de la importación; revisa uno al azar (por ejemplo `COL-3`).", "",
         "| Columna del CSV | Campo de Jira |", "|---|---|",
         "| Issue ID | Issue ID (identificador externo; solo se usa para enlazar padres y bloqueos) |",
         "| Issue Type | Issue Type |", "| Summary | Summary (Resumen) |", "| Description | Description (Descripción) |",
         "| Parent | Parent (Principal); en proyectos antiguos, Epic Link |",
         "| Epic Name | Epic Name (Nombre de la épica) |", "| Component | Component/s (Componentes) |",
         "| Labels (dos columnas) | Labels (Etiquetas): fase y módulo comercial |",
         "| Clave del kit | Campo personalizado «Clave del kit» |",
         "| Bloqueada por (cinco columnas) | Link «Blocks» en sentido entrante: «is blocked by» |", "",
         "## Cómo leer cada actividad", "",
         "- **Clave** (`COL-3`): código de la épica y número. Es la referencia estable que usan la documentación del kit y las dependencias.",
         "- **Tipo**: *Historia* (valor visible para un usuario) o *Tarea técnica* (trabajo interno necesario).",
         "- **Depende de**: actividades que deben estar terminadas antes. Ninguna depende de una fase posterior; la épica QA es transversal y puede depender de cualquiera.",
         "- **Descripción**: qué construir.",
         "- **Criterios de aceptación**: lo que se verifica para darla por terminada, además de la definición de «hecho» general (QA-1).",
         "- **Referencias**: documentos del kit, reglas de negocio de la épica («RN-…» en `docs/06`), máquina de estados («ME-nn» en `docs/05`), punto del pedido original («Pedido §N»), corrección («C-xx») o ampliación («E-xx») de `SPEC.md`, y pantalla real del prototipo (según `docs/17-mapa-del-prototipo.md`).",
         "- **Componente**: va en la línea de la épica; una actividad lo indica solo cuando difiere del de su épica.",
         "- **Etiquetas** (en el CSV y el JSON): fase (`fase-0-cimientos` … `fase-9-ecosistema`, `transversal`) y módulo comercial (`modulo-…`).", "",
         "## Orden de trabajo", "",
         "Las fases 0 y 1 son requisito de todo. La 2 va antes de la 3 (la planilla consume asistencia y ausencias). "
         "Las fases 4 a 7 no dependen entre sí en lo funcional y pueden reordenarse según prioridad comercial, respetando las dependencias indicadas. "
         "La 8 necesita datos de las anteriores.", "",
         "| Fase | Épicas |", "|---|---|"]
    for f in ORDEN_FASES:
        L.append(f"| {FASES[f][0]} | " + " · ".join(f"{e['codigo']} {e['nombre']}" for e in epicas if e["fase"] == f) + " |")
    L.append("")
    for f in ORDEN_FASES:
        L += [f"# {FASES[f][0]}", ""]
        for e in (x for x in epicas if x["fase"] == f):
            L += [f"## {e['codigo']} · {e['nombre']}", "", e["descripcion"], "", f"*Componente: {e['componente']}*", ""]
            for a in e["actividades"]:
                tipo = "Historia" if a["tipo"] == "Story" else "Tarea técnica"
                comp = f" · {a['componente']}" if a["componente"] != e["componente"] else ""
                dep = "depende de " + ", ".join(a["dependencias"]) if a["dependencias"] else "sin dependencias"
                L += [f"### {a['clave']} · {a['titulo']}", "", f"*{tipo}*{comp} · {dep}", "", a["descripcion"], ""]
                L += [f"- [ ] {x}" for x in a["criterios"]]
                L += ["", "Referencias: " + " · ".join(a["referencias"]), ""]
    open(ruta, "w", encoding="utf-8").write("\n".join(L))


def escribir_json(epicas, ruta):
    datos = dict(producto="Ayni HR", nota="Sin estimaciones de tiempo ni de personas: el equipo las agrega al planificar.",
                 conteos=conteos(epicas), fases=[])
    for f in ORDEN_FASES:
        fase = dict(fase=f, nombre=FASES[f][0], etiqueta=FASES[f][1], epicas=[])
        for e in (x for x in epicas if x["fase"] == f):
            fase["epicas"].append(dict(
                codigo=e["codigo"], issue_id=e["issue_id"], nombre=e["nombre"], descripcion=e["descripcion"],
                componente=e["componente"], etiquetas=[FASES[f][1], e["modulo"]],
                actividades=[dict(clave=a["clave"], issue_id=a["issue_id"], tipo=a["tipo"],
                                  resumen=f"[{a['clave']}] {a['titulo']}", titulo=a["titulo"],
                                  descripcion=a["descripcion"], criterios_de_aceptacion=a["criterios"],
                                  referencias=a["referencias"], componente=a["componente"],
                                  etiquetas=[FASES[f][1], e["modulo"]], depende_de=a["dependencias"])
                             for a in e["actividades"]]))
        datos["fases"].append(fase)
    json.dump(datos, open(ruta, "w", encoding="utf-8"), ensure_ascii=False, indent=2)


def main():
    epicas = cargar()
    res = verificar(epicas)
    numerar(epicas)
    if all(ok for _, ok, _ in res):
        os.makedirs(SALIDA, exist_ok=True)
        escribir_csv(epicas, os.path.join(SALIDA, "backlog.csv"))
        escribir_md(epicas, os.path.join(SALIDA, "backlog.md"))
        escribir_json(epicas, os.path.join(SALIDA, "backlog.json"))
        res.append(verificar_csv(epicas, os.path.join(SALIDA, "backlog.csv")))
        import shutil
        shutil.copyfile(os.path.join(SALIDA, "backlog.csv"), os.path.join(RAIZ, "AyniHR_backlog_jira.csv"))
        j = json.load(open(os.path.join(SALIDA, "backlog.json"), encoding="utf-8"))
        n = sum(len(e["actividades"]) for f in j["fases"] for e in f["epicas"])
        res.append(("El JSON se relee y contiene todas las actividades", n == j["conteos"]["actividades"], str(n)))
    for nombre, ok, det in res:
        print(("  OK   " if ok else "  FALLA") + f" {nombre}: {det}")
    c = conteos(epicas)
    print(f"\n{c['epicas']} épicas · {c['historias']} historias · {c['tareas']} tareas técnicas · {c['actividades']} actividades")
    for f, v in c["por_fase"].items():
        print(f"  {f}: {v['epicas']} épicas, {v['historias']} historias, {v['tareas']} tareas")
    if not all(ok for _, ok, _ in res):
        print("\nVERIFICACIÓN FALLIDA: no se dan por válidas las salidas.")
        sys.exit(1)
    print("\nTodas las verificaciones pasaron. Salidas en backlog/.")


if __name__ == "__main__":
    main()
