# M-PRI · Privacidad y retención

Fase 7 · Operación y cumplimiento · Épica `PRI` · Reglas `RN-PRI-nn` · Módulo comercial: Cumplimiento Perú (`compliance`)

## 1. Objetivo

Cumplir la normativa de datos personales: saber qué datos se tratan y para qué, atender los derechos de los titulares, conservar lo necesario y eliminar o anonimizar lo demás.

## 2. Alcance

- Registro de tratamientos y bancos de datos; finalidades y bases legales por tipo de dato.
- Consentimientos por finalidad (biometría, imagen, discapacidad, comunicaciones, candidatos) con versión, fecha y revocación.
- Solicitudes de derechos del titular (acceso, rectificación, cancelación, oposición, portabilidad) con plazos y respuesta.
- Políticas de retención, anonimización, eliminación y exportación por organización y jurisdicción; ejecución programada.
- Registro de incidentes de seguridad con notificación.
- Evaluaciones de impacto para biometría y perfilado con IA.
- Transferencias internacionales y encargados de tratamiento; oficial de datos personales.
- Registro de accesos y descargas de datos personales.

## 3. Fuera de alcance

- Controles técnicos de seguridad (M-SEG, M-PLT). Asesoría legal.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §91 Retención de datos; §92 Privacidad.

**Correcciones que incorpora:** C-08 IA con gobierno.

**Ampliaciones que incorpora:** E-16 Privacidad (Ley 29733, DS 016-2024-JUS).

## 5. Actores y permisos

- Oficial de datos personales o Administrador. RRHH atiende solicitudes. Titulares (colaboradores, ex-colaboradores, candidatos) ejercen derechos.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `privacy.consents.view` | Ver consentimientos | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Empleado, Manager |
| `privacy.consents.create` | Crear consentimientos | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.consents.revoke` | Revocar consentimientos | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.consents.configure` **(+)** | Configurar textos y versiones de consentimiento | Administrador empresa, RRHH |
| `privacy.subject_requests.view` | Ver solicitudes de derechos sobre datos personales | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Empleado, Manager |
| `privacy.subject_requests.create` | Crear solicitudes de derechos sobre datos personales | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.subject_requests.reject` | Rechazar solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |
| `privacy.subject_requests.resolve` | Resolver solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |
| `privacy.subject_requests.update` | Editar solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |
| `privacy.incidents.view` | Ver incidentes de seguridad de datos | Administrador empresa, Auditor |
| `privacy.incidents.close` | Cerrar incidentes de seguridad de datos | Administrador empresa |
| `privacy.incidents.contain` | Registrar la contención de incidentes de seguridad de datos | Administrador empresa |
| `privacy.incidents.create` | Crear incidentes de seguridad de datos | Administrador empresa |
| `privacy.incidents.mark_notified` | Marcar como notificados incidentes de seguridad de datos | Administrador empresa |
| `privacy.incidents.update` | Editar incidentes de seguridad de datos | Administrador empresa |
| `privacy.retention_policies.view` | Ver políticas de retención | Administrador empresa, RRHH, Auditor |
| `privacy.retention_policies.create` | Crear políticas de retención | Administrador empresa |
| `privacy.retention_policies.update` | Editar políticas de retención | Administrador empresa |
| `privacy.people.anonymize` | Anonimizar datos de personas | Administrador empresa |
| `privacy.processing_register.view` **(+)** | Ver tratamientos, bancos de datos y finalidades | Administrador empresa, RRHH, Auditor |
| `privacy.processing_register.update` **(+)** | Administrar tratamientos, bancos de datos y finalidades | Administrador empresa |
| `privacy.impact_assessments.update` **(+)** | Registrar evaluaciones de impacto | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitud de derechos.** 1) El titular la presenta desde el portal o un formulario público con verificación de identidad. 2) Se registra con su plazo. 3) RRHH reúne la información o ejecuta la acción. 4) Si hay obligación legal de conservar, se responde con el fundamento. 5) Se entrega la respuesta y se cierra.

**Retención.** 1) Se definen plazos por categoría de dato y evento de inicio (cese, cierre de proceso). 2) Un proceso periódico lista lo que cumplió plazo. 3) El responsable revisa y aprueba. 4) Se anonimiza o elimina y queda acta.

**Incidente.** 1) Se registra el incidente (qué, cuándo, datos y personas afectadas). 2) Se evalúa si corresponde notificar. 3) Se notifica dentro del plazo. 4) Se registran medidas y cierre.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Privacidad | Tratamientos, bancos de datos, finalidades, encargados | Prototipo: Cumplimiento → Privacidad |
| Consentimientos | Textos y versiones; estado por persona | Prototipo: Cumplimiento → Privacidad |
| Solicitudes de derechos | Bandeja con tipo, plazo, estado; responder | Prototipo: Cumplimiento → Privacidad |
| Retención | Políticas por categoría; lotes pendientes de aprobar; actas | Prototipo: Cumplimiento → Privacidad |
| Incidentes | Registro con plazo de notificación | Prototipo: Cumplimiento → Privacidad |
| Mis datos | Consentimientos otorgados, descargar mis datos, presentar solicitud | Prototipo: Cumplimiento → Privacidad |

## 8. Datos principales

Tratamientos, bancos de datos, finalidades, consentimientos (`consents`), solicitudes (`data_subject_requests`), políticas de retención, lotes y actas de anonimización, incidentes, evaluaciones de impacto, transferencias.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PRI.

- **RN-PRI-01**: Si un tratamiento requiere consentimiento (biometría, imagen, discapacidad, conservación de datos de candidato) → no se ejecuta sin consentimiento vigente y registrado con versión y fecha.
- **RN-PRI-02**: Si el titular revoca un consentimiento → el tratamiento se detiene desde ese momento y se ofrece la alternativa (otro método de marcación).
- **RN-PRI-03** (parámetro): Si se registra una solicitud de derechos → se calcula su fecha límite según el tipo (plazos del paquete de país, a confirmar con la norma vigente) y se alerta antes de vencer.
- **RN-PRI-04**: Si se pide cancelar datos que la empresa debe conservar por obligación legal (planilla, asistencia, SST) → se rechaza esa parte con el fundamento y se atiende el resto.
- **RN-PRI-05** (parámetro): Si se detecta un incidente de seguridad con datos personales → se crea la tarea de notificación con plazo de 48 horas (valor de referencia 2026, parámetro con vigencia).
- **RN-PRI-06** (parámetro): Si un dato cumple su plazo de retención y no hay obligación ni litigio que lo impida → entra al lote de anonimización o eliminación, que exige aprobación antes de ejecutarse.
- **RN-PRI-07**: Si se anonimiza un registro → se conservan los agregados estadísticos y se elimina toda posibilidad de reidentificación; la acción es irreversible y deja acta.
- **RN-PRI-08**: Si se activa una función de biometría o de perfilado con IA → exige una evaluación de impacto registrada y aprobada.
- **RN-PRI-09**: Si los datos se alojan o transfieren fuera del país → debe existir el registro de la transferencia y sus garantías.
- **RN-PRI-10**: Si existe una retención legal por litigio o inspección sobre una persona → sus datos quedan excluidos de cualquier purga hasta levantarla.
- **RN-PRI-11**: Si el titular pide portabilidad o acceso → recibe sus datos en formato estructurado, sin datos de terceros.

## 10. Estados

- ME-39 · Solicitud de derechos sobre datos personales (`05-maquinas-de-estado.md`): estados Recibida, Verificando identidad, En proceso, Atendida, Denegada (con fundamento), Cerrada.

## 11. Eventos

**Emite:**

- `privacy.consent_granted`, `privacy.consent_revoked`, `privacy.request_received`, `privacy.request_resolved`, `privacy.incident_opened`, `privacy.retention_batch_executed`.

**Consume:**

- `employee.terminated`, `application.rejected`, `grievance.closed`, `tenant.cancelled`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Ex-colaborador que pide eliminar todo: se conserva lo exigido por ley.
- Candidato que revoca durante un proceso activo.
- Menor de edad en modalidad formativa: consentimiento del representante.

## 14. Criterios de aceptación generales

- Se gestionan consentimientos, accesos, descargas, solicitudes y anonimización con registro, y las políticas de retención se aplican por organización y jurisdicción.
