# Ayni HR — kit de desarrollo

Material para que un equipo construya Ayni HR con Claude Code: reglas, arquitectura, modelo de datos, contrato de API, backlog y prompts. Ayni HR es un SaaS integral de Recursos Humanos multiempresa, que nace para Perú y se extiende a Latinoamérica.

El kit no contiene precios, costos, horas, fechas ni tamaño de equipo: eso lo define el área de desarrollo al planificar.

## Instalación y arranque local

El entorno completo (API Laravel, Web Next.js, PostgreSQL 17, Redis, MinIO, Mailpit y Horizon) se instala y arranca con un **único comando**:

```bash
make up && make api-install && make web-install && make migrate && make seed
```

### Comandos de desarrollo (Makefile)

| Comando | Acción |
|---|---|
| `make up` | Levanta todos los contenedores en segundo plano |
| `make down` | Detiene y remueve los contenedores |
| `make ps` | Muestra el estado y salud de los contenedores |
| `make logs` | Sigue los registros de todos los servicios |
| `make api-install` | Ejecuta `composer install` dentro del contenedor de API |
| `make web-install` | Ejecuta `npm install` dentro del contenedor web |
| `make migrate` | Aplica las migraciones de Laravel |
| `make seed` | Ejecuta los seeders de la base de datos |
| `make db-reset` | Recrea la base limpia y ejecuta migraciones y semillas |
| `make test` | Ejecuta todas las pruebas (API Pest + Web Vitest) |
| `make test-api` | Ejecuta pruebas de Laravel con Pest (incluyendo arquitectura) |
| `make test-web` | Ejecuta pruebas de Next.js con Vitest |
| `make lint` | Ejecuta análisis estático y linters de API y Web |
| `make lint-api` | Ejecuta Laravel Pint y PHPStan (Nivel 8) |
| `make lint-web` | Ejecuta ESLint y TypeScript typecheck (`tsc --noEmit`) |

### Servicios y puertos locales

- **API REST (Laravel)**: [http://localhost:8000](http://localhost:8000) (Healthcheck: `/api/v1/health`)
- **Aplicación Web (Next.js)**: [http://localhost:3000](http://localhost:3000)
- **Mailpit (Servidor de correo de pruebas local)**: [http://localhost:8025](http://localhost:8025) (SMTP en puerto 1025)
- **MinIO (Almacenamiento S3)**: Consola en [http://localhost:9001](http://localhost:9001), API en [http://localhost:9000](http://localhost:9000)
- **PostgreSQL 17**: `localhost:5432` (`POSTGRES_DB=aynihr`, `POSTGRES_USER=ayni`, `POSTGRES_PASSWORD=secret`)
- **Redis 7**: `localhost:6379`

## Cómo usar el kit

1. **Copia `kit/` a la raíz del repositorio** del producto (o úsalo como raíz). `CLAUDE.md` y `.claude/commands/` deben quedar en la raíz para que Claude Code los cargue.
2. **Cierra las decisiones que bloquean la fase 0** en [`docs/13-decisiones-abiertas.md`](docs/13-decisiones-abiertas.md): A-01, A-02, A-03, A-04, A-08, D-01, D-03, D-04, D-08, D-13, D-29.
3. **Lee en este orden** la primera vez: [`01`](docs/01-vision-y-fases.md) → [`02`](docs/02-arquitectura.md) → [`04`](docs/04-modelo-de-datos.md) → [`03`](docs/03-convenciones.md) → [`15`](docs/15-que-no-copiar-del-prototipo.md).
4. **Trabaja historia por historia** con el backlog ([`backlog/backlog.md`](backlog/backlog.md)), en el orden de fases:

   ```
   /historia PLT-1
   ```

   El comando hace que Claude Code lea la historia, la especificación del módulo, las tablas y las rutas, implemente en el orden migración → dominio → caso de uso → endpoint → pruebas → pantalla, y termine con las pruebas en verde.
5. **Revisa cada cambio** con `/revisar` antes de integrarlo.
6. **Mantén vivo el contrato**: la API se edita en [`tools/gen_openapi.py`](tools/gen_openapi.py) y se regenera (`python3 tools/gen_openapi.py`); el modelo, en `database/schema.sql` y `docs/04`.

### Comandos de Claude Code

| Comando | Para |
|---|---|
| [`/historia <código>`](.claude/commands/historia.md) | Implementar una historia del backlog de punta a punta |
| [`/migracion <qué>`](.claude/commands/migracion.md) | Crear una migración con aislamiento, vigencia e índices |
| [`/endpoint <MÉTODO /ruta>`](.claude/commands/endpoint.md) | Crear un endpoint según el contrato |
| [`/pantalla <nombre>`](.claude/commands/pantalla.md) | Construir una pantalla a partir del prototipo |
| [`/revisar [rango]`](.claude/commands/revisar.md) | Revisar un cambio contra las reglas no negociables |

Los mismos prompts en versión larga, más los de concepto de planilla, paquete de país, adaptador y evento, están en [`PROMPTS.md`](PROMPTS.md).

## Índice

### Raíz

| Archivo | Contenido |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Qué es el producto, estructura del monorepo, comandos y las 15 reglas no negociables con su comprobación |
| [`PROMPTS.md`](PROMPTS.md) | Prompts listos para Claude Code |
| [`.claude/commands/`](.claude/commands/) | Cinco comandos |

### Documentos

| N.º | Documento | Contenido |
|---|---|---|
| 01 | [Visión y fases](docs/01-vision-y-fases.md) | Visión, público, principios, módulos comerciales, fases y dependencias, qué se contrata por separado |
| 02 | [Arquitectura](docs/02-arquitectura.md) | Monolito modular, capas, módulos y límites, multiempresa y RLS, jerarquía, vigencias, eventos y outbox, aprobaciones y workflows, motor de nómina, planes y flags, archivos, búsqueda, caché, colas, rendimiento, i18n, IA, MCP |
| 03 | [Convenciones](docs/03-convenciones.md) | Código backend y frontend, nombres, carpetas, migraciones, errores, respuesta, pruebas, commits |
| 04 | [Modelo de datos](docs/04-modelo-de-datos.md) | Tablas, relaciones y reglas de integridad (junto con [`database/schema.sql`](database/schema.sql)) |
| 05 | [Máquinas de estado](docs/05-maquinas-de-estado.md) | Estados, transiciones, guardas y permisos por recurso |
| 06 | [Reglas de negocio](docs/06-reglas-de-negocio.md) | Reglas por módulo |
| 07 | [Permisos y roles](docs/07-permisos-y-roles.md) | Catálogo de permisos, permisos por campo, alcances y matriz por rol |
| 08 | [API](docs/08-api.md) | Versionado, autenticación, errores, paginación, filtros, idempotencia, acciones, webhooks, límites y tabla de endpoints por módulo |
| 09 | [Integraciones](docs/09-integraciones.md) | Puertos y adaptadores, implementaciones falsas, salud de conectores |
| 10 | [Pantallas y UX](docs/10-pantallas-y-ux.md) | Tokens, componentes, patrones, navegación por rol, móvil, accesibilidad, temas |
| 11 | [Pruebas y calidad](docs/11-pruebas-y-calidad.md) | Estrategia, definición de terminado, aislamiento, matriz de permisos, máquinas de estado, pruebas doradas, recorridos por fase, carga |
| 12 | [Seguridad y privacidad](docs/12-seguridad-y-privacidad.md) | Amenazas, autenticación, permisos por campo, cifrado, archivos, registros, copias, retención, Ley 29733, biometría, IA, denuncias |
| 13 | [Decisiones abiertas](docs/13-decisiones-abiertas.md) | 51 decisiones (A-01 a A-14, D-01 a D-37) con opciones, recomendación y fase límite |
| 14 | [Localización Perú](docs/14-localizacion-peru.md) | Norma peruana, regímenes, conceptos y archivos (resumen; el detalle técnico está en el 18) |
| 15 | [Qué no copiar del prototipo](docs/15-que-no-copiar-del-prototipo.md) | Qué se toma y qué no del prototipo |
| 16 | [Glosario](docs/16-glosario.md) | Términos de RRHH, planilla peruana y del producto |
| 17 | [Mapa del prototipo](docs/17-mapa-del-prototipo.md) | Pantallas del prototipo, su módulo, épica y qué es real o simulado |
| 18 | [Localización Perú: detalle técnico](docs/18-localizacion-peru-detalle-tecnico.md) | Ficha T-Registro, motor de quinta categoría con casos resueltos, archivos PLAME, T-Registro y AFPnet, regímenes especiales, flujos de cumplimiento y libro inalterable; estado de verificación de cada dato y fuentes |
| — | [Especificaciones por módulo](docs/modulos/) | Una por módulo: alcance, pantallas, reglas, endpoints, eventos, pruebas |

Los documentos 04, 05, 06, 07 y 14, las especificaciones de `docs/modulos/`, `database/schema.sql` y `backlog/` los escriben otros autores del kit; este índice los enlaza por su nombre acordado.

### Contrato, datos y herramientas

| Ruta | Contenido |
|---|---|
| [`api/openapi.yaml`](api/openapi.yaml) | OpenAPI 3.0.3 con `x-permission` y `x-phase` por operación. Generado; no editar a mano. Es grande: búscalo por ruta |
| [`tools/gen_openapi.py`](tools/gen_openapi.py) | Generador y validador del contrato y de la tabla de endpoints de `docs/08-api.md` |
| [`database/schema.sql`](database/schema.sql) | Esquema de referencia |
| [`backlog/`](backlog/) | Backlog en Markdown, CSV (Jira) y JSON |

## Reglas que no se negocian (resumen)

Aislamiento por organización · historia con vigencia · estados solo por acciones · toda regla legal es un parámetro · auditoría de solo inserción · permisos por campo y alcance · accesos sensibles registrados · adaptadores + outbox · idempotencia · el backend valida lo que la interfaz oculta · sin lógica legal en el frontend · i18n, dinero decimal y fechas con zona · la IA asiste, no decide · asistencia inalterable y anonimato real · pruebas obligatorias y contrato primero. Detalle y comprobación en [`CLAUDE.md`](CLAUDE.md).

## Qué se comprobó

| Comprobación | Resultado |
|---|---|
| `api/openapi.yaml` validado con `openapi-spec-validator` (OpenAPI 3.0.3) | Válido: 687 rutas, 939 operaciones, 302 esquemas |
| Toda operación tiene `x-permission` y `x-phase` | Sí (lo afirma el generador; falla si falta) |
| Todas las épicas con API tienen al menos un endpoint | 68 épicas cubiertas; UI y QA no tienen endpoints propios |
| `operationId` únicos | Sí (lo afirma el generador) |
| Tabla de endpoints de `docs/08-api.md` | Generada desde el mismo origen que el YAML |
| Tokens de diseño de `docs/10` | Copiados del `:root` de `core.html` (claro y oscuro) |
| Códigos de decisión citados en los documentos | Todos existen en `docs/13` |
| Ausencia de precios, costos, horas, fechas de entrega y tamaño de equipo | Revisado en los documentos de este autor |
| Tasas legales | No se inventó ninguna; las citadas remiten a `docs/research.md` y a `docs/18` (con su estado de verificación y fuente) y son parámetros con vigencia |
| `database/schema.sql` cargado en PostgreSQL 16 limpio | 478 tablas (429 de organización con RLS forzado, 49 de plataforma), 1 533 claves foráneas, 20 tablas inalterables |
| `database/pruebas_aislamiento.sql` | «TODAS LAS PRUEBAS PASARON», incluida la prueba del libro de cumplimiento encadenado (8.5) |
| Catálogos | 876 permisos, 152 parámetros legales de Perú (113 con valor), 62 conceptos de planilla (45 códigos cotejados con la Tabla 22) |
| Documentos funcionales | 49 máquinas de estado conciliadas con el esquema, 682 reglas de negocio |

Lo que **no** se comprobó y queda a cargo del equipo:

- Los diagramas mermaid de `docs/02` y `docs/01` no se renderizaron con una herramienta; revisar al abrirlos.
- Los nombres de tabla usados en 02, 03 y 15 son conceptuales (`tenants`, `legal_entities`, `sites`, `org_units`, `job_profiles`, `positions`, `people`, `employments`, `users`): `database/schema.sql` no existía al escribirlos. Si el esquema difiere, manda el esquema.
- Los códigos de permiso del contrato están en inglés; el prototipo y algunos ejemplos del backlog usan español. Hay que unificarlos (decisión D-29) contra `docs/07-permisos-y-roles.md`.
- Los comandos `make` de `CLAUDE.md` son el contrato esperado del `Makefile`; se crean en las historias PLT.
- La normativa peruana procede de `docs/research.md`, que cita varias fuentes secundarias y marca datos sin verificar: validar con el área legal (D-15) antes de usar cualquier valor en producción.
