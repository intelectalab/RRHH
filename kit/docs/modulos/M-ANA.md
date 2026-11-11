# M-ANA · People analytics y tableros

Fase 8 · Inteligencia · Épica `ANA` · Reglas `RN-ANA-nn` · Módulo comercial: Analítica y reportes (`analytics`)

## 1. Objetivo

Dar a dirección, RRHH y jefes indicadores confiables de su gente, con filtros, tendencias y tableros por rol, respetando permisos y confidencialidad.

## 2. Alcance

- Tablero ejecutivo e indicadores: dotación, altas, bajas, rotación, ausentismo, tardanzas, horas extra, costo laboral, vacaciones, desempeño, capacitación, compromiso, eNPS, tiempo y costo de contratación, vacantes, diversidad cuando legalmente corresponde, antigüedad, distribución salarial, promociones, movilidad, SST.
- Filtros por empresa, sede, área, puesto, fecha y manager; comparación entre periodos; desglose hasta el listado (si hay permiso).
- Tableros personalizables por rol con widgets reordenables (RRHH, dirección, manager, payroll).
- Definiciones de cada indicador visibles (fórmula y fuente); explicación de métricas con IA.
- Modelo analítico con historia (fotos periódicas y cálculo a la fecha).

## 3. Fuera de alcance

- Constructor de reportes (M-RPT). Alertas (M-ALE).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §64 People analytics; §65 Dashboards personalizables; §106 Home RRHH.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Dirección, RRHH, Payroll, managers, auditor.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `analytics.dashboards.view` | Ver tableros | Administrador empresa, RRHH, Payroll, Manager, Auditor, SST, Contador |
| `analytics.dashboards.create` | Crear tableros | Administrador empresa, RRHH |
| `analytics.dashboards.delete` | Eliminar tableros | Administrador empresa, RRHH |
| `analytics.dashboards.update` | Editar tableros | Administrador empresa, RRHH |
| `analytics.metrics.view` | Ver indicadores no sensibles de su alcance | Administrador empresa, RRHH, Payroll, Manager, Auditor, SST, Contador |
| `analytics.metrics.drill_down` **(+)** | Desglosar un indicador hasta el listado de personas | Administrador empresa, RRHH |
| `analytics.metrics.labor_cost.view` **(S)** **(+)** | Ver indicadores de costo laboral y distribución salarial | Administrador empresa, Payroll, Contador |
| `analytics.metrics.diversity.view` **(S)** **(+)** | Ver indicadores de diversidad | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Consulta.** 1) El usuario abre su tablero. 2) Aplica filtros. 3) Cada widget se calcula sobre su alcance. 4) Puede abrir la definición o pedir una explicación. 5) Con permiso, desglosa hasta el listado.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Tablero ejecutivo | Indicadores clave con tendencia y comparación | Prototipo: Analytics → Tableros |
| People Analytics | Exploración por tema (dotación, rotación, tiempo, costo, talento, clima, SST) con filtros | Prototipo: Analytics → People Analytics → pestañas Resumen, Dotación, Tiempo, Costos, Talento y desarrollo, Clima, SST y diversidad |
| Editor de tablero | Biblioteca de widgets, arrastrar y soltar, publicar a un rol | Prototipo: Analytics → Tableros |
| Definiciones | Fórmula, fuente y última actualización de cada indicador | Prototipo: Analytics → People Analytics → pestañas Resumen, Dotación, Tiempo, Costos, Talento y desarrollo, Clima, SST y diversidad |

## 8. Datos principales

Hechos y dimensiones analíticas derivados (dotación diaria, movimientos, asistencia, planilla agregada), definiciones de indicadores, tableros y widgets.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ANA.

- **RN-ANA-01**: Si un indicador se muestra → se calcula solo con las personas dentro del alcance del usuario.
- **RN-ANA-02**: Si el indicador usa datos de un campo sensible (costo, sueldo, diversidad) → exige el permiso correspondiente; sin él, el widget no aparece.
- **RN-ANA-03** (parámetro): Si un segmento tiene menos personas que el mínimo de confidencialidad (parámetro) y el indicador es sensible → se oculta o se agrega.
- **RN-ANA-04**: Si se calcula la rotación → es bajas del periodo sobre dotación promedio del periodo, con la definición visible; cada indicador tiene una única fórmula publicada.
- **RN-ANA-05**: Si se consulta un periodo pasado → se usa la estructura y la dotación vigentes en ese periodo (historia con vigencia), no las actuales.
- **RN-ANA-06**: Si un indicador de diversidad usa sexo, edad, discapacidad o nacionalidad → solo se muestra agregado y cuando la norma permite tratar ese dato para esa finalidad.
- **RN-ANA-07**: Si un módulo no está contratado → sus indicadores no aparecen en la biblioteca.
- **RN-ANA-08**: Si el locador no es trabajador → no cuenta en dotación ni rotación; se reporta aparte.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `dashboard.published`.

**Consume:**

- Todos los eventos de dominio (actualizan el modelo analítico).

## 12. Integraciones

Herramientas externas de inteligencia de negocio por API o exportación (M-API).

## 13. Casos borde

- Persona con dos relaciones: cuenta una vez como persona y dos como relación; el indicador declara cuál usa.
- Reorganización: comparaciones «con estructura actual» o «con estructura de la fecha».
- Datos importados como histórico con menor detalle.

## 14. Criterios de aceptación generales

- El tablero ofrece todos los indicadores del pedido con los seis filtros y cada rol tiene su propio tablero.
