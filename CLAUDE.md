# GestiaHR — instrucciones para Claude Code

## Qué es

GestiaHR es un SaaS integral de Recursos Humanos (HRIS/HCM) multiempresa: una organización cliente administra varias razones sociales y todo el ciclo de vida del colaborador.
Nace para Perú (planilla, SUNAT, AFP, SST, SUNAFIL) y se extiende a Latinoamérica con paquetes de país.
Es modular: cada organización contrata módulos (Core HR, Tiempo, Nómina, Talento…) sobre el mismo código y la misma base.
La ficha del colaborador es la fuente única; los demás módulos consumen sus datos con vigencia por fecha.
El prototipo (`kit/prototipo/`) es referencia de navegación y flujo, no de implementación (ver `kit/docs/15-que-no-copiar-del-prototipo.md`).

## Antes de escribir código

Si es la primera vez en el repositorio, lee `kit/EMPEZAR-AQUI.md` y `kit/docs/20-entrega-al-equipo.md`, y toma las actividades en el orden de `kit/backlog/ruta-mvp.md`. Si te piden avanzar una épica completa, sigue `kit/prompts/<ÉPICA>.md`.

1. Lee la historia en `kit/backlog/backlog.md` (búscala por su código: `grep -n "VAC-3 ·" kit/backlog/backlog.md`) y la especificación del módulo en `kit/docs/modulos/`. Según lo que toque: estados en `kit/docs/05-maquinas-de-estado.md`, reglas en `kit/docs/06-reglas-de-negocio.md`, permisos en `kit/docs/07-permisos-y-roles.md`, norma peruana en `kit/docs/14-localizacion-peru.md`.
2. Lee las tablas implicadas en `kit/database/schema.sql` y `kit/docs/04-modelo-de-datos.md`. No inventes tablas ni columnas: si falta algo, propón la migración.
3. Busca la ruta en `kit/api/openapi.yaml` (es grande: busca por ruta con `grep -n`, no lo leas entero).
4. Si la historia toca una decisión abierta (`kit/docs/13-decisiones-abiertas.md`), detente y pregunta.

## Estructura del monorepo

```
apps/
  api/                      Laravel 12 · PHP 8.4
    app/Modules/<Modulo>/   un módulo de dominio por carpeta (ver kit/docs/02-arquitectura.md §4)
      Domain/               entidades, objetos de valor, máquinas de estado, eventos, puertos
      Application/          casos de uso (Actions), consultas (Queries), DTO, políticas
      Infrastructure/       modelos Eloquent, repositorios, adaptadores, trabajos en cola
      Http/                 controladores, FormRequests, Resources, rutas
      Database/             migraciones, seeders, factories del módulo
      Tests/                Unit, Feature, Golden
    app/Shared/             Tenancy, Vigencia, Workflow, Outbox, Auditoria, Permisos, Dinero, Archivos
    packages/country-pe/    paquete de país Perú (conceptos, parámetros, archivos, plantillas)
  web/                      Next.js 15 · React · TypeScript · Tailwind
    src/app/                rutas (App Router): (app)/, (portal)/, (plataforma)/, (publico)/
    src/modules/<modulo>/   pantallas, componentes y hooks del módulo
    src/ui/                 sistema de diseño (tokens y componentes base)
    src/lib/                cliente de API, permisos, i18n, formato
    messages/               es.json, en.json, pt.json
packages/
  contracts/                tipos TypeScript generados desde kit/api/openapi.yaml
kit/                        este kit: documentación, esquema, OpenAPI, backlog, prototipo, prompts y generadores
CLAUDE.md                   este archivo (en la raíz del repositorio, no dentro de kit/)
.claude/commands/           comandos de Claude Code (también en la raíz)
```

Todas las rutas de este archivo y de los comandos son relativas a la raíz del repositorio. Los generadores de `kit/tools/` asumen esta misma estructura.

## Comandos

```bash
make up                         # Postgres 17, Redis, almacenamiento S3 local, correo de pruebas
make api-install web-install    # dependencias
make migrate seed               # migraciones + organización demo coherente
make api                        # API en :8000   ·   make web → web en :3000   ·   make horizon → colas
make test                       # todo: Pest + Vitest
make test-api  F=NombreDeTest   # una prueba de backend
make test-golden                # pruebas doradas de planilla
make test-isolation             # aislamiento entre organizaciones sobre todas las rutas
make test-e2e                   # Playwright
make lint                       # Pint + PHPStan (Larastan) + ESLint + tsc
make contracts                  # regenera packages/contracts desde kit/api/openapi.yaml
make openapi                    # python3 kit/tools/gen_openapi.py (regenera y valida)
```

Si un comando no existe todavía en el `Makefile`, créalo en la historia PLT correspondiente; no inventes alias distintos.

## Reglas no negociables

Cada regla indica cómo se comprueba. Un cambio que rompe una regla no se entrega aunque «funcione».

1. **Aislamiento por organización.** Toda tabla de negocio lleva `tenant_id`, política RLS y claves foráneas compuestas `(tenant_id, id)`. Ninguna consulta usa `withoutGlobalScopes` ni una conexión sin contexto de organización fuera de `app/Shared/Tenancy` y de la consola de plataforma.
   *Cómo se comprueba:* `make test-isolation` recorre todas las rutas de `openapi.yaml` con un usuario de la organización B contra datos de la A y exige 404; una prueba de arquitectura falla si aparece `withoutGlobalScopes` fuera de las carpetas permitidas; una prueba de esquema falla si una tabla con `tenant_id` no tiene RLS forzado.

2. **Historia con vigencia; nada se sobrescribe.** Asignación, compensación, contrato, jefe, horario y parámetros se guardan como filas con `valid_from` / `valid_to`. Cambiar es cerrar la fila vigente e insertar otra. No hay `UPDATE` sobre el valor de una fila vigente cerrada ni `DELETE` físico de datos laborales (soft delete + papelera + retención).
   *Cómo se comprueba:* restricción de exclusión en base (sin solapes por relación); prueba que cambia un dato con fecha pasada, presente y futura y verifica que `as_of` devuelve el valor correcto en cada fecha.

3. **Los estados cambian solo por acciones.** No existe `PATCH` del campo `status`. Cada transición es `POST /recurso/{id}/accion`, la valida la máquina de estados del dominio y devuelve 409 `invalid_transition` si no procede.
   *Cómo se comprueba:* prueba de matriz por recurso (cada estado × cada acción) generada desde la definición de la máquina; prueba que envía `status` en un `PATCH` y verifica que se ignora o rechaza.

4. **Toda regla legal es un parámetro con vigencia.** Tasas, topes, plazos, UIT, RMV, días de vacaciones, umbrales de comités: tabla de parámetros legales por país y régimen con `valid_from`. Ningún número legal en código PHP ni TypeScript.
   *Cómo se comprueba:* prueba de arquitectura que busca literales sospechosos en `Modules/Payroll` y `packages/country-*` (lista de patrones en `kit/docs/11-pruebas-y-calidad.md` §8); prueba dorada que cambia un parámetro con fecha futura y verifica que el periodo anterior no cambia.

5. **Auditoría de solo inserción.** Toda acción sensible registra quién, qué, cuándo, desde dónde, valor anterior y valor nuevo. La tabla de auditoría no admite `UPDATE` ni `DELETE` (privilegios revocados al rol de aplicación). Auditoría, actividad de usuario y logs técnicos son tres almacenes distintos.
   *Cómo se comprueba:* prueba que intenta actualizar y borrar una fila de auditoría con el rol de aplicación y espera error de permisos; cada prueba de acción sensible afirma la fila de auditoría con antes y después.

6. **Permisos por campo y alcance.** Sueldo, salud, denuncias, sanciones, biometría y afiliación sindical exigen permiso por campo además del permiso del recurso. Sin el permiso, el campo no viaja en la respuesta (no se enmascara en el cliente). El alcance (organización, empresa, sede, unidad, equipo, propio) se aplica en la consulta, no en la vista.
   *Cómo se comprueba:* matriz de permisos (rol × endpoint × campo sensible) en `Tests/Feature/Permissions`; la prueba «administrador que no ve sueldos» debe pasar siempre.

7. **Acceso a datos sensibles registrado.** Leer un campo sensible deja fila en el registro de accesos (quién, qué registro, qué campos, cuándo).
   *Cómo se comprueba:* prueba que lee la compensación de un colaborador y afirma la fila en el registro de accesos.

8. **Integraciones por adaptadores + outbox.** Ningún módulo de dominio llama a un proveedor externo. Se define un puerto (interfaz), una implementación falsa usable en desarrollo y pruebas, y el efecto externo sale por la tabla outbox dentro de la misma transacción que el cambio.
   *Cómo se comprueba:* prueba de arquitectura: `Domain/` y `Application/` no importan SDK de terceros ni clientes HTTP; toda prueba corre con los adaptadores falsos; prueba que fuerza un fallo del proveedor y verifica reintento sin duplicar el efecto.

9. **Idempotencia.** Los `POST` que crean o disparan efectos aceptan `Idempotency-Key`; los consumidores de eventos y los trabajos en cola son idempotentes (registro de eventos procesados). Las marcaciones sin conexión se deduplican por `client_id`.
   *Cómo se comprueba:* prueba que repite la misma solicitud con la misma clave y verifica una sola fila y la misma respuesta; prueba que entrega dos veces el mismo evento.

10. **Lo que la interfaz oculta, el backend lo valida.** Permiso, alcance, módulo contratado, límite del plan y regla de negocio se validan en el servidor. El frontend solo decide qué mostrar.
    *Cómo se comprueba:* cada endpoint tiene prueba de 403 sin permiso, 403 `module_not_enabled` con el módulo apagado y 422 con datos inválidos.

11. **Sin lógica legal ni de cálculo en el frontend.** El navegador no calcula planilla, saldos de vacaciones, antigüedad para beneficios, tardanzas ni vencimientos legales: los pide a la API. El frontend formatea.
    *Cómo se comprueba:* revisión (`/revisar`) y regla de lint que prohíbe importar parámetros legales en `apps/web`; las simulaciones se hacen con endpoints `simulate`.

12. **i18n, dinero y fechas.** Ningún texto visible en código: claves en `messages/{es,en,pt}.json` y `lang/` del backend. Todo monto es `{amount, currency}` con decimal exacto (nunca `float`). Fechas-hora en UTC en base; se muestran en la zona horaria de la empresa; las fechas laborales (ingreso, vigencia) son `date` sin hora.
    *Cómo se comprueba:* lint de cadenas literales en JSX; prueba que falla si falta una clave en algún idioma; análisis estático que prohíbe `float` en columnas y DTO de dinero.

13. **La IA asiste, no decide.** Ninguna ruta de IA ejecuta contratar, cesar, sancionar, promover ni cambiar sueldo. La IA consulta datos con los permisos del usuario que pregunta, cada uso queda en la bitácora de IA, y toda alerta o puntaje lleva explicación.
    *Cómo se comprueba:* prueba que pregunta por sueldos con un usuario sin permiso y verifica que no se filtran; prueba que la lista de herramientas de IA no contiene acciones de alto impacto; toda respuesta de alerta incluye `explanation` no vacío.

14. **Registro de asistencia inalterable y anonimato real.** Una marcación no se edita ni se borra: la corrección es otra marcación con motivo que referencia a la original. Las respuestas de encuestas anónimas y las denuncias anónimas no guardan usuario, relación laboral ni IP.
    *Cómo se comprueba:* privilegios de solo inserción en la tabla de marcaciones; prueba de esquema que verifica que las tablas de respuestas anónimas no tienen columnas de identidad; prueba de umbral mínimo por segmento.

15. **Pruebas obligatorias y contrato primero.** Ninguna historia se entrega sin: prueba del caso feliz, de permiso, de aislamiento, de transición inválida si hay estados, y actualización de `openapi.yaml` + `make contracts` si cambió la API. Las fórmulas de planilla exigen caso dorado.
    *Cómo se comprueba:* `make test lint` en verde; la prueba de contrato compara las rutas registradas en Laravel contra `openapi.yaml` y falla ante cualquier diferencia.

## Forma de trabajar

- Empieza por el primer corte comercial: las actividades marcadas «· MVP» en `kit/backlog/backlog.md` (`grep -n "· MVP" kit/backlog/backlog.md`). Ninguna depende de algo fuera del corte. Después siguen las marcadas «· Ola 2» (WhatsApp básico, simulador de costo laboral y modo estudio contable); si una historia mvp parece necesitar asistencia, horas extra o préstamos, léelos por su puerto y usa las incidencias del periodo cuando el módulo no esté (`kit/docs/01-vision-y-fases.md` §5.1). El sitio comercial gestiahr.com (épica WEB, etiqueta `web`) está pendiente y separado: solo depende del MVP, así que se construye en paralelo o justo antes de abrir la venta; su diseño de referencia está en `kit/docs/web/` y sus productos en `kit/docs/01-vision-y-fases.md` §4.1.
- Los archivos generados no se editan a mano: `kit/database/schema.sql`, `kit/database/catalogos/`, `kit/docs/04`, `kit/docs/19` (desde `kit/tools/esquema/`), `kit/docs/20` y `kit/prompts/` (desde `kit/tools/gen_entrega.py`), `kit/api/openapi.yaml` y la tabla de `kit/docs/08` (desde `kit/tools/gen_openapi.py`) y `kit/backlog/` (desde `kit/tools/fuente/`).
- Si cierras una decisión de `kit/docs/13-decisiones-abiertas.md`, regístrala en `kit/docs/adr/` con la plantilla.
- Cambios pequeños y verticales: migración → dominio → caso de uso → endpoint → pruebas → pantalla.
- No preguntes para continuar con el siguiente paso lógico. Pregunta solo ante una decisión abierta o una ambigüedad legal.
- No borres código existente sin justificarlo: clasifica primero (funciona / mejorable / parcial / duplicado / obsoleto).
- No dejes botones sin función ni datos de ejemplo incoherentes en el seeder demo.
- No incluyas precios, costos ni estimaciones de esfuerzo en código, comentarios ni documentos.
- Ante una duda normativa peruana: no asumas la tasa. Créala como parámetro, márcala «por validar» y anótala en la historia.
- **Toda prueba Feature de un endpoint DEBE llamar a `$this->assertMatchesOpenApi($response, $method, $path, $status)` para verificar el código de estado y los campos requeridos del body contra la operación exacta declarada en `kit/api/openapi.yaml`.** El ayudante está en `Tests\Support\OpenApiAssert` (incluido por defecto en `Tests\TestCase`).
- El avance por actividad se registra en `docs/AVANCE.md` (fuera de `kit/`, porque es del repositorio y no del kit); la guía de trabajo del repositorio está en `docs/guia-trabajo.md`.

## Comandos de Claude Code disponibles

`/historia <código>` · `/migracion <descripción>` · `/endpoint <método y ruta>` · `/pantalla <ruta del prototipo o nombre>` · `/revisar [rama o rango]`. Los prompts completos están en `PROMPTS.md`.
