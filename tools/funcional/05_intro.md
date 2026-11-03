# 05 · Máquinas de estado

Ayni HR · kit de desarrollo. Fuente: `SPEC.md` (regla transversal 3: los estados cambian solo por acciones explícitas).

## Cómo leer y cómo implementar

- Cada entidad con ciclo de vida tiene una máquina con código `ME-nn`. El estado se guarda en la entidad; **nunca se edita como un campo**: solo cambia mediante `POST /api/v1/<recurso>/{id}/<accion>`.
- La columna «Acción» es el nombre funcional de la transición, en español. La ruta real usa el verbo en inglés de `api/openapi.yaml`, que es el mismo verbo del permiso de la columna «Quién puede» (por ejemplo la acción `aprobar` de un periodo de planilla es `POST /payroll-periods/{id}/approve` con el permiso `payroll.periods.approve`). Si una transición no tiene todavía ruta en el contrato, se añade siguiendo `03-convenciones.md`.
- Los nombres y etiquetas de estado están en español porque son los que ve el usuario; el valor almacenado es el identificador en inglés del esquema (ver la línea «Valores en `schema.sql`» de cada máquina y la tabla de conciliación al final).
- Eventos: se nombran `agregado.hecho`. El catálogo canónico es `02-arquitectura.md` §9.2; los eventos de este documento que no figuren allí son los que la especificación funcional necesita añadir a ese catálogo.
- El servicio de cada acción hace, en una sola transacción: 1) comprobar módulo contratado, permiso y alcance; 2) comprobar que el estado actual figura en «Desde»; 3) ejecutar las validaciones; 4) aplicar los efectos; 5) insertar auditoría (antes y después); 6) insertar el evento en la bandeja de salida. Si algo falla, nada se confirma.
- «Quién puede» nombra el permiso (`modulo.recurso.accion`, ver `07-permisos-y-roles.md`) o «Sistema» cuando la transición la dispara un proceso programado o un evento. Las transiciones de sistema quedan auditadas con origen «sistema» y el evento que las causó.
- Una acción pedida desde un estado que no figura en «Desde» responde error de validación con el estado actual y las acciones permitidas. La API expone por registro la lista de acciones disponibles para el usuario; la interfaz muestra solo esos botones.
- Toda acción acepta clave de idempotencia. Repetirla devuelve el resultado original.
- Los nombres de tabla son los de `kit/database/schema.sql`.
- Los estados finales no admiten más transiciones; lo que venga después es un registro nuevo enlazado (reingreso, rectificatoria, nueva versión, liquidación complementaria).
- Plazos, umbrales y ventanas mencionados en validaciones son parámetros con vigencia (ver `06-reglas-de-negocio.md` y `14-localizacion-peru.md`).
