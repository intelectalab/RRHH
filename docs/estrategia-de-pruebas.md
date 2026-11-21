# Estrategia de pruebas y definición de hecho (QA-1)

Este documento es del repositorio (no del kit). Complementa `kit/docs/11-pruebas-y-calidad.md`, `kit/docs/modulos/M-QA.md` y la lista de verificación de `.github/pull_request_template.md`, que lo aplica en cada solicitud de cambio.

## 1. Pirámide de pruebas

| Nivel | Qué prueba | Dónde | Cómo se corre |
|---|---|---|---|
| Unitarias | Dominio puro: máquinas de estado, fórmulas, objetos de valor, reglas de cálculo | `apps/api/tests/Unit`, `app/Modules/*/Tests/Unit`, `apps/web/src/**/*.test.ts(x)` | `make test-api`, `make test-web` |
| Arquitectura | Reglas no negociables verificables en código: sin `withoutGlobalScopes` fuera de Tenancy, sin SDK externos en Domain/Application, sin literales legales, matriz estado × acción de toda máquina | `apps/api/tests/Architecture` | `make test-api` |
| API (Feature) | Cada endpoint: caso feliz, 403 sin permiso, 403 `module_not_enabled`, 422 con datos inválidos, 409 en transiciones inválidas, 404 entre organizaciones, auditoría y registro de accesos | `apps/api/tests/Feature` | `make test-api F=…` |
| Contrato | Toda respuesta de una prueba Feature se valida con `assertMatchesOpenApi()` contra `kit/api/openapi.yaml`; las rutas de Laravel y las del contrato coinciden | `tests/Support/OpenApiAssert.php`, prueba de contrato | `make test-api`, `make contracts` |
| Aislamiento | SQL sobre el esquema (RLS forzado, claves compuestas) y recorrido de todas las rutas con un usuario de otra organización | `kit/database/pruebas_aislamiento.sql`, `tests/Feature/TenancyIsolationTest.php` | `make test-isolation` |
| Doradas | Casos de planilla resueltos a mano, con los parámetros como dato del caso | `--group=golden` | `make test-golden` |
| Extremo a extremo | Recorridos del usuario en el navegador sobre la organización demo | `apps/web/e2e` (Playwright) | `make test-e2e` |

Las pruebas de backend corren **en serie** sobre PostgreSQL (RLS, exclusiones y disparadores no se simulan con SQLite).

## 2. Entornos y datos de prueba

- **Base de pruebas automáticas**: se crea solo con `kit/database/schema.sql` (sin migraciones de Laravel), igual que en la integración continua. Cada prueba Feature abre su transacción (`DatabaseTransactions`) y siembra sus propias organizaciones con `seed_tenant_defaults()` y los ayudantes de `tests/Support` (`IdentityFixtures`, `PayrollFixtures`, factories). Los parámetros legales que un caso necesita se fijan en el propio caso: nunca se depende de un valor del catálogo.
- **Organización demo** (`php artisan db:seed`): `DemoOrganizationSeeder`, `DemoPeopleSeeder` y `DemoStorySeeder` dejan una organización coherente (dos empresas, estructura, personas e historia) y es la base del recorrido de extremo a extremo. La clave del administrador demo llega por `DEMO_ADMIN_PASSWORD`; si falta se genera una al azar. Nunca se escribe en el código ni en el repositorio.
- **Datos reproducibles por fase**: cada fase agrega su parte a los seeders demo (idempotentes) y sus fábricas; un recorrido de una fase nueva parte de la demo sembrada en una base limpia y crea lo que necesita por la API.

## 3. Integración continua

- `ci.yml` (push y solicitudes de cambio): lint y análisis estático, deriva de contratos, base desde `schema.sql`, SQL de aislamiento, convenciones de base, Pest **sin cobertura** (rápido), Vitest y pruebas del sitio, y el job `e2e` (Playwright contra una base limpia con la demo sembrada, API y web levantadas en el propio runner).
- `coverage.yml` (programado los lunes y a pedido con *Run workflow*): Pest con xdebug, informe clover como artefacto y **mínimo de cobertura del backend del 70 %** (`--min=70`, falla si no se alcanza). Se separó porque con xdebug el paso de pruebas tardaba unos 40 minutos.

## 4. Definición de hecho

Una actividad del backlog está hecha cuando, además de sus criterios de aceptación:

1. **Reglas no negociables** de `CLAUDE.md` respetadas (aislamiento, vigencia, estados por acciones, parámetros legales, auditoría, permisos por campo y alcance, registro de accesos, adaptadores + outbox, idempotencia, i18n, dinero exacto).
2. **Pruebas**: caso feliz, permiso (403), aislamiento (404 desde otra organización), validación (422), transición inválida (409) si hay estados y caso dorado si hay fórmula de planilla; cada prueba de endpoint llama a `assertMatchesOpenApi()`.
3. **Contrato primero**: si cambió la API, se actualizaron las fuentes de `kit/tools/gen_openapi.py`, se regeneró `openapi.yaml` y `packages/contracts`. Los archivos generados no se editan a mano.
4. **Base**: migración idempotente con RLS forzado y claves compuestas, y la misma tabla en las fuentes del esquema (`kit/tools/esquema`), de modo que la base creada solo desde `schema.sql` sea equivalente.
5. **Pantalla**: textos en `messages/{es,en,pt}.json`, clave de ayuda en `screens.ts` y `screens.json` con su artículo, entrada de menú con el permiso que corresponda, estados vacío/error/cargando, y **ningún botón sin función**: cada acción llama a la API y su resultado se ve al recargar (persistencia).
6. **Checks en verde**: Pint, PHPStan, Pest completo, SQL de aislamiento, `tsc`, ESLint sin advertencias, Vitest, `npm run build` y el recorrido de extremo a extremo.
7. **Seeder demo coherente**: si la actividad agrega datos visibles, la demo los trae sin incoherencias.

## 5. Recorrido de extremo a extremo (QA-2)

`apps/web/e2e/cimientos-core.spec.ts` recorre, sobre la demo: ingreso con correo y clave, segundo factor (activación TOTP o código), alta de un colaborador con su contrato, registro de la firma del contrato y apertura del portal. Para correrlo en local:

```bash
DEMO_ADMIN_PASSWORD=… php artisan db:seed          # base creada desde kit/database/schema.sql
php artisan serve --port=8000                       # con FILESYSTEM_DISK=local y TRUSTED_PROXIES='*'
npm run build && npx next start -p 3000             # en apps/web
E2E_ADMIN_PASSWORD=… make test-e2e                  # o: cd apps/web && npm run test:e2e
```

Pendiente del recorrido (siguientes iteraciones de QA-2): crear la organización desde la consola, ingresar con documento, vacaciones de punta a punta (solicitud y aprobación), firma electrónica por la bandeja y la comprobación en pantalla, API y exportación de que un rol sin permiso de sueldos no los ve.
