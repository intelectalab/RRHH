# M-CIN · Comunicación interna

Fase 6 · Experiencia · Épica `CIN` · Reglas `RN-CIN-nn` · Módulo comercial: Clima y encuestas (`engagement`)

## 1. Objetivo

Informar a las personas correctas por el canal correcto y saber quién leyó lo que debía leer.

## 2. Alcance

- Noticias, comunicados, eventos, cumpleaños y aniversarios.
- Segmentación por empresa, sede, área, equipo, puesto o lista.
- Programación, fijado, vigencia, adjuntos, comentarios y reacciones opcionales.
- Confirmación de lectura obligatoria para comunicados formales (políticas, reglamentos).
- Borrador con IA; envío por notificaciones y WhatsApp.

## 3. Fuera de alcance

- Chat entre colaboradores. Transmisiones en vivo.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §52 Comunicación interna.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH o comunicaciones publica. Managers publican a su equipo si se permite. Todos leen.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `engagement.announcements.view` | Ver publicaciones dirigidas a su segmento | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Contador |
| `engagement.announcements.acknowledge` | Dar acuse de publicaciones dirigidas a su segmento | solo sobre lo propio o asignado: todos los roles de organización |
| `engagement.announcements.archive` | Archivar publicaciones dirigidas a su segmento | Administrador empresa, RRHH |
| `engagement.announcements.create` | Crear publicaciones dirigidas a su segmento | Administrador empresa, RRHH |
| `engagement.announcements.publish` | Publicar publicaciones dirigidas a su segmento | Administrador empresa, RRHH |
| `engagement.announcements.update` | Editar publicaciones dirigidas a su segmento | Administrador empresa, RRHH |
| `engagement.announcements.create_for_team` **(+)** | Publicar solo a su equipo | Manager |
| `engagement.announcements.view_reads` **(+)** | Ver el estado de lectura por persona | Administrador empresa, RRHH, Auditor |
| `engagement.announcements.manage_events` **(+)** | Crear eventos y gestionar asistencia | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Comunicado con lectura obligatoria.** 1) Se redacta y se elige el segmento. 2) Se marca «requiere confirmación». 3) Se publica o programa. 4) Cada destinatario lo ve en pendientes y confirma. 5) RRHH sigue el avance y envía recordatorios. 6) La lista de confirmaciones sirve como evidencia.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Comunicaciones | Muro de noticias, comunicados y eventos del segmento del usuario | Prototipo: Experiencia → Comunicaciones |
| Editor de publicación | Contenido, segmento, programación, lectura obligatoria, vista previa | Prototipo: Experiencia → Comunicaciones |
| Seguimiento de lectura | Leído, confirmado y pendiente por persona; recordar; exportar | Prototipo: Experiencia → Comunicaciones |
| Celebraciones | Cumpleaños y aniversarios según preferencias de privacidad | Prototipo: Calendario → filtros Cumpleaños y Aniversarios |

## 8. Datos principales

Publicaciones (`posts`), segmentos, lecturas y confirmaciones, eventos y asistencia, comentarios.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CIN.

- **RN-CIN-01**: Si una publicación tiene segmento → solo la ven quienes pertenecen a él al momento de consultar, más sus autores.
- **RN-CIN-02**: Si la publicación requiere confirmación → queda en pendientes del destinatario hasta que confirme; la confirmación guarda fecha y hora.
- **RN-CIN-03**: Si alguien entra al segmento después de publicar (ingreso, traslado) y la publicación sigue vigente → la recibe.
- **RN-CIN-04**: Si un colaborador desactiva mostrar su cumpleaños → no aparece en celebraciones; la preferencia por defecto la define la organización.
- **RN-CIN-05** (parámetro): Si la publicación la crea un rol que requiere aprobación (parámetro) → no sale hasta ser aprobada.
- **RN-CIN-06**: Si un comunicado con lectura obligatoria se edita tras publicarse → se crea una versión nueva y se pide confirmar de nuevo.
- **RN-CIN-07** (parámetro): Si la confirmación no llega dentro del plazo (parámetro) → se recuerda al destinatario y luego a su jefe.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `announcement.published`, `announcement.read`, `announcement.acknowledged`, `event.created`, `policy.published`.

**Consume:**

- `employee.hired`, `employment.assignment_changed`.

## 12. Integraciones

Canales de M-NOT y M-WHA.

## 13. Casos borde

- Colaborador sin acceso digital: confirmación en kiosko o en físico con cargo.
- Publicación para todo el grupo con empresas en distintos idiomas.

## 14. Criterios de aceptación generales

- Se publican noticias, comunicados y eventos segmentados y se registra lectura cuando se exige.
