# GestiaHR · Cambios de la versión 1.8

Versión 1.8 · 04/11/2026. Agrega un **centro de ayuda para todos los roles** y un **asistente de ayuda con IA** que el administrador enciende o apaga. Ambos entran al MVP; el asistente, en su versión documental. Los cambios de la 1.7 (incluido el cambio de nombre a GestiaHR) siguen en `CAMBIOS-v1.7.md`.

## Qué se recomendó y se aplicó

1. **Centro de ayuda sin IA, en el MVP** (épica nueva AYU, fase 1, módulo `core`): botón de ayuda en todas las pantallas y roles, ayuda contextual por pantalla, guías por rol y módulo, políticas propias de la organización, buscador de texto completo, valoración «¿te sirvió?», derivación a RRHH (y al soporte de GestiaHR para el administrador), recorridos de primer ingreso y novedades de versión.
2. **Asistente de ayuda documental, en el MVP** (IAR-9): responde solo con los artículos que el usuario puede ver, cita la fuente, dice cuando no sabe y ofrece derivar. No lee datos de personas ni ejecuta acciones.
3. **Activación en tres niveles** (IAR-10): GestiaHR lo habilita por plan (función `ayuda.asistente_ia`), el administrador lo enciende o apaga y elige roles, límite de preguntas y aviso; el usuario lo ve solo si su rol está habilitado. Apagado, desaparece y la API lo rechaza; el centro de ayuda sigue funcionando. Nace apagado (D-38, ADR 0003).
4. **Calidad medida** (IAR-11): preguntas de referencia por rol en integración continua y tablero de preguntas sin respuesta.
5. **Fase 8** (IAR-12): el asistente responde con los datos del propio usuario (saldo de vacaciones, última boleta) con sus permisos, como caso de uso aparte.

## Backlog

| Clave | Actividad | Corte |
|---|---|---|
| AYU-1 | Contenido de ayuda del producto con versiones | MVP |
| AYU-2 | Centro de ayuda para todos los roles | MVP |
| AYU-3 | Ayuda contextual por pantalla | MVP |
| AYU-4 | Artículos y políticas de la organización | MVP |
| AYU-5 | Buscador de ayuda y valoración de artículos | MVP |
| AYU-6 | Derivar la consulta a RRHH o a soporte | MVP |
| AYU-7 | Recorridos guiados y novedades de versión | MVP |
| IAR-9 | Asistente de ayuda documental | MVP |
| IAR-10 | Activación del asistente de ayuda desde la administración | MVP |
| IAR-11 | Calidad de las respuestas del asistente de ayuda | MVP |
| IAR-12 | Asistente de ayuda con los datos del propio usuario | Fase 8 |

También entran al MVP IAR-1 (capa de proveedor de IA) e IAR-2 (registro de uso), que el asistente necesita. HSD-5 ahora amplía los artículos de AYU-4 en lugar de crear otro repositorio, y QA-15 publica su material en el centro de ayuda.

Totales: 71 épicas, 352 historias y 147 tareas técnicas (499 actividades); 241 del MVP y 9 de la segunda ola.

## Esquema

- Nuevas tablas de AYU: `help_articles` y `help_article_versions` (plataforma, legibles por la aplicación), `help_article_feedback`, `kb_article_versions`, `help_search_queries` (particionada por mes, sin identificar a la persona), `help_escalations` y `help_tour_progress`.
- `kb_categories`, `kb_articles` y `kb_article_feedback` pasan de HSD a AYU; `kb_articles` suma responsable, fecha de revisión, versión y el estado `in_review`.
- Búsqueda: columna generada `search_vector` (español) e índices GIN en `help_articles` y `kb_articles`.
- IAR: `ai_use_case_settings` y `ai_use_case_roles` (encendido, roles, límite y aviso por caso de uso).
- Totales: 487 tablas, 436 de organización, 7 particionadas. `pruebas_aislamiento.sql` incluye las dos tablas de plataforma nuevas en su lista cerrada.

## API y permisos

- 20 rutas nuevas (`/help/*`, `/platform/help-*`, `/ai/help-assistant/*`, `/ai/use-case-settings`). `/kb-articles` pasa a `/help/org-articles`.
- Permisos nuevos: `help.articles.view` (los once roles), `help.org_articles.*` (administrador y RRHH), `help.escalations.*`, `help.insights.view`, `platform.help_articles.*`, `ai.use_case_settings.*` y `ai.help_assistant.use`. `helpdesk.articles.*` se reemplaza por `help.org_articles.*`. Total: 890 permisos.
- Reglas: RN-AYU-01 a RN-AYU-07 y RN-IAR-11 a RN-IAR-16 (695 reglas).

## Documentos

- `docs/01`: AYU en el módulo `core`, el asistente de ayuda como excepción dentro de `ai` y el MVP ampliado.
- `docs/10` §4.1, `docs/12` §13, `docs/17` (pantallas nuevas), `docs/modulos/M-AYU.md`, D-38 en `docs/13` y `docs/adr/0003-asistente-de-ayuda.md`.

## Prototipo

- `fuentes/mod-9-ayuda.js`, ya incluido en `GestiaHR_prototipo.html`:
  - Botón de ayuda en la cabecera.
  - Centro de ayuda con cuatro pestañas.
  - 22 artículos del producto y 5 políticas de la organización demo.
  - Asistente simulado: busca con reglas escritas y cita la fuente.
  - Derivación a la mesa de ayuda.
  - Configuración → Ayuda y asistente.
  - Pestaña «Ayuda del producto» en la consola.
  - Función `ayuda.asistente_ia` en Consola → Funciones, encendida para los planes Profesional y Corporativo. La organización demo con plan Esencial ve el aviso de que su plan no la incluye.
- Probado con Playwright con ocho roles, la consola y en 400 px, sin errores de JavaScript ni desborde horizontal.
