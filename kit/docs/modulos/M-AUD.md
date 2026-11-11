# M-AUD · Auditoría, actividad y logs

Fase 0 · Cimientos · Épica `AUD` · Reglas `RN-AUD-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Dejar evidencia confiable de quién hizo qué, cuándo, desde dónde y con qué valores antes y después, separando auditoría de negocio, actividad de usuario y logs técnicos.

## 2. Alcance

- Auditoría de negocio de solo inserción: entidad, registro, acción, valores anterior y nuevo, autor, origen (web, API, WhatsApp, automatización, soporte, IA), IP cuando corresponda.
- Actividad de usuario: ingresos, vistas de fichas, descargas, exportaciones, búsquedas sensibles.
- Logs técnicos: errores, trazas, trabajos; solo plataforma.
- Línea de «Actividad» por registro (ficha, candidato, ticket, caso).
- Verificación de integridad (encadenado de huellas) y exportación para auditorías externas.

## 3. Fuera de alcance

- Historia laboral con vigencia (M-HIS): es dato de negocio, no auditoría.
- Retención y purga (M-PRI define plazos; aquí se aplican).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §63 Auditoría; §110 Logs técnicos.

**Correcciones que incorpora:** C-07 Auditoría, actividad y logs separados; asistencia inalterable.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Auditor y Administrador consultan. Cada módulo escribe. Nadie edita.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `audit.logs.view` | Ver la auditoría de negocio | Administrador empresa, Auditor |
| `audit.logs.export` **(+)** | Exportar auditoría para revisiones externas | Administrador empresa, Auditor |
| `audit.activity.view` **(+)** | Ver ingresos, vistas y descargas por usuario | Administrador empresa, Auditor |
| `audit.sensitive_access.view` **(S)** | Ver el registro de accesos a datos sensibles | Administrador empresa, Auditor |
| `audit.logs.sensitive_values.view` **(S)** **(+)** | Ver valores anterior y nuevo de campos sensibles en la auditoría | Administrador empresa, Auditor |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Registro.** 1) Un servicio confirma un cambio. 2) En la misma transacción inserta la fila de auditoría con el diff por campo. 3) Los campos sensibles se guardan cifrados y se muestran solo con permiso.

**Consulta.** 1) El auditor filtra por fecha, usuario, entidad, acción u origen. 2) Abre un registro y ve antes y después. 3) Puede saltar a la ficha del registro afectado si tiene permiso.

**Verificación de integridad.** 1) Un proceso periódico recalcula la cadena de huellas. 2) Si detecta una rotura, alerta a la plataforma y a los administradores.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Auditoría | Tabla con fecha, usuario, acción, entidad, registro, antes → después, origen; filtros, búsqueda, exportación | Prototipo: Seguridad → Auditoría |
| Actividad del registro | Línea de tiempo de cambios y eventos de un colaborador u otro registro | Prototipo: Personas → Colaboradores → Ficha → pestaña Actividad |
| Actividad de usuarios | Ingresos, vistas y descargas por usuario | Prototipo: Seguridad → Auditoría → pestaña Actividad |

## 8. Datos principales

Auditoría (solo inserción, particionada por fecha), actividad de usuario, registro de accesos sensibles (M-SEG), huellas de integridad.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección AUD.

- **RN-AUD-01**: Si se crea, modifica, archiva, restaura o cambia de estado un registro de negocio → se inserta una fila de auditoría con valores anterior y nuevo por campo.
- **RN-AUD-02**: Si alguien intenta modificar o borrar una fila de auditoría por la aplicación o la API → no existe operación para hacerlo; la base lo impide por permisos del rol de conexión.
- **RN-AUD-03**: Si el campo auditado es sensible → su valor se muestra solo a quien tenga `audit.logs.sensitive_values.view` y además el permiso del campo en su módulo; los demás ven «valor protegido».
- **RN-AUD-04**: Si el cambio lo origina una automatización, una importación, soporte o la IA → la fila registra el origen y el usuario responsable de haberla lanzado.
- **RN-AUD-05**: Si se registra una denuncia anónima → la auditoría del caso no guarda IP, dispositivo ni usuario del denunciante.
- **RN-AUD-06** (parámetro): Si una fila de auditoría supera el plazo de retención de su jurisdicción (parámetro) → se archiva en frío; no se borra antes del plazo legal del dato que respalda.
- **RN-AUD-07**: Si actividad, auditoría y logs técnicos se consultan → cada uno vive en almacenamiento y pantalla distintos; los logs técnicos nunca contienen datos personales en claro.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `audit.integrity_failed`.

**Consume:**

- Todos los eventos de dominio (para la línea de actividad por registro).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Cambio masivo (importación de 5 000 filas): una fila de auditoría por registro, agrupadas por identificador de lote.
- Cambio con fecha de vigencia futura: se audita el día en que se registró, con la vigencia como dato.
- Restauración desde papelera: se audita como acción propia, no como creación.

## 14. Criterios de aceptación generales

- Cambiar un sueldo muestra en auditoría «Sueldo: valor anterior → valor nuevo», autor, fecha, hora y origen.
- No existe endpoint ni pantalla de edición o borrado de auditoría.
- La exportación de auditoría respeta alcance y permisos de campo.
