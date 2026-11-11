# M-SST · Seguridad y salud en el trabajo

Fase 7 · Operación y cumplimiento · Épica `SST` · Reglas `RN-SST-nn` · Módulo comercial: Seguridad y salud (`sst`)

## 1. Objetivo

Gestionar la seguridad y salud en el trabajo con los registros obligatorios, sus plazos de conservación y un tablero de indicadores, configurable por país.

## 2. Alcance

- Accidentes, incidentes peligrosos e incidentes; enfermedades ocupacionales; investigación y causas.
- Matriz de peligros y riesgos (IPERC) por puesto y sede; controles.
- Inspecciones internas, observaciones, medidas correctivas con responsable y plazo.
- Exámenes médicos ocupacionales (ingreso, periódico, retiro) con aptitud y vencimientos; datos médicos restringidos.
- Capacitaciones, inducciones y simulacros (con M-LMS).
- Equipos de protección personal (con M-ACT), equipos de emergencia.
- Comité o supervisor: elecciones, miembros, reuniones, actas, acuerdos.
- Monitoreo de agentes (físicos, químicos, biológicos, psicosociales, disergonómicos), auditorías, estadísticas.
- Documentos del sistema de gestión, plan anual, vencimientos; SCTR por puesto y sede.
- Los ocho registros obligatorios con sus formatos y conservación.

## 3. Fuera de alcance

- Historia clínica (la custodia el servicio médico; aquí solo aptitud y restricciones). Gestión ambiental.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §57 Seguridad y salud en el trabajo.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-12 EPP y uniformes.

## 5. Actores y permisos

- Responsable SST administra. Comité revisa. Managers reportan y ejecutan medidas. Colaboradores reportan incidentes y actos inseguros.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `sst.incidents.view` | Ver incidentes y accidentes (sin datos médicos) | Administrador empresa, RRHH, Manager, Auditor, SST |
| `sst.incidents.close` | Cerrar incidentes y accidentes (sin datos médicos) | Administrador empresa, SST |
| `sst.incidents.create` | Crear incidentes y accidentes (sin datos médicos) | Administrador empresa, RRHH, Manager, Empleado, SST |
| `sst.incidents.investigate` | Investigar incidentes y accidentes (sin datos médicos) | Administrador empresa, SST |
| `sst.incidents.update` | Editar incidentes y accidentes (sin datos médicos) | Administrador empresa, SST |
| `sst.risks.view` | Ver la matriz de peligros y riesgos | Administrador empresa, RRHH, Manager, Auditor, SST |
| `sst.risks.archive` | Archivar la matriz de peligros y riesgos | Administrador empresa, SST |
| `sst.risks.create` | Crear la matriz de peligros y riesgos | Administrador empresa, SST |
| `sst.risks.update` | Editar la matriz de peligros y riesgos | Administrador empresa, SST |
| `sst.inspections.view` | Ver inspecciones | Administrador empresa, RRHH, Manager, Auditor, SST |
| `sst.inspections.close` | Cerrar inspecciones | Administrador empresa, SST |
| `sst.inspections.create` | Crear inspecciones | Administrador empresa, SST |
| `sst.inspections.update` | Editar inspecciones | Administrador empresa, SST |
| `sst.corrective_actions.view` | Ver medidas correctivas | Administrador empresa, RRHH, Manager, Auditor, SST |
| `sst.corrective_actions.complete` | Completar medidas correctivas | SST; solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager |
| `sst.corrective_actions.create` | Crear medidas correctivas | Administrador empresa, SST |
| `sst.corrective_actions.update` | Editar medidas correctivas | Administrador empresa, SST |
| `sst.corrective_actions.verify` | Verificar medidas correctivas | Administrador empresa, SST |
| `sst.medical_exams.view` **(S)** | Ver exámenes médicos ocupacionales (detalle y resultados) | SST |
| `sst.medical_exams.create` **(S)** | Crear exámenes médicos ocupacionales (detalle y resultados) | SST |
| `sst.medical_exams.update` **(S)** | Editar exámenes médicos ocupacionales (detalle y resultados) | SST |
| `sst.medical_exams.view_fitness` **(S)** **(+)** | Ver solo aptitud y restricciones laborales | Administrador empresa, RRHH, Manager, SST |
| `sst.committees.view` | Ver comité o supervisor, reuniones y actas | Administrador empresa, RRHH, Empleado, Auditor, SST |
| `sst.committees.create` | Crear comité o supervisor, reuniones y actas | Administrador empresa, SST |
| `sst.committees.update` | Editar comité o supervisor, reuniones y actas | Administrador empresa, SST |
| `sst.elections.view` | Ver procesos electorales del comité o supervisor | Administrador empresa, RRHH, Empleado, Auditor, SST |
| `sst.elections.annul` | Anular procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.call` | Convocar procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.communicate` | Comunicar el inicio de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.count` | Escrutar procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.create` | Crear procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.form_board` | Conformar la junta electoral de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.install` | Instalar el órgano resultante de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.open_nominations` | Abrir la inscripción de candidatos de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.open_voting` | Abrir la votación de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.proclaim` | Proclamar los resultados de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.publish_candidates` | Publicar los candidatos de procesos electorales del comité o supervisor | Administrador empresa, SST |
| `sst.elections.vote` | Votar en procesos electorales del comité o supervisor | solo sobre lo propio o asignado: Empleado |
| `sst.committee_meetings.view` | Ver sesiones y actas del comité | Administrador empresa, RRHH, Empleado, Auditor, SST |
| `sst.committee_meetings.call` | Convocar sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.cancel` | Cancelar sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.create` | Crear sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.draft_minutes` | Redactar el acta de sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.record_no_quorum` | Registrar la falta de quórum de sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.record_session` | Registrar la sesión y la asistencia de sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.sign_minutes` | Firmar el acta de sesiones y actas del comité | Administrador empresa, SST |
| `sst.committee_meetings.update` | Editar sesiones y actas del comité | Administrador empresa, SST |
| `sst.records.export` **(+)** | Generar los registros obligatorios | Administrador empresa, Auditor, SST |
| `assets.ppe_deliveries.view` | Ver entregas de EPP y uniformes | Administrador empresa, RRHH, Auditor, SST; solo sobre lo propio o asignado: Empleado |
| `assets.ppe_deliveries.create` | Crear entregas de EPP y uniformes | Administrador empresa, SST |
| `assets.ppe_deliveries.sign` | Firmar entregas de EPP y uniformes | solo sobre lo propio o asignado: todos los roles de organización |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Accidente o incidente.** 1) Cualquiera lo reporta (fecha, lugar, personas, descripción, fotos). 2) SST lo clasifica y abre la investigación. 3) Registra causas y medidas correctivas. 4) Si hay descanso, se enlaza la ausencia. 5) Se notifica a la autoridad cuando el tipo lo exige. 6) Se verifica la eficacia de las medidas y se cierra. 7) Entra al registro obligatorio y a las estadísticas.

**Examen ocupacional.** 1) Se programa por ingreso, periodicidad o retiro. 2) Se registra resultado de aptitud y restricciones. 3) El jefe solo ve «apto», «apto con restricciones» o «no apto» y las restricciones aplicables. 4) Se agenda el siguiente.

**Inspección.** 1) Se planifica con lista de verificación. 2) Se registra en campo (móvil, fotos). 3) Cada hallazgo genera una medida. 4) Seguimiento hasta el cierre.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Tablero SST | Accidentabilidad, días perdidos, medidas vencidas, exámenes y capacitaciones por vencer, cumplimiento del plan | Prototipo: SST → Tablero SST |
| Incidentes | Lista con tipo, gravedad, estado, días perdidos; reportar | Prototipo: SST → Accidentes e incidentes |
| Investigación | Hechos, causas, involucrados, medidas, documentos | Prototipo: SST → Accidentes e incidentes |
| Riesgos | Matriz por puesto y sede con nivel de riesgo y controles | Prototipo: SST → Matriz IPERC |
| Inspecciones | Programa, registros, hallazgos | Prototipo: SST → Registros obligatorios |
| Medidas correctivas | Medidas por origen, responsable, plazo, estado | Prototipo: SST → Registros obligatorios |
| Salud ocupacional | Exámenes por persona, aptitud, vencimientos | Prototipo: SST → Registros obligatorios |
| Capacitaciones SST | Programa, asistencia, simulacros | Prototipo: SST → Registros obligatorios |
| Comité | Miembros, vigencia, reuniones, actas, acuerdos | Prototipo: SST → Comité y parámetros |
| Registros obligatorios | Generación de cada registro por periodo en su formato | Prototipo: SST → Registros obligatorios |
| Proceso electoral | Línea de tiempo de la elección con el plazo de cada etapa, junta electoral, candidatos, padrón; acciones de cada etapa | Prototipo: SST → Comité y parámetros |
| Votación | Candidatos aptos; emitir un voto secreto; constancia de participación | Prototipo: Talento → Onboarding |
| Sesiones y actas | Calendario de sesiones ordinarias, asistencia y quórum, acuerdos, firma del acta; libro de actas con huella y verificación de la cadena | Prototipo: SST → Comité y parámetros |

## 8. Datos principales

Incidentes (`sst_incidents`), investigaciones, peligros y riesgos, inspecciones y hallazgos, medidas correctivas, exámenes ocupacionales, monitoreos, auditorías, comité y actas, pólizas SCTR, plan anual.  Elecciones (`sst_committee_elections`, participantes y padrón sin preferencia), sesiones con estado y asistencia (`sst_committee_meetings`, `sst_committee_meeting_attendees`).

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección SST.

- **RN-SST-01** (parámetro): Si se registra un accidente de trabajo o incidente peligroso → su registro se conserva 10 años; enfermedades ocupacionales 20 años; los demás registros 5 años (valores de referencia 2026, parámetros con vigencia).
- **RN-SST-02**: Si un incidente se cierra → debe tener investigación, causas y todas sus medidas en estado verificada o descartada con motivo.
- **RN-SST-03** (parámetro): Si el evento es accidente mortal o incidente peligroso → se crea la tarea de notificación a la autoridad con su plazo (parámetro del paquete de país, a confirmar con la norma vigente).
- **RN-SST-04**: Si un usuario sin `sst.medical_exams.view` consulta a una persona → solo ve la aptitud y las restricciones laborales, nunca diagnósticos ni resultados.
- **RN-SST-05** (parámetro): Si un examen ocupacional está por vencer (anticipación y periodicidad por tipo de puesto: parámetros) → se alerta a SST y al colaborador.
- **RN-SST-06**: Si una medida correctiva supera su plazo → pasa a «Vencida», se alerta al responsable y a SST y cuenta en el tablero.
- **RN-SST-07** (parámetro): Si la empresa tiene 20 o más trabajadores (`sst_comite_minimo_trabajadores`, comparación mayor o igual) → cumplimiento exige comité vigente; con menos de 20, supervisor elegido por los trabajadores.
- **RN-SST-08**: Si un puesto o sede está marcado de riesgo → sus ocupantes deben figurar en la póliza SCTR vigente; si no, se alerta.
- **RN-SST-09**: Si se entrega un EPP → queda con firma del trabajador y alimenta el registro de equipos de seguridad.
- **RN-SST-10**: Si la empresa es micro o pequeña → se usan los registros simplificados del paquete de país (norma a confirmar).
- **RN-SST-11**: Si un colaborador ingresa o cambia a un puesto con riesgos distintos → se le asigna la inducción y capacitación específica y se registra la entrega de recomendaciones de SST.
- **RN-SST-12**: Si se reporta un acto o condición insegura de forma anónima → se registra sin identificar al reportante.
- **RN-SST-13** (parámetro): Si faltan los días de aviso para el fin del mandato (`sst_eleccion_aviso_dias`), la dotación cruza el umbral o hay vacancia sin suplente → se planifica un proceso electoral (ME-46) con el plazo de cada etapa tomado de los parámetros `sst_eleccion_*`.
- **RN-SST-14**: Si se emite un voto en una elección → se registra quién votó en el padrón y se incrementa el contador del candidato, sin guardar vínculo entre votante y preferencia.
- **RN-SST-15**: Si un integrante de la junta electoral se inscribe como candidato (o un candidato se designa en la junta) → se rechaza.
- **RN-SST-16** (parámetro): Si la sesión no alcanza el quórum (mitad más uno de los miembros) → queda «Sin quórum» y se crea una sesión nueva dentro de `sst_sin_quorum_reprogramacion_dias`, que sesiona con los presentes.
- **RN-SST-17**: Si un mes calendario termina sin sesión ordinaria realizada y el comité está vigente → la obligación del mes pasa a «incumple» con explicación.
- **RN-SST-18**: Si el acta se firma → se asienta en el libro inalterable de cumplimiento con la huella del documento y ya no se edita; una corrección es un acta aclaratoria nueva.
- **RN-SST-19** (parámetro): Si un miembro acumula las inasistencias de `sst_vacancia_inasistencias_consecutivas` o `sst_vacancia_inasistencias_alternas` → se propone la vacancia y se llama al suplente.
- **RN-SST-20** (parámetro): Si se elige a los representantes → el comité debe instalarse dentro de `sst_instalacion_dias_habiles`; vencido el plazo se alerta y la obligación pasa a «en riesgo».

## 10. Estados

- ME-35 · Incidente o accidente SST (`05-maquinas-de-estado.md`): estados Reportado, Clasificado, En investigación, Con medidas en curso, Cerrado, Descartado.
- ME-36 · Medida correctiva (`05-maquinas-de-estado.md`): estados Pendiente, En curso, Ejecutada, Verificada, Vencida, Descartada.
- ME-46 · Proceso electoral del comité de SST (`05-maquinas-de-estado.md`): estados Planificado, Comunicado, Convocado, Junta electoral conformada, Inscripción de candidatos, Candidatos publicados, En votación, Escrutado, Proclamado, Instalado, Anulado.
- ME-47 · Sesión del comité de SST (`05-maquinas-de-estado.md`): estados Programada, Convocada, Realizada, Sin quórum, Acta redactada, Acta firmada, Cancelada.

## 11. Eventos

**Emite:**

- `sst.incident_reported`, `sst.incident_classified`, `sst.incident_closed`, `sst.corrective_action_created`, `sst.corrective_action_overdue`, `sst.exam_expiring`, `sst.inspection_completed`, `sst.committee_expiring`.

**Consume:**

- `employee.hired`, `employment.assignment_changed`, `course.completed`, `asset.assigned`, `absence.approved`.

## 12. Integraciones

Clínicas ocupacionales y aseguradoras por archivo o API (M-INT).

## 13. Casos borde

- Accidente de un tercero (contratista, visitante).
- Accidente en teletrabajo o en trayecto.
- Trabajador «no apto» para su puesto: reubicación con movimiento.
- Cierre de sede con medidas abiertas.

## 14. Criterios de aceptación generales

- Se administran accidentes, incidentes, inspecciones, riesgos, observaciones, medidas, exámenes, capacitaciones, EPP, comités, documentos y vencimientos, con tablero y los ocho registros exportables.
