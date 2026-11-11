# M-SEG · Identidad, roles, permisos por campo y alcance

Fase 0 · Cimientos · Épica `SEG` · Reglas `RN-SEG-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Controlar quién entra y qué puede ver o hacer, con el detalle que piden los datos de recursos humanos: permiso por acción, por campo sensible y por alcance.

## 2. Alcance

- Persona, usuario y relación laboral separados; un usuario puede existir sin relación (consultor, contador externo) y una persona sin usuario.
- Ingreso con correo o con documento de identidad + clave u OTP; MFA; SSO (vía M-INT); políticas de contraseña, bloqueo, sesiones y límite de intentos.
- Roles de sistema y personalizados; permisos `modulo.recurso.accion`; permisos por campo sensible; alcance (organización, empresa, sede, unidad, equipo, propio).
- Registro de accesos a datos sensibles y control de descargas.
- Delegación temporal de aprobaciones y de funciones.

## 3. Fuera de alcance

- Catálogo detallado y matriz (documento `07-permisos-y-roles.md`). Auditoría de negocio (M-AUD).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §3 Planes y suscripciones; §60 Seguridad de información RRHH; §61 Roles; §62 Permisos granulares; §92 Privacidad; §105 UX de información sensible.

**Correcciones que incorpora:** C-01 Persona ≠ usuario ≠ colaborador ≠ relación laboral; C-09 Acceso sin correo corporativo; C-10 Permisos por campo y alcance.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Administrador empresa: usuarios y roles. RRHH: invita colaboradores. Cada usuario: su perfil de acceso, MFA y sesiones.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `identity.users.view` | Ver usuarios | Administrador empresa, RRHH, Auditor |
| `identity.users.create` | Crear usuarios | Administrador empresa |
| `identity.users.invite` | Invitar usuarios | Administrador empresa, RRHH |
| `identity.users.reactivate` | Reactivar usuarios | Administrador empresa |
| `identity.users.reset_mfa` | Restablecer el MFA de usuarios | Administrador empresa |
| `identity.users.reset_password` | Restablecer la clave de usuarios | Administrador empresa |
| `identity.users.suspend` | Suspender usuarios | Administrador empresa |
| `identity.users.update` | Editar usuarios | Administrador empresa |
| `identity.roles.view` | Ver roles y su matriz de permisos | Administrador empresa, RRHH, Auditor |
| `identity.roles.create` | Crear roles y su matriz de permisos | Administrador empresa |
| `identity.roles.delete` | Eliminar roles y su matriz de permisos | Administrador empresa |
| `identity.roles.duplicate` | Duplicar roles y su matriz de permisos | Administrador empresa |
| `identity.roles.update` | Editar roles y su matriz de permisos | Administrador empresa |
| `identity.security_policies.view` **(+)** | Ver políticas de contraseña, MFA, bloqueo y sesión | Administrador empresa, Auditor |
| `identity.security_policies.update` **(+)** | Configurar contraseñas, MFA por rol, bloqueo, duración de sesión y redes permitidas | Administrador empresa |
| `identity.sessions.revoke` **(+)** | Ver y cerrar sesiones de otros usuarios | Administrador empresa |
| `identity.delegations.create` **(+)** | Delegar sus propias aprobaciones por un periodo | Administrador empresa, RRHH, Payroll, Manager |
| `identity.delegations.manage` **(+)** | Crear delegaciones a nombre de otros usuarios | Administrador empresa, RRHH |
| `audit.sensitive_access.view` **(S)** | Ver el registro de accesos a datos sensibles | Administrador empresa, Auditor |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ingreso sin correo.** 1) El colaborador escribe tipo y número de documento. 2) Recibe un OTP por SMS o WhatsApp al teléfono registrado, o usa su clave. 3) En el primer ingreso define clave y acepta el aviso de privacidad. 4) Si el rol exige MFA, se pide el segundo factor.

**Invitación.** 1) RRHH invita desde la ficha o en lote. 2) Se crea el usuario «Invitado» ligado a la persona. 3) El invitado activa su cuenta con enlace u OTP. 4) Pasa a «Activo» con rol Empleado y alcance propio.

**Rol personalizado.** 1) El administrador crea el rol (vacío o duplicando uno). 2) Marca permisos por módulo y permisos por campo. 3) Define alcance por defecto y, si hace falta, alcance distinto por módulo. 4) Asigna usuarios. Todo cambio queda auditado con antes y después.

**Comprobación de autorización (en cada petición).** módulo contratado → permiso de acción → alcance sobre el registro → permisos de campo para filtrar la respuesta.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Ingreso | Correo o documento, clave u OTP, MFA, mensaje claro si la organización está suspendida | Prototipo: Ingreso |
| Usuarios | Tabla con rol, estado, último acceso, MFA; invitar, bloquear, reactivar, cambiar rol, cerrar sesiones | Prototipo: Seguridad → Usuarios |
| Roles y permisos | Matriz permiso × rol con casillas, alcance por rol, nuevo rol, duplicar; roles de sistema marcados | Prototipo: Seguridad → Roles y permisos |
| Políticas de seguridad | Contraseña, MFA por rol, bloqueo, duración de sesión, redes permitidas | Prototipo: Configuración → Centro de configuración → sección Seguridad |
| Accesos a datos sensibles | Quién vio o descargó qué dato sensible y cuándo | Prototipo: Seguridad → Auditoría → pestaña Accesos |
| Mi cuenta | Cambiar clave, activar MFA, ver y cerrar mis sesiones, delegar aprobaciones | Prototipo: Menú de usuario → Mi cuenta → pestañas Datos y preferencias, Contraseña y seguridad |

## 8. Datos principales

`people`, `users`, pertenencias usuario–organización, roles, permisos, permisos por rol, asignaciones de rol con alcance, sesiones, factores MFA, intentos de ingreso, delegaciones, registro de accesos sensibles.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección SEG.

- **RN-SEG-01**: Si se intenta borrar o renombrar un rol de sistema → se rechaza; solo se pueden duplicar.
- **RN-SEG-02**: Si un cambio dejara a la organización sin ningún usuario activo con el rol Administrador empresa → se rechaza.
- **RN-SEG-03**: Si un usuario intenta concederse permisos a sí mismo o asignar un rol con permisos que él no tiene → se rechaza, salvo que sea Administrador empresa.
- **RN-SEG-04**: Si el usuario tiene el permiso de acción pero el registro está fuera de su alcance → la respuesta es «no encontrado».
- **RN-SEG-05**: Si el usuario no tiene el permiso de un campo sensible → el campo no viaja en la respuesta de la API (no se enmascara en el cliente) y no puede filtrar, ordenar ni exportar por él.
- **RN-SEG-06**: Si un usuario consulta o descarga un dato marcado sensible → se inserta una fila en el registro de accesos con usuario, dato, registro y momento.
- **RN-SEG-07** (parámetro): Si se superan los intentos fallidos permitidos (parámetro) → la cuenta se bloquea por el tiempo configurado y se notifica al titular.
- **RN-SEG-08** (parámetro): Si un rol tiene MFA obligatorio (parámetro por rol) → no se completa el ingreso sin segundo factor; Administrador, Payroll e Investigador lo traen activado por defecto.
- **RN-SEG-09**: Si el colaborador no tiene correo → puede ingresar con documento + clave u OTP; el correo nunca es campo obligatorio de persona ni de usuario.
- **RN-SEG-10**: Si la relación laboral de un usuario con rol Empleado termina → su acceso pasa a modo ex-colaborador (solo sus boletas, certificados y liquidación) por el periodo configurado; los demás roles se retiran.
- **RN-SEG-11** (parámetro): Si una delegación vence (fecha fin obligatoria) → las aprobaciones vuelven al titular automáticamente.
- **RN-SEG-12**: Si un usuario pertenece al rol Manager → su alcance «equipo» se calcula con la línea de reporte vigente (directos e indirectos) a la fecha de consulta.
- **RN-SEG-13**: Si una persona es a la vez aprobadora y solicitante de un mismo trámite → no puede aprobarse a sí misma; el paso sube al siguiente nivel.
- **RN-SEG-14** (parámetro): Si la sesión supera el tiempo de inactividad configurado (parámetro) → se cierra y se pide ingresar de nuevo.

## 10. Estados

- ME-02 · Cuenta de usuario (`05-maquinas-de-estado.md`): estados Invitado, Activo, Bloqueado, Ex-colaborador (solo lectura), Desactivado.

## 11. Eventos

**Emite:**

- `user.invited`, `user.activated`, `user.suspended`, `user.deactivated`, `role.changed`, `sensitive_data.accessed`.

**Consume:**

- `employee.hired` (ofrece invitación), `employee.terminated` (pasa a modo ex-colaborador), `employment.manager_changed` (recalcula alcance).

## 12. Integraciones

SSO (SAML/OIDC), proveedor de SMS/WhatsApp para OTP, todos tras adaptadores (M-INT, M-NOT).

## 13. Casos borde

- Persona con dos relaciones activas en empresas distintas: un solo usuario, alcance «propio» cubre ambas.
- Manager que también es RRHH de una sola sede: dos asignaciones de rol con alcances distintos; los permisos se unen por alcance.
- Cambio de jefe con fecha futura: el alcance cambia el día de vigencia, no antes.
- Usuario bloqueado con aprobaciones pendientes: se reasignan según la regla de ausencia del aprobador (M-WFL).

## 14. Criterios de aceptación generales

- Se puede crear un rol «Administrador sin sueldos» que administre usuarios y estructura y no reciba ningún campo de compensación en ninguna pantalla, exportación, reporte ni respuesta de API.
- Toda ruta de API declara su permiso; una prueba automática falla si alguna queda sin declarar.
- El menú, los botones y la creación rápida se construyen con los mismos permisos que valida el servidor.
