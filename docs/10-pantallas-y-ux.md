# 10 · Pantallas y UX

El producto utiliza el diseño aprobado de la versión anterior (`assets/css/` e `includes/`). Este documento fija el sistema de diseño, tokens, componentes y navegación para su implementación en `apps/web`. Qué **no** copiar del prototipo: `15-que-no-copiar-del-prototipo.md`.

Idea rectora del diseño: **navegación horizontal superior de doble barra** (barra superior limpia de 56 px + barra de módulos en petróleo oscuro de 44 px) sin barra lateral fija, maximizando el espacio de trabajo para tablas de nómina, calendarios y matrices; **la ficha del colaborador es el centro del producto**.

## 1. Tokens

Definidos en `variables.css` (en `apps/web/src/ui/tokens.css` como variables CSS y mapeados a Tailwind). **Ningún componente usa un color literal.**

### 1.1 Color

#### Paleta de marca: Petróleo andino (principal) y Ocre andino (acento)

| Token | Claro | Oscuro (AA) | Uso |
|---|---|---|---|
| `--pe-800` | `#0B4350` | `#8BD0DD` | Estados activos profundos, énfasis máximo |
| `--pe-700` | `#0E4F5C` | `#74C1D0` | Fondo de la barra de módulos (`modbar`), hover de botones primarios |
| `--pe-600` | `#125F6E` | `#4AAABF` | Color primario de marca, botón primario, enlaces, acento interactivo |
| `--pe-500` | `#17788A` | `#2A93A7` | Foco de controles (`focus-visible`), bordes activos |
| `--pe-200` | `#B7D9E0` | `#125F6E` | Iconos secundarios, fondos ilustrativos |
| `--pe-100` | `#D5EAEE` | `#0E4F5C` | Selección de texto (`::selection`), etiquetas suaves |
| `--pe-50`  | `#EEF6F7` | `#0A2B33` | Fondo de tarjetas destacadas, hover de filas de tabla |
| `--oc`     | `#C98A1B` | `#E5A83B` | Acento puntual, indicador de módulo activo, badges de alerta |
| `--oc-600` | `#A9720F` | `#C98A1B` | Hover de acento ocre |
| `--oc-50`  | `#FBF1DC` | `#3D2806` | Fondo de insignias demo o advertencia suave |

#### Texto y superficies

| Token | Claro | Oscuro (AA) | Uso |
|---|---|---|---|
| `--tx`   | `#1C2630` | `#E6ECF1` | Texto principal (contraste > 14:1 en ambos temas) |
| `--tx2`  | `#55616E` | `#9DAEC0` | Texto secundario, subtítulos, etiquetas (contraste > 7:1) |
| `--tx3`  | `#8A96A3` | `#6E8094` | Texto terciario, estados vacíos, placeholders |
| `--bd`   | `#E1E6EB` | `#2E3C48` | Bordes principales de tarjetas, tablas y controles |
| `--bd2`  | `#EDF0F3` | `#25323D` | Separadores internos y bordes secundarios |
| `--bg`   | `#F4F6F8` | `#10171D` | Fondo del área de trabajo (lienzo general) |
| `--sup`  | `#FFFFFF` | `#18222A` | Superficie de tarjetas, modales, barra superior |
| `--sup2` | `#F8FAFB` | `#1F2B35` | Cabeceras de tabla, bandas alternadas, fondos secundarios |

#### Estados (siempre acompañados de texto o icono)

| Token | Claro | Oscuro (AA) | Uso |
|---|---|---|---|
| `--ok` / `--ok-50` | `#276B42` / `#E3F1E8` | `#48B876` / `#0E2B1A` | Éxito, aprobado, vigente, puntual |
| `--wa` / `--wa-50` | `#8E5510` / `#FBEEDC` | `#E5A245` / `#362006` | Advertencia, por vencer, pendiente de firma |
| `--er` / `--er-50` | `#A8321F` / `#FAE5E2` | `#F06550` / `#38120D` | Error, rechazado, cesado, acción destructiva |
| `--in` / `--in-50` | `#245B91` / `#E2EDF8` | `#5DA5E8` / `#0F243D` | Informativo, en proceso |

#### Gráficos y analítica

| Token | Claro | Oscuro (AA) | Uso |
|---|---|---|---|
| `--g1` / `--g1-wash` | `#00809A` / `rgba(0,128,154,.10)` | `#22A8C4` / `rgba(34,168,196,.15)` | Serie principal |
| `--g2` | `#C65A2E` | `#E27447` | Serie secundaria de contraste |
| `--g-gris` | `#9AA6B2` | `#6E8094` | Serie neutral o contexto |
| `--g-grid` | `#E6EAEE` | `#24323E` | Rejillas y guías de gráficos |

### 1.2 Tipografía

Familia tipográfica base:
```css
--fuente: "Nunito Sans", "Segoe UI", Arial, sans-serif;
```

Escala tipográfica:
- `--fs-xs`: `12px` (etiquetas, badges pequeños, notas al pie)
- `--fs-s`: `13px` (texto secundario, celdas de tabla, controles compactos)
- `--fs-m`: `14px` / `line-height: 20px` (cuerpo de texto general, inputs, botones)
- `--fs-l`: `15px` (títulos de tarjetas secundarias, nombres en listas)
- `--fs-h2`: `15px` / peso `800` (cabeceras de tarjetas y paneles)
- `--fs-h1`: `24px` / peso `800` (título principal de pantalla en encabezado)
- `--fs-cifra`: `28px` / `line-height: 34px` / peso `800` (cifras clave de KPIs)
- `--fs-heroe`: `48px` / `line-height: 52px` / peso `800` (cifras destacadas de impacto)

Cifras y tablas: siempre con `font-variant-numeric: tabular-nums` (`.num`) y alineadas a la derecha.

### 1.3 Forma, profundidad y movimiento

- **Radios de curvatura:**
  - `--r-s`: `3px` (etiquetas, badges, atajos de teclado `kbd`)
  - `--r`: `4px` (botones, inputs, selects, tarjetas compactas)
  - `--r-l`: `6px` (tarjetas principales, cabeceras de paneles)
  - `--r-xl`: `10px` (modales grandes, paneles flotantes)
- **Sombras:**
  - `--sombra-1`: `0 1px 2px rgba(28,38,48,.06)` (botones secundarios, elevación sutil)
  - `--sombra-2`: `0 6px 18px rgba(28,38,48,.12)` (desplegables, resultados de búsqueda, modales)
  - `--sombra-3`: `0 12px 32px rgba(28,38,48,.18)` (paneles laterales, avisos críticos)
- **Movimiento:**
  - `--ease-out`: `cubic-bezier(.23,1,.32,1)`
  - `--ease-panel`: `cubic-bezier(.32,.72,0,1)`
  - `--t-rapido`: `120ms` (hover, foco, active)
  - `--t-medio`: `180ms` (desplegables, transiciones de color)
  - `--t-panel`: `240ms` (paneles laterales, modales)
  - Desactivación con `@media (prefers-reduced-motion: reduce)`.

## 2. Componentes base (`src/ui`)

| Componente | Notas de comportamiento |
|---|---|
| `AppShell` | Barra lateral + barra superior + contenido. Variantes: aplicación, portal del colaborador, consola de plataforma, público |
| `PageHeader` | Migas, título, subtítulo, acciones (máximo una primaria) |
| `Button` | Variantes: primario, secundario, peligro, fantasma; tamaño normal y pequeño; estado de carga que impide doble envío |
| `Field`, `Input`, `Select`, `Combobox`, `DatePicker`, `MoneyInput`, `Textarea`, `Checkbox`, `Switch`, `FileDrop` | Etiqueta siempre visible; ayuda y error bajo el campo; `MoneyInput` muestra moneda y no permite cambiarla si la fija el contexto |
| `EffectiveDateField` | Campo «vigente desde» presente en todo formulario que cambia un dato con historia; por defecto hoy; avisa si la fecha cae en un periodo de planilla cerrado |
| `Panel` | Cabecera + cuerpo; contenedor estándar |
| `KpiStrip`, `Kpi` | Cifra, etiqueta, variación; al pulsar abre el listado filtrado |
| `Pill` | Estado: neutro, `ok`, `warn`, `crit`, `acc`. El texto viene de i18n por valor de enumeración |
| `Avatar`, `PersonCell` | Iniciales o foto; nombre + puesto; enlaza a la ficha |
| `Tabs` | Desplazables en horizontal en móvil; pestaña en la URL |
| `DataTable` | Ver §3.1 |
| `Drawer` | Panel lateral; ver §3.2 |
| `Modal`, `ConfirmDialog` | Ver §3.3 |
| `Timeline` | Ver §3.4 |
| `Kanban` | Ver §3.5 |
| `Calendar` | Ver §3.6 |
| `Tree`, `OrgChart` | Árbol de unidades; organigrama por posiciones con carga por niveles |
| `DescriptionList` | Ficha de datos (`dt`/`dd`) en rejilla adaptable |
| `ProgressBar`, `Stepper` | Avance de procesos; pasos de asistentes |
| `AlertItem` | Título + **explicación obligatoria** + acción sugerida |
| `EmptyState` | Ver §6 |
| `Skeleton` | Para carga inicial de tablas, fichas y tableros |
| `Toast` | Confirmaciones breves; los errores que requieren acción no van en toast |
| `Menu`, `Popover`, `Tooltip` | Accesibles por teclado |
| `Chart` | Barras, líneas, dona, mapa de calor; colores por tokens; tabla de datos alternativa |
| `DocumentPreview` | Vista previa de PDF e imágenes con descarga según permiso |
| `SensitiveValue` | Muestra un valor sensible con «ver» explícito; al revelar se registra el acceso |
| `StatusActions` | Pinta los botones de `meta.available_actions` de la API |

## 3. Patrones

### 3.1 Tabla avanzada

Referencia: Colaboradores en el prototipo.

- Búsqueda, filtros, orden, selección de columnas, redimensionado, paginación, acciones masivas, exportación y **guardar vista**.
- Todo en servidor: la tabla envía `q`, `filter[...]`, `sort`, `page`, `per_page`. El estado vive en la URL.
- Filtros visibles: los 3 o 4 más usados; el resto tras «Más filtros». Filtros activos como fichas removibles.
- Columnas por defecto pocas; el selector añade más. Las columnas sensibles (sueldo) solo aparecen en el selector si el usuario tiene el permiso por campo.
- Primera columna fija con la identidad del registro; fila completa pulsable hacia el detalle.
- Acciones masivas aparecen al seleccionar; muestran cuántos registros afectan y piden confirmación; el resultado informa éxitos y errores por fila.
- Exportación asíncrona con los filtros actuales y solo las columnas permitidas.
- Móvil: desplazamiento horizontal dentro de la tabla con primera columna fija, o lista de tarjetas en pantallas del portal del colaborador.
- Estados: skeleton, vacío sin datos (acción de crear), vacío por filtros (acción de limpiar), error (reintentar).

### 3.2 Panel lateral

- Para **ver o editar sin perder el contexto** de la lista: detalle rápido, formularios cortos, aprobación con comentario.
- Entra por la derecha; ancho 480–640 px; en móvil ocupa toda la pantalla.
- Tiene URL propia (parámetro), se cierra con Esc, devuelve el foco al elemento que lo abrió.
- Si el contenido crece (más de dos secciones o pestañas), es una página, no un panel.

### 3.3 Modal

- Para **decisiones y formularios breves** que bloquean: confirmar, crear con pocos campos, vista previa de documento.
- Una acción primaria; destructivas en estilo de peligro y con el nombre de la acción («Cesar colaborador», no «Aceptar»).
- Las confirmaciones dicen la consecuencia concreta («Se descontarán 5 días del saldo de Sofía Navarro»).
- Foco atrapado, Esc cierra, no se cierra al pulsar fuera si hay cambios sin guardar.
- Nunca un modal sobre otro.

### 3.4 Línea de tiempo

- Historia laboral (asignaciones, compensaciones, contratos, eventos) en orden descendente.
- Cada entrada: fecha de **vigencia**, qué cambió (antes → después), motivo, quién y cuándo lo **registró**.
- Los cambios futuros se muestran arriba, marcados como «programado», con opción de anular.
- Las correcciones muestran la entrada reemplazada tachada y enlazada.
- Las entradas sensibles (sueldo) aparecen solo con permiso; sin permiso, la entrada no existe en la lista.
- Filtro por tipo de cambio.

### 3.5 Kanban

- Reclutamiento (etapas), tareas, tickets, acciones correctivas.
- Arrastrar ejecuta la **acción** de la API (`move-stage`); si falla, la tarjeta vuelve y se explica por qué.
- Alternativa accesible obligatoria: menú «Mover a…» en cada tarjeta.
- Columnas con contador; carga paginada por columna; desplazamiento horizontal contenido en móvil.

### 3.6 Calendario

- Vistas mes, semana y lista. Capas activables: vacaciones, ausencias, cumpleaños, aniversarios, contratos por vencer, capacitaciones, evaluaciones, entrevistas, feriados.
- Programación de turnos: rejilla persona × día con arrastre y asignación masiva; publicar es una acción aparte.
- El motivo de una ausencia sensible (descanso médico) se muestra como «Ausente» a quien no tiene permiso.
- En móvil, la vista por defecto es lista.

### 3.7 Bandeja de pendientes

- Una sola bandeja (`GET /inbox`) con todo lo que espera al usuario: aprobaciones, firmas, evaluaciones, cursos, tareas, rendiciones.
- Agrupada por tipo con contador; cada elemento permite resolver ahí mismo (aprobar o rechazar con comentario) o abrir el detalle.
- Acciones masivas solo donde es seguro (vacaciones sin conflicto); nunca para aprobaciones con montos distintos sin revisión.
- El contador de la barra superior y del menú sale de la misma fuente.
- Cada elemento explica por qué está ahí («Eres jefe directo de Sofía»).

### 3.8 Creación rápida

- Botón «+ Crear» en la barra superior: colaborador, vacante, solicitud, documento, curso, encuesta, tarea… según permisos y módulos.
- Abre el formulario mínimo (campos obligatorios) en modal o panel; «Guardar y completar» lleva a la ficha.

### 3.9 Buscador global

- Atajo de teclado (`Ctrl/⌘ K`) y campo en la barra superior.
- Resultados agrupados por tipo (personas, documentos, vacantes, candidatos, cursos, solicitudes, reportes, artículos) + **acciones** («Crear vacante», «Ir a Parámetros de planilla»).
- Respeta permisos y alcance en servidor; nunca muestra un resultado que el usuario no podría abrir.
- Navegable por teclado; recuerda búsquedas recientes del usuario (en el dispositivo).

### 3.10 Formularios

- Una columna en móvil, dos en escritorio para campos cortos.
- Validación al salir del campo y al enviar; errores del servidor asignados al campo.
- Aviso de cambios sin guardar al salir.
- Asistentes por pasos para procesos largos (alta de colaborador, importación, periodo de planilla); cada paso guarda borrador.
- Los formularios que crean cambios con historia incluyen «vigente desde» y «motivo».

### 3.11 Asistentes de proceso

Planilla (periodo → incidencias → cálculo → revisión → aprobación → cierre → boletas → pago → asiento), importación (subir → mapear → validar → previsualizar → importar) y puesta en marcha. Muestran el paso actual, lo que falta y lo que bloquea; nunca permiten saltar un paso obligatorio.

### 3.12 Armazón de la aplicación

Referencia: estructura aprobada en `cabecera.php`, `modulos.php` y `base.css`. Dos franjas horizontales superiores fijas y el área de trabajo completa: **sin barra lateral**.

#### 1. Barra superior (`topbar`, altura 56 px)

Fondo blanco superficie (`--sup`), borde inferior `--bd` (`1px solid #E1E6EB`) y contenido centrado con ancho máximo de 1360 px:

1. **Logotipo y marca:** Isotipo SVG oficial de AyniHR y texto («**Ayni**» en negrita + «HR» en petróleo `--pe-600`), enlazando a Inicio. En entornos de prueba o demostración, muestra la insignia «DEMO» en ocre suave (`--oc-50`).
2. **Selector de razón social / empresa activa (`empsel`):** Botón con el logotipo o icono de la empresa, nombre comercial en negrita y RUC / número de sedes en subtítulo. Desplegable que permite alternar la razón social activa o seleccionar «Todas las empresas» para usuarios con alcance consolidado (grupo empresarial).
3. **Buscador global (`gsearch`):** Campo de búsqueda rápida con icono de lupa Phosphor, atajo de teclado accesible con la tecla `/` (`<kbd>/</kbd>`) y panel flotante de resultados inmediatos (`res`) con navegación por teclado y categorización de resultados.
4. **Acción rápida «+ Nuevo»:** Botón primario compacto (`btn-primary btn-sm`) con menú desplegable para las creaciones más frecuentes del día a día: Colaborador (`colaborador_form`), Solicitud de vacaciones o permiso (panel lateral rápido) y Marcación manual (`asistencia`). Visible según permisos (`personal.editar`).
5. **Ayuda contextual:** Botón de icono (`ibtn`) con icono Phosphor `question` que abre el panel lateral deslizable de ayuda (`panel=ayuda`) correspondiente a la pantalla en uso.
6. **Centro de notificaciones:** Botón `ibtn` con icono `bell` y badge circular en ocre (`cnt`) con el conteo de avisos sin leer. Desplegable con lista de notificaciones, indicador de novedad (punto ocre) y acción directa «Marcar todo como leído».
7. **Menú de cuenta y usuario (`userbtn`):** Avatar circular (`av`) con fotografía o iniciales con contraste, nombre de usuario en negrita y rol asignado en subtítulo. Desplegable con acceso a «Mi ficha», «Cambiar contraseña», divisor de menú y «Cerrar sesión».

#### 2. Barra horizontal de módulos (`modbar`, altura 44 px)

Ubicada inmediatamente debajo de la barra superior, con fondo petróleo oscuro (`--pe-700`: `#0E4F5C`):

- **Navegación horizontal fluida:** Enlaces dispuestos horizontalmente con desplazamiento táctil o con rueda de ratón sin barra de desplazamiento visible (`scrollbar-width: none`).
- **Elementos de módulo:** Cada enlace incluye su icono temático de Phosphor (18 px), etiqueta descriptiva en texto bold (`#D3E4E8`) y badge de contador (`cnt-mod`) en ocre andino (`--oc`) cuando existen tareas o solicitudes pendientes de atención directa.
- **Módulo activo (`.on`):** Color blanco puro (`#fff`), fondo sutil (`rgba(255,255,255,.05)`) y línea inferior de acento de 3 px en ocre andino (`--oc`: `#C98A1B`).
- **Foco accesible:** Contorno visible blanco (`:focus-visible`) que garantiza navegación fluida mediante tabulador.

#### 3. Encabezado de página (`phead`) y contenido (`main`)

- **Encabezado (`phead`):** Fondo blanco (`--sup`), borde inferior `--bd`, migas de pan (`breadcrumb`), título principal de la pantalla (`h1`, 24 px / 800) y franja de acciones contextuales (filtros rápidos, selector de vistas, botones de acción primaria y secundaria).
- **Área principal (`main`):** Ancho máximo de 1360 px centrado (`margin: 0 auto; padding: 24px`), aprovechando el 100 % del ancho útil de la pantalla para renderizar tablas densas de nómina, calendarios de turnos, organigramas y fichas 360 sin estrechez visual.

#### Puntos de quiebre y adaptación responsiva

| Ancho | Barra superior | Barra de módulos |
|---|---|---|
| ≥ 1200 px | Completa: logo, selector de empresa, buscador con `/`, botón «+ Nuevo», ayuda, notificaciones y menú de usuario | Completa con todas las etiquetas e iconos visibles |
| 900–1199 px | Buscador compacto; selector de empresa abreviado | Desplazamiento horizontal con desborde suave |
| 600–899 px | Buscador como icono que expande barra superpuesta; botón «+ Nuevo» solo icono | Desplazamiento horizontal táctil |
| < 600 px (móvil) | Logo + campana + usuario; buscador integrado en menú; en el portal del colaborador se complementa con barra de navegación inferior móvil primero | Barra horizontal deslizable compacta |

## 4. Navegación por rol

La barra de módulos se construye dinámicamente con la respuesta de `GET /me` (permisos, módulos activos en la organización y alcance).

Si un usuario no cuenta con privilegios administrativos sobre un módulo pero el módulo ofrece autoservicio para el colaborador, la barra de módulos enlaza de forma transparente a la vista de autoservicio correspondiente:

| Módulo comercial | Clave | Icono Phosphor | Vista de gestión (con permiso) | Vista de autoservicio (sin permiso) |
|---|---|---|---|---|
| Inicio | `inicio` | `house` | Inicio / Tablero principal | Inicio / Portal del colaborador |
| Core HR | `personal` | `users` | Personal (Colaboradores, Directorio) | Mi ficha (`mi_ficha`) |
| Asistencia | `asistencia` | `clock` | Asistencia y turnos | Mis marcaciones (`mis_marcaciones`) |
| Vacaciones | `vacaciones` | `calendar-blank` | Gestión de vacaciones y ausencias | Mis vacaciones (`mis_vacaciones`) |
| Aprobaciones | `aprobaciones` | `checks` | Bandeja de aprobaciones (con badge) | Solo visible si tiene subordinados o solicitudes por aprobar |
| Organización | `organizacion` | `buildings` | Empresas, sedes, unidades, puestos | Solo visible con permiso de organización |
| Configuración | `configuracion` | `gear` | Centro de configuración, roles, auditoría | Solo visible con permiso de configuración o auditoría |

### Navegación según el rol del usuario

| Rol | Experiencia principal | Módulos disponibles en la barra superior |
|---|---|---|
| **Colaborador** | Portal del colaborador (móvil primero) | Inicio · Mi ficha · Mis marcaciones · Mis vacaciones |
| **Manager / Jefe** | Portal con gestión de equipo | Inicio · Mi ficha · Mi equipo · Mis marcaciones · Mis vacaciones · Aprobaciones (badge) |
| **RRHH / Personal** | Gestión integral de personas | Inicio · Personal · Asistencia · Vacaciones · Aprobaciones · Organización · Configuración |
| **Analista de Nómina** | Gestión y cálculo de planilla | Inicio · Personal (consulta) · Asistencia (cierres) · Vacaciones · Nómina · Aprobaciones |
| **Seguridad y Salud (SST)** | Tablero de prevención y EPP | Inicio · Personal (consulta) · SST · Capacitaciones |
| **Investigador de Denuncias** | Canal reservado e independiente | Canal de denuncias (acceso reservado) · Auditoría de casos |
| **Auditor** | Fiscalización y trazabilidad | Personal (lectura) · Asistencia (lectura) · Vacaciones (lectura) · Auditoría completa |
| **Administrador de Organización** | Control total de la empresa | Todos los módulos contratados + Organización + Configuración global |
| **Superadministrador SaaS** | **Consola SaaS independiente** (`(plataforma)`) | Organizaciones · Planes · Módulos y flags · Suscripciones · Auditoría global de plataforma |

Estructura del menú (del pedido, ajustada): Inicio · Personas (Colaboradores, Directorio, Organigrama, Posiciones) · Tiempo · Nómina · Talento · Aprendizaje · Experiencia · Compensación · Operaciones · SST · Cumplimiento · Analítica · Automatización · Configuración.

- Selector de razón social en la barra superior cuando el usuario ve más de una: filtra todas las pantallas.
- Quien tiene ficha propia y además un rol de gestión alterna entre «Mi espacio» y «Gestión».
- Migas en toda pantalla interior.

## 5. Divulgación progresiva

1. **Primero lo frecuente.** Cada pantalla abre con lo que se hace a diario; la configuración va en un segundo nivel.
2. **Valores por defecto peruanos** al crear una organización (plantillas de conceptos, contratos, tipos de ausencia): se puede trabajar sin configurar nada.
3. **Opciones avanzadas plegadas** («Opciones avanzadas») en formularios; se recuerda si el usuario las abrió.
4. **Configuración junto a su módulo** (engranaje en la cabecera de la sección) y, además, reunida en el Centro de configuración.
5. **La ficha 360 muestra solo las pestañas** de módulos contratados y con permiso; el resumen enlaza al detalle.
6. **Tableros con pocos indicadores** y enlace al detalle; widgets adicionales bajo demanda.
7. **Lenguaje llano**: «Vigente desde», «Jefe directo», «Boleta»; sin siglas internas ni inglés salvo términos habituales (OKR, eNPS, 9-box).

## 6. Estados vacíos

Nunca «No hay registros». Cada estado vacío tiene título, una frase de qué es y para qué sirve, y la acción principal.

| Caso | Contenido |
|---|---|
| Primera vez (sin datos) | Qué se hace aquí + botón de crear + enlace a la plantilla o al importador. Ej.: «Aún no tienes procesos de onboarding. Crea una plantilla para que cada ingreso reciba sus tareas.» [Crear plantilla] |
| Sin resultados por filtro | «Ningún colaborador coincide con estos filtros.» [Limpiar filtros] |
| Sin permiso | «No tienes acceso a esta sección.» + a quién pedirlo |
| Módulo no contratado | Qué hace el módulo + «Pide a tu administrador que lo active» (sin precios) |
| Todo al día | Estado positivo: «No tienes pendientes.» |
| Error | Qué falló en lenguaje llano + [Reintentar] + identificador de la solicitud para soporte |

## 7. Móvil

- **Responsive real desde 360 px**; se verifica en 390 px en cada pantalla. Sin desplazamiento horizontal de página.
- El portal del colaborador es móvil primero: barra inferior con 4 o 5 destinos (Inicio, Marcar, Vacaciones, Boletas, Más); acciones al alcance del pulgar; áreas táctiles ≥ 44 px.
- Tareas que deben resolverse en móvil en pocos toques: marcar asistencia, solicitar vacaciones, ver boleta, firmar, responder encuesta, ver horario, crear solicitud, aprobar (manager).
- Aplicación web instalable (PWA) con marcación sin conexión: la marcación se guarda en el dispositivo con `client_id` e instante, se sincroniza al reconectar y se muestra como «pendiente de sincronizar». App nativa: decisión A-12.
- Cámara para selfie y comprobantes; geolocalización solo al marcar y con aviso.
- Tablas de gestión en móvil: columnas esenciales + detalle en panel.
- Se respetan las áreas seguras del dispositivo.

## 8. Modo claro y oscuro

- Ambos temas son de primera clase: cada token tiene su valor oscuro propio (§1.1); no se invierten colores.
- Por defecto sigue al sistema (`prefers-color-scheme`); el usuario puede fijarlo (`data-theme="light|dark"` en `:root`), preferencia guardada en su perfil.
- En oscuro, la elevación se expresa con superficie más clara y borde, no con sombra.
- Gráficos, estados y documentos embebidos se verifican en ambos temas. Las vistas previas de PDF conservan fondo blanco.
- La marca blanca cambia `--accent` y sus derivados; el sistema calcula `--accent-ink` y `--accent-soft` y **rechaza** colores que no alcancen contraste AA.

## 9. Accesibilidad

Objetivo: WCAG 2.2 nivel AA.

- Contraste mínimo 4,5:1 en texto y 3:1 en controles y gráficos, en ambos temas.
- Todo se opera con teclado; orden de foco lógico; foco siempre visible; enlace «Saltar al contenido».
- HTML semántico: `button`, `a`, `table` con `th` y `scope`, `label` asociado a cada campo, regiones (`nav`, `main`).
- Modales y paneles con foco atrapado, `aria-modal`, cierre con Esc y retorno del foco.
- Arrastrar y soltar siempre con alternativa por menú o teclado.
- Mensajes de error asociados al campo (`aria-describedby`) y resumen al enviar; avisos dinámicos con región viva.
- Gráficos con texto alternativo y tabla de datos equivalente.
- No depender del color; iconos con nombre accesible; imágenes decorativas ocultas a lectores.
- Respetar `prefers-reduced-motion`; nada parpadea.
- Zoom al 200 % sin pérdida de función; texto en unidades relativas.
- Tiempo de sesión: aviso antes de cerrar y opción de extender.
- Se prueba con verificación automática en cada pantalla (pruebas de extremo a extremo) y con lector de pantalla en los recorridos principales.

## 10. Inventario de pantallas base (del núcleo del prototipo)

| Pantalla | Ruta sugerida | Patrón |
|---|---|---|
| Acceso (correo o documento, OTP, MFA) | `/ingresar` | Formulario centrado |
| Inicio por rol | `/inicio` | Indicadores + alertas explicadas + pendientes |
| Colaboradores | `/colaboradores` | Tabla avanzada |
| Ficha 360 | `/colaboradores/[id]` | Cabecera de persona + pestañas + línea de tiempo |
| Directorio | `/directorio` | Rejilla de tarjetas con búsqueda (sin datos sensibles) |
| Organigrama | `/organigrama` | Árbol por posiciones con vacantes |
| Estructura (empresas, sedes, unidades, puestos, centros de costo) | `/configuracion/estructura` | Pestañas + tabla + formulario en modal; archivar y restaurar |
| Usuarios | `/configuracion/usuarios` | Tabla + invitación |
| Roles y permisos | `/configuracion/roles` | Matriz de permisos por grupo + permisos por campo + alcance |
| Auditoría | `/auditoria` | Tabla por cursor con filtros; detalle antes → después |
| Consola SaaS | dominio aparte | Organizaciones, planes, módulos, salud |

Las pantallas de cada módulo están descritas en `modulos/`.


> **Identidad visual:** La identidad oficial aprobada se basa en la paleta petróleo andino (`--pe-600: #125F6E`) y ocre andino (`--oc: #C98A1B`), tipografía Nunito Sans / Segoe UI, y el sistema de navegación de doble barra horizontal superior (56 px + 44 px) sin barra lateral, según lo especificado en la sección 1 y en el [ADR 0012](adr/0012-UI-diseno-sistema-visual-y-navegacion.md).
