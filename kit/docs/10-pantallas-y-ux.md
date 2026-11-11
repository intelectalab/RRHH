# 10 · Pantallas y UX

El prototipo (`core.html` y módulos) fija la navegación, la jerarquía visual y los flujos. Este documento fija el sistema con el que se reconstruyen en `apps/web`. Qué **no** copiar del prototipo: `15-que-no-copiar-del-prototipo.md`.

Idea rectora del diseño: barra lateral oscura fija + área de trabajo clara; **la ficha del colaborador es el centro del producto**. Identidad propia: no se copian interfaces, iconografía ni textos de otros productos.

## 1. Tokens

Tomados del `:root` de `core.html`. Van en `apps/web/src/ui/tokens.css` como variables CSS y se exponen a Tailwind como colores semánticos. **Ningún componente usa un color literal.**

### 1.1 Color

| Token | Claro | Oscuro | Uso |
|---|---|---|---|
| `--bg` | `#F3F5F9` | `#0D1513` | Fondo del área de trabajo |
| `--surface` | `#FFFFFF` | `#152120` | Paneles, tarjetas, modales, campos |
| `--surface2` | `#F6F8FB` | `#1A2826` | Fila al pasar el cursor, fondos secundarios, pista de barras |
| `--ink` | `#15202E` | `#E5EDEB` | Texto principal |
| `--muted` | `#5B6678` | `#93A4A0` | Texto secundario, etiquetas, cabeceras de tabla |
| `--line` | `#E1E6EE` | `#273532` | Bordes y separadores |
| `--accent` | `#0A3A73` | `#52BDB1` | Acción primaria, enlaces, foco, selección |
| `--accent-ink` | `#FFFFFF` | `#06211E` | Texto sobre `--accent` |
| `--accent-soft` | `#E6F0FB` | `#17342F` | Avatares, notas, estado «informativo» |
| `--side` | `#0A3A73` | `#0A1210` | Fondo de la barra lateral |
| `--side-fg` | `#D5E2DF` | `#D5E2DF` | Texto de la barra lateral |
| `--side-muted` | `#8AA09B` | `#7F958F` | Títulos de grupo en la barra lateral |
| `--side-active` | `#1D3B35` | `#18302B` | Elemento activo o con cursor en la barra lateral |
| `--ok` / `--ok-soft` | `#2B7A4B` / `#E0F1E6` | `#6CC58C` / `#173224` | Correcto, aprobado, al día |
| `--warn` / `--warn-soft` | `#9A5B0B` / `#FBEBD2` | `#E3A44B` / `#38290F` | Atención, por vencer, pendiente |
| `--crit` / `--crit-soft` | `#B3372F` / `#F8E1DF` | `#EE8179` / `#3B1B19` | Error, vencido, rechazado, acción destructiva |
| `--shadow` | `0 12px 40px rgba(16,35,31,.18)` | `0 12px 40px rgba(0,0,0,.5)` | Modales, menús, panel lateral |

El color nunca es el único portador de significado: todo estado lleva texto (y, donde ayuda, icono).

### 1.2 Tipografía

| Token | Valor | Uso |
|---|---|---|
| `--f-display` | `"IBM Plex Sans", system-ui, sans-serif` | Títulos, cifras de indicadores, marca |
| `--f-body` | `"IBM Plex Sans", system-ui, -apple-system, "Segoe UI", sans-serif` | Todo lo demás |
| `--f-mono` | `"IBM Plex Mono", ui-monospace, Menlo, monospace` | Códigos, identificadores, fórmulas |

Escala: cuerpo 14 px / 1,45 · `h1` 24 px / 700 · `h2` 17 px / 700 · `h3` 14 px / 700 · etiquetas y cabeceras de tabla 11 px en mayúsculas con espaciado `.06em` · cifra de indicador 28 px display · monoespaciada 12,5 px. Cifras en tablas con `font-variant-numeric: tabular-nums` y alineadas a la derecha. Las fuentes se sirven desde el propio dominio (no desde un tercero) en producción.

### 1.3 Forma y espacio

| Token | Valor |
|---|---|
| Radio de control (botón, campo) | 7 px |
| Radio de panel | 10 px · modal 12 px · píldora 99 px |
| Borde | 1 px `--line` |
| Espaciado base | múltiplos de 4 px; separación entre paneles 18 px; relleno de panel 16 px; celda de tabla 10 × 12 px |
| Barra lateral | 232 px (240 px como cajón en móvil) |
| Ancho máximo del contenido | 1180 px |
| Punto de quiebre principal | 820 px (debajo: barra lateral como cajón) |
| Foco | contorno 2 px `--accent`, separación 2 px |
| Movimiento | transiciones ≤ 200 ms; se desactivan con `prefers-reduced-motion` |

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

Referencia: `shell()` del prototipo. Dos zonas fijas y el contenido.

**Barra lateral** (fondo oscuro de la marca, 252 px):

1. Logotipo «A» y nombre del producto (o el de la marca blanca).
2. Tarjeta del usuario: avatar con iniciales, nombre, rol y punto de sesión activa.
3. «Buscar en el menú»: filtra entradas de todos los grupos; aparece cuando el menú tiene 12 entradas o más.
4. Sección «Menú»: entradas sueltas (Inicio, Calendario, Pendientes, Tareas) y grupos tipo acordeón con flecha. El grupo de la pantalla actual siempre está abierto; un grupo cerrado muestra la suma de sus contadores como insignia. La lista tiene desplazamiento propio.
5. Sección «Cuenta» al pie: Mi cuenta, Configuración (si el rol tiene permiso) y Salir.

En escritorio se contrae a una columna de iconos de 68 px con un botón; la preferencia se recuerda por navegador. Contraída, tocar el icono de un grupo la expande con ese grupo abierto.

**Cabecera** (60 px, una sola línea en cualquier ancho; nada salta de línea):

- Izquierda: botón de menú (solo cuando la barra lateral está oculta), miga pequeña «Organización · Rol» y título de la pantalla, truncado con elipsis.
- Derecha: buscador global con atajo «/», selector de empresa como chip, botones de módulo (por ejemplo Procesos), campana con contador que une pendientes y avisos y abre un panel con los últimos y los enlaces a Pendientes y Notificaciones, «Crear», y avatar con nombre y rol que abre el menú de usuario.

El título vive en la cabecera: la pantalla no lo repite. Bajo la cabecera, cada pantalla empieza por su subtítulo y sus acciones.

**Puntos de corte:**

| Ancho | Barra lateral | Cabecera |
|---|---|---|
| ≥ 1200 px | Completa (o contraída por el usuario) | Completa: buscador, chip de empresa, botones de módulo, campana, «Crear» con texto, avatar con nombre y rol |
| 1024–1199 px | Completa (o contraída) | Buscador como icono que abre una barra superpuesta; sin nombres; «Crear» como icono; empresa dentro del menú de usuario |
| 768–1023 px | Solo iconos; su botón la abre superpuesta | Igual que la anterior |
| < 768 px | Cajón con fondo oscurecido; cierra al tocar fuera, con Escape y al navegar | Menú + título + buscar + campana + avatar; «Crear» como botón flotante; botones de módulo dentro del menú de usuario |

Áreas táctiles de la cabecera y del menú: 40 px como mínimo. Los menús desplegables de la cabecera se anclan al borde de la ventana en celular para no salirse.

**Iconografía.** Juego propio de iconos de línea: lienzo de 24 × 24, trazo de 1.75, extremos y uniones redondeados, sin relleno, color heredado (`currentColor`). Un icono por grupo del menú y por entrada suelta; las entradas dentro de un grupo no llevan icono salvo que aporte significado. Nunca un icono sin texto o sin etiqueta accesible.

| Grupo | Icono | Grupo | Icono |
|---|---|---|---|
| Inicio | casa | Compensación | tendencia |
| Pendientes | bandeja | Operaciones | maletín |
| Tareas | casilla marcada | Experiencia | corazón |
| Calendario | calendario | SST | triángulo de alerta |
| Personas | personas | Cumplimiento | círculo con visto |
| Tiempo | reloj | Analytics | barras |
| Nómina | billetera | Automatización | rayo |
| Talento | diana | Configuración | controles |
| Aprendizaje | libro | Organización / Seguridad | edificio / escudo |

**Inicio.** Franja de saludo («Hola, Nombre», la fecha y cuántos pendientes hay, y una acción principal según el rol), bandeja, tarjetas de indicadores con icono y borde de color, y después alertas y paneles de cada módulo.

**Ingreso.** Dos columnas: panel de marca (mensaje, tres beneficios, degradado de la paleta; sin fotografías) y formulario (organización, perfil con icono, usuario, contraseña, botón Ingresar). Debajo, «Otros accesos»: consola de la plataforma y estudio contable. En celular las columnas se apilan y el formulario queda en una tarjeta. En el prototipo las credenciales vienen precargadas y rotuladas como demostración, y hay una fila «Entrar rápido como…» por organización que el producto no tendrá.

**Consola de la plataforma y cartera del estudio.** Mismo armazón sin barra lateral: cabecera de una línea con miga, título y Salir, y pestañas con desplazamiento horizontal.

## 4. Navegación por rol

El menú se arma con `GET /me` (permisos, módulos, alcance). Un grupo sin elementos visibles no se muestra.

| Rol | Entrada | Menú principal |
|---|---|---|
| Colaborador | Portal (móvil primero): saludo, saldo de vacaciones, próximo pago, pendientes | Inicio · Mi perfil · Mi horario y marcación · Vacaciones y permisos · Boletas · Documentos y firma · Objetivos · Cursos · Solicitudes · Gastos |
| Manager | Inicio de equipo: quién falta hoy, solicitudes, objetivos, evaluaciones | Lo del colaborador + Mi equipo · Aprobaciones · Calendario del equipo · Desempeño del equipo |
| RRHH | Inicio de RRHH: dotación, altas y bajas, ausencias hoy, contratos por vencer, alertas | Personas · Tiempo · Talento · Aprendizaje · Experiencia · Compensación · Operaciones · SST · Analítica · Automatización · Configuración |
| Nómina | Estado del periodo en curso y pendientes de cálculo | Nómina (Periodos, Incidencias, Cálculo, Boletas, Conceptos, Préstamos, Declaraciones, Asientos) · Personas (lectura) · Tiempo (cierres) |
| Reclutador | Vacantes abiertas y candidatos por etapa | Reclutamiento · Entrevistas · Ofertas · Base de talento |
| SST | Tablero SST | SST · Capacitaciones · EPP |
| Investigador de denuncias | Casos asignados | Solo el módulo de denuncias |
| Auditor | Solo lectura | Lo que su rol permita + Auditoría |
| Contador | Planilla y asientos en lectura | Nómina (lectura) · Asientos · Provisiones |
| Administrador | Como RRHH + Configuración completa | Todo lo contratado |
| Superadministrador SaaS | **Consola aparte** (otro dominio, otro armazón) | Organizaciones · Planes · Suscripciones · Módulos y flags · Soporte · Salud · Auditoría |

Estructura del menú (del pedido, ajustada): Inicio · Personas (Colaboradores, Directorio, Organigrama, Posiciones) · Tiempo · Nómina · Talento · Aprendizaje · Experiencia · Compensación · Operaciones · SST · Cumplimiento · Analítica · Automatización · Configuración.

- Selector de razón social en la barra superior cuando el usuario ve más de una: filtra todas las pantallas.
- Quien tiene ficha propia y además un rol de gestión alterna entre «Mi espacio» y «Gestión».
- Migas en toda pantalla interior.

### 4.1 Ayuda en todas las pantallas (AYU)

- Botón de ayuda (signo de interrogación) en la barra superior de toda pantalla y rol; en el celular está en el menú de usuario. Abre el centro de ayuda en «Esta pantalla», con los artículos de la clave de ayuda de la pantalla de origen.
- Pestañas del centro: **Esta pantalla**, **Guías** (buscador, novedades de versión y artículos por módulo, incluidas las políticas de la empresa), **Asistente** (solo si está habilitado para el rol) y **Mis consultas** (derivaciones y su estado).
- Cada artículo termina con «¿Te sirvió?» y, si corresponde, «Ir a la pantalla». Toda respuesta sin solución ofrece «Derivar a RRHH»; el administrador ve además «Escribir a soporte de GestiaHR».
- El asistente muestra siempre el aviso de la organización, cita sus fuentes con enlace y dice cuando no encontró un artículo. Nunca muestra datos de personas en su versión documental.
- Cada pantalla del menú declara su clave de ayuda; una prueba falla si falta (AYU-3).
- Administración en Configuración → Ayuda y asistente: encendido, roles, límite y aviso; artículos de la organización; consultas y búsquedas sin resultado.

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


> **Actualización v1.5:** la paleta base es azul marino y blanco (acento `#0A3A73`, detalle dorado `#F4B400` en la barra lateral). El tema claro es el predeterminado en cualquier equipo; el oscuro (tonos azul noche) solo se activa si el usuario lo elige en Mi cuenta. Los valores vigentes son los del bloque `:root` de `prototipo/fuentes/core.html`.
