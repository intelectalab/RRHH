# M-ESS · Portal del colaborador

Fase 1 · Core HR · Épica `ESS` · Reglas `RN-ESS-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Dar a cada colaborador un autoservicio sencillo, pensado primero para el móvil, donde consulta lo suyo y hace sus trámites sin depender de RRHH.

## 2. Alcance

- Inicio personal con saludo, vacaciones disponibles, próximo pago y pendientes.
- Perfil y actualización de datos permitidos; contrato y documentos; boletas; vacaciones y permisos; asistencia y horario; beneficios; gastos; encuestas; evaluaciones; objetivos; cursos; firma; certificados; solicitudes a RRHH.
- Acceso sin correo, modo ex-colaborador, aplicación web instalable.

## 3. Fuera de alcance

- La lógica de cada trámite (vive en su módulo). El canal WhatsApp (M-WHA).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §8 Portal del colaborador; §85 Experiencia móvil; §108 Home empleado.

**Correcciones que incorpora:** C-09 Acceso sin correo corporativo.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Empleado (alcance propio). Ex-colaborador (lectura limitada).

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `people.employments.view` | Ver colaboradores (relaciones laborales) | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador; solo sobre lo propio o asignado: Empleado |
| `people.employments.activate` | Activar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.cancel_termination` | Revertir el cese de colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.create` | Crear colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.suspend` | Suspender colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.terminate` | Terminar o cesar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.employments.update` | Editar colaboradores (relaciones laborales) | Administrador empresa, RRHH |
| `people.change_requests.view` | Ver solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.approve` | Aprobar solicitudes de cambio de datos | Administrador empresa, RRHH |
| `people.change_requests.cancel` | Cancelar solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.create` | Crear solicitudes de cambio de datos | Administrador empresa, RRHH; solo sobre lo propio o asignado: Empleado, Manager |
| `people.change_requests.reject` | Rechazar solicitudes de cambio de datos | Administrador empresa, RRHH |
| `people.certificates.request` **(+)** | Solicitar certificados y constancias propios | solo sobre lo propio o asignado: Empleado, Manager |
| `time.punches.view` | Ver marcaciones | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.punches.create` | Crear marcaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.punches.create_for_crew` **(+)** | Marcar por los integrantes de su cuadrilla | Manager |
| `time.vacation_requests.view` | Ver solicitudes de vacaciones | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.vacation_requests.approve` | Aprobar solicitudes de vacaciones | Administrador empresa, RRHH, Manager |
| `time.vacation_requests.cancel` | Cancelar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.create` | Crear solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.reject` | Rechazar solicitudes de vacaciones | Administrador empresa, RRHH, Manager |
| `time.vacation_requests.submit` | Enviar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.update` | Editar solicitudes de vacaciones | solo sobre lo propio o asignado: todos los roles de organización |
| `time.vacation_requests.validate` **(+)** | Validación final de RRHH | Administrador empresa, RRHH |
| `time.vacation_requests.schedule` **(+)** | Programar el rol anual y vacaciones colectivas | Administrador empresa, RRHH, Manager |
| `time.absences.view` | Ver ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Payroll, Manager, Auditor; solo sobre lo propio o asignado: Empleado |
| `time.absences.approve` | Aprobar ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Manager |
| `time.absences.cancel` | Cancelar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.create` | Crear ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.reject` | Rechazar ausencias y permisos (sin diagnóstico) | Administrador empresa, RRHH, Manager |
| `time.absences.submit` | Enviar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.update` | Editar ausencias y permisos (sin diagnóstico) | solo sobre lo propio o asignado: todos los roles de organización |
| `time.absences.create_for_others` **(+)** | Registrar ausencias a nombre de otros | Administrador empresa, RRHH |
| `time.absences.manage_subsidy` **(+)** | Gestionar subsidios y reembolsos | Administrador empresa, RRHH, Payroll |
| `payroll.payslips.view` | Ver boletas de pago | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: RRHH, Recruiter, Manager, Empleado, SST |
| `payroll.payslips.acknowledge` | Dar acuse de boletas de pago | solo sobre lo propio o asignado: todos los roles de organización |
| `payroll.payslips.publish` | Publicar boletas de pago | Administrador empresa, Payroll |
| `payroll.payslips.void` | Anular boletas de pago | Administrador empresa |
| `payroll.payslips.bulk_download` **(+)** | Descarga masiva de boletas | Administrador empresa, Payroll |
| `payroll.payslips.update_template` **(+)** | Configurar la plantilla de boleta | Administrador empresa, Payroll |
| `helpdesk.tickets.view` | Ver solicitudes a RRHH | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `helpdesk.tickets.assign` | Asignar solicitudes a RRHH | Administrador empresa, RRHH |
| `helpdesk.tickets.close` | Cerrar solicitudes a RRHH | Administrador empresa, RRHH, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `helpdesk.tickets.create` | Crear solicitudes a RRHH | solo sobre lo propio o asignado: todos los roles de organización |
| `helpdesk.tickets.reopen` | Reabrir solicitudes a RRHH | solo sobre lo propio o asignado: todos los roles de organización |
| `helpdesk.tickets.request_info` | Pedir información en solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `helpdesk.tickets.resolve` | Resolver solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `helpdesk.tickets.update` | Editar solicitudes a RRHH | Administrador empresa, RRHH, Payroll |
| `expenses.reports.view` | Ver informes de gastos | Administrador empresa, Payroll, Manager, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `expenses.reports.approve` | Aprobar informes de gastos | Administrador empresa, Manager |
| `expenses.reports.create` | Crear informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.mark_paid` | Marcar como pagado informes de gastos | Administrador empresa, Payroll |
| `expenses.reports.reject` | Rechazar informes de gastos | Administrador empresa, Payroll, Manager, Contador |
| `expenses.reports.submit` | Enviar informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.update` | Editar informes de gastos | solo sobre lo propio o asignado: todos los roles de organización |
| `expenses.reports.review` **(+)** | Revisión de finanzas y observaciones | Administrador empresa, Payroll, Contador |
| `privacy.consents.view` | Ver consentimientos | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Empleado, Manager |
| `privacy.consents.create` | Crear consentimientos | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.consents.revoke` | Revocar consentimientos | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.consents.configure` **(+)** | Configurar textos y versiones de consentimiento | Administrador empresa, RRHH |
| `privacy.subject_requests.view` | Ver solicitudes de derechos sobre datos personales | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Empleado, Manager |
| `privacy.subject_requests.create` | Crear solicitudes de derechos sobre datos personales | solo sobre lo propio o asignado: todos los roles de organización |
| `privacy.subject_requests.reject` | Rechazar solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |
| `privacy.subject_requests.resolve` | Resolver solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |
| `privacy.subject_requests.update` | Editar solicitudes de derechos sobre datos personales | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Pendientes.** 1) El portal lista lo que espera al colaborador (firmar, evaluar, curso obligatorio, documento por subir). 2) Cada elemento abre la acción directa. 3) Al completarla, desaparece y actualiza el contador.

**Trámite típico (vacaciones).** 1) Ve su saldo. 2) Elige fechas; el sistema valida en línea. 3) Envía. 4) Sigue el estado y recibe el aviso de la decisión.

**Descarga de boleta.** 1) Abre Mis boletas. 2) Elige periodo. 3) Ve o descarga; queda constancia de acceso.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Inicio del colaborador | Saludo, vacaciones disponibles, próximo pago, pendientes, accesos rápidos | Prototipo: Inicio |
| Mi perfil | Datos personales y laborales, emergencia, derechohabientes; proponer cambios | Prototipo: Inicio → Ver mi ficha |
| Mis documentos y contrato | Documentos propios, contrato vigente, firma pendiente | Prototipo: Operaciones → Documentos |
| Mis boletas | Historial de boletas y certificados de renta; descargar | Prototipo: Nómina → Mis boletas |
| Mi tiempo | Marcar, ver asistencia, horario, vacaciones, permisos, horas extra y bolsa | Prototipo: grupo de menú Tiempo |
| Mis solicitudes | Trámites enviados con estado; nueva solicitud a RRHH | Prototipo: Experiencia → Mesa de ayuda |
| Mi desarrollo | Objetivos, evaluaciones, cursos, beneficios, reconocimientos, encuestas | Prototipo: grupos de menú Talento, Aprendizaje y Compensación |

## 8. Datos principales

No tiene datos propios; consume los de cada módulo con alcance «propio».

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ESS.

- **RN-ESS-01**: Si el usuario tiene alcance «propio» → toda consulta del portal se limita a sus relaciones laborales; no existe parámetro para pedir datos de otra persona.
- **RN-ESS-02**: Si el módulo de un bloque no está contratado → el bloque no aparece en el portal.
- **RN-ESS-03**: Si el colaborador ve o descarga una boleta → se registra fecha y hora de acceso como constancia de entrega.
- **RN-ESS-04**: Si el colaborador tiene varias relaciones activas → elige con cuál opera y ve boletas y saldos separados por empresa.
- **RN-ESS-05** (parámetro): Si el colaborador cesó → mantiene acceso de solo lectura a boletas, certificados, liquidación y documentos de cese por el periodo configurado (parámetro).
- **RN-ESS-06**: Si una acción del portal exige confirmar identidad (firmar, cambiar cuenta de pago) → se pide OTP aunque la sesión esté activa.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

Ninguno propio.

**Consume:**

- Todos los que generan pendientes para la persona.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Colaborador sin teléfono inteligente: kiosko compartido en sede con cierre de sesión automático.
- Conectividad baja: pantallas ligeras y marcación sin conexión (M-ASI).
- Menor de edad en modalidad formativa: avisos también al tutor si la empresa lo configura.

## 14. Criterios de aceptación generales

- Desde un móvil se completa: marcar asistencia, pedir vacaciones, ver boletas, firmar, responder encuestas, ver horario y crear solicitudes.
- El inicio muestra saldo de vacaciones, próximo pago y pendientes reales.
