# M-SAS · Consola SaaS

Fase 0 · Cimientos · Épica `SAS` · Reglas `RN-SAS-nn` · Módulo comercial: transversal

## 1. Objetivo

Dar al propietario de la plataforma un panel separado para administrar organizaciones, planes, módulos contratados, límites, feature flags, soporte, copias de seguridad y salud del servicio.

## 2. Alcance

- Organizaciones: alta, prueba gratuita, activación, suspensión, bloqueo, cancelación, datos de contacto y facturación del SaaS.
- Planes configurables (nombre libre, precio y moneda como campos que define el superadministrador, ciclo mensual o anual, límites, módulos y funcionalidades incluidas), duplicar, activar o desactivar, complementos (add-ons).
- Suscripciones, consumo (colaboradores activos, administradores, almacenamiento, llamadas de API, mensajes), licencias.
- Feature flags por organización, plan, grupo beta y país.
- Soporte: suplantación controlada con consentimiento y registro; avisos de mantenimiento.
- Monitoreo: errores, trabajos fallidos, colas, uso de API, webhooks fallidos, almacenamiento, estado de integraciones.
- Copias de seguridad: automáticas y manuales, historial, retención, restauración segura.
- Tablero SaaS: organizaciones activas y nuevas, usuarios, colaboradores administrados, ingresos recurrentes mensual y anual, cancelación, pruebas, conversiones, almacenamiento, suspendidas, módulos más usados, incidencias.

## 3. Fuera de alcance

- Cobro con pasarela y emisión de comprobantes: se integra por adaptador (M-INT); la consola registra el estado de cuenta.
- Administración de RRHH del cliente: la consola no muestra datos laborales de las organizaciones.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §2 Superadministrador del SaaS; §3 Planes y suscripciones; §89 Copias de seguridad; §111 Monitoreo SaaS; §112 Feature flags; §118 Modularidad comercial; §120 Estrategia de implementación; §124 Objetivo final.

**Correcciones que incorpora:** C-04 La plataforma SaaS va en los cimientos.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Superadmin SaaS y roles internos de plataforma (soporte, finanzas, operaciones) con permisos propios de la consola. Ningún usuario de cliente accede.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `platform.tenants.view` | Ver organizaciones | Superadmin SaaS |
| `platform.tenants.cancel` | Cancelar organizaciones | Superadmin SaaS |
| `platform.tenants.create` | Crear organizaciones | Superadmin SaaS |
| `platform.tenants.reactivate` | Reactivar organizaciones | Superadmin SaaS |
| `platform.tenants.suspend` | Suspender organizaciones | Superadmin SaaS |
| `platform.tenants.update` | Editar organizaciones | Superadmin SaaS |
| `platform.plans.view` | Ver planes y complementos | Superadmin SaaS |
| `platform.plans.activate` | Activar planes y complementos | Superadmin SaaS |
| `platform.plans.create` | Crear planes y complementos | Superadmin SaaS |
| `platform.plans.duplicate` | Duplicar planes y complementos | Superadmin SaaS |
| `platform.plans.retire` | Retirar planes y complementos | Superadmin SaaS |
| `platform.plans.update` | Editar planes y complementos | Superadmin SaaS |
| `platform.subscriptions.view` | Ver suscripciones, módulos y límites | Superadmin SaaS |
| `platform.subscriptions.change_plan` | Cambiar el plan de suscripciones, módulos y límites | Superadmin SaaS |
| `platform.subscriptions.create` | Crear suscripciones, módulos y límites | Superadmin SaaS |
| `platform.subscriptions.reactivate` | Reactivar suscripciones, módulos y límites | Superadmin SaaS |
| `platform.subscriptions.suspend` | Suspender suscripciones, módulos y límites | Superadmin SaaS |
| `platform.subscriptions.update` | Editar suscripciones, módulos y límites | Superadmin SaaS |
| `platform.feature_flags.view` | Ver funciones por organización, plan, beta o país | Superadmin SaaS |
| `platform.feature_flags.create` | Crear funciones por organización, plan, beta o país | Superadmin SaaS |
| `platform.feature_flags.update` | Editar funciones por organización, plan, beta o país | Superadmin SaaS |
| `platform.support_sessions.view` | Ver sesiones de soporte con suplantación | Superadmin SaaS |
| `platform.support_sessions.create` | Crear sesiones de soporte con suplantación | Superadmin SaaS |
| `platform.support_sessions.end` | Finalizar sesiones de soporte con suplantación | Superadmin SaaS |
| `platform.health.view` | Ver salud del servicio (errores, colas, trabajos, webhooks, almacenamiento) | Superadmin SaaS |
| `platform.metrics.view` | Ver tablero SaaS | Superadmin SaaS |
| `platform.backups.view` **(+)** | Ver historial y estado de copias | Superadmin SaaS |
| `platform.backups.create` **(+)** | Lanzar una copia manual | Superadmin SaaS |
| `platform.backups.restore` **(+)** | Restaurar una copia (con doble aprobación) | Superadmin SaaS |
| `platform.settings.update` **(+)** | Cambiar configuraciones globales | Superadmin SaaS |
| `platform.audit.view` **(+)** | Ver la auditoría de la consola | Superadmin SaaS |
| `platform.settings.view` **(+)** | Ver configuraciones globales y avisos de la plataforma | Superadmin SaaS |
| `platform.health.manage` **(+)** | Reintentar trabajos y eventos fallidos desde el monitoreo | Superadmin SaaS |
| `platform.logs.view` **(+)** | Ver la actividad y los logs técnicos de la plataforma (sin datos de RRHH) | Superadmin SaaS |
| `platform.billing.view` **(+)** | Ver cargos, comprobantes electrónicos, pagos y estado de cuenta de las organizaciones | Superadmin SaaS |
| `platform.billing.manage` **(+)** | Generar cargos, emitir comprobantes electrónicos, registrar pagos y anular comprobantes | Superadmin SaaS |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Alta de organización.** 1) El superadmin registra nombre, país, contacto y plan (o prueba gratuita con fecha de fin). 2) Se crea la organización con su primera empresa y un usuario Administrador invitado. 3) Se activan los módulos del plan y se siembran plantillas del país. 4) El administrador entra al asistente de primeros pasos (M-IMP).

**Cambio de plan o de módulos.** 1) El superadmin (o el administrador del cliente, si el plan permite autogestión) elige el nuevo plan o complemento. 2) El sistema compara límites con el consumo actual. 3) Al subir, los módulos se activan de inmediato. 4) Al bajar, los módulos retirados pasan a solo lectura y luego se ocultan; los datos se conservan.

**Suspensión por falta de pago.** 1) Vence el periodo de gracia. 2) La organización pasa a «Suspendida»: sus usuarios ven el aviso y no pueden operar; los datos se conservan. 3) Al regularizar, se reactiva sin pérdida.

**Restauración.** 1) Se elige copia y alcance. 2) Se restaura primero en un entorno aislado y se verifica. 3) Con doble aprobación interna se promueve. 4) Queda registro y aviso a la organización afectada.

**Suplantación de soporte.** 1) El agente indica organización, usuario y motivo (ticket). 2) La organización debe tener habilitado el acceso de soporte. 3) La sesión es temporal, con marca visible; no puede ver campos sensibles salvo autorización expresa. 4) Todo queda en la auditoría de la organización y de la consola.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Tablero SaaS | Indicadores de la plataforma y tendencias; accesos a organizaciones con problemas | Prototipo: Consola SaaS → pestaña Tablero |
| Organizaciones | Tabla con plan, estado, colaboradores, almacenamiento, fin de prueba; crear, suspender, reactivar, entrar como soporte | Prototipo: Consola SaaS → Organizaciones |
| Detalle de organización | Empresas, usuarios, suscripción, módulos, límites y consumo, flags, historial de estado, notas de soporte | Prototipo: Consola SaaS → pestañas Organizaciones y Suscripciones |
| Planes y complementos | Catálogo de planes con límites y módulos; crear, duplicar, activar o desactivar | Prototipo: Consola SaaS → pestaña Planes |
| Feature flags | Lista de funciones con reglas de activación por organización, plan, beta y país | Prototipo: Consola SaaS → pestaña Funciones |
| Monitoreo | Errores, colas, trabajos fallidos, webhooks fallidos, uso de API, integraciones | Prototipo: Consola SaaS → pestaña Soporte y monitoreo |
| Copias de seguridad | Historial, estado, retención, copia manual, restauración | Prototipo: Consola SaaS → pestaña Copias de seguridad |
| Suscripción (vista del cliente) | Plan vigente, módulos activos, consumo frente a límites, solicitar cambio | Prototipo: Configuración → Suscripción |

## 8. Datos principales

Organizaciones, planes, módulos comerciales, funcionalidades por plan, complementos, suscripciones, límites y consumo por periodo, feature flags y reglas, sesiones de soporte, copias y restauraciones, avisos de plataforma, estado de cuenta.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección SAS.

- **RN-SAS-01**: Si una organización no tiene contratado un módulo → sus rutas de API responden «módulo no contratado», su menú no lo muestra y sus permisos no surten efecto aunque el rol los tenga.
- **RN-SAS-02** (parámetro): Si el número de colaboradores activos alcanza el límite del plan → se avisa al administrador al llegar al porcentaje de alerta (parámetro) y, al tope, no se permiten nuevas altas (los datos existentes siguen operativos).
- **RN-SAS-03** (parámetro): Si el almacenamiento alcanza el límite del plan → se bloquean nuevas subidas y se mantiene la lectura.
- **RN-SAS-04** (parámetro): Si vence la prueba gratuita sin conversión → la organización pasa a «Suspendida» tras el periodo de gracia (parámetro).
- **RN-SAS-05**: Si una organización está suspendida → sus usuarios no pueden ingresar a operar; los colaboradores conservan acceso de solo lectura a sus boletas y certificados.
- **RN-SAS-06**: Si se retira un módulo → sus datos no se borran; quedan en solo lectura durante el periodo de transición (parámetro) y luego se ocultan hasta reactivarlo.
- **RN-SAS-07**: Si una función está tras un feature flag → se evalúa en el orden organización, plan, grupo beta, país; el primero que la define decide.
- **RN-SAS-08**: Si soporte suplanta a un usuario → necesita motivo, la sesión caduca sola y cada acción lleva la marca «soporte» en la auditoría de la organización.
- **RN-SAS-09**: Si se pide restaurar una copia → nunca se sobrescribe producción directamente; se restaura en aislado, se verifica y se promueve con doble aprobación.
- **RN-SAS-10** (parámetro): Si se cancela una organización → sus datos se conservan durante el periodo de retención (parámetro por jurisdicción), se ofrece exportación completa y después se eliminan o anonimizan.
- **RN-SAS-11**: Si un plan tiene organizaciones suscritas → no se elimina; se desactiva para nuevas ventas y las existentes conservan sus condiciones.
- **RN-SAS-12**: Si la consola muestra datos de una organización → solo metadatos y consumo; nunca sueldos, fichas ni documentos.

## 10. Estados

- ME-40 · Organización y suscripción (`05-maquinas-de-estado.md`): estados En prueba, Activa, En periodo de gracia, Suspendida, Cancelada, Eliminada o anonimizada.

## 11. Eventos

**Emite:**

- `tenant.created`, `tenant.trial_started`, `tenant.activated`, `tenant.suspended`, `tenant.reactivated`, `tenant.cancelled`, `subscription.changed`, `tenant.module_enabled`, `tenant.module_disabled`, `limit.reached`.

**Consume:**

- `employee.hired`, `employee.terminated` (recalcula consumo), métricas técnicas de M-PLT.

## 12. Integraciones

Pasarela de cobro y facturación electrónica del SaaS (adaptador), monitoreo y alertas de infraestructura.

## 13. Casos borde

- Bajada de plan con consumo por encima del nuevo límite: se permite, pero quedan bloqueadas las altas hasta regularizar.
- Organización con periodo de planilla abierto al momento de suspenderse: al reactivar continúa donde quedó.
- Módulo retirado del que dependen otros (planilla depende de tiempo): se advierte la dependencia antes de confirmar.
- Dos organizaciones del mismo grupo económico creadas por separado: se pueden fusionar solo por proceso de soporte, nunca automático.

## 14. Criterios de aceptación generales

- La consola vive en una aplicación y un dominio de autenticación separados; un usuario de cliente no puede obtener un token válido para ella.
- Activar o desactivar un módulo para una organización cambia menú, permisos efectivos y API sin desplegar código.
- Una empresa puede empezar con Core HR + Vacaciones y activar después cualquier otro módulo sin migración.
