# M-MSS · Portal del manager

Fase 1 · Core HR · Épica `MSS` · Reglas `RN-MSS-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Dar a cada responsable una vista de su equipo y una bandeja para decidir, sin exponerle datos que no le corresponden.

## 2. Alcance

- Mi equipo (directos e indirectos), ausentes hoy, asistencia, vacaciones, solicitudes, objetivos, evaluaciones, capacitaciones, carga, cumpleaños, aniversarios, alertas.
- Aprobaciones, propuestas de movimiento e incremento, solicitud de posición, 1:1, reconocimientos.
- Calendario del equipo y control de traslapes.

## 3. Fuera de alcance

- Datos sensibles sin permiso de campo (sueldo, salud, sanciones). Lógica de cada trámite.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §9 Portal del manager; §107 Home manager.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Manager (alcance equipo). Suplente o delegado.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `people.team.view` | Ver el tablero y el calendario de su equipo | Administrador empresa, RRHH, Manager |
| `people.team.view_indirect` **(+)** | Incluir reportes indirectos en su alcance | Administrador empresa, RRHH, Manager |
| `people.team.view_workload` **(+)** | Ver carga de trabajo y horas de su equipo | Administrador empresa, RRHH, Manager |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Revisión diaria.** 1) Ve quién está ausente y qué solicitudes esperan. 2) Aprueba o rechaza desde la misma pantalla. 3) Revisa alertas (contrato por vencer, evaluación pendiente, vacaciones acumuladas) con su explicación.

**Propuesta de movimiento.** 1) Abre la ficha de un integrante. 2) Propone promoción, traslado o incremento con sustento. 3) Va al flujo de aprobación; aprobada, RRHH la aplica con vigencia.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Inicio del manager | Mi equipo, ausentes, solicitudes, objetivos, evaluaciones, cumpleaños, pendientes | Prototipo: Inicio |
| Mi equipo | Lista o tarjetas de integrantes con estado del día, saldo de vacaciones, pendientes; ir a la ficha limitada | Prototipo: Inicio (vista del jefe) |
| Calendario del equipo | Vacaciones, ausencias, turnos y eventos; traslapes | Prototipo: Inicio (vista del jefe) |
| Solicitudes del equipo | Bandeja filtrada de aprobaciones de su equipo | Prototipo: Inicio (vista del jefe) |

## 8. Datos principales

Sin datos propios; usa la línea de reporte vigente (M-HIS) y los datos de cada módulo con alcance «equipo».

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección MSS.

- **RN-MSS-01**: Si el usuario tiene alcance «equipo» → ve a quienes le reportan directa o indirectamente según la línea de reporte vigente; nadie más.
- **RN-MSS-02**: Si el rol Manager no trae permiso de un campo sensible → la ficha de sus reportes omite sueldo, salud, sanciones y documentos confidenciales.
- **RN-MSS-03** (parámetro): Si una ausencia solicitada deja al equipo por debajo de la dotación mínima (parámetro por unidad) → el manager ve la advertencia antes de aprobar.
- **RN-MSS-04**: Si el manager propone un cambio de sueldo o de puesto → nunca se aplica directo; pasa por aprobación y lo ejecuta RRHH o Payroll.
- **RN-MSS-05**: Si el manager está ausente → su suplente recibe las aprobaciones y ve al equipo durante la suplencia.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

Ninguno propio.

**Consume:**

- `employment.manager_changed`, `approval.requested`, eventos de ausencia y asistencia del equipo.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Manager sin equipo aún (posición nueva): estado vacío útil.
- Integrante con dos jefes (matriz): aprueba el jerárquico, el funcional es informado.
- Manager que es también colaborador: conmuta entre «mi equipo» y «lo mío».

## 14. Criterios de aceptación generales

- El manager ve equipo, asistencia, vacaciones, solicitudes, objetivos, evaluaciones, capacitaciones, carga, cumpleaños, aniversarios y alertas, y aprueba según permisos.
