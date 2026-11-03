# M-DIS · Casos disciplinarios

Fase 7 · Operación y cumplimiento · Épica `DIS` · Reglas `RN-DIS-nn` · Módulo comercial: Denuncias (`grievances`)

## 1. Objetivo

Llevar los procesos disciplinarios con orden, derecho de defensa y confidencialidad, dejando un expediente completo.

## 2. Alcance

- Caso: hechos, fecha, involucrados, tipo de falta, evidencias.
- Etapas: registro, imputación (carta de preaviso), descargos del trabajador, evaluación, decisión, medida (amonestación verbal o escrita, suspensión, despido), notificación, seguimiento.
- Plazos de descargo; documentos generados y firmados; historial de medidas por persona con vigencia de antecedentes.
- Efectos: suspensión como ausencia sin goce; despido hacia offboarding.

## 3. Fuera de alcance

- Investigación de denuncias (M-DEN). Asesoría legal.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §59 Casos disciplinarios.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH y asesor legal conducen. Jefe informa los hechos. Trabajador presenta descargos. Administrador decide medidas graves.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `discipline.cases.view` **(S)** | Ver casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.close` **(S)** | Cerrar casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.create` **(S)** | Crear casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.notify` **(S)** | Notificar la imputación en casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.record_statement` **(S)** | Registrar descargos en casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.resolve` **(S)** | Resolver casos disciplinarios | Administrador empresa |
| `discipline.cases.update` **(S)** | Editar casos disciplinarios | Administrador empresa, RRHH |
| `discipline.cases.report` **(S)** **(+)** | Reportar hechos de su equipo a RRHH | Manager |
| `discipline.cases.submit_defense` **(S)** **(+)** | Presentar descargos en un caso propio | solo sobre lo propio o asignado: Empleado, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Caso.** 1) El jefe reporta o RRHH abre el caso. 2) Se documentan hechos y evidencias. 3) Se emite la imputación con plazo para descargos. 4) El trabajador presenta descargos desde el portal o por escrito. 5) RRHH evalúa y propone. 6) Quien tiene `discipline.cases.resolve` decide. 7) Se notifica con documento firmado. 8) Se aplican efectos y se da seguimiento.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Casos disciplinarios | Lista con persona, tipo, etapa, plazos (solo con permiso) | Prototipo: Cumplimiento → Casos disciplinarios |
| Expediente disciplinario | Hechos, evidencias, imputación, descargos, decisión, documentos, seguimiento | Prototipo: Cumplimiento → Casos disciplinarios |
| Presentar descargos | Vista del trabajador con la imputación, plazo y formulario | Prototipo: Cumplimiento → Casos disciplinarios |

## 8. Datos principales

Casos (`disciplinary_cases`), hechos, evidencias, imputaciones, descargos, decisiones, medidas, tipos de falta.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección DIS.

- **RN-DIS-01**: Si se pretende aplicar una medida → debe existir imputación notificada y haberse cumplido el plazo de descargos o recibido los descargos.
- **RN-DIS-02** (parámetro): Si se notifica la imputación → el plazo de descargos se calcula según el tipo de falta (parámetro del paquete de país, a confirmar con la norma vigente).
- **RN-DIS-03**: Si la medida es despido o suspensión → la decide una persona con `discipline.cases.resolve` distinta de quien condujo el caso.
- **RN-DIS-04**: Si un usuario no tiene permiso disciplinario → no ve la existencia de casos ni medidas en la ficha, reportes ni búsquedas; el jefe solo ve lo que él reportó y la medida comunicada.
- **RN-DIS-05**: Si la medida es suspensión → crea una ausencia sin goce por los días decididos.
- **RN-DIS-06**: Si la medida es despido → inicia el offboarding con ese motivo.
- **RN-DIS-07** (parámetro): Si una medida supera su vigencia como antecedente (parámetro de política) → deja de contarse para reincidencia y se marca histórica.
- **RN-DIS-08**: Si la IA resume el expediente → no propone la medida; solo ordena hechos y documentos.
- **RN-DIS-09**: Si el caso proviene de una denuncia → enlaza solo la conclusión necesaria, sin exponer la identidad del denunciante.

## 10. Estados

- ME-38 · Caso disciplinario (`05-maquinas-de-estado.md`): estados Abierto, Imputación notificada, En descargos, En evaluación, Decidido, Medida notificada, Cerrado, Archivado sin medida.

## 11. Eventos

**Emite:**

- `disciplinary.opened`, `disciplinary.charge_notified`, `disciplinary.defense_submitted`, `disciplinary.decided`, `disciplinary.closed`.

**Consume:**

- `grievance.closed` (solo si el investigador lo deriva), `attendance.absence_detected`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Trabajador que no recibe la notificación (medios alternativos con constancia).
- Falta cometida por un dirigente sindical (protecciones del paquete de país).
- Caso que termina sin medida (archivo).

## 14. Criterios de aceptación generales

- El expediente reúne incidentes, descargos, documentos, medidas y seguimiento con acceso restringido.
