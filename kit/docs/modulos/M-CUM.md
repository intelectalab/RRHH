# M-CUM · Cumplimiento Perú y SUNAFIL

Fase 7 · Operación y cumplimiento · Épica `CUM` · Reglas `RN-CUM-nn` · Módulo comercial: Cumplimiento Perú (`compliance`)

## 1. Objetivo

Mostrar en un solo tablero si cada empresa cumple sus obligaciones laborales, con responsable y evidencia, y preparar el expediente para una inspección.

## 2. Alcance

- Catálogo de obligaciones del paquete de país con condición de aplicación (por número de trabajadores, actividad, régimen, sede).
- Evaluación automática con datos del sistema y semáforo (cumple, por vencer, incumple, no aplica, sin datos); responsable, evidencia y plan.
- Obligaciones iniciales: comité o supervisor SST, comité o delegado de hostigamiento, política salarial y cuadro de categorías, Vida Ley, SCTR, cuota de discapacidad, lactario, T-Registro al día, registros SST, registro de asistencia, contratos vigentes y sin desnaturalización, capacitaciones obligatorias, exámenes ocupacionales, boletas entregadas, depósitos de CTS, reglamentos.
- Expediente de inspección exportable por trabajador y periodo: boletas, asistencia, contratos, SST, constancias.
- Bandeja de casilla electrónica: requerimientos y notificaciones con responsable, plazo y respuesta.
- Calendario de obligaciones.

## 3. Fuera de alcance

- Asesoría legal y defensa en el procedimiento. Lectura automática de la casilla de la autoridad (integración futura).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-01 Tablero de cumplimiento Perú; E-02 Expediente de inspección SUNAFIL y casilla electrónica; E-15 Hostigamiento sexual (Ley 27942); E-19 Derechohabientes, seguros y regímenes.

## 5. Actores y permisos

- Administrador y RRHH son responsables. Payroll, SST y legal aportan evidencia. Auditor consulta.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `compliance.obligations.view` | Ver el tablero de cumplimiento | Administrador empresa, RRHH, Payroll, Auditor, SST, Contador |
| `compliance.obligations.attach_evidence` | Adjuntar evidencia en el tablero de cumplimiento | Administrador empresa, RRHH |
| `compliance.obligations.reevaluate` | Reevaluar el tablero de cumplimiento | Administrador empresa, RRHH |
| `compliance.obligations.update` | Editar el tablero de cumplimiento | Administrador empresa, RRHH |
| `compliance.inspection_files.view` | Ver expedientes de inspección | Administrador empresa, RRHH, Auditor |
| `compliance.inspection_files.create` | Crear expedientes de inspección | Administrador empresa, RRHH |
| `compliance.notices.view` | Ver requerimientos de la casilla electrónica | Administrador empresa, RRHH, Auditor |
| `compliance.notices.answer` | Responder requerimientos de la casilla electrónica | Administrador empresa, RRHH |
| `compliance.notices.assign` | Asignar requerimientos de la casilla electrónica | Administrador empresa, RRHH |
| `compliance.notices.close` | Cerrar requerimientos de la casilla electrónica | Administrador empresa, RRHH |
| `compliance.notices.create` | Crear requerimientos de la casilla electrónica | Administrador empresa, RRHH |
| `compliance.notices.update` | Editar requerimientos de la casilla electrónica | Administrador empresa, RRHH |
| `compliance.ledger.view` **(S)** | Ver el libro inalterable de cumplimiento | Administrador empresa, Auditor, SST; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.ledger.verify` **(S)** | Verificar el libro inalterable de cumplimiento | Administrador empresa, Auditor |
| `compliance.deadlines.view` | Ver plazos legales en curso | Administrador empresa, RRHH, Auditor, SST; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.authority_communications.view` | Ver comunicaciones a la autoridad | Administrador empresa, RRHH, Auditor; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.authority_communications.mark_sent` | Marcar como presentadas comunicaciones a la autoridad | Administrador empresa, RRHH; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.authority_communications.update` | Editar comunicaciones a la autoridad | Administrador empresa, RRHH; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.evidence_exports.view` | Ver exportaciones de expedientes con manifiesto | Administrador empresa, Auditor; solo sobre lo propio o asignado: Investigador de denuncias |
| `compliance.evidence_exports.create` | Crear exportaciones de expedientes con manifiesto | Administrador empresa; solo sobre lo propio o asignado: Investigador de denuncias |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Evaluación.** 1) Cada día el sistema evalúa las obligaciones aplicables a cada empresa. 2) Calcula el estado y la explicación («faltan 3 trabajadores en la póliza Vida Ley»). 3) Notifica cambios al responsable. 4) El responsable regulariza o adjunta evidencia.

**Expediente de inspección.** 1) Se eligen empresa, trabajadores y periodo. 2) El sistema reúne boletas con constancia de entrega, registro de asistencia, contratos, documentos SST y constancias. 3) Lista los faltantes. 4) Genera el paquete con índice.

**Requerimiento.** 1) Se registra la notificación recibida en la casilla con su fecha. 2) Se calcula el vencimiento. 3) Se asigna responsable. 4) Se prepara y adjunta la respuesta. 5) Se marca atendido con constancia.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Cumplimiento | Semáforo por obligación y empresa, explicación, responsable, evidencia, vencimiento | Prototipo: Cumplimiento → Cumplimiento Perú |
| Detalle de obligación | Regla aplicada, datos usados, historial de estado, evidencias, plan | Prototipo: Cumplimiento → Cumplimiento Perú |
| Expediente de inspección | Selección, contenido, faltantes, generar | Prototipo: Cumplimiento → Cumplimiento Perú → pestaña Expediente de inspección |
| Casilla electrónica | Requerimientos con plazo, responsable y estado | Prototipo: Cumplimiento → Cumplimiento Perú |
| Calendario de obligaciones | Vencimientos legales del mes y del año | Prototipo: Cumplimiento → Cumplimiento Perú |

## 8. Datos principales

Obligaciones (catálogo del paquete), evaluaciones por empresa y fecha, evidencias, planes, expedientes generados, requerimientos de la autoridad.  Libro inalterable de cumplimiento encadenado por huella (`compliance_ledger_entries`, `compliance_ledger_anchors`), plazos legales en curso (`compliance_deadlines`), comunicaciones a la autoridad (`authority_communications`) y exportaciones de expedientes con manifiesto (`evidence_exports`).

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CUM.

- **RN-CUM-01**: Si una obligación depende de un umbral (número de trabajadores, mujeres en edad fértil por sede, promedio anual) → el sistema calcula el indicador con datos vigentes y aplica el umbral del parámetro.
- **RN-CUM-02**: Si el semáforo muestra un estado → incluye siempre la explicación con los datos y la regla que lo producen.
- **RN-CUM-03**: Si faltan datos para evaluar → el estado es «sin datos» con la lista de lo que falta; nunca «cumple» por omisión.
- **RN-CUM-04** (parámetro): Si la empresa supera el umbral que obliga a la cuota de empleo de personas con discapacidad (umbral y porcentaje: parámetros a confirmar con la norma vigente) → se calcula la cuota sobre el promedio anual de trabajadores y se compara con las personas registradas con consentimiento.
- **RN-CUM-05** (parámetro): Si una sede alcanza el número de mujeres en edad fértil que obliga a lactario (parámetro a confirmar con la norma vigente) → la obligación pasa a «aplica» y pide evidencia de implementación.
- **RN-CUM-06**: Si un trabajador activo no figura en la póliza Vida Ley → la obligación pasa a «incumple» desde el primer día de la relación.
- **RN-CUM-07** (parámetro): Si se contrata o renueva la póliza Vida Ley → se registra y se alerta su registro ante la autoridad dentro de 30 días (valor de referencia 2026, parámetro con vigencia).
- **RN-CUM-08** (parámetro): Si se registra un requerimiento de la casilla → el plazo corre desde la notificación aunque nadie la haya abierto; el sistema alerta según anticipación (parámetro).
- **RN-CUM-09**: Si se genera el expediente → cada documento incluido conserva su hash y el índice lista lo que falta por trabajador.
- **RN-CUM-10**: Si una evidencia manual tiene vigencia → al vencer, la obligación vuelve a «por vencer» o «incumple».
- **RN-CUM-11**: Si la obligación no aplica a la empresa (régimen, tamaño) → se muestra «no aplica» con la razón.
- **RN-CUM-12**: Si un proceso de cumplimiento asienta un acto → la fila del libro (`compliance_ledger_entries`) guarda la huella de la fila anterior de su cadena; la tabla es de solo inserción y no tiene columnas de IP ni de sesión.
- **RN-CUM-13**: Si la verificación de una cadena encuentra una fila que no cuadra → se abre un incidente de seguridad y el expediente solo se exporta con la advertencia de integridad.
- **RN-CUM-14**: Si se exporta un expediente → el paquete lleva un manifiesto con la huella de cada pieza y de cada asiento, y la exportación queda asentada en la misma cadena.
- **RN-CUM-15** (parámetro): Si se registra una notificación de la casilla electrónica → se exige el instante del depósito; surte efectos ese día (o el primer día hábil siguiente si es inhábil) y el plazo que fija el documento corre desde el día hábil siguiente.
- **RN-CUM-16**: Si no se registra la revisión diaria de la casilla de una razón social → se alerta al responsable y, al segundo día hábil, al administrador.
- **RN-CUM-17** (parámetro): Si una obligación debe comunicarse a la autoridad → se crea la comunicación con su plazo (parámetro) y solo se da por cumplida con el cargo de presentación adjunto.

## 10. Estados

- ME-45 · Requerimiento de la autoridad (casilla electrónica) (`05-maquinas-de-estado.md`): estados Registrado, Asignado, En preparación, Respondido, Vencido, Cerrado.

## 11. Eventos

**Emite:**

- `compliance.status_changed`, `compliance.obligation_overdue`, `inspection_file.generated`, `authority_request.registered`, `authority_request.due`.

**Consume:**

- `employee.hired`, `employee.terminated`, `contract.expired`, `sst.committee_expiring`, `payslip.published`, `benefit.cts_deposited`, `tregistro.pending`, `course.completed`, `parameter.changed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Empresa que cruza un umbral a mitad de año.
- Grupo con empresas de distinto tamaño y régimen.
- Obligación nueva publicada en el paquete de país.

## 14. Criterios de aceptación generales

- Cada empresa ve sus obligaciones con semáforo, responsable y evidencia, y obtiene un expediente de inspección por trabajador y periodo.
