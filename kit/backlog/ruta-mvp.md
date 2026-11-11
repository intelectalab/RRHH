# Ruta de arranque del MVP

Generado por `kit/tools/gen_ruta.py` desde `backlog.json`. No se edita a mano.

Cada **tanda** reúne actividades cuyas dependencias ya están en tandas anteriores: dentro de una tanda se pueden trabajar en paralelo y en cualquier orden. No hay tiempos ni responsables: el equipo los asigna al planificar. Para implementar una actividad con Claude Code: `/historia <clave>`.

Resumen: 241 actividades del MVP, 9 de la segunda ola y 13 del sitio comercial.

## 1. Primer corte comercial (MVP, etiqueta `mvp`)

Lo mínimo para vender: plataforma, seguridad, organizaciones y planes, ficha del colaborador, vacaciones y planilla peruana.

### Tanda 1

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `PLT-1` | Monorepo y estructura de aplicaciones | PLT · Plataforma técnica | Tarea | — |

### Tanda 2

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `PLT-2` | Entorno local con Docker | PLT · Plataforma técnica | Tarea | PLT-1 |
| `PLT-3` | Integración continua | PLT · Plataforma técnica | Tarea | PLT-1 |
| `PLT-4` | Formato único de errores y validación en servidor | PLT · Plataforma técnica | Tarea | PLT-1 |
| `PLT-12` | Convenciones del modelo de datos y migraciones | PLT · Plataforma técnica | Tarea | PLT-1 |
| `PLT-14` | Guía de trabajo del repositorio | PLT · Plataforma técnica | Tarea | PLT-1 |
| `UI-1` | Fundamentos visuales y temas claro y oscuro | UI · Sistema de diseño y armazón | Tarea | PLT-1 |

### Tanda 3

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `CFG-6` | Internacionalización | CFG · Configuración, campos personalizados, i18n y multimoneda | Tarea | UI-1, PLT-4 |
| `HIS-1` | Patrón de vigencia reutilizable | HIS · Historia con vigencia | Tarea | PLT-12 |
| `PLT-5` | Contratos OpenAPI y tipos compartidos | PLT · Plataforma técnica | Tarea | PLT-1, PLT-4 |
| `PLT-6` | Colas, trabajos programados y outbox de eventos de dominio | PLT · Plataforma técnica | Tarea | PLT-1, PLT-2 |
| `PLT-7` | Servicio de archivos | PLT · Plataforma técnica | Tarea | PLT-1, PLT-2 |
| `PLT-8` | Idempotencia y acciones de estado | PLT · Plataforma técnica | Tarea | PLT-4 |
| `PLT-9` | Entornos y despliegue | PLT · Plataforma técnica | Tarea | PLT-3 |
| `QA-1` | Estrategia de pruebas y definición de hecho | QA · Calidad, seguridad y salida a producción | Tarea | PLT-3, PLT-14 |
| `TEN-1` | Contexto de organización en cada petición | TEN · Multiempresa y aislamiento | Tarea | PLT-1, PLT-12 |
| `UI-3` | Biblioteca de componentes | UI · Sistema de diseño y armazón | Tarea | UI-1 |

### Tanda 4

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `IMP-1` | Motor de importación | IMP · Importador y puesta en marcha | Tarea | PLT-6, PLT-7 |
| `PLT-10` | Observabilidad | PLT · Plataforma técnica | Tarea | PLT-9 |
| `PLT-11` | Rendimiento de base: paginación, índices y caché | PLT · Plataforma técnica | Tarea | PLT-5 |
| `PLT-13` | Adaptadores de integración con implementación falsa | PLT · Plataforma técnica | Tarea | PLT-6 |
| `SEG-1` | Ingreso con correo y contraseña | SEG · Identidad, roles, permisos por campo y alcance | Historia | TEN-1, PLT-4 |
| `TEN-2` | Políticas RLS en PostgreSQL | TEN · Multiempresa y aislamiento | Tarea | TEN-1 |
| `TEN-5` | Organización con varias empresas y selector de empresa | TEN · Multiempresa y aislamiento | Historia | TEN-1 |
| `TEN-6` | Aislamiento en caché, colas, archivos, búsqueda y exportaciones | TEN · Multiempresa y aislamiento | Tarea | TEN-1, PLT-6, PLT-7 |
| `UI-5` | Formularios y validación en interfaz | UI · Sistema de diseño y armazón | Tarea | UI-3, PLT-4 |
| `UI-6` | Estados vacíos, carga y error | UI · Sistema de diseño y armazón | Tarea | UI-3 |
| `UI-10` | Divulgación progresiva | UI · Sistema de diseño y armazón | Tarea | UI-3 |
| `UI-12` | Componentes de calendario, kanban y gráficos | UI · Sistema de diseño y armazón | Tarea | UI-3 |
| `WFL-1` | Modelo de definiciones, versiones y ejecuciones | WFL · Motor de aprobaciones y workflows | Tarea | PLT-6, PLT-8 |

### Tanda 5

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUD-1` | Bitácora de auditoría de solo inserción | AUD · Auditoría | Tarea | TEN-2, PLT-12 |
| `IMP-6` | Informe y reversión de importaciones | IMP · Importador y puesta en marcha | Historia | IMP-1 |
| `NOT-1` | Motor de notificaciones y plantillas | NOT · Notificaciones y mensajería | Tarea | PLT-6, PLT-13 |
| `SEG-2` | Ingreso con documento de identidad y clave u OTP | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-1, PLT-13 |
| `SEG-3` | Segundo factor (MFA) | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-1 |
| `SEG-4` | Sesiones y dispositivos | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-1 |
| `SEG-5` | Políticas de contraseña, bloqueo y límite de peticiones | SEG · Identidad, roles, permisos por campo y alcance | Tarea | SEG-1 |
| `SEG-6` | Usuarios e invitaciones | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-1 |
| `TEN-3` | Claves foráneas compuestas por organización | TEN · Multiempresa y aislamiento | Tarea | TEN-2 |
| `TEN-4` | Prueba automática de aislamiento sobre todos los endpoints | TEN · Multiempresa y aislamiento | Tarea | TEN-2, PLT-5, PLT-3 |
| `UI-4` | Tablas profesionales | UI · Sistema de diseño y armazón | Tarea | UI-3, PLT-11 |
| `WFL-2` | Motor de aprobaciones de N niveles | WFL · Motor de aprobaciones y workflows | Tarea | WFL-1 |
| `WFL-5` | Reglas CUANDO → SI → ENTONCES | WFL · Motor de aprobaciones y workflows | Tarea | WFL-1 |

### Tanda 6

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUD-3` | Actividad de usuario y logs técnicos separados | AUD · Auditoría | Tarea | AUD-1, PLT-10 |
| `AUD-5` | Integridad y retención de la auditoría | AUD · Auditoría | Tarea | AUD-1 |
| `CFG-3` | Parámetros con vigencia por fecha | CFG · Configuración, campos personalizados, i18n y multimoneda | Tarea | PLT-12, AUD-1 |
| `NOT-3` | Canal de correo | NOT · Notificaciones y mensajería | Tarea | NOT-1 |
| `NOT-5` | Capa de mensajería para SMS y WhatsApp | NOT · Notificaciones y mensajería | Tarea | NOT-1 |
| `SAS-1` | Consola separada para el superadministrador | SAS · Consola SaaS | Historia | SEG-3, TEN-1 |
| `SEG-7` | Roles del sistema y roles personalizados | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-6 |
| `WFL-3` | Resolución de aprobadores, delegación y escalamiento | WFL · Motor de aprobaciones y workflows | Tarea | WFL-2 |
| `WFL-4` | Centro de aprobaciones | WFL · Motor de aprobaciones y workflows | Historia | WFL-2, UI-4 |
| `WFL-6` | Constructor visual de flujos | WFL · Motor de aprobaciones y workflows | Historia | WFL-5, WFL-2, UI-12 |
| `WFL-7` | Centro de automatizaciones | WFL · Motor de aprobaciones y workflows | Historia | WFL-5 |
| `WFL-8` | Tareas de RRHH | WFL · Motor de aprobaciones y workflows | Historia | WFL-1, UI-4 |

### Tanda 7

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUS-1` | Tipos de ausencia con reglas propias | AUS · Ausencias y permisos | Historia | CFG-3, WFL-2 |
| `CFG-7` | Multimoneda y tipos de cambio | CFG · Configuración, campos personalizados, i18n y multimoneda | Tarea | CFG-3 |
| `CFG-10` | Catálogos maestros | CFG · Configuración, campos personalizados, i18n y multimoneda | Tarea | CFG-3 |
| `CON-1` | Tipos de contrato y modalidades | CON · Contratos y adendas | Tarea | CFG-3 |
| `SAS-2` | Organizaciones: alta, suspensión y bloqueo | SAS · Consola SaaS | Historia | SAS-1 |
| `SAS-3` | Planes comerciales | SAS · Consola SaaS | Historia | SAS-1 |
| `SAS-9` | Salud y monitoreo de la plataforma | SAS · Consola SaaS | Historia | SAS-1, PLT-10 |
| `SAS-12` | Copias de seguridad y restauración | SAS · Consola SaaS | Historia | SAS-1, PLT-9 |
| `SAS-13` | Configuración global y registros de la consola | SAS · Consola SaaS | Historia | SAS-1, AUD-1 |
| `SEG-8` | Catálogo de permisos `dominio.recurso.accion` | SEG · Identidad, roles, permisos por campo y alcance | Tarea | SEG-7 |
| `WFL-9` | Bandeja de pendientes | WFL · Motor de aprobaciones y workflows | Historia | WFL-4, WFL-8 |

### Tanda 8

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUS-4` | Licencias de maternidad, paternidad y otras de ley | AUS · Ausencias y permisos | Historia | AUS-1 |
| `COL-1` | Personas y deduplicación | COL · Colaboradores y ficha 360 | Tarea | TEN-3, CFG-10 |
| `SAS-4` | Suscripciones, complementos y pruebas gratuitas | SAS · Consola SaaS | Historia | SAS-2, SAS-3 |
| `SAS-8` | Acceso de soporte auditado | SAS · Consola SaaS | Historia | SAS-2, AUD-1 |
| `SEG-9` | Permisos por campo sensible | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-8 |
| `SEG-10` | Alcance de datos | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-8, TEN-5 |

### Tanda 9

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUD-2` | Captura automática de antes y después | AUD · Auditoría | Tarea | AUD-1, SEG-9 |
| `HIS-3` | Compensación con vigencia | HIS · Historia con vigencia | Historia | HIS-1, SEG-9 |
| `SAS-5` | Módulos por organización | SAS · Consola SaaS | Tarea | SAS-4, SEG-8 |
| `SAS-11` | Facturación de la suscripción y estado de cuenta | SAS · Consola SaaS | Historia | SAS-4, PLT-13 |
| `SEG-11` | Cifrado de datos sensibles y registro de acceso | SEG · Identidad, roles, permisos por campo y alcance | Tarea | SEG-9, PLT-12 |
| `SEG-12` | Control de descargas y exportaciones | SEG · Identidad, roles, permisos por campo y alcance | Historia | SEG-9, PLT-7 |

### Tanda 10

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUD-4` | Visor de auditoría | AUD · Auditoría | Historia | AUD-2, UI-4 |
| `AYU-1` | Contenido de ayuda del producto con versiones | AYU · Centro de ayuda | Tarea | SAS-1, SAS-5, CFG-6 |
| `CFG-9` | Papelera y restauración | CFG · Configuración, campos personalizados, i18n y multimoneda | Historia | PLT-12, AUD-2 |
| `SAS-6` | Límites y consumo | SAS · Consola SaaS | Historia | SAS-5, PLT-7 |
| `SAS-7` | Feature flags | SAS · Consola SaaS | Historia | SAS-5 |
| `UI-2` | Armazón de navegación | UI · Sistema de diseño y armazón | Historia | UI-1, SAS-5, SEG-8 |
| `UI-11` | Servicio de exportación | UI · Sistema de diseño y armazón | Tarea | UI-4, SEG-12, PLT-6 |

### Tanda 11

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AYU-2` | Centro de ayuda para todos los roles | AYU · Centro de ayuda | Historia | AYU-1, UI-2, SEG-7, SAS-5 |
| `CFG-1` | Centro de configuración | CFG · Configuración, campos personalizados, i18n y multimoneda | Historia | UI-2, SEG-8 |
| `IAR-1` | Capa de proveedor de IA | IAR · Asistente de IA y gobierno | Tarea | PLT-13, SAS-7 |
| `NOT-2` | Centro de notificaciones en la aplicación | NOT · Notificaciones y mensajería | Historia | NOT-1, UI-2 |
| `SAS-10` | Tablero del negocio SaaS | SAS · Consola SaaS | Historia | SAS-4, SAS-6 |
| `UI-7` | Paleta de comandos y búsqueda global | UI · Sistema de diseño y armazón | Historia | UI-2, SEG-10, TEN-6 |
| `UI-8` | Botón de creación rápida | UI · Sistema de diseño y armazón | Historia | UI-2 |
| `UI-9` | Experiencia móvil y aplicación instalable | UI · Sistema de diseño y armazón | Tarea | UI-2, UI-4 |

### Tanda 12

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AYU-3` | Ayuda contextual por pantalla | AYU · Centro de ayuda | Historia | AYU-2, UI-6 |
| `AYU-4` | Artículos y políticas de la organización | AYU · Centro de ayuda | Historia | AYU-2, SEG-10, NOT-2 |
| `AYU-7` | Recorridos guiados y novedades de versión | AYU · Centro de ayuda | Historia | AYU-2, UI-2 |
| `CFG-2` | Configuración por empresa | CFG · Configuración, campos personalizados, i18n y multimoneda | Historia | CFG-1, TEN-5 |
| `CFG-4` | Campos personalizados | CFG · Configuración, campos personalizados, i18n y multimoneda | Historia | CFG-1, SEG-9 |
| `IAR-2` | Registro de uso de IA | IAR · Asistente de IA y gobierno | Tarea | IAR-1, AUD-3 |
| `NOT-4` | Canal push | NOT · Notificaciones y mensajería | Tarea | NOT-1, UI-9 |
| `NOT-6` | Preferencias de notificación | NOT · Notificaciones y mensajería | Historia | NOT-2, NOT-3 |

### Tanda 13

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AYU-5` | Buscador de ayuda y valoración de artículos | AYU · Centro de ayuda | Historia | AYU-2, AYU-4 |
| `CFG-5` | Campos personalizados en tablas, filtros, importación y API | CFG · Configuración, campos personalizados, i18n y multimoneda | Tarea | CFG-4, UI-4 |
| `CFG-8` | Calendarios, feriados y calendario de RRHH | CFG · Configuración, campos personalizados, i18n y multimoneda | Historia | CFG-2, UI-12 |
| `ORG-1` | Empresas y razones sociales | ORG · Estructura organizacional | Historia | TEN-5, CFG-2 |

### Tanda 14

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `ORG-2` | Sedes, sucursales y ubicaciones | ORG · Estructura organizacional | Historia | ORG-1, CFG-8 |
| `ORG-3` | Árbol de unidades organizacionales | ORG · Estructura organizacional | Historia | ORG-1 |
| `ORG-4` | Centros de costo | ORG · Estructura organizacional | Historia | ORG-1 |
| `ORG-5` | Catálogo de puestos, cargos, niveles y categorías | ORG · Estructura organizacional | Historia | ORG-1 |
| `VAC-1` | Política de vacaciones por régimen | VAC · Vacaciones | Tarea | CFG-3, ORG-1 |

### Tanda 15

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `ORG-6` | Organigrama visual dinámico | ORG · Estructura organizacional | Historia | ORG-3, UI-12 |
| `ORG-7` | Directorio de personas | ORG · Estructura organizacional | Historia | ORG-3, SEG-9 |
| `POS-1` | Posiciones como plazas | POS · Posiciones y headcount | Historia | ORG-5, ORG-3 |

### Tanda 16

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `POS-2` | Estados de la posición | POS · Posiciones y headcount | Tarea | POS-1, PLT-8 |
| `POS-7` | Organigrama por posiciones | POS · Posiciones y headcount | Historia | POS-1, ORG-6 |

### Tanda 17

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `POS-3` | Solicitud y aprobación de nuevas posiciones | POS · Posiciones y headcount | Historia | POS-2, WFL-2 |
| `POS-4` | Presupuesto de dotación y desviaciones | POS · Posiciones y headcount | Historia | POS-2, SEG-9 |
| `POS-6` | Política de contratar solo contra posición | POS · Posiciones y headcount | Tarea | POS-2 |

### Tanda 18

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `COL-2` | Alta de relación laboral | COL · Colaboradores y ficha 360 | Historia | COL-1, POS-6, HIS-1 |
| `POS-5` | Escenarios de planificación | POS · Posiciones y headcount | Historia | POS-4 |

### Tanda 19

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `COL-3` | Ficha 360 del colaborador | COL · Colaboradores y ficha 360 | Historia | COL-2, SEG-9, SEG-10 |
| `COL-9` | Listado de colaboradores | COL · Colaboradores y ficha 360 | Historia | COL-2, UI-4 |
| `COL-10` | Reingreso y relaciones simultáneas | COL · Colaboradores y ficha 360 | Historia | COL-2 |
| `CON-2` | Registro del contrato | CON · Contratos y adendas | Historia | CON-1, COL-2, HIS-1 |
| `HIS-2` | Movimientos de personal | HIS · Historia con vigencia | Historia | HIS-1, COL-2, WFL-2 |
| `VAC-2` | Devengo y saldos de vacaciones | VAC · Vacaciones | Tarea | VAC-1, COL-2 |

### Tanda 20

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `COL-4` | Datos personales y contactos de emergencia | COL · Colaboradores y ficha 360 | Historia | COL-3 |
| `COL-5` | Datos laborales de la ficha | COL · Colaboradores y ficha 360 | Historia | COL-3, HIS-2 |
| `COL-11` | Registro del cese de la relación laboral | COL · Colaboradores y ficha 360 | Tarea | COL-2, HIS-2 |
| `CON-3` | Renovaciones y adendas | CON · Contratos y adendas | Historia | CON-2 |
| `DOC-1` | Repositorio y carpetas del legajo | DOC · Gestión documental, plantillas y firma | Historia | PLT-7, SEG-12, COL-3 |
| `ESS-1` | Inicio del colaborador | ESS · Portal del colaborador | Historia | WFL-9, COL-3 |
| `HIS-4` | Cambios con fecha futura y correcciones retroactivas | HIS · Historia con vigencia | Tarea | HIS-2, HIS-3, PLT-6 |
| `HIS-5` | Línea de tiempo laboral | HIS · Historia con vigencia | Historia | HIS-2, HIS-3, UI-3 |
| `HIS-6` | Servicio de consulta de datos maestros | HIS · Historia con vigencia | Tarea | HIS-2, HIS-3 |
| `IMP-2` | Importadores de Core HR | IMP · Importador y puesta en marcha | Historia | IMP-1, COL-2, HIS-2, CON-2 |
| `VAC-7` | Importación de saldos de vacaciones | VAC · Vacaciones | Historia | VAC-2, IMP-1 |

### Tanda 21

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `COL-6` | Derechohabientes | COL · Colaboradores y ficha 360 | Historia | COL-4, DOC-1 |
| `COL-7` | Régimen pensionario, salud y seguros del trabajador | COL · Colaboradores y ficha 360 | Historia | COL-4, HIS-1 |
| `COL-8` | Cuentas bancarias y datos de pago | COL · Colaboradores y ficha 360 | Historia | COL-4, SEG-11 |
| `CON-4` | Alertas de vencimiento y de tope acumulado | CON · Contratos y adendas | Historia | CON-3, NOT-1 |
| `DOC-2` | Versiones, archivo y vencimientos | DOC · Gestión documental, plantillas y firma | Historia | DOC-1 |
| `DOC-3` | Solicitud y aprobación de documentos | DOC · Gestión documental, plantillas y firma | Historia | DOC-1, WFL-9 |
| `DOC-4` | Plantillas dinámicas con variables | DOC · Gestión documental, plantillas y firma | Historia | DOC-1 |
| `ESS-2` | Mi perfil y actualización de datos | ESS · Portal del colaborador | Historia | COL-4, WFL-2 |
| `ESS-5` | Solicitudes a RRHH desde el portal | ESS · Portal del colaborador | Historia | ESS-1, WFL-2 |
| `ESS-6` | Portal del colaborador en el móvil | ESS · Portal del colaborador | Tarea | ESS-1, UI-9, SEG-2 |
| `IMP-3` | Asistente de primeros pasos | IMP · Importador y puesta en marcha | Historia | IMP-2, CFG-1 |
| `IMP-5` | Organización demo coherente | IMP · Importador y puesta en marcha | Tarea | IMP-2 |
| `IMP-7` | Importadores desde otros sistemas de RRHH y planilla | IMP · Importador y puesta en marcha | Historia | IMP-2, IMP-6 |
| `MSS-1` | Inicio del manager | MSS · Portal del manager | Historia | ESS-1, SEG-10 |
| `NOM-1` | Arquitectura del motor: núcleo, paquete de país y régimen | NOM · Motor de nómina | Tarea | CFG-3, HIS-6 |
| `VAC-3` | Solicitud y aprobación de vacaciones | VAC · Vacaciones | Historia | VAC-2, WFL-2, ESS-1 |

### Tanda 22

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AYU-6` | Derivar la consulta a RRHH o a soporte | AYU · Centro de ayuda | Historia | AYU-5, ESS-5, SAS-8 |
| `COL-12` | Inicio de RRHH | COL · Colaboradores y ficha 360 | Historia | COL-9, CON-4 |
| `CON-6` | Acuerdo de teletrabajo | CON · Contratos y adendas | Historia | CON-3, DOC-4 |
| `DEC-3` | T-Registro | DEC · Declaraciones y archivos | Historia | COL-6, COL-7 |
| `DOC-5` | Generación de PDF | DOC · Gestión documental, plantillas y firma | Tarea | DOC-4, PLT-6 |
| `IMP-4` | Plantillas peruanas listas para usar | IMP · Importador y puesta en marcha | Tarea | CON-1, DOC-4, CFG-8 |
| `MSS-2` | Mi equipo | MSS · Portal del manager | Historia | MSS-1, COL-9 |
| `MSS-3` | Aprobaciones y solicitudes del equipo | MSS · Portal del manager | Historia | MSS-1, WFL-4, HIS-2 |
| `MSS-4` | Fechas clave y alertas del equipo | MSS · Portal del manager | Historia | MSS-1, CON-4 |
| `NOM-2` | Regímenes laborales por empresa y por trabajador | NOM · Motor de nómina | Tarea | NOM-1, ORG-1 |
| `NOM-3` | Intérprete de fórmulas seguro | NOM · Motor de nómina | Tarea | NOM-1 |
| `PAR-1` | Parámetros legales generales | PAR · Parámetros legales con vigencia | Historia | CFG-3, NOM-1 |
| `PER-1` | Periodos de planilla | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | NOM-1, PLT-8 |
| `VAC-4` | Calendario de ausencias del equipo | VAC · Vacaciones | Historia | VAC-3, UI-12, CFG-8 |
| `VAC-5` | Programación anual y vacaciones acumuladas | VAC · Vacaciones | Historia | VAC-3 |

### Tanda 23

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUS-2` | Solicitud y registro de ausencias | AUS · Ausencias y permisos | Historia | AUS-1, VAC-4 |
| `CPT-1` | Catálogo de conceptos | CPT · Conceptos y fórmulas | Historia | NOM-3 |
| `DEC-8` | Ficha del registro laboral y validación previa al alta | DEC · Declaraciones y archivos | Historia | DEC-3, COL-6 |
| `DOC-6` | Flujo de firma | DOC · Gestión documental, plantillas y firma | Historia | DOC-5, WFL-9, NOT-1 |
| `ESS-4` | Solicitud de certificados y constancias | ESS · Portal del colaborador | Historia | DOC-5, ESS-1 |
| `IAR-9` | Asistente de ayuda documental | IAR · Asistente de IA y gobierno | Historia | IAR-1, IAR-2, AYU-5, AYU-6 |
| `NOM-4` | Orden de cálculo y dependencias entre conceptos | NOM · Motor de nómina | Tarea | NOM-3 |
| `PAR-2` | Tablas de AFP | PAR · Parámetros legales con vigencia | Historia | PAR-1 |
| `PAR-3` | Escala y deducciones de renta de quinta | PAR · Parámetros legales con vigencia | Historia | PAR-1 |
| `PAR-4` | Publicación de actualizaciones normativas | PAR · Parámetros legales con vigencia | Historia | PAR-1, SAS-13 |
| `PAR-5` | Parámetros por régimen laboral | PAR · Parámetros legales con vigencia | Tarea | PAR-1, NOM-2 |
| `PAR-6` | Tabla salarial anual por convenio | PAR · Parámetros legales con vigencia | Historia | PAR-1 |

### Tanda 24

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `AUS-3` | Descansos médicos y subsidios | AUS · Ausencias y permisos | Historia | AUS-2, SEG-9 |
| `AUS-5` | Trabajo remoto y desconexión digital | AUS · Ausencias y permisos | Historia | AUS-2, CON-6, NOT-6 |
| `AUS-6` | Ausencias como incidencias para planilla | AUS · Ausencias y permisos | Tarea | AUS-2 |
| `CON-5` | Renovación masiva con firma | CON · Contratos y adendas | Historia | CON-4, DOC-6 |
| `CPT-2` | Editor de fórmulas y condiciones | CPT · Conceptos y fórmulas | Historia | CPT-1, NOM-4 |
| `CPT-3` | Afectaciones de cada concepto | CPT · Conceptos y fórmulas | Historia | CPT-1 |
| `CPT-4` | Contabilización por concepto | CPT · Conceptos y fórmulas | Historia | CPT-1, ORG-4 |
| `CPT-6` | Importación de conceptos e incidencias | CPT · Conceptos y fórmulas | Historia | CPT-1, IMP-1 |
| `DOC-7` | Evidencia de firma electrónica propia | DOC · Gestión documental, plantillas y firma | Tarea | DOC-6, SEG-2 |
| `ESS-3` | Mis documentos, contrato y firma | ESS · Portal del colaborador | Historia | DOC-6, DOC-1 |
| `IAR-10` | Activación del asistente de ayuda desde la administración | IAR · Asistente de IA y gobierno | Historia | IAR-9, SAS-7, SEG-7, AUD-2 |
| `IAR-11` | Calidad de las respuestas del asistente de ayuda | IAR · Asistente de IA y gobierno | Tarea | IAR-9, AYU-5 |
| `NOM-9` | Estrategia por régimen laboral | NOM · Motor de nómina | Tarea | NOM-1, PAR-5 |
| `VAC-6` | Fraccionamiento, adelanto y venta de vacaciones | VAC · Vacaciones | Historia | VAC-3, DOC-6 |

### Tanda 25

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `CPT-5` | Conceptos peruanos precargados | CPT · Conceptos y fórmulas | Tarea | CPT-3 |
| `DOC-8` | Capa de proveedor de firma digital | DOC · Gestión documental, plantillas y firma | Tarea | DOC-7, PLT-13 |
| `NOM-5` | Contexto de cálculo por relación laboral | NOM · Motor de nómina | Tarea | NOM-2, AUS-6 |
| `NOM-10` | Micro y pequeña empresa: acreditación, ventas y permanencia | NOM · Motor de nómina | Historia | NOM-9, ORG-1 |
| `PER-2` | Incidencias del periodo | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-1, CPT-6 |
| `QA-2` | Recorrido de extremo a extremo de cimientos y Core HR | QA · Calidad, seguridad y salida a producción | Tarea | QA-1, IMP-5, DOC-7, SAS-5, MSS-3 |

### Tanda 26

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `CPT-7` | Códigos de la tabla de conceptos y naturaleza en la proyección de quinta | CPT · Conceptos y fórmulas | Tarea | CPT-5 |
| `DEC-1` | Mapeo de conceptos a tablas SUNAT | DEC · Declaraciones y archivos | Tarea | CPT-5 |
| `NOM-6` | Trazabilidad del cálculo | NOM · Motor de nómina | Tarea | NOM-5 |
| `NOM-8` | Cálculo masivo en segundo plano | NOM · Motor de nómina | Tarea | NOM-5, PLT-6 |
| `PER-3` | Consumo de asistencia y ausencias | PER · Periodos, incidencias, cálculo, simulación y cierre | Tarea | PER-1, NOM-5 |

### Tanda 27

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `NOM-7` | Recálculo retroactivo | NOM · Motor de nómina | Tarea | NOM-6, HIS-4 |
| `PER-4` | Cálculo de la planilla | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-2, PER-3, NOM-8, CPT-5 |

### Tanda 28

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `BSO-4` | Asignación familiar | BSO · Beneficios sociales | Tarea | PER-4, COL-6 |
| `BSO-5` | Remuneración vacacional e indemnización vacacional | BSO · Beneficios sociales | Tarea | PER-4, VAC-6 |
| `PER-5` | Renta de quinta categoría | PER · Periodos, incidencias, cálculo, simulación y cierre | Tarea | PER-4, PAR-3 |
| `PER-6` | Aportes previsionales y de salud | PER · Periodos, incidencias, cálculo, simulación y cierre | Tarea | PER-4, PAR-2 |
| `PER-7` | Simulación y detección de anomalías | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-4 |

### Tanda 29

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `DEC-6` | Certificados de renta y de retenciones | DEC · Declaraciones y archivos | Historia | PER-5, DOC-5 |
| `DEC-9` | Declaración jurada de rentas de otros empleadores | DEC · Declaraciones y archivos | Historia | PER-5, COL-6 |
| `PAR-7` | Novedades normativas visibles para el cliente | PAR · Parámetros legales con vigencia | Historia | PAR-4, NOT-2, PER-7 |
| `PER-8` | Revisión, aprobación y cierre | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-7, WFL-2 |
| `PER-11` | Libro de retenciones de quinta | PER · Periodos, incidencias, cálculo, simulación y cierre | Tarea | PER-5 |

### Tanda 30

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `BOL-1` | Generación de boletas en PDF | BOL · Boletas de pago | Tarea | PER-8, DOC-5 |
| `BSO-1` | Gratificaciones | BSO · Beneficios sociales | Historia | PER-8, PAR-5 |
| `BSO-3` | Utilidades | BSO · Beneficios sociales | Historia | PER-8, AUS-6 |
| `DEC-2` | Archivos de PLAME | DEC · Declaraciones y archivos | Historia | DEC-1, PER-8 |
| `DEC-4` | Planilla de aportes para AFPnet | DEC · Declaraciones y archivos | Historia | PER-6, PER-8 |
| `DEC-11` | Devolución de retenciones de quinta en exceso | DEC · Declaraciones y archivos | Historia | PER-11 |
| `PER-9` | Reapertura controlada y planilla complementaria | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-8 |
| `PER-10` | Pago de la planilla | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-8, COL-8 |
| `PER-12` | Importación de acumulados de planilla del ejercicio | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | IMP-1, PER-1, PER-5, PER-11 |

### Tanda 31

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `BOL-2` | Publicación en el portal y constancia de entrega | BOL · Boletas de pago | Historia | BOL-1, ESS-3, DOC-7 |
| `BSO-2` | CTS | BSO · Beneficios sociales | Historia | BSO-1, COL-8 |
| `DEC-5` | Archivos bancarios | DEC · Declaraciones y archivos | Historia | PER-10 |
| `DEC-7` | Bitácora de declaraciones | DEC · Declaraciones y archivos | Historia | DEC-2, DEC-4 |
| `DEC-10` | Generador de archivos con estructuras versionadas | DEC · Declaraciones y archivos | Tarea | DEC-2 |
| `PER-13` | Comparación con el sistema anterior durante la planilla en paralelo | PER · Periodos, incidencias, cálculo, simulación y cierre | Historia | PER-12, PER-7, IMP-7 |

### Tanda 32

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `BOL-3` | Historial y descarga masiva | BOL · Boletas de pago | Historia | BOL-2, SEG-12 |
| `BOL-4` | Conservación de boletas y constancias | BOL · Boletas de pago | Tarea | BOL-2 |
| `BOL-5` | Explicación del cambio de neto en la boleta | BOL · Boletas de pago | Historia | BOL-2, NOM-6 |
| `CUM-9` | Tablero mínimo de obligaciones de planilla | CUM · Cumplimiento Perú y SUNAFIL | Historia | DEC-3, DEC-4, BSO-2, CON-4, NOT-2 |
| `LIQ-1` | Cálculo de la liquidación | LIQ · Liquidación de beneficios sociales | Tarea | BSO-2, BSO-5, COL-11 |

### Tanda 33

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `LIQ-2` | Simulador de liquidación | LIQ · Liquidación de beneficios sociales | Historia | LIQ-1 |
| `LIQ-3` | Aprobación, pago y plazo de la liquidación | LIQ · Liquidación de beneficios sociales | Historia | LIQ-1, WFL-2 |

### Tanda 34

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `LIQ-4` | Documentos de cese | LIQ · Liquidación de beneficios sociales | Historia | LIQ-3, DOC-6 |
| `QA-17` | Salida a producción del primer corte comercial | QA · Calidad, seguridad y salida a producción | Tarea | QA-2, PER-13, BOL-2, DEC-4, LIQ-3 |

## 2. Sitio comercial (etiqueta `web`)

Va en paralelo al MVP y solo depende de él. Diseño de referencia en `kit/docs/web/sitio/`.

Usa además estas actividades de fuera del grupo, que deben estar hechas antes: `NOT-3`, `PLT-1`, `PLT-13`, `PLT-5`, `PLT-9`, `SAS-1`, `SAS-11`, `SAS-13`, `SAS-2`, `SAS-3`, `SAS-4`, `SAS-5`, `SEG-5`, `SEG-6`.

### Tanda 4

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-1` | Aplicación del sitio comercial y su CMS | WEB · Sitio comercial gestiahr.com | Tarea | PLT-1, PLT-9, PLT-5 |

### Tanda 5

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-8` | Blog, boletín y comentarios moderados | WEB · Sitio comercial gestiahr.com | Historia | WEB-1 |
| `WEB-11` | Popups, banner de cookies y medición | WEB · Sitio comercial gestiahr.com | Historia | WEB-1 |

### Tanda 7

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-6` | Agendar demo, contacto y partners | WEB · Sitio comercial gestiahr.com | Historia | WEB-1, NOT-3 |
| `WEB-12` | Páginas legales y libro de reclamaciones virtual | WEB · Sitio comercial gestiahr.com | Historia | WEB-1, NOT-3 |

### Tanda 8

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-13` | Sección «Sitio web» de la consola | WEB · Sitio comercial gestiahr.com | Historia | SAS-1, SAS-13, WEB-6 |

### Tanda 10

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-2` | Catálogo de productos ligado a los módulos | WEB · Sitio comercial gestiahr.com | Tarea | SAS-3, SAS-5, WEB-1 |

### Tanda 11

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-3` | Inicio, páginas de producto y página de la Suite | WEB · Sitio comercial gestiahr.com | Historia | WEB-2 |
| `WEB-4` | Precios, comparador y selector de módulos | WEB · Sitio comercial gestiahr.com | Historia | WEB-2, SAS-4 |
| `WEB-10` | Chatbot comercial del sitio | WEB · Sitio comercial gestiahr.com | Historia | WEB-2, PLT-13 |

### Tanda 12

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WEB-5` | Registro de prueba y suscripción en tres pasos | WEB · Sitio comercial gestiahr.com | Historia | WEB-4, SAS-2, SAS-11, SEG-6, SEG-5 |
| `WEB-7` | Lista de espera de lo que está en «Próximamente» | WEB · Sitio comercial gestiahr.com | Historia | WEB-3, WEB-6 |
| `WEB-9` | SEO técnico y páginas por módulo e industria | WEB · Sitio comercial gestiahr.com | Tarea | WEB-3, WEB-8 |

## 3. Segunda ola (etiqueta `ola-2`)

Después del MVP.

Usa además estas actividades de fuera del grupo, que deben estar hechas antes: `BOL-2`, `CUM-9`, `DEC-2`, `ESS-4`, `IMP-4`, `NOM-9`, `NOT-5`, `PER-7`, `PER-8`, `SEG-10`, `SEG-2`, `TEN-1`, `VAC-2`.

### Tanda 7

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WHA-1` | Vinculación de identidad y sesión en WhatsApp | WHA · Autoservicio por WhatsApp | Tarea | NOT-5, SEG-2 |

### Tanda 9

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `EST-1` | Consola de socio | EST · Modo estudio contable | Historia | TEN-1, SEG-10 |

### Tanda 20

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `WHA-2` | Menú conversacional y consultas | WHA · Autoservicio por WhatsApp | Historia | WHA-1, VAC-2 |

### Tanda 23

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `EST-2` | Alta de empresas cliente y delegación | EST · Modo estudio contable | Historia | EST-1, IMP-4 |

### Tanda 29

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `PRV-5` | Simulador público de costo laboral | PRV · Provisiones y asientos contables | Historia | NOM-9, PER-7 |

### Tanda 31

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `EST-3` | Procesos de planilla en lote | EST · Modo estudio contable | Historia | EST-2, PER-8, DEC-2 |

### Tanda 32

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `EST-5` | Portal de la empresa cliente | EST · Modo estudio contable | Historia | EST-3 |
| `WHA-3` | Boletas y constancias por WhatsApp | WHA · Autoservicio por WhatsApp | Historia | WHA-2, BOL-2, ESS-4 |

### Tanda 33

| Clave | Actividad | Épica | Tipo | Bloqueada por |
|---|---|---|---|---|
| `EST-4` | Tablero de obligaciones por cliente | EST · Modo estudio contable | Historia | EST-2, CUM-9 |

