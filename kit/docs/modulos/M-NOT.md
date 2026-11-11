# M-NOT · Notificaciones y mensajería

Fase 0 · Cimientos · Épica `NOT` · Reglas `RN-NOT-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Avisar a cada persona por el canal adecuado, sin saturarla, y dar a los módulos una única capa de mensajería independiente del proveedor.

## 2. Alcance

- Centro de notificaciones en la aplicación; canales correo, push, SMS y WhatsApp tras adaptadores.
- Plantillas por evento, idioma y canal, con variables; marca de la organización.
- Preferencias por usuario y por tipo; resúmenes agrupados; horarios de silencio.
- Registro de entregas (enviado, entregado, leído, fallido) y reintentos.
- Calendario central de RRHH (vacaciones, ausencias, cumpleaños, aniversarios, contratos, capacitaciones, evaluaciones, entrevistas, eventos) con filtros.

## 3. Fuera de alcance

- Comunicados y noticias (M-CIN). Autoservicio conversacional por WhatsApp (M-WHA).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §78 Calendario RRHH; §86 Notificaciones.

**Correcciones que incorpora:** C-04 La plataforma SaaS va en los cimientos; C-09 Acceso sin correo corporativo.

**Ampliaciones que incorpora:** E-11 Autoservicio por WhatsApp.

## 5. Actores y permisos

- Todos reciben. Administrador y RRHH configuran plantillas y reglas.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `notifications.templates.view` | Ver plantillas de mensajes | Administrador empresa, RRHH |
| `notifications.templates.create` | Crear plantillas de mensajes | Administrador empresa, RRHH |
| `notifications.templates.update` | Editar plantillas de mensajes | Administrador empresa, RRHH |
| `notifications.deliveries.view` **(+)** | Ver entregas y fallos de mensajes | Administrador empresa, RRHH, Auditor |
| `notifications.channels.update` **(+)** | Configurar canales, remitentes y horarios de silencio | Administrador empresa |
| `time.calendar.view` | Ver el calendario central de RRHH | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, SST |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Envío.** 1) Un evento llega al servicio. 2) Se resuelven destinatarios con permisos vigentes. 3) Se aplican preferencias, agrupación y silencio. 4) Se renderiza la plantilla en el idioma del destinatario. 5) Se entrega por adaptador y se registra el resultado.

**Preferencias.** 1) El usuario elige por tipo de aviso qué canales acepta. 2) Los avisos obligatorios (firma requerida, seguridad de cuenta, obligaciones legales) no se pueden desactivar en la aplicación.

**Calendario.** 1) Cada módulo publica sus fechas. 2) El calendario las muestra filtradas por alcance y tipo. 3) El usuario puede suscribirse desde su calendario externo (M-INT).

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Centro de notificaciones | Lista con no leídas, marcar leídas, ir al registro | Prototipo: Pendientes → Notificaciones (incluye la bandeja de envíos simulados) |
| Preferencias | Matriz tipo de aviso × canal, silencio, resumen diario | Prototipo: Menú de usuario → Mi cuenta → pestaña Notificaciones |
| Plantillas | Lista por evento; editor con variables y vista previa por idioma y canal | Prototipo: Pendientes → Notificaciones (incluye la bandeja de envíos simulados) |
| Registro de entregas | Mensajes enviados con estado y error; reenviar | Prototipo: Pendientes → Notificaciones (incluye la bandeja de envíos simulados) |
| Calendario RRHH | Mes, semana y lista con filtros por tipo, empresa, sede y unidad | Prototipo: Calendario |

## 8. Datos principales

Notificaciones, plantillas, preferencias, entregas por canal, suscripciones de calendario, dispositivos para push.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección NOT.

- **RN-NOT-01**: Si un evento genera un aviso → el destinatario se calcula en el momento del envío con los permisos y el alcance vigentes; nadie recibe datos que no puede ver.
- **RN-NOT-02**: Si el mensaje va por un canal externo (correo, SMS, WhatsApp) → no incluye montos de sueldo, datos de salud ni contenido de denuncias; solo el aviso y un enlace que exige ingresar.
- **RN-NOT-03** (parámetro): Si el aviso cae en el horario de silencio del usuario (parámetro) y no es urgente → se entrega al terminar el silencio.
- **RN-NOT-04** (parámetro): Si se generan varios avisos del mismo tipo en la ventana de agrupación (parámetro) → se envía uno solo con el resumen.
- **RN-NOT-05**: Si un aviso es de tipo obligatorio → el usuario no puede desactivar el canal en la aplicación.
- **RN-NOT-06** (parámetro): Si la entrega falla → se reintenta según la política del canal y, agotados los intentos, se usa el canal alternativo configurado.
- **RN-NOT-07**: Si el colaborador no tiene correo → los avisos van por aplicación, push o WhatsApp/SMS; ningún flujo queda bloqueado por falta de correo.
- **RN-NOT-08**: Si una persona está con vacaciones o descanso aprobado → los avisos no urgentes de trabajo respetan la desconexión digital y se entregan al regreso.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `notification.sent`, `notification.failed`.

**Consume:**

- Todos los eventos con plantilla configurada.

## 12. Integraciones

Proveedor de correo, push, SMS y WhatsApp Business por adaptador con implementación falsa («mensajes enviados (simulado)»).

## 13. Casos borde

- Destinatario que perdió el permiso entre el evento y el envío: no se envía.
- Plantilla sin traducción al idioma del usuario: cae al idioma de la empresa.
- Rebote permanente de correo: se marca el contacto como inválido y se avisa a RRHH.

## 14. Criterios de aceptación generales

- Cambiar de proveedor de un canal no requiere tocar ningún módulo.
- Cada notificación abre el registro exacto y desaparece de pendientes al resolverse.
