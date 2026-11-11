# GestiaHR — kit de desarrollo

Material para que un equipo construya GestiaHR con Claude Code: reglas, arquitectura, modelo de datos, contrato de API, backlog y prompts. GestiaHR es un SaaS integral de Recursos Humanos multiempresa, que nace para Perú y se extiende a Latinoamérica.

El kit no contiene precios, costos, horas, fechas ni tamaño de equipo: eso lo define el área de desarrollo al planificar.

## Cómo usar el kit

> **Primera vez:** lee [`EMPEZAR-AQUI.md`](EMPEZAR-AQUI.md) y [`docs/20-entrega-al-equipo.md`](docs/20-entrega-al-equipo.md), y sigue el orden de [`backlog/ruta-mvp.md`](backlog/ruta-mvp.md). Para avanzar una épica completa, usa su prompt en [`prompts/`](prompts/README.md).

1. **Descomprime el zip en la raíz del repositorio** del producto. Queda así: `CLAUDE.md` y `.claude/commands/` en la raíz (Claude Code los carga desde ahí) y este kit en la carpeta `kit/`. Las rutas de `CLAUDE.md`, de los comandos y de `PROMPTS.md` son relativas a la raíz (`kit/docs/…`), y los generadores de `kit/tools/` asumen esa estructura. No muevas `kit/` a la raíz.
2. **Las decisiones que bloqueaban la fase 0 están cerradas** desde la v1.11 (A-01, A-02, A-03, A-04, A-08, D-01, D-03, D-04, D-08, D-13, D-29); en la v1.12 se cerró también D-02, el nombre y la marca (ADR 0014), con su ADR en [`docs/adr/`](docs/adr/). Tres tienen un dato pendiente que no frena el desarrollo: el visto bueno legal de A-08, el proveedor de facturación de D-04 y los plazos de D-13. Las demás decisiones de [`docs/13-decisiones-abiertas.md`](docs/13-decisiones-abiertas.md) se cierran antes de la fase que indican.
3. **Lee en este orden** la primera vez: [`01`](docs/01-vision-y-fases.md) → [`02`](docs/02-arquitectura.md) → [`04`](docs/04-modelo-de-datos.md) → [`03`](docs/03-convenciones.md) → [`15`](docs/15-que-no-copiar-del-prototipo.md).
4. **Trabaja historia por historia** con el backlog ([`backlog/backlog.md`](backlog/backlog.md)), en el orden de fases y empezando por el primer corte comercial (actividades marcadas «· MVP», etiqueta `mvp` en Jira) y siguiendo con la segunda ola (`ola-2`); el sitio comercial (`web`) va aparte y solo depende del MVP; ver [`docs/01`](docs/01-vision-y-fases.md) §5.1 y §5.2:

   ```
   /historia PLT-1
   ```

   El comando hace que Claude Code lea la historia, la especificación del módulo, las tablas y las rutas, implemente en el orden migración → dominio → caso de uso → endpoint → pruebas → pantalla, y termine con las pruebas en verde.
5. **Revisa cada cambio** con `/revisar` antes de integrarlo.
6. **Mantén vivo el contrato**: la API se edita en [`tools/gen_openapi.py`](tools/gen_openapi.py) y se regenera desde la raíz con `python3 kit/tools/gen_openapi.py`. El modelo de datos se edita en [`tools/esquema/`](tools/esquema/LEEME.md) (los `.dsl` y `catalogos.py`) y se regenera `database/schema.sql`, los catálogos, `docs/04` y `docs/19`; el backlog se edita en `tools/fuente/` y se regenera con `python3 kit/tools/generar_backlog.py`. Ninguno de esos archivos generados se edita a mano.

### Comandos de Claude Code

| Comando | Para |
|---|---|
| [`/historia <código>`](../.claude/commands/historia.md) | Implementar una historia del backlog de punta a punta |
| [`/migracion <qué>`](../.claude/commands/migracion.md) | Crear una migración con aislamiento, vigencia e índices |
| [`/endpoint <MÉTODO /ruta>`](../.claude/commands/endpoint.md) | Crear un endpoint según el contrato |
| [`/pantalla <nombre>`](../.claude/commands/pantalla.md) | Construir una pantalla a partir del prototipo |
| [`/revisar [rango]`](../.claude/commands/revisar.md) | Revisar un cambio contra las reglas no negociables |

Los mismos prompts en versión larga, más los de concepto de planilla, paquete de país, adaptador y evento, están en [`PROMPTS.md`](PROMPTS.md).

## Índice

### Raíz del repositorio y del kit

| Archivo | Contenido |
|---|---|
| [`CLAUDE.md`](../CLAUDE.md) (en la raíz) | Qué es el producto, estructura del monorepo, comandos y las 15 reglas no negociables con su comprobación |
| [`EMPEZAR-AQUI.md`](EMPEZAR-AQUI.md) | Guía de arranque para el área de desarrollo |
| [`backlog/ruta-mvp.md`](backlog/ruta-mvp.md) | Orden de trabajo en tandas paralelas (generado por `tools/gen_ruta.py`) |
| [`PROMPTS.md`](PROMPTS.md) | Prompts listos para Claude Code |
| [`prompts/`](prompts/README.md) | Un prompt por épica, con sus actividades en orden, dependencias y decisiones (generado por `tools/gen_entrega.py`) |
| [`referencia/arranque/`](referencia/arranque/README.md) | Docker, `Makefile` e integración continua del kit para la raíz del repositorio, comprobados con PostgreSQL 17 |
| [`.claude/commands/`](../.claude/commands/) (en la raíz) | Cinco comandos |

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
| 13 | [Decisiones abiertas](docs/13-decisiones-abiertas.md) | 55 decisiones (A-01 a A-14, D-01 a D-41) con opciones, recomendación y fase límite |
| 14 | [Localización Perú](docs/14-localizacion-peru.md) | Norma peruana, regímenes, conceptos y archivos (resumen; el detalle técnico está en el 18) |
| 15 | [Qué no copiar del prototipo](docs/15-que-no-copiar-del-prototipo.md) | Qué se toma y qué no del prototipo |
| 16 | [Glosario](docs/16-glosario.md) | Términos de RRHH, planilla peruana y del producto |
| 17 | [Mapa del prototipo](docs/17-mapa-del-prototipo.md) | Pantallas del prototipo, su módulo, épica y qué es real o simulado |
| 18 | [Localización Perú: detalle técnico](docs/18-localizacion-peru-detalle-tecnico.md) | Ficha T-Registro, motor de quinta categoría con casos resueltos, archivos PLAME, T-Registro y AFPnet, regímenes especiales, flujos de cumplimiento y libro inalterable; estado de verificación de cada dato y fuentes |
| 19 | [Parámetros legales por validar](docs/19-parametros-legales-por-validar.md) | Lista de trabajo para el especialista laboral: parámetros sin valor verificado, cuáles bloquean el MVP y cuáles solo necesitan confirmar la fecha. Generado |
| 20 | [Entrega al equipo](docs/20-entrega-al-equipo.md) | Qué recibe el equipo, primer día, cómo repartir el trabajo con IA, definición de hecho, estado por módulo y decisiones que tocan el MVP. Generado |
| — | [Sitio comercial](docs/web/) | Diseño de referencia navegable de gestiahr.com en [`docs/web/sitio/`](docs/web/sitio/portada.html) (47 pantallas con su SEO, textos legales completos, seis artículos del blog, `sitemap.xml` y `robots.txt`, mismos tokens que el sistema) y el prompt de Stitch original; base de la épica WEB |
| — | [Registro de decisiones](docs/adr/) | Plantilla de ADR, índice y estado de las decisiones que bloquean la fase 0 |
| 00 | [Especificación maestra](docs/00-especificacion-maestra.md), [pedido original](docs/00-pedido-original.md), [análisis de competencia](docs/00-analisis-de-competencia.md) y [trazabilidad](docs/00-trazabilidad.md) | Fuentes del kit: correcciones y ampliaciones al pedido, investigación de mercado y normativa (§5), y los 124 puntos del pedido mapeados a épicas |
| — | [Calculadora de planilla de referencia](referencia/calculadora-planilla/) | Núcleo de la calculadora en Laravel 12 (mes, prorrateo, quinta, liquidación con indemnizaciones, agrario y construcción civil), con parámetros con vigencia, pasos extensibles y pruebas; incluye las correcciones al requerimiento del 05/11/2026 |
| — | [Especificaciones por módulo](docs/modulos/) | Una por módulo: alcance, pantallas, reglas, endpoints, eventos, pruebas |

Los documentos 04, 05, 06, 07, 00-trazabilidad, 19 y 20, los prompts de `prompts/`, las especificaciones de `docs/modulos/`, `database/`, `api/openapi.yaml` y `backlog/` se generan con `tools/`; los demás se editan a mano.

### Contrato, datos y herramientas

| Ruta | Contenido |
|---|---|
| [`api/openapi.yaml`](api/openapi.yaml) | OpenAPI 3.0.3 con `x-permission` y `x-phase` por operación. Generado; no editar a mano. Es grande: búscalo por ruta |
| [`tools/gen_openapi.py`](tools/gen_openapi.py) | Generador y validador del contrato y de la tabla de endpoints de `docs/08-api.md` |
| [`database/schema.sql`](database/schema.sql) | Esquema de referencia (generado por `tools/esquema/gen.py`) |
| [`database/pruebas_aislamiento.sql`](database/pruebas_aislamiento.sql) | Pruebas de aislamiento, registros inalterables, libro de cumplimiento, UUID v7 y particiones |
| [`backlog/`](backlog/) | Backlog en Markdown, CSV (Jira) y JSON |

## Reglas que no se negocian (resumen)

Aislamiento por organización · historia con vigencia · estados solo por acciones · toda regla legal es un parámetro · auditoría de solo inserción · permisos por campo y alcance · accesos sensibles registrados · adaptadores + outbox · idempotencia · el backend valida lo que la interfaz oculta · sin lógica legal en el frontend · i18n, dinero decimal y fechas con zona · la IA asiste, no decide · asistencia inalterable y anonimato real · pruebas obligatorias y contrato primero. Detalle y comprobación en [`CLAUDE.md`](../CLAUDE.md).

## Qué se comprobó

| Comprobación | Resultado |
|---|---|
| `api/openapi.yaml` validado con `openapi-spec-validator` (OpenAPI 3.0.3) | Válido: 738 rutas, 998 operaciones, 313 esquemas |
| Toda operación tiene `x-permission` y `x-phase` | Sí (lo afirma el generador; falla si falta) |
| Todas las épicas con API tienen al menos un endpoint | 70 épicas cubiertas |
| `operationId` únicos | Sí (lo afirma el generador) |
| Tabla de endpoints de `docs/08-api.md` | Generada desde el mismo origen que el YAML |
| Tokens de diseño de `docs/10` | Copiados del `:root` de `core.html` (claro y oscuro) |
| Códigos de decisión citados en los documentos | Todos existen en `docs/13` |
| Ausencia de precios, costos, horas, fechas de entrega y tamaño de equipo | Revisado en los documentos de este autor |
| Tasas legales | No se inventó ninguna; las citadas remiten a `docs/00-analisis-de-competencia.md` §5 y a `docs/18` (con su estado de verificación y fuente) y son parámetros con vigencia |
| `database/schema.sql` cargado en PostgreSQL 17.11 limpio (y en 16.15) | 495 tablas (436 de organización con RLS forzado, 59 de plataforma), 7 de ellas particionadas por mes, 1 568 claves foráneas, 22 tablas inalterables, claves primarias UUID v7 |
| `database/pruebas_aislamiento.sql` | «TODAS LAS PRUEBAS PASARON», incluidas el libro de cumplimiento encadenado (8.5), UUID v7 y particiones con RLS sin acceso directo (9) |
| Catálogos | 908 permisos, 166 parámetros legales de Perú (126 con valor, 40 por validar en `docs/19`), historial 2025 de UIT y RMV cotejado con la norma, 62 conceptos de planilla (45 códigos cotejados con la Tabla 22) |
| Generadores | `tools/esquema/gen.py`, `gen_docs.py`, `gen_pendientes.py`, `tools/gen_openapi.py` y `tools/generar_backlog.py` corren desde el kit sin archivos externos y reproducen los archivos publicados |
| Backlog | 72 épicas, 362 historias y 150 tareas; verificador en verde, incluido «el MVP no depende de nada fuera del MVP» (241 actividades `mvp`), «la segunda ola solo depende del MVP» (9 actividades `ola-2`) y «el sitio comercial solo depende del MVP» (13 actividades `web`) |
| Diagramas mermaid | Los 70 de `docs/` se leen sin error con mermaid 11 (`tools/verificar_mermaid.js`) |
| Documentos funcionales | 49 máquinas de estado conciliadas con el esquema, 707 reglas de negocio |

Lo que **no** se comprobó y queda a cargo del equipo:

- Los nombres de tabla de 02, 03, 09, 12 y 15 se cotejaron con el esquema en la v1.6; si en el futuro difieren, manda el esquema.
- El prototipo conserva códigos de permiso en español: es solo referencia de navegación (D-29, `docs/adr/0002`).
- Los comandos `make` de `CLAUDE.md` son el contrato esperado del `Makefile`; se crean en las historias PLT.
- La normativa peruana procede de `docs/00-analisis-de-competencia.md` §5, que cita varias fuentes secundarias y marca datos sin verificar: validar con el área legal (D-15) antes de usar cualquier valor en producción.
