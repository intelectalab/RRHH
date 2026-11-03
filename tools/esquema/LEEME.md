# Generador del esquema

`database/schema.sql`, los JSON de `database/catalogos/` y `docs/04-modelo-de-datos.md` se generan desde esta carpeta. **No se editan a mano**: se cambia la fuente y se regenera.

| Archivo | Qué es |
|---|---|
| `model1.dsl`, `model2.dsl`, `model3.dsl` | Definición de las tablas (fases 0–1, 2–5, 6–9) en una notación compacta |
| `prelude.sql`, `epilogue.sql` | Cabecera (roles, funciones) y cierre (triggers específicos, vista, `seed_tenant_defaults`, privilegios) de `schema.sql` |
| `catalogos.py` | Catálogos: lee módulos de `docs/01-vision-y-fases.md` §4 y permisos y matriz de `docs/07-permisos-y-roles.md` §5; define parámetros, parámetros legales de Perú y conceptos |
| `gen.py` | Genera `schema.sql` y los cinco JSON |
| `gen_docs.py`, `doc_head.md`, `doc_tail.md` | Generan `docs/04-modelo-de-datos.md` con los conteos leídos del catálogo de PostgreSQL |
| `estados.py` | Mapa estado funcional → valor de base; reescribe la conciliación final de `docs/05-maquinas-de-estado.md` |

## Regenerar

```bash
cd kit/tools/esquema
python3 gen.py                                   # schema.sql + catalogos/*.json
createdb ayni && psql -v ON_ERROR_STOP=1 -d ayni -f ../../database/schema.sql
psql -v ON_ERROR_STOP=1 -d ayni -f ../../database/pruebas_aislamiento.sql     # debe terminar en «TODAS LAS PRUEBAS PASARON»
AYNI_PSQL='psql -d ayni' python3 gen_docs.py     # 04-modelo-de-datos.md con conteos reales
python3 estados.py                               # conciliación del 05 (falla si falta un valor en un CHECK)
```

Cuándo regenerar: si cambia el catálogo de permisos o la matriz del 07, los módulos del 01, una máquina de estados del 05 (añadir el valor al `status enum(...)` de la tabla en el `.dsl` y al mapa de `estados.py`) o cualquier tabla.

## Notación de los `.dsl`

- `#F n | título` fase · `#E COD | título` épica · `#N texto` nota de la épica.
- `@tabla marcas | comentario`. Marcas: `P` plataforma (sin `tenant_id` ni RLS) · `cat` catálogo legible por `ayni_app` · `soft` `deleted_at` · `lock` `lock_version` · `imm` inalterable (trigger) · `ao` solo anexado, sin `updated_at` · `nots` sin marcas de tiempo · `sens` tabla sensible · `main` aparece en el diagrama.
- Columnas separadas por ` ; `. `col -> tabla` clave foránea compuesta obligatoria; `?->` opcional; `=>` a tabla de plataforma por `id`; `~> tabla.code` a catálogo por código.
- Tipos abreviados: `ts` timestamptz, `j` jsonb, `money` numeric(14,2), `cur` char(3) obligatorio (`cur?` opcional), `bool=f`, `enum(a,b)=a` (text + CHECK con valor por defecto), `!` obligatorio, `=x` valor por defecto. `#S` marca la columna como sensible; las `_enc` lo son siempre.
- Un nombre suelto se infiere: `code`, `name`, `title` texto obligatorio; `is_…` booleano; `…_at` timestamptz; `…_on` date; `status` activo/inactivo; lo demás texto.
- Directivas: `uniq a, b` · `idx a, b` · `vig clave [| condición]` vigencia con exclusión · `vigcols` solo columnas de vigencia · `check expr` · `raw restricción`.
