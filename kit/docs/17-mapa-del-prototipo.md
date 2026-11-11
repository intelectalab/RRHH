# 17 · Mapa del prototipo

GestiaHR · kit de desarrollo. Mapa del prototipo final (`core.html` más `mod-1-tiempo.js` a `mod-9-complementos.js`, incluido `mod-9-ayuda.js` del centro de ayuda, ensamblados en `index.html`) para ubicar cada pantalla, su módulo comercial, su épica y qué parte es real o simulada.

**Cómo leerlo**

- «Módulo comercial (id)» es el código de `database/catalogos/modulos.json`; entre paréntesis va el id que usa el prototipo en `registerModule`, que agrupa distinto (por ejemplo `tiempo` cubre `attendance`, `time_off` y `timesheets`).
- «Pestañas» lista solo las que constan en el código del prototipo; «—» significa pantalla sin pestañas o con secciones no relevadas aquí.
- «Real» = el prototipo guarda el dato en el navegador, aplica permisos y alcance y actualiza las demás pantallas. «Simulado» = el prototipo lo rotula como simulado y no hay efecto externo.
- Regla general de lo simulado en todo el prototipo: no hay servidor ni base de datos (todo vive en el almacenamiento del navegador); no sale ningún correo, push, SMS ni WhatsApp (quedan en una bandeja de envíos simulados); los «PDF» son vistas previas; los archivos adjuntos guardan nombre y tipo, no el archivo; las descargas son texto para copiar; no hay integraciones reales. Qué no debe copiarse del prototipo: `15-que-no-copiar-del-prototipo.md`.
- Las especificaciones de `docs/modulos/` citan estas rutas en su columna «Prototipo».

## 1. Menú de la organización

### Sin grupo (parte superior del menú)

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Inicio | Panel «Primeros pasos» y «Tu bandeja» cuando aplican | `base`, `core` (núcleo) | UI, ESS, MSS, IMP | Tablero según rol y alcance, accesos a pendientes | Indicadores calculados solo con los datos demo |
| Pendientes | Acceso a Notificaciones | `base` (`plataforma`) | WFL, NOT | Bandeja única de lo que espera al usuario; avisos leídos y no leídos | Canales externos: bandeja de envíos simulados |
| Tareas | — | `base` (`plataforma`) | WFL | Tareas con responsable, fecha, estado y prioridad | — |
| Calendario | Filtros Vacaciones, Ausencias, Cumpleaños, Aniversarios, Contratos, Capacitaciones, Evaluaciones, Entrevistas, Feriados, Eventos | `analytics` (`analytics`) | NOT, CFG | Calendario central alimentado por los módulos | — |

### Barra superior y menú de usuario

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Barra superior → Procesos | — | `base` (`complementos`) | PLT | Lista de procesos propios con avance, resultado y archivo | Los procesos no corren en un servidor; el archivo es texto y «vence» por fecha |
| Menú de usuario → Mi cuenta | Datos y preferencias, Contraseña y seguridad, Notificaciones | `base` (`complementos`) | SEG, NOT, UI | Preferencias por tipo de aviso y canal, idioma y tema | Contraseña, MFA y sesiones sin efecto real |
| Barra superior → Ayuda | Esta pantalla, Guías, Asistente, Mis consultas | `core` (`ayuda`) | AYU, IAR | Botón de ayuda en todas las pantallas y roles; artículos filtrados por rol y módulo; ayuda de la pantalla actual; búsqueda, valoración y derivación a RRHH o a soporte; recorrido de primer ingreso | **Asistente simulado**: busca en los artículos con reglas escritas y cita la fuente, sin llamar a ningún servicio |

### Personas

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Colaboradores | — (acciones Nuevo colaborador, Exportar CSV, selección y acciones masivas) | `core` (núcleo) | COL | Alta, reingreso, filtros, columnas, exportación con permisos | Exportación como texto para copiar |
| Ficha del colaborador | Resumen, Información, Contrato, Compensación, Historial, Actividad; de módulos: Asistencia, Planilla, Onboarding, Desempeño, Capacitaciones, Beneficios, Documentos, Reconocimientos, Activos, Campos adicionales, Vacaciones, Competencias | `core` y el módulo de cada pestaña | COL, HIS y la épica de cada pestaña | Movimientos con vigencia, cese, permisos por campo | — |
| Directorio | — | `core` (núcleo) | ORG | Búsqueda y tarjetas según permiso | — |
| Organigrama | Por personas, Por unidades | `core` (núcleo) | ORG | Se redibuja con los cambios de jefe y unidad | — |
| Headcount | — | `core` (`headcount`) | POS | Posiciones, solicitudes, aprobación, congelar y cubrir | Costos con valores demo |
| Movimientos programados | — | `core` (`complementos`) | HIS | Cambios con fecha futura; aplicar los vencidos; editar o cancelar | La aplicación automática por fecha se lanza a mano |

### Organización y Seguridad

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Organización → Estructura | Empresas, Sedes, Unidades, Puestos, Reorganizar | `core` (núcleo) | ORG, TEN | Crear, editar, archivar, ver archivados | — |
| Seguridad → Usuarios | Acceso de terceros | `base` (núcleo) | SEG | Crear usuarios, asignar rol, cambiar de usuario demo; acceso de terceros con vigencia y revocación | Sin autenticación real ni MFA |
| Seguridad → Roles y permisos | — | `base` (núcleo) | SEG | Matriz permiso × rol editable, alcance por rol, roles nuevos | Códigos de permiso abreviados en español (ver `07-permisos-y-roles.md`) |
| Seguridad → Auditoría | Accesos, Actividad | `base` (núcleo) | AUD | Registro con antes y después, filtros | Vive en el navegador; no es inalterable |

### Tiempo

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Asistencia | Tablero del día, Registro | `attendance` (`tiempo`) | ASI | Marcación, cálculo diario, correcciones como registros nuevos | Reloj, geocerca y selfie simulados |
| Turnos y horarios | Tipos de turno, Horarios | `attendance` (`tiempo`) | TUR | Asignación y planificación | — |
| Vacaciones | — | `time_off` (`tiempo`) | VAC | Saldo, solicitud, aprobación, calendario | — |
| Ausencias y permisos | — | `time_off` (`tiempo`) | AUS | Tipos con reglas, solicitud y aprobación | Sustentos: se guarda el nombre del archivo |
| Horas extra y bolsa | — | `attendance` (`tiempo`) | BHO | Autorización, calificación, saldo de bolsa | Simulador de campaña de horas extra |
| Hojas de tiempo | — | `timesheets` (`tiempo`) | TSH | Registro y aprobación por proyecto | — |
| Kiosko de marcación | Pantalla completa | `attendance` (`complementos`) | ASI | Marcación compartida que genera el mismo registro de asistencia | Lector de QR, PIN y reloj simulados |

### Nómina

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Mis boletas | — | `payroll` (`nomina`) | BOL, ESS | Historial propio y acuse | PDF como vista previa |
| Planilla | Procesos: Planilla mensual, Gratificación, Depósito de CTS, Utilidades, Liquidación, Recibos de locadores | `payroll` (`nomina`) | PER, NOM, BOL | Periodo, incidencias, cálculo, comparación, aprobación por persona distinta, cierre | Cálculo ilustrativo con valores de ejemplo; no es el motor |
| Beneficios sociales | Gratificaciones, CTS, Utilidades, Provisiones, Liquidación de beneficios | `payroll` (`nomina`) | BSO, PRV, LIQ | Procesos y simulador de liquidación | Fórmulas de ejemplo sin validar |
| Préstamos y adelantos | — | `advances` (`nomina`) | PRE | Solicitud, cronograma, descuento en planilla | Desembolso simulado |
| Declaraciones | Incluye asiento de centralización y archivo de banco | `payroll` (`nomina`) | DEC, PRV | Estados de cada archivo | Archivos ilustrativos; no son las estructuras oficiales |
| Locadores | — | `contractors` (`nomina`) | LOC | Prestadores y recibos | — |
| Configuración de nómina | Datos por colaborador, Conceptos, Parámetros legales, Paquetes de país | `payroll` (`nomina`) | CPT, PAR, PAI | Conceptos y parámetros con marca «confirmado con la norma» | Valores de ejemplo |

### Talento

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Reclutamiento | Configuración: Etapas y scorecard, Plantillas de oferta, Listas | `recruiting` (`reclutamiento`) | ATS, ENT | Vacantes, tablero por etapas, entrevistas, ofertas | Mensajes al candidato y CV (texto, no archivo) |
| Base de talento | — | `recruiting` (`reclutamiento`) | ATS | Etiquetas y búsqueda | — |
| Portal de empleos | — | `recruiting` (`reclutamiento`) | ATS | Vista pública y formulario configurable | No es un sitio público real |
| Onboarding | — | `onboarding` (`onboarding`) | ONB | Plantillas, tareas, avance | Correos de bienvenida |
| Offboarding | — | `onboarding` (`onboarding`) | OFF | Lista de comprobación y motivo | — |
| Evaluaciones | Avance, Evaluadores, Configuración, Calibración y resultados | `performance` (`desempeno`) | DES | Campañas, formularios, calibración | Recordatorios |
| Objetivos | — | `performance` (`desempeno`) | OKR | Objetivos, resultados clave, avance | — |
| 1:1 y feedback | Reuniones 1:1, Feedback (Recibido, Dado, Solicitudes, Reconocimientos) | `performance` (`desempeno`) | FBK | Agenda, notas, compromisos, feedback | — |
| Competencias | Catálogo, Por puesto, Matriz de habilidades, Brechas | `performance` (`desempeno`) | CMP | Niveles y brechas | — |
| Desarrollo y sucesión | Planes de desarrollo, Plan de carrera, Matriz 9-box, Riesgo de rotación | `careers` (`desempeno`) | CAR | Planes, sucesores, ubicación 9-box | Riesgo de rotación por reglas simples, con factores visibles |

### Aprendizaje y Compensación

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Aprendizaje → Cursos | — | `learning` (`aprendizaje`) | LMS | Cursos, asignación, avance, certificados | Contenido de lecciones y certificados como vista previa |
| Aprendizaje → Plan anual | — | `learning` (`aprendizaje`) | LMS | Planificado frente a ejecutado | — |
| Compensación → Bandas salariales | Posición en la banda, Bandas por puesto | `compensation` (`compensacion`) | COM | Bandas y posición, solo con permiso de sueldos | — |
| Compensación → Revisión salarial | — | `compensation` (`compensacion`) | COM | Propuestas, etapas de aprobación, aplicación con vigencia | — |
| Compensación → Igualdad salarial | Brecha salarial, Categorías y funciones, Política salarial, Comunicación | `compensation` (`compensacion`) | COM | Cuadro, brecha, acuses | Envío de la comunicación |
| Compensación → Beneficios | — | `benefits` (`compensacion`) | BNF | Catálogo, elegibilidad, inscripción | — |

### Operaciones y Experiencia

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Operaciones → Documentos | — | `core` (`documentos`) | DOC | Carpetas, solicitudes, plantillas, generación | Archivos: nombre y tipo; PDF como vista previa |
| Operaciones → Firma electrónica | — | `core` (`documentos`) | DOC | Firmantes, orden, estados, evidencia registrada | OTP y sello de tiempo ilustrativos |
| Operaciones → Contratos | — | `core` (`documentos`) | CON | Contratos, vencimientos, renovación | — |
| Operaciones → Activos | Activos; EPP: Entregas y vencimientos, Catálogo, Tallas por colaborador | `assets` (`activos`) | ACT | Asignación con acta, devolución, EPP | — |
| Operaciones → Gastos | — | `expenses` (`gastos`) | GAS | Gastos, informes, viáticos, aprobación en dos pasos | Comprobantes: nombre del archivo; pago simulado |
| Experiencia → Encuestas | Resultados: Resumen, Mapa de calor, Comentarios | `engagement` (`experiencia`) | ENC | Constructor, anonimato con umbral, planes de acción | Invitaciones |
| Experiencia → eNPS | — | `engagement` (`experiencia`) | ENC | Cálculo y tendencia | — |
| Experiencia → Reconocimientos | — | `engagement` (`experiencia`) | REC | Muro, valores, insignias | — |
| Experiencia → Comunicaciones | — | `engagement` (`experiencia`) | CIN | Segmentos, lectura obligatoria | Correos |
| Experiencia → Mesa de ayuda | — | `helpdesk` (`mesa`) | HSD | Solicitudes, SLA, base de conocimiento | — |
| Experiencia → Solicitudes por WhatsApp | Simulador de conversaciones, conversaciones derivadas | `whatsapp` (`complementos`) | WHA, HSD | Trámites creados con las mismas validaciones del portal; derivación a un agente | Todo el canal: no hay proveedor ni mensajes reales |

### SST y Cumplimiento

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| SST → Tablero SST | — | `sst` (`sst`) | SST | Indicadores y medidas vencidas | — |
| SST → Accidentes e incidentes | — | `sst` (`sst`) | SST | Reporte, investigación, medidas | — |
| SST → Registros obligatorios | Los registros (incluye inspecciones, exámenes, capacitaciones, monitoreo) | `sst` (`sst`) | SST, LMS | Registros con su conservación | Formatos ilustrativos |
| SST → Matriz IPERC | — | `sst` (`sst`) | SST | Peligros, riesgos y controles | — |
| SST → Comité y parámetros | — | `sst` (`sst`) | SST | Miembros, actas, parámetros | — |
| Cumplimiento → Canal de denuncias | — | `grievances` (`etica`) | DEN | Denuncia anónima o identificada con código de seguimiento | No es un portal público separado |
| Cumplimiento → Casos de denuncia | Etapas del procedimiento de hostigamiento | `grievances` (`etica`) | DEN | Expediente, plazos, medidas de protección; solo el investigador | — |
| Cumplimiento → Casos disciplinarios | — | `grievances` (`etica`) | DIS | Imputación, descargos, decisión | — |
| Cumplimiento → Cumplimiento Perú | Tablero, Expediente de inspección, Sindicatos (Sindicatos, Afiliados, Licencias sindicales, Cláusulas del convenio) | `compliance` (`cumplimiento`) | CUM, SIN | Semáforo con explicación, expediente, casilla | Umbrales legales de ejemplo |
| Cumplimiento → Privacidad | — | `compliance` (`cumplimiento`) | PRI | Consentimientos, solicitudes, incidentes | — |

### Analytics, Automatización y Configuración

| Pantalla | Pestañas | Módulo comercial (id) | Épicas | Real | Simulado |
|---|---|---|---|---|---|
| Analytics → Tableros | — | `analytics` (`analytics`) | ANA, UI | Tableros por rol con widgets | — |
| Analytics → People Analytics | Resumen, Dotación, Tiempo, Costos, Talento y desarrollo, Clima, SST y diversidad | `analytics` (`analytics`) | ANA | Indicadores con filtros y alcance | Calculados sobre datos demo |
| Analytics → Reportes | Predefinidos, Personalizados, Programados, Entregas (simulado); Constructor | `analytics` (`analytics`) | RPT | Constructor, guardar, columnas según permiso | Entregas programadas |
| Analytics → Alertas | — | `analytics` (`analytics`) | ALE | Reglas y alertas con explicación | — |
| Analytics → Asistente | Conversar, Redacción, Bitácora de uso, Política de IA; botón «Buscar en todo» | `ai` (`ia`) | IAR, PLT | Bitácora de usos, política, búsqueda global con permisos | **IA simulada**: respuestas por reglas escritas, sin llamar a ningún servicio |
| Automatización → Aprobaciones | — | `base` (`automatizacion`) | WFL | Flujos por tipo y bandeja de aprobación | — |
| Automatización → Workflows | Editor | `base` (`automatizacion`) | WFL | Constructor CUANDO–SI–ENTONCES | Acciones limitadas (crear tarea, registrar en auditoría) |
| Automatización → Automatizaciones | Reglas, Ejecuciones | `base` (`automatizacion`) | WFL | Centro de automatizaciones con ejecuciones | — |
| Configuración → Centro de configuración | Secciones: Empresas, Marca de la organización, Idioma, Monedas y tipos de cambio, Paquetes de país, Campos personalizados, Seguridad, Configuración de cada módulo | `base`, `white_label` (`plataforma`) | CFG, WLB, SEG, PAI | Datos de empresa, marca básica, campos personalizados | Idiomas en y pt solo preparados; seguridad sin efecto real |
| Configuración → Ayuda y asistente | Asistente, Artículos de la organización, Consultas | `core`, `ai` (`ayuda`) | AYU, IAR | Encender o apagar el asistente, roles habilitados, límite de preguntas y aviso, con auditoría; artículos propios con audiencia, responsable y revisión; consultas, búsquedas sin resultado y valoración | La disponibilidad por plan se toma de la función `ayuda.asistente_ia` de la consola |
| Configuración → Importar datos | Pasos subir, mapear, validar, importar | `core` (`plataforma`) | IMP | Validación por fila y vista previa | El archivo se pega o se usa la plantilla de ejemplo |
| Configuración → Integraciones y API | Conectores, Tokens de API (incluye webhooks) | `integrations` (`plataforma`) | INT, API | Alta de conectores, tokens y webhooks con registro | Pruebas de conexión y entregas: no sale ninguna petición de red |
| Configuración → Papelera | — | `base` (`plataforma`) | PLT | Restaurar registros eliminados | — |
| Configuración → Catálogos | — | `base` (`complementos`) | CFG | Catálogos editables por módulo | — |
| Configuración → Parámetros | — | `base` (`complementos`) | CFG | Parámetros de política con vigencia e historial | — |
| Configuración → Suscripción | — | `base` (`complementos`) | SAS | Plan, módulos y consumo; solicitud de módulo que llega a la consola | Sin cobro |
| Configuración → Marca · Dominio | — | `white_label` (`complementos`) | WLB | Registro del dominio y del remitente con sus estados | Verificación de DNS y certificado simulados |
| Configuración → API · Registro de llamadas | — | `integrations` (`complementos`) | API | Bitácora por token con estado y explorador de llamadas | Llamadas simuladas: no hay API real |
| Configuración → Integraciones · WhatsApp | — | `whatsapp` (`complementos`) | WHA | Configuración del canal, menús y política de entrega de documentos | No se valida el número con el proveedor |

## 2. Consola SaaS

Se entra desde la pantalla de ingreso como «Superadministrador SaaS»; no comparte menú con las organizaciones.

| Pestaña | Módulo comercial | Épicas | Real | Simulado |
|---|---|---|---|---|
| Organizaciones | consola (fuera de módulos) | SAS, TEN | Crear organización, suspender y reactivar; una organización suspendida no puede ingresar | — |
| Tablero | consola | SAS | Indicadores de la plataforma | Cifras comerciales de ejemplo |
| Planes | consola | SAS | Crear, duplicar, activar planes con módulos y límites | — |
| Suscripciones | consola | SAS | Plan y módulos por organización; el menú de la organización cambia | Cobro y estado de cuenta |
| Funciones | consola | SAS | Feature flags por organización, plan y beta | — |
| Soporte y monitoreo | consola | SAS | Tareas fallidas, colas, webhooks fallidos, errores por organización | Datos de salud generados |
| Copias de seguridad | consola | SAS | Historial, política, copia manual | Restauración |
| Socios | consola, `partner` | EST | Alta de estudios contables y vínculo con organizaciones | — |
| Ayuda del producto | consola | AYU | Artículos del producto por módulo, rol y pantalla con versiones; búsquedas sin resultado de todas las organizaciones; consultas de soporte enviadas por administradores | — |
| Solicitudes de módulos | consola | SAS | Solicitudes enviadas desde Configuración → Suscripción; aprobar activa el módulo | — |

## 3. Organizaciones y usuarios demo

Organizaciones: **Grupo Andino (demo)** con todos los módulos y datos sembrados; **Clínica del Sur (demo)** solo con el módulo `tiempo`, para comprobar que el menú y las pestañas se ajustan a lo contratado.

| Usuario demo (Grupo Andino) | Rol | Alcance | Qué ve |
|---|---|---|---|
| Luis Paredes Soto | Administrador empresa | Toda la organización | Todo el menú, configuración, seguridad, automatización; no el contenido de denuncias |
| Carmen Huamán Rojas | RRHH | Toda la organización | Personas, tiempo, talento, experiencia, operaciones, cumplimiento; sin usuarios ni roles |
| Milagros Torres Luna | Payroll | Toda la organización | Nómina completa, sueldos y datos de pago; no aprueba lo que calcula |
| Martín Gutiérrez León | Manager | Su equipo | Inicio de jefe, aprobaciones, su equipo sin sueldos |
| Sofía Navarro Poma | Empleado | Propio | Su ficha, marcación, vacaciones, boletas, firmas, cursos, solicitudes |
| Auditoría Externa | Auditor | Toda la organización, solo lectura | Colaboradores, estructura y auditoría; sin relación laboral |
| Diego Salazar Vega | Recruiter | Toda la organización | Reclutamiento, base de talento, portal de empleos |
| Comité de Ética | Investigador de denuncias | Casos asignados | Solo Casos de denuncia |
| ZK Perú · Soporte de relojes | Proveedor externo (acceso de terceros) | Acceso con vigencia ya vencida | No puede ingresar: sirve para comprobar que el acceso de terceros caduca solo |
| Estudio Contable Rimac & Asociados (ingreso «Estudio contable (socio)») | Estudio contable (externo) | Organizaciones cliente que le delegaron acceso | Cartera de clientes con estado de planilla, entrar a un cliente con el rol delegado, acciones en lote |
| Superadministrador SaaS | Consola | Plataforma | Solo la consola SaaS |

El prototipo define además el rol SST (sin usuario demo). No tiene rol ni usuario demo para Contador; esos permisos se prueban creando un usuario y asignándole el rol en Seguridad.

## 4. Ingreso como estudio contable

| Pantalla | Módulo comercial | Épicas | Real | Simulado |
|---|---|---|---|---|
| Ingreso → Estudio contable (socio) → Cartera | `partner` (`complementos`) | EST | Cartera con estado por cliente, entrada al cliente con el rol delegado, acciones en lote con resultado por cliente, auditoría en el cliente | Las acciones en lote registran el resultado; no generan archivos reales |

## 5. Pantallas especificadas que no existen en el prototipo

Dentro de la aplicación y la consola, ninguna: las pantallas de `docs/modulos/` tienen ruta real en el prototipo final. Lo que sigue siendo simulado está en la columna «Simulado» de cada fila.

El sitio comercial gestiahr.com (M-WEB, pendiente) no está en el prototipo: su diseño de referencia es el prompt de Stitch `docs/web/prompt-stitch-landing-gestiahr.md`. Sus pantallas son Inicio, Página de producto, Suite, Precios, Registro, Agendar demo, Blog, Artículo, Páginas SEO, Partners, Legales y libro de reclamaciones, y la sección «Sitio web» de la consola.
