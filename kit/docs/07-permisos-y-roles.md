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

**Fuente del catálogo.** La sección 5 parte de los permisos que `api/openapi.yaml` declara en cada ruta (`x-permission`) y les añade: a) los permisos de campo; b) las acciones funcionales que las máquinas de estado y las reglas exigen y que el contrato de la API aún no declara, marcadas **(+)**. Cada **(+)** es un pendiente del contrato: al cerrar D-29 debe añadirse a `kit/tools/gen_openapi.py` y a la semilla `database/catalogos/permisos_y_roles.json`. **Pendiente de regeneración:** esa semilla y los `INSERT` de permisos de `database/schema.sql` se generaron desde una versión anterior de este documento, con códigos en español por épica (`people.employments.salary.view`, 404 permisos). Deben regenerarse desde la sección 5 de esta versión (el formato de las tablas es el mismo; se añaden la marca **(+)** y los códigos de campo de cuatro partes).

Equivalencia con los ejemplos del pedido: `Empleado.salario.ver` → `people.employments.salary.view`; `Empleado.salario.editar` → `people.employments.salary.edit`; `Empleado.documentos.ver` → `documents.documents.view`; `Planilla.procesar` → `payroll.periods.calculate`; `Planilla.aprobar` → `payroll.periods.approve`; `Planilla.cerrar` → `payroll.periods.close`; `Reclutamiento.crear` → `recruiting.requisitions.create`; `SST.administrar` → conjunto `sst.*`. El prototipo (`core.html`) usa nombres abreviados en español (`colaborador.ver`, `colaborador.salario.ver`, `estructura.administrar`, `usuarios.administrar`, `roles.administrar`, `auditoria.ver`, `datos.exportar`) que corresponden a `people.employments.view`, `people.employments.salary.view`, `org.units.update` (y demás `org.*`), `identity.users.update`, `identity.roles.update`, `audit.logs.view` y `reports.exports.create`.

Entidades: usuarios, roles, permisos (catálogo fijo que publica el producto), permisos por rol, asignaciones de rol (usuario + rol + alcance + vigencia). Un usuario puede tener varias asignaciones; sus permisos efectivos son la **unión por alcance**: cada permiso vale sobre la unión de los alcances de las asignaciones que lo contienen.

Principio de mínimo privilegio: un rol nuevo nace sin permisos; los roles de sistema traen lo imprescindible para su función; todo lo sensible es un permiso aparte.

## 2. Permisos por campo sensible

Marcados **(S)** en el catálogo. Reglas:

- Un permiso de campo se suma al permiso de acción: para ver el sueldo de alguien hacen falta `people.employments.view` (dentro del alcance) **y** `people.employments.salary.view`.
- Sin el permiso, el campo no aparece en pantallas, exportaciones, reportes, búsqueda, filtros, ordenamientos, API, webhooks, notificaciones ni respuestas de la IA.
- Toda lectura o descarga de un dato **(S)** inserta una fila en el registro de accesos sensibles (quién, qué dato, de qué registro, cuándo, por qué canal).
- Los datos **(S)** se cifran en reposo a nivel de campo.
- Categorías de datos sensibles y su permiso:

| Categoría | Datos | Permiso de lectura | Permiso de edición |
|---|---|---|---|
| Datos personales | Documento, nacimiento, dirección, estado civil, contacto de emergencia | `people.people.personal_data.view` | `people.people.personal_data.edit` |
| Compensación | Sueldo, bonos, historial salarial, bandas, propuestas | `people.employments.salary.view`, `people.compensations.view`, `compensation.bands.view` | `people.employments.salary.edit`, `compensation.proposals.create` |
| Datos de pago | Banco, cuenta, billetera | `people.people.bank_account.view` | `people.people.bank_account.edit` |
| Resultados de planilla | Boletas ajenas, resultados por concepto, traza, liquidaciones | `payroll.results.view`, `payroll.results.trace.view` | — (se recalcula, no se edita) |
| Costos | Presupuesto de posiciones, costo por hora, costo de beneficios, indicadores de costo | `org.positions.budget.view`, `time.timesheets.cost.view`, `benefits.benefits.cost.view`, `analytics.metrics.labor_cost.view` | — |
| Salud | Aptitud y exámenes ocupacionales, certificados médicos, discapacidad | `sst.medical_exams.view`, `time.absences.medical_certificate.view`, `people.people.health_data.view` | `sst.medical_exams.update` |
| Biometría | Plantillas biométricas, selfies de marcación | `time.punches.biometric.edit` | `time.punches.biometric.edit` |
| Denuncias | Contenido del caso e identidades | `grievances.cases.view` (solo asignados) | `grievances.cases.investigate` |
| Sanciones | Casos y medidas disciplinarias, motivo detallado de cese | `discipline.cases.view`, `onboarding.offboardings.exit_reason.view` | `discipline.cases.update` |
| Talento reservado | Resultados de evaluación ajenos, 9-box, sucesión, riesgo de rotación | `performance.reviews.results.view`, `careers.talent_reviews.view`, `careers.succession_plans.view`, `analytics.turnover_risk.view` | `careers.succession_plans.update`, `careers.talent_reviews.update` |
| Afiliación sindical | Quién está afiliado | `unions.memberships.view` | `unions.memberships.update` |
| Opinión | Comentarios abiertos de encuestas | `engagement.surveys.comments.view` | — |
| Documentos confidenciales | Tipos documentales marcados confidenciales, solicitudes confidenciales | `documents.documents.confidential.view`, `helpdesk.tickets.confidential.view` | — |

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
- El alcance «Equipo» incluye a los indirectos solo si el rol tiene `people.team.view_indirect`.
- Nadie aprueba sus propios trámites ni ve su propio expediente disciplinario o denuncia como gestor, aunque su alcance lo incluya.

## 4. Cómo se combina la suscripción con los permisos

Permiso efectivo = **módulo contratado ∧ función habilitada ∧ permiso del rol ∧ alcance ∧ permiso de campo**.

- Cada permiso pertenece a un recurso; cada recurso pertenece a una épica; cada épica pertenece a un **módulo comercial** (lo que la organización contrata). Correspondencia vigente, tomada de `database/catalogos/modulos.json` (configurable en la consola SaaS):

| Módulo comercial | Código | Épicas | Requiere | Recomienda |
|---|---|---|---|---|
| Plataforma base (siempre activo) | `base` | PLT, TEN, SEG, AUD, UI, WFL, NOT, CFG | — | — |
| Core HR (siempre activo) | `core` | ORG, POS, COL, HIS, CON, DOC, ESS, MSS, IMP, AYU | base | — |
| Asistencia y turnos | `attendance` | ASI, TUR, BHO | core | — |
| Vacaciones y ausencias | `time_off` | VAC, AUS | core | — |
| Hojas de tiempo | `timesheets` | TSH | core | — |
| Nómina Perú | `payroll` | NOM, CPT, PAR, PER, BOL, BSO, DEC, PRV, LIQ | core, time_off | attendance |
| Préstamos y adelanto de sueldo | `advances` | PRE | payroll | — |
| Locadores | `contractors` | LOC | core | — |
| Reclutamiento | `recruiting` | ATS, ENT | core | — |
| Onboarding y offboarding | `onboarding` | ONB, OFF | core | — |
| Desempeño y objetivos | `performance` | DES, OKR, CMP, FBK | core | — |
| Aprendizaje | `learning` | LMS | core | — |
| Carrera y sucesión | `careers` | CAR | performance | — |
| Compensaciones | `compensation` | COM | core | — |
| Beneficios | `benefits` | BNF | core | — |
| Clima y encuestas | `engagement` | ENC, REC, CIN | core | — |
| Mesa de ayuda | `helpdesk` | HSD | core | — |
| Seguridad y salud | `sst` | SST | core | — |
| Denuncias | `grievances` | DEN, DIS | core | — |
| Activos y EPP | `assets` | ACT | core | — |
| Gastos y viáticos | `expenses` | GAS | core | — |
| Cumplimiento Perú | `compliance` | CUM, PRI, SIN | core | — |
| Analítica y reportes | `analytics` | ANA, RPT, ALE | core | — |
| Asistente de IA | `ai` | IAR | core | — |
| API e integraciones | `integrations` | API, INT | base | — |
| WhatsApp | `whatsapp` | WHA | core | — |
| Marca blanca | `white_label` | WLB | base | — |
| Estudio contable | `partner` | EST | payroll | — |

- Si el módulo **no está contratado**: sus rutas de API responden «módulo no contratado», el menú y la creación rápida no lo muestran, sus pestañas de la ficha no aparecen, sus permisos no surten efecto aunque el rol los tenga y sus eventos no se emiten. En la pantalla de roles, los permisos de módulos no contratados se muestran atenuados con la leyenda «requiere el módulo X» (se pueden preconfigurar).
- **Funciones dentro de un módulo** (por ejemplo firma con certificado digital, marca blanca, constructor de reportes, número de flujos de aprobación) se controlan con funcionalidades del plan y feature flags, evaluados en el orden organización → plan → beta → país.
- **Límites del plan** (colaboradores activos, administradores, almacenamiento, llamadas de API, empresas, clientes de un estudio) no quitan permisos: bloquean la acción que superaría el límite con un mensaje claro (RN-SAS-02, RN-SAS-03).
- **Límite de administradores**: cuenta los usuarios con roles que incluyan permisos de configuración (`identity.*`, `settings.*`, `workflow.workflows.*`) (definición exacta configurable en el plan).
- **Dependencias entre módulos**: columnas «Requiere» y «Recomienda» de la tabla. People Analytics muestra solo indicadores de módulos contratados. Retirar un módulo del que depende otro exige confirmar el impacto.
- **Módulo retirado**: sus datos pasan a solo lectura durante la transición y luego se ocultan; reactivarlo los devuelve intactos (RN-SAS-06).
- El **Superadmin SaaS** no hereda permisos dentro de las organizaciones; para ver una organización usa la suplantación de soporte, con motivo, caducidad y registro (RN-SAS-08).

## 5. Catálogo de permisos y matriz de los 11 roles de sistema

Leyenda de columnas: **SA** Superadmin SaaS · **AD** Administrador empresa · **RH** RRHH · **PY** Payroll · **RC** Recruiter · **MG** Manager · **EM** Empleado · **AU** Auditor · **ST** SST · **IV** Investigador de denuncias · **CT** Contador.

`✓` concedido con el alcance por defecto del rol (sección 3). `○` concedido solo sobre lo propio o lo asignado a la persona (sus solicitudes, sus casos, sus tareas, lo que debe firmar o evaluar). Vacío = no concedido. **(S)** = permiso de campo o recurso sensible: su uso queda en el registro de accesos. **(+)** = no figura aún en `api/openapi.yaml`; debe añadirse al contrato (permisos de campo y acciones funcionales).

Totales: 908 permisos · 769 tomados de `api/openapi.yaml` · 28 de campo · 111 acciones funcionales por añadir al contrato · 108 sensibles.

### Fase 0 · Cimientos

#### PLT · Plataforma técnica

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `audit.trash.view` | Ver la papelera |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `audit.trash.restore` | Restaurar la papelera |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `reports.exports.view` | Ver exportaciones de listados (CSV, Excel, PDF) |  | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |  | ✓ |
| `reports.exports.create` | Crear exportaciones de listados (CSV, Excel, PDF) |  | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |  | ✓ |

#### TEN · Multiempresa y aislamiento

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `org.legal_entities.view` | Ver empresas (razones sociales) |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `org.legal_entities.archive` | Archivar empresas (razones sociales) |  | ✓ |  |  |  |  |  |  |  |  |  |
| `org.legal_entities.create` | Crear empresas (razones sociales) |  | ✓ |  |  |  |  |  |  |  |  |  |
| `org.legal_entities.restore` | Restaurar empresas (razones sociales) |  | ✓ |  |  |  |  |  |  |  |  |  |
| `org.legal_entities.update` | Editar empresas (razones sociales) |  | ✓ |  |  |  |  |  |  |  |  |  |
| `org.legal_entities.view_consolidated` **(+)** | Ver información consolidada de todas las empresas del grupo |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |

#### SEG · Identidad, roles, permisos por campo y alcance

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `identity.users.view` | Ver usuarios |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `identity.users.create` | Crear usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.users.invite` | Invitar usuarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `identity.users.reactivate` | Reactivar usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.users.reset_mfa` | Restablecer el MFA de usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.users.reset_password` | Restablecer la clave de usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.users.suspend` | Suspender usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.users.update` | Editar usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.roles.view` | Ver roles y su matriz de permisos |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `identity.roles.create` | Crear roles y su matriz de permisos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.roles.delete` | Eliminar roles y su matriz de permisos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.roles.duplicate` | Duplicar roles y su matriz de permisos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.roles.update` | Editar roles y su matriz de permisos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.security_policies.view` **(+)** | Ver políticas de contraseña, MFA, bloqueo y sesión |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `identity.security_policies.update` **(+)** | Configurar contraseñas, MFA por rol, bloqueo, duración de sesión y redes permitidas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.sessions.revoke` **(+)** | Ver y cerrar sesiones de otros usuarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.delegations.create` **(+)** | Delegar sus propias aprobaciones por un periodo |  | ✓ | ✓ | ✓ |  | ✓ |  |  |  |  |  |
| `identity.delegations.manage` **(+)** | Crear delegaciones a nombre de otros usuarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### SAS · Consola SaaS

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `platform.tenants.view` | Ver organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.tenants.cancel` | Cancelar organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.tenants.create` | Crear organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.tenants.reactivate` | Reactivar organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.tenants.suspend` | Suspender organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.tenants.update` | Editar organizaciones | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.view` | Ver planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.activate` | Activar planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.create` | Crear planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.duplicate` | Duplicar planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.retire` | Retirar planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.plans.update` | Editar planes y complementos | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.view` | Ver suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.change_plan` | Cambiar el plan de suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.create` | Crear suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.reactivate` | Reactivar suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.suspend` | Suspender suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.subscriptions.update` | Editar suscripciones, módulos y límites | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.feature_flags.view` | Ver funciones por organización, plan, beta o país | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.feature_flags.create` | Crear funciones por organización, plan, beta o país | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.feature_flags.update` | Editar funciones por organización, plan, beta o país | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.support_sessions.view` | Ver sesiones de soporte con suplantación | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.support_sessions.create` | Crear sesiones de soporte con suplantación | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.support_sessions.end` | Finalizar sesiones de soporte con suplantación | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.health.view` | Ver salud del servicio (errores, colas, trabajos, webhooks, almacenamiento) | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.metrics.view` | Ver tablero SaaS | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.backups.view` **(+)** | Ver historial y estado de copias | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.backups.create` **(+)** | Lanzar una copia manual | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.backups.restore` **(+)** | Restaurar una copia (con doble aprobación) | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.settings.update` **(+)** | Cambiar configuraciones globales | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.audit.view` **(+)** | Ver la auditoría de la consola | ✓ |  |  |  |  |  |  |  |  |  |  |

#### AUD · Auditoría, actividad y logs

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `audit.logs.view` | Ver la auditoría de negocio |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `audit.logs.export` **(+)** | Exportar auditoría para revisiones externas |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `audit.activity.view` **(+)** | Ver ingresos, vistas y descargas por usuario |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `audit.sensitive_access.view` **(S)** | Ver el registro de accesos a datos sensibles |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `audit.logs.sensitive_values.view` **(S)** **(+)** | Ver valores anterior y nuevo de campos sensibles en la auditoría |  | ✓ |  |  |  |  |  | ✓ |  |  |  |

#### UI · Sistema de diseño y armazón

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `settings.saved_views.view` | Ver vistas guardadas de tabla |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `settings.saved_views.create` | Crear vistas guardadas de tabla |  | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `settings.saved_views.delete` | Eliminar vistas guardadas de tabla |  | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `settings.saved_views.update` | Editar vistas guardadas de tabla |  | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `settings.saved_views.share` **(+)** | Compartir vistas con un rol o con toda la organización |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `settings.home_layouts.update_own` **(+)** | Reordenar y elegir los widgets de su inicio |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `settings.home_layouts.update_default` **(+)** | Definir el tablero por defecto de cada rol |  | ✓ |  |  |  |  |  |  |  |  |  |

#### WFL · Motor de aprobaciones y workflows

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `workflow.approval_flows.view` | Ver flujos de aprobación |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `workflow.approval_flows.create` | Crear flujos de aprobación |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.approval_flows.publish` | Publicar flujos de aprobación |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.approval_flows.retire` | Retirar flujos de aprobación |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.approval_flows.update` | Editar flujos de aprobación |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.approval_requests.view` | Ver solicitudes de aprobación |  | ✓ | ✓ | ○ | ○ | ○ | ○ | ✓ | ○ |  | ○ |
| `workflow.approval_requests.approve` | Aprobar solicitudes de aprobación |  | ○ | ○ | ○ | ○ | ○ |  |  | ○ |  | ○ |
| `workflow.approval_requests.delegate` | Delegar solicitudes de aprobación |  | ○ | ○ | ○ |  | ○ |  |  |  |  |  |
| `workflow.approval_requests.reject` | Rechazar solicitudes de aprobación |  | ○ | ○ | ○ | ○ | ○ |  |  | ○ |  | ○ |
| `workflow.approval_requests.reassign` **(+)** | Reasignar aprobaciones atascadas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `workflow.workflows.view` | Ver automatizaciones y reglas |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `workflow.workflows.activate` | Activar automatizaciones y reglas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.workflows.create` | Crear automatizaciones y reglas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.workflows.pause` | Pausar automatizaciones y reglas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.workflows.retire` | Retirar automatizaciones y reglas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.workflows.update` | Editar automatizaciones y reglas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.runs.view` | Ver ejecuciones del centro de automatizaciones |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `workflow.runs.cancel` | Cancelar ejecuciones del centro de automatizaciones |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.runs.retry` | Reintentar ejecuciones del centro de automatizaciones |  | ✓ |  |  |  |  |  |  |  |  |  |
| `workflow.tasks.view` | Ver tareas internas |  | ✓ | ✓ | ○ | ○ | ✓ | ○ |  | ✓ |  |  |
| `workflow.tasks.cancel` | Cancelar tareas internas |  | ✓ | ✓ |  | ✓ | ✓ |  |  | ✓ |  |  |
| `workflow.tasks.complete` | Completar tareas internas |  | ✓ | ✓ | ○ | ○ | ✓ | ○ |  | ✓ |  |  |
| `workflow.tasks.create` | Crear tareas internas |  | ✓ | ✓ |  | ✓ | ✓ |  |  | ✓ |  |  |
| `workflow.tasks.update` | Editar tareas internas |  | ✓ | ✓ |  | ✓ | ✓ |  |  | ✓ |  |  |

#### NOT · Notificaciones y mensajería

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `notifications.templates.view` | Ver plantillas de mensajes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `notifications.templates.create` | Crear plantillas de mensajes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `notifications.templates.update` | Editar plantillas de mensajes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `notifications.deliveries.view` **(+)** | Ver entregas y fallos de mensajes |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `notifications.channels.update` **(+)** | Configurar canales, remitentes y horarios de silencio |  | ✓ |  |  |  |  |  |  |  |  |  |
| `time.calendar.view` | Ver el calendario central de RRHH |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  |  |

#### CFG · Configuración, parámetros, campos personalizados, i18n y multimoneda

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `settings.organization.view` | Ver la configuración de la organización y de cada empresa |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `settings.organization.update` | Editar la configuración de la organización y de cada empresa |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.custom_fields.view` | Ver campos personalizados |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `settings.custom_fields.create` | Crear campos personalizados |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.custom_fields.delete` | Eliminar campos personalizados |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.custom_fields.update` | Editar campos personalizados |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.exchange_rates.view` | Ver monedas y tipos de cambio |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `settings.exchange_rates.create` | Crear monedas y tipos de cambio |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `settings.catalogs.update` **(+)** | Editar catálogos, tipos y motivos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `settings.parameters.view` **(+)** | Ver parámetros y su historial de vigencias |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `settings.parameters.update` **(+)** | Cambiar parámetros de política con vigencia |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.holiday_calendars.view` | Ver calendarios y feriados |  | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ |  |  |  |
| `time.holiday_calendars.create` | Crear calendarios y feriados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.holiday_calendars.update` | Editar calendarios y feriados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### WEB · Sitio comercial gestiahr.com

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `platform.web_products.view` | Ver productos de la web | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_products.hide` | Ocultar productos de la web | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_products.publish` | Publicar productos de la web | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_products.update` | Editar productos de la web | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_leads.view` | Ver leads del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_leads.assign` | Asignar leads del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_leads.convert` | Convertir leads del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_leads.discard` | Descartar leads del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_leads.update` | Editar leads del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_demo_bookings.view` | Ver demos agendadas desde el sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_demo_bookings.cancel` | Cancelar demos agendadas desde el sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_demo_bookings.complete` | Completar demos agendadas desde el sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_demo_bookings.update` | Editar demos agendadas desde el sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_complaints.view` | Ver libro de reclamaciones del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_complaints.answer` | Responder libro de reclamaciones del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_complaints.close` | Cerrar libro de reclamaciones del sitio | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_settings.view` | Ver ajustes del sitio (chatbot, popups, cookies y prueba social) | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.web_settings.update` | Editar ajustes del sitio (chatbot, popups, cookies y prueba social) | ✓ |  |  |  |  |  |  |  |  |  |  |

### Fase 1 · Core HR

#### ORG · Estructura organizacional

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `org.units.view` | Ver unidades organizativas |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `org.units.archive` | Archivar unidades organizativas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.units.create` | Crear unidades organizativas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.units.move` | Mover unidades organizativas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.units.restore` | Restaurar unidades organizativas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.units.update` | Editar unidades organizativas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.sites.view` | Ver sedes |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `org.sites.archive` | Archivar sedes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.sites.create` | Crear sedes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.sites.restore` | Restaurar sedes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.sites.update` | Editar sedes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.cost_centers.view` | Ver centros de costo |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `org.cost_centers.archive` | Archivar centros de costo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.cost_centers.create` | Crear centros de costo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.cost_centers.restore` | Restaurar centros de costo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.cost_centers.update` | Editar centros de costo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.job_profiles.view` | Ver puestos de catálogo, niveles y categorías |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `org.job_profiles.archive` | Archivar puestos de catálogo, niveles y categorías |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.job_profiles.create` | Crear puestos de catálogo, niveles y categorías |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.job_profiles.restore` | Restaurar puestos de catálogo, niveles y categorías |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.job_profiles.update` | Editar puestos de catálogo, niveles y categorías |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.directory.view` | Ver el directorio y el organigrama |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |

#### POS · Posiciones y headcount

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `org.positions.view` | Ver posiciones |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  |  | ✓ |
| `org.positions.approve` | Aprobar posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.close` | Cerrar posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.create` | Crear posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.freeze` | Congelar posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.unfreeze` | Descongelar posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.update` | Editar posiciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.position_requests.create` **(+)** | Solicitar una nueva posición o un reemplazo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `org.headcount_plans.view` | Ver planes y escenarios de plantilla |  | ✓ | ✓ |  |  |  |  | ✓ |  |  | ✓ |
| `org.headcount_plans.approve` | Aprobar planes y escenarios de plantilla |  | ✓ |  |  |  |  |  |  |  |  |  |
| `org.headcount_plans.close` | Cerrar planes y escenarios de plantilla |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.headcount_plans.create` | Crear planes y escenarios de plantilla |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.headcount_plans.submit` | Enviar planes y escenarios de plantilla |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.headcount_plans.update` | Editar planes y escenarios de plantilla |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `org.positions.budget.view` **(S)** **(+)** | Ver costo presupuestado y proyectado de posiciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  | ✓ |

#### COL · Colaboradores y ficha 360

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `people.employments.view` | Ver colaboradores (relaciones laborales) |  | ✓ | ✓ | ✓ | ✓ | ✓ | ○ | ✓ | ✓ |  | ✓ |
| `people.employments.activate` | Activar colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.cancel_termination` | Revertir el cese de colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.create` | Crear colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.suspend` | Suspender colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.terminate` | Terminar o cesar colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.update` | Editar colaboradores (relaciones laborales) |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.people.view` | Ver personas |  | ✓ | ✓ | ✓ | ✓ | ✓ | ○ | ✓ | ✓ |  | ✓ |
| `people.people.create` | Crear personas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.people.merge` | Fusionar personas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `people.people.update` | Editar personas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.dependents.view` | Ver derechohabientes |  | ✓ | ✓ | ✓ |  |  | ○ | ✓ |  |  |  |
| `people.dependents.create` | Crear derechohabientes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `people.dependents.delete` | Eliminar derechohabientes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `people.dependents.update` | Editar derechohabientes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `people.change_requests.view` | Ver solicitudes de cambio de datos |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `people.change_requests.approve` | Aprobar solicitudes de cambio de datos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.change_requests.cancel` | Cancelar solicitudes de cambio de datos |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `people.change_requests.create` | Crear solicitudes de cambio de datos |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `people.change_requests.reject` | Rechazar solicitudes de cambio de datos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.people.personal_data.view` **(S)** **(+)** | Ver documento, nacimiento, dirección, estado civil y contacto de emergencia |  | ✓ | ✓ | ✓ |  |  |  | ✓ | ✓ |  |  |
| `people.people.personal_data.edit` **(S)** **(+)** | Editar datos personales sensibles |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.employments.salary.view` **(S)** **(+)** | Ver sueldo, bonos e historial salarial |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `people.employments.salary.edit` **(S)** **(+)** | Modificar la compensación |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `people.people.bank_account.view` **(S)** **(+)** | Ver banco, cuenta y billetera |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `people.people.bank_account.edit` **(S)** **(+)** | Editar datos de pago |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `people.people.health_data.view` **(S)** **(+)** | Ver datos de salud y condición de discapacidad |  |  | ✓ |  |  |  |  |  | ✓ |  |  |

#### HIS · Historia con vigencia

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `people.assignments.view` | Ver movimientos de puesto, unidad, sede, jefe y horario |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `people.assignments.cancel` | Cancelar movimientos de puesto, unidad, sede, jefe y horario |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.assignments.create` | Crear movimientos de puesto, unidad, sede, jefe y horario |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.assignments.correct` **(+)** | Corregir o anular un movimiento ya vigente |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.assignments.bulk_create` **(+)** | Registrar movimientos en bloque |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `people.assignments.propose` **(+)** | Proponer un movimiento para su equipo |  |  |  |  |  | ✓ |  |  |  |  |  |
| `people.compensations.view` **(S)** | Ver registros de compensación con vigencia |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `people.compensations.cancel` **(S)** | Cancelar registros de compensación con vigencia |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `people.compensations.create` **(S)** | Crear registros de compensación con vigencia |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### CON · Contratos y adendas

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `contracts.contracts.view` | Ver contratos |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `contracts.contracts.create` | Crear contratos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.issue` | Emitir contratos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.renew` | Renovar contratos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.terminate` | Terminar o cesar contratos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.update` | Editar contratos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.approve` **(+)** | Aprobar contratos y renovaciones antes de la firma |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contracts.bulk_renew` **(+)** | Renovar contratos en bloque |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.addenda.view` | Ver adendas |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `contracts.addenda.cancel` | Cancelar adendas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.addenda.create` | Crear adendas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.addenda.issue` | Emitir adendas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `contracts.contract_types.update` **(+)** | Administrar tipos de contrato y sus topes |  | ✓ |  |  |  |  |  |  |  |  |  |

#### DOC · Gestión documental, plantillas y firma

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `documents.documents.view` | Ver documentos |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ | ✓ |  |  |
| `documents.documents.archive` | Archivar documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.documents.create` | Crear documentos |  | ✓ | ✓ | ✓ |  |  | ○ |  | ✓ |  |  |
| `documents.documents.new_version` | Subir nueva versión de documentos |  | ✓ | ✓ | ✓ |  |  | ○ |  | ✓ |  |  |
| `documents.documents.restore` | Restaurar documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.documents.update` | Editar documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.documents.approve` **(+)** | Aprobar o rechazar documentos entregados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.documents.request` **(+)** | Solicitar documentos a colaboradores |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.templates.view` | Ver plantillas de documentos |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `documents.templates.create` | Crear plantillas de documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.templates.generate` | Generar documentos desde plantillas de documentos |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `documents.templates.publish` | Publicar plantillas de documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.templates.retire` | Retirar plantillas de documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.templates.update` | Editar plantillas de documentos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `documents.signature_requests.view` | Ver envíos de firma |  | ✓ | ✓ | ✓ |  | ○ | ○ | ✓ |  |  |  |
| `documents.signature_requests.cancel` | Cancelar envíos de firma |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `documents.signature_requests.create` | Crear envíos de firma |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `documents.signature_requests.decline` | Rechazar (firmante o candidato) envíos de firma |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `documents.signature_requests.remind` | Enviar recordatorio de envíos de firma |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `documents.signature_requests.send` | Enviar envíos de firma |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `documents.signature_requests.sign` | Firmar envíos de firma |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `documents.signature_requests.evidence` **(+)** | Descargar la constancia de evidencia de firma |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `documents.document_types.update` **(+)** | Administrar tipos, visibilidad y vencimientos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `documents.documents.confidential.view` **(S)** **(+)** | Ver documentos de tipos marcados confidenciales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### ESS · Portal del colaborador

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `people.certificates.request` **(+)** | Solicitar certificados y constancias propios |  |  |  |  |  | ○ | ○ |  |  |  |  |

#### MSS · Portal del manager

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `people.team.view` | Ver el tablero y el calendario de su equipo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `people.team.view_indirect` **(+)** | Incluir reportes indirectos en su alcance |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `people.team.view_workload` **(+)** | Ver carga de trabajo y horas de su equipo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### IMP · Importador y puesta en marcha

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `imports.imports.view` | Ver importaciones |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `imports.imports.cancel` | Cancelar importaciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `imports.imports.create` | Crear importaciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `imports.imports.execute` | Ejecutar importaciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `imports.imports.map` | Mapear columnas de importaciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `imports.imports.validate` | Validar importaciones |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `imports.imports.revert` **(+)** | Deshacer un lote importado |  | ✓ |  |  |  |  |  |  |  |  |  |
| `imports.setup.use` **(+)** | Usar el asistente y aplicar plantillas del paquete de país |  | ✓ |  |  |  |  |  |  |  |  |  |

#### AYU · Centro de ayuda

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `help.articles.view` | Ver la ayuda del producto y de la organización |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `help.org_articles.view` | Ver los artículos de la organización |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `help.org_articles.archive` | Archivar los artículos de la organización |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `help.org_articles.create` | Crear los artículos de la organización |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `help.org_articles.publish` | Publicar los artículos de la organización |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `help.org_articles.update` | Editar los artículos de la organización |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `help.escalations.view` | Ver consultas derivadas desde la ayuda |  | ✓ | ✓ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `help.escalations.create` | Crear consultas derivadas desde la ayuda |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ | ○ | ○ |
| `help.escalations.support` **(+)** | Escribir al soporte de GestiaHR desde la ayuda |  | ✓ |  |  |  |  |  |  |  |  |  |
| `help.insights.view` | Ver indicadores de uso de la ayuda |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `platform.help_articles.view` | Ver la ayuda del producto | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.help_articles.archive` | Archivar la ayuda del producto | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.help_articles.create` | Crear la ayuda del producto | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.help_articles.publish` | Publicar la ayuda del producto | ✓ |  |  |  |  |  |  |  |  |  |  |
| `platform.help_articles.update` | Editar la ayuda del producto | ✓ |  |  |  |  |  |  |  |  |  |  |

### Fase 2 · Tiempo

#### ASI · Asistencia y marcación

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.punches.view` | Ver marcaciones |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.punches.create` | Crear marcaciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.punches.create_for_crew` **(+)** | Marcar por los integrantes de su cuadrilla |  |  |  |  |  | ✓ |  |  |  |  |  |
| `time.attendance_days.view` | Ver asistencia diaria calculada |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.attendance_days.close` | Cerrar asistencia diaria calculada |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.attendance_days.justify` | Justificar asistencia diaria calculada |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.attendance_days.recalculate` | Recalcular asistencia diaria calculada |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.attendance_days.reopen` **(+)** | Reabrir la asistencia de un periodo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.attendance_days.export_legal` **(+)** | Generar el registro de asistencia para inspección |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `time.attendance_corrections.create` **(+)** | Solicitar la corrección de una marca propia o registrarla por otro |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `time.attendance_corrections.approve` **(+)** | Aprobar o rechazar correcciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.devices.view` | Ver kioskos, QR y relojes |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `time.devices.create` | Crear kioskos, QR y relojes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.devices.disable` | Deshabilitar kioskos, QR y relojes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.devices.enable` | Habilitar kioskos, QR y relojes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.devices.update` | Editar kioskos, QR y relojes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.geofences.view` | Ver geocercas |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `time.geofences.archive` | Archivar geocercas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.geofences.create` | Crear geocercas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.geofences.update` | Editar geocercas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.punches.biometric.view` **(S)** **(+)** | Ver selfies y plantillas biométricas de marcación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.punches.biometric.edit` **(S)** **(+)** | Administrar plantillas biométricas y su consentimiento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### TUR · Horarios, jornadas y turnos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.schedules.view` | Ver jornadas, horarios y políticas de tiempo |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.schedules.archive` | Archivar jornadas, horarios y políticas de tiempo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.schedules.create` | Crear jornadas, horarios y políticas de tiempo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.schedules.update` | Editar jornadas, horarios y políticas de tiempo |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.shifts.view` | Ver turnos |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.shifts.archive` | Archivar turnos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.shifts.create` | Crear turnos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.shifts.update` | Editar turnos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.shift_assignments.view` | Ver la planificación de turnos |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.shift_assignments.create` | Crear la planificación de turnos |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.shift_assignments.delete` | Eliminar la planificación de turnos |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.shift_assignments.publish` | Publicar la planificación de turnos |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.shift_assignments.update` | Editar la planificación de turnos |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.shift_assignments.swap` **(+)** | Proponer intercambio de turno |  |  |  |  |  | ○ | ○ |  |  |  |  |
| `time.shift_assignments.approve_swap` **(+)** | Aprobar intercambios |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### VAC · Vacaciones

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.vacation_requests.view` | Ver solicitudes de vacaciones |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.vacation_requests.approve` | Aprobar solicitudes de vacaciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.vacation_requests.cancel` | Cancelar solicitudes de vacaciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.vacation_requests.create` | Crear solicitudes de vacaciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.vacation_requests.reject` | Rechazar solicitudes de vacaciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.vacation_requests.submit` | Enviar solicitudes de vacaciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.vacation_requests.update` | Editar solicitudes de vacaciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.vacation_requests.validate` **(+)** | Validación final de RRHH |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.vacation_requests.schedule` **(+)** | Programar el rol anual y vacaciones colectivas |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.vacation_balances.view` | Ver saldos de vacaciones |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.vacation_balances.adjust` | Ajustar saldos de vacaciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.vacation_policies.update` **(+)** | Configurar políticas de vacaciones |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### AUS · Ausencias y permisos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.absences.view` | Ver ausencias y permisos (sin diagnóstico) |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.absences.approve` | Aprobar ausencias y permisos (sin diagnóstico) |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.absences.cancel` | Cancelar ausencias y permisos (sin diagnóstico) |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.absences.create` | Crear ausencias y permisos (sin diagnóstico) |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.absences.reject` | Rechazar ausencias y permisos (sin diagnóstico) |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.absences.submit` | Enviar ausencias y permisos (sin diagnóstico) |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.absences.update` | Editar ausencias y permisos (sin diagnóstico) |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `time.absences.create_for_others` **(+)** | Registrar ausencias a nombre de otros |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.absences.manage_subsidy` **(+)** | Gestionar subsidios y reembolsos |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `time.absence_types.view` | Ver tipos de ausencia y sus reglas |  | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ |  |  |  |
| `time.absence_types.archive` | Archivar tipos de ausencia y sus reglas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.absence_types.create` | Crear tipos de ausencia y sus reglas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.absence_types.update` | Editar tipos de ausencia y sus reglas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.absences.medical_certificate.view` **(S)** **(+)** | Ver certificados médicos y sustentos confidenciales |  |  | ✓ |  |  |  |  |  |  |  |  |

#### BHO · Horas extra y bolsa de horas

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.overtime_requests.view` | Ver horas extra |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.overtime_requests.approve` | Aprobar horas extra |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.overtime_requests.cancel` | Cancelar horas extra |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `time.overtime_requests.create` | Crear horas extra |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `time.overtime_requests.reject` | Rechazar horas extra |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.time_bank.view` | Ver la bolsa de horas |  | ✓ | ✓ | ✓ |  | ✓ | ○ | ✓ |  |  |  |
| `time.time_bank.create` | Crear la bolsa de horas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `time.overtime_policies.update` **(+)** | Configurar reglas de sobretiempo y compensación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### TSH · Hojas de tiempo por proyecto

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `time.timesheets.view` | Ver hojas de tiempo |  | ✓ | ✓ |  |  | ✓ | ○ | ✓ |  |  | ✓ |
| `time.timesheets.approve` | Aprobar hojas de tiempo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.timesheets.create` | Crear hojas de tiempo |  |  |  |  |  | ○ | ○ |  |  |  |  |
| `time.timesheets.reject` | Rechazar hojas de tiempo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.timesheets.submit` | Enviar hojas de tiempo |  |  |  |  |  | ○ | ○ |  |  |  |  |
| `time.timesheets.update` | Editar hojas de tiempo |  |  |  |  |  | ○ | ○ |  |  |  |  |
| `time.projects.view` | Ver proyectos, actividades y asignaciones |  | ✓ | ✓ |  |  | ✓ | ✓ | ✓ |  |  | ✓ |
| `time.projects.close` | Cerrar proyectos, actividades y asignaciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.projects.create` | Crear proyectos, actividades y asignaciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.projects.update` | Editar proyectos, actividades y asignaciones |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `time.timesheets.cost.view` **(S)** **(+)** | Ver costo por hora y costo por proyecto |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |

### Fase 3 · Nómina Perú

#### NOM · Motor de nómina

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.regimes.view` | Ver regímenes laborales |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.entity_annual_figures.view` | Ver ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.entity_annual_figures.create` | Crear ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.entity_annual_figures.update` | Editar ventas y dotación anuales por razón social (umbrales MYPE y tramos agrarios) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.construction_works.view` | Ver obras de construcción civil |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.construction_works.create` | Crear obras de construcción civil |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.construction_works.finish` | Dar por terminadas obras de construcción civil |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.construction_works.update` | Editar obras de construcción civil |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.construction_assignments.view` | Ver categorías de construcción civil por obra |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `payroll.construction_assignments.cancel` | Cancelar categorías de construcción civil por obra |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.construction_assignments.create` | Crear categorías de construcción civil por obra |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.results.view` **(S)** | Ver resultados de planilla por persona y concepto |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.payroll_groups.update` **(+)** | Configurar grupos, procesos y calendarios |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.results.trace.view` **(S)** **(+)** | Ver el detalle de cálculo (fórmula, entradas y parámetros) de un resultado |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |

#### CPT · Conceptos y fórmulas

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.concepts.view` | Ver conceptos y fórmulas |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.concepts.create` | Crear conceptos y fórmulas |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.concepts.publish` | Publicar conceptos y fórmulas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.concepts.retire` | Retirar conceptos y fórmulas |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.concepts.test` | Probar conceptos y fórmulas |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.concepts.update` | Editar conceptos y fórmulas |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.concepts.map` **(+)** | Mapear conceptos a códigos de declaración y cuentas contables |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### PAR · Parámetros legales con vigencia

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.legal_parameters.view` | Ver parámetros legales con vigencia |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.legal_parameters.add_value` | Añadir valores con vigencia a parámetros legales con vigencia | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.legal_parameters.create` | Crear parámetros legales con vigencia | ✓ |  |  |  |  |  |  |  |  |  |  |
| `payroll.legal_parameters.confirm` **(+)** | Confirmar un parámetro «a confirmar» antes de un cierre |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.wage_tables.view` | Ver tablas salariales por convenio (lotes de parámetros con vigencia) |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.wage_tables.create` | Crear tablas salariales por convenio (lotes de parámetros con vigencia) | ✓ |  |  |  |  |  |  |  |  |  |  |
| `payroll.wage_tables.verify` | Verificar tablas salariales por convenio (lotes de parámetros con vigencia) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### PER · Periodos, incidencias, cálculo, simulación y cierre

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.periods.view` | Ver periodos de planilla |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.periods.approve` | Aprobar periodos de planilla |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.periods.calculate` | Calcular periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.periods.close` | Cerrar periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.periods.create` | Crear periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.periods.mark_paid` | Marcar como pagado periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.periods.reject` | Rechazar periodos de planilla |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.periods.reopen` | Reabrir periodos de planilla |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.periods.simulate` | Simular periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.periods.submit` | Enviar periodos de planilla |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.incidents.view` | Ver incidencias de planilla |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.incidents.create` | Crear incidencias de planilla |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.incidents.delete` | Eliminar incidencias de planilla |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.incidents.update` | Editar incidencias de planilla |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.incidents.approve` **(+)** | Aprobar incidencias que requieren visto bueno |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### BOL · Boletas de pago

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.payslips.view` | Ver boletas de pago |  | ✓ | ○ | ✓ | ○ | ○ | ○ | ✓ | ○ |  | ✓ |
| `payroll.payslips.acknowledge` | Dar acuse de boletas de pago |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `payroll.payslips.publish` | Publicar boletas de pago |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.payslips.void` | Anular boletas de pago |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.payslips.bulk_download` **(+)** | Descarga masiva de boletas |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.payslips.update_template` **(+)** | Configurar la plantilla de boleta |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### BSO · Beneficios sociales

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.benefit_runs.view` | Ver procesos de gratificaciones, CTS y utilidades |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.benefit_runs.approve` | Aprobar procesos de gratificaciones, CTS y utilidades |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.benefit_runs.calculate` | Calcular procesos de gratificaciones, CTS y utilidades |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.benefit_runs.close` | Cerrar procesos de gratificaciones, CTS y utilidades |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.benefit_runs.create` | Crear procesos de gratificaciones, CTS y utilidades |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.benefit_runs.configure_profit_sharing` **(+)** | Registrar renta base, porcentaje y criterios de utilidades |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.benefit_runs.manage_cts` **(+)** | Administrar entidades depositarias y constancias de CTS |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### PRE · Préstamos, adelantos y adelanto de sueldo

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.loans.view` | Ver préstamos |  | ✓ |  | ✓ |  | ○ | ○ | ✓ |  |  | ✓ |
| `payroll.loans.approve` | Aprobar préstamos |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.loans.cancel` | Cancelar préstamos |  | ✓ |  | ✓ |  | ○ | ○ |  |  |  |  |
| `payroll.loans.create` | Crear préstamos |  | ✓ |  | ✓ |  | ○ | ○ |  |  |  |  |
| `payroll.loans.disburse` | Desembolsar préstamos |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.loans.reject` | Rechazar préstamos |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.loans.update` | Editar préstamos |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.salary_advances.view` | Ver adelantos de sueldo |  | ✓ |  | ✓ |  | ○ | ○ | ✓ |  |  | ✓ |
| `payroll.salary_advances.approve` | Aprobar adelantos de sueldo |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.salary_advances.cancel` | Cancelar adelantos de sueldo |  | ✓ |  | ✓ |  | ○ | ○ |  |  |  |  |
| `payroll.salary_advances.create` | Crear adelantos de sueldo |  | ✓ |  | ✓ |  | ○ | ○ |  |  |  |  |
| `payroll.salary_advances.pay` | Pagar adelantos de sueldo |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.salary_advances.reject` | Rechazar adelantos de sueldo |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.advance_policies.update` **(+)** | Configurar topes, plazos y elegibilidad |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### DEC · Declaraciones y archivos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.declaration_files.view` | Ver archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.declaration_files.create` | Crear archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.declaration_files.mark_submitted` | Marcar como presentados archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.declaration_files.rectify` | Rectificar archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.declaration_files.validate` | Validar archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.registry_profiles.view` **(S)** | Ver fichas del registro laboral (T-Registro) |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `payroll.registry_profiles.create` **(S)** | Crear fichas del registro laboral (T-Registro) |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.registry_profiles.validate` **(S)** | Validar fichas del registro laboral (T-Registro) |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.external_income_declarations.view` **(S)** | Ver declaraciones juradas de rentas de otros empleadores |  | ✓ |  | ✓ |  |  | ○ | ✓ |  |  |  |
| `payroll.external_income_declarations.create` **(S)** | Crear declaraciones juradas de rentas de otros empleadores |  | ✓ | ✓ | ✓ |  |  | ○ |  |  |  |  |
| `payroll.external_income_declarations.reject` **(S)** | Rechazar declaraciones juradas de rentas de otros empleadores |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.external_income_declarations.supersede` **(S)** | Reemplazar declaraciones juradas de rentas de otros empleadores |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.external_income_declarations.validate` **(S)** | Validar declaraciones juradas de rentas de otros empleadores |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.fifth_category_ledger.view` **(S)** | Ver el libro de retenciones de quinta categoría |  | ✓ |  | ✓ |  |  | ○ | ✓ |  |  | ✓ |
| `payroll.fifth_category_refunds.view` **(S)** | Ver devoluciones de retenciones de quinta en exceso |  | ✓ |  | ✓ |  |  | ○ | ✓ |  |  | ✓ |
| `payroll.fifth_category_refunds.close` **(S)** | Cerrar devoluciones de retenciones de quinta en exceso |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.fifth_category_refunds.compensate` **(S)** | Compensar devoluciones de retenciones de quinta en exceso |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.fifth_category_refunds.create` **(S)** | Crear devoluciones de retenciones de quinta en exceso |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.fifth_category_refunds.refund` **(S)** | Devolver al trabajador devoluciones de retenciones de quinta en exceso |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.fifth_category_refunds.request_authority_refund` **(S)** | Solicitar a la autoridad la devolución de devoluciones de retenciones de quinta en exceso |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.file_layouts.view` | Ver estructuras oficiales de archivos con vigencia |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.parametric_tables.view` | Ver tablas paramétricas de la planilla electrónica |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.file_formats.update` **(+)** | Configurar formatos de banco y mapeos |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### PRV · Provisiones y asientos contables

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.accounting_entries.view` | Ver asientos contables |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.accounting_entries.create` | Crear asientos contables |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.accounting_entries.export` | Exportar asientos contables |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `payroll.accounting_entries.post` | Contabilizar asientos contables |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.provisions.view` | Ver provisiones |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.accounting_accounts.update` **(+)** | Configurar cuentas y reglas de contabilización |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### LIQ · Liquidación de beneficios sociales

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.settlements.view` **(S)** | Ver liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  | ○ | ✓ |  |  | ✓ |
| `payroll.settlements.approve` **(S)** | Aprobar liquidaciones de beneficios sociales |  | ✓ |  |  |  |  |  |  |  |  |  |
| `payroll.settlements.calculate` **(S)** | Calcular liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.settlements.create` **(S)** | Crear liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.settlements.mark_paid` **(S)** | Marcar como pagado liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.settlements.simulate` **(S)** | Simular liquidaciones de beneficios sociales |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `payroll.settlements.update` **(S)** | Editar liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.settlements.void` **(S)** | Anular liquidaciones de beneficios sociales |  | ✓ |  | ✓ |  |  |  |  |  |  |  |

#### LOC · Locadores y cuarta categoría

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `payroll.contractors.view` | Ver locadores y contratos de servicios |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `payroll.contractors.create` | Crear locadores y contratos de servicios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `payroll.contractors.end` | Finalizar locadores y contratos de servicios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `payroll.contractors.update` | Editar locadores y contratos de servicios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `payroll.contractor_invoices.view` | Ver recibos por honorarios |  | ✓ |  | ✓ |  | ○ |  | ✓ |  |  | ✓ |
| `payroll.contractor_invoices.approve` | Aprobar recibos por honorarios |  | ✓ |  |  |  | ○ |  |  |  |  |  |
| `payroll.contractor_invoices.create` | Crear recibos por honorarios |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.contractor_invoices.mark_paid` | Marcar como pagado recibos por honorarios |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.contractor_invoices.reject` | Rechazar recibos por honorarios |  | ✓ |  |  |  | ○ |  |  |  |  |  |
| `payroll.contractor_invoices.update` | Editar recibos por honorarios |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `payroll.contractors.fees.view` **(S)** **(+)** | Ver honorarios pactados y pagados |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |

### Fase 4 · Talento

#### ATS · Reclutamiento, portal de empleos y base de talento

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `recruiting.requisitions.view` | Ver vacantes |  | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |  |  |  |
| `recruiting.requisitions.approve` | Aprobar vacantes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `recruiting.requisitions.close` | Cerrar vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.requisitions.create` | Crear vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.requisitions.hold` | Pausar vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.requisitions.publish` | Publicar vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.requisitions.submit` | Enviar vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.requisitions.update` | Editar vacantes |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.view` | Ver postulaciones |  | ✓ | ✓ |  | ✓ | ○ |  |  |  |  |  |
| `recruiting.applications.create` | Crear postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.hire` | Contratar desde postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.move_stage` | Mover de etapa postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.reject` | Rechazar postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.update` | Editar postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.applications.withdraw` | Retirar postulaciones |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.candidates.view` | Ver candidatos |  | ✓ | ✓ |  | ✓ | ○ |  |  |  |  |  |
| `recruiting.candidates.anonymize` | Anonimizar candidatos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `recruiting.candidates.create` | Crear candidatos |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.candidates.update` | Editar candidatos |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.talent_pools.view` | Ver la base de talento |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.talent_pools.create` | Crear la base de talento |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.talent_pools.delete` | Eliminar la base de talento |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.talent_pools.update` | Editar la base de talento |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.career_site.update` **(+)** | Configurar el portal de empleos y el formulario |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.referrals.create` **(+)** | Referir candidatos y postular a vacantes internas |  |  |  |  |  | ✓ | ✓ |  |  |  |  |
| `recruiting.candidates.salary_expectation.view` **(S)** **(+)** | Ver pretensión salarial del candidato |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |

#### ENT · Entrevistas y ofertas

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `recruiting.interviews.view` | Ver entrevistas |  | ✓ | ✓ |  | ✓ | ○ |  |  |  |  |  |
| `recruiting.interviews.cancel` | Cancelar entrevistas |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.interviews.complete` | Completar entrevistas |  | ✓ | ✓ |  | ✓ | ○ |  |  |  |  |  |
| `recruiting.interviews.create` | Crear entrevistas |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.interviews.update` | Editar entrevistas |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.scorecards.view` | Ver evaluaciones de entrevista |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.scorecards.create` | Crear evaluaciones de entrevista |  | ○ | ○ |  | ○ | ○ |  |  |  |  |  |
| `recruiting.offers.view` | Ver ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.accept` | Aceptar ofertas laborales (lo ejerce el candidato por enlace seguro, sin rol) |  |  |  |  |  |  |  |  |  |  |  |
| `recruiting.offers.approve` | Aprobar ofertas laborales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `recruiting.offers.create` | Crear ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.decline` | Rechazar (firmante o candidato) ofertas laborales (lo ejerce el candidato por enlace seguro, sin rol) |  |  |  |  |  |  |  |  |  |  |  |
| `recruiting.offers.send` | Enviar ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.submit` | Enviar ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.update` | Editar ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.withdraw` | Retirar ofertas laborales |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.amount.view` **(S)** **(+)** | Ver el monto de la oferta |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `recruiting.offers.amount.edit` **(S)** **(+)** | Editar el monto de la oferta |  | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |

#### ONB · Pre-onboarding y onboarding

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `onboarding.processes.view` | Ver procesos de onboarding |  | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |  |  |  |
| `onboarding.processes.cancel` | Cancelar procesos de onboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.processes.complete` | Completar procesos de onboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.processes.create` | Crear procesos de onboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.processes.start` | Iniciar procesos de onboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.processes.complete_task` **(+)** | Completar las tareas de onboarding que le asignan |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  |  |
| `onboarding.processes.review_preboarding` **(+)** | Revisar y aprobar datos y documentos de pre-ingreso |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.templates.view` | Ver plantillas de onboarding y offboarding |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `onboarding.templates.create` | Crear plantillas de onboarding y offboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.templates.publish` | Publicar plantillas de onboarding y offboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.templates.retire` | Retirar plantillas de onboarding y offboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.templates.update` | Editar plantillas de onboarding y offboarding |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### OFF · Offboarding

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `onboarding.offboardings.view` | Ver procesos de salida |  | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |  |  |  |
| `onboarding.offboardings.cancel` | Cancelar procesos de salida |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.offboardings.complete` | Completar procesos de salida |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.offboardings.create` | Crear procesos de salida |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.offboardings.start` | Iniciar procesos de salida |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.offboardings.update` | Editar procesos de salida |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `onboarding.offboardings.complete_task` **(+)** | Completar las tareas de salida que le asignan |  | ○ | ○ | ○ |  | ○ | ○ |  | ○ |  |  |
| `onboarding.offboardings.exit_reason.view` **(S)** **(+)** | Ver motivo detallado, entrevista de salida y marca de recontratable |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### DES · Evaluación de desempeño

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `performance.cycles.view` | Ver campañas de evaluación |  | ✓ | ✓ |  |  | ✓ |  | ✓ |  |  |  |
| `performance.cycles.close` | Cerrar campañas de evaluación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.cycles.create` | Crear campañas de evaluación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.cycles.launch` | Lanzar campañas de evaluación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.cycles.start_calibration` | Iniciar la calibración de campañas de evaluación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.cycles.update` | Editar campañas de evaluación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.reviews.view` | Ver evaluaciones asignadas y resultados propios publicados |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `performance.reviews.reopen` | Reabrir evaluaciones asignadas y resultados propios publicados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.reviews.share` | Publicar resultados de evaluaciones asignadas y resultados propios publicados |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `performance.reviews.submit` | Enviar evaluaciones asignadas y resultados propios publicados |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `performance.reviews.update` | Editar evaluaciones asignadas y resultados propios publicados |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `performance.reviews.calibrate` **(+)** | Ajustar calificaciones en sesiones de calibración |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.review_templates.update` **(+)** | Diseñar formularios y escalas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.reviews.results.view` **(S)** **(+)** | Ver resultados de evaluación de otras personas dentro de su alcance |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### OKR · Objetivos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `performance.objectives.view` | Ver objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ | ✓ | ✓ |  |  |  |
| `performance.objectives.activate` | Activar objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `performance.objectives.check_in` | Registrar avance en objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ | ○ |  |  |  |  |
| `performance.objectives.close` | Cerrar objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ | ○ |  |  |  |  |
| `performance.objectives.create` | Crear objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ | ○ |  |  |  |  |
| `performance.objectives.update` | Editar objetivos y resultados clave |  | ✓ | ✓ |  |  | ✓ | ○ |  |  |  |  |
| `performance.objectives.manage_company` **(+)** | Administrar objetivos de empresa y de unidad y abrir o cerrar ciclos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### CMP · Competencias y habilidades

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `performance.competencies.view` | Ver competencias, niveles y perfiles de puesto |  | ✓ | ✓ |  | ✓ | ✓ | ✓ | ✓ |  |  |  |
| `performance.competencies.archive` | Archivar competencias, niveles y perfiles de puesto |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.competencies.create` | Crear competencias, niveles y perfiles de puesto |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.competencies.update` | Editar competencias, niveles y perfiles de puesto |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.competencies.view_levels` **(+)** | Ver niveles de competencias de personas dentro de su alcance |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `performance.skills.view` | Ver habilidades |  | ✓ | ✓ |  | ✓ | ✓ | ✓ | ✓ |  |  |  |
| `performance.skills.archive` | Archivar habilidades |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.skills.create` | Crear habilidades |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.skills.update` | Editar habilidades |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `performance.skills.declare` **(+)** | Declarar habilidades propias |  |  |  |  |  | ○ | ○ |  |  |  |  |
| `performance.skills.validate` **(+)** | Validar habilidades de su equipo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### FBK · Reuniones 1:1 y feedback continuo

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `performance.one_on_ones.view` | Ver reuniones 1:1 donde participa |  | ○ | ○ |  |  | ○ | ○ |  |  |  |  |
| `performance.one_on_ones.cancel` | Cancelar reuniones 1:1 donde participa |  | ○ | ○ |  |  | ○ | ○ |  |  |  |  |
| `performance.one_on_ones.complete` | Completar reuniones 1:1 donde participa |  | ○ | ○ |  |  | ○ | ○ |  |  |  |  |
| `performance.one_on_ones.create` | Crear reuniones 1:1 donde participa |  | ○ | ○ |  |  | ○ | ○ |  |  |  |  |
| `performance.one_on_ones.update` | Editar reuniones 1:1 donde participa |  | ○ | ○ |  |  | ○ | ○ |  |  |  |  |
| `performance.feedback.view` | Ver feedback recibido y dado |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `performance.feedback.create` | Crear feedback recibido y dado |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |
| `performance.feedback.view_team` **(+)** | Ver el feedback recibido por su equipo según visibilidad |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `performance.feedback.view_usage` **(+)** | Ver indicadores de uso sin contenido |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

### Fase 5 · Desarrollo y compensación

#### LMS · Aprendizaje y plan anual de capacitación

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `learning.courses.view` | Ver cursos |  | ✓ | ✓ |  |  | ✓ | ✓ | ✓ | ✓ |  |  |
| `learning.courses.archive` | Archivar cursos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.courses.create` | Crear cursos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.courses.publish` | Publicar cursos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.courses.update` | Editar cursos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.enrollments.view` | Ver inscripciones |  | ✓ | ✓ |  |  | ✓ | ○ | ✓ | ✓ |  |  |
| `learning.enrollments.complete` | Completar inscripciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `learning.enrollments.create` | Crear inscripciones |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |  |  |
| `learning.enrollments.reset` | Reiniciar inscripciones |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.enrollments.update` | Editar inscripciones |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.training_plans.view` | Ver el plan anual de capacitación |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `learning.training_plans.approve` | Aprobar el plan anual de capacitación |  | ✓ |  |  |  |  |  |  |  |  |  |
| `learning.training_plans.close` | Cerrar el plan anual de capacitación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `learning.training_plans.create` | Crear el plan anual de capacitación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `learning.training_plans.update` | Editar el plan anual de capacitación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `learning.certificates.issue` **(+)** | Emitir y revocar certificados |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `learning.training_requests.approve` **(+)** | Aprobar solicitudes de capacitación |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |

#### CAR · Desarrollo, carrera, sucesión y 9-box

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `careers.development_plans.view` | Ver planes de desarrollo |  | ✓ | ✓ |  |  | ✓ | ○ |  |  |  |  |
| `careers.development_plans.activate` | Activar planes de desarrollo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `careers.development_plans.complete` | Completar planes de desarrollo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `careers.development_plans.create` | Crear planes de desarrollo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `careers.development_plans.update` | Editar planes de desarrollo |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `careers.paths.view` | Ver rutas de carrera |  | ✓ | ✓ |  |  | ✓ | ✓ |  |  |  |  |
| `careers.paths.archive` | Archivar rutas de carrera |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.paths.create` | Crear rutas de carrera |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.paths.update` | Editar rutas de carrera |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.succession_plans.view` **(S)** | Ver planes de sucesión |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.succession_plans.archive` **(S)** | Archivar planes de sucesión |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.succession_plans.create` **(S)** | Crear planes de sucesión |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.succession_plans.update` **(S)** | Editar planes de sucesión |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.talent_reviews.view` **(S)** | Ver sesiones de talento y matriz 9-box |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.talent_reviews.close` **(S)** | Cerrar sesiones de talento y matriz 9-box |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.talent_reviews.create` **(S)** | Crear sesiones de talento y matriz 9-box |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `careers.talent_reviews.update` **(S)** | Editar sesiones de talento y matriz 9-box |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `analytics.turnover_risk.view` **(S)** | Ver indicadores de riesgo de rotación |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### COM · Compensaciones, bandas, revisión salarial e igualdad salarial

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `compensation.bands.view` **(S)** | Ver bandas salariales |  | ✓ | ✓ | ✓ |  | ✓ |  |  |  |  |  |
| `compensation.bands.create` **(S)** | Crear bandas salariales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.bands.update` **(S)** | Editar bandas salariales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.cycles.view` | Ver ciclos de revisión salarial |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `compensation.cycles.apply` | Aplicar ciclos de revisión salarial |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `compensation.cycles.approve` | Aprobar ciclos de revisión salarial |  | ✓ |  |  |  |  |  |  |  |  |  |
| `compensation.cycles.close` | Cerrar ciclos de revisión salarial |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.cycles.create` | Crear ciclos de revisión salarial |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.cycles.open` | Abrir ciclos de revisión salarial |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.cycles.submit` | Enviar ciclos de revisión salarial |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.cycles.update` | Editar ciclos de revisión salarial |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.proposals.view` **(S)** | Ver propuestas de incremento |  | ✓ | ✓ |  |  | ○ |  |  |  |  |  |
| `compensation.proposals.approve` **(S)** | Aprobar propuestas de incremento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.proposals.create` **(S)** | Crear propuestas de incremento |  | ✓ | ✓ |  |  | ○ |  |  |  |  |  |
| `compensation.proposals.reject` **(S)** | Rechazar propuestas de incremento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.proposals.submit` **(S)** | Enviar propuestas de incremento |  | ✓ | ✓ |  |  | ○ |  |  |  |  |  |
| `compensation.proposals.update` **(S)** | Editar propuestas de incremento |  | ✓ | ✓ |  |  | ○ |  |  |  |  |  |
| `compensation.pay_equity.view` **(S)** | Ver cuadro de categorías, política salarial y reporte de brecha |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `compensation.pay_equity.create` **(S)** | Crear cuadro de categorías, política salarial y reporte de brecha |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compensation.pay_equity.update` **(S)** | Editar cuadro de categorías, política salarial y reporte de brecha |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### BNF · Beneficios

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `benefits.benefits.view` | Ver beneficios y reglas de elegibilidad |  | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ |  |  |  |
| `benefits.benefits.archive` | Archivar beneficios y reglas de elegibilidad |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.benefits.create` | Crear beneficios y reglas de elegibilidad |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.benefits.update` | Editar beneficios y reglas de elegibilidad |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.enrollments.view` | Ver inscripciones a beneficios |  | ✓ | ✓ | ✓ |  | ○ | ○ |  |  |  |  |
| `benefits.enrollments.approve` | Aprobar inscripciones a beneficios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.enrollments.create` | Crear inscripciones a beneficios |  | ✓ | ✓ |  |  | ○ | ○ |  |  |  |  |
| `benefits.enrollments.end` | Finalizar inscripciones a beneficios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.enrollments.reject` | Rechazar inscripciones a beneficios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `benefits.benefits.cost.view` **(S)** **(+)** | Ver costo por persona de los beneficios |  | ✓ | ✓ | ✓ |  |  |  |  |  |  | ✓ |

### Fase 6 · Experiencia

#### ENC · Encuestas, clima y eNPS

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `engagement.surveys.view` | Ver encuestas |  | ✓ | ✓ |  |  | ✓ |  | ✓ |  |  |  |
| `engagement.surveys.close` | Cerrar encuestas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.surveys.create` | Crear encuestas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.surveys.launch` | Lanzar encuestas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.surveys.results` | Ver resultados agregados de encuestas |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `engagement.surveys.update` | Editar encuestas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.surveys.respond` **(+)** | Responder encuestas asignadas |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `engagement.action_plans.view` | Ver planes de acción |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `engagement.action_plans.complete` | Completar planes de acción |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `engagement.action_plans.create` | Crear planes de acción |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `engagement.action_plans.update` | Editar planes de acción |  | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |
| `engagement.surveys.comments.view` **(S)** **(+)** | Ver comentarios abiertos de encuestas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### REC · Reconocimientos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `engagement.recognitions.view` | Ver reconocimientos |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |
| `engagement.recognitions.create` | Crear reconocimientos |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |
| `engagement.recognitions.hide` | Ocultar reconocimientos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.recognitions.configure` **(+)** | Configurar valores, insignias, programas y puntos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### CIN · Comunicación interna

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `engagement.announcements.view` | Ver publicaciones dirigidas a su segmento |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `engagement.announcements.acknowledge` | Dar acuse de publicaciones dirigidas a su segmento |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `engagement.announcements.archive` | Archivar publicaciones dirigidas a su segmento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.announcements.create` | Crear publicaciones dirigidas a su segmento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.announcements.publish` | Publicar publicaciones dirigidas a su segmento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.announcements.update` | Editar publicaciones dirigidas a su segmento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `engagement.announcements.create_for_team` **(+)** | Publicar solo a su equipo |  |  |  |  |  | ✓ |  |  |  |  |  |
| `engagement.announcements.view_reads` **(+)** | Ver el estado de lectura por persona |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `engagement.announcements.manage_events` **(+)** | Crear eventos y gestionar asistencia |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### HSD · Mesa de ayuda y base de conocimiento

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `helpdesk.tickets.view` | Ver solicitudes a RRHH |  | ✓ | ✓ | ○ | ○ | ○ | ○ | ✓ | ○ |  | ○ |
| `helpdesk.tickets.assign` | Asignar solicitudes a RRHH |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `helpdesk.tickets.close` | Cerrar solicitudes a RRHH |  | ✓ | ✓ | ✓ |  | ○ | ○ |  |  |  |  |
| `helpdesk.tickets.create` | Crear solicitudes a RRHH |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `helpdesk.tickets.reopen` | Reabrir solicitudes a RRHH |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `helpdesk.tickets.request_info` | Pedir información en solicitudes a RRHH |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `helpdesk.tickets.resolve` | Resolver solicitudes a RRHH |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `helpdesk.tickets.update` | Editar solicitudes a RRHH |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `helpdesk.settings.update` **(+)** | Configurar la mesa de ayuda |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `helpdesk.tickets.confidential.view` **(S)** **(+)** | Ver solicitudes de categorías confidenciales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

### Fase 7 · Operación y cumplimiento

#### SST · Seguridad y salud en el trabajo

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `sst.incidents.view` | Ver incidentes y accidentes (sin datos médicos) |  | ✓ | ✓ |  |  | ✓ |  | ✓ | ✓ |  |  |
| `sst.incidents.close` | Cerrar incidentes y accidentes (sin datos médicos) |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.incidents.create` | Crear incidentes y accidentes (sin datos médicos) |  | ✓ | ✓ |  |  | ✓ | ✓ |  | ✓ |  |  |
| `sst.incidents.investigate` | Investigar incidentes y accidentes (sin datos médicos) |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.incidents.update` | Editar incidentes y accidentes (sin datos médicos) |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.risks.view` | Ver la matriz de peligros y riesgos |  | ✓ | ✓ |  |  | ✓ |  | ✓ | ✓ |  |  |
| `sst.risks.archive` | Archivar la matriz de peligros y riesgos |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.risks.create` | Crear la matriz de peligros y riesgos |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.risks.update` | Editar la matriz de peligros y riesgos |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.inspections.view` | Ver inspecciones |  | ✓ | ✓ |  |  | ✓ |  | ✓ | ✓ |  |  |
| `sst.inspections.close` | Cerrar inspecciones |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.inspections.create` | Crear inspecciones |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.inspections.update` | Editar inspecciones |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.corrective_actions.view` | Ver medidas correctivas |  | ✓ | ✓ |  |  | ✓ |  | ✓ | ✓ |  |  |
| `sst.corrective_actions.complete` | Completar medidas correctivas |  | ○ | ○ |  |  | ○ |  |  | ✓ |  |  |
| `sst.corrective_actions.create` | Crear medidas correctivas |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.corrective_actions.update` | Editar medidas correctivas |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.corrective_actions.verify` | Verificar medidas correctivas |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.medical_exams.view` **(S)** | Ver exámenes médicos ocupacionales (detalle y resultados) |  |  |  |  |  |  |  |  | ✓ |  |  |
| `sst.medical_exams.create` **(S)** | Crear exámenes médicos ocupacionales (detalle y resultados) |  |  |  |  |  |  |  |  | ✓ |  |  |
| `sst.medical_exams.update` **(S)** | Editar exámenes médicos ocupacionales (detalle y resultados) |  |  |  |  |  |  |  |  | ✓ |  |  |
| `sst.medical_exams.view_fitness` **(S)** **(+)** | Ver solo aptitud y restricciones laborales |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |  |  |
| `sst.committees.view` | Ver comité o supervisor, reuniones y actas |  | ✓ | ✓ |  |  |  | ✓ | ✓ | ✓ |  |  |
| `sst.committees.create` | Crear comité o supervisor, reuniones y actas |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committees.update` | Editar comité o supervisor, reuniones y actas |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.view` | Ver procesos electorales del comité o supervisor |  | ✓ | ✓ |  |  |  | ✓ | ✓ | ✓ |  |  |
| `sst.elections.annul` | Anular procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.call` | Convocar procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.communicate` | Comunicar el inicio de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.count` | Escrutar procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.create` | Crear procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.form_board` | Conformar la junta electoral de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.install` | Instalar el órgano resultante de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.open_nominations` | Abrir la inscripción de candidatos de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.open_voting` | Abrir la votación de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.proclaim` | Proclamar los resultados de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.publish_candidates` | Publicar los candidatos de procesos electorales del comité o supervisor |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.elections.vote` | Votar en procesos electorales del comité o supervisor |  |  |  |  |  |  | ○ |  |  |  |  |
| `sst.committee_meetings.view` | Ver sesiones y actas del comité |  | ✓ | ✓ |  |  |  | ✓ | ✓ | ✓ |  |  |
| `sst.committee_meetings.call` | Convocar sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.cancel` | Cancelar sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.create` | Crear sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.draft_minutes` | Redactar el acta de sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.record_no_quorum` | Registrar la falta de quórum de sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.record_session` | Registrar la sesión y la asistencia de sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.sign_minutes` | Firmar el acta de sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.committee_meetings.update` | Editar sesiones y actas del comité |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `sst.records.export` **(+)** | Generar los registros obligatorios |  | ✓ |  |  |  |  |  | ✓ | ✓ |  |  |

#### DEN · Canal de denuncias y hostigamiento

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `grievances.cases.view` **(S)** | Ver denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.add_protection_measure` **(S)** | Dictar medidas de protección en denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.admit` **(S)** | Admitir denuncias |  |  |  |  |  |  |  |  |  | ✓ |  |
| `grievances.cases.assign` **(S)** | Asignar denuncias |  |  |  |  |  |  |  |  |  | ✓ |  |
| `grievances.cases.close` **(S)** | Cerrar denuncias |  |  |  |  |  |  |  |  |  | ✓ |  |
| `grievances.cases.decide` **(S)** | Decidir denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.dismiss` **(S)** | Descartar denuncias |  |  |  |  |  |  |  |  |  | ✓ |  |
| `grievances.cases.issue_report` **(S)** | Emitir el informe de denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.offer_care` **(S)** | Ofrecer atención médica y psicológica en denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.refer_to_committee` **(S)** | Trasladar al comité denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.resolve` **(S)** | Resolver denuncias |  |  |  |  |  |  |  |  |  | ✓ |  |
| `grievances.cases.start_investigation` **(S)** | Iniciar la investigación de denuncias |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.cases.investigate` **(S)** **(+)** | Registrar actuaciones, medidas y conclusiones |  |  |  |  |  |  |  |  |  | ○ |  |
| `grievances.harassment_committees.view` | Ver comités de intervención frente al hostigamiento sexual y delegados |  | ✓ | ✓ |  |  |  |  | ✓ |  | ✓ |  |
| `grievances.harassment_committees.create` | Crear comités de intervención frente al hostigamiento sexual y delegados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `grievances.harassment_committees.update` | Editar comités de intervención frente al hostigamiento sexual y delegados |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `grievances.statistics.view` **(+)** | Ver estadísticas agregadas sin datos identificables |  | ✓ | ✓ |  |  |  |  | ✓ |  | ✓ |  |
| `grievances.settings.update` **(+)** | Configurar categorías e investigadores |  | ✓ |  |  |  |  |  |  |  |  |  |
| `grievances.settings.manage_committee` **(+)** | Administrar el comité de intervención y su vigencia |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `grievances.cases.identity.view` **(S)** **(+)** | Ver la identidad del denunciante identificado y de las personas mencionadas |  |  |  |  |  |  |  |  |  | ○ |  |

#### DIS · Casos disciplinarios

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `discipline.cases.view` **(S)** | Ver casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.close` **(S)** | Cerrar casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.create` **(S)** | Crear casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.notify` **(S)** | Notificar la imputación en casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.record_statement` **(S)** | Registrar descargos en casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.resolve` **(S)** | Resolver casos disciplinarios |  | ✓ |  |  |  |  |  |  |  |  |  |
| `discipline.cases.update` **(S)** | Editar casos disciplinarios |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `discipline.cases.report` **(S)** **(+)** | Reportar hechos de su equipo a RRHH |  |  |  |  |  | ✓ |  |  |  |  |  |
| `discipline.cases.submit_defense` **(S)** **(+)** | Presentar descargos en un caso propio |  |  |  |  |  | ○ | ○ |  |  |  |  |

#### ACT · Activos, EPP y uniformes

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `assets.assets.view` | Ver activos |  | ✓ | ✓ |  |  | ✓ |  | ✓ | ✓ |  |  |
| `assets.assets.assign` | Asignar activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.create` | Crear activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.report_lost` | Marcar como perdidos activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.retire` | Retirar activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.return` | Registrar la devolución de activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.send_to_maintenance` | Enviar a mantenimiento activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.update` | Editar activos |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |
| `assets.assets.request` **(+)** | Solicitar activos para sí o para su equipo |  |  |  |  |  | ✓ | ✓ |  |  |  |  |
| `assets.ppe_deliveries.view` | Ver entregas de EPP y uniformes |  | ✓ | ✓ |  |  |  | ○ | ✓ | ✓ |  |  |
| `assets.ppe_deliveries.create` | Crear entregas de EPP y uniformes |  | ✓ |  |  |  |  |  |  | ✓ |  |  |
| `assets.ppe_deliveries.sign` | Firmar entregas de EPP y uniformes |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `assets.assets.value.view` **(S)** **(+)** | Ver valor y costo de los activos |  | ✓ |  |  |  |  |  |  |  |  | ✓ |

#### GAS · Gastos, viáticos y reembolsos

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `expenses.reports.view` | Ver informes de gastos |  | ✓ |  | ✓ |  | ✓ | ○ | ✓ |  |  | ✓ |
| `expenses.reports.approve` | Aprobar informes de gastos |  | ✓ |  |  |  | ✓ |  |  |  |  |  |
| `expenses.reports.create` | Crear informes de gastos |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `expenses.reports.mark_paid` | Marcar como pagado informes de gastos |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `expenses.reports.reject` | Rechazar informes de gastos |  | ✓ |  | ✓ |  | ✓ |  |  |  |  | ✓ |
| `expenses.reports.submit` | Enviar informes de gastos |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `expenses.reports.update` | Editar informes de gastos |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `expenses.reports.review` **(+)** | Revisión de finanzas y observaciones |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `expenses.advances.view` | Ver anticipos de viáticos y rendiciones |  | ✓ |  | ✓ |  | ✓ | ○ | ✓ |  |  | ✓ |
| `expenses.advances.approve` | Aprobar anticipos de viáticos y rendiciones |  | ✓ |  |  |  | ✓ |  |  |  |  |  |
| `expenses.advances.create` | Crear anticipos de viáticos y rendiciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `expenses.advances.mark_paid` | Marcar como pagado anticipos de viáticos y rendiciones |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `expenses.advances.reject` | Rechazar anticipos de viáticos y rendiciones |  | ✓ |  |  |  | ✓ |  |  |  |  |  |
| `expenses.advances.settle` | Rendir anticipos de viáticos y rendiciones |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `expenses.policies.view` | Ver categorías, topes y políticas de gasto |  | ✓ |  | ✓ |  |  |  | ✓ |  |  | ✓ |
| `expenses.policies.archive` | Archivar categorías, topes y políticas de gasto |  | ✓ |  |  |  |  |  |  |  |  |  |
| `expenses.policies.create` | Crear categorías, topes y políticas de gasto |  | ✓ |  |  |  |  |  |  |  |  |  |
| `expenses.policies.update` | Editar categorías, topes y políticas de gasto |  | ✓ |  |  |  |  |  |  |  |  |  |

#### CUM · Cumplimiento Perú y SUNAFIL

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `compliance.obligations.view` | Ver el tablero de cumplimiento |  | ✓ | ✓ | ✓ |  |  |  | ✓ | ✓ |  | ✓ |
| `compliance.obligations.attach_evidence` | Adjuntar evidencia en el tablero de cumplimiento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.obligations.reevaluate` | Reevaluar el tablero de cumplimiento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.obligations.update` | Editar el tablero de cumplimiento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.inspection_files.view` | Ver expedientes de inspección |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `compliance.inspection_files.create` | Crear expedientes de inspección |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.notices.view` | Ver requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `compliance.notices.answer` | Responder requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.notices.assign` | Asignar requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.notices.close` | Cerrar requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.notices.create` | Crear requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.notices.update` | Editar requerimientos de la casilla electrónica |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `compliance.ledger.view` **(S)** | Ver el libro inalterable de cumplimiento |  | ✓ |  |  |  |  |  | ✓ | ✓ | ○ |  |
| `compliance.ledger.verify` **(S)** | Verificar el libro inalterable de cumplimiento |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `compliance.deadlines.view` | Ver plazos legales en curso |  | ✓ | ✓ |  |  |  |  | ✓ | ✓ | ○ |  |
| `compliance.authority_communications.view` | Ver comunicaciones a la autoridad |  | ✓ | ✓ |  |  |  |  | ✓ |  | ○ |  |
| `compliance.authority_communications.mark_sent` | Marcar como presentadas comunicaciones a la autoridad |  | ✓ | ✓ |  |  |  |  |  |  | ○ |  |
| `compliance.authority_communications.update` | Editar comunicaciones a la autoridad |  | ✓ | ✓ |  |  |  |  |  |  | ○ |  |
| `compliance.evidence_exports.view` | Ver exportaciones de expedientes con manifiesto |  | ✓ |  |  |  |  |  | ✓ |  | ○ |  |
| `compliance.evidence_exports.create` | Crear exportaciones de expedientes con manifiesto |  | ✓ |  |  |  |  |  |  |  | ○ |  |

#### PRI · Privacidad y retención

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `privacy.consents.view` | Ver consentimientos |  | ✓ | ✓ |  |  | ○ | ○ | ✓ |  |  |  |
| `privacy.consents.create` | Crear consentimientos |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `privacy.consents.revoke` | Revocar consentimientos |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `privacy.consents.configure` **(+)** | Configurar textos y versiones de consentimiento |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `privacy.subject_requests.view` | Ver solicitudes de derechos sobre datos personales |  | ✓ | ✓ |  |  | ○ | ○ | ✓ |  |  |  |
| `privacy.subject_requests.create` | Crear solicitudes de derechos sobre datos personales |  | ○ | ○ | ○ | ○ | ○ | ○ |  | ○ |  | ○ |
| `privacy.subject_requests.reject` | Rechazar solicitudes de derechos sobre datos personales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `privacy.subject_requests.resolve` | Resolver solicitudes de derechos sobre datos personales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `privacy.subject_requests.update` | Editar solicitudes de derechos sobre datos personales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `privacy.incidents.view` | Ver incidentes de seguridad de datos |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `privacy.incidents.close` | Cerrar incidentes de seguridad de datos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.incidents.contain` | Registrar la contención de incidentes de seguridad de datos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.incidents.create` | Crear incidentes de seguridad de datos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.incidents.mark_notified` | Marcar como notificados incidentes de seguridad de datos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.incidents.update` | Editar incidentes de seguridad de datos |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.retention_policies.view` | Ver políticas de retención |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `privacy.retention_policies.create` | Crear políticas de retención |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.retention_policies.update` | Editar políticas de retención |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.people.anonymize` | Anonimizar datos de personas |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.processing_register.view` **(+)** | Ver tratamientos, bancos de datos y finalidades |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `privacy.processing_register.update` **(+)** | Administrar tratamientos, bancos de datos y finalidades |  | ✓ |  |  |  |  |  |  |  |  |  |
| `privacy.impact_assessments.update` **(+)** | Registrar evaluaciones de impacto |  | ✓ |  |  |  |  |  |  |  |  |  |

#### SIN · Sindicatos y convenios

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `unions.unions.view` | Ver sindicatos y dirigentes |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `unions.unions.archive` | Archivar sindicatos y dirigentes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.unions.create` | Crear sindicatos y dirigentes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.unions.update` | Editar sindicatos y dirigentes |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.unions.manage_leaves` **(+)** | Administrar licencias sindicales y cupos |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.memberships.view` **(S)** | Ver afiliaciones sindicales |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `unions.memberships.create` **(S)** | Crear afiliaciones sindicales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.memberships.update` **(S)** | Editar afiliaciones sindicales |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.agreements.view` | Ver convenios y cláusulas |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  | ✓ |
| `unions.agreements.activate` | Activar convenios y cláusulas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.agreements.create` | Crear convenios y cláusulas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `unions.agreements.update` | Editar convenios y cláusulas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

### Fase 8 · Inteligencia

#### ANA · People analytics y tableros

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `analytics.dashboards.view` | Ver tableros |  | ✓ | ✓ | ✓ |  | ✓ |  | ✓ | ✓ |  | ✓ |
| `analytics.dashboards.create` | Crear tableros |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `analytics.dashboards.delete` | Eliminar tableros |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `analytics.dashboards.update` | Editar tableros |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `analytics.metrics.view` | Ver indicadores no sensibles de su alcance |  | ✓ | ✓ | ✓ |  | ✓ |  | ✓ | ✓ |  | ✓ |
| `analytics.metrics.drill_down` **(+)** | Desglosar un indicador hasta el listado de personas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `analytics.metrics.labor_cost.view` **(S)** **(+)** | Ver indicadores de costo laboral y distribución salarial |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `analytics.metrics.diversity.view` **(S)** **(+)** | Ver indicadores de diversidad |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### RPT · Reportes y constructor

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `reports.reports.view` | Ver reportes |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `reports.reports.create` | Crear reportes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `reports.reports.delete` | Eliminar reportes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `reports.reports.run` | Ejecutar reportes |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `reports.reports.update` | Editar reportes |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `reports.reports.share` **(+)** | Compartir reportes con roles o personas |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `reports.reports.schedule` **(+)** | Programar entregas automáticas |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |

#### ALE · Alertas explicadas

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `alerts.alerts.view` | Ver alertas de su alcance |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  | ✓ |
| `alerts.alerts.dismiss` | Descartar alertas de su alcance |  | ✓ | ✓ | ✓ |  | ✓ |  |  | ✓ |  |  |
| `alerts.alerts.resolve` | Resolver alertas de su alcance |  | ✓ | ✓ | ✓ |  | ✓ |  |  | ✓ |  |  |
| `alerts.alerts.snooze` | Posponer alertas de su alcance |  | ✓ | ✓ | ✓ |  | ✓ |  |  | ✓ |  |  |
| `alerts.rules.view` | Ver reglas de alerta |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |
| `alerts.rules.create` | Crear reglas de alerta |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `alerts.rules.pause` | Pausar reglas de alerta |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `alerts.rules.resume` | Reanudar reglas de alerta |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `alerts.rules.update` | Editar reglas de alerta |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### IAR · Asistente de IA y gobierno

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `ai.conversations.view` | Ver conversaciones con el asistente |  | ○ | ○ | ○ | ○ | ○ |  |  | ○ |  |  |
| `ai.conversations.create` | Crear conversaciones con el asistente |  | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  |
| `ai.conversations.send_message` | Enviar mensajes en conversaciones con el asistente |  | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  |
| `ai.conversations.query_data` **(+)** | Consultar datos en lenguaje natural dentro de sus permisos |  | ✓ | ✓ | ✓ |  | ✓ |  |  |  |  |  |
| `ai.mcp.use` | Usar asistentes externos por MCP |  | ✓ | ✓ | ✓ |  | ✓ |  |  |  |  |  |
| `ai.usage_logs.view` | Ver el registro de usos de IA |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `ai.policies.update` **(+)** | Activar casos de uso, proveedor y exclusiones |  | ✓ |  |  |  |  |  |  |  |  |  |
| `ai.use_case_settings.view` | Ver la activación del asistente de ayuda y de cada caso de uso |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `ai.use_case_settings.update` | Editar la activación del asistente de ayuda y de cada caso de uso |  | ✓ |  |  |  |  |  |  |  |  |  |
| `ai.help_assistant.use` | Usar el asistente de ayuda |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ai.help_assistant.use_personal_data` **(+)** | Preguntar al asistente de ayuda por sus propios datos (fase 8) |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |

### Fase 9 · Ecosistema y expansión

#### API · API pública, webhooks y MCP

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `identity.api_tokens.view` | Ver credenciales de API y sus alcances |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `identity.api_tokens.create` | Crear credenciales de API y sus alcances |  | ✓ |  |  |  |  |  |  |  |  |  |
| `identity.api_tokens.revoke` | Revocar credenciales de API y sus alcances |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.view` | Ver suscripciones de webhook |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `integrations.webhooks.create` | Crear suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.delete` | Eliminar suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.pause` | Pausar suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.resume` | Reanudar suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.rotate_secret` | Rotar el secreto de suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.test` | Probar suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhooks.update` | Editar suscripciones de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.webhook_deliveries.view` | Ver entregas de webhook |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `integrations.webhook_deliveries.retry` | Reintentar entregas de webhook |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.events.view` | Ver el catálogo de eventos |  | ✓ |  |  |  |  |  | ✓ |  |  |  |

#### INT · Integraciones

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `integrations.connections.view` | Ver conectores |  | ✓ | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `integrations.connections.create` | Crear conectores |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.connections.disconnect` | Desconectar conectores |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.connections.sync` | Sincronizar conectores |  | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |
| `integrations.connections.test` | Probar conectores |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.connections.update` | Editar conectores |  | ✓ |  |  |  |  |  |  |  |  |  |
| `integrations.biometric.push` | Recibir marcas enviadas por relojes (credencial de dispositivo, sin rol) |  |  |  |  |  |  |  |  |  |  |  |

#### WHA · Autoservicio por WhatsApp

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `integrations.whatsapp.view` | Ver el canal de WhatsApp |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `integrations.whatsapp.update` | Editar el canal de WhatsApp |  | ✓ | ✓ |  |  |  |  |  |  |  |  |
| `integrations.whatsapp.use` **(+)** | Usar el autoservicio por WhatsApp |  |  |  |  |  | ✓ | ✓ |  |  |  |  |
| `integrations.whatsapp.view_conversations` **(+)** | Ver conversaciones derivadas a un agente |  | ✓ | ✓ |  |  |  |  |  |  |  |  |

#### PAI · Paquetes de país

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `platform.country_packs.view` | Ver paquetes de país | ✓ | ✓ |  | ✓ |  |  |  | ✓ |  |  |  |
| `platform.country_packs.activate` | Activar paquetes de país | ✓ | ✓ |  |  |  |  |  |  |  |  |  |
| `platform.country_packs.publish` **(+)** | Construir, versionar y publicar paquetes de país | ✓ |  |  |  |  |  |  |  |  |  |  |

#### WLB · Marca blanca

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `settings.branding.view` | Ver la marca de la organización |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.branding.update` | Editar la marca de la organización |  | ✓ |  |  |  |  |  |  |  |  |  |
| `settings.branding.manage_domain` **(+)** | Configurar dominio y remitente propios |  | ✓ |  |  |  |  |  |  |  |  |  |

#### EST · Modo estudio contable

| Permiso | Qué permite | SA | AD | RH | PY | RC | MG | EM | AU | ST | IV | CT |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `partner.clients.view` | Ver la cartera de clientes del estudio |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `partner.clients.create` | Crear la cartera de clientes del estudio |  | ✓ |  |  |  |  |  |  |  |  |  |
| `partner.clients.enter` | Entrar a la cartera de clientes del estudio |  | ✓ |  | ✓ |  |  |  |  |  |  | ✓ |
| `partner.clients.run_batch` **(+)** | Ejecutar acciones en lote sobre varios clientes |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `partner.clients.grant_access` **(+)** | (Cliente) otorgar y revocar el acceso del estudio |  | ✓ |  |  |  |  |  |  |  |  |  |

#### Épicas sin permisos propios

No definen recursos propios y usan los permisos de otras épicas: QA. En el portal del colaborador (ESS) todo se ejerce con alcance «Propio» (`○`) sobre los permisos de cada módulo; el armazón (UI) y la plataforma técnica (PLT) aplican los permisos del recurso que muestran; QA no tiene pantallas.

## 6. Reglas sobre roles

| Código | Regla |
|---|---|
| RP-01 | Los 11 roles de sistema no se pueden borrar ni renombrar. Sí se pueden duplicar para crear uno personalizado. |
| RP-02 | El rol Administrador empresa no pierde los permisos `identity.*`; el resto de roles de sistema puede ajustarse por organización, quedando registrado el cambio respecto de la plantilla. |
| RP-03 | Siempre queda al menos un usuario activo con el rol Administrador empresa. Se rechaza cualquier cambio (bloquear, desactivar, cambiar rol, cesar) que deje la organización sin ninguno. |
| RP-04 | Un usuario no puede concederse permisos ni asignar un rol con permisos que él mismo no tiene, salvo el Administrador empresa. |
| RP-05 | Un rol personalizado nace sin permisos y con alcance «Propio» hasta que se configure. |
| RP-06 | No se puede borrar un rol con usuarios asignados; primero se reasignan. Los roles personalizados sin uso se archivan. |
| RP-07 | Todo cambio de rol, permiso, alcance o asignación queda en auditoría con antes y después. |
| RP-08 | Los permisos **(S)** nunca se conceden «por paquete»: al duplicar un rol se copian, pero al crear un rol desde cero deben marcarse uno a uno. |
| RP-09 | Las asignaciones de rol pueden tener fecha de fin (suplencias, auditorías externas); al vencer se retiran solas. |
| RP-10 | Los roles Administrador empresa, Payroll e Investigador de denuncias exigen MFA por defecto (parámetro por rol). |
| RP-11 | El rol Investigador de denuncias no se combina por defecto con Administrador ni RRHH en la misma persona sin dejar constancia; su asignación la hace el órgano que la organización designe. |
| RP-12 | El Auditor y el Contador solo tienen permisos de lectura y exportación; el sistema impide añadirles permisos de escritura sin duplicar el rol. |
| RP-13 | Un usuario externo (contador de un estudio, auditor externo) se crea sin relación laboral, con asignación de rol con vigencia. |

## 7. Segregación de funciones

Controles que el sistema impone aunque el usuario tenga ambos permisos (se comprueban sobre la **persona**, no sobre el rol):

| Proceso | Quien hace | No puede |
|---|---|---|
| Planilla (periodo) | Calcula, recalcula o carga incidencias del periodo | Aprobar ese mismo periodo (RN-PER-01) |
| Liquidación | Calcula la liquidación | Aprobarla (RN-LIQ-06) |
| Conceptos de planilla | Edita la fórmula | Publicarla, con doble control activo (RN-CPT-07) |
| Cualquier trámite | Es el solicitante o el beneficiario | Aprobarlo (RN-SEG-13, RN-WFL-05) |
| Revisión salarial | Es el beneficiario o su subordinado | Proponer o aprobar ese incremento (RN-COM-06) |
| Gastos | Registra el gasto | Aprobarlo ni revisarlo (RN-GAS-07) |
| Marcación | Solicita la corrección | Aprobarla (ME-14) |
| SST | Ejecuta la medida correctiva | Verificarla (ME-36) |
| Disciplinario | Conduce el caso | Decidir suspensión o despido (RN-DIS-03) |
| Denuncias | Es mencionado en el caso | Verlo o investigarlo (RN-DEN-04) |
| Mesa de ayuda | Crea la solicitud | Atenderla (RN-HSD-07) |
| Restauración de copias | Solicita la restauración | Promoverla sin una segunda aprobación (RN-SAS-09) |

Por defecto el rol **Payroll** trae calcular, enviar y cerrar, y **no** trae `payroll.periods.approve`, `payroll.benefit_runs.approve` ni `payroll.settlements.approve`; esos permisos están en Administrador empresa. Lo recomendado es crear un rol personalizado «Aprobador de planilla» (jefe de RRHH, finanzas o gerencia) con `payroll.periods.view`, `payroll.results.view`, `payroll.periods.approve`, `payroll.periods.reject`, `payroll.benefit_runs.approve`, `payroll.settlements.view` y `payroll.settlements.approve`. En una empresa muy pequeña con una sola persona en planilla, la aprobación la da el Administrador o, en modo estudio contable, el contacto del cliente (RN-EST-04).

## 8. Perfil de cada rol de sistema

- **Superadmin SaaS.** Solo consola de plataforma (`platform.*`). Ningún dato laboral.
- **Administrador empresa.** Configura la organización, usuarios, roles, módulos y políticas. Tiene los permisos de administración y de decisión final. **No** tiene acceso al contenido de denuncias ni a datos médicos ocupacionales detallados salvo asignación expresa; puede crearse un «Administrador sin sueldos» retirando los permisos **(S)** de compensación.
- **RRHH.** Gestiona el ciclo del colaborador: fichas, contratos, documentos, tiempo, talento, experiencia, casos disciplinarios. Ve datos personales; ve sueldos, no los edita.
- **Payroll.** Opera la nómina: incidencias, cálculo, cierre, boletas, declaraciones, préstamos, liquidaciones. Edita compensación y datos de pago. No aprueba lo que calcula.
- **Recruiter.** Vacantes, candidatos, entrevistas y ofertas. No ve colaboradores más allá del directorio y la estructura.
- **Manager.** Ve y aprueba lo de su equipo; propone movimientos e incrementos. No ve sueldos, salud ni sanciones salvo permiso adicional (bandas y propuestas en ciclo de revisión sí, para su equipo).
- **Empleado.** Lo propio: perfil, documentos, boletas, tiempo, solicitudes, objetivos, cursos, encuestas.
- **Auditor.** Lectura de casi todo lo no reservado, auditoría y registros de acceso. Sin escritura.
- **SST.** Módulo SST completo, EPP, datos de salud ocupacional, cursos de seguridad.
- **Investigador de denuncias.** Solo los casos que tiene asignados. Nada más por este rol.
- **Contador.** Lectura de periodos, resultados, declaraciones, asientos, provisiones, liquidaciones y gastos; exporta asientos.

## 9. Roles personalizados de ejemplo (plantillas sugeridas)

| Rol | Base | Cambios |
|---|---|---|
| Administrador sin sueldos | Administrador empresa | Sin `people.employments.salary.*`, `people.compensations.*`, `people.people.bank_account.*`, `compensation.*`, `payroll.results.*`, `analytics.metrics.labor_cost.view`, `org.positions.budget.view` |
| Aprobador de planilla | (vacío) | `payroll.periods.view`, `payroll.results.view`, `payroll.periods.approve`, `payroll.periods.reject`, `payroll.benefit_runs.approve`, `payroll.settlements.view`, `payroll.settlements.approve` |
| RRHH de sede | RRHH | Alcance «Sede» |
| Supervisor de cuadrilla | Manager | Añade `time.punches.create_for_crew`, `time.shift_assignments.*` |
| Finanzas (gastos) | Contador | Añade `expenses.reports.review`, `expenses.reports.mark_paid`, `expenses.advances.mark_paid` |
| Especialista de compensaciones | RRHH | Añade `compensation.*` y `people.employments.salary.view`; sin `discipline.*` |
| Oficial de datos personales | (vacío) | `privacy.*`, `audit.sensitive_access.view`, `audit.logs.view` |
| Miembro del comité de intervención | Investigador de denuncias | Solo categoría hostigamiento sexual, alcance «Asignado» |

## 10. Verificación automática

- Cada ruta de la API declara su permiso; una prueba falla si alguna ruta queda sin declarar.
- Por cada permiso **(S)** existe una prueba que comprueba que, sin él, el campo no aparece en detalle, listado, exportación, reporte, búsqueda ni API.
- Por cada alcance existe una prueba que comprueba «no encontrado» fuera de alcance.
- La matriz de esta sección es la semilla de los roles de sistema; una prueba compara la semilla con este documento.
- Pruebas específicas: no se puede dejar la organización sin administrador (RP-03); quien calcula no aprueba (sección 7); ningún rol distinto del asignado obtiene datos de una denuncia.
