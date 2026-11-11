# M-AYU · Centro de ayuda

Fase 1 · Core HR · Épica `AYU` · Reglas `RN-AYU-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Que cualquier persona, en cualquier rol y pantalla, resuelva sola sus dudas sobre cómo usar GestiaHR y sobre las políticas de su empresa, y que derive a RRHH lo que la ayuda no resuelve.

## 2. Alcance

- Botón de ayuda en la barra superior de todas las pantallas y en el portal móvil, para los once roles de sistema y los roles personalizados.
- Ayuda del producto redactada y versionada por GestiaHR: guías por rol y módulo, ayuda contextual por pantalla, ayudas de campo, recorridos de primer ingreso y novedades de versión.
- Artículos propios de la organización (reglamento interno, políticas de vacaciones, permisos, gastos) con audiencia, responsable, versión y fecha de revisión.
- Buscador de texto completo sobre ambos contenidos, valoración «¿te sirvió?» y registro de búsquedas sin resultado.
- Derivación de la consulta a RRHH como solicitud y, para el administrador, al soporte de GestiaHR.
- Asistente de ayuda con IA (IAR-9 a IAR-11) dentro del centro de ayuda, que puede encenderse o apagarse (M-IAR).

## 3. Fuera de alcance

- Mesa de ayuda con colas y SLA (M-HSD): el centro de ayuda solo crea la solicitud. Respuestas con datos del propio usuario (IAR-12, fase 8).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §54 Base de conocimiento RRHH; §69 IA para RRHH; §85 Experiencia móvil.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Todos los roles consultan. Administrador y RRHH publican artículos de la organización y configuran el asistente. El superadministrador SaaS publica la ayuda del producto y atiende el soporte.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `help.articles.view` | Ver la ayuda del producto y de la organización | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Investigador de denuncias, Contador |
| `help.org_articles.view` | Ver los artículos de la organización | Administrador empresa, RRHH, Auditor |
| `help.org_articles.archive` | Archivar los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.create` | Crear los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.publish` | Publicar los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.update` | Editar los artículos de la organización | Administrador empresa, RRHH |
| `help.escalations.view` | Ver consultas derivadas desde la ayuda | Administrador empresa, RRHH; solo sobre lo propio o asignado: todos los roles de organización |
| `help.escalations.create` | Crear consultas derivadas desde la ayuda | solo sobre lo propio o asignado: todos los roles de organización |
| `help.escalations.support` **(+)** | Escribir al soporte de GestiaHR desde la ayuda | Administrador empresa |
| `help.insights.view` | Ver indicadores de uso de la ayuda | Administrador empresa, RRHH |
| `platform.help_articles.view` | Ver la ayuda del producto | Superadmin SaaS |
| `platform.help_articles.archive` | Archivar la ayuda del producto | Superadmin SaaS |
| `platform.help_articles.create` | Crear la ayuda del producto | Superadmin SaaS |
| `platform.help_articles.publish` | Publicar la ayuda del producto | Superadmin SaaS |
| `platform.help_articles.update` | Editar la ayuda del producto | Superadmin SaaS |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Consulta.** 1) El usuario pulsa el botón de ayuda en una pantalla. 2) El centro abre en «Esta pantalla» con sus artículos. 3) El usuario busca o pregunta al asistente si está habilitado. 4) Valora el artículo. 5) Si no se resolvió, deriva la consulta a RRHH con la pregunta y los artículos consultados.

**Publicación de la organización.** 1) RRHH redacta el artículo y elige audiencia, responsable y fecha de revisión. 2) Lo publica y queda versionado. 3) Al vencer la revisión, el responsable recibe un aviso.

**Ayuda del producto.** 1) GestiaHR redacta el artículo con módulo, roles y pantallas. 2) Lo publica en la consola con la versión del producto. 3) Se actualiza el índice de búsqueda y el asistente lo usa desde ese momento.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Centro de ayuda | Pestañas Esta pantalla, Guías, Asistente (si está habilitado) y Mis consultas; buscador, valoración y derivación | Prototipo: Barra superior → Ayuda |
| Ayuda y asistente | Pestañas Asistente (activación, roles, límite y aviso), Artículos de la organización y Consultas (indicadores y búsquedas sin resultado) | Prototipo: Configuración → Ayuda y asistente |
| Ayuda del producto | Artículos del producto por módulo y rol, versiones, publicación, búsquedas sin resultado y consultas de soporte de las organizaciones | Prototipo: Consola de la plataforma → Ayuda del producto |

## 8. Datos principales

Artículos del producto (`help_articles`, de plataforma, con versiones en `help_article_versions`), artículos de la organización (`kb_categories`, `kb_articles`, `kb_article_feedback`), valoraciones de artículos del producto (`help_article_feedback`), búsquedas registradas (`help_search_queries`), consultas derivadas (`help_escalations`) y recorridos vistos (`help_tour_progress`).

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección AYU.

- **RN-AYU-01**: Si un usuario abre la ayuda → solo ve artículos de módulos contratados y activos, dirigidos a su rol y de pantallas que puede abrir.
- **RN-AYU-02**: Si un artículo del producto se publica en una versión nueva → la anterior se conserva y el artículo indica la versión del producto a la que corresponde.
- **RN-AYU-03**: Si la organización publica un artículo propio → solo lo ve la audiencia elegida (roles, empresas o sedes) y lleva responsable, versión y fecha de revisión.
- **RN-AYU-04** (parámetro): Si un artículo propio supera su fecha de revisión (parámetro de anticipación) → se avisa al responsable y se marca «por revisar» sin ocultarse.
- **RN-AYU-05**: Si una búsqueda no da resultados → se registra el texto buscado, la pantalla y el rol, sin datos de la persona, para mejorar el contenido.
- **RN-AYU-06**: Si la ayuda no resuelve la duda → el usuario puede derivarla a RRHH como solicitud con la pregunta y los artículos consultados; solo el administrador puede escribir al soporte de GestiaHR.
- **RN-AYU-07**: Si el artículo no tiene traducción al idioma del usuario → se muestra en español con un aviso.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `help.article_published`, `help.search_without_result`, `help.escalated`.

**Consume:**

- `module.activated`, `module.deactivated` (cambian los artículos visibles).

## 12. Integraciones

Índice de búsqueda de texto completo de PostgreSQL en español; sin servicios externos.

## 13. Casos borde

- Organización con marca blanca: la ayuda del producto se muestra con la marca de la organización.
- Artículo propio que contradice la ayuda del producto: prevalece el de la organización para sus políticas y se indica la fuente.
- Usuario sin relación laboral (auditor externo, contador de un estudio): ve la ayuda de su rol y no puede derivar a RRHH.

## 14. Criterios de aceptación generales

- Los once roles de sistema abren la ayuda desde cualquier pantalla, ven solo artículos permitidos y pueden derivar una consulta; con el asistente apagado el centro de ayuda funciona igual.
