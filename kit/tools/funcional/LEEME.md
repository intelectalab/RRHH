
## Conciliación de estados (documento 05)

La sección final de `docs/05-maquinas-de-estado.md` («Conciliación con `schema.sql`») y la línea «Valores en `schema.sql`» de cada máquina las mantiene `tools/esquema/estados.py`, no `gen.py` de esta carpeta. Si se regenera el 05 con `gen.py`, hay que ejecutar después `python3 ../esquema/estados.py` para restituir la conciliación (su mapa estado → valor de base está en ese archivo).
