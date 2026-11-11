# 07 · Permisos y roles

GestiaHR · kit de desarrollo. Desarrolla la corrección C-10 (permisos por campo y alcance como capacidad de base) y los puntos §60, §61, §62 y §105 del pedido.

## 1. Modelo de autorización

Una petición se autoriza solo si pasan, en este orden, cuatro comprobaciones en el servidor:

1. **Módulo contratado.** La organización tiene activo el módulo comercial al que pertenece el permiso (sección 4).
2. **Permiso de acción.** Alguno de los roles del usuario contiene el permiso `modulo.recurso.accion`.
3. **Alcance.** El registro pedido cae dentro del alcance con que ese rol fue asignado al usuario.
4. **Permiso de campo.** Los campos sensibles de la respuesta se incluyen solo si el usuario tiene el permiso de campo correspondiente; si no, **no viajan** (no se enmascaran en el cliente).

Formato del permiso: `modulo.recurso.accion`, en minúsculas y en inglés, igual que rutas, tablas y eventos (`03-convenciones.md`). Este documento adopta la opción (c) de la decisión abierta D-29: **código en inglés, etiqueta traducida** (es, en, pt) en la pantalla de roles. La columna «Qué permite» de la sección 5 es esa etiqueta en español.

- `modulo` = módulo de dominio de la API (`people`, `time`, `payroll`, `recruiting`…), el mismo prefijo de `api/openapi.yaml`.
- `recurso` = recurso de la API en plural (`employments`, `vacation_requests`, `periods`).
- `accion` = `view`, `create`, `update` o la acción de estado de `05-maquinas-de-estado.md` (`approve`, `close`, `reopen`…).
- Permiso por campo: `modulo.recurso.campo.view|edit` (`people.employments.salary.view`).
- Dos valores especiales de `x-permission` en la API no son permisos de rol: `public` (portal de empleos, canal de denuncias, enlaces de oferta y firma) y `authenticated` (lo propio de cualquier usuario con sesión: su perfil, sus notificaciones, sus pendientes).

**Fuente del catálogo.** La sección 5 parte de los permisos que `api/openapi.yaml` declara en cada ruta (`x-permission`) y les añade: a) los permisos de campo; b) las acciones funcionales que las máquinas de estado y las reglas exigen y que el contrato de la API aún no declara, marcadas **(+)**. Cada **(+)** es un pendiente del contrato: al cerrar D-29 debe añadirse a `kit/tools/gen_openapi.py` y a la semilla `database/catalogos/permisos_y_roles.json`. **Pendiente de regeneración:** esa semilla y los `INSERT` de permisos de `database/schema.sql` se generaron desde una versión anterior de este documento, con códigos en español por épica (`col.salario.ver`, 404 permisos). Deben regenerarse desde la sección 5 de esta versión (el formato de las tablas es el mismo; se añaden la marca **(+)** y los códigos de campo de cuatro partes).

Equivalencia con los ejemplos del pedido: `Empleado.salario.ver` → `people.employments.salary.view`; `Empleado.salario.editar` → `people.employments.salary.edit`; `Empleado.documentos.ver` → `documents.documents.view`; `Planilla.procesar` → `payroll.periods.calculate`; `Planilla.aprobar` → `payroll.periods.approve`; `Planilla.cerrar` → `payroll.periods.close`; `Reclutamiento.crear` → `recruiting.requisitions.create`; `SST.administrar` → conjunto `sst.*`. El prototipo (`core.html`) usa nombres abreviados en español (`colaborador.ver`, `colaborador.salario.ver`, `estructura.administrar`, `usuarios.administrar`, `roles.administrar`, `auditoria.ver`, `datos.exportar`) que corresponden a `people.employments.view`, `people.employments.salary.view`, `org.units.update` (y demás `org.*`), `identity.users.update`, `identity.roles.update`, `audit.logs.view` y `reports.exports.create`.

Entidades: usuarios, roles, permisos (catálogo fijo que publica el producto), permisos por rol, asignaciones de rol (usuario + rol + alcance + vigencia). Un usuario puede tener varias asignaciones; sus permisos efectivos son la **unión por alcance**: cada permiso vale sobre la unión de los alcances de las asignaciones que lo contienen.

Principio de mínimo privilegio: un rol nuevo nace sin permisos; los roles de sistema traen lo imprescindible para su función; todo lo sensible es un permiso aparte.

## 2. Permisos por campo sensible

Marcados **(S)** en el catálogo. Reglas:

- Un permiso de campo se suma al permiso de acción: para ver el sueldo de alguien hacen falta `col.colaborador.ver` (dentro del alcance) **y** `col.salario.ver`.
- Sin el permiso, el campo no aparece en pantallas, exportaciones, reportes, búsqueda, filtros, ordenamientos, API, webhooks, notificaciones ni respuestas de la IA.
- Toda lectura o descarga de un dato **(S)** inserta una fila en el registro de accesos sensibles (quién, qué dato, de qué registro, cuándo, por qué canal).
- Los datos **(S)** se cifran en reposo a nivel de campo.
- Categorías de datos sensibles y su permiso:

| Categoría | Datos | Permiso de lectura | Permiso de edición |
|---|---|---|---|
| Datos personales | Documento, nacimiento, dirección, estado civil, contacto de emergencia | `col.personal.ver` | `col.personal.editar` |
| Compensación | Sueldo, bonos, historial salarial, bandas, propuestas | `col.salario.ver`, `com.compensacion.ver`, `com.banda.ver` | `col.salario.editar`, `com.propuesta.crear` |
| Datos de pago | Banco, cuenta, billetera | `col.pago.ver` | `col.pago.editar` |
| Resultados de planilla | Boletas ajenas, resultados por concepto, traza, liquidaciones | `per.resultado.ver`, `nom.traza.ver` | — (se recalcula, no se edita) |
| Costos | Presupuesto de posiciones, costo por hora, costo de beneficios, indicadores de costo | `pos.presupuesto.ver`, `tsh.costo.ver`, `bnf.costo.ver`, `ana.costo.ver` | — |
| Salud | Aptitud y exámenes ocupacionales, certificados médicos, discapacidad | `sst.salud.ver`, `aus.sustento.ver`, `col.salud.ver` | `sst.salud.administrar` |
| Biometría | Plantillas biométricas, selfies de marcación | `asi.biometria.administrar` | `asi.biometria.administrar` |
| Denuncias | Contenido del caso e identidades | `den.denuncia.ver` (solo asignados) | `den.denuncia.investigar` |
| Sanciones | Casos y medidas disciplinarias, motivo detallado de cese | `dis.caso.ver`, `off.motivo.ver` | `dis.caso.administrar` |
| Talento reservado | Resultados de evaluación ajenos, 9-box, sucesión, riesgo de rotación | `des.resultado.ver`, `car.ninebox.ver`, `car.sucesion.ver`, `car.riesgo.ver` | `careers.succession_plans.update`, `careers.talent_reviews.update` |
| Afiliación sindical | Quién está afiliado | `sin.afiliacion.ver` | `sin.afiliacion.administrar` |
| Opinión | Comentarios abiertos de encuestas | `enc.comentario.ver` | — |
| Documentos confidenciales | Tipos documentales marcados confidenciales, solicitudes confidenciales | `doc.confidencial.ver`, `hsd.ticket.confidencial` | — |

- Los campos personalizados pueden marcarse sensibles; entonces se crea su permiso de campo `modulo.recurso.cf_<clave>.view|edit` dentro del recurso de la entidad.

## 3. Alcance

El alcance define **sobre qué registros** vale un permiso. Se fija en la asignación del rol al usuario (y, opcionalmente, distinto por módulo dentro del mismo rol).

| Alcance | Registros incluidos |
|---|---|
| Organización | Todas las empresas del grupo |
| Empresa | Una o varias razones sociales indicadas |
| Sede | Una o varias sedes |
| Unidad | Una unidad organizativa y sus descendientes |
| Equipo | Quienes reportan al usuario, directa o indirectamente, según la línea de reporte vigente a la fecha |
| Propio | Solo las relaciones laborales de la propia persona |
| Asignado | Solo los registros asignados al usuario (casos de denuncia, tickets de su cola, tareas, vacantes donde participa). Aparece como `○` en la matriz |

Alcance por defecto de los roles de sistema:

| Rol | Alcance por defecto | Observación |
|---|---|---|
| Superadmin SaaS | Plataforma (consola aparte) | No tiene acceso a datos laborales de las organizaciones |
| Administrador empresa | Organización | Se puede limitar a empresas concretas |
| RRHH | Organización | Se puede limitar por empresa, sede o unidad |
| Payroll | Organización | Se puede limitar por empresa (planillas separadas) |
| Recruiter | Organización | En candidatos: vacantes donde es responsable o participa |
| Manager | Equipo | Directos e indirectos |
| Empleado | Propio | |
| Auditor | Organización | Solo lectura |
| SST | Organización | Se puede limitar por sede |
| Investigador de denuncias | Asignado | Solo sus casos; no ve nada más por este rol |
| Contador | Organización | Solo lectura de planilla y asientos; se puede limitar por empresa |

Reglas de alcance:

- Un registro fuera de alcance responde «no encontrado», no «prohibido», para no revelar su existencia.
- El alcance se evalúa con datos **vigentes a la fecha**: un cambio de jefe o de unidad con fecha futura no altera el alcance hasta ese día.
- Los listados, contadores, tableros, reportes, búsquedas y la IA aplican el mismo filtro de alcance que el detalle.
- El alcance «Equipo» incluye a los indirectos solo si el rol tiene `mss.equipo.indirectos`.
- Nadie aprueba sus propios trámites ni ve su propio expediente disciplinario o denuncia como gestor, aunque su alcance lo incluya.

## 4. Cómo se combina la suscripción con los permisos

Permiso efectivo = **módulo contratado ∧ función habilitada ∧ permiso del rol ∧ alcance ∧ permiso de campo**.

- Cada permiso pertenece a un recurso; cada recurso pertenece a una épica; cada épica pertenece a un **módulo comercial** (lo que la organización contrata). Correspondencia vigente, tomada de `database/catalogos/modulos.json` (configurable en la consola SaaS):

{{MODULOS}}

- Si el módulo **no está contratado**: sus rutas de API responden «módulo no contratado», el menú y la creación rápida no lo muestran, sus pestañas de la ficha no aparecen, sus permisos no surten efecto aunque el rol los tenga y sus eventos no se emiten. En la pantalla de roles, los permisos de módulos no contratados se muestran atenuados con la leyenda «requiere el módulo X» (se pueden preconfigurar).
- **Funciones dentro de un módulo** (por ejemplo firma con certificado digital, marca blanca, constructor de reportes, número de flujos de aprobación) se controlan con funcionalidades del plan y feature flags, evaluados en el orden organización → plan → beta → país.
- **Límites del plan** (colaboradores activos, administradores, almacenamiento, llamadas de API, empresas, clientes de un estudio) no quitan permisos: bloquean la acción que superaría el límite con un mensaje claro (RN-SAS-02, RN-SAS-03).
- **Límite de administradores**: cuenta los usuarios con roles que incluyan permisos de configuración (`identity.*`, `settings.*`, `workflow.workflows.*`) (definición exacta configurable en el plan).
- **Dependencias entre módulos**: columnas «Requiere» y «Recomienda» de la tabla. People Analytics muestra solo indicadores de módulos contratados. Retirar un módulo del que depende otro exige confirmar el impacto.
- **Módulo retirado**: sus datos pasan a solo lectura durante la transición y luego se ocultan; reactivarlo los devuelve intactos (RN-SAS-06).
- El **Superadmin SaaS** no hereda permisos dentro de las organizaciones; para ver una organización usa la suplantación de soporte, con motivo, caducidad y registro (RN-SAS-08).
