# GestiaHR — repositorio de la aplicación

SaaS integral de Recursos Humanos multiempresa, que nace para Perú y se extiende a Latinoamérica. Este repositorio contiene la aplicación (`apps/`, `packages/`) y el kit de desarrollo v1.13 (`kit/`) del que salen la especificación, el esquema, el contrato de API y el backlog.

| Ruta | Qué es |
|---|---|
| `apps/api/` | API Laravel 12 · PHP 8.4 (módulos de dominio en `app/Modules/`) |
| `apps/web/` | Web Next.js 15 · React · TypeScript |
| `packages/contracts/` | Tipos TypeScript generados desde `kit/api/openapi.yaml` |
| `kit/` | Kit de desarrollo: documentos (`kit/docs/`), esquema (`kit/database/`), contrato (`kit/api/`), backlog (`kit/backlog/`), prompts por épica (`kit/prompts/`), prototipo y generadores (`kit/tools/`). Empieza por `kit/EMPEZAR-AQUI.md` y `kit/docs/20-entrega-al-equipo.md` |
| `docs/AVANCE.md` | Estado de cada actividad del backlog (se regenera con `python3 scripts/gen_avance.py`) |
| `docs/guia-trabajo.md` | Guía de trabajo del repositorio |
| `CLAUDE.md`, `.claude/commands/` | Reglas y comandos para Claude Code |

Los archivos generados del kit no se editan a mano: se cambia la fuente en `kit/tools/` y se regenera (`make openapi`, `make backlog`, `cd kit/tools/esquema && python3 gen.py`). `make kit-check` comprueba que coinciden con sus fuentes.

## Arranque local

```bash
make up && make api-install && make web-install && make migrate && make seed
```

| Servicio | Dirección |
|---|---|
| API (Laravel) | http://localhost:8000 (salud: `/api/v1/health`, referencia: `/docs`) |
| Web (Next.js) | http://localhost:3000 (organización demo: http://demo.localhost:3000) |
| Mailpit (correo, SMS y WhatsApp simulados) | http://localhost:8025 |
| MinIO (S3 local) | consola http://localhost:9001 |
| PostgreSQL 17 | `localhost:5432` (base `aynihr`, usuario `ayni`) |
| Redis 7 | `localhost:6379` |

## Comandos

| Comando | Acción |
|---|---|
| `make up` / `make down` / `make ps` / `make logs` | Servicios de Docker |
| `make migrate` / `make seed` / `make db-reset` | Migraciones, datos demo, base limpia |
| `make test` / `make test-api F=…` / `make test-web` | Pruebas (Pest y Vitest) |
| `make test-isolation` | Pruebas de aislamiento entre organizaciones |
| `make lint` | Pint, PHPStan, ESLint y TypeScript |
| `make openapi` / `make contracts` | Regenerar el contrato y los tipos |
| `make kit-check` | Esquema, aislamiento y generadores del kit en verde |

Las 15 reglas que no se negocian, con su comprobación, están en [`CLAUDE.md`](CLAUDE.md).
