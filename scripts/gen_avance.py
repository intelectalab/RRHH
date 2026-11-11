#!/usr/bin/env python3
"""
Regenera docs/AVANCE.md desde kit/backlog/backlog.json conservando el estado, la fecha y la nota
de cada actividad ya registrada en el archivo actual. Las actividades nuevas del kit entran como
«pendiente». Uso: python3 scripts/gen_avance.py
"""
import json
import pathlib
import re
from collections import Counter
from datetime import date

ROOT = pathlib.Path(__file__).resolve().parent.parent
BACKLOG = ROOT / "kit" / "backlog" / "backlog.json"
AVANCE = ROOT / "docs" / "AVANCE.md"

previo: dict[str, tuple[str, str, str]] = {}
if AVANCE.exists():
    for line in AVANCE.read_text(encoding="utf-8").splitlines():
        m = re.match(r"\|\s*([A-Z]+-\d+)\s*\|", line)
        if m:
            cols = [c.strip() for c in line.strip().strip("|").split("|")]
            # Código | Título | Épica | Fase | Tipo | MVP? | Estado | Fecha | Nota  (o el formato anterior sin MVP)
            if len(cols) >= 9 and cols[5] in ("sí", "—", "si", "no"):
                previo[m.group(1)] = (cols[6], cols[7], "|".join(cols[8:]))
            else:
                previo[m.group(1)] = (cols[5], cols[6], "|".join(cols[7:]))

data = json.loads(BACKLOG.read_text(encoding="utf-8"))
filas, estados, mvp_estados = [], Counter(), Counter()
for fase in data["fases"]:
    for epica in fase["epicas"]:
        for a in epica["actividades"]:
            clave = a["clave"]
            estado, fecha, nota = previo.get(clave, ("pendiente", "—", ""))
            titulo = a["resumen"].split("] ", 1)[-1]
            mvp = "sí" if a.get("mvp") else "—"
            tipo = "Story" if a["tipo"].lower().startswith("hist") or a["tipo"] == "Story" else "Task"
            filas.append(f"| {clave} | {titulo} | {epica['codigo']} | {fase['nombre']} | {tipo} | {mvp} | {estado} | {fecha} | {nota} |")
            estados[estado] += 1
            if a.get("mvp"):
                mvp_estados[estado] += 1

total = sum(estados.values())
total_mvp = sum(mvp_estados.values())


def pct(n: int, d: int) -> str:
    return f"{100 * n / d:.1f} %".replace(".", ",") if d else "—"


out = [
    f"# AVANCE — {data['producto']}",
    "",
    f"Actualizado el **{date.today().isoformat()}** con `python3 scripts/gen_avance.py` desde `kit/backlog/backlog.json` (kit v1.13). "
    "El estado, la fecha y la nota de cada actividad se editan aquí al cerrarla; al regenerar se conservan.",
    "",
    "> **Leyenda de estado:** `pendiente` · `en_curso` · `hecha` · `bloqueada`. La columna MVP marca el primer corte comercial (`kit/backlog/ruta-mvp.md`).",
    "",
    "| Medida | Actividades | Hechas | En curso | Avance |",
    "|---|---|---|---|---|",
    f"| Primer corte comercial (MVP) | {total_mvp} | {mvp_estados['hecha']} | {mvp_estados['en_curso']} | {pct(mvp_estados['hecha'], total_mvp)} |",
    f"| Backlog completo | {total} | {estados['hecha']} | {estados['en_curso']} | {pct(estados['hecha'], total)} |",
    "",
    "<!-- TABLE_START -->",
    "| Código | Título | Épica | Fase | Tipo | MVP | Estado | Fecha | Nota |",
    "|--------|--------|-------|------|------|-----|--------|-------|------|",
    *filas,
    "<!-- TABLE_END -->",
    "",
    f"**Total:** {total} actividades",
    "",
]
AVANCE.write_text("\n".join(out), encoding="utf-8")
print(f"{total} actividades · hechas {estados['hecha']} · en curso {estados['en_curso']} · MVP {total_mvp}")
