---
description: Crea una migración que cumple las reglas de aislamiento, vigencia e índices
argument-hint: <qué tabla o cambio>
---

Crea la migración para: **$ARGUMENTS**

Antes: lee `kit/database/schema.sql` y `kit/docs/04-modelo-de-datos.md`. Si la tabla ya está definida ahí, usa exactamente esos nombres y tipos; si no, propón el cambio también en `schema.sql`.

La migración debe:

- Ubicarse en `apps/api/app/Modules/<Modulo>/Database/Migrations/`.
- Tabla en inglés, `snake_case`, plural. Clave primaria `id` (tipo según `kit/docs/04`), `tenant_id` no nulo, `created_at`, `updated_at`; `deleted_at` si el recurso va a la papelera.
- Restricción única `(tenant_id, id)` y **claves foráneas compuestas** `(tenant_id, x_id) → tabla(tenant_id, id)`.
- **RLS**: `ENABLE` + `FORCE ROW LEVEL SECURITY` y política `tenant_id = current_setting('app.tenant_id')::…` usando el helper `Tenancy::enableRls($tabla)`.
- Si guarda historia: `valid_from date not null`, `valid_to date null`, restricción de exclusión contra solapes por la clave natural (`EXCLUDE USING gist`), índice por `(tenant_id, <dueño>, valid_from desc)`.
- Estados como `text` con `CHECK` (no `enum` de Postgres), nunca booleanos múltiples.
- Dinero: `numeric(14,2)` + columna de moneda `char(3)`; tasas: `numeric(9,6)`. Nunca `float`.
- Índices para cada filtro y orden que expone el endpoint de listado; índice parcial `WHERE deleted_at IS NULL` cuando aplique.
- Tablas de solo inserción (auditoría, marcaciones, bitácoras): revoca `UPDATE` y `DELETE` al rol de aplicación.
- `down()` reversible, salvo migraciones de datos (documenta por qué no).
- Sin datos de negocio en la migración: los catálogos van en seeders o en el paquete de país.

Después: añade la factory y, si corresponde, datos en el seeder demo coherentes con la organización demo. Escribe la prueba de esquema (RLS activo, FK compuestas, sin solapes) y ejecuta `make migrate test-api F=Schema`.
