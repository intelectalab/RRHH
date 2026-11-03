# M-WHA · Autoservicio por WhatsApp

Fase 9 · Ecosistema y expansión · Épica `WHA` · Reglas `RN-WHA-nn` · Módulo comercial: WhatsApp (`whatsapp`)

## 1. Objetivo

Llevar el autoservicio al canal que el personal operativo ya usa, con la misma seguridad que el portal.

## 2. Alcance

- Vinculación del número con la persona mediante verificación.
- Consultas y trámites: boleta, saldo y solicitud de vacaciones, constancias, marcación con ubicación, horario, estado de solicitudes, preguntas a la base de conocimiento, confirmación de lectura.
- Menú guiado y lenguaje natural; derivación a un agente de la mesa de ayuda.
- Avisos salientes con plantillas aprobadas.

## 3. Fuera de alcance

- Trámites que exigen firma con evidencia completa (se deriva al portal con enlace). Datos de salud, sanciones o denuncias.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-11 Autoservicio por WhatsApp.

## 5. Actores y permisos

- Colaboradores. Agentes de RRHH para derivaciones. Administrador configura.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `integrations.whatsapp.view` | Ver el canal de WhatsApp | Administrador empresa, RRHH |
| `integrations.whatsapp.update` | Editar el canal de WhatsApp | Administrador empresa, RRHH |
| `integrations.whatsapp.use` **(+)** | Usar el autoservicio por WhatsApp | Empleado, Manager |
| `integrations.whatsapp.view_conversations` **(+)** | Ver conversaciones derivadas a un agente | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Vinculación.** 1) El colaborador escribe al número de la empresa. 2) Se identifica con su documento. 3) Recibe un OTP por otro medio o confirma un dato. 4) El número queda vinculado.

**Boleta.** 1) Pide «mi boleta». 2) El sistema confirma identidad reciente. 3) Envía un enlace de un solo uso al portal o el documento protegido, según política. 4) Registra la entrega.

**Vacaciones.** 1) Pide saldo. 2) Indica fechas. 3) El sistema valida con las mismas reglas del portal. 4) Crea la solicitud y avisa del resultado.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Configuración de WhatsApp | Número, menús, plantillas, política de entrega de documentos | Prototipo: Configuración → Integraciones · WhatsApp |
| Conversaciones | Derivadas a agentes, con contexto | Prototipo: Experiencia → Solicitudes por WhatsApp |

## 8. Datos principales

Vinculaciones número–persona, sesiones de conversación, mensajes (metadatos y contenido no sensible), plantillas.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección WHA.

- **RN-WHA-01**: Si un número no está vinculado y verificado → no recibe ningún dato personal.
- **RN-WHA-02** (parámetro): Si la última verificación de identidad supera el tiempo configurado (parámetro) → se pide de nuevo antes de entregar documentos o crear trámites.
- **RN-WHA-03** (parámetro): Si se entrega una boleta o constancia → se hace por enlace de un solo uso con caducidad o por documento protegido, según la política (parámetro); nunca montos en texto del chat.
- **RN-WHA-04**: Si el trámite se crea por WhatsApp → pasa por las mismas validaciones, aprobaciones y auditoría que por el portal, con origen «WhatsApp».
- **RN-WHA-05**: Si la marcación llega por WhatsApp → exige ubicación compartida y sigue las reglas de geocerca.
- **RN-WHA-06**: Si el colaborador cesa o cambia de número → la vinculación se revoca.
- **RN-WHA-07**: Si la consulta no se entiende o es sensible → se deriva a un agente o al portal.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `whatsapp.linked`, `whatsapp.unlinked`, `whatsapp.handoff_requested`.

**Consume:**

- Avisos de M-NOT; `employee.terminated`.

## 12. Integraciones

Proveedor de WhatsApp Business tras el adaptador de mensajería.

## 13. Casos borde

- Número compartido por dos colaboradores.
- Mensajes fuera de la ventana de conversación del proveedor.
- Pérdida o robo del teléfono: desvinculación desde el portal o por RRHH.

## 14. Criterios de aceptación generales

- Un colaborador consulta boletas, pide vacaciones, obtiene constancias y marca asistencia por WhatsApp con identidad verificada.
