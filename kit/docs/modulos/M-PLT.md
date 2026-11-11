# M-PLT · Plataforma técnica

Fase 0 · Cimientos · Épica `PLT` · Reglas `RN-PLT-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Dar a todos los módulos una base común: API primero, eventos de dominio, colas, archivos, búsqueda, papelera, exportación y tareas programadas, para que ninguna épica funcional reinvente estos servicios.

## 2. Alcance

- API REST versionada (`/api/v1`), contrato OpenAPI y tipos compartidos; toda la lógica vive en servicios, no en la interfaz.
- Acciones explícitas de estado (`POST /recurso/{id}/accion`) validadas contra `05-maquinas-de-estado.md`.
- Eventos de dominio con bandeja de salida (outbox), consumidores idempotentes y reintentos.
- Colas y trabajos en segundo plano con progreso visible (cálculos, importaciones, exportaciones, generación masiva de PDF).
- Almacenamiento de archivos con enlaces firmados de corta duración, antivirus y control de descargas.
- Búsqueda global que respeta permisos; papelera con restauración; exportación CSV/Excel/PDF con permisos; paginación en servidor, índices y caché.
- Organización demo coherente (semilla) que recorre el ciclo completo.

## 3. Fuera de alcance

- Reglas de negocio de cada módulo (viven en su épica).
- Infraestructura concreta del proveedor de nube y decisiones de despliegue (documento de arquitectura).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §1 Arquitectura SaaS multitenant; §60 Seguridad de información RRHH; §70 Búsqueda global; §72 Exportación; §90 Papelera; §100 Validaciones; §101 Performance; §102 Arquitectura de datos; §103 Event-driven; §104 Integridad referencial; §113 Demo; §115 Persistencia; §117 Diseño API-first.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Todos los usuarios usan estos servicios de forma indirecta. El Superadmin SaaS observa su salud (ver M-SAS).

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `audit.trash.view` | Ver la papelera | Administrador empresa, RRHH |
| `audit.trash.restore` | Restaurar la papelera | Administrador empresa, RRHH |
| `reports.exports.view` | Ver exportaciones de listados (CSV, Excel, PDF) | Administrador empresa, RRHH, Payroll, Recruiter, Auditor, SST, Contador |
| `reports.exports.create` | Crear exportaciones de listados (CSV, Excel, PDF) | Administrador empresa, RRHH, Payroll, Recruiter, Auditor, SST, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Acción de estado.** 1) El cliente llama `POST /recurso/{id}/accion` con clave de idempotencia. 2) El servicio comprueba permiso, alcance, módulo contratado y transición válida. 3) Aplica el cambio y escribe auditoría y evento en la misma transacción. 4) Un despachador publica el evento a consumidores internos, webhooks y automatizaciones.

**Exportación.** 1) El usuario pide exportar una vista con sus filtros. 2) El sistema recalcula en servidor qué filas y columnas puede ver (campos sensibles sin permiso se omiten). 3) Si supera el umbral de filas, se encola y se notifica al terminar. 4) Queda registro en actividad (quién, qué vista, cuántas filas, si incluyó campos sensibles).

**Búsqueda global.** 1) El usuario escribe en la paleta de comandos. 2) Se consulta el índice por tipo (personas, documentos, vacantes, candidatos, cursos, solicitudes, reportes). 3) Cada resultado se filtra con el mismo permiso y alcance que su listado de origen.

**Papelera.** 1) Eliminar marca el registro como eliminado y lo oculta. 2) Aparece en Papelera con quién y cuándo. 3) Restaurar lo devuelve con sus relaciones; la purga definitiva solo la hace la política de retención (M-PRI).

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Búsqueda global / paleta de comandos | Resultados agrupados por tipo, atajos de creación rápida según permiso, navegación por teclado | Prototipo: Analytics → Asistente → Buscar en todo (y buscador de la barra superior) |
| Procesos en segundo plano | Lista de trabajos propios con avance, resultado, errores y enlace de descarga temporal | Prototipo: Barra superior → Procesos |
| Papelera | Registros eliminados por módulo, quién y cuándo; restaurar | Prototipo: Configuración → Papelera |
| Modal de exportación | Formato, columnas permitidas, aviso de datos sensibles incluidos; copia o descarga | Prototipo: Personas → Colaboradores → Exportar CSV |

## 8. Datos principales

Bandeja de salida de eventos (evento, carga, estado de entrega, intentos), trabajos en segundo plano, archivos (ubicación, hash, tamaño, tipo, propietario, clasificación de sensibilidad), claves de idempotencia, índice de búsqueda, registro de exportaciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PLT.

- **RN-PLT-01**: Si una petición de cambio de estado no corresponde a una transición de la máquina de la entidad → se rechaza con error de validación y no se modifica nada.
- **RN-PLT-02**: Si una acción llega dos veces con la misma clave de idempotencia → se devuelve el resultado original y no se repite el efecto.
- **RN-PLT-03**: Si un cambio de negocio se confirma → su evento y su auditoría se escriben en la misma transacción; si falla cualquiera, no se confirma ninguno.
- **RN-PLT-04** (parámetro): Si la entrega de un evento a un consumidor falla → se reintenta con espera creciente hasta el número máximo de intentos (parámetro) y luego pasa a «fallido» visible en monitoreo.
- **RN-PLT-05**: Si un usuario exporta o busca → solo obtiene filas dentro de su alcance y columnas para las que tiene permiso de campo; nunca se confía en lo que la interfaz ocultó.
- **RN-PLT-06** (parámetro): Si una exportación supera el umbral de filas (parámetro) → se ejecuta en segundo plano y se entrega por enlace temporal.
- **RN-PLT-07** (parámetro): Si se descarga un archivo → el enlace firmado caduca tras el tiempo configurado (parámetro) y la descarga queda en el registro de accesos cuando el archivo es sensible.
- **RN-PLT-08**: Si se elimina un registro con historia laboral (relación laboral, boleta, marcación, contrato firmado) → la eliminación se rechaza; solo procede archivar o anular según su máquina de estados.
- **RN-PLT-09**: Si se restaura un registro cuya referencia padre sigue eliminada → se exige restaurar primero el padre.
- **RN-PLT-10** (parámetro): Si un archivo subido supera el tamaño máximo o no es de un tipo permitido (parámetros) o no pasa el antivirus → se rechaza con mensaje claro.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- Publica todos los eventos de dominio de los módulos (no define eventos de negocio propios).
- `export.completed`, `job.failed` (técnicos, para notificación y monitoreo).

**Consume:**

- Todos: los enruta a notificaciones (M-NOT), automatizaciones (M-WFL), webhooks (M-API) y analítica (M-ANA).

## 12. Integraciones

Almacenamiento compatible con S3, antivirus, motor de búsqueda y colas, todos detrás de adaptadores con implementación falsa para pruebas.

## 13. Casos borde

- Evento duplicado por reintento: el consumidor lo descarta por identificador de evento.
- Exportación pedida y, antes de terminar, se le retira el permiso al usuario: se cancela la entrega.
- Trabajo largo interrumpido por despliegue: se reanuda desde el último lote confirmado.
- Organización suspendida: la API responde solo lectura o bloqueo según el estado (ver M-SAS).

## 14. Criterios de aceptación generales

- Todo recurso tiene listado paginado en servidor, filtros, orden y acciones de estado documentadas en OpenAPI.
- Ninguna transición es posible por edición directa del campo estado.
- La semilla demo permite recorrer vacante → candidato → contratación → onboarding → asistencia → planilla → evaluación → capacitación → vacaciones → offboarding sin cargar datos a mano.
- Pruebas de carga con organizaciones de 50, 500, 5 000 y 50 000 trabajadores sin degradación de listados ni de cálculo por lotes.
