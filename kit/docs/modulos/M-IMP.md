# M-IMP · Importador y puesta en marcha

Fase 1 · Core HR · Épica `IMP` · Reglas `RN-IMP-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Poner en marcha una organización en poco tiempo y cargar datos en bloque con validación, sin importar nunca a ciegas.

## 2. Alcance

- Asistente de primeros pasos: empresa, estructura, plantillas del país, usuarios, primera carga, activación de módulos; lista de avance.
- Plantillas peruanas listas: conceptos de planilla, tipos de contrato, políticas de vacaciones y asistencia, plantillas de documentos, catálogos.
- Importador CSV/Excel de colaboradores, contratos, saldos de vacaciones, salarios, asistencia, conceptos, históricos (movimientos, boletas, planillas previas), estructura, posiciones.
- Proceso: subir → mapear columnas → validar → previsualizar → importar; deshacer por lote.
- Detección de datos sucios (duplicados, formatos, faltantes) con sugerencias.

## 3. Fuera de alcance

- Migración a medida desde otros sistemas (servicio, fuera del producto). Integraciones continuas (M-INT).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §71 Importador masivo.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-27 Puesta en marcha guiada.

## 5. Actores y permisos

- Administrador y RRHH. Payroll para conceptos e históricos de planilla. Socio implementador o estudio contable (M-EST).

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `imports.imports.view` | Ver importaciones | Administrador empresa, RRHH, Payroll, Auditor |
| `imports.imports.cancel` | Cancelar importaciones | Administrador empresa, RRHH, Payroll |
| `imports.imports.create` | Crear importaciones | Administrador empresa, RRHH, Payroll |
| `imports.imports.execute` | Ejecutar importaciones | Administrador empresa, RRHH, Payroll |
| `imports.imports.map` | Mapear columnas de importaciones | Administrador empresa, RRHH, Payroll |
| `imports.imports.validate` | Validar importaciones | Administrador empresa, RRHH, Payroll |
| `imports.imports.revert` **(+)** | Deshacer un lote importado | Administrador empresa |
| `imports.setup.use` **(+)** | Usar el asistente y aplicar plantillas del paquete de país | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Importación.** 1) Se elige el tipo de datos y se descarga la plantilla (opcional). 2) Se sube el archivo. 3) El sistema propone el mapeo de columnas y recuerda mapeos anteriores. 4) Valida fila por fila (formato, catálogos, duplicados, reglas de negocio, permisos de campo). 5) Muestra vista previa con filas correctas, con advertencias y con errores. 6) El usuario corrige o descarga los errores. 7) Confirma; se importa en segundo plano. 8) Queda el informe del lote con opción de deshacer mientras sea posible.

**Puesta en marcha guiada.** 1) El asistente pide datos de la empresa. 2) Aplica plantillas del país. 3) Guía la carga de estructura y personas. 4) Propone los siguientes pasos por módulo contratado. 5) Marca el avance y sugiere lo que falta para cumplir (enlace a M-CUM).

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Primeros pasos | Lista de pasos con avance, pendientes y accesos directos | Prototipo: Inicio → panel Primeros pasos |
| Importaciones | Historial de lotes con estado, filas, errores, autor; nueva importación | Prototipo: Configuración → Importar datos |
| Asistente de importación | Pasos subir, mapear, validar, previsualizar, confirmar | Prototipo: Configuración → Importar datos |
| Resultado del lote | Resumen, registros creados o actualizados, errores descargables, deshacer | Prototipo: Configuración → Importar datos |
| Plantillas del país | Conjuntos disponibles con su contenido; aplicar | Prototipo: Configuración → Centro de configuración → sección Paquetes de país |

## 8. Datos principales

Lotes de importación, filas con estado y errores, mapeos guardados, avance de puesta en marcha, plantillas aplicadas.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección IMP.

- **RN-IMP-01**: Si un archivo no ha pasado por validación y vista previa → no se puede importar.
- **RN-IMP-02** (parámetro): Si hay filas con error → el usuario elige entre importar solo las válidas o corregir todo primero (política por tipo de datos).
- **RN-IMP-03**: Si una fila apunta a un catálogo inexistente (unidad, puesto, banco) → es error, salvo que el usuario active «crear faltantes» y tenga permiso para administrar ese catálogo.
- **RN-IMP-04**: Si la importación incluye columnas sensibles (sueldo, cuenta) → el usuario debe tener el permiso de edición de ese campo; si no, la columna se ignora con aviso.
- **RN-IMP-05**: Si se importan históricos (boletas, movimientos pasados) → se cargan como historia de solo lectura marcada «importado», sin disparar eventos de negocio ni notificaciones.
- **RN-IMP-06**: Si se importan colaboradores vigentes → cada alta respeta las mismas reglas que el alta manual (documento único, límites del plan, vigencias sin solape).
- **RN-IMP-07**: Si se deshace un lote → solo es posible mientras ningún registro del lote haya sido usado por otro proceso (planilla cerrada, firma, aprobación); en ese caso se indica qué lo impide.
- **RN-IMP-08**: Si el mismo archivo se sube dos veces → se detecta por huella y se advierte antes de continuar.
- **RN-IMP-09**: Si se importan saldos de vacaciones → entran como saldo inicial con fecha de corte y sustento, no como movimientos inventados.

## 10. Estados

- ME-41 · Importación (`05-maquinas-de-estado.md`): estados Archivo cargado, Mapeado, Validado, Con errores, Importando, Completado, Fallido, Revertido, Descartado.

## 11. Eventos

**Emite:**

- `import.validated`, `import.completed`, `import.failed`, `import.reverted`, `onboarding_setup.completed`.

**Consume:**

- `tenant.created` (inicia el asistente).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Archivo con 50 000 filas: validación y carga por lotes con avance.
- Codificación o separador inesperado: detección automática y vista previa.
- Jefes que referencian a personas del mismo archivo: resolución en dos pasadas.
- Fechas en formatos mixtos.

## 14. Criterios de aceptación generales

- Ninguna importación escribe sin vista previa confirmada.
- Una empresa nueva queda operativa con plantillas peruanas aplicadas y su plantilla cargada siguiendo solo el asistente.
