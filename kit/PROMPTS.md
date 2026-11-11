# Prompts para Claude Code

Prompts listos para copiar. Sustituye lo que está entre `< >`. Cinco de ellos existen también como comandos (`/historia`, `/migracion`, `/endpoint`, `/pantalla`, `/revisar`) en `.claude/commands/`; aquí están en versión larga, junto con los que no tienen comando.

Todos presuponen que Claude Code ya leyó `CLAUDE.md` (lo hace solo al abrir el repositorio).

---

## 1. Implementar una historia del backlog

```
Implementa la historia <CÓDIGO> de GestiaHR.

Lee primero, sin escribir código:
- la historia y sus criterios de aceptación en kit/backlog/backlog.md (búscala por código);
- lo que aplique de kit/docs/05-maquinas-de-estado.md, kit/docs/06-reglas-de-negocio.md,
  kit/docs/07-permisos-y-roles.md y kit/docs/14-localizacion-peru.md;
- la especificación del módulo en kit/docs/modulos/;
- las tablas implicadas en kit/database/schema.sql;
- las rutas en kit/api/openapi.yaml (búscalas con grep, el archivo es grande).

Si depende de una historia no implementada o de una decisión abierta
(kit/docs/13-decisiones-abiertas.md), detente y dímelo.

Escribe un plan de máximo 12 líneas (tablas, casos de uso, endpoints, permisos,
eventos, pantallas, pruebas) y continúa sin esperar confirmación.

Orden: migración → dominio (entidad, máquina de estados, eventos) → caso de uso
(una transacción: cambio + auditoría + outbox) → endpoint → pruebas → pantalla.

Pruebas mínimas: caso feliz; 403 sin permiso; 403 con el módulo apagado; 404 desde
otra organización; 422; 409 por transición inválida; auditoría con antes y después;
evento en outbox; campo sensible ausente sin permiso por campo.

Si cambió la API: edita kit/tools/gen_openapi.py y ejecuta make openapi contracts.
Termina con make test lint en verde y un informe: criterios cumplidos uno por uno,
archivos tocados, pruebas añadidas y lo que quedó fuera con su motivo.

No añadas nada que la historia no pida. Ningún número legal en código.
```

## 2. Crear una migración

```
Crea la migración para <tabla o cambio> en el módulo <Módulo>.

Usa los nombres y tipos de kit/database/schema.sql; si la tabla no está ahí, añádela
también a schema.sql y a kit/docs/04-modelo-de-datos.md.

Requisitos:
- tenant_id no nulo, única (tenant_id, id), claves foráneas compuestas
  (tenant_id, x_id), RLS activado y forzado con Tenancy::enableRls().
- Si guarda historia: valid_from, valid_to y restricción de exclusión contra solapes.
- Estados como text con CHECK. Dinero numeric(14,2) + moneda char(3). Sin float.
- Índices para cada filtro y orden del listado; índice parcial si hay deleted_at.
- Si es de solo inserción (auditoría, marcaciones, bitácoras): revoca UPDATE y DELETE
  al rol de aplicación.
- down() reversible.

Añade factory, datos coherentes en el seeder demo y una prueba de esquema que
verifique RLS, FK compuestas y ausencia de solapes. Ejecuta make migrate y las pruebas.
```

## 3. Crear un endpoint

```
Crea el endpoint <MÉTODO /ruta>.

Tómalo de kit/api/openapi.yaml (x-permission, x-phase, esquemas). Si no existe, añádelo
en kit/tools/gen_openapi.py y regenera con make openapi contracts.

- FormRequest (validación + authorize con la Policy), Action o Query (sin lógica en
  el controlador), Resource (campos sensibles con SensitiveField::when).
- Middlewares: tenant, module:<clave>, throttle.
- Listado: paginación en servidor, filter[...], sort, as_of, alcance en la consulta.
- Escritura: una transacción con cambio + auditoría + outbox; Idempotency-Key en POST;
  If-Match en PATCH.
- Acción de estado: POST /recurso/{id}/accion validada por la máquina de estados.
- Errores con la envoltura y códigos de kit/docs/08-api.md.

Pruebas: 200/201, 401, 403 sin permiso, 403 module_not_enabled, 404 otra organización,
404 fuera de alcance, 422, 409, idempotencia, auditoría, outbox, campo sensible.
```

## 4. Crear una pantalla desde el prototipo

```
Construye en apps/web la pantalla <nombre> que en el prototipo está en <ruta o menú>.

Recorre la pantalla del prototipo: flujo, columnas, filtros, acciones, estados vacíos.
Copia el flujo y la jerarquía visual; no copies el código, ni los datos guardados en
el navegador, ni los cálculos (kit/docs/15-que-no-copiar-del-prototipo.md).

- Componentes solo de src/ui (kit/docs/10-pantallas-y-ux.md). Si falta uno, créalo ahí.
- Datos desde la API con los tipos de packages/contracts; paginación, filtros y orden
  en servidor; filtros reflejados en la URL.
- can() y moduleOn() para mostrar acciones; un campo sensible se pinta solo si viene
  en la respuesta.
- Textos en messages/es.json, en.json y pt.json. formatMoney y formatDate.
- Estados: cargando, vacío con acción, error con reintento, sin permiso.
- Sin reglas legales ni cálculos de negocio en el cliente.

Verifica 1280 px y 390 px, teclado, tema claro y oscuro. Añade prueba de componente
y un recorrido Playwright del flujo principal.
```

## 5. Añadir un concepto de planilla

```
Añade el concepto de planilla <código y nombre> al paquete de país <PE>.

Lee kit/docs/modulos/ (nómina), kit/docs/02-arquitectura.md §11 y los conceptos existentes en
apps/api/packages/country-pe/concepts/.

1. Define el concepto como datos, no como código: código, nombre (clave i18n), tipo
   (ingreso, descuento, aporte del empleador, impuesto, provisión, informativo),
   fórmula en el lenguaje del motor, condiciones (régimen, tipo de trabajador),
   afectaciones (qué bases integra: pensiones, salud, renta de quinta, CTS,
   gratificación, vacaciones), código oficial para la declaración, cuenta contable,
   orden de cálculo y vigencia (valid_from).
2. Toda tasa, tope o monto legal que use la fórmula debe venir de un parámetro legal
   con vigencia: param('clave', fecha). Si el parámetro no existe, créalo en el paquete
   con su fuente normativa. Si no tienes un valor verificado, NO lo inventes: déjalo sin
   valor, marcado "por validar", y dímelo.
3. Si necesita una función nueva del motor (no expresable con las existentes), añádela
   en Modules/Payroll/Domain/Engine/Functions con pruebas unitarias; debe ser pura.
4. Casos dorados: añade al menos tres casos en Tests/Golden/<pais>/<concepto>/ (entrada
   + resultado esperado + fuente). Los resultados esperados los confirma el especialista
   en planilla: marca los casos como "pendiente de validación" hasta que lo haga.
5. Verifica que un cambio de vigencia no altera periodos cerrados: prueba con dos
   versiones del concepto y un periodo anterior.
6. Ejecuta make test-golden.

Informa: definición final, parámetros usados o creados, bases afectadas, casos dorados
y lo que queda por validar.
```

## 6. Añadir un paquete de país

```
Crea el esqueleto del paquete de país <código ISO, p. ej. CO> en
apps/api/packages/country-<iso>/, tomando country-pe como referencia de estructura
(no de contenido).

Un paquete de país contiene solo datos y adaptadores declarados; el motor no cambia.
Estructura:
- pack.json: país, versión, moneda, zona horaria por defecto, idiomas, dependencias.
- regimes/: regímenes laborales y sus reglas (días de vacaciones, beneficios que aplican).
- parameters/: parámetros legales con vigencia y fuente normativa.
- concepts/: conceptos de planilla con fórmula, afectaciones y código oficial.
- calendars/: feriados.
- documents/: plantillas de contratos, certificados y boletas.
- filings/: generadores de archivos para la autoridad (implementan el puerto
  FilingGenerator) y bancos.
- compliance/: obligaciones del tablero de cumplimiento (regla de aplicabilidad +
  evidencia exigida).
- identity/: tipos de documento de identidad y su validación.
- lang/: traducciones propias del país.
- tests/golden/: casos dorados.

Pasos:
1. Crea la estructura y el registro del paquete (PackRegistry); que se pueda activar
   por organización y por razón social con el feature flag de país.
2. No copies valores de Perú. Deja parameters/ y concepts/ con la lista de lo que hay
   que definir y cada valor vacío marcado "por validar con especialista local".
3. Prueba de arquitectura: el paquete no importa clases de otro paquete de país ni
   modifica Modules/Payroll.
4. Prueba: una razón social del país nuevo y otra peruana en la misma organización
   calculan cada una con su paquete.

Informa qué queda por definir con el especialista local, agrupado por carpeta.
```

## 7. Revisar un cambio

```
Revisa <rama, commit o rango; o "los cambios sin confirmar"> contra CLAUDE.md.
No corrijas: informa.

Responde cada punto con "cumple", "no aplica" o el hallazgo con archivo y línea:
1. Aislamiento (tenant_id, RLS forzado, FK compuestas, trabajos en cola con organización).
2. Vigencias (sin UPDATE de historia, sin borrado físico).
3. Estados solo por acción y máquina de estados.
4. Ningún número legal en código.
5. Auditoría con antes y después; registro de accesos sensibles.
6. Policy por endpoint, alcance en la consulta, campos sensibles filtrados en el Resource.
7. Puerto + adaptador falso + outbox; sin SDK en Domain/ ni Application/.
8. Idempotencia en POST, consumidores y trabajos.
9. Frontend sin cálculo de negocio, sin textos literales, sin datos de negocio en localStorage.
10. Dinero decimal con moneda; fechas UTC y zona de la empresa.
11. IA sin acciones de alto impacto, con permisos y bitácora.
12. Sin N+1; paginación en servidor; índices; masivos por lotes.
13. Pruebas: feliz, permiso, módulo apagado, aislamiento, validación, transición, dorado.
14. openapi.yaml y packages/contracts al día.
15. Sin secretos ni datos personales en logs; archivos con URL firmada.

Cierra con: bloqueantes, menores, pruebas que faltan y el resultado de make test lint.
```

---

## 8. Construir una página del sitio comercial

```
Construye la página <ruta, por ejemplo /precios> del sitio gestiahr.com (historia <WEB-x>).

Lee primero, sin escribir código:
- la historia en kit/backlog/backlog.md y las reglas RN-WEB-01 a RN-WEB-12 en kit/docs/06-reglas-de-negocio.md;
- el diseño de referencia en kit/docs/web/sitio/<archivo>.html y sus estilos en
  kit/docs/web/sitio/assets/site.css (mismos tokens que kit/docs/10);
- los endpoints /public/* que use en kit/api/openapi.yaml.

Reproduce la estructura, los textos y el aspecto del diseño de referencia con los
componentes del sistema de diseño; no copies el HTML tal cual. Los textos, planes,
precios y estados («Próximamente») vienen del catálogo público y del CMS, nunca
escritos en el código. La página funciona desde 360 px sin desborde horizontal.

Termina con pruebas (render, enlaces, formulario con y sin errores, accesibilidad
básica) y un informe de lo que quedó distinto del diseño y por qué.
```

## 9. Avanzar una épica completa

Cada épica tiene su prompt en `kit/prompts/<ÉPICA>.md` (índice en `kit/prompts/README.md`), generado desde el backlog con `kit/tools/gen_entrega.py`. Trae las actividades en el orden en que se desbloquean, las dependencias de otras épicas y las decisiones abiertas que la tocan. Se pega al abrir una sesión nueva de Claude Code en la raíz del repositorio. Para retomar una épica a medias:

```
Retomamos la épica <ÉPICA> de GestiaHR. Lee CLAUDE.md y kit/prompts/<ÉPICA>.md.
Revisa en git log qué actividades ya están integradas (cada commit lleva [<clave>]) y
dime en una lista corta cuáles faltan y cuáles están libres porque sus dependencias ya
están en main. Luego sigue con la primera libre usando /historia, sin pedir confirmación
salvo ante una decisión abierta.
```

## Prompts de apoyo

**Diagnosticar una diferencia de planilla**

```
El caso dorado <ruta> falla: esperado <x>, obtenido <y>. No cambies el resultado
esperado. Muestra la traza de cálculo del motor (concepto por concepto, con el
parámetro y la vigencia usados) y localiza el primer concepto que diverge. Propón la
corrección en la fórmula o en el parámetro, nunca en el caso.
```

**Añadir un adaptador de integración**

```
Añade el adaptador <proveedor> para el puerto <NombreDelPuerto> (kit/docs/09-integraciones.md).
Implementa la interfaz completa, el mapeo de errores a las excepciones del puerto, la
verificación de salud y la configuración por organización cifrada. No toques el
dominio. Pruebas: de contrato (las mismas que pasa el adaptador falso) y de fallo
(tiempo de espera, 5xx, respuesta inválida) verificando reintento sin duplicar.
```

**Añadir un evento de dominio**

```
Añade el evento <modulo.hecho> (kit/docs/02-arquitectura.md §9). Define el payload con
versión, emítelo dentro de la transacción del caso de uso vía outbox, regístralo en el
catálogo (GET /events) y documenta sus consumidores internos. Prueba: el evento queda
en outbox en la misma transacción; un consumidor lo procesa una sola vez aunque se
entregue dos.
```

**Clasificar código existente antes de tocarlo**

```
Antes de modificar <carpeta>, clasifica lo que hay: funciona / mejorable / parcial /
duplicado / obsoleto, con una línea de evidencia por elemento. No borres nada hasta
que te confirme la lista de lo obsoleto.
```
