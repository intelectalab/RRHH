# Ayni HR — API interna del prototipo para escribir módulos

El prototipo es un solo HTML (`core.html`) en JavaScript puro, sin librerías. Cada módulo es un archivo `mod-N-nombre.js` que se inserta como `<script>` después del núcleo. **Lee `core.html` completo antes de escribir**: tu módulo debe verse y comportarse igual que las pantallas del núcleo (Colaboradores, Ficha, Estructura, Roles, Auditoría).

## Reglas obligatorias

1. Todo el archivo va dentro de una IIFE: `(function(){ 'use strict'; ... registerModule({...}); })();`. Nada en el ámbito global.
2. Prefija con el prefijo de tu módulo (se te indica) TODO lo que agregues a espacios compartidos: rutas, nombres de acciones (`A`), claves de `ui`, colecciones del tenant, ids de elementos.
3. **Ningún botón falso.** Todo control visible funciona, persiste y actualiza las demás pantallas. Si algo no se puede hacer en un prototipo (enviar correo real, integrarse con un proveedor), se simula de forma explícita y útil (p. ej. queda registrado en una bandeja «Mensajes enviados (simulado)») y se rotula como simulado.
4. Los cambios persisten: muta `T()` y llama `save()` y luego `render()` (los `submit` de `openForm` y `confirmBox` ya hacen save+render al terminar).
5. Toda acción sensible registra auditoría con `log(...)`.
6. Texto de interfaz en español de Perú, claro y directo, sin jerga técnica ni inglés (salvo términos habituales: OKR, eNPS, 9-box). Nada de precios del SaaS, horas ni estimaciones de desarrollo.
7. Colores solo con las variables CSS del núcleo (`var(--accent)`, `--ok`, `--warn`, `--crit`, `--muted`, `--line`, `--surface`, `--surface2`, `--accent-soft`, `--ok-soft`, `--warn-soft`, `--crit-soft`, `--ink`, `--bg`). Nunca colores literales: debe verse bien en tema claro y oscuro.
8. Responsive real a 400 px: toda tabla dentro de `<div class="tw">`, filas con `class="row"` (flex con wrap), grillas con `class="grid g2"`/`g4`. Nada con ancho fijo mayor a la pantalla. Kanban/calendarios anchos van dentro de un contenedor con `overflow-x:auto`.
9. Prohibido `alert/confirm/prompt`, `window.print`, descargas (`<a download>`), iframes y librerías externas. Para «exportar/descargar» usa `openModal` con un `<textarea readonly>` y botón Copiar (mira la acción `csv`/`copyCsv` del núcleo). Los «PDF» (boletas, certificados, contratos) se muestran como vista previa con formato de documento dentro de `openModal(..., true)`.
10. Gráficos: SVG en línea o barras CSS con las variables de color. Texto de gráficos con `fill="currentColor"` o variables.
11. Estados vacíos útiles con `emptyState(titulo, texto, botonHtml)`; nunca solo «No hay registros».
12. Datos de ejemplo coherentes con la organización demo (abajo). Se siembran SOLO cuando `tid==='t1'`; para otros tenants se crean las colecciones vacías. Las fechas sensibles al tiempo deben ser relativas a `TODAY` (usa `addDays(TODAY,-n)`), para que el demo siempre se vea vigente.
13. Respeta permisos y alcance: usa `can('permiso')`, `scoped()` (relaciones que el usuario puede ver), `myRel()`, `own(rel)`. Lo sensible (sueldos, salud, denuncias, sanciones) nunca se muestra sin permiso.
14. La IA nunca decide: redacta, resume, sugiere; la decisión es de una persona.
15. No modifiques `core.html`. Si necesitas algo del núcleo que no existe, usa primero los puntos de extensión (sección «Puntos de extensión del núcleo»); si aun así falta, resuélvelo dentro de tu módulo y menciónalo en tu informe final.

## Globales del núcleo que puedes usar

Datos y sesión:
- `db` (todo), `T()` datos del tenant actual, `tenant()`, `me()` usuario, `role()`, `can(p)`, `modOn(id)`, `myRel()` relación laboral activa del usuario (o null), `own(rel)`.
- `get(coleccion,id)`, `per(rel)` persona, `nm(rel)` nombre completo, `relName(relId)`, `uniOf(rel)` unidad, `cur(rel)` moneda, `under(rel,[relIds])` ¿reporta (directa o indirectamente) a alguno?, `scoped()` relaciones visibles según alcance, `visible()` = scoped + filtro de empresa (`ui.emp`).
- `nid(prefijo)` id nuevo, `save()`, `log(accion, entidad, registro, regId, antes, despues, sensible?)`.
- Utilidades: `esc(s)` (¡escapa siempre texto de usuario!), `fd('2026-10-02')` → `02/10/2026`, `TODAY`, `iso(date)`, `days(a,b)`, `addDays(s,n)`, `ini(nombre)`, `money(v,moneda)`, `antig(rel)`.
- `ui` estado de interfaz (agrega tus claves con prefijo), `go(ruta, extraUi)`, `render()`, `toast(msg)`.
- Eventos de dominio: `on('employee.hired'|'employee.updated'|'employee.terminated', fn)` y `emit(evento, payload)`. Payload: `{rel,...}`. Puedes emitir los tuyos (`leave.approved`, `payroll.closed`, `candidate.hired`, …) con `emit`.

Interfaz:
- `pageHead(crumb, titulo, accionesHtml, subtituloHtml)`, `pill(texto, 'ok'|'warn'|'crit'|'acc'|'')`, `emptyState(...)`, `whoCell(rel)` (avatar + nombre + puesto), `st(rel)` pill de estado.
- `openForm(build, valores)`: `build(v)` devuelve `{title, ok?, note?, fields:[{k,l,type?,opts?,req?,full?,dyn?,keep?}], submit(o){ ...; return 'mensaje de error' | undefined }}`. Tipos: text (def.), date, number, email, time, month, textarea, `checks` (con `opts`, valor = array), y select si hay `opts` (`[[valor,etiqueta],...]` o `['a','b']`). `dyn:1` re-ejecuta `build` al cambiar ese campo. `keep:1` quita la opción vacía. Al terminar sin error: cierra, guarda y renderiza.
- `confirmBox(titulo, htmlTexto, etiquetaOk, fn, peligro?)`, `openModal(titulo, bodyHtml, footerHtml?, ancho?)`, `closeM()`.
- `opt(coleccion, filtro?)` → opciones `[id,nombre]` de registros activos; `actRels(filtro?)` → opciones de colaboradores activos.
- Eventos delegados del núcleo (no agregues listeners si te bastan):
  - `data-a="accion"` + `data-*` → `A.accion(dataset, event)`. Los `<a href="#" data-a>` no navegan.
  - `<input data-i="claveUi">` → al escribir hace `ui[clave]=valor; render()` (búsquedas). Para conservar el foco, registra el id del input: el núcleo solo restaura foco de ids `q|dq|aq|gq`; en tu módulo usa `oninput` propio o vuelve a enfocar tras render (patrón: guarda `document.activeElement.id` antes y enfoca después dentro de tu acción).
  - `<select data-c="claveUi">` → `ui[clave]=valor; render()`. `<input type=checkbox data-chk="claveUi">`.
  - Puedes añadir tus propios `document.addEventListener(...)` (drag & drop, change) dentro de la IIFE; filtra por atributos con tu prefijo.
- Clases CSS disponibles: `panel` (+ `ph` cabecera, `pb` cuerpo), `kpis`/`kpi`, `grid g2|g4`, `row`, `tw`, `btn` (+ `p` primario, `d` peligro, `s` pequeño, `ghost`), `in`, `pill`, `av` (+`xl`), `who`, `tabs`/`tab` (+`on`), `tl` (línea de tiempo `<ul class="tl"><li>`), `tree`, `node`, `bar` (`<div class="bar"><i style="width:40%"></i></div>`), `alert`+`dot`, `empty`, `note`, `err`, `mono`, `muted`, `num`, `chk`, `pop`+`menu` (desplegable con `<details class="pop"><summary class="btn">…</summary><div class="menu">…</div></details>`), `form` (grilla de formulario). `dl/dt/dd` ya tienen estilo de ficha. Si necesitas CSS propio, inyecta un `<style>` desde tu módulo con clases prefijadas y solo variables de color.

## registerModule

```js
registerModule({
  id:'tiempo',                 // id comercial del módulo (se activa/desactiva por organización)
  nombre:'Tiempo y asistencia',
  siempre:false,               // true = no depende de la suscripción
  perms:[['tiempo.asistencia.ver','Tiempo','Ver asistencia'], ...],       // [código, grupo, descripción]
  rolePerms:{r_rrhh:[...], r_mgr:[...], r_emp:[...], r_pay:[...], r_aud:[...]}, // permisos por defecto (r_admin tiene todo)
  nav:[{grupo:'Tiempo', icono:'clock' /*opcional*/, items:[['ti_asistencia','Asistencia',()=>can('tiempo.asistencia.ver'), ()=>nPendientes /*opcional: contador*/, 'clock' /*opcional: icono*/]]}],
  detail:['ti_detalle'],       // rutas sin entrada de menú
  views:{ti_asistencia:()=>html, ...},      // cada vista devuelve HTML (string)
  actions:{ti_marcar:(dataset,ev)=>{...}},
  init(t,tid){ /* crea colecciones (t.marcaciones=t.marcaciones||[]) y siembra demo si tid==='t1'. Se ejecuta una sola vez por organización. */ },
  fichaTabs:[['ti_asis','Asistencia', rel=>html, rel=>visible?]],  // pestañas extra en la ficha del colaborador
  alerts(rels){ return [{t:'título', d:'explicación de por qué importa y qué hacer', c:'crit'|'', rel:relId?}] }, // alertas del Inicio (siempre con explicación)
  home(){ return html },       // paneles extra en Inicio (según rol: usa role().alcance y myRel())
  pend(){ return [{id, tipo:'Vacaciones', t:'título', d:'detalle', relId, aprobar:{a:'ti_vacAprobar',id}, rechazar:{a:'ti_vacRechazar',id}, abrir:{r:'ti_vacaciones'}}] }, // pendientes del usuario actual (centro de aprobaciones)
  saasTabs:[['planes','Planes', ()=>html]],  // solo módulo plataforma: pestañas de la consola SaaS (ahí NO existe T())
});
```

`pendientes()` del núcleo junta los `pend()` de todos los módulos.

## Armazón, iconos y clases (lee esto antes de tocar la interfaz)

**Estructura.** `shell()` pinta `div.app` (clases `min` = barra en solo iconos, `sopen` = cajón abierto) con:
- `aside#side.side`: `.brand` (logotipo `.mark` + `b` con el nombre: la marca blanca de `plataforma` los reescribe), tarjeta `.side-user`, buscador `#mq` («Buscar en el menú», aparece con 12 entradas o más), `nav#sideNav.side-nav` con entradas `.nav` y grupos `.navg` > `.navh` + `.sub`, y pie `.side-foot` («Cuenta»: lo que aporten los módulos con `sideFoot` + Salir).
- `button.scrim`: fondo oscurecido del cajón.
- `div.main` > `header.top` (una sola línea, 60 px, nunca salta) + `div.content` > `div.wrap` (tu vista).

**Cabecera** (`header.top`), de izquierda a derecha: `.burger` (solo con la barra oculta), `.top-t` (miga «Organización · Rol» + título `.ttl`), buscador `.top-s` con `#gq` (atajo «/»), botón `.top-sb` (abre el buscador superpuesto por debajo de 1200 px), chip de empresa `.chip` con `#empSel`, botones de módulo `.top-x`, campana `details.top-b`, `details.top-cr` («Crear»; su `summary` conserva las clases `btn p`) y `details.top-u` (menú de usuario).

**Título de la pantalla.** No lo repitas: usa `pageHead(crumb,titulo,acciones,subtitulo)` (o el mismo marcado `<div class="head"><div><div class="crumb">…</div><h1>…</h1>`). El armazón saca ese `<h1>` de tu vista y lo muestra en la cabecera; en el contenido quedan el subtítulo y las acciones. La miga de la vista se conserva solo si dice algo distinto del nombre del grupo del menú. Si tu vista no empieza con ese encabezado (ficha, pantallas de detalle), la cabecera muestra la etiqueta del menú, o `titulos:{ruta:'Título'}` del módulo, o el nombre del módulo.

**Puntos de corte.** ≥1200 px: cabecera completa. 1024–1199: buscador como icono, sin nombres, «Crear» como icono, empresa dentro del menú de usuario. 768–1023: además la barra lateral queda en solo iconos (el botón de la barra la abre superpuesta). <768: barra lateral como cajón (cierra al tocar fuera, con Escape y al navegar), cabecera = menú + título + buscar + campana + avatar, «Crear» como botón flotante, botones de módulo dentro del menú de usuario, modales a pantalla completa y formularios a una columna. En escritorio el usuario puede contraer la barra (`ui.sideMin`, se recuerda).

**Iconos.** `ic(nombre, tamaño?, clase?)` devuelve un SVG en línea (24×24, trazo 1.75, `currentColor`). Nombres: `home, users, user, building, shield, clock, wallet, target, book, trend, briefcase, heart, alert, check, chart, zap, settings, inbox, tasks, calendar, bell, search, plus, menu, chev, x, logout, panel, activity, folder, dot, more, key, doc, refresh`. Para agregar uno: `ICONS.minombre='<path d="…"/>'` (solo trazos, sin colores). Cada grupo del menú toma su icono de `icono` en `nav`, o de `GICON[nombre del grupo]`, o `folder`; cada entrada suelta, del quinto elemento del arreglo, o de `EICON[etiqueta]`, o `dot`. Las entradas dentro de un grupo solo llevan icono si lo declaras.

**Claves nuevas de `registerModule`:**
- `topbar(){ return [{a:'accion', d:{r:'ruta'}, icono:'activity', label:'Texto accesible', n:contador, id:'idDeLaInsignia'}] }` — botones de icono de la cabecera (en celular pasan al menú de usuario). Ya no devuelve HTML.
- `avisos(){ return {n, items:[{t,d,a,data}], links:[[ruta,etiqueta,contador]]} }` — alimenta la campana (hoy: `plataforma`, con pendientes y avisos sin leer).
- `sideFoot(){ return [['accion','Etiqueta','icono']] }` — entradas de la sección «Cuenta» de la barra lateral.
- `loginExtra(){ return html }` — filas `button.acct` que se agregan a «Otros accesos» de la pantalla de ingreso (ya no un panel completo).
- `titulos:{ruta:'Título'}` — título de cabecera para rutas de `detail`.

**Clases nuevas:** `ib` (botón de icono de 40 px), `badge-n` (insignia de contador), `hero` (franja de saludo), `kcards`/`kcard` (+`ok|warn|crit`; indicador con icono `.ki` y borde de color), `chip`, `qchip`, `ptabs`/`ptab`, `menu w` (panel ancho), `mi`/`mh` (fila y título dentro de un menú). `xShell(miga, titulo, html, pestañas?)` da el mismo armazón sin barra lateral a la consola SaaS y a las sesiones de módulo.

**Ingreso.** `vLogin()` tiene dos columnas (marca y formulario). El formulario elige organización (`#lorg`), perfil (`[data-a="lperf"][data-p]`), usuario (`#lmail`) y contraseña de demostración, y entra con `[data-a="loginForm"]`. Las pruebas siguen entrando con los accesos rápidos `[data-a="login"][data-t][data-u]` y `[data-a="loginSaas"]`: no los quites.

## Puntos de extensión del núcleo

Claves opcionales de `registerModule` (solo se consultan en módulos contratados, salvo `loginExtra`, `loginCheck` y `hooks`, que valen siempre):

```js
registerModule({
  hooks:{ login(){}, logout(){}, route(ruta){}, fichaView({rel,tab}){}, audit(entrada){} },
      // avisos de sesión e interfaz. NO pasan por el bus de dominio (no disparan webhooks ni workflows).
      // fichaView se llama en cada render de la ficha: deduplica tú. audit recibe cada entrada creada con log().
  userMenu(){ return [['accion','Etiqueta'], ...] },      // botones extra en el menú de usuario
  topbar(){ return [{a,d,icono,label,n,id}] },             // botones de icono en la cabecera (ver «Armazón»)
  loginExtra(){ return html },                             // filas .acct en «Otros accesos» del ingreso (aquí no hay sesión ni T())
  loginCheck(tenantId,userId){ return 'mensaje' | '' },    // impide el ingreso mostrando el mensaje
  colabActions(){ return html },                           // botones en la cabecera de Colaboradores
  colabBulk:{ can:()=>bool, bar:idsSeleccionados=>html },  // activa las casillas de selección de la tabla (ui.sel) y pinta la barra
  movFuturo(rel,campo,valor,fecha,motivo){ return 'error' | undefined }, // recibe los movimientos de la ficha con fecha futura
  screenTabs:{ auditoria:[[id,'Título',()=>html,()=>visible?]], usuarios:[...], estructura:[...] }, // pestañas en pantallas del núcleo
  api:{ ... },                                             // funciones que el módulo comparte: (MODS.find(m=>m.id==='tiempo')||{}).api
});
```

- `hook(nombre, dato)` emite un aviso; `xs(clave)` devuelve los módulos contratados que aportan esa clave.
- **Listas maestras**: `lista(clave, valorActual?)` devuelve los valores activos de `T().listas[clave]` (`[{v,activo}]`) o, si no existe, los de `LISTAS[clave].v`. Claves: `tipoDoc, tipoTrabajador, modalidad, contratoTipo, motivoCese, estadoCivil, nivelPuesto, tipoUnidad`. Usa siempre `lista()` en lugar de listas fijas. `LISTAS[clave].fijos` son valores con reglas propias (no se renombran ni archivan).
- **Parámetros**: `param(clave, empresaId?)` lee `T().paramsEmp[empresaId][clave]`, luego `T().params[clave]` y por último `PARAMS[clave].d`. Un módulo registra los suyos con `PARAMS['mi.clave']={g:'Grupo',t:'Título',d:valorPorDefecto,tipo:'Número'|'Texto',u:'unidad',min,max,emp:1?,uso:'dónde se aplica'}`; aparecen solos en Configuración › Parámetros.
- `aplicarMov(rel, campo, valor, fecha, motivo)` aplica un movimiento con historial, auditoría y evento `employee.updated`.
- **Sesión de módulo**: `session={ext:'ruta', ...}` hace que el núcleo pinte `VIEWS[ruta]()` sin menú ni organización (no existe `T()`). **Pantalla completa**: `ui.full='ruta'` pinta `VIEWS[ruta]()` sin el armazón dentro de la organización; vuelve con `ui.full=''`.
- `api` publicadas hoy: `tiempo` → `marcar, abierta, sigMarca, pinDe, metodoOn, estadoDia, vacSaldo, ML, MB`; `plataforma` → `PL, pref, notify, applySub, planOf, NT, CH, modName`.

## Organización demo (tenant `t1`, «Grupo Andino (demo)»)

- Empresas: `e1` Andina Servicios S.A.C. (PEN), `e2` Andina Logística S.A.C. (PEN), `e3` Andina Colombia S.A.S. (COP).
- Sedes: `s1` Lima · San Isidro (e1), `s2` Arequipa (e1), `s3` Callao · Almacén central (e2), `s4` Bogotá (e3).
- Unidades: `u1` Gerencia General, `u2` Administración y Finanzas, `u3` Contabilidad, `u4` Recursos Humanos, `u5` Comercial, `u6` Ventas, `u7` Marketing, `u8` Tecnología, `u9` Desarrollo, `u10` Operaciones, `u11` Almacén.
- Puestos `p1`…`p17` (p17 Diseñador UX está vacante). Centros de costo `c1`…`c4`.
- Relaciones laborales (`rlN`, persona `peN`): rl1 Rosa Quispe (Gerente General) · rl2 Luis Paredes (Gte. Adm. y Finanzas) · rl3 Carmen Huamán (Jefa RRHH) · rl4 Diego Salazar (Analista RRHH) · rl5 Milagros Torres (Analista de Nómina) · rl6 Jorge Ccori (Contador General) · rl7 Andrea Ríos (Analista Contable) · rl8 Fernando Castillo (Gte. Comercial) · rl9 Valeria Mendoza (Ventas, Arequipa) · rl10 Kevin Apaza (Ventas, Arequipa, plazo fijo) · rl11 Lucía Benites (Marketing) · rl12 Martín Gutiérrez (Gte. Tecnología) · rl13 Carlos Pérez (Desarrollador Senior, remoto) · rl14 Sofía Navarro (Desarrolladora Junior) · rl15 Raúl Chumpitaz (Jefe de Operaciones, e2) · rl16 Elena Yupanqui (Supervisora de Almacén, e2) · rl17 Pedro Mamani (Operario, e2) · rl18 Camila Restrepo (Ventas, Bogotá, e3, sueldo en COP) · rl19 Javier Ochoa (cesado).
- Campos de la relación: `id, personaId, codigo, empresaId, sedeId, puestoId, jefeId, ingreso, cese, motivoCese, estado('Activo'|'Cesado'), tipoTrabajador, modalidad, ccId, contratoTipo, contratoFin, prueba, jornada, salario`. Persona: `id, nombres, apellidos, tipoDoc, doc, nacimiento, nacionalidad, estadoCivil, direccion, telefono, correo, emergencia`.
- Usuarios demo: `us1` Luis (Administrador empresa) · `us2` Carmen (RRHH) · `us3` Milagros (Payroll) · `us4` Martín (Manager; su equipo: rl13 y rl14) · `us5` Sofía (Empleado) · `us6` Auditoría Externa (Auditor, sin ficha). Roles: `r_admin, r_rrhh, r_pay, r_mgr, r_emp, r_aud`; alcance `todos|equipo|propio`.

## Colecciones compartidas (contrato entre módulos)

Usa exactamente estos nombres y campos mínimos (puedes agregar más campos). Otros módulos (analítica, calendario, centro de aprobaciones) las leen con guardas `(T().x||[])`.

| Colección | Dueño | Campos mínimos |
|---|---|---|
| `marcaciones` | tiempo | `id, relId, fecha, entrada('08:58'), salida, metodo, tardanzaMin, heMin, estado` |
| `vacSolicitudes` | tiempo | `id, relId, desde, hasta, dias, estado('Pendiente'|'Aprobada'|'Rechazada'|'Anulada')` |
| `ausencias` | tiempo | `id, relId, tipo, desde, hasta, dias, estado` |
| `feriados` | tiempo | `id, fecha, nombre` |
| `nomPeriodos` | nomina | `id, periodo('2026-09'), empresaId, estado('Abierto'|'Calculado'|'Aprobado'|'Cerrado')` |
| `nomBoletas` | nomina | `id, periodoId, relId, ingresos, descuentos, aportes, neto, lineas:[{codigo,nombre,tipo,monto}]` |
| `vacantes` | talento | `id, titulo, puestoId, unidadId, estado('Borrador'|'Por aprobar'|'Publicada'|'Cerrada'), creada` |
| `candidatos` | talento | `id, vacanteId, nombre, etapa, fuente, creado` |
| `onbProcesos` | talento | `id, relId, tipo('onboarding'|'offboarding'), estado, tareas:[{t,hecho,resp,vence}]` |
| `posiciones` | talento | `id, puestoId, unidadId, estado('Aprobada'|'Ocupada'|'Vacante'|'Congelada'|'Eliminada'), relId` |
| `evalCiclos`, `evaluaciones` | desarrollo | evaluación: `id, cicloId, relId, estado, nota(1-5), potencial(1-3)` |
| `objetivos` | desarrollo | `id, nivel('Corporativo'|'Área'|'Individual'), relId?, unidadId?, titulo, avance(0-100), padreId?` |
| `cursos`, `inscripciones` | desarrollo | inscripción: `id, cursoId, relId, estado('No iniciado'|'En progreso'|'Completado'|'Vencido'), vence` |
| `encuestas`, `encRespuestas` | experiencia | encuesta: `id, titulo, tipo, anonima, estado`; respuesta: `id, encuestaId, relId|null, valores` |
| `tickets` | experiencia | `id, relId, categoria, asunto, estado, creado, venceSla` |
| `documentos` | experiencia | `id, relId, carpeta, nombre, estadoFirma('No requiere'|'Pendiente'|'Firmado'), fecha` |
| `activos` | operaciones | `id, tipo, nombre, serie, estado, relId` |
| `gastos` | operaciones | `id, relId, fecha, categoria, monto, moneda, estado('Borrador'|'Enviado'|'Aprobado'|'Rechazado'|'Pagado')` |
| `sstIncidentes` | operaciones | `id, fecha, tipo('Accidente'|'Incidente'|'Incidente peligroso'), gravedad, sedeId, relId?, estado` |
| `denuncias` | operaciones | `id, codigo, tipo, anonima, estado, creada` (acceso muy restringido) |

## Cómo probar tu módulo (obligatorio antes de entregar)

```bash
cd /home/claude/ayni-hr
python3 build.py out-MIMOD.html mod-N-MIMOD.js      # núcleo + solo tu módulo
node tmod.js out-MIMOD.html us2 /tmp/MIMOD           # recorre todas las rutas como Carmen (RRHH), en 1280 y 400 px
node tmod.js out-MIMOD.html us5 /tmp/MIMOD-emp       # como Sofía (Empleado)
node tmod.js out-MIMOD.html us4 /tmp/MIMOD-mgr       # como Martín (Manager)
```
`tmod.js` informa errores de JavaScript, vistas rotas y desborde horizontal; debe terminar en «ERRORES: ninguno». Además escribe tu propio script de Playwright (copia el patrón de `tmod.js`: `p.setContent(...)`, `executablePath:'/opt/pw-browsers/chromium'`) que ejecute los flujos principales de tu módulo de punta a punta (crear, aprobar, rechazar, cada formulario) y verifica con capturas (míralas con la herramienta Read) que el resultado se ve bien en escritorio y en 400 px. Corrige lo que encuentres. No uses `localStorage` para pasar estado entre pruebas (con `setContent` no está disponible; el núcleo lo tolera).

## Entrega

Escribe únicamente tu archivo `mod-N-nombre.js` (y scripts de prueba con prefijo `test-NOMBRE-`). Tu respuesta final debe listar: rutas y pantallas creadas, flujos probados, colecciones creadas, permisos añadidos, y cualquier limitación o simulación.
