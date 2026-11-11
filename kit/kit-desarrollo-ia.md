# GestiaHR — Kit de desarrollo (traspaso al área de desarrollo)

Versión 1.13 · 05/11/2026 · Archivo: `GestiaHR_Kit_Desarrollo_v1.13_20261105.zip` (incluye el prototipo y el sitio de referencia)
Sitio comercial de referencia: https://claude.ai/artifact/5GTVbEDGAQoQkFwU9m5p5R · Para empezar: `kit/EMPEZAR-AQUI.md` y `kit/docs/20-entrega-al-equipo.md`
Prototipo publicado (v1.8): https://claude.ai/artifact/V7cMURwmedrgJvbH5tUHxx · Decisiones y pendientes para los encargados: https://claude.ai/artifact/JuxJGANZgPpFpkihwCUqKY

SaaS integral de Recursos Humanos (HRIS/HCM) multiempresa, para Perú y luego Latinoamérica. Proyecto propio. El zip se descomprime en la raíz del repositorio y está pensado para trabajar con Claude Code. **No incluye tiempos, costos, estimaciones ni tamaño de equipo.** Nombre del producto: **GestiaHR**; dominio: `gestiahr.com`.

## Qué cambió en la 1.13

Detalle en `kit/CAMBIOS-v1.13.md`.

- **Entrega al equipo de desarrollo**: `docs/20-entrega-al-equipo.md` (qué recibe el equipo, primer día, cómo repartir el trabajo con IA, definición de hecho, estado por módulo y decisiones que tocan el MVP) y **un prompt por épica** en `kit/prompts/`. Los genera `tools/gen_entrega.py`.
- **Arranque del repositorio** en `referencia/arranque/`: Docker con PostgreSQL 17, Redis, S3 y correo de pruebas, `Makefile` con las metas de `CLAUDE.md` e integración continua del kit.
- **PostgreSQL 17 comprobado**: `schema.sql` carga y `pruebas_aislamiento.sql` pasa en 17.11. Los 70 diagramas mermaid se validaron.
- **Calculadora de referencia**: indemnización por despido y vacacional, quinta del cese, regularización de diciembre con devolución, y regímenes agrario y de construcción civil; 46 pruebas.
- **Sitio**: textos legales completos (términos, privacidad, cookies y tratamiento de datos) y seis artículos del blog; 47 pantallas.
- **Jira**: cada épica enlaza su especificación y su prompt, y cada actividad trae su comando `/historia`.

## Qué cambió en la 1.12

Detalle en `kit/CAMBIOS-v1.12.md`.

- **`CLAUDE.md` y `.claude/commands/` de vuelta en la raíz del zip**: faltaban en la v1.11.
- **Marca**: D-02 cerrada con el nombre GestiaHR y un logo provisional (ADR 0014), en el sitio, el prototipo, el ícono y las imágenes para redes. El logo vive en `tools/sitio/marca.py`.
- **Planes y complementos como datos iniciales**, sin montos (`docs/01` §4.2, ADR 0013): tabla nueva `addon_modules`.
- **SEO del sitio**: título, descripción, canónica, Open Graph y datos estructurados por página, `sitemap.xml`, `robots.txt` y 12 páginas nuevas por proceso e industria (WEB-9). El generador rechaza títulos repetidos o datos estructurados inválidos.

## Qué cambió en la 1.11

Detalle en `kit/CAMBIOS-v1.11.md`.

- **Decisiones de la fase 0 cerradas**: A-01, A-02, A-03, A-04, A-08, D-01, D-03, D-04, D-08, D-13 y D-29, con la opción recomendada. Hay nueve ADR nuevos (0004 a 0012), y 0001 y 0002 pasan a aceptados.
- Quedan tres datos pendientes que no frenan el desarrollo: el visto bueno legal de A-08, el proveedor de facturación de D-04 y los plazos de D-13.
- **Precios de ejemplo en el sitio**: se cambian en `tools/sitio/build.py`; en el producto viven en la consola.
- **Calculadora de planilla de referencia** en `referencia/calculadora-planilla/`: Laravel 12, sin tasas en el código: mes, prorrateo, quinta proyectada y liquidación de beneficios sociales, con 31 pruebas, más las correcciones al requerimiento de planillas.

## Qué cambió en la 1.10

Detalle en `kit/CAMBIOS-v1.10.md`.

- **`EMPEZAR-AQUI.md`** y **`backlog/ruta-mvp.md`**: decisiones previas, orden de trabajo por tandas paralelas, uso de Claude Code e importación en Jira.
- **Sitio comercial de referencia completo** en `docs/web/sitio/` (29 pantallas) con el mismo estilo del sistema y capturas reales del prototipo; cada historia WEB cita su pantalla.
- **Tipografía única** IBM Plex Sans / IBM Plex Mono en el sistema y en el sitio.

## Qué cambió en la 1.9

Detalle en `kit/CAMBIOS-v1.9.md`.

- **Productos de la web** (`docs/01` §4.1): el sitio gestiahr.com agrupa los módulos en cinco productos (Personas, Tiempo, Planilla, Talento y Formación) más la Suite, cada uno con su página. La página de precios es una sola y cada plan permite añadir módulos.
- **Épica WEB pendiente** (13 actividades, etiqueta `web`): sitio comercial con CMS, catálogo de productos, precios, registro y suscripción, demo, lista de espera, blog, SEO, chatbot, cookies, legales y sección «Sitio web» en la consola. Está separada del MVP y solo depende de él.
- **Etiqueta de producto** en todo el backlog (`producto-…`), para ver en Jira qué parte del sitio vende cada actividad.
- Decisiones nuevas D-39 a D-41. Diseño de referencia del sitio en `kit/docs/web/`.
- Backlog: 362 historias y 150 tareas; 241 actividades del MVP, 9 de la segunda ola y 13 del sitio.

## Qué cambió en la 1.8

Detalle en `kit/CAMBIOS-v1.8.md`.

- **Centro de ayuda en todos los roles** (épica AYU, MVP): botón de ayuda en cada pantalla, ayuda contextual, guías por rol, políticas de la empresa, buscador, valoración y derivación a RRHH o a soporte.
- **Asistente de ayuda con IA** (IAR-9 a IAR-11, MVP): responde con los artículos visibles para el usuario y cita la fuente. GestiaHR lo habilita por plan y el administrador lo enciende o apaga y elige roles y límite. La versión con datos del propio usuario (IAR-12) queda en la fase 8.
- Backlog: 352 historias y 147 tareas; 241 actividades del MVP y 9 de la segunda ola.

## Qué cambió en la 1.7

Detalle en `kit/CAMBIOS-v1.7.md`.

- **Nuevo nombre: GestiaHR**, con dominio `gestiahr.com`. Cambian también los roles de base (`gestia_app`, `gestia_platform`), la cookie, los prefijos de token y las cabeceras de webhook.
- **Cinco diferenciadores dentro del MVP**: tablero mínimo de obligaciones de planilla (CUM-9), novedades normativas visibles para el cliente (PAR-7), importadores desde otros sistemas (IMP-7), comparación boleta por boleta con el sistema anterior (PER-13) y explicación del cambio de neto en la boleta (BOL-5).
- **Segunda ola** (etiqueta `ola-2`): WhatsApp básico (WHA-1 a WHA-3), simulador público de costo laboral (PRV-5) y modo estudio contable (EST-1 a EST-5).
- Backlog: 343 historias y 145 tareas; 229 actividades del MVP y 9 de la segunda ola.

## Qué cambió en la 1.6

Detalle en `kit/CAMBIOS-v1.6.md`.

- **Primer corte comercial (MVP)** definido en `docs/01` §5.1 y marcado con la etiqueta `mvp` en 224 actividades del backlog: plataforma, Core HR, vacaciones y ausencias, y nómina Perú del régimen general y MYPE. El verificador comprueba que ninguna actividad mvp dependa de algo fuera del corte.
- **Estructura única**: `CLAUDE.md` y `.claude/commands/` en la raíz, el kit en `kit/`. Referencias rotas corregidas (`research.md`, `SPEC.md` y `prompt-original.md` apuntan a los documentos 00) y generadores ejecutables desde el kit.
- **Esquema**: claves UUID v7 (`uuid_v7()`) y seis tablas de registro particionadas por mes, con pruebas nuevas.
- **Backlog**: PER-12 (importar acumulados de planilla a mitad de año), QA-17 (salida del MVP con piloto y planilla en paralelo), facturación electrónica SUNAT en SAS-11 y códigos de permiso en inglés.
- **Parámetros legales**: UIT 2025 y RMV desde 2025 cotejadas con la norma, 27 tasas extendidas a 2025 y `docs/19` con los 39 parámetros por validar (32 del MVP).
- **Decisiones**: `docs/adr/` con plantilla, índice de las once decisiones de la fase 0 y propuestas para A-03/A-06 y D-29.

## Contenido

- **CLAUDE.md** (raíz) — 15 reglas no negociables, orden de trabajo MVP y archivos generados; **.claude/commands/** — cinco comandos.
- **kit/docs/00-…** — especificación maestra, pedido original (124 puntos), análisis de competencia (20 competidores y normativa peruana en §5) y trazabilidad.
- **kit/database/** — PostgreSQL 17 (carga también en 16): 495 tablas, 436 con RLS forzado, 7 particionadas por mes, 1568 claves foráneas (1030 compuestas con `tenant_id`), 22 tablas inalterables, claves UUID v7, búsqueda de texto completo del centro de ayuda, `seed_tenant_defaults()`, `pruebas_aislamiento.sql`, catálogos JSON (908 permisos, 11 roles, parámetros, 166 parámetros legales de Perú, conceptos de planilla, módulos y productos de la web).
- **kit/api/openapi.yaml** — OpenAPI 3.0.3 válido: 738 rutas, 998 operaciones, con `x-permission` y `x-phase`.
- **kit/docs/** — visión y fases (con el MVP), arquitectura, convenciones, modelo de datos, 49 máquinas de estado, 707 reglas de negocio, permisos y roles, API, integraciones, pantallas y UX, pruebas, seguridad y privacidad, 55 decisiones abiertas y su registro `adr/`, localización Perú (y su detalle técnico en `docs/18`), qué no copiar del prototipo, glosario, mapa del prototipo, parámetros legales por validar (`docs/19`), entrega al equipo (`docs/20`), 72 especificaciones de módulo y el diseño de referencia del sitio comercial (`docs/web/`).
- **kit/backlog/** — 72 épicas, 362 historias y 150 tareas técnicas con criterios de aceptación, referencias, dependencias y etiquetas `mvp`, `ola-2`, `web` y de producto, en CSV para Jira, Markdown y JSON. `GestiaHR_backlog_jira.csv` en la raíz es el mismo CSV.
- **kit/prototipo/** — HTML de un solo archivo y sus fuentes por módulo, con el centro de ayuda y el asistente simulado en todos los roles.
- **kit/prompts/** — un prompt por épica para avanzarla en una sesión nueva de Claude Code.
- **kit/referencia/** — `arranque/` (Docker, `Makefile` e integración continua) y `calculadora-planilla/` (núcleo de planilla en Laravel 12 con 46 pruebas).
- **kit/tools/** — generadores y verificadores del esquema, la API, los documentos funcionales, los parámetros por validar, el backlog, la entrega al equipo, el sitio y los diagramas.

## Qué se comprobó y qué no

- `schema.sql` cargó limpio en PostgreSQL 17.11 y en 16.15; `pruebas_aislamiento.sql` pasó completo, incluido el bloque de UUID v7 y particiones y las tablas nuevas del centro de ayuda; `seed_tenant_defaults` se ejecutó.
- El prototipo con el centro de ayuda se recorrió con Playwright con ocho roles, la consola y en 400 px, sin errores de JavaScript ni desborde.
- Todos los generadores corren desde el kit y reproducen exactamente los archivos publicados.
- `openapi.yaml` validó como OpenAPI 3.0.3.
- El backlog pasó su verificador: claves únicas, dependencias existentes, sin ciclos, sin dependencias hacia fases posteriores, 124 de 124 puntos del pedido cubiertos, sin estimaciones, MVP cerrado, segunda ola y sitio comercial dependientes solo del MVP.
- UIT 2025 (DS 260-2024-EF) y RMV desde el 1-1-2025 (DS 006-2024-TR) se cotejaron con fuentes públicas.
- Los 70 diagramas mermaid se leen sin error con mermaid 11; la calculadora de referencia pasa sus 46 pruebas en PostgreSQL 17.
- **No comprobado:** el asistente con un proveedor de IA real (el prototipo lo simula); la integración continua en GitHub (corre cuando exista el repositorio); los 40 parámetros legales sin valor y los 102 con fecha por confirmar (`docs/19`), que debe validar un especialista laboral.

## Fases (sin fechas)

| Fase | Épicas |
|---|---|
| 0 · Cimientos | PLT, TEN, SEG, SAS, AUD, UI, WFL, NOT, CFG, WEB (sitio, pendiente) |
| 1 · Core HR | ORG, POS, COL, HIS, CON, DOC, ESS, MSS, IMP, AYU |
| 2 · Tiempo | ASI, TUR, VAC, AUS, BHO, TSH |
| 3 · Nómina Perú | NOM, CPT, PAR, PER, BOL, BSO, PRE, DEC, PRV, LIQ, LOC |
| 4 · Talento | ATS, ENT, ONB, OFF, DES, OKR, CMP, FBK |
| 5 · Desarrollo y compensación | LMS, CAR, COM, BNF |
| 6 · Experiencia | ENC, REC, CIN, HSD |
| 7 · Operación y cumplimiento | SST, DEN, DIS, ACT, GAS, CUM, PRI, SIN |
| 8 · Inteligencia | ANA, RPT, ALE, IAR |
| 9 · Ecosistema y expansión | API, INT, WHA, PAI, WLB, EST |
| Transversal | QA |

**Primer corte comercial (MVP):** fases 0 y 1 completas, VAC y AUS, y NOM (sin construcción civil ni agrario), CPT, PAR, PER, BOL, BSO, DEC y LIQ, más CUM-9, el asistente de ayuda (IAR-1, IAR-2, IAR-9 a IAR-11), QA-1, QA-2 y QA-17. **Segunda ola:** WHA-1 a WHA-3, PRV-5 y EST. **Sitio comercial (pendiente, aparte):** WEB-1 a WEB-13.

## Decisiones tomadas en el kit (a confirmar)

- Stack igual al de los demás kits: Laravel 12 / PHP 8.4, PostgreSQL 17, Redis, Next.js 15.
- Multiempresa en esquema compartido con `tenant_id`, RLS y claves foráneas compuestas.
- Persona, usuario y relación laboral separados; puesto separado de posición; historia con vigencia.
- La plataforma SaaS (planes, módulos, flags), el motor de aprobaciones y las notificaciones pasan a la Fase 0.
- Planilla = motor + reglas + paquete de país + régimen laboral; toda tasa es un parámetro con vigencia. Asistencia, horas extra y préstamos entran por puertos.
- Identificadores UUID v7 y registros particionados por mes (ADR 0001, aceptado).
- Códigos de permiso en inglés con etiqueta traducida (ADR 0002, D-29, aceptado).
- Asistente de ayuda documental, apagado por defecto y con búsqueda de texto completo (propuesta ADR 0003, D-38).
