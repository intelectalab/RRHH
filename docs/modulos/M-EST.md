# M-EST · Modo estudio contable

Fase 9 · Ecosistema y expansión · Épica `EST` · Reglas `RN-EST-nn` · Módulo comercial: Estudio contable (`partner`)

## 1. Objetivo

Permitir que un estudio contable o socio administre las planillas y obligaciones de muchas empresas pequeñas desde una sola consola.

## 2. Alcance

- Organización de tipo «estudio» con cartera de organizaciones cliente.
- Acceso delegado a cada cliente con rol y alcance definidos por el cliente; cambio rápido de cliente.
- Tablero de cartera: estado de planilla, declaraciones, vencimientos y cumplimiento por cliente.
- Acciones en lote entre clientes (abrir periodos, generar archivos) con resultados separados.
- Plantillas del estudio aplicables a clientes nuevos; portal del cliente para cargar novedades y aprobar la planilla.

## 3. Fuera de alcance

- Facturación del estudio a sus clientes. Contabilidad general.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-24 Modo estudio contable.

## 5. Actores y permisos

- Personal del estudio (operador, supervisor). Contacto del cliente (aprueba). Trabajadores del cliente (portal).

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `partner.clients.view` | Ver la cartera de clientes del estudio | Administrador empresa, Payroll, Contador |
| `partner.clients.create` | Crear la cartera de clientes del estudio | Administrador empresa |
| `partner.clients.enter` | Entrar a la cartera de clientes del estudio | Administrador empresa, Payroll, Contador |
| `partner.clients.run_batch` **(+)** | Ejecutar acciones en lote sobre varios clientes | Administrador empresa, Payroll |
| `partner.clients.grant_access` **(+)** | (Cliente) otorgar y revocar el acceso del estudio | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Alta de cliente.** 1) El estudio crea o invita a la organización cliente. 2) El cliente otorga el acceso delegado con un rol. 3) El estudio aplica su plantilla. 4) El cliente aparece en la cartera.

**Ciclo mensual.** 1) El cliente carga novedades en su portal. 2) El operador calcula. 3) El supervisor del estudio o el contacto del cliente aprueba (nunca quien calculó). 4) Se generan archivos y boletas. 5) El tablero de cartera marca el avance.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Cartera | Clientes con estado de planilla, declaraciones, vencimientos y semáforo de cumplimiento; entrar | Prototipo: Ingreso → Estudio contable (socio) → Cartera |
| Acciones en lote | Selección de clientes y acción; resultado por cliente | Prototipo: Ingreso → Estudio contable (socio) → Cartera |
| Acceso del estudio (vista del cliente) | Estudio vinculado, rol otorgado, actividad, revocar | Prototipo: Seguridad → Usuarios → Acceso de terceros |

## 8. Datos principales

Relación estudio–cliente, delegaciones con rol y vigencia, operadores asignados, plantillas del estudio, ejecuciones en lote.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección EST.

- **RN-EST-01**: Si el personal del estudio entra a un cliente → opera con el rol y el alcance que ese cliente delegó, dentro del aislamiento de esa organización; ninguna consulta mezcla clientes.
- **RN-EST-02**: Si el cliente revoca la delegación → el acceso del estudio termina de inmediato y los datos permanecen en el cliente.
- **RN-EST-03**: Si se ejecuta una acción en lote → corre por separado en cada cliente y un fallo en uno no afecta a los demás.
- **RN-EST-04**: Si el estudio calcula una planilla → la aprobación la hace una persona distinta (del estudio o del cliente), igual que en cualquier organización.
- **RN-EST-05**: Si el tablero de cartera muestra datos → solo estados y fechas; los montos requieren entrar al cliente con permiso.
- **RN-EST-06**: Si una acción del estudio se audita → queda en la auditoría del cliente con el usuario real del estudio.
- **RN-EST-07** (parámetro): Si el estudio supera el número de clientes de su plan → no puede vincular otro.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `partner.client_linked`, `partner.delegation_revoked`, `partner.batch_executed`.

**Consume:**

- `payroll.closed`, `filing.submitted`, `compliance.status_changed` (de cada cliente, solo estado).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Cliente que cambia de estudio: revoca uno y delega a otro.
- Operador del estudio que también es contador del cliente.
- Cliente que deja el estudio y continúa solo, sin migración.

## 14. Criterios de aceptación generales

- Un estudio administra varias empresas con cambio rápido de cliente, tablero de cartera y aislamiento comprobado.
