"""
Genera docs/AVANCE.md con la tabla completa de backlog.
"""
import json, pathlib

ROOT = pathlib.Path(__file__).parent.parent
backlog_path = ROOT / "backlog" / "backlog.json"
output_path  = ROOT / "docs" / "AVANCE.md"

with open(backlog_path, encoding="utf-8") as f:
    data = json.load(f)

# Historias que tienen implementación y pruebas en verde
HECHAS: dict[str, str] = {
    "PLT-1": "Monorepo con apps/api, apps/web y packages/contracts; boundaries test y linters en verde.",
    "PLT-2": "Entorno Docker completo con API, Web, Postgres, Redis, MinIO, Mailpit y Horizon; db-reset verificado.",
}

lines = [
    "# AVANCE — Ayni HR Kit",
    "",
    "Generado el **2026-11-02**. Actualizar manualmente al cerrar cada historia.",
    "",
    "> **Leyenda de estado:** `pendiente` · `en_curso` · `hecha` · `bloqueada`",
    "",
    "<!-- TABLE_START -->",
    "| Código | Título | Épica | Fase | Tipo | Estado | Fecha | Nota |",
    "|--------|--------|-------|------|------|--------|-------|------|",
]

for fase in data["fases"]:
    fn = fase["nombre"]
    for epica in fase["epicas"]:
        ec = epica["codigo"]
        en = epica["nombre"]
        for act in epica["actividades"]:
            clave  = act["clave"]
            titulo = act["titulo"].replace("|", "\\|")
            tipo   = act["tipo"]
            estado = "hecha" if clave in HECHAS else "pendiente"
            fecha  = "2026-11-02" if clave in HECHAS else "—"
            nota   = HECHAS.get(clave, "")
            lines.append(
                f"| {clave} | {titulo} | {ec} | {fn} | {tipo} | {estado} | {fecha} | {nota} |"
            )

lines += [
    "<!-- TABLE_END -->",
    "",
    f"**Total:** {sum(len(e['actividades']) for fase in data['fases'] for e in fase['epicas'])} actividades",
    "",
]

output_path.write_bytes("\n".join(lines).encode("utf-8"))
print(f"Generado {output_path} con {len(lines)-10} filas de datos.")
