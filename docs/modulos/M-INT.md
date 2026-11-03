# M-INT · Integraciones

Fase 9 · Ecosistema y expansión · Épica `INT` · Reglas `RN-INT-nn` · Módulo comercial: API e integraciones (`integrations`)

## 1. Objetivo

Conectar con los sistemas que rodean a RRHH sin acoplar el núcleo a ningún proveedor.

## 2. Alcance

- Capa de adaptadores por tipo: ERP y contabilidad, bancos y billeteras, relojes biométricos, correo, calendarios, SSO, firma, ATS externos y bolsas de empleo, LMS externos, pruebas y antecedentes, aseguradoras y EPS, proveedores de IA, mensajería.
- Catálogo de conectores con instalación por organización, credenciales cifradas, mapeos y prueba de conexión.
- Sincronizaciones programadas y por evento; cola de reintentos; registro y panel de estado.
- Implementación falsa de cada adaptador para desarrollo y pruebas.

## 3. Fuera de alcance

- Desarrollo a medida por cliente. Integración directa de la autoridad tributaria (se usa archivo hasta que exista interfaz).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §75 Integraciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-07 Marcación universal; E-09 Adelanto de sueldo y billeteras.

## 5. Actores y permisos

- Administrador instala y configura. Payroll, RRHH y TI operan cada conector.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `integrations.connections.view` | Ver conectores | Administrador empresa, RRHH, Payroll, Auditor |
| `integrations.connections.create` | Crear conectores | Administrador empresa |
| `integrations.connections.disconnect` | Desconectar conectores | Administrador empresa |
| `integrations.connections.sync` | Sincronizar conectores | Administrador empresa, RRHH, Payroll |
| `integrations.connections.test` | Probar conectores | Administrador empresa |
| `integrations.connections.update` | Editar conectores | Administrador empresa |
| `integrations.biometric.push` | Recibir marcas enviadas por relojes (credencial de dispositivo, sin rol) | — |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Instalación.** 1) Se elige el conector. 2) Se ingresan credenciales y mapeos. 3) Se prueba la conexión. 4) Se activa con su programación. 5) El panel muestra el estado y los errores.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Integraciones | Catálogo por categoría, instalados, estado | Prototipo: Configuración → Integraciones y API → pestaña Conectores |
| Detalle del conector | Credenciales, mapeos, programación, últimas ejecuciones, errores, reintentar | Prototipo: Configuración → Integraciones y API → pestaña Conectores |

## 8. Datos principales

Conectores instalados, credenciales cifradas, mapeos, ejecuciones de sincronización, errores.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección INT.

- **RN-INT-01**: Si un módulo necesita un servicio externo → lo llama a través de la interfaz del adaptador; ningún módulo referencia a un proveedor concreto.
- **RN-INT-02**: Si un efecto externo se envía (pago, mensaje, asiento, firma) → sale por la bandeja de salida con clave de idempotencia; un reintento no lo duplica.
- **RN-INT-03**: Si un conector falla → el proceso de negocio continúa en estado «pendiente de integración» visible, sin perder datos.
- **RN-INT-04**: Si se guardan credenciales → se cifran y nunca se devuelven completas por la API ni se escriben en logs.
- **RN-INT-05**: Si un adaptador no tiene implementación falsa → no pasa la revisión de calidad.
- **RN-INT-06** (parámetro): Si una sincronización supera el número de fallos consecutivos (parámetro) → se pausa y se alerta al administrador.
- **RN-INT-07**: Si un conector de reloj biométrico entrega marcas → entran como marcaciones con su origen y siguen las mismas reglas de inalterabilidad.
- **RN-INT-08**: Si se usa SSO → el alta y la baja de usuarios pueden sincronizarse, pero los roles y el alcance se administran en Ayni HR.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `integration.installed`, `integration.sync_completed`, `integration.health_changed`.

**Consume:**

- Eventos de dominio que cada conector necesite.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Proveedor que cambia su formato de archivo.
- Reloj que reenvía marcas ya recibidas.
- Banco con horario de corte.

## 14. Criterios de aceptación generales

- Cambiar de proveedor en cualquier categoría solo requiere otro adaptador y configuración.
