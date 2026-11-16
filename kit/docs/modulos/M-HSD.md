# M-HSD · Mesa de ayuda y base de conocimiento

Fase 6 · Experiencia · Épica `HSD` · Reglas `RN-HSD-nn` · Módulo comercial: Mesa de ayuda (`helpdesk`)

## 1. Objetivo

Atender las consultas y pedidos a RRHH con orden, plazos y respuestas reutilizables.

## 2. Alcance

- Solicitudes (tickets) por categoría: certificado, planilla, vacaciones, beneficios, documentos, consulta, otro; formularios por categoría.
- Estados nuevo, en atención, esperando información, resuelto, cerrado; asignación por reglas, colas y prioridad.
- SLA configurable por categoría y prioridad con calendario laboral; alertas y escalamiento.
- Conversación con el solicitante, notas internas, adjuntos, plantillas de respuesta, encuesta de satisfacción.
- Base de conocimiento: artículos, categorías, buscador, sugerencia de artículos al crear una solicitud, artículos relacionados con solicitudes.
- Categorías confidenciales; asistente de IA que responde con la base de conocimiento.

## 3. Fuera de alcance

- Denuncias (M-DEN). Soporte técnico del SaaS (M-SAS).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §53 Mesa de ayuda RRHH; §54 Base de conocimiento RRHH.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Empleado crea. Agentes de RRHH y Payroll atienden. Administrador configura.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `help.org_articles.view` | Ver los artículos de la organización | Administrador empresa, RRHH, Auditor |
| `help.org_articles.archive` | Archivar los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.create` | Crear los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.publish` | Publicar los artículos de la organización | Administrador empresa, RRHH |
| `help.org_articles.update` | Editar los artículos de la organización | Administrador empresa, RRHH |
| `helpdesk.tickets.view` | Ver solicitudes a RRHH | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `helpdesk.tickets.assign` | Asignar solicitudes a RRHH | Administrador empresa, RRHH |
| `helpdesk.tickets.close` | Cerrar solicitudes a RRHH | Administrador empresa, RRHH, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `helpdesk.tickets.create` | Crear solicitudes a RRHH | solo sobre lo propio o asignado: todos los roles de organización |
| `helpdesk.tickets.reopen` | Reabrir solicitudes a RRHH | solo sobre lo propio o asignado: todos los roles de organización |
| `helpdesk.tickets.request_info` | Pedir información en solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `helpdesk.tickets.resolve` | Resolver solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `helpdesk.tickets.update` | Editar solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `helpdesk.settings.update` | Configurar los tipos de solicitud a RRHH (y, con la mesa de ayuda, colas, SLA y plantillas); desde ESS-5 forma parte de core | Administrador empresa, RRHH |
| `helpdesk.tickets.confidential.view` **(S)** **(+)** | Ver solicitudes de categorías confidenciales | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitud.** 1) El colaborador elige categoría; el sistema sugiere artículos. 2) Si no resuelve, envía el formulario. 3) Se asigna a una cola y a un agente. 4) El agente responde, pide información («Esperando información» pausa el plazo) o resuelve. 5) El solicitante confirma o reabre. 6) Se cierra y se pide valoración.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Solicitudes | Bandeja por cola con estado, prioridad, plazo y vencimiento; filtros | Prototipo: Experiencia → Mesa de ayuda |
| Detalle de solicitud | Conversación, notas internas, datos del solicitante permitidos, artículos relacionados, acciones | Prototipo: Experiencia → Mesa de ayuda |
| Mis solicitudes | Solicitudes propias y nueva solicitud con sugerencias | Prototipo: Experiencia → Mesa de ayuda |
| Base de conocimiento | Artículos por categoría, buscador, utilidad | Prototipo: Experiencia → Mesa de ayuda |
| Configuración de mesa de ayuda | Categorías, colas, reglas de asignación, SLA, plantillas | Prototipo: Experiencia → Mesa de ayuda |

## 8. Datos principales

Solicitudes (`tickets`), mensajes, notas internas, categorías, colas, políticas de SLA, artículos (`kb_articles`, compartidos con el centro de ayuda de M-AYU) y versiones, valoraciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección HSD.

- **RN-HSD-01** (parámetro): Si se crea una solicitud → su plazo de primera respuesta y de resolución se calcula con el SLA de su categoría y prioridad sobre el calendario laboral de la empresa (parámetros).
- **RN-HSD-02**: Si la solicitud está «Esperando información» → el reloj del SLA se detiene y se reanuda al responder el solicitante.
- **RN-HSD-03** (parámetro): Si el solicitante no responde en el plazo configurado → la solicitud se cierra automáticamente con aviso (parámetro).
- **RN-HSD-04** (parámetro): Si un plazo está por vencer o vence → se alerta al agente y se escala según la regla de la cola.
- **RN-HSD-05**: Si la categoría es confidencial → solo la ven el solicitante y los agentes con el permiso; no aparece en búsquedas ni reportes generales.
- **RN-HSD-06** (parámetro): Si una solicitud resuelta no se reabre dentro del plazo (parámetro) → pasa a «Cerrado» y ya no admite reapertura; se crea una nueva enlazada.
- **RN-HSD-07**: Si un agente es el propio solicitante → no puede atender su solicitud.
- **RN-HSD-08**: Si un artículo se publica → tiene versión, autor y fecha de revisión; los artículos vencidos se marcan «por revisar».
- **RN-HSD-09**: Si la IA responde una consulta → cita el artículo fuente y ofrece crear una solicitud; no inventa políticas ni datos personales.
- **RN-HSD-10**: Si la solicitud pide un certificado de emisión automática → se genera el documento (M-DOC) y la solicitud se resuelve sola.

## 10. Estados

- ME-31 · Solicitud a RRHH (ticket) (`05-maquinas-de-estado.md`): estados Nuevo, En atención, Esperando información, Resuelto, Cerrado.

## 11. Eventos

**Emite:**

- `ticket.created`, `ticket.assigned`, `ticket.waiting_info`, `ticket.resolved`, `ticket.reopened`, `ticket.closed`, `ticket.sla_breached`, `kb_article.published`.

**Consume:**

- `document.generated`, `approval.approved`.

## 12. Integraciones

WhatsApp y correo como canales de entrada (M-WHA, M-NOT).

## 13. Casos borde

- Solicitud creada por un ex-colaborador.
- Cambio de categoría que cambia el SLA.
- Solicitud que en realidad es una denuncia: el agente la deriva al canal con consentimiento del solicitante y la retira de la mesa.

## 14. Criterios de aceptación generales

- Las solicitudes recorren los cinco estados con SLA configurable y la base de conocimiento se relaciona con ellas.
