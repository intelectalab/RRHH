# M-RPT · Reportes y constructor

Fase 8 · Inteligencia · Épica `RPT` · Reglas `RN-RPT-nn` · Módulo comercial: Analítica y reportes (`analytics`)

## 1. Objetivo

Entregar reportes listos para usar y permitir crear los propios sin programar, con exportación y programación, sin abrir brechas de datos sensibles.

## 2. Alcance

- Centro de reportes con predefinidos por módulo.
- Constructor: fuente de datos → campos → filtros → agrupaciones → métricas → visualización; guardar, compartir.
- Exportar a Excel, CSV y PDF; programar entrega automática.
- Generación con lenguaje natural (IA) que produce una definición editable.
- Reportes legales del paquete de país.

## 3. Fuera de alcance

- Tableros (M-ANA).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §66 Reportes; §67 Constructor de reportes; §72 Exportación.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH, Payroll, managers, auditor, contador.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `reports.exports.view` | Ver exportaciones de listados (CSV, Excel, PDF) | Administrador empresa, RRHH, Payroll, Recruiter, Auditor, SST, Contador |
| `reports.exports.create` | Crear exportaciones de listados (CSV, Excel, PDF) | Administrador empresa, RRHH, Payroll, Recruiter, Auditor, SST, Contador |
| `reports.reports.view` | Ver reportes | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `reports.reports.create` | Crear reportes | Administrador empresa, RRHH, Payroll |
| `reports.reports.delete` | Eliminar reportes | Administrador empresa, RRHH, Payroll |
| `reports.reports.run` | Ejecutar reportes | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `reports.reports.update` | Editar reportes | Administrador empresa, RRHH, Payroll |
| `reports.reports.share` **(+)** | Compartir reportes con roles o personas | Administrador empresa, RRHH |
| `reports.reports.schedule` **(+)** | Programar entregas automáticas | Administrador empresa, RRHH, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Construir.** 1) Se elige la fuente. 2) Se seleccionan campos (solo los permitidos). 3) Filtros, agrupaciones y métricas. 4) Visualización (tabla, barras, líneas). 5) Guardar y, si se desea, compartir o programar.

**Entrega programada.** 1) Llega la hora. 2) El reporte se ejecuta con los permisos del destinatario. 3) Se entrega como enlace que exige ingresar.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Centro de reportes | Predefinidos y propios por categoría; buscar, ejecutar | Prototipo: Analytics → Reportes |
| Constructor | Pasos fuente, campos, filtros, agrupaciones, métricas, visualización; vista previa | Prototipo: Analytics → Reportes → Constructor |
| Programaciones | Reportes programados con frecuencia, destinatarios y última entrega | Prototipo: Analytics → Reportes → pestañas Programados y Entregas (simulado) |

## 8. Datos principales

Definiciones de reporte, fuentes publicadas y su diccionario, comparticiones, programaciones, ejecuciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección RPT.

- **RN-RPT-01**: Si el usuario construye un reporte → solo ve fuentes y campos de módulos contratados para los que tiene permiso, incluidos permisos de campo.
- **RN-RPT-02**: Si un reporte guardado se comparte → cada persona lo ejecuta con sus propios permisos y alcance; las columnas no permitidas se omiten.
- **RN-RPT-03**: Si un reporte programado se entrega → se ejecuta con los permisos vigentes del destinatario en ese momento y no adjunta datos sensibles en correo; entrega un enlace.
- **RN-RPT-04**: Si el destinatario perdió el acceso o cesó → la entrega se cancela y se avisa al dueño.
- **RN-RPT-05**: Si la IA genera un reporte → produce una definición visible que el usuario puede revisar y editar; se ejecuta con los permisos del usuario.
- **RN-RPT-06**: Si las fuentes de denuncias, casos disciplinarios o datos médicos existen → no están disponibles en el constructor general.
- **RN-RPT-07** (parámetro): Si la ejecución supera el límite de filas o tiempo (parámetros) → pasa a segundo plano.
- **RN-RPT-08**: Si se exporta → queda registrado en actividad con la definición usada.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `report.created`, `report.scheduled`, `report.delivered`, `report.delivery_cancelled`.

**Consume:**

- Ninguno.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Campo personalizado sensible añadido a un reporte compartido.
- Reporte sobre una fuente de un módulo que la organización dio de baja.

## 14. Criterios de aceptación generales

- El constructor guarda reportes con fuente, campos, filtros, agrupaciones, métricas y visualización, exporta en tres formatos y controla datos sensibles.
