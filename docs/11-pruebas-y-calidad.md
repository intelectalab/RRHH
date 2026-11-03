# 11 · Pruebas y calidad

## 1. Estrategia

| Nivel | Herramienta | Qué prueba | Dónde |
|---|---|---|---|
| Unitarias de dominio | Pest | Reglas puras, máquinas de estado, motor de vigencias, evaluador de fórmulas, objetos de valor | `Modules/X/Tests/Unit` |
| De caso de uso y API | Pest (HTTP + base real) | Endpoint completo: validación, permiso, alcance, transacción, auditoría, outbox | `Modules/X/Tests/Feature` |
| De arquitectura | Pest Arch | Dependencias entre capas y módulos; prohibiciones de `CLAUDE.md` | `tests/Architecture` |
| De esquema | Pest + catálogo de Postgres | RLS forzado, FK compuestas, exclusiones de vigencia, tablas de solo inserción | `tests/Schema` |
| De aislamiento | Pest, generadas desde OpenAPI | Ninguna ruta cruza organizaciones | `tests/Isolation` |
| De contrato de API | Pest | Rutas registradas = `openapi.yaml`; respuestas válidas contra el esquema | `tests/Contract` |
| De contrato de adaptadores | Pest | Misma batería para el adaptador falso y el real | `Modules/X/Tests/Contract` |
| Doradas de planilla | Pest + archivos de datos | Resultado exacto por caso | `packages/country-pe/tests/golden` |
| De componente | Vitest + Testing Library | Estados, permisos, formularios | junto al componente |
| De extremo a extremo | Playwright | Recorridos por fase, en escritorio y móvil, con verificación de accesibilidad | `apps/web/e2e` |
| De carga | k6 (o equivalente) | Presupuestos de §11 con datos de 50 000 colaboradores | `tests/load` |
| De seguridad | Análisis estático, dependencias, pruebas dirigidas | `12-seguridad-y-privacidad.md` | canal de integración |

Principios:

- Las pruebas de API usan **PostgreSQL real** (no SQLite): RLS, exclusiones y `numeric` son parte del comportamiento.
- Cada prueba corre en transacción revertida; dos organizaciones sembradas (`A` y `B`) disponibles siempre.
- Reloj falso (`Clock`); ninguna prueba depende de la fecha del día.
- Adaptadores falsos siempre; la red está bloqueada en pruebas.
- Una prueba, una razón de fallo. Nombres en español que describen el comportamiento.
- Cobertura: no se persigue un porcentaje global; se exige la lista de §2 por historia. Dominio de planilla y motor de vigencias: toda rama cubierta.

## 2. Definición de terminado

Una historia está terminada cuando **todo** lo siguiente es cierto:

**Función**
- [ ] Cumple cada criterio de aceptación de la historia, demostrado por una prueba.
- [ ] Ningún control visible sin función; los cambios persisten y actualizan las pantallas relacionadas.
- [ ] Datos de la organización demo actualizados y coherentes si la historia añade un recurso.

**Reglas no negociables**
- [ ] Tabla nueva con `tenant_id`, RLS forzado, FK compuestas (prueba de esquema en verde).
- [ ] Prueba de aislamiento en verde para cada ruta nueva.
- [ ] Permiso, alcance y permisos por campo probados (403, 404 fuera de alcance, campo ausente).
- [ ] Módulo apagado → 403 `module_not_enabled`.
- [ ] Estados solo por acción; matriz de transiciones en verde.
- [ ] Auditoría con antes y después; registro de acceso en lecturas sensibles.
- [ ] Evento en outbox en la misma transacción; consumidores idempotentes.
- [ ] Ningún número legal en código; parámetros con vigencia y fuente.
- [ ] Idempotencia en `POST` con efecto.

**Contrato y código**
- [ ] `openapi.yaml` regenerado y válido; `packages/contracts` regenerado; prueba de contrato en verde.
- [ ] `make lint` en verde (Pint, PHPStan, ESLint, `tsc`).
- [ ] Sin N+1 (carga perezosa prohibida en pruebas); índices para filtros y órdenes nuevos.
- [ ] Procesos masivos en cola, por lotes, con progreso.

**Interfaz**
- [ ] Textos en es, en y pt; sin literales.
- [ ] 1280 px y 390 px sin desborde; tema claro y oscuro; teclado y foco; verificación automática de accesibilidad sin fallos graves.
- [ ] Estados de carga, vacío, error y sin permiso.

**Planilla (si aplica)**
- [ ] Casos dorados añadidos o actualizados y validados por el especialista.
- [ ] Traza de cálculo disponible para cada línea.

**Entrega**
- [ ] Documentación viva actualizada (modelo de datos, especificación del módulo, decisiones).
- [ ] Revisión con `/revisar` sin bloqueantes.

## 3. Pruebas de aislamiento

Objetivo: demostrar, en cada integración continua, que ninguna ruta devuelve o modifica datos de otra organización.

**Generada desde OpenAPI** (`tests/Isolation/CrossTenantTest`):

1. Siembra las organizaciones A y B con un registro de cada recurso (registro de factories por esquema).
2. Para cada operación de `openapi.yaml` con `x-permission` distinto de `public`:
   - `GET /recurso/{id}` con usuario administrador de B e `id` de A → **404**.
   - `PATCH`, `DELETE` y cada acción con `id` de A → **404** y la fila de A sin cambios.
   - `GET /recurso` como B → ningún `id` de A en `data`.
   - `POST /recurso` como B con una referencia (`x_id`) de A en el cuerpo → **422** o **404**, nunca 201.
3. Falla si una operación del contrato no tiene factory registrada (no se puede añadir una ruta sin cubrirla).

**De base de datos** (`tests/Schema`):

- Toda tabla con columna `tenant_id` tiene `relrowsecurity` y `relforcerowsecurity` activos y una política sobre `tenant_id`.
- Toda FK hacia una tabla con `tenant_id` es compuesta.
- Con el rol `app` y sin `app.tenant_id` definido, `SELECT count(*)` de cada tabla devuelve 0.
- Con `app.tenant_id = A`, un `INSERT` con `tenant_id = B` falla.

**De infraestructura transversal**:

- Trabajo en cola despachado en A y ejecutado tras una solicitud de B conserva A.
- Clave de caché sin prefijo de organización → falla la prueba de arquitectura.
- URL firmada de un archivo de A pedida por B → 404.
- Búsqueda global como B no devuelve documentos de A.
- Webhook y exportación de A no incluyen datos de B.
- Sesión de soporte de plataforma: sin sesión abierta y vigente, la consola no lee datos de la organización; con ella, queda auditoría visible para el cliente.
- Modo estudio contable: el socio solo entra a organizaciones vinculadas.

## 4. Matriz de permisos

Fuente: la matriz rol × permiso y los permisos por campo de `07-permisos-y-roles.md`. Se materializa como datos (`tests/Permissions/matrix.php`) y una sola prueba la recorre.

Para cada **rol de sistema** (Administrador, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Investigador, Contador) × cada **operación** del contrato:

| Caso | Esperado |
|---|---|
| El rol tiene el permiso y el registro está en su alcance | 2xx |
| El rol tiene el permiso y el registro está **fuera** de su alcance | 404 |
| El rol no tiene el permiso | 403 `forbidden` |
| Rol de solo lectura (Auditor, Contador) ante cualquier escritura | 403 |

Para cada **campo sensible** (`x-sensitive-fields` del contrato):

| Caso | Esperado |
|---|---|
| Sin permiso por campo `…view` | El campo **no está** en la respuesta (ni en listados, ni en `include`, ni en exportaciones, ni en búsqueda, ni en webhooks, ni en respuestas de IA) |
| Sin permiso `…view`, filtrar u ordenar por el campo | 400 `invalid_filter` |
| Con `…view` pero sin `…edit`, enviar el campo en escritura | 403 |
| Con `…view` | El campo aparece y se inserta fila en el registro de accesos |

Casos con nombre propio que deben existir siempre:

- **Administrador que no ve sueldos**: rol con todo salvo `people.employments.salary.view` → administra colaboradores y nunca recibe `base_salary`, compensaciones, resultados de planilla ni bandas.
- **Manager**: ve a su equipo directo e indirecto; no ve a pares ni a su propio jefe más allá del directorio; no ve diagnósticos de ausencias.
- **Empleado**: solo lo propio; no puede aprobar su propia solicitud.
- **Investigador de denuncias**: solo casos asignados; el Administrador no ve denuncias.
- **Payroll**: ve sueldos; no ve casos disciplinarios, denuncias ni datos de salud.
- **Rol personalizado**: combinación arbitraria de permisos y alcance se respeta.
- **Token con scope de lectura**: no escribe aunque el usuario pueda.
- **Escalada**: un usuario no puede asignarse ni asignar a otros un permiso que no tiene.

## 5. Máquinas de estado

Cada máquina se define como datos; una prueba genérica recorre la definición:

- **Matriz completa**: para cada estado × cada acción: si la transición existe → 200 y nuevo estado; si no → 409 `invalid_transition` y estado intacto.
- **Guardas**: cada guarda tiene un caso que la viola → 422 `business_rule_violated` con su `rule`.
- **Permiso por acción**: cada acción sin su permiso → 403.
- **`PATCH` con `status`** → ignorado o 422; nunca cambia el estado.
- **Efectos**: cada transición afirma auditoría, evento y efectos colaterales declarados (aprobar vacaciones: saldo, calendario, día de asistencia).
- **Concurrencia**: dos acciones simultáneas sobre el mismo registro → una gana, la otra 409.
- **`available_actions`** coincide con las transiciones permitidas para ese usuario.
- **Inalcanzables**: no hay estados sin transición de entrada ni estados no finales sin salida (prueba sobre la definición).

Máquinas mínimas a cubrir: relación laboral, posición, contrato, solicitud de firma, solicitud de aprobación, vacaciones, ausencia, sobretiempo, periodo de planilla, préstamo, adelanto, archivo de declaración, liquidación, requisición, postulación, oferta, proceso de onboarding y offboarding, ciclo de evaluación, evaluación, ciclo de compensación, encuesta, ticket, incidente SST, denuncia, caso disciplinario, activo, rendición de gastos, incidente de privacidad, importación, suscripción.

## 6. Vigencias

Batería sobre el servicio `Vigencia` y repetida por cada recurso vigenciado:

- Cambio con fecha pasada, de hoy y futura; `as_of` devuelve el valor correcto antes, en y después de cada fecha.
- Dos cambios el mismo día; cambio que cae exactamente en `valid_from` de otra fila.
- Solape rechazado por la base (409 `overlap`).
- Corrección: la fila original queda marcada, no borrada; la historia muestra ambas.
- Anular un cambio futuro reabre la fila anterior.
- Cambio dentro de un periodo de planilla cerrado → evento de retroactivo y aviso al usuario.
- Reingreso: nueva relación; la historia de la anterior no cambia.
- Entrada en vigencia: el trabajo diario emite el evento una sola vez aunque corra dos.

## 7. Eventos, outbox e integraciones

- El evento está en outbox si y solo si la transacción confirmó (prueba con fallo provocado después del cambio).
- Doble entrega del mismo evento → un solo efecto por consumidor.
- Orden por agregado respetado.
- Fallo transitorio del adaptador → reintento → un solo efecto externo (se verifica con el adaptador falso y su clave de idempotencia).
- Fallo permanente → queda visible, no reintenta, avisa.
- Webhook: firma verificable con el secreto; rotación con dos firmas; destino interno bloqueado.
- Workflow: bucle detectado y cortado; espera reanudada tras reinicio de trabajadores.

## 8. Pruebas doradas de planilla

Definen qué es «correcto». **Las mantiene un especialista en planilla peruana**, no el equipo de desarrollo: el desarrollador nunca cambia un resultado esperado para hacer pasar una prueba.

### 8.1 Formato

Un directorio por caso en `packages/country-pe/tests/golden/<tema>/<caso>/`:

```
case.yaml        descripción, norma de referencia, periodo, versión del paquete, estado de validación
input.yaml       empresa (régimen), relación laboral con vigencias, asistencia, ausencias, incidencias, acumulados previos
params.yaml      (opcional) parámetros fijados para el caso, con su vigencia; si falta, usa los del paquete a la fecha
expected.yaml    líneas esperadas por concepto (monto exacto), bases, neto, aportes del empleador; opcional: archivos esperados
notes.md         cálculo a mano del especialista, paso a paso
```

```yaml
# case.yaml
id: PE-GEN-MENSUAL-001
title: Régimen general, mes completo, sin incidencias
reference: "norma y artículo que sustenta el caso"
period: "AAAA-MM"
validated_by: "nombre del especialista"
validated_on: "fecha"
status: validated        # draft | pending_validation | validated | obsolete
```

- `status: pending_validation` → la prueba corre pero no bloquea; aparece en un informe de casos por validar. Solo `validated` bloquea la integración.
- Los montos esperados los escribe el especialista a partir de la norma vigente en el periodo del caso. Este kit **no** incluye montos: no se inventan tasas (ver `research.md`, que marca varias como no verificadas).
- Comparación exacta al céntimo; la regla de redondeo es parte del caso.
- Al fallar, la prueba imprime la **traza** del motor concepto por concepto junto al esperado.

### 8.2 Casos mínimos (los define y valida el especialista)

| Tema | Casos |
|---|---|
| Mensual, régimen general | Mes completo; ingreso a mitad de mes; cese a mitad de mes; con asignación familiar; con faltas y tardanzas; con descanso médico y subsidio; con vacaciones gozadas; con licencia sin goce |
| Sistema pensionario | ONP; AFP con cada esquema de comisión; cambio de AFP a mitad de año; tope de remuneración asegurable; trabajador pensionista |
| Horas extra y nocturno | Sobretasas por tramo; trabajo en feriado y en día de descanso; jornada nocturna; compensación con bolsa de horas en lugar de pago |
| Renta de quinta categoría | Proyección sin cambios; aumento a mitad de año; ingreso a mitad de año con rentas de otro empleador; gratificación y bonos extraordinarios; cese con devolución de exceso; remuneración por debajo del mínimo afecto |
| Gratificaciones | Semestre completo; proporcional por meses; trunca al cese; con bonificación extraordinaria; afiliado a EPS |
| CTS | Semestre completo; proporcional; con remuneración variable promediada; trunca |
| Vacaciones | Remuneración vacacional; truncas; no gozadas con indemnización; régimen con menos días |
| Utilidades | Distribución por días y por remuneraciones; trabajador con parte del año; tope |
| Regímenes | Microempresa; pequeña empresa; construcción civil; agrario; modalidades formativas; trabajador con régimen distinto al de la empresa por fecha de ingreso |
| Liquidación | Renuncia; fin de contrato; despido con indemnización; con préstamo pendiente; con vacaciones no gozadas |
| Préstamos y adelantos | Cuota normal; cuota que supera el neto disponible; adelanto de sueldo con descuento en el mismo periodo |
| Retroactivos | Aumento con vigencia en periodo cerrado → reintegro en el periodo abierto |
| Otros | Cuota sindical; retención judicial; EPS con crédito; teletrabajo con compensación no remunerativa; multimoneda (sueldo pactado en otra moneda) |
| Archivos | PLAME, T-Registro, AFPnet y archivo bancario generados a partir de un periodo dorado, comparados contra el archivo esperado |
| Vigencia de parámetros | Mismo caso antes y después de un cambio de parámetro: el periodo anterior no cambia |

### 8.3 Reglas

- Cambiar una fórmula o un parámetro exige ejecutar toda la batería; cualquier diferencia se revisa con el especialista.
- Un defecto de planilla encontrado en uso real se convierte en caso dorado antes de corregirse.
- **Prueba de literales legales**: una prueba de arquitectura busca en `Modules/Payroll`, `Modules/Time` y `packages/country-*` (código, no datos) números con apariencia de tasa o tope (decimales entre 0 y 1 en multiplicaciones, constantes con nombres como `UIT`, `RMV`, `RATE`, `TASA`, `TOPE`) y falla si los encuentra fuera de las carpetas de datos del paquete.
- **Determinismo**: calcular dos veces el mismo periodo produce resultados idénticos byte a byte.
- **Propiedades** (pruebas generativas): neto = ingresos − descuentos; la suma de líneas coincide con los totales; ningún descuento voluntario deja el neto por debajo del límite parametrizado; prorratear por días y sumar los tramos da el total del mes.

## 9. Recorridos de extremo a extremo por fase

Playwright, sobre la organización demo, en escritorio y móvil. Cada recorrido termina verificando un efecto en otra pantalla.

| Fase | Recorrido |
|---|---|
| 0 | Superadministrador crea organización con plan → administrador entra → crea rol «administrador sin sueldos» → ese usuario no ve sueldos en ninguna pantalla · Apagar un módulo lo quita del menú y la API responde 403 · Aprobación de dos niveles de ejemplo · Acceso con documento + OTP · Cambio de idioma y de tema |
| 1 | Alta de colaborador sobre posición aprobada → contrato generado desde plantilla → firma → ficha completa · Cambio de puesto con fecha futura → organigrama cambia ese día · Reingreso · Cese · Importación con errores → corrección → carga · Colaborador actualiza su dirección → RRHH aprueba |
| 2 | Colaborador marca desde el móvil (con y sin conexión) → RRHH ve el día → corrección con motivo queda como registro nuevo · Solicita vacaciones → manager aprueba → saldo, calendario y asistencia actualizados · Programación de turnos publicada · Sobretiempo aprobado a bolsa de horas · Cierre de asistencia del periodo |
| 3 | Periodo: incidencias → cálculo → comparación con el anterior y anomalía explicada → aprobación → cierre → boletas publicadas → colaborador descarga la suya → archivos generados y validados → asiento exportado · Préstamo aprobado → cuota descontada · Liquidación simulada y aprobada con documentos de cese · Cambio de parámetro con vigencia futura no altera el periodo cerrado |
| 4 | Requisición aprobada → publicada en el portal → postulación pública → etapas → entrevista con evaluación → oferta aceptada → pre-onboarding → colaborador creado → onboarding con tareas · Ciclo 360 lanzado → evaluaciones → cierre · Offboarding con devolución de activos |
| 5 | Curso obligatorio asignado al ingreso → completado → certificado · Revisión salarial: manager propone → aprobaciones → se aplica como cambio con vigencia · Reporte de brecha salarial · Sesión 9-box |
| 6 | Encuesta anónima con segmento por debajo del umbral → resultado oculto · Ticket con plazo vencido → alerta · Comunicado con confirmación de lectura |
| 7 | Incidente SST → investigación → acción correctiva → registro · Denuncia anónima con código → investigador asignado → medida de protección → cierre; el administrador no la ve · Rendición de gastos fuera de política rechazada · Tablero de cumplimiento con evidencia · Expediente de inspección generado · Solicitud de derecho del titular atendida |
| 8 | Pregunta en lenguaje natural responde con datos permitidos y niega los no permitidos · Reporte construido, guardado y exportado sin columnas sensibles · Alerta con explicación → descartada |
| 9 | Webhook recibido y verificado por un receptor de prueba · Token con scope limitado · Reloj biométrico simulado envía marcaciones · Estudio contable entra a dos clientes · Herramienta MCP respeta permisos |

## 10. Calidad continua

En cada cambio: lint y análisis estático → unitarias → API (con aislamiento, permisos, contrato, esquema, arquitectura) → doradas → componentes → e2e de humo. En la rama principal además: e2e completo, accesibilidad, análisis de dependencias y secretos. Antes de una salida: carga, restauración de copia y e2e en preproducción.

Se mide y se revisa: pruebas inestables (se arreglan o se retiran, no se reintentan en silencio), duración de la batería, casos dorados pendientes de validar, hallazgos de `/revisar` por regla.

## 11. Carga

Conjunto de datos sintético: una organización con 50 000 relaciones activas, 3 razones sociales, 200 sedes, 2 años de marcaciones, 24 periodos de planilla; más 300 organizaciones pequeñas para probar convivencia.

| Escenario | Qué se mide |
|---|---|
| Listado de colaboradores con filtros y orden | Latencia p95 y plan de consulta (sin recorridos completos de tabla) |
| Ficha 360 | Latencia p95 y número de consultas |
| Pico de marcación (entrada de turno) | Marcaciones por segundo sostenidas sin pérdida; latencia p95 de escritura |
| Sincronización de lote sin conexión | Sin duplicados |
| Bandeja de pendientes de un manager con equipo grande | Latencia p95 |
| Cálculo de planilla de 50 000 relaciones | Duración total, uso de memoria por lote, reanudación tras fallo de un lote |
| Generación de boletas | Duración y cola |
| Importación masiva | Duración; validación por fragmentos |
| Búsqueda global | Latencia p95 |
| Tableros | Latencia p95 sobre agregados |
| Exportación grande | Memoria constante |
| Convivencia | Una organización calculando planilla no degrada a las demás |

Los **presupuestos numéricos** (latencias objetivo, duración máxima del cálculo, marcaciones por segundo) los fija el negocio (D-22) y se convierten en umbrales que hacen fallar la prueba. Cada escenario guarda su línea base para detectar regresiones.
