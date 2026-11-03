# M-API · API pública, webhooks y MCP

Fase 9 · Ecosistema y expansión · Épica `API` · Reglas `RN-API-nn` · Módulo comercial: API e integraciones (`integrations`)

## 1. Objetivo

Abrir la plataforma a integraciones y asistentes externos con una API versionada, eventos por webhook y un servidor MCP, con el mismo control de permisos.

## 2. Alcance

- API REST `/api/v1` documentada (OpenAPI): colaboradores, asistencia, ausencias, reclutamiento, planilla (lectura), estructura, documentos y demás recursos.
- Credenciales por aplicación con alcances (scopes), caducidad, rotación; OAuth para aplicaciones de terceros; límites de uso.
- Webhooks: suscripción a eventos (`employee.created`, `employee.updated`, `employee.terminated`, `leave.approved`, `payroll.closed`, `candidate.hired` y el resto del catálogo), firma, reintentos, registro de entregas.
- Servidor MCP que expone herramientas de consulta y acción con los permisos del usuario que autoriza.
- Portal de desarrolladores, entorno de pruebas y registro de llamadas.

## 3. Fuera de alcance

- Conectores concretos (M-INT).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §73 API; §74 Webhooks; §117 Diseño API-first.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-22 API pública y servidor MCP.

## 5. Actores y permisos

- Administrador gestiona credenciales. Desarrolladores e integradores consumen.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `ai.mcp.use` | Usar asistentes externos por MCP | Administrador empresa, RRHH, Payroll, Manager |
| `identity.api_tokens.view` | Ver credenciales de API y sus alcances | Administrador empresa, Auditor |
| `identity.api_tokens.create` | Crear credenciales de API y sus alcances | Administrador empresa |
| `identity.api_tokens.revoke` | Revocar credenciales de API y sus alcances | Administrador empresa |
| `integrations.webhooks.view` | Ver suscripciones de webhook | Administrador empresa, Auditor |
| `integrations.webhooks.create` | Crear suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.delete` | Eliminar suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.pause` | Pausar suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.resume` | Reanudar suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.rotate_secret` | Rotar el secreto de suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.test` | Probar suscripciones de webhook | Administrador empresa |
| `integrations.webhooks.update` | Editar suscripciones de webhook | Administrador empresa |
| `integrations.webhook_deliveries.view` | Ver entregas de webhook | Administrador empresa, Auditor |
| `integrations.webhook_deliveries.retry` | Reintentar entregas de webhook | Administrador empresa |
| `integrations.events.view` | Ver el catálogo de eventos | Administrador empresa, Auditor |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Credencial.** 1) El administrador crea una aplicación con alcances mínimos y caducidad. 2) Se muestra el secreto una sola vez. 3) Cada llamada se valida contra alcances, módulo contratado y límites.

**Webhook.** 1) Se registra la URL y los eventos. 2) Cada evento se envía firmado con identificador único. 3) Si falla, se reintenta con espera creciente. 4) Tras agotar intentos, queda «fallido» y se puede reenviar.

**MCP.** 1) El usuario autoriza al asistente externo. 2) El asistente llama herramientas. 3) Cada herramienta aplica permisos y alcance del usuario y queda en el registro de IA.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| API | Aplicaciones, alcances, caducidad, último uso; crear, rotar, revocar | Prototipo: Configuración → Integraciones y API → pestaña Tokens de API (incluye webhooks) |
| Webhooks | Suscripciones, estado, entregas, reenviar | Prototipo: Configuración → Integraciones y API → pestaña Tokens de API (incluye webhooks) |
| Registro de llamadas | Llamadas por aplicación, estado, latencia, errores | Prototipo: Configuración → API · Registro de llamadas |

## 8. Datos principales

Aplicaciones y credenciales (solo huella del secreto), alcances, suscripciones de webhook, entregas, autorizaciones MCP, registro de llamadas.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección API.

- **RN-API-01**: Si una llamada usa una credencial → solo accede a los recursos de sus alcances y nunca a más de lo que permite el módulo contratado.
- **RN-API-02**: Si un alcance no incluye campos sensibles → la respuesta los omite igual que para un usuario sin permiso de campo.
- **RN-API-03** (parámetro): Si la aplicación supera su límite de llamadas (parámetro del plan) → recibe respuesta de límite excedido con el tiempo de espera.
- **RN-API-04**: Si se envía un webhook → va firmado, con identificador de evento y sin datos sensibles en el cuerpo; el receptor consulta el detalle por API.
- **RN-API-05** (parámetro): Si la entrega de un webhook falla → se reintenta según la política y, agotados los intentos (parámetros), la suscripción se marca con error y se avisa.
- **RN-API-06**: Si se introduce un cambio incompatible → se publica en una nueva versión de la API; la anterior se mantiene durante el periodo de convivencia anunciado.
- **RN-API-07**: Si un asistente actúa por MCP → lo hace con los permisos del usuario que autorizó, y las acciones de alto impacto no están expuestas como herramientas.
- **RN-API-08**: Si una credencial no se usa durante el periodo configurado o su creador pierde el rol → se marca para revisión.
- **RN-API-09**: Si se crea una credencial → el secreto se muestra una única vez y se almacena solo su huella.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `api.credential_created`, `api.credential_revoked`, `webhook.delivery_failed`.

**Consume:**

- Todos los eventos de dominio publicables.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Receptor de webhook lento o caído durante días.
- Dos integraciones que escriben el mismo dato: gana la última con auditoría de origen.
- Token MCP de un usuario que cesa: se revoca.

## 14. Criterios de aceptación generales

- La API está versionada con tokens y alcances, los seis eventos del pedido se entregan por webhook y un asistente externo consulta por MCP sin superar los permisos del usuario.
