# GestiaHR · Cambios de la versión 1.9

Versión 1.9 · 05/11/2026. Deja establecido cómo se distribuye el producto en el sitio comercial gestiahr.com y agrega el sitio al backlog como trabajo **pendiente y separado del MVP**, para que desarrollo sepa desde ahora qué va en cada página. No cambia nada de lo construido en la 1.8: solo se suma. Los cambios anteriores siguen en `CAMBIOS-v1.8.md`.

## Productos de la web (`docs/01` §4.1)

El sitio no vende módulos sueltos: los agrupa en cinco productos, más la Suite. Lo que se activa en una organización siguen siendo los módulos de `docs/01` §4.

| Producto | Sigla | Ruta | Módulos | Al primer corte |
|---|---|---|---|---|
| Gestia Personas | HRIS | `/personas` | core | Disponible |
| Gestia Tiempo | WFM | `/tiempo` | time_off, attendance, timesheets | Vacaciones y ausencias; el resto en «Próximamente» |
| Gestia Planilla | PAY | `/planilla` | payroll, advances, contractors | Planilla; préstamos y locadores en «Próximamente» |
| Gestia Talento | TMS | `/talento` | recruiting, onboarding, performance, careers, compensation | «Próximamente» |
| Gestia Formación | LMS | `/formacion` | learning | «Próximamente» |
| Suite GestiaHR | HCM | `/suite` | los demás módulos | Por partes, según se publiquen |

- Cada producto lleva su sigla junto al nombre (por ejemplo «Gestia Tiempo · WFM») para distinguirlo y para que se reconozca la categoría del mercado.
- Un solo dominio, gestiahr.com, con una ruta por producto y una sola página de precios. La aplicación sigue en `app.gestiahr.com` y cada organización en `miempresa.gestiahr.com`.
- Personas no se vende suelto: es la base y viene en todos los planes. Tiempo, Desempeño y Formación sí se pueden contratar solos.
- Cada plan permite añadir módulos que no trae, con los complementos (`addons`) que ya existen en la consola.
- «Disponible» o «Próximamente» sale de lo que está publicado en la consola; nunca se escribe a mano.

## Épica nueva WEB · Sitio comercial gestiahr.com (fase 0, etiqueta `web`)

Trece actividades pendientes, fuera del MVP. Solo dependen del MVP o del propio sitio (lo comprueba el verificador del backlog), así que pueden construirse en paralelo o justo antes de abrir la venta.

| Clave | Actividad |
|---|---|
| WEB-1 | Aplicación del sitio comercial y su CMS |
| WEB-2 | Catálogo de productos ligado a los módulos |
| WEB-3 | Inicio, páginas de producto y página de la Suite |
| WEB-4 | Precios, comparador y selector de módulos |
| WEB-5 | Registro de prueba y suscripción en tres pasos |
| WEB-6 | Agendar demo, contacto y partners |
| WEB-7 | Lista de espera de lo que está en «Próximamente» |
| WEB-8 | Blog, boletín y comentarios moderados |
| WEB-9 | SEO técnico y páginas por módulo e industria |
| WEB-10 | Chatbot comercial del sitio |
| WEB-11 | Popups, banner de cookies y medición |
| WEB-12 | Páginas legales y libro de reclamaciones virtual |
| WEB-13 | Sección «Sitio web» de la consola |

La calculadora de costo laboral del sitio es PRV-5 (segunda ola). El dominio propio por organización sigue siendo WLB-2 (fase 9).

## Backlog

- **Etiqueta de producto** en cada épica y actividad: `producto-personas`, `producto-tiempo`, `producto-planilla`, `producto-talento`, `producto-formacion`, `producto-suite` o `producto-plataforma` (base, consola, sitio y calidad). En Jira: `labels = producto-tiempo`. El CSV pasa a cuatro columnas «Labels» (17 columnas en total).
- Totales: 72 épicas, 362 historias y 150 tareas técnicas (512 actividades); 241 del MVP, 9 de la segunda ola y 13 del sitio.

## Base de datos, API, permisos y reglas

- Tablas de plataforma nuevas: `web_products`, `web_product_modules` (sembradas desde `docs/01` §4.1), `web_leads`, `web_demo_bookings`, `web_signups`, `web_chat_sessions` y `web_complaints`. Páginas, blog, comentarios, popups y testimonios viven en el CMS del sitio, no en la base de las organizaciones.
- `database/catalogos/modulos.json` agrega la clave `productos`.
- Totales: 494 tablas, 436 de organización, 7 particionadas. `pruebas_aislamiento.sql` incluye las tablas nuevas en la lista cerrada de plataforma y pasa completo.
- API: 12 endpoints públicos `/public/*` del sitio (catálogo, RUC, subdominio, registro, leads, demo, chatbot y libro de reclamaciones) y los de la consola para productos, leads, demos, reclamos, embudo y ajustes. Totales: 738 rutas y 998 operaciones.
- Permisos de consola `platform.web_*` (908 permisos). Reglas RN-WEB-01 a RN-WEB-12 (707 reglas). Especificación en `docs/modulos/M-WEB.md`.

## Documentos

- `docs/01` §4.1: productos de la web.
- `docs/13`: D-39 (qué se publica al abrir la venta y si asistencia entra antes), D-40 (dominio propio en el lanzamiento) y D-41 (CMS del sitio).
- `docs/web/prompt-stitch-landing-gestiahr.md`: diseño de referencia del sitio para Stitch.
- `docs/17` §5: el sitio no está en el prototipo y remite al prompt de Stitch.
- `CLAUDE.md` y `README.md`: orden de trabajo con el sitio y conteos actualizados.

## Qué se comprobó

- Todos los generadores corren de nuevo y el kit se reproduce igual en dos pasadas.
- `schema.sql` carga en PostgreSQL 16 y `pruebas_aislamiento.sql` termina en «TODAS LAS PRUEBAS PASARON».
- `openapi.yaml` valida como OpenAPI 3.0.3.
- El verificador del backlog pasa completo, incluida la verificación nueva «el sitio comercial solo depende del MVP o del propio sitio».
