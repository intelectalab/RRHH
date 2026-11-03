# M-UI · Sistema de diseño y armazón

Fase 0 · Cimientos · Épica `UI` · Reglas `RN-UI-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Lograr que una plataforma muy amplia se sienta sencilla y propia: una sola forma de navegar, listar, filtrar, editar y aprobar en todos los módulos, en escritorio y en móvil, en tema claro y oscuro.

## 2. Alcance

- Armazón: barra lateral por grupos, barra superior (empresa, búsqueda, crear, pendientes, notificaciones, usuario), migas, paleta de comandos.
- Componentes: tarjetas, tablas avanzadas (buscar, filtrar, ordenar, elegir y ocultar columnas, redimensionar, paginar, acciones masivas, exportar, guardar vista), paneles laterales, modales, pestañas, líneas de tiempo, calendarios, kanban, tableros, gráficos, filtros, estados vacíos útiles, esqueletos de carga, ayudas.
- Inicios por rol (RRHH, Manager, Empleado, Payroll), tableros con widgets reordenables.
- Botón «+ Crear» y menú según permisos y módulos contratados.
- Divulgación progresiva: lo esencial primero, lo avanzado en un segundo nivel.
- Tema claro y oscuro diseñados por separado; responsive real; accesibilidad; validaciones con mensajes claros (el servidor repite toda validación).
- Identidad visual propia (sin copiar interfaces, textos ni iconografía de terceros).

## 3. Fuera de alcance

- Marca blanca por organización (M-WLB). Contenido de cada pantalla (su épica).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §65 Dashboards personalizables; §70 Búsqueda global; §80 Multiidioma; §84 Modo claro / oscuro; §85 Experiencia móvil; §87 Bandeja «Pendientes»; §88 Comando / creación rápida; §96 Diseño del sistema; §97 No sobrecargar la interfaz; §98 Tablas profesionales; §99 Estados vacíos; §100 Validaciones; §105 UX de información sensible; §106 Home RRHH; §107 Home manager; §108 Home empleado; §114 No crear botones falsos; §115 Persistencia; §119 Menú propuesto.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Todos los usuarios. Cada usuario personaliza sus vistas guardadas y su tablero.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `settings.saved_views.view` | Ver vistas guardadas de tabla | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Investigador de denuncias, Contador |
| `settings.saved_views.create` | Crear vistas guardadas de tabla | solo sobre lo propio o asignado: todos los roles de organización |
| `settings.saved_views.delete` | Eliminar vistas guardadas de tabla | solo sobre lo propio o asignado: todos los roles de organización |
| `settings.saved_views.update` | Editar vistas guardadas de tabla | solo sobre lo propio o asignado: todos los roles de organización |
| `settings.saved_views.share` **(+)** | Compartir vistas con un rol o con toda la organización | Administrador empresa, RRHH |
| `settings.home_layouts.update_own` **(+)** | Reordenar y elegir los widgets de su inicio | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Contador |
| `settings.home_layouts.update_default` **(+)** | Definir el tablero por defecto de cada rol | Administrador empresa |
| `analytics.dashboards.view` | Ver tableros | Administrador empresa, RRHH, Payroll, Manager, Auditor, SST, Contador |
| `analytics.dashboards.create` | Crear tableros | Administrador empresa, RRHH |
| `analytics.dashboards.delete` | Eliminar tableros | Administrador empresa, RRHH |
| `analytics.dashboards.update` | Editar tableros | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Inicio por rol.** 1) Al entrar, el sistema elige el inicio según el rol principal. 2) Muestra saludo, pendientes y widgets permitidos. 3) El usuario reordena o cambia widgets; se guarda por usuario.

**Tabla con vista guardada.** 1) El usuario filtra, ordena y elige columnas. 2) Guarda la vista con nombre. 3) La vista vuelve a aplicar permisos al abrirse: si perdió acceso a una columna, desaparece.

**Creación rápida.** 1) «+ Crear» lista solo lo que el usuario puede crear en los módulos contratados. 2) Abre el formulario en panel lateral. 3) Al guardar, ofrece ir al registro.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Armazón | Barra lateral, barra superior, migas, selector de empresa, tema claro u oscuro | Prototipo: armazón común a todas las pantallas |
| Inicio RRHH | Dotación, ingresos, salidas, ausentes hoy, vacaciones, contratos por vencer, cumpleaños, onboarding, vacantes, pendientes, alertas, rotación, costo laboral (con permiso) | Prototipo: Inicio |
| Inicio Manager | Mi equipo, quién está ausente, solicitudes, objetivos, evaluaciones, cumpleaños, pendientes | Prototipo: Inicio |
| Inicio Empleado | Saludo, vacaciones disponibles, próximo pago, pendientes; accesos a perfil, horario, documentos, boletas, objetivos, cursos, solicitudes | Prototipo: Inicio |
| Paleta de comandos | Buscar e ir a pantallas o registros, crear | Prototipo: Analytics → Asistente → Buscar en todo (y buscador de la barra superior) |

## 8. Datos principales

Vistas guardadas, preferencias de usuario (tema, idioma, densidad), disposición de tableros por usuario y por rol.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección UI.

- **RN-UI-01**: Si un control visible (crear, editar, eliminar, aprobar, rechazar, descargar, exportar, filtrar, buscar, firmar, duplicar, restaurar) no tiene una operación real detrás → no se publica la pantalla.
- **RN-UI-02**: Si el usuario no tiene el permiso o el módulo no está contratado → el control no se muestra y el servidor rechaza la llamada igualmente.
- **RN-UI-03**: Si una lista está vacía → se muestra un estado vacío con explicación y la acción para crear el primer registro (si el usuario puede).
- **RN-UI-04**: Si una acción cambia datos → al terminar, todas las vistas afectadas (saldo, calendario, tablero, pendientes) reflejan el cambio sin recargar la sesión.
- **RN-UI-05**: Si un formulario falla en servidor → cada error se muestra junto a su campo con un mensaje en lenguaje claro.
- **RN-UI-06**: Si la pantalla mide menos que el ancho móvil de referencia → las tablas se vuelven tarjetas o se desplazan dentro de su contenedor; nunca se desborda la página.
- **RN-UI-07**: Si un texto visible no proviene de los archivos de traducción → la revisión de calidad lo marca como defecto.
- **RN-UI-08** (parámetro): Si una acción masiva afecta a más registros que el umbral seguro (parámetro) o a datos sensibles → pide confirmación con resumen y se ejecuta en segundo plano.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

Ninguno propio.

**Consume:**

Ninguno.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Usuario con dos roles (Manager y Empleado): el inicio combina widgets y permite cambiar de vista.
- Vista guardada compartida que incluye una columna sensible: quien no tenga el permiso la ve sin esa columna.
- Tema oscuro: gráficos y estados usan variables, no colores fijos.

## 14. Criterios de aceptación generales

- Todas las tablas del producto ofrecen el mismo conjunto de capacidades.
- Recorrido completo en móvil: marcar asistencia, pedir vacaciones, ver boletas, firmar, responder encuestas, ver horario y crear solicitudes.
- Sin textos críticos fijos en componentes; es, en y pt conmutables.
