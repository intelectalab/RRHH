# Arranque del repositorio

Estos archivos adelantan parte de PLT-1, PLT-2 y PLT-3 para que el primer día el repositorio ya compruebe el kit. Se copian a la **raíz** del repositorio, junto a `CLAUDE.md`:

```bash
cp -r kit/referencia/arranque/{docker-compose.yml,Makefile,.env.example,.gitignore,.github} .
make up             # PostgreSQL 17, Redis, S3 local (SeaweedFS) y buzón de correo (Mailpit en :8025)
make kit-check      # carga schema.sql, corre pruebas_aislamiento.sql y verifica que los generados estén al día
```

Si ya hay otro PostgreSQL en el puerto 5432: `PG_PORT=5433 make up`.

| Archivo | Qué deja hecho | Qué falta y en qué historia |
|---|---|---|
| `docker-compose.yml` | Servicios de apoyo con chequeo de salud | Contenedores de API, web, Horizon y programador (PLT-2) |
| `Makefile` | Metas del kit (`kit-check`, `openapi`, `backlog`, `sitio`) y los nombres de metas que usa `CLAUDE.md` | Cada meta de la aplicación avisa en qué historia se crea mientras no existan `apps/api` y `apps/web` (PLT-1 en adelante) |
| `.env.example` | Variables de desarrollo para la API, alineadas con los servicios | Se copia a `apps/api/.env` en PLT-1 |
| `.github/workflows/kit.yml` | Integración continua del kit: esquema y aislamiento en PostgreSQL 17, generadores sincronizados, diagramas mermaid y pruebas de la calculadora de referencia | Etapas de la aplicación: Pest, Vitest, lint, contratos, cobertura e imágenes (PLT-3) |

## Comprobado el 05/11/2026

- `make kit-check` con PostgreSQL 17.11 en Docker: `schema.sql` carga limpio, `pruebas_aislamiento.sql` termina en «TODAS LAS PRUEBAS PASARON» y los generadores reproducen exactamente los archivos del kit.
- La calculadora de referencia pasa sus 46 pruebas sobre ese mismo PostgreSQL 17.
- `tools/verificar_mermaid.js` lee los 70 diagramas de `kit/docs/` sin error.
- El flujo de GitHub Actions se validó como YAML; corre por primera vez cuando el repositorio exista.

Las credenciales de estos archivos son solo para desarrollo local y para la integración continua.
