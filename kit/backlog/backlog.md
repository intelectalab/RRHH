# GestiaHR — Backlog

72 épicas · 362 historias · 150 tareas técnicas. Sin estimaciones de tiempo ni de personas: el equipo las agrega al planificar.

Este archivo se genera con `kit/tools/generar_backlog.py` a partir de `kit/tools/fuente/`. No lo edites a mano: cambia la fuente y vuelve a generar.

## Cómo importar `backlog.csv` en Jira

1. En Jira, crea el proyecto (plantilla Scrum o Kanban de software) y verifica que existan los tipos de incidencia Epic, Story y Task.
2. Crea los componentes Backend, Frontend, Backend + Frontend, Infraestructura, Calidad y Datos, o deja que el importador los cree.
3. Crea un campo personalizado de texto corto llamado «Clave del kit» y agrégalo a las pantallas del proyecto.
4. Ve a Configuración → Sistema → Importación de sistema externo → CSV y sube `backlog.csv` (codificación UTF-8, delimitador coma).
5. Elige el proyecto de destino y asigna las columnas según la tabla de abajo. Marca «Asignar valores de campo» solo en Issue Type si tu Jira usa otros nombres (Historia, Tarea).
6. Ejecuta la importación y revisa el registro: deben crearse tantas incidencias como filas tiene el archivo sin contar la cabecera.
7. Los vínculos «Bloqueada por» se crean al final de la importación; revisa uno al azar (por ejemplo `COL-3`).

| Columna del CSV | Campo de Jira |
|---|---|
| Issue ID | Issue ID (identificador externo; solo se usa para enlazar padres y bloqueos) |
| Issue Type | Issue Type |
| Summary | Summary (Resumen) |
| Description | Description (Descripción) |
| Parent | Parent (Principal); en proyectos antiguos, Epic Link |
| Epic Name | Epic Name (Nombre de la épica) |
| Component | Component/s (Componentes) |
| Labels (cuatro columnas) | Labels (Etiquetas): fase, área, corte comercial (`mvp`, `ola-2` o `web`) y producto de la web (`producto-…`) |
| Clave del kit | Campo personalizado «Clave del kit» |
| Bloqueada por (cinco columnas) | Link «Blocks» en sentido entrante: «is blocked by» |

## Cómo leer cada actividad

- **Clave** (`COL-3`): código de la épica y número. Es la referencia estable que usan la documentación del kit y las dependencias.
- **Tipo**: *Historia* (valor visible para un usuario) o *Tarea técnica* (trabajo interno necesario).
- **Depende de**: actividades que deben estar terminadas antes. Ninguna depende de una fase posterior; la épica QA es transversal y puede depender de cualquiera.
- **Descripción**: qué construir.
- **Criterios de aceptación**: lo que se verifica para darla por terminada, además de la definición de «hecho» general (QA-1).
- **Referencias**: documentos del kit, reglas de negocio de la épica («RN-…» en `docs/06`), máquina de estados («ME-nn» en `docs/05`), punto del pedido original («Pedido §N»), corrección («C-xx») o ampliación («E-xx») de `docs/00-especificacion-maestra.md`, y pantalla real del prototipo (según `docs/17-mapa-del-prototipo.md`).
- **Componente**: va en la línea de la épica; una actividad lo indica solo cuando difiere del de su épica.
- **Etiquetas** (en el CSV y el JSON): fase (`fase-0-cimientos` … `fase-9-ecosistema`, `transversal`), módulo comercial (`modulo-…`) `mvp`, `ola-2` o `web` según el corte, y el producto de la web (`producto-personas`, `producto-tiempo`, `producto-planilla`, `producto-talento`, `producto-formacion`, `producto-suite` o `producto-plataforma`; `docs/01` §4.1). En Jira: `labels = producto-tiempo`.
- **MVP**: las actividades marcadas «· MVP» forman el primer corte comercial (plataforma, Core HR, vacaciones y ausencias, nómina Perú del régimen general y MYPE). Ninguna depende de algo fuera del MVP. Filtra en Jira con `labels = mvp`.
- **Ola 2**: las marcadas «· Ola 2» son lo primero que se construye después del MVP (WhatsApp básico, simulador público de costo laboral y modo estudio contable). Solo dependen del MVP o de la propia ola. En Jira: `labels = ola-2`.
- **Sitio web**: las marcadas «· Sitio web» (épica WEB) son el sitio comercial gestiahr.com: productos, precios, registro y suscripción, blog, SEO y chatbot. Están pendientes y separadas del MVP; solo dependen del MVP o del propio sitio, así que pueden construirse en paralelo o justo antes de abrir la venta. En Jira: `labels = web`.

## Orden de trabajo

Las fases 0 y 1 son requisito de todo. La 2 va antes de la 3 (la planilla consume asistencia y ausencias), salvo en el primer corte comercial: las actividades con etiqueta `mvp` solo necesitan vacaciones y ausencias, porque la planilla lee asistencia y horas extra por puertos y, sin esos módulos, usa las incidencias del periodo (`docs/01` §5.1). Las fases 4 a 7 no dependen entre sí en lo funcional y pueden reordenarse según prioridad comercial, respetando las dependencias indicadas. La 8 necesita datos de las anteriores.

| Fase | Épicas |
|---|---|
| Fase 0 · Cimientos | PLT Plataforma técnica · TEN Multiempresa y aislamiento · SEG Identidad, roles, permisos por campo y alcance · SAS Consola SaaS · AUD Auditoría · UI Sistema de diseño y armazón · WFL Motor de aprobaciones y workflows · NOT Notificaciones y mensajería · CFG Configuración, campos personalizados, i18n y multimoneda · WEB Sitio comercial gestiahr.com |
| Fase 1 · Core HR | ORG Estructura organizacional · POS Posiciones y headcount · COL Colaboradores y ficha 360 · HIS Historia con vigencia · CON Contratos y adendas · DOC Gestión documental, plantillas y firma · ESS Portal del colaborador · MSS Portal del manager · IMP Importador y puesta en marcha · AYU Centro de ayuda |
| Fase 2 · Tiempo | ASI Asistencia y marcación · TUR Horarios, jornadas y turnos · VAC Vacaciones · AUS Ausencias y permisos · BHO Horas extra y bolsa de horas · TSH Hojas de tiempo por proyecto |
| Fase 3 · Nómina Perú | NOM Motor de nómina · CPT Conceptos y fórmulas · PAR Parámetros legales con vigencia · PER Periodos, incidencias, cálculo, simulación y cierre · BOL Boletas de pago · BSO Beneficios sociales · PRE Préstamos, adelantos y adelanto de sueldo · DEC Declaraciones y archivos · PRV Provisiones y asientos contables · LIQ Liquidación de beneficios sociales · LOC Locadores y cuarta categoría |
| Fase 4 · Talento | ATS Reclutamiento, portal de empleos y base de talento · ENT Entrevistas y ofertas · ONB Pre-onboarding y onboarding · OFF Offboarding · DES Evaluación de desempeño · OKR Objetivos · CMP Competencias y habilidades · FBK Reuniones 1:1 y feedback |
| Fase 5 · Desarrollo y compensación | LMS Aprendizaje y plan anual · CAR Desarrollo, carrera, sucesión y 9-box · COM Compensaciones, bandas, revisión salarial e igualdad salarial · BNF Beneficios |
| Fase 6 · Experiencia | ENC Encuestas, clima y eNPS · REC Reconocimientos · CIN Comunicación interna · HSD Mesa de ayuda y base de conocimiento |
| Fase 7 · Operación y cumplimiento | SST Seguridad y salud en el trabajo · DEN Canal de denuncias y hostigamiento · DIS Casos disciplinarios · ACT Activos, EPP y uniformes · GAS Gastos, viáticos y reembolsos · CUM Cumplimiento Perú y SUNAFIL · PRI Privacidad y retención · SIN Sindicatos y convenios |
| Fase 8 · Inteligencia | ANA People analytics y tableros · RPT Reportes y constructor · ALE Alertas explicadas · IAR Asistente de IA y gobierno |
| Fase 9 · Ecosistema y expansión | API API pública, webhooks y MCP · INT Integraciones · WHA Autoservicio por WhatsApp · PAI Paquetes de país · WLB Marca blanca · EST Modo estudio contable |
| Transversal · Calidad | QA Calidad, seguridad y salida a producción |

# Fase 0 · Cimientos

## PLT · Plataforma técnica

Base técnica común: repositorio, entornos, contratos de API, colas, archivos, errores y observabilidad sobre la que se construyen todos los módulos.

*Componente: Infraestructura*

### PLT-1 · Monorepo y estructura de aplicaciones

*Tarea técnica* · MVP · sin dependencias

Crear el monorepo con `apps/api` (Laravel), `apps/web` (Next.js) y `packages/contracts`. Definir la estructura interna de la API por módulos de dominio (una carpeta por código de épica) con límites explícitos: un módulo solo usa a otro por su servicio público o por eventos. Incluir linters, formateadores y análisis estático configurados.

- [ ] El repositorio se instala y arranca con un único comando documentado en el README
- [ ] Una prueba de arquitectura falla si un módulo importa clases internas de otro
- [ ] Linter, formateador y análisis estático corren sin errores sobre el esqueleto

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md · Pedido §117 · Pedido §120

### PLT-2 · Entorno local con Docker

*Tarea técnica* · MVP · depende de PLT-1

Preparar el entorno local con contenedores para API, web, PostgreSQL, Redis, almacenamiento compatible con S3, servidor de correo de pruebas y Horizon. Incluir semillas mínimas y un comando para reiniciar la base.

- [ ] `docker compose up` deja la API, la web y los servicios de apoyo respondiendo
- [ ] Los correos enviados en local se ven en el buzón de pruebas, nunca salen a internet
- [ ] Existe un comando que recrea la base y carga las semillas

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md

### PLT-3 · Integración continua

*Tarea técnica* · MVP · depende de PLT-1

Configurar la canalización de integración continua: instalación, linters, análisis estático, pruebas de API y de web, verificación de contratos y construcción de imágenes. Las ramas principales quedan protegidas y exigen la canalización en verde.

- [ ] Cada solicitud de cambio ejecuta todas las etapas y bloquea la fusión si alguna falla
- [ ] La canalización falla si el contrato OpenAPI y los tipos generados están desincronizados
- [ ] Se publica el informe de cobertura de pruebas como artefacto
- [ ] La canalización carga `kit/database/schema.sql` y corre `pruebas_aislamiento.sql` en PostgreSQL 17, y valida los diagramas mermaid de `kit/docs/`

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md

### PLT-4 · Formato único de errores y validación en servidor

*Tarea técnica* · Backend · MVP · depende de PLT-1

Implementar un formato único de error (`application/problem+json`) con código estable, mensaje traducible y detalle por campo. Toda entrada se valida en el backend con reglas declaradas; la interfaz solo replica validaciones para dar respuesta inmediata.

- [ ] Toda respuesta de error de la API cumple el mismo esquema, incluidos 401, 403, 404, 409, 422 y 429
- [ ] Un envío que salta la validación de la interfaz es rechazado por el backend con detalle por campo
- [ ] Los códigos de error están catalogados y cada uno tiene mensaje en es, en y pt

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/08-api.md · docs/02-arquitectura.md · Pedido §100 · RN-PLT-01 a RN-PLT-10

### PLT-5 · Contratos OpenAPI y tipos compartidos

*Tarea técnica* · Backend · MVP · depende de PLT-1, PLT-4

Mantener la especificación OpenAPI de `/api/v1` como contrato: cada endpoint se documenta con esquemas de entrada, salida y errores. Generar desde ella los tipos TypeScript de `packages/contracts` y un cliente tipado para la web.

- [ ] Las pruebas validan que cada respuesta real cumple el esquema publicado
- [ ] La web no declara a mano ningún tipo de respuesta: los importa de `packages/contracts`
- [ ] La documentación navegable de la API se publica en los entornos de prueba

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §117 · Pedido §73 · RN-PLT-01 a RN-PLT-10

### PLT-6 · Colas, trabajos programados y outbox de eventos de dominio

*Tarea técnica* · Backend · MVP · depende de PLT-1, PLT-2

Configurar colas con Horizon (prioridades, reintentos, trabajos fallidos) y el planificador. Implementar el outbox: los eventos de dominio (`employee.hired`, `leave.approved`, `payroll.closed`, etc.) se guardan en la misma transacción que el cambio y un despachador los entrega a los suscriptores internos y a los efectos externos.

- [ ] Un evento solo se publica si la transacción que lo originó se confirmó
- [ ] Un suscriptor que falla se reintenta sin duplicar efectos y termina en la lista de fallidos con su causa
- [ ] Existe un catálogo de eventos con su carga útil versionada

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §103 · Pedido §101 · RN-PLT-01 a RN-PLT-10

### PLT-7 · Servicio de archivos

*Tarea técnica* · Backend · MVP · depende de PLT-1, PLT-2

Construir el servicio único de archivos sobre almacenamiento compatible con S3: carga con validación de tipo y tamaño, análisis antivirus, cifrado en reposo, rutas separadas por organización y descarga solo mediante URL firmada de corta vigencia emitida tras verificar permisos. Los módulos nunca guardan archivos por su cuenta: usan este servicio y reciben un identificador.

- [ ] Ningún archivo es accesible por URL pública ni por una URL firmada vencida
- [ ] La carga rechaza tipos no permitidos y archivos que superan el límite configurado
- [ ] Cada descarga queda registrada con usuario, archivo y momento

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §60 · Pedido §19 · RN-PLT-01 a RN-PLT-10

### PLT-8 · Idempotencia y acciones de estado

*Tarea técnica* · Backend · MVP · depende de PLT-4

Implementar el soporte de `Idempotency-Key` en los endpoints que crean o ejecutan efectos, y la base para acciones de estado (`POST /recurso/{id}/accion`) que validan la máquina de estados declarada del recurso y usan bloqueo optimista por versión. Ambas piezas se entregan como componentes compartidos que los módulos declaran, no reimplementan.

- [ ] Repetir una petición con la misma clave devuelve el mismo resultado sin duplicar el efecto
- [ ] Una transición no declarada en la máquina de estados responde 409 con el estado actual
- [ ] Dos ediciones simultáneas del mismo registro no se pisan: la segunda recibe conflicto de versión

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/08-api.md · docs/02-arquitectura.md · RN-PLT-01 a RN-PLT-10

### PLT-9 · Entornos y despliegue

*Tarea técnica* · MVP · depende de PLT-3

Definir los entornos (desarrollo, pruebas, preproducción, producción) como infraestructura declarada en código, con despliegue automatizado, migraciones sin corte, gestión de secretos y reversión. Cada entorno usa su propia base, almacenamiento y credenciales, y los datos de producción no se copian a entornos inferiores sin anonimizar.

- [ ] El despliegue a cada entorno se hace desde la canalización, sin pasos manuales en servidores
- [ ] Ningún secreto vive en el repositorio; se inyectan desde el gestor de secretos
- [ ] Existe un procedimiento probado de reversión de versión

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md

### PLT-10 · Observabilidad

*Tarea técnica* · MVP · depende de PLT-9

Instrumentar logs técnicos estructurados con identificador de correlación y de organización, métricas de API, colas y base de datos, trazas y captura de errores con alertas. Los logs técnicos no contienen datos personales ni sueldos.

- [ ] Cada petición puede seguirse de punta a punta por su identificador de correlación
- [ ] Existen tableros de latencia, errores, colas y trabajos fallidos, con alertas configuradas
- [ ] Una prueba verifica que los campos sensibles se enmascaran en los logs

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md · Pedido §110 · Pedido §111

### PLT-11 · Rendimiento de base: paginación, índices y caché

*Tarea técnica* · Backend · MVP · depende de PLT-5

Establecer las convenciones de rendimiento: paginación en servidor por cursor, filtros y ordenamiento declarados, carga diferida, índices por organización, caché con claves por organización y prevención de consultas N+1 detectada en pruebas. Las convenciones se documentan en la arquitectura y se verifican con pruebas automáticas.

- [ ] Ningún listado devuelve la colección completa: todos paginan en servidor
- [ ] Las pruebas fallan si un endpoint supera el número de consultas declarado
- [ ] La caché nunca comparte claves entre organizaciones

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · docs/08-api.md · Pedido §101 · RN-PLT-01 a RN-PLT-10

### PLT-12 · Convenciones del modelo de datos y migraciones

*Tarea técnica* · Datos · MVP · depende de PLT-1

Fijar las convenciones del modelo: nombres, claves, columnas de auditoría, `tenant_id` obligatorio, soft delete, vigencias, montos con moneda y fechas con zona horaria. Entregar el esqueleto de migraciones y un verificador que revisa cada tabla nueva contra las convenciones.

- [ ] El verificador falla si una tabla de negocio no tiene `tenant_id`, política RLS y claves compuestas
- [ ] Ningún borrado en cascada destruye historia laboral
- [ ] El diccionario de datos se genera desde el esquema

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §102 · Pedido §104 · RN-PLT-01 a RN-PLT-10

### PLT-13 · Adaptadores de integración con implementación falsa

*Tarea técnica* · Backend · MVP · depende de PLT-6

Definir el patrón de adaptador para toda integración externa (correo, mensajería, firma, bancos, biométricos, IA): interfaz, implementación falsa determinista para desarrollo y pruebas, registro de llamadas y selección por configuración de la organización. Las implementaciones reales se agregan después sin tocar el dominio.

- [ ] Ningún módulo llama a un proveedor externo fuera de un adaptador
- [ ] Todos los entornos que no son producción usan por defecto las implementaciones falsas
- [ ] Las llamadas salientes quedan registradas con resultado y tiempos de respuesta

Referencias: docs/modulos/M-PLT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §75 · RN-PLT-01 a RN-PLT-10

### PLT-14 · Guía de trabajo del repositorio

*Tarea técnica* · Calidad · MVP · depende de PLT-1

Redactar la guía de trabajo que acompaña al código: orden de construcción por fases, definición de «hecho» por actividad (entidades, permisos, servicios, backend, interfaz, datos reales, pruebas, responsive), cómo evaluar código existente antes de reemplazarlo (funciona, mejorable, parcial, duplicado, obsoleto) y cómo continuar con el siguiente paso sin pedir confirmación salvo decisiones críticas. La guía es breve y se mantiene junto al código, de modo que también la lean los asistentes de programación.

- [ ] La guía está en el repositorio y la plantilla de solicitud de cambio enlaza su lista de verificación
- [ ] Incluye la regla de no eliminar funcionalidad existente sin justificación técnica escrita
- [ ] Incluye el registro de decisiones de arquitectura con su formato

Referencias: docs/modulos/M-PLT.md · docs/02-arquitectura.md · Pedido §120 · Pedido §121 · Pedido §122 · Pedido §123

## TEN · Multiempresa y aislamiento

Aislamiento total entre organizaciones con `tenant_id`, RLS y claves compuestas, y jerarquía organización → empresas.

*Componente: Backend*

### TEN-1 · Contexto de organización en cada petición

*Tarea técnica* · MVP · depende de PLT-1, PLT-12

Resolver la organización activa a partir de la sesión o del token y fijarla en la conexión de base de datos (`SET app.tenant_id`) y en un ámbito global de los modelos. Los procesos en segundo plano reciben y restauran el mismo contexto.

- [ ] Ninguna consulta de negocio se ejecuta sin contexto de organización: sin él, falla
- [ ] Los trabajos en cola se ejecutan con la organización que los originó
- [ ] Un usuario con acceso a varias organizaciones cambia de una a otra sin mezclar datos

Referencias: docs/modulos/M-TEN.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · docs/04-modelo-de-datos.md · Pedido §1 · RN-TEN-01 a RN-TEN-07

### TEN-2 · Políticas RLS en PostgreSQL

*Tarea técnica* · Datos · MVP · depende de TEN-1

Activar seguridad a nivel de fila en todas las tablas de negocio con una política que compara `tenant_id` con el contexto de la conexión. El rol de base de datos de la aplicación no puede saltarse RLS; solo un rol separado de mantenimiento lo hace.

- [ ] Una consulta SQL directa con el rol de la aplicación y otra organización en contexto devuelve cero filas
- [ ] Insertar una fila con `tenant_id` distinto al contexto es rechazado por la base
- [ ] El verificador de esquema lista cualquier tabla sin política

Referencias: docs/modulos/M-TEN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/02-arquitectura.md · Pedido §1 · RN-TEN-01 a RN-TEN-07

### TEN-3 · Claves foráneas compuestas por organización

*Tarea técnica* · Datos · MVP · depende de TEN-2

Usar claves foráneas compuestas `(tenant_id, id)` para que ninguna fila pueda referenciar un registro de otra organización, incluso ante un error de aplicación. Las claves únicas de negocio (documento, código de colaborador, RUC) también se definen por organización.

- [ ] Intentar enlazar un registro con otro de distinta organización falla en la base de datos
- [ ] Todas las relaciones entre tablas de negocio incluyen `tenant_id`

Referencias: docs/modulos/M-TEN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §104 · RN-TEN-01 a RN-TEN-07

### TEN-4 · Prueba automática de aislamiento sobre todos los endpoints

*Tarea técnica* · Calidad · MVP · depende de TEN-2, PLT-5, PLT-3

Construir una prueba que recorre todos los endpoints del contrato OpenAPI con dos organizaciones sembradas e intenta leer, modificar, listar y buscar recursos de la otra. Se ejecuta en la integración continua y crece sola al agregarse endpoints.

- [ ] Todo acceso cruzado responde 404 y no revela existencia del recurso
- [ ] Un endpoint nuevo queda cubierto sin escribir una prueba específica
- [ ] La canalización falla si un endpoint no está cubierto por la prueba

Referencias: docs/modulos/M-TEN.md · docs/02-arquitectura.md · docs/08-api.md · Pedido §1

### TEN-5 · Organización con varias empresas y selector de empresa

*Historia* · Backend + Frontend · MVP · depende de TEN-1

Modelar organización (tenant) → empresas (razones sociales) y permitir que el usuario filtre el trabajo por una empresa o vea el grupo completo según su alcance. El selector de empresa de la barra superior afecta a todos los listados.

- [ ] Un grupo con tres empresas ve datos consolidados o por empresa según el selector
- [ ] Un usuario con alcance sobre una sola empresa no ve las demás en el selector
- [ ] El filtro de empresa se aplica en el backend, no solo en la interfaz

Referencias: docs/modulos/M-TEN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §1 · RN-TEN-01 a RN-TEN-07

### TEN-6 · Aislamiento en caché, colas, archivos, búsqueda y exportaciones

*Tarea técnica* · MVP · depende de TEN-1, PLT-6, PLT-7

Extender el aislamiento fuera de la base de datos: prefijos por organización en caché y almacenamiento, contexto en trabajos, índices de búsqueda filtrados y exportaciones generadas dentro del contexto. Todo componente nuevo de infraestructura debe declarar cómo separa los datos por organización.

- [ ] La prueba de aislamiento incluye búsqueda global, descargas y exportaciones
- [ ] Un trabajo sin organización declarada no se encola

Referencias: docs/modulos/M-TEN.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §1 · RN-TEN-01 a RN-TEN-07

## SEG · Identidad, roles, permisos por campo y alcance

Ingreso con correo o documento de identidad, MFA, sesiones, roles personalizables, permisos `dominio.recurso.accion`, permisos por campo y alcance.

*Componente: Backend + Frontend*

### SEG-1 · Ingreso con correo y contraseña

*Historia* · MVP · depende de TEN-1, PLT-4

Implementar el ingreso con correo y contraseña, recuperación de clave por enlace de un solo uso y cierre de sesión. Las sesiones de la web usan cookies seguras; no se guardan tokens en almacenamiento del navegador.

- [ ] Las contraseñas se almacenan con algoritmo de derivación robusto y nunca se registran en logs
- [ ] El enlace de recuperación caduca y es de un solo uso
- [ ] Los mensajes de error no revelan si el correo existe

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · docs/08-api.md · Pedido §60 · RN-SEG-01 a RN-SEG-14

### SEG-2 · Ingreso con documento de identidad y clave u OTP

*Historia* · MVP · depende de SEG-1, PLT-13

Permitir que el personal sin correo corporativo ingrese con tipo y número de documento más clave, o con un código de un solo uso enviado al celular registrado. La activación inicial se hace con un código entregado por RRHH o por mensaje.

- [ ] Un colaborador sin correo activa su cuenta e ingresa solo con su documento
- [ ] El correo no es obligatorio en ningún formulario de persona ni de usuario
- [ ] Los códigos OTP caducan, son de un solo uso y tienen tope de intentos

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · C-09 · Pedido §60 · RN-SEG-01 a RN-SEG-14

### SEG-3 · Segundo factor (MFA)

*Historia* · MVP · depende de SEG-1

Agregar segundo factor con aplicación autenticadora (TOTP) y códigos de respaldo. La organización puede exigirlo por rol (por ejemplo a Payroll y administradores) y la consola SaaS lo exige siempre.

- [ ] Un rol marcado con MFA obligatorio no puede operar sin configurarlo
- [ ] Los códigos de respaldo se muestran una sola vez y se invalidan al usarse
- [ ] Desactivar MFA exige reautenticación y queda auditado

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §60 · RN-SEG-01 a RN-SEG-14

### SEG-4 · Sesiones y dispositivos

*Historia* · MVP · depende de SEG-1

Gestionar sesiones activas: duración, cierre por inactividad configurable, listado de dispositivos del usuario con cierre remoto y cierre global al cambiar la contraseña. La duración y la inactividad máxima se configuran por organización dentro de los márgenes que fija la plataforma.

- [ ] El usuario ve sus sesiones abiertas y puede cerrar cualquiera
- [ ] Al cambiar la contraseña se cierran las demás sesiones
- [ ] Un administrador puede forzar el cierre de sesión de un usuario

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §60 · RN-SEG-01 a RN-SEG-14

### SEG-5 · Políticas de contraseña, bloqueo y límite de peticiones

*Tarea técnica* · Backend · MVP · depende de SEG-1

Implementar políticas de contraseña configurables por organización (longitud, historial, vencimiento opcional), bloqueo temporal tras intentos fallidos y límite de peticiones por usuario, IP y token. Los intentos fallidos y los bloqueos se registran en la actividad del usuario.

- [ ] Superar el tope de intentos bloquea la cuenta y notifica al usuario
- [ ] Superar el límite de peticiones responde 429 con el formato único de error
- [ ] Las políticas se editan desde Configuración → Seguridad y quedan auditadas

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §60 · RN-SEG-01 a RN-SEG-14 · Prototipo: Configuración → Centro de configuración → Seguridad

### SEG-6 · Usuarios e invitaciones

*Historia* · MVP · depende de SEG-1

Administrar usuarios como entidad de acceso separada de la persona y de la relación laboral: invitar, activar, suspender, asignar roles y vincular con una persona. Un usuario puede existir sin relación laboral (un auditor externo) y una persona sin usuario.

- [ ] Suspender un usuario no altera la ficha ni la historia del colaborador
- [ ] El cese de una relación laboral no elimina al usuario si conserva otra relación activa
- [ ] La invitación caduca y puede reenviarse

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/07-permisos-y-roles.md · docs/04-modelo-de-datos.md · C-01 · Pedido §104 · ME-02 · RN-SEG-01 a RN-SEG-14 · Prototipo: Seguridad → Usuarios

### SEG-7 · Roles del sistema y roles personalizados

*Historia* · MVP · depende de SEG-6

Sembrar los roles del sistema (Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, Auditor, SST, Investigador de denuncias, Contador) y permitir crear, duplicar y editar roles personalizados. Un usuario puede tener varios roles con alcances distintos.

- [ ] Los roles del sistema no se eliminan, pero pueden duplicarse y ajustarse
- [ ] Un rol personalizado se crea marcando permisos en una matriz por módulo
- [ ] El cambio de permisos de un rol se aplica de inmediato y queda auditado

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §61 · RN-SEG-01 a RN-SEG-14 · Prototipo: Seguridad → Roles y permisos

### SEG-8 · Catálogo de permisos `dominio.recurso.accion`

*Tarea técnica* · Backend · MVP · depende de SEG-7

Definir el registro central de permisos en el que cada módulo declara los suyos con códigos en inglés alineados con la API (`people.employments.salary.view`, `payroll.periods.close`) y etiqueta traducida en la pantalla de roles y un mecanismo único de autorización (políticas) que todos los endpoints usan. Lo que la interfaz oculta, el backend lo valida.

- [ ] Todo endpoint declara el permiso que exige; una prueba falla si alguno no lo hace
- [ ] La API de sesión entrega al cliente los permisos efectivos para adaptar menús y botones
- [ ] Llamar a un endpoint sin permiso responde 403 aunque el botón esté oculto

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · docs/08-api.md · Pedido §62 · RN-SEG-01 a RN-SEG-14

### SEG-9 · Permisos por campo sensible

*Historia* · MVP · depende de SEG-8

Marcar campos como sensibles (sueldo, cuenta bancaria, salud, sanciones, afiliación sindical) y exigir un permiso específico para verlos y otro para editarlos. La API omite o enmascara el campo en respuestas, listados, búsquedas, exportaciones y reportes.

- [ ] Se puede crear un administrador que gestiona colaboradores y no ve sueldos
- [ ] Un campo sin permiso no viaja en la respuesta de la API ni aparece en exportaciones
- [ ] La prueba automática recorre los campos sensibles con un rol sin permiso

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · C-10 · Pedido §105 · Pedido §62 · RN-SEG-01 a RN-SEG-14 · Prototipo: Seguridad → Roles y permisos

### SEG-10 · Alcance de datos

*Historia* · MVP · depende de SEG-8, TEN-5

Aplicar alcance a cada asignación de rol: toda la organización, empresa, sede, unidad, equipo (subordinados directos e indirectos) o propio. El alcance se resuelve en el backend como filtro obligatorio de las consultas de colaboradores y de todo lo que cuelga de ellos.

- [ ] Un manager solo ve y aprueba lo de quienes le reportan directa o indirectamente
- [ ] Un RRHH con alcance de sede no ve colaboradores de otra sede en listados, búsqueda ni reportes
- [ ] El alcance se recalcula cuando cambia la estructura o el jefe

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · C-10 · Pedido §60 · RN-SEG-01 a RN-SEG-14

### SEG-11 · Cifrado de datos sensibles y registro de acceso

*Tarea técnica* · Backend · MVP · depende de SEG-9, PLT-12

Cifrar a nivel de campo los datos sensibles con claves gestionadas fuera de la base y registrar cada lectura de esos campos (quién, qué registro, cuándo). El registro de accesos se guarda aparte de la auditoría de cambios y alimenta al módulo de privacidad.

- [ ] Los campos sensibles no son legibles en un volcado de la base
- [ ] Consultar el sueldo o la salud de un colaborador deja un registro de acceso consultable
- [ ] La rotación de claves está documentada y probada

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · docs/02-arquitectura.md · Pedido §60 · Pedido §105 · RN-SEG-01 a RN-SEG-14

### SEG-12 · Control de descargas y exportaciones

*Historia* · MVP · depende de SEG-9, PLT-7

Controlar las descargas de documentos y exportaciones con permiso propio, marca de agua opcional con el nombre del usuario y registro de cada descarga. La organización puede restringir exportaciones masivas a ciertos roles.

- [ ] Exportar un listado respeta permisos por campo y alcance
- [ ] Cada descarga masiva queda registrada con filtros usados y número de filas
- [ ] Un rol sin permiso de exportación no ve el botón y recibe 403 si llama al endpoint

Referencias: docs/modulos/M-SEG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §60 · Pedido §72 · RN-SEG-01 a RN-SEG-14

## SAS · Consola SaaS

Consola del propietario del SaaS, separada de la aplicación del cliente: organizaciones, planes, módulos, límites, feature flags, soporte y salud.

*Componente: Backend + Frontend*

### SAS-1 · Consola separada para el superadministrador

*Historia* · MVP · depende de SEG-3, TEN-1

Construir la consola SaaS como aplicación aparte, con su propio ingreso, MFA obligatorio y usuarios internos que no pertenecen a ninguna organización cliente. Desde la consola no se accede a datos de RRHH salvo por el acceso de soporte auditado.

- [ ] Un usuario de una organización cliente no puede ingresar a la consola ni ver sus rutas
- [ ] Los usuarios de la consola tienen roles internos (propietario, soporte, finanzas)
- [ ] Toda acción de la consola queda en una auditoría propia

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · docs/07-permisos-y-roles.md · Pedido §2 · C-04 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Tablero

### SAS-2 · Organizaciones: alta, suspensión y bloqueo

*Historia* · MVP · depende de SAS-1

Administrar organizaciones: crear con su primer administrador, editar datos, suspender, bloquear por falta de pago, reactivar y dar de baja con periodo de retención. Cada cambio de estado sigue una máquina de estados y notifica al cliente.

- [ ] Una organización suspendida no puede ingresar y ve un mensaje claro
- [ ] La baja no borra datos de inmediato: aplica la política de retención
- [ ] La ficha de la organización muestra empresas, usuarios, colaboradores y estado de cuenta

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §2 · ME-40 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Organizaciones

### SAS-3 · Planes comerciales

*Historia* · MVP · depende de SAS-1

Crear, editar, duplicar y activar o desactivar planes con nombre libre, precio, moneda, ciclo mensual o anual, límites, módulos y funcionalidades incluidas. Los planes se versionan para que un cambio no altere a quienes ya lo contrataron.

- [ ] Los nombres de plan no están fijos en el código
- [ ] Duplicar un plan copia módulos, límites y funcionalidades
- [ ] Editar un plan en uso crea una versión nueva y conserva la anterior para sus suscriptores

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §3 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Planes

### SAS-4 · Suscripciones, complementos y pruebas gratuitas

*Historia* · MVP · depende de SAS-2, SAS-3

Gestionar la suscripción de cada organización: plan, complementos (add-ons), periodo de prueba con fecha de fin, cambio de plan, renovación y cancelación. La suscripción es la fuente de lo que la organización puede usar.

- [ ] Al vencer una prueba sin conversión, la organización pasa a solo lectura
- [ ] Agregar un complemento habilita su módulo sin reiniciar ni migrar
- [ ] El historial de suscripción muestra cada cambio con autor

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §2 · Pedido §3 · ME-40 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Suscripciones

### SAS-5 · Módulos por organización

*Tarea técnica* · Backend · MVP · depende de SAS-4, SEG-8

Implementar el control de módulos contratados: cada ruta, endpoint, trabajo y elemento de menú declara su módulo comercial y se desactiva si la organización no lo tiene. Activar un módulo no requiere migrar datos ni desplegar otra versión.

- [ ] Un endpoint de un módulo no contratado responde 403 con código «módulo no contratado»
- [ ] El menú solo muestra módulos activos y permitidos
- [ ] Una organización con solo Core HR y Vacaciones activa Asistencia y conserva todos sus datos

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · docs/08-api.md · Pedido §118 · Pedido §124 · C-04 · RN-SAS-01 a RN-SAS-12

### SAS-6 · Límites y consumo

*Historia* · MVP · depende de SAS-5, PLT-7

Medir y aplicar límites por organización: colaboradores activos, administradores, almacenamiento y llamadas de API. Mostrar el consumo al cliente y a la consola, con avisos antes de alcanzar el tope.

- [ ] Superar el límite de colaboradores impide nuevas altas con un mensaje que explica cómo ampliarlo
- [ ] El consumo de almacenamiento se actualiza con cada carga y borrado
- [ ] La consola ve consumo actual e histórico por organización

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §2 · Pedido §3 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Suscripciones

### SAS-7 · Feature flags

*Historia* · MVP · depende de SAS-5

Implementar banderas de funcionalidad evaluables por organización, plan, país y grupo beta, administradas desde la consola. El backend y la web consultan la misma evaluación.

- [ ] Una función puede activarse solo para una organización o para un país
- [ ] El cambio de una bandera surte efecto sin desplegar
- [ ] Cada cambio de bandera queda auditado

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §112 · C-04 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Funciones

### SAS-8 · Acceso de soporte auditado

*Historia* · MVP · depende de SAS-2, AUD-1

Permitir que soporte ingrese a una organización solo con autorización vigente del cliente o con un motivo registrado, por tiempo limitado, con cabecera visible «sesión de soporte» y sin acceso a campos sensibles salvo permiso expreso. El cliente puede desactivar por completo el acceso de soporte desde su configuración de seguridad.

- [ ] Toda sesión de soporte queda registrada con motivo, inicio, fin y acciones realizadas
- [ ] El administrador del cliente ve en su auditoría los accesos de soporte
- [ ] La sesión de soporte caduca sola y no puede ver sueldos ni denuncias por defecto

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §2 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Soporte y monitoreo

### SAS-9 · Salud y monitoreo de la plataforma

*Historia* · MVP · depende de SAS-1, PLT-10

Mostrar en la consola el estado operativo: errores, trabajos fallidos, colas, uso de API, webhooks fallidos, almacenamiento e integraciones, con filtro por organización y reintento de trabajos. Los datos provienen de la observabilidad de la plataforma, sin consultar información de RRHH de los clientes.

- [ ] La consola lista los trabajos fallidos y permite reintentarlos
- [ ] Se ve el uso de API y los errores por organización
- [ ] Existe una página de estado de los componentes

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §111 · Pedido §2 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Soporte y monitoreo

### SAS-10 · Tablero del negocio SaaS

*Historia* · MVP · depende de SAS-4, SAS-6

Construir el tablero de la consola con organizaciones activas, nuevas y suspendidas, usuarios, colaboradores administrados, ingresos recurrentes mensuales y anuales, cancelaciones, pruebas, conversiones, almacenamiento, módulos más usados e incidencias. Los montos se expresan en la moneda de cada plan y se consolidan con el tipo de cambio vigente.

- [ ] Cada indicador se calcula desde suscripciones y consumo reales, con su definición visible
- [ ] Los indicadores se filtran por periodo y por plan

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · Pedido §2 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Tablero

### SAS-11 · Facturación de la suscripción y estado de cuenta

*Historia* · MVP · depende de SAS-4, PLT-13

Registrar los cargos de cada suscripción, comprobantes, pagos y saldos por organización detrás de un adaptador de cobro. Cada comprobante se emite como comprobante electrónico ante la administración tributaria a través de un adaptador de facturación (OSE o PSE), con el impuesto general a las ventas como parámetro con vigencia. Incluir el estado de cuenta visible para el cliente y la regla de bloqueo por mora configurable.

- [ ] La organización ve su plan, sus cargos y sus comprobantes electrónicos con su constancia de aceptación
- [ ] Un comprobante rechazado por la administración tributaria queda observado y no se da por emitido
- [ ] El bloqueo por mora avisa antes y sigue la regla configurada en la consola
- [ ] Los adaptadores de cobro y de facturación tienen implementación falsa para pruebas

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §2 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Suscripciones

### SAS-12 · Copias de seguridad y restauración

*Historia* · MVP · depende de SAS-1, PLT-9

Administrar desde la consola las copias automáticas y manuales, su historial, retención y estado, y la restauración segura hacia un entorno aislado para verificar antes de aplicar. Incluir la exportación completa de los datos de una organización.

- [ ] Se ve el historial de copias con resultado y tamaño
- [ ] Una restauración de prueba se ejecuta y verifica de forma periódica y deja evidencia
- [ ] Restaurar exige doble confirmación de dos usuarios de la consola y queda auditado

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §89 · RN-SAS-01 a RN-SAS-12 · Prototipo: Consola de la plataforma → Copias de seguridad

### SAS-13 · Configuración global y registros de la consola

*Historia* · MVP · depende de SAS-1, AUD-1

Centralizar la configuración global del SaaS (parámetros por defecto de nuevas organizaciones, países habilitados, plantillas base, avisos de mantenimiento) y los registros consultables de la consola: auditoría, actividad y logs técnicos por separado. Cada registro se consulta con filtros por organización, usuario y fecha.

- [ ] Un aviso de mantenimiento se muestra a todas las organizaciones o a las seleccionadas
- [ ] Las nuevas organizaciones nacen con la configuración por defecto vigente

Referencias: docs/modulos/M-SAS.md · docs/06-reglas-de-negocio.md · Pedido §2 · Pedido §110 · RN-SAS-01 a RN-SAS-12

## AUD · Auditoría

Auditoría de negocio de solo inserción con antes y después, separada de la actividad de usuario y de los logs técnicos.

*Componente: Backend + Frontend*

### AUD-1 · Bitácora de auditoría de solo inserción

*Tarea técnica* · Backend · MVP · depende de TEN-2, PLT-12

Crear el almacén de auditoría de negocio con quién, qué acción, sobre qué registro, cuándo, desde dónde (IP y dispositivo cuando corresponda) y valores anterior y nuevo. La tabla no admite actualización ni borrado desde la aplicación.

- [ ] El rol de base de datos de la aplicación no tiene permiso de UPDATE ni DELETE sobre la auditoría
- [ ] No existe endpoint ni pantalla que modifique un registro de auditoría
- [ ] Cada entrada guarda organización, usuario, acción, entidad, identificador y momento

Referencias: docs/modulos/M-AUD.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/02-arquitectura.md · Pedido §63 · C-07 · RN-AUD-01 a RN-AUD-07

### AUD-2 · Captura automática de antes y después

*Tarea técnica* · Backend · MVP · depende de AUD-1, SEG-9

Registrar de forma automática los cambios de las entidades marcadas como auditables y de todas las acciones de estado, con el valor anterior y el nuevo por campo. Los campos sensibles se guardan cifrados y solo se muestran a quien tiene permiso.

- [ ] Cambiar un sueldo genera una entrada con el valor anterior y el nuevo
- [ ] Un usuario sin permiso sobre el campo ve la entrada con el valor enmascarado
- [ ] Las acciones masivas generan una entrada por registro afectado

Referencias: docs/modulos/M-AUD.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §63 · RN-AUD-01 a RN-AUD-07

### AUD-3 · Actividad de usuario y logs técnicos separados

*Tarea técnica* · Backend · MVP · depende de AUD-1, PLT-10

Mantener tres registros distintos: auditoría de negocio, actividad del usuario (ingresos, vistas, descargas, búsquedas) y logs técnicos. Cada uno tiene su almacén, su retención y sus permisos de consulta.

- [ ] La línea de actividad de una ficha no mezcla entradas técnicas
- [ ] Los tres registros tienen políticas de retención configurables por separado

Referencias: docs/modulos/M-AUD.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §110 · C-07 · RN-AUD-01 a RN-AUD-07

### AUD-4 · Visor de auditoría

*Historia* · MVP · depende de AUD-2, UI-4

Construir la pantalla de auditoría con filtros por usuario, entidad, acción, registro y rango de fechas, detalle con comparación antes y después, y exportación. El rol Auditor la usa en modo de solo lectura.

- [ ] Se puede reconstruir la historia completa de cambios de un registro
- [ ] La exportación respeta permisos por campo
- [ ] El Auditor consulta todo y no puede modificar nada en el sistema

Referencias: docs/modulos/M-AUD.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §63 · Pedido §61 · RN-AUD-01 a RN-AUD-07 · Prototipo: Seguridad → Auditoría

### AUD-5 · Integridad y retención de la auditoría

*Tarea técnica* · Backend · MVP · depende de AUD-1

Encadenar las entradas de auditoría con hash para detectar alteraciones y aplicar la retención definida por política, con archivado a almacenamiento frío. La verificación de integridad se ejecuta de forma programada y su resultado se muestra en la consola.

- [ ] Un proceso de verificación detecta cualquier entrada alterada o faltante
- [ ] El archivado conserva la posibilidad de consulta por el plazo de retención configurado

Referencias: docs/modulos/M-AUD.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §63 · Pedido §91 · RN-AUD-01 a RN-AUD-07

## UI · Sistema de diseño y armazón

Identidad visual propia, componentes reutilizables, armazón de navegación, tablas, formularios y patrones de experiencia para toda la aplicación.

*Componente: Frontend*

### UI-1 · Fundamentos visuales y temas claro y oscuro

*Tarea técnica* · MVP · depende de PLT-1

Definir los fundamentos del sistema de diseño (color, tipografía, espaciado, radios, sombras) como variables, con tema claro y tema oscuro diseñados por separado y preferencia guardada por usuario. Los fundamentos se publican como paquete único que consumen todas las pantallas.

- [ ] Ningún componente usa colores literales: todos consumen las variables
- [ ] Ambos temas cumplen contraste AA en texto y controles
- [ ] La identidad visual es propia y no reproduce la de productos de referencia

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §84 · Pedido §96 · Pedido §124 · RN-UI-01 a RN-UI-08

### UI-2 · Armazón de navegación

*Historia* · MVP · depende de UI-1, SAS-5, SEG-8

Construir el armazón: barra lateral agrupada (Inicio, Personas, Tiempo, Nómina, Talento, Aprendizaje, Experiencia, Compensación, Operaciones, SST, Analytics, Automatización, Configuración), barra superior con selector de empresa, migas de pan y menú de usuario. El menú se arma según permisos y módulos contratados.

- [ ] Un módulo no contratado o sin permiso no aparece en el menú
- [ ] La barra lateral se colapsa y en móvil se convierte en menú desplegable
- [ ] Las rutas tienen dirección propia y se pueden compartir

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §119 · Pedido §96 · RN-UI-01 a RN-UI-08

### UI-3 · Biblioteca de componentes

*Tarea técnica* · MVP · depende de UI-1

Implementar y documentar los componentes base: tarjetas, pestañas, cajones laterales, modales, líneas de tiempo, etiquetas de estado, avisos, tooltips, menús, selectores de persona, cargador de archivos y esqueletos de carga. Cada componente expone variantes y estados (normal, foco, deshabilitado, error, cargando).

- [ ] Cada componente tiene ejemplos en un catálogo navegable con sus estados
- [ ] Todo control visible ejecuta una acción real: no existen botones decorativos
- [ ] Los componentes son operables con teclado

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §96 · Pedido §114 · RN-UI-01 a RN-UI-08

### UI-4 · Tablas profesionales

*Tarea técnica* · MVP · depende de UI-3, PLT-11

Construir el componente de tabla con búsqueda, filtros, orden, selección y ocultamiento de columnas, redimensionado, paginación en servidor, selección múltiple con acciones masivas, exportación y vistas guardadas por usuario. Todos los listados de los módulos usan este componente, sin tablas hechas a medida.

- [ ] Una vista guardada conserva filtros, columnas y orden
- [ ] Las acciones masivas muestran cuántos registros afectarán y piden confirmación
- [ ] La tabla funciona con listados muy grandes porque pagina en servidor

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §98 · Pedido §101 · RN-UI-01 a RN-UI-08

### UI-5 · Formularios y validación en interfaz

*Tarea técnica* · MVP · depende de UI-3, PLT-4

Construir el sistema de formularios: campos tipados, validación inmediata, mensajes claros, mapeo de errores por campo devueltos por la API, borradores y aviso de cambios sin guardar. Los campos personalizados y los permisos por campo se resuelven dentro del mismo sistema.

- [ ] Los errores del backend se muestran junto al campo correspondiente
- [ ] Los mensajes dicen qué corregir, en lenguaje claro
- [ ] Un formulario largo conserva lo escrito ante un error de red

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §100 · docs/08-api.md · RN-UI-01 a RN-UI-08

### UI-6 · Estados vacíos, carga y error

*Tarea técnica* · MVP · depende de UI-3

Definir y aplicar los patrones de estado vacío útil (explica qué es la pantalla y ofrece la acción principal), carga con esqueletos y error con opción de reintentar. Los tres patrones se entregan como componentes que reciben el texto y la acción de cada módulo.

- [ ] Ninguna pantalla muestra solo «No hay registros»
- [ ] Todo estado vacío ofrece la acción que corresponde si el usuario tiene permiso

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §99 · Pedido §96 · RN-UI-01 a RN-UI-08

### UI-7 · Paleta de comandos y búsqueda global

*Historia* · Backend + Frontend · MVP · depende de UI-2, SEG-10, TEN-6

Implementar la búsqueda global y la paleta de comandos: personas, documentos, vacantes, candidatos, cursos, solicitudes y reportes, además de accesos a pantallas y acciones. Los módulos registran sus fuentes de búsqueda.

- [ ] Los resultados respetan permisos, alcance y organización
- [ ] Un colaborador sin permiso no encuentra documentos ni datos ajenos
- [ ] La paleta se abre con atajo de teclado y navega con flechas

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/08-api.md · docs/07-permisos-y-roles.md · Pedido §70 · Pedido §96 · RN-UI-01 a RN-UI-08 · Prototipo: Analytics → Asistente → Buscar en todo

### UI-8 · Botón de creación rápida

*Historia* · MVP · depende de UI-2

Agregar el botón «+ Crear» con las opciones (colaborador, vacante, solicitud, documento, curso, encuesta, tarea) que cada módulo registra, visibles solo si el usuario tiene el permiso y el módulo está activo. El atajo de teclado y la paleta de comandos exponen las mismas opciones.

- [ ] La lista cambia según el rol y los módulos contratados
- [ ] Cada opción abre el formulario real de creación

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §88 · RN-UI-01 a RN-UI-08

### UI-9 · Experiencia móvil y aplicación instalable

*Tarea técnica* · MVP · depende de UI-2, UI-4

Asegurar el comportamiento responsive real de todo el armazón y los componentes, y entregar la aplicación como PWA instalable con caché del armazón. Las tablas pasan a tarjetas y los formularios a una columna.

- [ ] Todas las pantallas del armazón funcionan a 360 px de ancho sin desplazamiento horizontal
- [ ] La aplicación se instala en el teléfono y abre con pantalla propia

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §85 · Pedido §121 · RN-UI-01 a RN-UI-08

### UI-10 · Divulgación progresiva

*Tarea técnica* · MVP · depende de UI-3

Establecer el patrón de divulgación progresiva: cada pantalla muestra primero lo esencial y deja lo avanzado en secciones plegables, cajones o un segundo nivel de configuración. Documentarlo con ejemplos para los módulos.

- [ ] Los formularios de alta piden solo los campos obligatorios en el primer paso
- [ ] La configuración avanzada de cada módulo vive en un nivel secundario

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §97 · Pedido §124 · RN-UI-01 a RN-UI-08

### UI-11 · Servicio de exportación

*Tarea técnica* · Backend + Frontend · MVP · depende de UI-4, SEG-12, PLT-6

Implementar el servicio común de exportación a CSV, Excel y PDF: se ejecuta en segundo plano para volúmenes grandes, respeta permisos por campo y alcance, y avisa cuando el archivo está listo. Los módulos solo declaran la consulta y las columnas; el servicio hace el resto.

- [ ] Una exportación grande no bloquea la interfaz y llega como notificación con enlace
- [ ] El archivo exportado contiene solo las columnas y filas que el usuario puede ver

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §72 · Pedido §66 · RN-UI-01 a RN-UI-08

### UI-12 · Componentes de calendario, kanban y gráficos

*Tarea técnica* · MVP · depende de UI-3

Construir los componentes compuestos que varios módulos reutilizan: calendario (mes, periodo corto, agenda) con arrastrar y soltar, tablero kanban, árbol jerárquico y gráficos (barras, líneas, dona, mapa de calor) con el tema del sistema. Todos funcionan en el móvil con gestos táctiles y tienen alternativa accesible por teclado.

- [ ] Arrastrar una tarjeta o un bloque dispara una acción real con confirmación de resultado
- [ ] Los gráficos tienen alternativa en tabla y se leen en ambos temas

Referencias: docs/modulos/M-UI.md · docs/06-reglas-de-negocio.md · Pedido §96 · RN-UI-01 a RN-UI-08

## WFL · Motor de aprobaciones y workflows

Motor transversal de aprobaciones, reglas CUANDO → SI → ENTONCES, constructor visual, tareas y bandejas.

*Componente: Backend + Frontend*

### WFL-1 · Modelo de definiciones, versiones y ejecuciones

*Tarea técnica* · Backend · MVP · depende de PLT-6, PLT-8

Modelar definiciones de flujo versionadas, ejecuciones, pasos y su registro. Una ejecución en curso sigue con la versión con la que empezó. Los módulos registran sus tipos de solicitud y eventos disparadores.

- [ ] Editar un flujo crea una versión nueva y no altera ejecuciones en curso
- [ ] Cada ejecución guarda la traza completa de pasos, decisiones y tiempos

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · C-04 · ME-42 · RN-WFL-01 a RN-WFL-13

### WFL-2 · Motor de aprobaciones de N niveles

*Tarea técnica* · Backend · MVP · depende de WFL-1

Implementar aprobaciones de uno a N niveles, secuenciales o paralelas, con regla de quórum (todos, uno, mayoría), comentarios, rechazo con motivo y devolución para corrección. El resultado se entrega al módulo dueño de la solicitud mediante un contrato único.

- [ ] Un flujo de dos niveles secuenciales y otro de dos aprobadores en paralelo funcionan sobre el mismo tipo de solicitud
- [ ] Aprobar o rechazar es una acción idempotente y auditada
- [ ] El módulo dueño recibe el resultado una sola vez

Referencias: docs/modulos/M-WFL.md · docs/05-maquinas-de-estado.md · docs/06-reglas-de-negocio.md · Pedido §56 · C-04 · ME-43 · RN-WFL-01 a RN-WFL-13

### WFL-3 · Resolución de aprobadores, delegación y escalamiento

*Tarea técnica* · Backend · MVP · depende de WFL-2

Resolver aprobadores por jefe directo, jefe de N niveles, responsable de unidad, rol, posición o usuario fijo, según condiciones del pedido (monto, empresa, tipo). Incluir delegación por ausencia, escalamiento por tiempo sin respuesta y la regla que impide aprobar una solicitud propia.

- [ ] Si el aprobador está ausente con delegación vigente, la solicitud llega al delegado
- [ ] Una solicitud sin respuesta escala según la regla configurada y lo deja registrado
- [ ] Nadie aprueba su propia solicitud

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §56 · RN-WFL-01 a RN-WFL-13

### WFL-4 · Centro de aprobaciones

*Historia* · MVP · depende de WFL-2, UI-4

Construir la bandeja única de aprobaciones agrupada por tipo (vacaciones, gastos, headcount, horas extra, etc.) con detalle, aprobar, rechazar con motivo y acciones masivas cuando el tipo lo permite. Cada elemento muestra solicitante, resumen, antigüedad de la solicitud y nivel de aprobación en curso.

- [ ] La bandeja muestra el conteo por tipo y solo lo que corresponde al usuario
- [ ] La aprobación masiva está disponible solo en los tipos marcados como seguros
- [ ] Aprobar desde la bandeja actualiza de inmediato el módulo de origen

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · Pedido §109 · Pedido §56 · RN-WFL-01 a RN-WFL-13 · Prototipo: Automatización → Aprobaciones

### WFL-5 · Reglas CUANDO → SI → ENTONCES

*Tarea técnica* · Backend · MVP · depende de WFL-1

Implementar el motor de reglas: disparador (evento de dominio, fecha o programación), condiciones sobre los datos del evento y acciones (crear tarea, notificar, asignar proceso, iniciar aprobación, cambiar campo permitido, llamar webhook). Cada regla puede probarse con un evento de ejemplo antes de activarse.

- [ ] La regla «cuando se crea un colaborador, si el área es Tecnología, entonces asignar onboarding de TI y notificar al manager» se configura sin código
- [ ] Una acción fallida se reintenta y queda en el registro de ejecuciones
- [ ] Las reglas se ejecutan con permisos de sistema acotados a su organización

Referencias: docs/modulos/M-WFL.md · docs/05-maquinas-de-estado.md · docs/06-reglas-de-negocio.md · Pedido §55 · Pedido §103 · RN-WFL-01 a RN-WFL-13

### WFL-6 · Constructor visual de flujos

*Historia* · Frontend · MVP · depende de WFL-5, WFL-2, UI-12

Construir el lienzo visual con nodos de inicio, condición, aprobación, acción, notificación, espera y fin; validación del diseño antes de publicar y modo de prueba con datos de ejemplo. El lienzo guarda borradores y publica versiones sobre el modelo de definiciones.

- [ ] No se puede publicar un flujo con nodos sin conectar o sin salida
- [ ] El modo de prueba muestra el recorrido que seguiría un caso de ejemplo
- [ ] El mismo constructor sirve para cualquier módulo que registre disparadores y acciones

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · Pedido §95 · Pedido §55 · RN-WFL-01 a RN-WFL-13 · Prototipo: Automatización → Workflows

### WFL-7 · Centro de automatizaciones

*Historia* · MVP · depende de WFL-5

Mostrar las reglas activas con sus ejecuciones, errores, última y próxima ejecución, y el registro detallado de cada corrida. Permitir pausar, reanudar y reintentar.

- [ ] Cada ejecución muestra qué la disparó, qué condiciones evaluó y qué hizo
- [ ] Una regla con errores repetidos se marca y avisa a su responsable

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §76 · ME-44 · RN-WFL-01 a RN-WFL-13 · Prototipo: Automatización → Automatizaciones → Reglas

### WFL-8 · Tareas de RRHH

*Historia* · MVP · depende de WFL-1, UI-4

Implementar tareas internas con responsable, fecha de vencimiento, estado, prioridad y vínculo con un proceso (onboarding, offboarding, caso, colaborador). Las crean personas o reglas.

- [ ] Una tarea puede asignarse a una persona o a un rol
- [ ] Las tareas vencidas se resaltan y notifican
- [ ] Desde la tarea se navega al registro relacionado

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §77 · RN-WFL-01 a RN-WFL-13 · Prototipo: Tareas

### WFL-9 · Bandeja de pendientes

*Historia* · MVP · depende de WFL-4, WFL-8

Construir la bandeja central «Pendientes» que reúne lo que el usuario debe hacer: aprobaciones, firmas, evaluaciones, tareas y solicitudes. Cada módulo registra sus tipos de pendiente mediante un contrato común.

- [ ] El contador de la barra superior coincide con los pendientes reales
- [ ] Al resolver un pendiente desaparece sin recargar
- [ ] Un pendiente lleva directo a la pantalla donde se resuelve

Referencias: docs/modulos/M-WFL.md · docs/06-reglas-de-negocio.md · Pedido §87 · RN-WFL-01 a RN-WFL-13 · Prototipo: Pendientes

## NOT · Notificaciones y mensajería

Motor de notificaciones con plantillas y canales (en la aplicación, correo, push y mensajería) y preferencias por usuario.

*Componente: Backend + Frontend*

### NOT-1 · Motor de notificaciones y plantillas

*Tarea técnica* · Backend · MVP · depende de PLT-6, PLT-13

Implementar el motor que recibe eventos, resuelve destinatarios, aplica plantillas traducibles con variables y entrega por los canales habilitados, por medio del outbox. Las plantillas se editan por organización.

- [ ] Una notificación se envía una sola vez por canal aunque el evento se reprocese
- [ ] Las plantillas admiten es, en y pt y variables validadas
- [ ] Cada envío guarda estado de entrega y error

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §86 · Pedido §80 · RN-NOT-01 a RN-NOT-08

### NOT-2 · Centro de notificaciones en la aplicación

*Historia* · MVP · depende de NOT-1, UI-2

Construir la campana con contador, lista de notificaciones con leído y no leído, enlace al registro y actualización en tiempo real. Las notificaciones se agrupan por tipo y se conservan según la política de retención.

- [ ] El contador se actualiza sin recargar la página
- [ ] Marcar todo como leído funciona y persiste

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · Pedido §86 · RN-NOT-01 a RN-NOT-08 · Prototipo: Pendientes

### NOT-3 · Canal de correo

*Tarea técnica* · Backend · MVP · depende de NOT-1

Integrar el envío de correo por adaptador con remitente por organización, plantillas con la marca del cliente, registro de rebotes y supresión de direcciones inválidas. Cada organización puede usar el remitente de la plataforma o el suyo verificado.

- [ ] Los rebotes marcan la dirección y avisan a RRHH
- [ ] En entornos de prueba ningún correo sale a destinatarios reales

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §86 · Pedido §75 · RN-NOT-01 a RN-NOT-08

### NOT-4 · Canal push

*Tarea técnica* · MVP · depende de NOT-1, UI-9

Implementar notificaciones push web para la aplicación instalada, con registro de dispositivos y baja automática de suscripciones caducadas. El contenido del aviso push no incluye datos sensibles: invita a abrir la aplicación.

- [ ] El usuario acepta o rechaza push desde sus preferencias
- [ ] Una notificación push abre la pantalla correspondiente

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · Pedido §86 · Pedido §85 · RN-NOT-01 a RN-NOT-08

### NOT-5 · Capa de mensajería para SMS y WhatsApp

*Tarea técnica* · Backend · MVP · depende de NOT-1

Definir la capa de mensajería saliente y entrante (SMS y WhatsApp) como adaptador con implementación falsa, plantillas aprobables y registro de mensajes. Sirve al OTP y, más adelante, al autoservicio por WhatsApp.

- [ ] El OTP de ingreso con documento se envía por esta capa
- [ ] Cambiar de proveedor no modifica los módulos que envían mensajes

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §86 · C-09 · RN-NOT-01 a RN-NOT-08

### NOT-6 · Preferencias de notificación

*Historia* · MVP · depende de NOT-2, NOT-3

Permitir que cada usuario elija canal por tipo de notificación y que la organización defina cuáles son obligatorias y un horario de silencio que respeta la desconexión digital. Las preferencias se guardan por usuario y se aplican en el motor antes de cada envío.

- [ ] Una notificación obligatoria no puede desactivarse por el usuario
- [ ] Fuera del horario permitido los avisos no urgentes se difieren

Referencias: docs/modulos/M-NOT.md · docs/06-reglas-de-negocio.md · Pedido §86 · Pedido §79 · RN-NOT-01 a RN-NOT-08

## CFG · Configuración, campos personalizados, i18n y multimoneda

Centro de configuración, parámetros con vigencia, campos personalizados, idiomas, monedas, calendarios y papelera.

*Componente: Backend + Frontend*

### CFG-1 · Centro de configuración

*Historia* · MVP · depende de UI-2, SEG-8

Organizar la configuración en un solo lugar con secciones (organización, empresas, sedes, estructura, usuarios, roles, campos, workflows, y una sección por módulo activo, integraciones, notificaciones, seguridad, API, auditoría). Los módulos registran su sección.

- [ ] Solo aparecen las secciones de módulos contratados y con permiso
- [ ] Cada sección tiene buscador de ajustes y ayuda breve

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · Pedido §93 · RN-CFG-01 a RN-CFG-09 · Prototipo: Configuración → Centro de configuración

### CFG-2 · Configuración por empresa

*Historia* · MVP · depende de CFG-1, TEN-5

Permitir que cada empresa configure datos fiscales, logo, moneda, zona horaria, idioma, calendario y políticas, heredando los valores de la organización cuando no los define. Los valores se guardan con vigencia cuando afectan cálculos (moneda, calendario).

- [ ] Dos empresas del mismo grupo pueden tener moneda y zona horaria distintas
- [ ] Las fechas se muestran en la zona horaria de la empresa
- [ ] La herencia organización → empresa es visible y se puede sobrescribir

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §79 · RN-CFG-01 a RN-CFG-09 · Prototipo: Organización → Estructura → Empresas

### CFG-3 · Parámetros con vigencia por fecha

*Tarea técnica* · Backend · MVP · depende de PLT-12, AUD-1

Implementar el almacén de parámetros con vigencia (`valid_from`, `valid_to`) y ámbito (plataforma, país, régimen, organización, empresa), con una función única de consulta «valor vigente a una fecha». Ninguna regla legal o de política vive como constante en el código.

- [ ] Consultar un parámetro a una fecha pasada devuelve el valor de entonces
- [ ] Registrar un valor con vigencia futura no altera cálculos anteriores a esa fecha
- [ ] Cada cambio de parámetro queda auditado con su fuente

Referencias: docs/modulos/M-CFG.md · docs/04-modelo-de-datos.md · docs/06-reglas-de-negocio.md · Pedido §28 · C-05 · RN-CFG-01 a RN-CFG-09

### CFG-4 · Campos personalizados

*Historia* · MVP · depende de CFG-1, SEG-9

Permitir crear campos de tipo texto, número, fecha, selector, selección múltiple, casilla, usuario, archivo, URL, moneda y porcentaje, asignarlos a entidades de los módulos y definir obligatoriedad, permisos y orden. Los valores se almacenan tipados y validados en el backend.

- [ ] Un campo nuevo aparece en el formulario de la entidad sin desplegar
- [ ] Un campo puede marcarse sensible y aplicar permiso por campo
- [ ] Desactivar un campo conserva los valores ya guardados

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §94 · C-04 · RN-CFG-01 a RN-CFG-09 · Prototipo: Configuración → Centro de configuración → Campos personalizados

### CFG-5 · Campos personalizados en tablas, filtros, importación y API

*Tarea técnica* · MVP · depende de CFG-4, UI-4

Hacer que los campos personalizados se comporten como nativos: columnas y filtros de tablas, exportación, importador, plantillas de documentos, reportes y API. El tratamiento es genérico: ningún módulo programa por separado el soporte de sus campos personalizados.

- [ ] Un campo personalizado se puede filtrar, exportar e importar
- [ ] La API lo expone con su tipo validado

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §94 · RN-CFG-01 a RN-CFG-09

### CFG-6 · Internacionalización

*Tarea técnica* · MVP · depende de UI-1, PLT-4

Implementar i18n en web y API con archivos de traducción (es completo; en y pt preparados), formatos de fecha, número y moneda por configuración regional, e idioma por usuario con valor por defecto de la empresa. Los catálogos y plantillas editables por el cliente también admiten traducción.

- [ ] Una regla de linter impide textos de interfaz escritos dentro de los componentes
- [ ] Cambiar de idioma no requiere recargar sesión y afecta también a notificaciones
- [ ] Las claves sin traducción se detectan en la integración continua

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §80 · RN-CFG-01 a RN-CFG-09

### CFG-7 · Multimoneda y tipos de cambio

*Tarea técnica* · Backend · MVP · depende de CFG-3

Guardar todo monto con su moneda, mantener tipos de cambio con vigencia por fecha y ofrecer conversión para consolidar reportes de grupos multinacionales sin alterar el valor original. El cálculo usa decimales exactos y redondeo definido por moneda.

- [ ] Ningún monto se guarda sin moneda
- [ ] Un reporte consolidado indica la moneda y el tipo de cambio aplicado

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §81 · RN-CFG-01 a RN-CFG-09

### CFG-8 · Calendarios, feriados y calendario de RRHH

*Historia* · MVP · depende de CFG-2, UI-12

Administrar calendarios y feriados por país, empresa y sede, y construir el calendario central de RRHH con fuentes que los módulos registran (cumpleaños, aniversarios, contratos, vacaciones, ausencias, capacitaciones, evaluaciones, entrevistas, eventos) y filtros. Los feriados se guardan con vigencia y tipo (nacional, regional, de empresa) para que asistencia y planilla los consuman.

- [ ] Los feriados nacionales del Perú vienen precargados y se pueden agregar los locales
- [ ] El calendario muestra solo las fuentes de módulos activos y lo que el usuario puede ver
- [ ] Cada elemento enlaza a su registro

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §78 · Pedido §24 · Pedido §79 · RN-CFG-01 a RN-CFG-09 · Prototipo: Calendario

### CFG-9 · Papelera y restauración

*Historia* · MVP · depende de PLT-12, AUD-2

Implementar borrado lógico con papelera por módulo, restauración con permiso y eliminación definitiva solo por política de retención. La información laboral crítica no se elimina de inmediato.

- [ ] Un registro eliminado puede restaurarse con sus relaciones intactas
- [ ] La papelera indica quién eliminó, cuándo y cuándo se depurará

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §90 · RN-CFG-01 a RN-CFG-09

### CFG-10 · Catálogos maestros

*Tarea técnica* · Datos · MVP · depende de CFG-3

Cargar y mantener los catálogos comunes con vigencia: tipos de documento de identidad, países, ubigeo del Perú, bancos, entidades previsionales, monedas, nacionalidades y motivos de cese. Cada catálogo tiene código estable para integraciones y declaraciones.

- [ ] Los catálogos se actualizan desde la consola sin desplegar
- [ ] La organización puede extender los catálogos que lo permiten

Referencias: docs/modulos/M-CFG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · RN-CFG-01 a RN-CFG-09

## WEB · Sitio comercial gestiahr.com

Sitio público de GestiaHR: inicio, páginas de producto, Suite, precios, registro de prueba y suscripción, demo, blog, SEO, chatbot comercial y su administración en la consola.

*Componente: Backend + Frontend*

### WEB-1 · Aplicación del sitio comercial y su CMS

*Tarea técnica* · Infraestructura · Sitio web · depende de PLT-1, PLT-9, PLT-5

Crear la aplicación `apps/site` del monorepo para gestiahr.com con un CMS de contenidos (páginas, blog, preguntas frecuentes, testimonios y ajustes), separada de la aplicación del cliente y de la consola. El sitio no accede a la base de las organizaciones: lee el catálogo público y crea registros solo por la API pública de la plataforma. Tiene entorno de prueba que no se indexa en buscadores. Diseño de referencia: `docs/web/sitio/` (abrir `portada.html`), con los mismos tokens del sistema (`docs/10`) en `docs/web/sitio/assets/site.css`.

- [ ] El sitio se despliega aparte y una caída del sitio no afecta a la aplicación ni a la consola
- [ ] El sitio solo usa los endpoints `/public/*` y nunca credenciales de la base de las organizaciones
- [ ] El entorno de prueba responde con `noindex` en todas sus páginas

Referencias: docs/modulos/M-WEB.md · docs/02-arquitectura.md · docs/08-api.md · docs/12-seguridad-y-privacidad.md · RN-WEB-01 a RN-WEB-12

### WEB-2 · Catálogo de productos ligado a los módulos

*Tarea técnica* · Backend · Sitio web · depende de SAS-3, SAS-5, WEB-1

Implementar el catálogo de productos de la web (Personas, Tiempo, Planilla, Talento, Formación y Suite) con su ruta y los módulos comerciales que agrupa, tal como lo define `docs/01` §4.1. Exponer en `/public/catalog` los productos, los planes públicos con sus módulos, límites y complementos, y el estado de cada módulo. El estado «Disponible» o «Próximamente» se deriva de los módulos publicados en la consola y nunca se escribe a mano en el sitio.

- [ ] Cambiar un plan, un precio o un complemento en la consola se refleja en el sitio sin desplegar
- [ ] Un producto con algún módulo sin publicar se muestra como «Próximamente» en esa parte
- [ ] El catálogo público no expone organizaciones, consumo ni datos de clientes

Referencias: docs/modulos/M-WEB.md · docs/04-modelo-de-datos.md · docs/08-api.md · Pedido §3 · RN-WEB-01 a RN-WEB-12

### WEB-3 · Inicio, páginas de producto y página de la Suite

*Historia* · Sitio web · depende de WEB-2

Construir el inicio, una página por producto (`/personas`, `/tiempo`, `/planilla`, `/talento`, `/formacion`) y la página `/suite`, que abren en la misma ventana y leen el catálogo público. Cada página de producto muestra qué resuelve, sus funciones, los planes que lo incluyen y, si se vende solo, su precio individual. La página de la Suite muestra el mapa de productos con su estado y los planes completos. Diseño de referencia: `docs/web/sitio/inicio.html`, `suite.html`, `personas.html`, `planilla.html`, `tiempo.html`, `talento.html` y `formacion.html`.

- [ ] Cada cuadro de producto del inicio abre su página en la misma ventana
- [ ] La página de Personas muestra los tres planes y no ofrece compra suelta
- [ ] Las partes en «Próximamente» muestran lista de espera y no tienen botón de compra

Referencias: docs/modulos/M-WEB.md · docs/10-pantallas-y-ux.md · Pedido §3 · RN-WEB-01 a RN-WEB-12

### WEB-4 · Precios, comparador y selector de módulos

*Historia* · Sitio web · depende de WEB-2, SAS-4

Construir la página `/precios` con interruptor mensual o anual, pestaña de productos individuales, buscador de plan según colaboradores y módulos, tabla comparativa por área y selector «Añadir módulos» por plan con subtotal. La selección pasa al registro con el plan y los complementos elegidos. Los montos, el impuesto y los límites salen de la consola. Diseño de referencia: `docs/web/sitio/precios.html`.

- [ ] El subtotal suma el plan y los complementos elegidos con el impuesto vigente
- [ ] Un módulo que exige otro lo agrega o lo avisa según `module_dependencies`
- [ ] Un módulo en «Próximamente» se puede marcar como «Avísame» y no se cobra

Referencias: docs/modulos/M-WEB.md · docs/10-pantallas-y-ux.md · Pedido §3 · RN-WEB-01 a RN-WEB-12

### WEB-5 · Registro de prueba y suscripción en tres pasos

*Historia* · Sitio web · depende de WEB-4, SAS-2, SAS-11, SEG-6, SEG-5

Construir el registro en tres pasos: cuenta (correo o Google), empresa (RUC con dígito verificador, razón social, colaboradores, régimen laboral y subdominio) y plan (prueba sin tarjeta o pago con el adaptador de cobro). Al terminar se crea la organización con su primera empresa, el subdominio, los módulos del plan y el usuario administrador, y se entra directo al asistente de primeros pasos. Un registro abandonado queda como lead para ventas. Diseño de referencia: `docs/web/sitio/registro.html` y `bienvenida.html`.

- [ ] Un RUC o un subdominio ya usados se rechazan antes de crear nada
- [ ] La organización creada desde el sitio queda igual que una creada desde la consola
- [ ] El pago y el comprobante usan los mismos adaptadores de la facturación de la suscripción
- [ ] Un registro abandonado aparece en la consola con el paso en que quedó

Referencias: docs/modulos/M-WEB.md · docs/08-api.md · docs/12-seguridad-y-privacidad.md · Pedido §2 · Pedido §3 · RN-WEB-01 a RN-WEB-12

### WEB-6 · Agendar demo, contacto y partners

*Historia* · Sitio web · depende de WEB-1, NOT-3

Ofrecer los formularios de demo con calendario de horarios libres, contacto y solicitud de partner para estudios contables y consultoras. Cada envío crea un lead en la consola con la página de origen y la campaña, y confirma por correo. La demo se puede reprogramar o cancelar desde el enlace del correo. Diseño de referencia: `docs/web/sitio/demo.html`, `demo-confirmada.html` y `partners.html`.

- [ ] No se ofrecen horarios ya tomados ni con menos anticipación que el parámetro de la consola
- [ ] Cada lead guarda página de origen, campaña y consentimiento
- [ ] La confirmación y los recordatorios salen por el motor de notificaciones

Referencias: docs/modulos/M-WEB.md · docs/08-api.md · docs/12-seguridad-y-privacidad.md · RN-WEB-01 a RN-WEB-12

### WEB-7 · Lista de espera de lo que está en «Próximamente»

*Historia* · Sitio web · depende de WEB-3, WEB-6

Registrar a las empresas que piden aviso de un producto o módulo en «Próximamente» y avisarles cuando el módulo se publica en la consola. El aviso lleva el enlace a la página del producto y, si la consola lo define, un cupón de lanzamiento. Diseño de referencia: sección «Lista de espera» de `docs/web/sitio/talento.html` y `formacion.html`.

- [ ] Publicar un módulo en la consola dispara el aviso a su lista de espera una sola vez
- [ ] La consola ve cuántas empresas esperan cada módulo

Referencias: docs/modulos/M-WEB.md · docs/08-api.md · RN-WEB-01 a RN-WEB-12

### WEB-8 · Blog, boletín y comentarios moderados

*Historia* · Sitio web · depende de WEB-1

Construir el blog con categorías, buscador, tipo de contenido (artículo, guía, plantilla, novedad normativa), autor verificado, fecha de actualización y artículos relacionados. Incluir la suscripción al boletín con doble confirmación y comentarios que se publican solo después de moderarlos. Diseño de referencia: `docs/web/sitio/blog.html` y `articulo.html`.

- [ ] Un comentario no aparece hasta que la consola o el CMS lo aprueba
- [ ] El boletín pide confirmación por correo y permite darse de baja en un clic
- [ ] Cada artículo muestra la fecha de su última actualización

Referencias: docs/modulos/M-WEB.md · docs/10-pantallas-y-ux.md · docs/12-seguridad-y-privacidad.md · RN-WEB-01 a RN-WEB-12

### WEB-9 · SEO técnico y páginas por módulo e industria

*Tarea técnica* · Frontend · Sitio web · depende de WEB-3, WEB-8

Generar las páginas SEO por módulo y por industria desde plantillas del CMS, con título, descripción, imagen, canónica y Open Graph por página. Publicar `sitemap.xml`, `robots.txt`, redirecciones y datos estructurados (Organization, SoftwareApplication, Product, FAQPage, Article y BreadcrumbList). Las páginas se generan estáticas y se actualizan al publicar. Diseño de referencia: `docs/web/sitio/soluciones.html`, las páginas por proceso (`legajo-digital.html`, `vacaciones-y-record-vacacional.html`, `control-de-asistencia.html`, `boletas-de-pago-electronicas.html`, `calculo-de-cts.html`, `calculo-de-gratificaciones.html`, `liquidacion-de-beneficios-sociales.html`, `plame-y-t-registro.html` y `software-de-planillas.html`), las de industria (`retail.html`, `agroindustria.html`, `empresas-de-servicios.html` y `estudios-contables.html`), y `sitemap.xml` y `robots.txt` de esa carpeta.

- [ ] Toda página publicada tiene título, descripción y canónica propios
- [ ] El sitemap incluye solo páginas publicadas e indexables
- [ ] Los datos estructurados pasan la validación de buscadores
- [ ] Cada página de proceso o industria enlaza a su producto y a sus páginas hermanas, y cada producto enlaza a sus páginas de proceso

Referencias: docs/modulos/M-WEB.md · docs/10-pantallas-y-ux.md · RN-WEB-01 a RN-WEB-12

### WEB-10 · Chatbot comercial del sitio

*Historia* · Sitio web · depende de WEB-2, PLT-13

Ofrecer un chatbot en todas las páginas que responde sobre productos, planes, preguntas frecuentes y artículos públicos, con respuestas rápidas y derivación a una persona o a WhatsApp. Usa la misma capa de proveedor de IA y el mismo registro de uso que la aplicación (IAR-1 y IAR-2), sin acceso a datos de organizaciones. El superadministrador lo enciende o apaga y define saludo, respuestas rápidas y fuentes. Diseño de referencia: botón «¿Tienes dudas?» de cualquier página de `docs/web/sitio/`.

- [ ] El chatbot solo responde con el catálogo público, las preguntas frecuentes y los artículos publicados
- [ ] Si no tiene respuesta, ofrece hablar con ventas o dejar sus datos
- [ ] Cada conversación queda en la consola y se borra según la política de retención

Referencias: docs/modulos/M-WEB.md · docs/12-seguridad-y-privacidad.md · RN-WEB-01 a RN-WEB-12

### WEB-11 · Popups, banner de cookies y medición

*Historia* · Sitio web · depende de WEB-1

Configurar desde la consola los popups del sitio (salida, desplazamiento y tiempo en página) con reglas de páginas, dispositivo y frecuencia, y el banner de cookies con aceptar, rechazar y configurar. Las etiquetas de medición y publicidad solo se cargan después del consentimiento. Diseño de referencia: banner de cookies de cualquier página de `docs/web/sitio/`.

- [ ] Se muestra como máximo un popup por visita y no se repite antes del periodo configurado
- [ ] Ninguna etiqueta de medición se carga antes de que la persona acepte
- [ ] Los popups no aparecen en el registro ni en el pago

Referencias: docs/modulos/M-WEB.md · docs/12-seguridad-y-privacidad.md · RN-WEB-01 a RN-WEB-12

### WEB-12 · Páginas legales y libro de reclamaciones virtual

*Historia* · Sitio web · depende de WEB-1, NOT-3

Publicar desde el CMS los términos, la política de privacidad, la de cookies y el acuerdo de tratamiento de datos, con fecha de actualización y descarga en PDF. Incluir el libro de reclamaciones virtual con código correlativo, copia por correo y bandeja de atención en la consola. Diseño de referencia: `docs/web/sitio/terminos.html`, `privacidad.html`, `libro-reclamaciones.html` y `reclamo-confirmado.html`.

- [ ] Cada reclamo recibe un código correlativo y la persona recibe su copia por correo
- [ ] La consola muestra los reclamos con su estado y el plazo legal de respuesta como parámetro

Referencias: docs/modulos/M-WEB.md · docs/12-seguridad-y-privacidad.md · docs/14-localizacion-peru.md · RN-WEB-01 a RN-WEB-12

### WEB-13 · Sección «Sitio web» de la consola

*Historia* · Sitio web · depende de SAS-1, SAS-13, WEB-6

Agregar a la consola la sección «Sitio web» con resumen del embudo (visitas, registros, demos, pagos), leads con su origen y responsable, demos agendadas, lista de espera, popups, chatbot, comentarios por moderar, reclamos y prueba social. Los testimonios, logos y cifras solo se publican si se marca que se tiene el permiso del cliente. Diseño de referencia: `docs/web/sitio/consola-sitio-web.html`.

- [ ] Un lead se asigna a un responsable y cambia de estado con historial
- [ ] La prueba social no se publica sin la confirmación del permiso del cliente
- [ ] Todas las acciones de la sección quedan en la auditoría de la consola

Referencias: docs/modulos/M-WEB.md · docs/08-api.md · docs/10-pantallas-y-ux.md · RN-WEB-01 a RN-WEB-12

# Fase 1 · Core HR

## ORG · Estructura organizacional

Empresas, sedes, unidades, centros de costo, catálogo de puestos y organigrama dinámico.

*Componente: Backend + Frontend*

### ORG-1 · Empresas y razones sociales

*Historia* · MVP · depende de TEN-5, CFG-2

Administrar las razones sociales de la organización con RUC, datos fiscales, representante legal, actividad económica, régimen laboral de la empresa y país. Cada empresa es el empleador de sus relaciones laborales.

- [ ] El RUC se valida en formato y no se repite dentro de la organización
- [ ] El régimen laboral de la empresa se guarda con vigencia
- [ ] No se puede eliminar una empresa con relaciones laborales; se desactiva

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · Pedido §4 · Pedido §1 · C-05 · RN-ORG-01 a RN-ORG-08 · Prototipo: Organización → Estructura → Empresas

### ORG-2 · Sedes, sucursales y ubicaciones

*Historia* · MVP · depende de ORG-1, CFG-8

Administrar sedes y ubicaciones por empresa con dirección, ubigeo, coordenadas, zona horaria, calendario de feriados y marcas de centro de riesgo (SCTR). Las sedes se usan en alcance, asistencia y cumplimiento.

- [ ] Una sede guarda coordenadas y radio para la geocerca de marcación
- [ ] Una sede desactivada conserva su historia y no admite nuevas asignaciones

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §4 · RN-ORG-01 a RN-ORG-08 · Prototipo: Organización → Estructura → Sedes

### ORG-3 · Árbol de unidades organizacionales

*Historia* · MVP · depende de ORG-1

Modelar áreas, departamentos, subdepartamentos, unidades y equipos como un árbol con tipo de nivel configurable, responsable y vigencia. Mover una unidad conserva la historia de la estructura anterior.

- [ ] Se pueden crear niveles con los nombres que usa cada organización
- [ ] Reorganizar una unidad con fecha efectiva no altera reportes de periodos anteriores
- [ ] Cada unidad tiene responsable y centro de costo por defecto

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §4 · C-03 · RN-ORG-01 a RN-ORG-08 · Prototipo: Organización → Estructura → Unidades

### ORG-4 · Centros de costo

*Historia* · MVP · depende de ORG-1

Administrar centros de costo por empresa con código, jerarquía y vigencia, y permitir distribuir a un colaborador entre varios centros por porcentaje. Los centros de costo alimentan la contabilización de planilla, gastos y hojas de tiempo.

- [ ] La suma de la distribución de un colaborador es siempre cien por ciento
- [ ] Un centro de costo cerrado no admite nuevas asignaciones

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §4 · RN-ORG-01 a RN-ORG-08

### ORG-5 · Catálogo de puestos, cargos, niveles y categorías

*Historia* · MVP · depende de ORG-1

Mantener el catálogo de puestos (`job_profiles`) con descripción, funciones, requisitos, nivel, familia y categoría laboral. El puesto es la definición de catálogo; la plaza concreta es la posición.

- [ ] Un puesto puede usarse en muchas posiciones
- [ ] La descripción del puesto se versiona
- [ ] Los niveles y categorías son catálogos configurables

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §4 · C-02 · RN-ORG-01 a RN-ORG-08 · Prototipo: Organización → Estructura → Puestos

### ORG-6 · Organigrama visual dinámico

*Historia* · MVP · depende de ORG-3, UI-12

Dibujar el organigrama por unidades y por línea de reporte, con búsqueda, ampliación, plegado de ramas y vista a una fecha. Se actualiza solo cuando cambian responsables o asignaciones.

- [ ] Cambiar el jefe de un colaborador se refleja en el organigrama sin acciones adicionales
- [ ] La vista a una fecha pasada muestra la estructura de entonces
- [ ] Se exporta como imagen y PDF

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · Pedido §4 · C-03 · RN-ORG-01 a RN-ORG-08 · Prototipo: Personas → Organigrama

### ORG-7 · Directorio de personas

*Historia* · MVP · depende de ORG-3, SEG-9

Construir el directorio con búsqueda por nombre, puesto, unidad y sede, tarjeta de contacto y vista de equipo. Muestra solo datos de contacto laboral permitidos por la política de la organización.

- [ ] Ningún dato sensible aparece en el directorio
- [ ] La organización decide qué campos de contacto son visibles para todos

Referencias: docs/modulos/M-ORG.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §119 · RN-ORG-01 a RN-ORG-08 · Prototipo: Personas → Directorio

## POS · Posiciones y headcount

Plazas presupuestadas, solicitud y aprobación de posiciones, presupuesto, escenarios y organigrama por posiciones.

*Componente: Backend + Frontend*

### POS-1 · Posiciones como plazas

*Historia* · MVP · depende de ORG-5, ORG-3

Modelar `positions`: cada posición es una plaza con puesto de catálogo, unidad, sede, centro de costo, posición jefe, banda de referencia y vigencia. Una posición admite un ocupante, o varios si se define como compartida.

- [ ] Varias posiciones pueden compartir el mismo puesto de catálogo
- [ ] La línea de reporte se deriva de la posición jefe
- [ ] Una posición guarda la historia de sus ocupantes

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · C-02 · Pedido §5 · RN-POS-01 a RN-POS-08 · Prototipo: Personas → Headcount

### POS-2 · Estados de la posición

*Tarea técnica* · Backend · MVP · depende de POS-1, PLT-8

Implementar la máquina de estados de la posición: solicitada, aprobada, vacante, en reclutamiento, ocupada, congelada y eliminada, con acciones explícitas y motivos. El estado ocupada o vacante se deriva de las asignaciones vigentes y no se edita a mano.

- [ ] Congelar una posición impide abrir una vacante sobre ella
- [ ] Eliminar una posición ocupada no está permitido
- [ ] Cada transición queda auditada

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §5 · ME-03 · RN-POS-01 a RN-POS-08

### POS-3 · Solicitud y aprobación de nuevas posiciones

*Historia* · MVP · depende de POS-2, WFL-2

Permitir que un manager o RRHH solicite una posición nueva con justificación y costo proyectado, y que pase por el flujo de aprobación configurado antes de entrar al presupuesto. La solicitud aprobada queda trazada desde su origen hasta la contratación que la ocupa.

- [ ] La solicitud aprobada crea la posición en estado aprobada
- [ ] El rechazo exige motivo y notifica al solicitante

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §5 · Pedido §56 · ME-04 · RN-POS-01 a RN-POS-08 · Prototipo: Personas → Headcount

### POS-4 · Presupuesto de dotación y desviaciones

*Historia* · MVP · depende de POS-2, SEG-9

Registrar el presupuesto de posiciones por unidad y periodo, y comparar posiciones aprobadas, ocupadas y vacantes, con costo proyectado y desviación frente al presupuesto. El costo proyectado se calcula con la banda o el sueldo vigente de cada plaza y sus cargas.

- [ ] El tablero muestra aprobadas, ocupadas, vacantes y congeladas por unidad
- [ ] El costo proyectado exige permiso para ver sueldos
- [ ] La desviación se explica por posición

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §5 · RN-POS-01 a RN-POS-08 · Prototipo: Personas → Headcount

### POS-5 · Escenarios de planificación

*Historia* · MVP · depende de POS-4

Crear escenarios (actual, conservador, crecimiento, personalizado) que copian la dotación y permiten agregar, congelar o quitar posiciones para comparar el efecto sin tocar la estructura real. Cada escenario guarda su autor, sus supuestos y la fecha de la dotación de base.

- [ ] Un escenario no modifica posiciones reales hasta que se aplica con aprobación
- [ ] Dos escenarios se comparan lado a lado en número de plazas y costo

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · Pedido §5 · RN-POS-01 a RN-POS-08 · Prototipo: Personas → Headcount

### POS-6 · Política de contratar solo contra posición

*Tarea técnica* · Backend · MVP · depende de POS-2

Agregar la política por empresa que impide registrar una relación laboral o abrir una vacante sin una posición aprobada y libre. Desactivada, el sistema crea la posición de forma implícita.

- [ ] Con la política activa, un alta sin posición vacante es rechazada con mensaje claro
- [ ] La política se activa o desactiva por empresa y queda auditada

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · Pedido §5 · RN-POS-01 a RN-POS-08

### POS-7 · Organigrama por posiciones

*Historia* · MVP · depende de POS-1, ORG-6

Dibujar el organigrama por posiciones, que incluye plazas vacantes y congeladas, con indicadores de ocupación y de presupuesto por rama. Reutiliza el componente del organigrama y cambia la fuente de datos a posiciones.

- [ ] Las vacantes se ven como nodos diferenciados
- [ ] Cada rama muestra plazas ocupadas sobre aprobadas

Referencias: docs/modulos/M-POS.md · docs/06-reglas-de-negocio.md · E-10 · C-02 · Pedido §4 · RN-POS-01 a RN-POS-08 · Prototipo: Personas → Headcount

## COL · Colaboradores y ficha 360

Personas, relaciones laborales y la ficha 360 como fuente única de información del colaborador.

*Componente: Backend + Frontend*

### COL-1 · Personas y deduplicación

*Tarea técnica* · Backend · MVP · depende de TEN-3, CFG-10

Modelar `people` con identidad (tipo y número de documento, nombres, fecha de nacimiento) independiente del usuario y de la relación laboral. Detectar duplicados por documento al crear o importar.

- [ ] Una persona existe una sola vez por organización aunque tenga varias relaciones
- [ ] Crear una persona con documento ya registrado ofrece usar la existente
- [ ] El número de documento se valida según su tipo

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · C-01 · Pedido §104 · RN-COL-01 a RN-COL-13

### COL-2 · Alta de relación laboral

*Historia* · MVP · depende de COL-1, POS-6, HIS-1

Registrar el ingreso de un colaborador como `employment`: persona, empresa, posición, fecha de ingreso, tipo de trabajador, régimen laboral, modalidad y jefe. El alta emite `employee.hired`.

- [ ] El alta crea la primera asignación, compensación y contrato con la misma fecha de inicio
- [ ] El evento `employee.hired` se emite una sola vez
- [ ] Si la política lo exige, el alta consume una posición vacante

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · Pedido §6 · Pedido §103 · C-01 · ME-01 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Colaboradores

### COL-3 · Ficha 360 del colaborador

*Historia* · MVP · depende de COL-2, SEG-9, SEG-10

Construir la ficha con cabecera (foto, nombre, puesto, estado, antigüedad) y pestañas registrables por los módulos: resumen, información, contrato, asistencia, vacaciones, planilla, documentos, activos, desempeño, objetivos, competencias, capacitaciones, beneficios, encuestas, reconocimientos, historial y actividad. La ficha se abre sobre una relación laboral y permite cambiar a otra relación de la misma persona.

- [ ] Las pestañas de módulos no contratados o sin permiso no aparecen
- [ ] Los campos sensibles se ocultan según permiso por campo
- [ ] La ficha es la única pantalla donde se editan los datos maestros del colaborador

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · Pedido §6 · C-11 · Pedido §116 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Ficha del colaborador

### COL-4 · Datos personales y contactos de emergencia

*Historia* · MVP · depende de COL-3

Gestionar foto, nombres, documento, fecha de nacimiento, sexo cuando legalmente corresponda, nacionalidad, estado civil, dirección, teléfonos, correo personal y contactos de emergencia. Los datos pertenecen a la persona y se comparten entre sus relaciones laborales.

- [ ] El correo es opcional
- [ ] Los cambios hechos por el propio colaborador siguen el flujo de aprobación definido
- [ ] La condición de discapacidad se registra solo con consentimiento y como dato sensible

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §6 · C-09 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Ficha del colaborador → Información

### COL-5 · Datos laborales de la ficha

*Historia* · MVP · depende de COL-3, HIS-2

Mostrar código, empresa, sede, unidad, equipo, puesto, posición, jefe, fecha de ingreso, antigüedad, tipo de trabajador, modalidad, horario, centro de costo y estado, leídos de la asignación vigente. Cada dato muestra desde cuándo rige y enlaza a su historia.

- [ ] La antigüedad se calcula, no se digita
- [ ] Ningún dato laboral se edita aquí directamente: se registra un movimiento con fecha efectiva

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · Pedido §6 · C-03 · C-11 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Ficha del colaborador → Resumen

### COL-6 · Derechohabientes

*Historia* · MVP · depende de COL-4, DOC-1

Registrar derechohabientes (cónyuge, conviviente, hijos y otros) con documento, vínculo, fechas de alta y baja y sustentos, para asignación familiar, seguros y T-Registro. Los derechohabientes pertenecen a la persona y son datos personales de terceros con acceso restringido.

- [ ] Cada derechohabiente guarda su documento de sustento
- [ ] El cumplimiento de la mayoría de edad de un hijo genera una alerta de revisión

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · E-19 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Ficha del colaborador → Información

### COL-7 · Régimen pensionario, salud y seguros del trabajador

*Historia* · MVP · depende de COL-4, HIS-1

Registrar con vigencia el régimen pensionario (AFP con su tipo de comisión, u ONP), el régimen de salud (EsSalud o EPS con plan), y la cobertura de SCTR y Vida Ley con sus beneficiarios declarados. Estos datos son la fuente para el cálculo de aportes y para las declaraciones.

- [ ] El cambio de AFP o de tipo de comisión se guarda con fecha efectiva
- [ ] La declaración de beneficiarios de Vida Ley queda firmada y archivada

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · E-19 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Ficha del colaborador → Información

### COL-8 · Cuentas bancarias y datos de pago

*Historia* · MVP · depende de COL-4, SEG-11

Registrar las cuentas de haberes y de CTS (banco, tipo, número, moneda, código interbancario) con vigencia, validación de formato y aprobación de cambios. Una persona puede tener cuentas distintas por relación laboral y por finalidad.

- [ ] El número de cuenta es campo sensible y se muestra enmascarado
- [ ] Un cambio de cuenta exige aprobación y notifica al colaborador

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §15 · RN-COL-01 a RN-COL-13

### COL-9 · Listado de colaboradores

*Historia* · MVP · depende de COL-2, UI-4

Construir el listado principal con búsqueda, filtros (empresa, sede, unidad, puesto, estado, jefe), vistas guardadas, columnas configurables, acciones masivas y exportación. El listado es la puerta de entrada a la ficha y usa el componente de tabla del sistema.

- [ ] El listado respeta alcance y permisos por campo
- [ ] Las vistas «activos», «por ingresar» y «cesados» vienen predefinidas

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · Pedido §98 · Pedido §119 · RN-COL-01 a RN-COL-13 · Prototipo: Personas → Colaboradores

### COL-10 · Reingreso y relaciones simultáneas

*Historia* · MVP · depende de COL-2

Permitir que una persona tenga varias relaciones laborales: reingreso tras un cese y relaciones simultáneas en distintas empresas del grupo. La ficha muestra todas y permite elegir la relación activa.

- [ ] Un reingreso reutiliza la persona y crea una relación nueva sin alterar la anterior
- [ ] La historia de la relación anterior permanece consultable
- [ ] Cada relación tiene su propia compensación, contrato y saldos

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · C-01 · Pedido §104 · Pedido §7 · RN-COL-01 a RN-COL-13

### COL-11 · Registro del cese de la relación laboral

*Tarea técnica* · Backend · MVP · depende de COL-2, HIS-2

Implementar la acción de cese: fecha, motivo de catálogo, detalle y efectos (cierre de asignación y compensación, liberación de la posición, emisión de `employee.terminated`). El proceso de salida completo lo conduce el módulo de offboarding.

- [ ] El cese no borra nada: la relación pasa a estado cesado con fecha de fin
- [ ] La posición queda vacante al cesar su ocupante
- [ ] Un cese con fecha futura no altera el estado hasta esa fecha

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §104 · Pedido §17 · ME-01 · RN-COL-01 a RN-COL-13

### COL-12 · Inicio de RRHH

*Historia* · MVP · depende de COL-9, CON-4

Construir el tablero de inicio de RRHH con dotación, ingresos, salidas, ausencias de hoy, vacaciones, contratos por vencer, cumpleaños, onboarding, vacantes, pendientes, alertas, rotación y costo laboral, mediante tarjetas que cada módulo registra. Los números se obtienen de consultas agregadas que respetan el alcance del usuario.

- [ ] Cada tarjeta enlaza al listado filtrado que explica su número
- [ ] Las tarjetas de módulos no contratados no aparecen
- [ ] El costo laboral exige permiso de sueldos

Referencias: docs/modulos/M-COL.md · docs/06-reglas-de-negocio.md · Pedido §106 · RN-COL-01 a RN-COL-13 · Prototipo: Inicio

## HIS · Historia con vigencia

Registros con vigencia para asignación, compensación, contrato y jefe; nada se sobrescribe.

*Componente: Backend + Frontend*

### HIS-1 · Patrón de vigencia reutilizable

*Tarea técnica* · Backend · MVP · depende de PLT-12

Implementar el patrón de registros con `valid_from` y `valid_to`: inserción que cierra el registro anterior, restricción de no solapamiento en base de datos y consulta «vigente a una fecha». El patrón se entrega como componente compartido que usan asignación, compensación, contrato, horario y parámetros.

- [ ] La base de datos impide dos registros vigentes solapados para la misma relación
- [ ] Consultar a cualquier fecha devuelve exactamente un registro o ninguno
- [ ] Un registro histórico no se modifica: se corrige con uno nuevo

Referencias: docs/modulos/M-HIS.md · docs/04-modelo-de-datos.md · docs/06-reglas-de-negocio.md · C-03 · Pedido §7 · RN-HIS-01 a RN-HIS-09

### HIS-2 · Movimientos de personal

*Historia* · MVP · depende de HIS-1, COL-2, WFL-2

Registrar con fecha efectiva los cambios de puesto, posición, unidad, sede, jefe, horario, centro de costo y estado, con tipo de movimiento (promoción, traslado, cambio de jefe) y motivo, sujetos a aprobación según configuración. El registro de movimientos es la única vía para cambiar datos laborales.

- [ ] Un movimiento crea un registro de asignación nuevo y cierra el anterior
- [ ] Los movimientos emiten `employee.updated` con los campos que cambiaron
- [ ] Una promoción queda identificada como tal para la analítica

Referencias: docs/modulos/M-HIS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · Pedido §7 · C-03 · RN-HIS-01 a RN-HIS-09 · Prototipo: Personas → Ficha del colaborador → Historial

### HIS-3 · Compensación con vigencia

*Historia* · MVP · depende de HIS-1, SEG-9

Guardar sueldo básico, moneda, periodicidad y componentes fijos (asignaciones, bonos recurrentes) como registros con vigencia y motivo del cambio. Es la única fuente del sueldo para todos los módulos.

- [ ] Un aumento se registra con fecha efectiva y motivo, y el anterior queda en la historia
- [ ] Ver y editar compensación exige permisos por campo distintos
- [ ] Cada cambio de sueldo queda auditado con valor anterior y nuevo

Referencias: docs/modulos/M-HIS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §6 · Pedido §116 · C-03 · C-11 · RN-HIS-01 a RN-HIS-09 · Prototipo: Personas → Ficha del colaborador → Compensación

### HIS-4 · Cambios con fecha futura y correcciones retroactivas

*Tarea técnica* · Backend · MVP · depende de HIS-2, HIS-3, PLT-6

Soportar movimientos programados a futuro que entran en vigor solos y correcciones con fecha pasada que marcan los periodos afectados para recálculo. Ambos casos usan la misma operación de registro con fecha efectiva.

- [ ] Un cambio futuro se puede editar o anular antes de su fecha
- [ ] Una corrección retroactiva emite un evento con el rango de fechas afectado

Referencias: docs/modulos/M-HIS.md · docs/06-reglas-de-negocio.md · C-03 · RN-HIS-01 a RN-HIS-09

### HIS-5 · Línea de tiempo laboral

*Historia* · Frontend · MVP · depende de HIS-2, HIS-3, UI-3

Mostrar en la ficha la línea de tiempo con todos los hitos de la relación: ingreso, cambios de puesto, unidad, sede, jefe, sueldo, contrato, horario y estado, con filtros por tipo. La línea de tiempo se arma con los registros con vigencia y con los eventos de los demás módulos.

- [ ] La línea de tiempo muestra el antes y el después de cada cambio
- [ ] Los cambios de sueldo solo se ven con el permiso correspondiente

Referencias: docs/modulos/M-HIS.md · docs/06-reglas-de-negocio.md · Pedido §7 · RN-HIS-01 a RN-HIS-09 · Prototipo: Personas → Ficha del colaborador → Historial

### HIS-6 · Servicio de consulta de datos maestros

*Tarea técnica* · Backend · MVP · depende de HIS-2, HIS-3

Publicar el servicio interno único con el que los demás módulos leen sueldo, jefe, puesto, unidad y centro de costo vigentes a una fecha. Ningún módulo guarda copia editable de estos datos.

- [ ] Una prueba de arquitectura detecta tablas de otros módulos que duplican sueldo o jefe como dato editable
- [ ] El servicio responde por relación laboral y fecha

Referencias: docs/modulos/M-HIS.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · C-11 · Pedido §116 · RN-HIS-01 a RN-HIS-09

## CON · Contratos y adendas

Contratos por modalidad con vigencia, renovaciones, adendas, alertas de vencimiento y de tope acumulado.

*Componente: Backend + Frontend*

### CON-1 · Tipos de contrato y modalidades

*Tarea técnica* · Datos · MVP · depende de CFG-3

Cargar el catálogo de tipos de contrato (indeterminado, modalidades a plazo, tiempo parcial, convenios formativos) con sus plazos máximos y periodo de prueba como parámetros con vigencia por país. Cada tipo indica si exige fecha de fin, causa objetiva y qué plantilla de documento usa.

- [ ] Los plazos máximos por modalidad se leen de parámetros, no del código
- [ ] La organización puede agregar tipos propios

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · Pedido §18 · E-08 · RN-CON-01 a RN-CON-10

### CON-2 · Registro del contrato

*Historia* · MVP · depende de CON-1, COL-2, HIS-1

Registrar el contrato de cada relación laboral: tipo, causa objetiva, inicio, fin, periodo de prueba, jornada y documento. El contrato se guarda con vigencia y sigue su máquina de estados (borrador, en firma, vigente, vencido, terminado).

- [ ] Un contrato a modalidad exige causa objetiva y fecha de fin
- [ ] El fin del periodo de prueba genera una alerta al jefe y a RRHH
- [ ] El contrato vigente se ve en la ficha con sus documentos

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · Pedido §18 · Pedido §6 · ME-11 · RN-CON-01 a RN-CON-10 · Prototipo: Personas → Ficha del colaborador → Contrato

### CON-3 · Renovaciones y adendas

*Historia* · MVP · depende de CON-2

Registrar renovaciones y adendas (cambio de sueldo, puesto, jornada, lugar) enlazadas al contrato original, cada una con su documento y sus firmas. Una renovación extiende el plazo; una adenda modifica condiciones sin cambiar de contrato.

- [ ] La cadena de contrato, renovaciones y adendas se ve en orden
- [ ] Una adenda de sueldo se vincula al registro de compensación correspondiente

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §18 · RN-CON-01 a RN-CON-10

### CON-4 · Alertas de vencimiento y de tope acumulado

*Historia* · MVP · depende de CON-3, NOT-1

Listar contratos próximos a vencer con anticipación configurable y calcular el tiempo acumulado por modalidad para avisar antes de superar el plazo máximo que desnaturaliza el contrato. El cálculo considera todos los contratos de la persona con la misma empresa.

- [ ] La alerta explica cuánto tiempo acumulado lleva el trabajador y cuál es el tope
- [ ] La anticipación de la alerta se configura por empresa
- [ ] Renovar más allá del tope exige confirmación expresa con motivo

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-08 · Pedido §18 · RN-CON-01 a RN-CON-10 · Prototipo: Operaciones → Contratos

### CON-5 · Renovación masiva con firma

*Historia* · MVP · depende de CON-4, DOC-6

Seleccionar varios contratos por vencer, definir la nueva fecha de fin, generar las renovaciones desde plantilla y enviarlas a firma en lote. Cada renovación del lote queda registrada como en el flujo individual.

- [ ] El lote muestra el avance de firma por contrato
- [ ] Un contrato del lote que supera el tope acumulado se excluye y se informa

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · E-08 · Pedido §18 · RN-CON-01 a RN-CON-10

### CON-6 · Acuerdo de teletrabajo

*Historia* · MVP · depende de CON-3, DOC-4

Generar y registrar el acuerdo de teletrabajo con su contenido mínimo: modalidad total o parcial, jornada, desconexión digital, lugar, supervisión, equipos y compensación de gastos. Atender las solicitudes de cambio de modalidad con su plazo de respuesta.

- [ ] El acuerdo no se puede emitir si falta alguno de los contenidos mínimos
- [ ] La solicitud de teletrabajo muestra el plazo legal de respuesta y avisa antes de vencer

Referencias: docs/modulos/M-CON.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-20 · RN-CON-01 a RN-CON-10

## DOC · Gestión documental, plantillas y firma

Repositorio de documentos con permisos, plantillas dinámicas, generación de PDF y firma electrónica con evidencia.

*Componente: Backend + Frontend*

### DOC-1 · Repositorio y carpetas del legajo

*Historia* · MVP · depende de PLT-7, SEG-12, COL-3

Construir el repositorio con carpetas (personal, contratos, boletas, certificados, vacaciones, SST, evaluaciones, capacitaciones) por colaborador y por empresa, tipos de documento y permisos por carpeta y por documento. Los archivos se guardan mediante el servicio de archivos y heredan su control de acceso.

- [ ] Un documento marcado confidencial solo lo ven los roles autorizados
- [ ] El colaborador ve únicamente sus documentos publicados
- [ ] Subir, descargar y previsualizar funcionan desde la ficha

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §19 · RN-DOC-01 a RN-DOC-13 · Prototipo: Operaciones → Documentos

### DOC-2 · Versiones, archivo y vencimientos

*Historia* · MVP · depende de DOC-1

Versionar documentos, archivarlos sin borrarlos y registrar fecha de vencimiento por tipo (carné, certificado, licencia) con alertas. El tipo de documento define si requiere vencimiento y con qué anticipación se avisa.

- [ ] Subir una versión nueva conserva las anteriores
- [ ] Los documentos por vencer aparecen en una lista con su responsable

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · Pedido §19 · Pedido §68 · RN-DOC-01 a RN-DOC-13

### DOC-3 · Solicitud y aprobación de documentos

*Historia* · MVP · depende de DOC-1, WFL-9

Permitir que RRHH solicite documentos a uno o varios colaboradores, que estos los carguen desde su portal y que RRHH los apruebe o rechace con motivo. La solicitud define tipo de documento, fecha límite y destinatarios por filtro.

- [ ] El colaborador ve qué documentos le faltan
- [ ] Un documento rechazado vuelve al colaborador con la observación

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §19 · ME-12 · RN-DOC-01 a RN-DOC-13

### DOC-4 · Plantillas dinámicas con variables

*Historia* · MVP · depende de DOC-1

Crear plantillas de documentos con variables (`{{empleado.nombre}}`, `{{empleado.cargo}}`) tomadas de la ficha, bloques condicionales, encabezado de la empresa y control de versiones. Las plantillas se organizan por tipo de documento y por empresa.

- [ ] El editor ofrece el catálogo de variables disponibles
- [ ] Una variable inexistente impide publicar la plantilla
- [ ] La vista previa usa los datos de un colaborador real

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · Pedido §20 · Pedido §14 · RN-DOC-01 a RN-DOC-13 · Prototipo: Operaciones → Documentos

### DOC-5 · Generación de PDF

*Tarea técnica* · Backend · MVP · depende de DOC-4, PLT-6

Generar PDF desde plantillas, uno a uno y en lote, en segundo plano, y guardarlos en el legajo con su tipo. Incluye el certificado de trabajo y constancias de uso frecuente.

- [ ] El certificado de trabajo se genera con datos vigentes de la ficha
- [ ] La generación en lote informa avance y errores

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · Pedido §20 · RN-DOC-01 a RN-DOC-13

### DOC-6 · Flujo de firma

*Historia* · MVP · depende de DOC-5, WFL-9, NOT-1

Implementar el flujo de firma: documento, firmantes, orden, envío, visto, firmado y completado, con recordatorios, rechazo con motivo y firma en lote para documentos masivos. El flujo es común a contratos, adendas, constancias, actas y cualquier documento de otros módulos.

- [ ] Un firmante no puede firmar antes que quien le precede en el orden
- [ ] El estado de cada firmante se ve en tiempo real
- [ ] Los documentos por firmar aparecen en la bandeja de pendientes

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §21 · ME-10 · RN-DOC-01 a RN-DOC-13 · Prototipo: Operaciones → Firma electrónica

### DOC-7 · Evidencia de firma electrónica propia

*Tarea técnica* · Backend · MVP · depende de DOC-6, SEG-2

Generar la evidencia de cada firma: hash del documento, sello de tiempo, IP, dispositivo, verificación por OTP e identidad del firmante, y una constancia adjunta al PDF final. La evidencia se almacena de forma inalterable junto al documento.

- [ ] Cualquier alteración posterior del documento es detectable con el hash
- [ ] La constancia de firma lista a cada firmante con su evidencia
- [ ] La firma exige OTP o reautenticación

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · C-12 · Pedido §21 · RN-DOC-01 a RN-DOC-13

### DOC-8 · Capa de proveedor de firma digital

*Tarea técnica* · Backend · MVP · depende de DOC-7, PLT-13

Definir el adaptador de proveedor de firma digital con certificado y su implementación falsa, de modo que la organización elija por tipo de documento entre firma electrónica propia o firma con proveedor. El flujo de firma es el mismo; solo cambia el paso de firma y la evidencia que devuelve el proveedor.

- [ ] Cambiar de proveedor no modifica el flujo de firma
- [ ] El tipo de firma usado queda registrado en el documento

Referencias: docs/modulos/M-DOC.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · C-12 · Pedido §21 · Pedido §75 · RN-DOC-01 a RN-DOC-13

## ESS · Portal del colaborador

Autoservicio del colaborador: inicio personalizado, perfil, documentos, firma, constancias y solicitudes.

*Componente: Backend + Frontend*

### ESS-1 · Inicio del colaborador

*Historia* · MVP · depende de WFL-9, COL-3

Construir el inicio personal con saludo, pendientes y tarjetas (mi perfil, mi horario, mis vacaciones, mis documentos, mis boletas, mis objetivos, mis cursos, mis solicitudes) que cada módulo registra cuando está activo. El inicio se adapta al móvil y prioriza lo pendiente.

- [ ] El inicio muestra solo tarjetas de módulos contratados
- [ ] Los pendientes llevan a la pantalla donde se resuelven

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §8 · Pedido §108 · RN-ESS-01 a RN-ESS-06 · Prototipo: Inicio

### ESS-2 · Mi perfil y actualización de datos

*Historia* · MVP · depende de COL-4, WFL-2

Permitir que el colaborador vea su ficha y actualice los campos que la organización habilita (dirección, teléfono, contacto de emergencia, cuenta bancaria), con aprobación de RRHH cuando se configure. Los campos editables y los que requieren aprobación se configuran por organización.

- [ ] El colaborador no puede editar campos no habilitados
- [ ] Un cambio pendiente de aprobación se muestra como tal

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §8 · RN-ESS-01 a RN-ESS-06 · Prototipo: Personas → Ficha del colaborador

### ESS-3 · Mis documentos, contrato y firma

*Historia* · MVP · depende de DOC-6, DOC-1

Mostrar al colaborador su contrato, sus documentos publicados y los documentos por firmar, con firma desde el teléfono. Los documentos se abren en un visor dentro de la aplicación y cada apertura queda registrada.

- [ ] El colaborador firma un documento desde el móvil en pocos pasos
- [ ] Solo ve documentos publicados para él

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §8 · Pedido §85 · RN-ESS-01 a RN-ESS-06 · Prototipo: Operaciones → Documentos

### ESS-4 · Solicitud de certificados y constancias

*Historia* · MVP · depende de DOC-5, ESS-1

Permitir solicitar certificados y constancias de un catálogo. Las de emisión automática se generan al instante desde plantilla; las demás pasan a RRHH.

- [ ] La constancia de trabajo se obtiene sin intervención de RRHH cuando así se configura
- [ ] Cada emisión queda registrada en el legajo

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §8 · Pedido §20 · RN-ESS-01 a RN-ESS-06

### ESS-5 · Solicitudes a RRHH desde el portal

*Historia* · MVP · depende de ESS-1, WFL-2

Ofrecer un punto único para crear solicitudes a RRHH con tipos configurables y seguimiento de estado. En esta fase es un formulario con flujo de aprobación; la mesa de ayuda lo amplía después.

- [ ] El colaborador ve el estado y el historial de cada solicitud
- [ ] RRHH recibe la solicitud en su bandeja

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §8 · RN-ESS-01 a RN-ESS-06

### ESS-6 · Portal del colaborador en el móvil

*Tarea técnica* · Frontend · MVP · depende de ESS-1, UI-9, SEG-2

Optimizar el portal para el teléfono: navegación inferior, acciones principales a un toque e ingreso con documento. Preparar los puntos de extensión para marcar asistencia, pedir vacaciones, ver boletas y responder encuestas cuando esos módulos se activen.

- [ ] Las tareas del colaborador se completan en una pantalla de 360 px sin desplazamiento horizontal
- [ ] La sesión del móvil permite reingreso rápido con PIN o biometría del dispositivo

Referencias: docs/modulos/M-ESS.md · docs/06-reglas-de-negocio.md · Pedido §85 · C-09 · RN-ESS-01 a RN-ESS-06

## MSS · Portal del manager

Experiencia del responsable de equipo: su gente, solicitudes, fechas clave y aprobaciones.

*Componente: Backend + Frontend*

### MSS-1 · Inicio del manager

*Historia* · MVP · depende de ESS-1, SEG-10

Construir el inicio del manager con su equipo, quién está ausente, solicitudes por aprobar, objetivos, evaluaciones, cumpleaños y pendientes, con tarjetas registradas por los módulos activos. Un usuario que es manager y colaborador alterna entre ambas vistas.

- [ ] El manager ve solo a sus subordinados directos e indirectos
- [ ] Las tarjetas enlazan al detalle filtrado por su equipo

Referencias: docs/modulos/M-MSS.md · docs/06-reglas-de-negocio.md · Pedido §9 · Pedido §107 · RN-MSS-01 a RN-MSS-05 · Prototipo: Inicio

### MSS-2 · Mi equipo

*Historia* · MVP · depende de MSS-1, COL-9

Mostrar el listado del equipo con ficha resumida de cada persona, carga del equipo y acceso a los datos que el manager tiene permitido ver. La vista incluye la estructura de reporte hacia abajo y las plazas vacantes del equipo.

- [ ] El sueldo del equipo solo se ve si el rol tiene el permiso por campo
- [ ] Desde el listado se inicia un movimiento o una solicitud para un subordinado

Referencias: docs/modulos/M-MSS.md · docs/06-reglas-de-negocio.md · Pedido §9 · C-10 · RN-MSS-01 a RN-MSS-05 · Prototipo: Inicio

### MSS-3 · Aprobaciones y solicitudes del equipo

*Historia* · MVP · depende de MSS-1, WFL-4, HIS-2

Integrar el centro de aprobaciones en el portal del manager y permitirle iniciar solicitudes para su equipo: movimiento, nueva posición o cambio de horario. El manager solo ve las solicitudes de las personas bajo su alcance.

- [ ] El manager aprueba desde el móvil
- [ ] Una solicitud iniciada por el manager sigue el flujo configurado

Referencias: docs/modulos/M-MSS.md · docs/06-reglas-de-negocio.md · Pedido §9 · Pedido §109 · RN-MSS-01 a RN-MSS-05

### MSS-4 · Fechas clave y alertas del equipo

*Historia* · MVP · depende de MSS-1, CON-4

Mostrar cumpleaños, aniversarios, fines de periodo de prueba y contratos por vencer del equipo, con recordatorios. Los avisos usan el motor de notificaciones y respetan las preferencias del manager.

- [ ] El manager recibe aviso antes del fin del periodo de prueba de un subordinado
- [ ] La organización puede ocultar los cumpleaños

Referencias: docs/modulos/M-MSS.md · docs/06-reglas-de-negocio.md · Pedido §9 · RN-MSS-01 a RN-MSS-05

## IMP · Importador y puesta en marcha

Importación masiva con validación, asistente de primeros pasos, plantillas peruanas y organización demo.

*Componente: Backend + Frontend*

### IMP-1 · Motor de importación

*Tarea técnica* · Backend · MVP · depende de PLT-6, PLT-7

Implementar el motor de importación por pasos: subir CSV o Excel, mapear columnas, validar, previsualizar e importar en segundo plano. Los módulos registran sus importadores con sus reglas.

- [ ] Nunca se importa sin pasar por validación y vista previa
- [ ] La importación es transaccional por lote configurable y puede revertirse
- [ ] Las filas con error se descargan con el motivo por fila

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/02-arquitectura.md · Pedido §71 · ME-41 · RN-IMP-01 a RN-IMP-09

### IMP-2 · Importadores de Core HR

*Historia* · MVP · depende de IMP-1, COL-2, HIS-2, CON-2

Entregar los importadores de estructura, puestos, posiciones, colaboradores, contratos, sueldos e históricos de movimientos, con plantillas descargables y ejemplos. Cada importador valida contra catálogos, vigencias y reglas del módulo dueño.

- [ ] La importación de colaboradores detecta personas duplicadas por documento
- [ ] Los históricos se cargan como registros con vigencia
- [ ] El mapeo de columnas se guarda para reutilizarlo

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · Pedido §71 · C-03 · RN-IMP-01 a RN-IMP-09 · Prototipo: Configuración → Importar datos

### IMP-3 · Asistente de primeros pasos

*Historia* · MVP · depende de IMP-2, CFG-1

Guiar al administrador nuevo con una lista de pasos (empresa, sedes, estructura, puestos, colaboradores, roles, políticas) con avance visible, que puede retomarse y saltarse. El asistente se oculta cuando la organización termina o decide omitirlo.

- [ ] El asistente muestra el porcentaje de avance de la puesta en marcha
- [ ] Cada paso lleva a la pantalla real, no a una copia

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · E-27 · RN-IMP-01 a RN-IMP-09 · Prototipo: Inicio → Primeros pasos

### IMP-4 · Plantillas peruanas listas para usar

*Tarea técnica* · Datos · MVP · depende de CON-1, DOC-4, CFG-8

Preparar los paquetes de configuración inicial para el Perú: tipos de contrato, tipos de ausencia, feriados, plantillas de contrato y constancias, políticas de vacaciones por régimen y roles sugeridos, aplicables al crear la organización. Cada paquete se versiona y declara qué crea en la organización.

- [ ] Una organización nueva puede aplicar el paquete y operar sin configurar desde cero
- [ ] Cada plantilla aplicada puede editarse después

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-27 · RN-IMP-01 a RN-IMP-09

### IMP-5 · Organización demo coherente

*Tarea técnica* · Datos · MVP · depende de IMP-2

Sembrar una organización demo (Empresa Demo SAC con Gerencia General, RRHH, Ventas, Tecnología y Operaciones) con colaboradores relacionados, jefaturas, contratos e historia. Cada fase posterior amplía la semilla con sus datos.

- [ ] Los datos demo permiten recorrer vacante, candidato, contratación, onboarding, asistencia, planilla, evaluación, capacitación, vacaciones y offboarding
- [ ] Las fechas de la semilla son relativas al día de carga
- [ ] No hay registros aleatorios sin sentido

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · Pedido §113 · RN-IMP-01 a RN-IMP-09

### IMP-6 · Informe y reversión de importaciones

*Historia* · MVP · depende de IMP-1

Mostrar el historial de importaciones con autor, archivo, resultado, filas creadas, actualizadas y rechazadas, y permitir revertir una importación completa mientras no tenga dependencias. La reversión elimina solo lo creado por esa importación y restaura lo que actualizó.

- [ ] Revertir una importación deja los datos como estaban
- [ ] El historial permite descargar el archivo original y el informe de errores

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · Pedido §71 · RN-IMP-01 a RN-IMP-09

### IMP-7 · Importadores desde otros sistemas de RRHH y planilla

*Historia* · MVP · depende de IMP-2, IMP-6

Ofrecer plantillas de importación que aceptan tal cual las exportaciones más comunes del mercado peruano (otras suites de RRHH, sistemas de planilla tradicionales y hojas de cálculo con la estructura de PLAME y T-Registro) y las traducen a los importadores de Core HR. El mapeo de cada origen es un perfil versionado que se puede corregir sin desplegar. El objetivo es que una empresa cargue su estructura, colaboradores, contratos y sueldos el primer día.

- [ ] Un archivo exportado de un sistema conocido se importa sin reordenar columnas a mano
- [ ] Cada fila rechazada indica el campo, el valor y la corrección esperada
- [ ] Un perfil de origen nuevo se agrega como configuración, sin cambiar código
- [ ] La importación usa los mismos validadores y la misma reversión que IMP-2 e IMP-6

Referencias: docs/modulos/M-IMP.md · docs/06-reglas-de-negocio.md · Pedido §71 · E-27 · RN-IMP-01 a RN-IMP-09 · Prototipo: Configuración → Importar datos

## AYU · Centro de ayuda

Ayuda disponible en todos los roles y pantallas: guías del producto, ayuda contextual, políticas de la propia organización, buscador y derivación a RRHH o a soporte.

*Componente: Backend + Frontend*

### AYU-1 · Contenido de ayuda del producto con versiones

*Tarea técnica* · Backend · MVP · depende de SAS-1, SAS-5, CFG-6

Implementar el repositorio de artículos de ayuda del producto que GestiaHR redacta y publica desde la consola: cada artículo declara módulo, roles destinatarios, pantallas a las que aplica, idioma y versión del producto. Publicar una versión nueva conserva la anterior y el índice de búsqueda se actualiza al publicar.

- [ ] Cada artículo declara módulo, roles destinatarios y claves de pantalla
- [ ] Publicar una versión nueva conserva la anterior consultable desde la consola
- [ ] Un artículo sin traducción al idioma del usuario se muestra en español con aviso

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/10-pantallas-y-ux.md · Pedido §80 · RN-AYU-01 a RN-AYU-07 · Prototipo: Consola de la plataforma → Ayuda del producto

### AYU-2 · Centro de ayuda para todos los roles

*Historia* · MVP · depende de AYU-1, UI-2, SEG-7, SAS-5

Ofrecer un botón de ayuda en la barra superior de todas las pantallas, también en el portal del colaborador en el móvil, que abre el centro de ayuda con guías por rol y por módulo. El centro muestra solo los artículos de módulos contratados y dirigidos al rol del usuario.

- [ ] El botón de ayuda aparece en todas las pantallas para los once roles de sistema
- [ ] Un usuario no ve artículos de módulos no contratados ni de pantallas que no puede abrir
- [ ] El centro de ayuda funciona aunque el asistente de IA esté apagado

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/10-pantallas-y-ux.md · Pedido §85 · RN-AYU-01 a RN-AYU-07 · Prototipo: Barra superior → Ayuda

### AYU-3 · Ayuda contextual por pantalla

*Historia* · MVP · depende de AYU-2, UI-6

Asociar cada pantalla a su clave de ayuda para que el centro abra directamente en los artículos de esa pantalla, y mostrar ayudas breves junto a los campos que lo necesiten. Los estados vacíos enlazan a la guía del módulo.

- [ ] Al abrir la ayuda desde una pantalla se muestran primero sus artículos
- [ ] Las ayudas de campo salen del mismo contenido versionado, no de textos sueltos en el código
- [ ] Una prueba falla si una pantalla del menú no tiene clave de ayuda

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/10-pantallas-y-ux.md · RN-AYU-01 a RN-AYU-07 · Prototipo: Barra superior → Ayuda → Esta pantalla

### AYU-4 · Artículos y políticas de la organización

*Historia* · MVP · depende de AYU-2, SEG-10, NOT-2

Permitir que el administrador y RRHH publiquen artículos propios, como el reglamento interno o la política de vacaciones, con audiencia por rol, empresa o sede, responsable y fecha de revisión. Los artículos se versionan y avisan al responsable cuando toca revisarlos.

- [ ] Un artículo propio solo lo ve la audiencia elegida
- [ ] Cada artículo tiene responsable, versión y fecha de revisión
- [ ] Al vencer la fecha de revisión se avisa al responsable y el artículo se marca por revisar

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §54 · RN-AYU-01 a RN-AYU-07 · Prototipo: Configuración → Ayuda y asistente → Artículos de la organización

### AYU-5 · Buscador de ayuda y valoración de artículos

*Historia* · MVP · depende de AYU-2, AYU-4

Ofrecer búsqueda de texto completo en español sobre la ayuda del producto y los artículos de la organización, filtrada por lo que el usuario puede ver, con la pregunta «¿te sirvió?» en cada artículo. Las búsquedas sin resultado se registran sin datos personales para mejorar el contenido.

- [ ] La búsqueda tolera tildes, mayúsculas y plurales
- [ ] Cada artículo muestra su valoración al responsable del contenido
- [ ] La consola y el administrador ven las búsquedas sin resultado más frecuentes

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · RN-AYU-01 a RN-AYU-07 · Prototipo: Barra superior → Ayuda → Guías

### AYU-6 · Derivar la consulta a RRHH o a soporte

*Historia* · MVP · depende de AYU-5, ESS-5, SAS-8

Permitir que, si la ayuda no resuelve la duda, el usuario la derive a RRHH como solicitud con su pregunta y los artículos que consultó. El administrador de la organización puede además escribir al soporte de GestiaHR, que recibe el caso en la consola.

- [ ] La derivación crea una solicitud a RRHH con la pregunta y los artículos consultados
- [ ] El usuario sigue el estado de su consulta desde el centro de ayuda
- [ ] Solo el administrador puede escribir al soporte de GestiaHR

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · Pedido §53 · RN-AYU-01 a RN-AYU-07 · Prototipo: Barra superior → Ayuda → Mis consultas

### AYU-7 · Recorridos guiados y novedades de versión

*Historia* · MVP · depende de AYU-2, UI-2

Mostrar un recorrido breve por rol en el primer ingreso y una sección de novedades con los cambios de cada versión del producto. El usuario puede saltar el recorrido y volver a abrirlo desde la ayuda.

- [ ] Cada rol de sistema tiene su recorrido de primer ingreso
- [ ] Las novedades muestran solo los cambios de módulos contratados

Referencias: docs/modulos/M-AYU.md · docs/06-reglas-de-negocio.md · docs/10-pantallas-y-ux.md · RN-AYU-01 a RN-AYU-07 · Prototipo: Barra superior → Ayuda → Guías

# Fase 2 · Tiempo

## ASI · Asistencia y marcación

Registro inalterable de marcaciones por múltiples medios, cálculo diario, incidencias y reportes legales.

*Componente: Backend + Frontend*

### ASI-1 · Registro de marcaciones inalterable

*Tarea técnica* · Backend · depende de HIS-1, AUD-1

Modelar las marcaciones como registros de solo inserción con trabajador, empresa, momento con zona horaria, tipo (entrada, salida, inicio y fin de descanso), medio, ubicación y evidencia. Las correcciones son registros nuevos enlazados al original, con motivo y autor.

- [ ] No existe operación que modifique o borre una marcación
- [ ] Cada corrección conserva el original y muestra quién la hizo y por qué
- [ ] El registro contiene los datos exigidos por el DS 004-2006-TR

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · C-07 · Pedido §22 · ME-13 · RN-ASI-01 a RN-ASI-14

### ASI-2 · Marcación web

*Historia* · depende de ASI-1, ESS-1

Permitir marcar entrada, salida y descansos desde la web con verificación de la política aplicable (red permitida o ubicación) y confirmación visible de la marca. La marca toma la hora del servidor, no la del navegador.

- [ ] El colaborador ve sus marcas del día y el tiempo trabajado
- [ ] Una marca fuera de la política se registra como observada, no se descarta

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · Pedido §22 · RN-ASI-01 a RN-ASI-14 · Prototipo: Tiempo → Asistencia → Tablero del día

### ASI-3 · Marcación desde la app con selfie y geocerca

*Historia* · depende de ASI-2, ESS-6, ORG-2

Permitir marcar desde el teléfono con foto, ubicación y validación de geocerca de la sede o del punto de trabajo asignado. La biometría facial es opcional y requiere consentimiento y evaluación de impacto.

- [ ] Una marca fuera de la geocerca se acepta como observada y avisa al supervisor
- [ ] La foto se guarda como evidencia con acceso restringido
- [ ] Sin consentimiento registrado no se activa la comparación facial

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-07 · Pedido §22 · Pedido §85 · C-08 · RN-ASI-01 a RN-ASI-14 · Prototipo: Tiempo → Asistencia → Tablero del día

### ASI-4 · Kiosko con QR y PIN

*Historia* · depende de ASI-1, SEG-2

Ofrecer el modo kiosko para una tableta compartida: el trabajador se identifica con QR personal rotativo o con documento y PIN, con foto opcional. El kiosko se registra como dispositivo autorizado de una sede.

- [ ] El QR personal caduca y no puede reutilizarse como captura de pantalla
- [ ] El kiosko solo permite marcar; no expone otros datos
- [ ] El PIN se bloquea tras intentos fallidos

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · E-07 · Pedido §22 · RN-ASI-01 a RN-ASI-14

### ASI-5 · Marcación de cuadrilla

*Historia* · depende de ASI-3, SEG-10

Permitir que un supervisor marque la entrada y salida de su cuadrilla en campo, uno a uno o en bloque, con ubicación y foto del grupo. La asignación de cuadrillas se mantiene por supervisor y fecha.

- [ ] El supervisor solo puede marcar a los trabajadores asignados a su cuadrilla
- [ ] Cada marca indica que fue registrada por el supervisor

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · E-07 · Pedido §22 · RN-ASI-01 a RN-ASI-14

### ASI-6 · Marcación sin conexión

*Tarea técnica* · depende de ASI-3, PLT-8

Guardar las marcas en el dispositivo cuando no hay conexión, con hora del dispositivo y control de manipulación del reloj, y sincronizarlas al recuperar señal sin duplicar. El dispositivo guarda las marcas cifradas hasta sincronizarlas.

- [ ] Una marca hecha sin conexión llega al servidor al reconectar con su hora original
- [ ] La sincronización repetida no duplica marcas
- [ ] Un desfase sospechoso de reloj marca el registro como observado

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · E-07 · RN-ASI-01 a RN-ASI-14

### ASI-7 · Conectores abiertos a relojes biométricos

*Tarea técnica* · Backend · depende de ASI-1, PLT-13

Publicar la interfaz de ingesta de marcaciones para relojes biométricos (API de recepción, carga de archivos y adaptador por fabricante) con mapeo de identificadores de reloj a trabajadores. Cada reloj se registra con su sede y su zona horaria.

- [ ] Las marcas de un reloj entran por API o por archivo con el mismo resultado
- [ ] Las marcas de identificadores sin mapear quedan en una bandeja para resolver
- [ ] El formato de ingesta está documentado para terceros

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/08-api.md · E-07 · Pedido §22 · Pedido §75 · RN-ASI-01 a RN-ASI-14

### ASI-8 · Cálculo diario de asistencia

*Tarea técnica* · Backend · depende de ASI-1, TUR-2, AUS-6

Calcular por trabajador y día las horas trabajadas, tardanzas, faltas, salidas anticipadas, sobretiempo y horas nocturnas comparando las marcas con el horario y las ausencias aprobadas. El cálculo se rehace cuando cambia una marca, un turno o una ausencia.

- [ ] Una ausencia aprobada no genera falta
- [ ] El recálculo de una fecha no altera periodos de planilla ya cerrados: genera una diferencia
- [ ] Las tolerancias se leen de la política vigente
- [ ] Con el módulo activo, el resumen de ausencias para planilla (AUS-6) toma de este cálculo los días laborados

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · Pedido §22 · RN-ASI-01 a RN-ASI-14

### ASI-9 · Incidencias y regularización

*Historia* · depende de ASI-8, WFL-2

Listar incidencias (marca faltante, tardanza, falta, marca observada) y permitir que el trabajador solicite la regularización y que su jefe la apruebe. La regularización indica la hora correcta y su sustento.

- [ ] Aprobar una regularización crea la corrección enlazada a la marca original
- [ ] El jefe ve las incidencias de su equipo por día

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §22 · C-07 · ME-14 · RN-ASI-01 a RN-ASI-14 · Prototipo: Tiempo → Asistencia → Registro

### ASI-10 · Tablero y reporte legal de asistencia

*Historia* · depende de ASI-8, UI-11

Construir el tablero de asistencia en vivo y el reporte de control de asistencia con el contenido legal (razón social, RUC, trabajador, documento, fecha, horas y minutos de ingreso y salida, sobretiempo), exportable por periodo. El reporte se genera desde el registro inalterable e incluye las correcciones con su motivo.

- [ ] El reporte legal puede generarse para cualquier periodo dentro del plazo de conservación
- [ ] El tablero muestra presentes, ausentes y tardanzas del día por sede

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §22 · RN-ASI-01 a RN-ASI-14 · Prototipo: Tiempo → Asistencia

### ASI-11 · Importación de asistencia

*Historia* · depende de ASI-1, IMP-1

Registrar el importador de marcaciones y de resúmenes de asistencia para la carga inicial o para empresas que aún no marcan en el sistema. Usa el motor de importación y las mismas validaciones que la ingesta por conectores.

- [ ] Las marcas importadas se identifican por su origen
- [ ] La importación valida trabajador, fecha y solapamientos

Referencias: docs/modulos/M-ASI.md · docs/06-reglas-de-negocio.md · Pedido §71 · RN-ASI-01 a RN-ASI-14

## TUR · Horarios, jornadas y turnos

Jornadas, horarios, políticas de tiempo, turnos y planificación visual.

*Componente: Backend + Frontend*

### TUR-1 · Jornadas y horarios

*Historia* · depende de HIS-1, CFG-3

Definir jornadas (horas diarias y jornada semanal máxima) y horarios con entrada, salida, descansos y días laborables, con vigencia y asignación por trabajador. Un horario puede ser fijo, flexible con franja de entrada o por turnos.

- [ ] Un horario se asigna a un trabajador con fecha efectiva
- [ ] La jornada máxima se lee de parámetros por país y régimen

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §24 · RN-TUR-01 a RN-TUR-08 · Prototipo: Tiempo → Turnos y horarios → Horarios

### TUR-2 · Políticas de tiempo por empresa, sede o grupo

*Historia* · depende de TUR-1, CFG-8

Configurar políticas de tolerancia, descansos, redondeo, tratamiento de sobretiempo y feriados, aplicables por empresa, sede o grupo de trabajadores. La política se resuelve del nivel más específico al más general.

- [ ] Dos sedes de la misma empresa pueden tener tolerancias distintas
- [ ] La política aplicable a un trabajador en una fecha es determinable y visible

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · Pedido §24 · RN-TUR-01 a RN-TUR-08

### TUR-3 · Catálogo de turnos

*Historia* · depende de TUR-1

Crear turnos (mañana, tarde, noche, partido, rotativo) con horas, descansos, color y si generan horas nocturnas. Los turnos se agrupan por sede y se reutilizan en el planificador.

- [ ] Un turno partido admite dos tramos en el mismo día
- [ ] Un turno nocturno que cruza la medianoche se calcula sobre el día de inicio

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §23 · RN-TUR-01 a RN-TUR-08

### TUR-4 · Calendario de turnos con arrastrar y soltar

*Historia* · depende de TUR-3, UI-12

Construir el planificador visual por equipo y periodo: arrastrar turnos sobre personas y días, copiar un periodo anterior y ver conflictos con ausencias y descansos mínimos. El planificador muestra las horas planificadas por persona frente a su jornada.

- [ ] Asignar un turno a alguien con vacaciones aprobadas muestra el conflicto
- [ ] El planificador avisa si no se respeta el descanso mínimo entre turnos
- [ ] Los cambios se guardan como borrador hasta publicar

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · Pedido §23 · RN-TUR-01 a RN-TUR-08 · Prototipo: Tiempo → Turnos y horarios

### TUR-5 · Asignación masiva y patrones rotativos

*Historia* · depende de TUR-4

Asignar turnos en bloque por unidad o grupo y definir patrones rotativos que se repiten en el tiempo. Los patrones se guardan como plantillas reutilizables.

- [ ] Un patrón rotativo se aplica a un grupo por un rango de fechas
- [ ] La asignación masiva informa cuántas personas y días afecta antes de confirmar

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · Pedido §23 · RN-TUR-01 a RN-TUR-08

### TUR-6 · Publicación de turnos y cambios entre compañeros

*Historia* · depende de TUR-4, ESS-1, WFL-2

Publicar la planificación para que cada trabajador vea su horario en el portal, y permitir solicitar intercambio de turno con un compañero con aprobación del jefe. Los cambios posteriores a la publicación quedan registrados con autor.

- [ ] Al publicar, los trabajadores afectados reciben aviso
- [ ] Un intercambio aprobado actualiza ambos calendarios

Referencias: docs/modulos/M-TUR.md · docs/06-reglas-de-negocio.md · Pedido §8 · Pedido §108 · RN-TUR-01 a RN-TUR-08 · Prototipo: Tiempo → Turnos y horarios

## VAC · Vacaciones

Saldos, solicitudes con aprobación, programación y calendario del equipo.

*Componente: Backend + Frontend*

### VAC-1 · Política de vacaciones por régimen

*Tarea técnica* · Backend · MVP · depende de CFG-3, ORG-1

Configurar con parámetros los días de vacaciones por régimen laboral, el requisito de récord, el fraccionamiento, el adelanto y la reducción por venta de días. La política se asigna por empresa y puede variar por grupo de trabajadores.

- [ ] Un trabajador de microempresa y uno del régimen general devengan días distintos según parámetros
- [ ] La política se cambia con vigencia sin tocar el código

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §25 · C-05 · RN-VAC-01 a RN-VAC-13

### VAC-2 · Devengo y saldos de vacaciones

*Tarea técnica* · Backend · MVP · depende de VAC-1, COL-2

Calcular el devengo por periodo vacacional de cada relación laboral y mantener un libro de movimientos de saldo: generadas, tomadas, programadas, pendientes y disponibles. El saldo es la suma del libro.

- [ ] El saldo mostrado siempre se puede explicar por sus movimientos
- [ ] El saldo se lleva por relación laboral, no por persona
- [ ] Los periodos vencidos se identifican

Referencias: docs/modulos/M-VAC.md · docs/04-modelo-de-datos.md · docs/06-reglas-de-negocio.md · Pedido §25 · C-11 · RN-VAC-01 a RN-VAC-13

### VAC-3 · Solicitud y aprobación de vacaciones

*Historia* · MVP · depende de VAC-2, WFL-2, ESS-1

Permitir que el colaborador solicite vacaciones viendo su saldo y el calendario del equipo; aprueba el responsable y RRHH valida si la política lo exige. Al aprobar se actualizan saldo, calendario y tableros, y se emite `leave.approved`.

- [ ] No se puede solicitar más días que los disponibles salvo adelanto permitido
- [ ] Aprobar actualiza solicitud, saldo, calendario, tablero y actividad en una sola operación
- [ ] Anular una solicitud aprobada devuelve los días al saldo

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §25 · Pedido §115 · ME-15 · RN-VAC-01 a RN-VAC-13 · Prototipo: Tiempo → Vacaciones

### VAC-4 · Calendario de ausencias del equipo

*Historia* · MVP · depende de VAC-3, UI-12, CFG-8

Mostrar el calendario del equipo con vacaciones y ausencias aprobadas y pendientes, y avisar cuando se supera el máximo de ausentes simultáneos definido. El calendario reutiliza el componente común y respeta el alcance de quien lo consulta.

- [ ] El manager ve los cruces antes de aprobar
- [ ] El colaborador ve quién de su equipo estará ausente, sin el motivo

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · Pedido §25 · Pedido §9 · RN-VAC-01 a RN-VAC-13 · Prototipo: Tiempo → Vacaciones

### VAC-5 · Programación anual y vacaciones acumuladas

*Historia* · MVP · depende de VAC-3

Permitir a RRHH y a los jefes programar el rol anual de vacaciones y alertar sobre trabajadores con periodos acumulados o próximos a vencer. La programación propuesta se confirma con el trabajador y se convierte en solicitudes.

- [ ] La alerta explica cuántos periodos acumula cada trabajador y desde cuándo
- [ ] El rol anual se puede exportar

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · Pedido §25 · Pedido §68 · RN-VAC-01 a RN-VAC-13

### VAC-6 · Fraccionamiento, adelanto y venta de vacaciones

*Historia* · MVP · depende de VAC-3, DOC-6

Soportar el goce fraccionado según la norma, el adelanto de días a cuenta del periodo en curso y el acuerdo de reducción por venta de días, cada uno con su documento firmado. Cada modalidad valida los límites que fija la norma mediante parámetros.

- [ ] La venta de días genera el concepto para planilla y descuenta el saldo
- [ ] El acuerdo de fraccionamiento queda firmado y archivado en el legajo

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §25 · RN-VAC-01 a RN-VAC-13

### VAC-7 · Importación de saldos de vacaciones

*Historia* · MVP · depende de VAC-2, IMP-1

Registrar el importador de saldos iniciales por periodo vacacional como movimientos de apertura del libro. Usa el motor de importación con su plantilla descargable.

- [ ] El saldo importado aparece como movimiento de apertura con su fecha
- [ ] La importación valida que el trabajador y el periodo existan

Referencias: docs/modulos/M-VAC.md · docs/06-reglas-de-negocio.md · Pedido §71 · RN-VAC-01 a RN-VAC-13

## AUS · Ausencias y permisos

Tipos de ausencia configurables con reglas propias, descansos médicos, licencias y teletrabajo.

*Componente: Backend + Frontend*

### AUS-1 · Tipos de ausencia con reglas propias

*Historia* · MVP · depende de CFG-3, WFL-2

Configurar tipos (descanso médico, licencia, permiso personal, maternidad, paternidad, compensación, trabajo remoto y otros) con reglas: con o sin goce, unidad en días u horas, tope, sustento obligatorio, flujo de aprobación y efecto en planilla. Los tipos se guardan con vigencia y pueden limitarse por empresa o régimen.

- [ ] Cada tipo define si descuenta remuneración y a qué concepto de planilla se asocia
- [ ] Un tipo puede exigir adjunto y limitar quién puede solicitarlo

Referencias: docs/modulos/M-AUS.md · docs/04-modelo-de-datos.md · docs/06-reglas-de-negocio.md · Pedido §26 · RN-AUS-01 a RN-AUS-09 · Prototipo: Tiempo → Ausencias y permisos

### AUS-2 · Solicitud y registro de ausencias

*Historia* · MVP · depende de AUS-1, VAC-4

Permitir que el colaborador solicite una ausencia o que RRHH la registre, con sustento, aprobación y efecto en asistencia y calendario. La solicitud valida las reglas del tipo antes de enviarse a aprobación.

- [ ] Una ausencia aprobada aparece en el calendario del equipo y evita la falta en asistencia
- [ ] No se admiten ausencias solapadas para el mismo trabajador

Referencias: docs/modulos/M-AUS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §26 · Pedido §8 · ME-16 · RN-AUS-01 a RN-AUS-09 · Prototipo: Tiempo → Ausencias y permisos

### AUS-3 · Descansos médicos y subsidios

*Historia* · MVP · depende de AUS-2, SEG-9

Registrar descansos médicos con certificado, llevar el conteo de días acumulados en el año para distinguir lo que paga el empleador y lo que corresponde a subsidio, y dar seguimiento al trámite de reembolso. Los umbrales y plazos del subsidio son parámetros con vigencia.

- [ ] El diagnóstico es dato sensible y solo lo ve quien tiene el permiso
- [ ] El sistema avisa cuando el acumulado del año alcanza el umbral de subsidio configurado
- [ ] El estado del trámite de subsidio es consultable

Referencias: docs/modulos/M-AUS.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/07-permisos-y-roles.md · Pedido §26 · Pedido §105 · RN-AUS-01 a RN-AUS-09

### AUS-4 · Licencias de maternidad, paternidad y otras de ley

*Historia* · MVP · depende de AUS-1

Precargar las licencias legales con su duración parametrizada, requisitos y documentos, y calcular fechas de inicio y fin. Cada licencia se registra como ausencia del tipo correspondiente con su sustento.

- [ ] La duración de cada licencia legal se lee de parámetros con vigencia
- [ ] La licencia calcula la fecha de retorno y la muestra al jefe

Referencias: docs/modulos/M-AUS.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §26 · RN-AUS-01 a RN-AUS-09

### AUS-5 · Trabajo remoto y desconexión digital

*Historia* · MVP · depende de AUS-2, CON-6, NOT-6

Registrar los días de trabajo remoto según el acuerdo de teletrabajo y aplicar la regla de desconexión digital: fuera de la jornada no se envían avisos no urgentes ni se exigen respuestas. El horario de desconexión se toma del acuerdo y de la jornada asignada.

- [ ] Los días remotos se distinguen en asistencia y no generan falta
- [ ] Las notificaciones no urgentes a un teletrabajador se difieren fuera de su jornada

Referencias: docs/modulos/M-AUS.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-20 · Pedido §26 · RN-AUS-01 a RN-AUS-09

### AUS-6 · Ausencias como incidencias para planilla

*Tarea técnica* · Backend · MVP · depende de AUS-2

Publicar el resumen por trabajador y periodo de días y horas de cada tipo de ausencia, con su efecto remunerativo, para que la planilla lo consuma. El resumen se obtiene de las ausencias aprobadas y, si la organización tiene el módulo de asistencia, del cálculo diario; sin ese módulo, los días laborados salen del calendario laboral y de las incidencias del periodo.

- [ ] El resumen de un periodo es reproducible y explica cada día
- [ ] Un cambio posterior al cierre del periodo se reporta como diferencia
- [ ] Sin el módulo de asistencia, el resumen se arma con el calendario laboral, las ausencias aprobadas y las incidencias importadas

Referencias: docs/modulos/M-AUS.md · docs/06-reglas-de-negocio.md · docs/08-api.md · C-11 · RN-AUS-01 a RN-AUS-09

## BHO · Horas extra y bolsa de horas

Autorización y cálculo de sobretiempo, y bolsa de horas con saldos para compensar con descanso.

*Componente: Backend + Frontend*

### BHO-1 · Solicitud y autorización de horas extra

*Historia* · depende de ASI-8, WFL-2

Permitir solicitar horas extra antes o después de trabajarlas, con motivo, y que el jefe las autorice. Sin autorización, el sobretiempo detectado queda pendiente de decisión.

- [ ] El sobretiempo no autorizado se lista para que el jefe lo apruebe o rechace
- [ ] La autorización indica si se paga o se compensa

Referencias: docs/modulos/M-BHO.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §56 · Pedido §22 · ME-17 · RN-BHO-01 a RN-BHO-09 · Prototipo: Tiempo → Horas extra y bolsa

### BHO-2 · Cálculo de sobretiempo

*Tarea técnica* · Backend · depende de BHO-1, TUR-2

Calcular las horas extra por tramos con las sobretasas vigentes, el trabajo en feriado y descanso semanal, y el recargo nocturno, todo desde parámetros. El resultado se entrega por trabajador y periodo para que la planilla lo consuma.

- [ ] Las sobretasas se leen de parámetros con vigencia
- [ ] El cálculo distingue horas de cada tramo y las explica
- [ ] El resultado se publica por el puerto de horas extra que lee el contexto de cálculo de la planilla (NOM-5)

Referencias: docs/modulos/M-BHO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §24 · RN-BHO-01 a RN-BHO-09

### BHO-3 · Bolsa de horas con saldos

*Tarea técnica* · Backend · depende de BHO-2

Llevar un libro de bolsa de horas por trabajador: acumulación por sobretiempo a compensar, consumo por descansos y ajustes, con saldo consultable. Cada movimiento referencia la asistencia o la solicitud que lo originó.

- [ ] El saldo se explica por sus movimientos
- [ ] Ninguna hora puede pagarse y además acumularse en la bolsa

Referencias: docs/modulos/M-BHO.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · E-06 · RN-BHO-01 a RN-BHO-09

### BHO-4 · Compensación con descanso

*Historia* · depende de BHO-3, AUS-2

Permitir solicitar descanso contra la bolsa de horas con aprobación, y registrar el acuerdo de compensación firmado cuando corresponda. La solicitud sigue el flujo de aprobación de ausencias.

- [ ] No se puede solicitar más descanso que el saldo disponible
- [ ] El descanso compensatorio aparece en el calendario como ausencia justificada

Referencias: docs/modulos/M-BHO.md · docs/06-reglas-de-negocio.md · E-06 · Pedido §26 · RN-BHO-01 a RN-BHO-09

### BHO-5 · Vencimiento de saldos y reporte

*Historia* · depende de BHO-3

Aplicar la regla de vencimiento de la bolsa (pago de lo no compensado al vencer el plazo configurado) y entregar el reporte de sobretiempo por unidad. El reporte compara horas pagadas, compensadas y pendientes.

- [ ] El plazo de vencimiento es un parámetro de política
- [ ] Lo vencido se envía a planilla como horas extra por pagar

Referencias: docs/modulos/M-BHO.md · docs/06-reglas-de-negocio.md · E-06 · RN-BHO-01 a RN-BHO-09

## TSH · Hojas de tiempo por proyecto

Registro de tiempo por proyecto y tarea con aprobación y costeo.

*Componente: Backend + Frontend*

### TSH-1 · Proyectos y tareas imputables

*Historia* · depende de ORG-4

Crear proyectos con cliente, centro de costo, responsable, fechas, tareas y miembros que pueden imputar tiempo. Los proyectos pueden marcarse como facturables o internos.

- [ ] Solo los miembros de un proyecto pueden imputarle tiempo
- [ ] Un proyecto cerrado no admite nuevas imputaciones

Referencias: docs/modulos/M-TSH.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · E-13 · RN-TSH-01 a RN-TSH-07

### TSH-2 · Hoja de tiempo

*Historia* · depende de TSH-1, ASI-8

Permitir que el colaborador registre su tiempo por proyecto y tarea en una grilla por periodo, con cronómetro opcional y comparación contra su asistencia. La hoja admite copiar el periodo anterior y registrar desde el móvil.

- [ ] La hoja muestra la diferencia entre lo imputado y lo trabajado según asistencia
- [ ] La hoja se envía a aprobación por periodo

Referencias: docs/modulos/M-TSH.md · docs/06-reglas-de-negocio.md · E-13 · RN-TSH-01 a RN-TSH-07 · Prototipo: Tiempo → Hojas de tiempo

### TSH-3 · Aprobación de hojas de tiempo

*Historia* · depende de TSH-2, WFL-2

Enviar la hoja al responsable del proyecto o al jefe para aprobarla o devolverla con observaciones. El flujo usa el motor de aprobaciones con el aprobador que define el proyecto.

- [ ] Una hoja aprobada no se edita sin reabrirla
- [ ] El responsable ve las hojas pendientes de su proyecto

Referencias: docs/modulos/M-TSH.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · E-13 · ME-18 · RN-TSH-01 a RN-TSH-07

### TSH-4 · Costeo y reporte por proyecto

*Historia* · depende de TSH-3, HIS-6

Calcular el costo de cada proyecto a partir del tiempo aprobado y el costo por hora derivado de la compensación, y reportar por proyecto, cliente y centro de costo. El costo por hora se obtiene del servicio de datos maestros a la fecha de cada registro.

- [ ] El costo por persona solo es visible con permiso de sueldos; sin él se ve el total del proyecto
- [ ] El reporte se exporta

Referencias: docs/modulos/M-TSH.md · docs/06-reglas-de-negocio.md · E-13 · C-11 · RN-TSH-01 a RN-TSH-07

# Fase 3 · Nómina Perú

## NOM · Motor de nómina

Motor de cálculo independiente: reglas, paquete de país y régimen laboral, con trazabilidad y retroactivos.

*Componente: Backend*

### NOM-1 · Arquitectura del motor: núcleo, paquete de país y régimen

*Tarea técnica* · MVP · depende de CFG-3, HIS-6

Construir el motor de nómina como módulo independiente de la interfaz, compuesto por un núcleo genérico (periodos, conceptos, fórmulas, resultados), un paquete de país que aporta conceptos, parámetros y declaraciones, y el régimen laboral por empresa y por trabajador que selecciona las reglas aplicables. El motor se invoca solo por servicios y por la API.

- [ ] El núcleo no contiene ninguna regla peruana: todas viven en el paquete Perú
- [ ] Ningún componente de interfaz calcula montos de planilla
- [ ] Agregar un régimen no requiere modificar el núcleo

Referencias: docs/modulos/M-NOM.md · docs/02-arquitectura.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §27 · Pedido §82 · C-05 · RN-NOM-01 a RN-NOM-11

### NOM-2 · Regímenes laborales por empresa y por trabajador

*Tarea técnica* · MVP · depende de NOM-1, ORG-1

Registrar con vigencia el régimen laboral de cada relación (general, microempresa, pequeña empresa, construcción civil, agrario, modalidades formativas), heredado de la empresa y modificable por trabajador según su fecha de ingreso. El cambio de régimen se registra como movimiento con fecha efectiva.

- [ ] Dos trabajadores de la misma empresa pueden estar en regímenes distintos
- [ ] El régimen vigente a la fecha del periodo decide qué conceptos se calculan

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · C-05 · RN-NOM-01 a RN-NOM-11

### NOM-3 · Intérprete de fórmulas seguro

*Tarea técnica* · MVP · depende de NOM-1

Implementar el lenguaje de fórmulas de conceptos (operadores, condiciones, funciones de fecha, redondeo, referencias a otros conceptos, a parámetros y a datos del trabajador) con un intérprete aislado, sin ejecución de código arbitrario. El lenguaje se documenta con ejemplos para quien configura conceptos.

- [ ] Una fórmula con sintaxis inválida o referencia inexistente no se puede guardar
- [ ] El intérprete no accede a nada fuera del contexto de cálculo
- [ ] Las fórmulas tienen pruebas con casos de entrada y salida

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · Pedido §29 · Pedido §27 · RN-NOM-01 a RN-NOM-11

### NOM-4 · Orden de cálculo y dependencias entre conceptos

*Tarea técnica* · MVP · depende de NOM-3

Resolver el orden de cálculo a partir de las dependencias entre conceptos y detectar ciclos al guardar. El grafo de dependencias se recalcula cada vez que se publica un concepto.

- [ ] Un ciclo entre conceptos se rechaza indicando los conceptos involucrados
- [ ] El orden de cálculo es determinista y consultable

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · Pedido §29 · RN-NOM-01 a RN-NOM-11

### NOM-5 · Contexto de cálculo por relación laboral

*Tarea técnica* · MVP · depende de NOM-2, AUS-6

Armar para cada relación y periodo el contexto de cálculo: compensación y asignación vigentes por tramo de fechas, días laborados, ausencias, horas extra, incidencias, préstamos y parámetros vigentes. El motor solo lee de las fuentes oficiales, a través de puertos: asistencia, horas extra y préstamos vienen de su módulo si la organización lo tiene y, si no, de las incidencias del periodo.

- [ ] Un cambio de sueldo a mitad de periodo se calcula por tramos
- [ ] El contexto usado en cada cálculo queda guardado para auditoría
- [ ] Sin los módulos de asistencia y horas extra, la planilla se calcula con ausencias e incidencias importadas

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · C-11 · C-03 · Pedido §116 · RN-NOM-01 a RN-NOM-11

### NOM-6 · Trazabilidad del cálculo

*Tarea técnica* · MVP · depende de NOM-5

Guardar por cada concepto calculado la fórmula aplicada, los valores de entrada, los parámetros con su vigencia y el resultado, para poder explicar cualquier monto de una boleta. La explicación se presenta en lenguaje claro en la pantalla de cálculo y en la revisión.

- [ ] Desde cualquier monto se llega al detalle de cómo se calculó
- [ ] La explicación indica la versión del concepto y de los parámetros usados

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · Pedido §27 · RN-NOM-01 a RN-NOM-11

### NOM-7 · Recálculo retroactivo

*Tarea técnica* · MVP · depende de NOM-6, HIS-4

Calcular diferencias cuando un cambio con fecha pasada afecta periodos cerrados: el motor recalcula en sombra, obtiene la diferencia por concepto y la incorpora al periodo abierto como reintegro o descuento. El recálculo se dispara con el evento de corrección retroactiva de la historia con vigencia.

- [ ] Un periodo cerrado nunca se modifica: la diferencia va al periodo abierto
- [ ] El reintegro indica el periodo de origen

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · C-03 · RN-NOM-01 a RN-NOM-11

### NOM-8 · Cálculo masivo en segundo plano

*Tarea técnica* · MVP · depende de NOM-5, PLT-6

Ejecutar el cálculo por lotes en colas, con avance visible, reanudación ante fallos y cálculo parcial por trabajador o grupo. El cálculo es idempotente: repetirlo con los mismos datos da el mismo resultado.

- [ ] El cálculo de una empresa grande no bloquea la aplicación y muestra su avance
- [ ] Un trabajador con error no detiene al resto y se reporta con su causa

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §101 · RN-NOM-01 a RN-NOM-11

### NOM-9 · Estrategia por régimen laboral

*Tarea técnica* · MVP · depende de NOM-1, PAR-5

Registrar desde el paquete de país una estrategia por tipo de régimen (general, micro, pequeña, construcción civil, agrario, formativo) que entrega al motor los conceptos, la unidad de remuneración, los procesos especiales y las gratificaciones por percibir. El motor parte el periodo por tramos de vigencia y pregunta a la estrategia; el núcleo no tiene condicionales por régimen (docs/18 §4.7).

- [ ] Una búsqueda de códigos de régimen en el núcleo del motor no devuelve resultados
- [ ] Un cambio de régimen a mitad de periodo calcula cada tramo con su estrategia y conserva la traza por tramo
- [ ] Micro y pequeña empresa usan la estrategia general con sus parámetros

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · C-05 · RN-NOM-01 a RN-NOM-18

### NOM-10 · Micro y pequeña empresa: acreditación, ventas y permanencia

*Historia* · Backend + Frontend · MVP · depende de NOM-9, ORG-1

Registrar por razón social la acreditación del régimen, las ventas anuales en UIT y la dotación promedio, y controlar el periodo de permanencia al superar el tope dos ejercicios consecutivos. El régimen especial solo se propone a relaciones posteriores a la acreditación; el cambio masivo de régimen es un movimiento con fecha efectiva. Reglas RN-PE-MYP del documento 18.

- [ ] Sin acreditación vigente, las relaciones nuevas se proponen en el régimen general
- [ ] Al superar el tope el segundo ejercicio se marca la permanencia con su fecha de fin
- [ ] Los contratados antes de la acreditación conservan su régimen

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · C-05 · RN-NOM-01 a RN-NOM-18

### NOM-11 · Construcción civil: obras, categorías y planilla semanal

*Historia* · Backend + Frontend · depende de NOM-9, PAR-6, PER-1

Registrar obras con su costo en UIT, asignar a cada trabajador su categoría y especialidad por obra con vigencia, y calcular la planilla semanal con jornal, dominical, bonificación unificada, movilidad, asignación escolar, bonificaciones por condición, gratificación de 40 jornales, compensaciones y CONAFOVICER desde la tabla salarial vigente en cada día. La declaración mensual consolida los periodos semanales del mismo periodo tributario. Reglas RN-PE-CC del documento 18.

- [ ] Una obra por debajo del umbral en UIT deja la relación en el régimen general
- [ ] Un cambio de categoría dentro del periodo semanal lo parte en tramos
- [ ] La declaración mensual no se genera si falta cerrar algún periodo semanal del mes tributario
- [ ] Lo no verificado (nocturno, altitud, alta especialización) queda sin valor y bloquea el cierre de quien lo use

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · C-05 · RN-NOM-01 a RN-NOM-18

### NOM-12 · Régimen agrario

*Historia* · Backend + Frontend · depende de NOM-9, PAR-5

Calcular la remuneración diaria agraria con gratificación y CTS prorrateadas como conceptos separados, o en sus oportunidades semestrales si el trabajador lo elige. Incluye la bonificación especial no remunerativa, el aporte a EsSalud por tramo de la razón social y el derecho preferencial de contratación por temporada. Reglas RN-PE-AGR del documento 18.

- [ ] Los tres componentes de la remuneración diaria se calculan y declaran por separado
- [ ] La opción del trabajador se guarda con vigencia y su documento
- [ ] Antes de cada temporada se lista a los extrabajadores con derecho preferencial y se genera la convocatoria

Referencias: docs/modulos/M-NOM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · C-05 · RN-NOM-01 a RN-NOM-18

## CPT · Conceptos y fórmulas

Catálogo de conceptos de nómina con fórmula, condiciones, vigencia, afectaciones y contabilización.

*Componente: Backend + Frontend*

### CPT-1 · Catálogo de conceptos

*Historia* · MVP · depende de NOM-3

Administrar conceptos con código, nombre, tipo (ingreso, descuento, aporte del trabajador, aporte del empleador, impuesto, informativo), vigencia y régimen al que aplican. Se pueden crear conceptos propios como comisión de ventas, bono de productividad o movilidad.

- [ ] Un concepto nuevo se usa en el siguiente cálculo sin desplegar
- [ ] Los cambios de un concepto se versionan con vigencia

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §29 · Pedido §27 · RN-CPT-01 a RN-CPT-08 · Prototipo: Nómina → Configuración de nómina → Conceptos

### CPT-2 · Editor de fórmulas y condiciones

*Historia* · MVP · depende de CPT-1, NOM-4

Construir el editor de fórmula con autocompletado de conceptos, parámetros y variables, condiciones de aplicación y un probador que calcula el concepto sobre un trabajador real antes de publicar. El editor valida la fórmula mientras se escribe y muestra de qué conceptos depende.

- [ ] El probador muestra el resultado y el detalle del cálculo
- [ ] No se publica una fórmula con errores

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · Pedido §29 · RN-CPT-01 a RN-CPT-08 · Prototipo: Nómina → Configuración de nómina → Conceptos

### CPT-3 · Afectaciones de cada concepto

*Historia* · MVP · depende de CPT-1

Definir por concepto sus afectaciones: si es remunerativo, y si integra la base de AFP u ONP, EsSalud, renta de quinta, CTS, gratificaciones, vacaciones y otros cálculos. Las afectaciones determinan las bases de cálculo que usa el motor.

- [ ] Marcar un concepto como no remunerativo lo excluye de las bases correspondientes
- [ ] Las afectaciones tienen vigencia

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §29 · RN-CPT-01 a RN-CPT-08

### CPT-4 · Contabilización por concepto

*Historia* · MVP · depende de CPT-1, ORG-4

Asignar a cada concepto su cuenta contable de gasto y de pasivo, con reglas por centro de costo y tipo de trabajador. La regla se evalúa al generar el asiento del periodo.

- [ ] Todo concepto con efecto contable tiene cuentas asignadas antes del cierre
- [ ] La regla de cuenta admite excepciones por centro de costo

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §29 · RN-CPT-01 a RN-CPT-08

### CPT-5 · Conceptos peruanos precargados

*Tarea técnica* · Datos · MVP · depende de CPT-3

Cargar el paquete de conceptos del Perú: remuneración básica, asignación familiar, horas extra, comisiones, bonificaciones, gratificaciones, CTS, vacaciones, AFP, ONP, EsSalud, renta de quinta y descuentos, con fórmula, afectaciones y código SUNAT. El paquete se instala al crear la organización y se actualiza con cada cambio normativo.

- [ ] Una organización nueva calcula una planilla del régimen general sin crear conceptos
- [ ] Cada concepto precargado tiene su código de la tabla SUNAT

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · E-27 · RN-CPT-01 a RN-CPT-08

### CPT-6 · Importación de conceptos e incidencias

*Historia* · MVP · depende de CPT-1, IMP-1

Registrar los importadores de conceptos propios y de valores por trabajador (montos fijos, variables del periodo). Usa el motor de importación con validación y vista previa.

- [ ] La importación valida que el concepto y el trabajador existan
- [ ] Los valores importados se identifican por su origen

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · Pedido §71 · RN-CPT-01 a RN-CPT-08

### CPT-7 · Códigos de la tabla de conceptos y naturaleza en la proyección de quinta

*Tarea técnica* · Datos · MVP · depende de CPT-5

Cargar en la plantilla de conceptos los códigos cotejados con la Tabla 22 de la autoridad tributaria (comisiones con 0103), la naturaleza de cada concepto en la proyección de quinta (ordinario, gratificación, extraordinario), los regímenes a los que aplica y los conceptos nuevos de construcción civil, agrario y devolución de quinta. Las afectaciones siguen marcadas como no validadas.

- [ ] Ningún concepto de la plantilla conserva un código que no exista en la tabla vigente
- [ ] Un concepto que afecta a quinta sin naturaleza declarada no se puede activar

Referencias: docs/modulos/M-CPT.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-CPT-01 a RN-CPT-10

## PAR · Parámetros legales con vigencia

Tasas, topes y tablas legales por país y régimen, con vigencia por fecha y actualización controlada.

*Componente: Backend + Frontend*

### PAR-1 · Parámetros legales generales

*Historia* · MVP · depende de CFG-3, NOM-1

Administrar UIT, remuneración mínima, tasas de EsSalud y ONP, porcentaje de asignación familiar y demás valores legales como parámetros con vigencia, fuente normativa y ámbito por país y régimen. Los valores del país los mantiene el producto; la organización solo gestiona los propios.

- [ ] Ningún valor legal está escrito en el código
- [ ] Cada parámetro muestra su historial de valores y la norma que lo sustenta

Referencias: docs/modulos/M-PAR.md · docs/14-localizacion-peru.md · docs/06-reglas-de-negocio.md · Pedido §28 · C-05 · RN-PAR-01 a RN-PAR-08 · Prototipo: Nómina → Configuración de nómina → Parámetros legales

### PAR-2 · Tablas de AFP

*Historia* · MVP · depende de PAR-1

Mantener por periodo las tasas de cada AFP: aporte obligatorio, comisión por tipo, prima de seguro y remuneración máxima asegurable. La carga se hace por formulario o por archivo, con la fuente oficial citada.

- [ ] Las tasas se cargan por periodo y por AFP
- [ ] El cálculo usa las tasas del periodo que se procesa

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-PAR-01 a RN-PAR-08

### PAR-3 · Escala y deducciones de renta de quinta

*Historia* · MVP · depende de PAR-1

Mantener la escala progresiva, la deducción en UIT y las reglas de proyección de la renta de quinta como parámetros con vigencia. El cálculo de quinta lee siempre la escala vigente a la fecha del periodo.

- [ ] Un cambio de escala con vigencia futura no altera cálculos anteriores
- [ ] Los tramos se editan sin tocar fórmulas

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-PAR-01 a RN-PAR-08

### PAR-4 · Publicación de actualizaciones normativas

*Historia* · MVP · depende de PAR-1, SAS-13

Permitir que el equipo del producto publique desde la consola SaaS un cambio de parámetros legales para todas las organizaciones de un país, con aviso al cliente y registro de qué cambió. La publicación exige revisión de una segunda persona del equipo del producto.

- [ ] Una actualización publicada llega a todas las organizaciones del país con su fecha de vigencia
- [ ] El cliente ve un aviso con el cambio y su norma

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-PAR-01 a RN-PAR-08

### PAR-5 · Parámetros por régimen laboral

*Tarea técnica* · Datos · MVP · depende de PAR-1, NOM-2

Cargar los parámetros que cambian por régimen: días de vacaciones, proporción de gratificación y de CTS, seguro de salud aplicable e indemnización. Los valores se cargan desde el paquete de país y quedan visibles para Payroll.

- [ ] Cada régimen tiene su conjunto de parámetros con vigencia
- [ ] La falta de un parámetro requerido impide calcular e informa cuál falta

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · C-05 · RN-PAR-01 a RN-PAR-08

### PAR-6 · Tabla salarial anual por convenio

*Historia* · MVP · depende de PAR-1

Cargar la tabla salarial de construcción civil como un lote de parámetros con la misma vigencia y fuente legal, sin verificar hasta que el responsable la confirme contra el acta. Si el periodo cae fuera de la vigencia y no hay tabla nueva, se calcula con la última, se marca a los afectados y al cargarse la nueva se generan los reintegros.

- [ ] El lote se publica desde el paquete de país; ninguna organización lo teclea
- [ ] Un periodo con tabla vencida queda advertido, no bloqueado
- [ ] La tabla nueva con vigencia retroactiva genera reintegros sin reabrir periodos cerrados
- [ ] Los plazos legales distinguen días hábiles de días calendario en su unidad

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · C-05 · RN-PAR-01 a RN-PAR-11

### PAR-7 · Novedades normativas visibles para el cliente

*Historia* · MVP · depende de PAR-4, NOT-2, PER-7

Publicar en la aplicación de cada organización un registro de novedades normativas: qué parámetro cambió, desde cuándo, con qué norma, a qué empresas y regímenes afecta y en qué periodo se aplicó. El administrador recibe aviso cuando una novedad toca un periodo abierto y puede ver la diferencia que produce en la simulación. Es la base del compromiso comercial de actualización normativa.

- [ ] Cada novedad muestra parámetro, valor anterior y nuevo, vigencia y norma oficial
- [ ] Una novedad que afecta un periodo abierto avisa al responsable de planilla y ofrece simular
- [ ] El historial de novedades se puede filtrar por empresa, régimen y fecha
- [ ] Una novedad no cambia periodos cerrados: si corresponde, propone una planilla complementaria

Referencias: docs/modulos/M-PAR.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · C-05 · RN-PAR-01 a RN-PAR-11

## PER · Periodos, incidencias, cálculo, simulación y cierre

Proceso de planilla de punta a punta: periodo, incidencias, cálculo, simulación, revisión, aprobación, cierre y pago.

*Componente: Backend + Frontend*

### PER-1 · Periodos de planilla

*Historia* · MVP · depende de NOM-1, PLT-8

Crear periodos por empresa y tipo de planilla (mensual, quincenal, gratificación, CTS, utilidades, liquidación) con su máquina de estados: abierto, en cálculo, en revisión, aprobado, cerrado y pagado. Cada periodo define sus fechas de corte, de pago y la población incluida.

- [ ] Solo se avanza de estado mediante acciones explícitas con permiso
- [ ] No se puede abrir un periodo si el anterior del mismo tipo no está cerrado, salvo permiso especial

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §27 · ME-19 · RN-PER-01 a RN-PER-13 · Prototipo: Nómina → Planilla

### PER-2 · Incidencias del periodo

*Historia* · MVP · depende de PER-1, CPT-6

Registrar las variables del periodo por trabajador: comisiones, bonos, descuentos eventuales y otros conceptos variables, por formulario, por carga masiva y desde los módulos que las originan. Las incidencias son la única vía para ingresar valores variables al cálculo.

- [ ] Cada incidencia indica su origen y autor
- [ ] Las incidencias se bloquean al aprobar el periodo

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §27 · RN-PER-01 a RN-PER-13 · Prototipo: Nómina → Planilla → Planilla mensual

### PER-3 · Consumo de asistencia y ausencias

*Tarea técnica* · Backend · MVP · depende de PER-1, NOM-5

Tomar para el periodo los resúmenes de asistencia, ausencias, horas extra y bolsa de horas, con fecha de corte configurable, y mostrar lo que quedó fuera de corte. El corte queda registrado para poder reproducir el cálculo.

- [ ] La planilla usa los datos de tiempo sin redigitarlos
- [ ] Lo ocurrido después del corte entra como diferencia al periodo siguiente

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · C-11 · RN-PER-01 a RN-PER-13

### PER-4 · Cálculo de la planilla

*Historia* · MVP · depende de PER-2, PER-3, NOM-8, CPT-5

Ejecutar el cálculo del periodo completo o de un grupo, ver el resultado por trabajador y concepto, y recalcular tras corregir incidencias. La pantalla permite filtrar por unidad, por concepto y por trabajadores con alerta.

- [ ] El resultado por trabajador muestra ingresos, descuentos, aportes y neto
- [ ] Recalcular reemplaza el resultado anterior mientras el periodo no esté aprobado
- [ ] Cada monto abre su explicación

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · Pedido §27 · RN-PER-01 a RN-PER-13 · Prototipo: Nómina → Planilla → Planilla mensual

### PER-5 · Renta de quinta categoría

*Tarea técnica* · Backend · MVP · depende de PER-4, PAR-3

Implementar el motor de retención de quinta como función pura (docs/18 §2.3 y §2.4): proyección con gratificaciones y su bonificación extraordinaria, deducción en UIT, escala, divisores y cortes de retenciones por mes, retención adicional por ingresos extraordinarios del mes, regularización de diciembre y ajuste final al cese. Las 3 UIT de gastos deducibles no se consideran: solo las aplica la administración tributaria en la declaración anual.

- [ ] La retención de cada mes se explica con la proyección, el impuesto anual, las retenciones deducidas y el divisor usados
- [ ] El ingreso extraordinario del mes se grava por diferencia de impuestos y se retiene íntegro en ese mes
- [ ] No domiciliados, exonerados y trabajadores cuyo otro empleador retiene se resuelven sin proyección y dejan el motivo en el libro
- [ ] El motor no lee constantes: escala, divisores, cortes y deducción llegan como parámetros con vigencia
- [ ] Los cuatro casos resueltos del documento 18 §2.9 son pruebas doradas con la UIT como dato del caso

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-PER-01 a RN-PER-13

### PER-6 · Aportes previsionales y de salud

*Tarea técnica* · Backend · MVP · depende de PER-4, PAR-2

Calcular AFP (aporte, comisión y prima con tope), ONP, EsSalud con su base mínima, crédito por EPS y SCTR según el régimen de cada trabajador. Las bases se arman con las afectaciones de cada concepto.

- [ ] El tope de la remuneración asegurable se aplica a la prima
- [ ] Un cambio de AFP a mitad de año usa la tasa correcta en cada periodo

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · E-19 · RN-PER-01 a RN-PER-13

### PER-7 · Simulación y detección de anomalías

*Historia* · MVP · depende de PER-4

Simular antes de cerrar y comparar por trabajador y por concepto contra el periodo anterior (anterior, actual y variación), resaltando variaciones que superan el umbral configurado, netos negativos y trabajadores sin cálculo. Los umbrales de variación se configuran por concepto y por empresa.

- [ ] Una caída del neto mayor al umbral aparece como alerta con su causa probable
- [ ] La simulación no altera datos del periodo
- [ ] Cada alerta se marca como revisada con comentario

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · Pedido §30 · Pedido §68 · RN-PER-01 a RN-PER-13 · Prototipo: Nómina → Planilla → Planilla mensual

### PER-8 · Revisión, aprobación y cierre

*Historia* · MVP · depende de PER-7, WFL-2

Enviar el periodo a aprobación con el resumen de totales y alertas, aprobar con el flujo configurado y cerrar. El cierre congela resultados, emite `payroll.closed` y dispara boletas, pago y contabilización.

- [ ] Quien procesa no puede ser quien aprueba si la política de segregación está activa
- [ ] Un periodo cerrado es inmutable
- [ ] No se puede cerrar con alertas críticas sin revisar

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §27 · Pedido §62 · ME-19 · RN-PER-01 a RN-PER-13 · Prototipo: Nómina → Planilla

### PER-9 · Reapertura controlada y planilla complementaria

*Historia* · MVP · depende de PER-8

Permitir reabrir un periodo cerrado solo con permiso especial, motivo y antes de haber sido declarado, y crear planillas complementarias para pagos fuera del periodo regular. Ambas operaciones conservan intacto el resultado original del periodo.

- [ ] La reapertura queda auditada con motivo
- [ ] Un periodo ya declarado no se reabre: se corrige con complementaria o rectificatoria

Referencias: docs/modulos/M-PER.md · docs/05-maquinas-de-estado.md · docs/06-reglas-de-negocio.md · RN-PER-01 a RN-PER-13

### PER-10 · Pago de la planilla

*Historia* · MVP · depende de PER-8, COL-8

Generar el lote de pago del periodo por banco y medio de pago, registrar la confirmación y marcar el periodo como pagado. El lote usa las cuentas vigentes de cada trabajador a la fecha de pago.

- [ ] El total del lote coincide con el neto del periodo
- [ ] Los trabajadores sin cuenta registrada se listan aparte

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · Pedido §27 · Pedido §28 · RN-PER-01 a RN-PER-13

### PER-11 · Libro de retenciones de quinta

*Tarea técnica* · Backend · MVP · depende de PER-5

Guardar cada cálculo de quinta como una fila de solo inserción con sus entradas, parámetros, traza y versión del motor; un recálculo inserta otra fila. El acumulado retenido de cada mes es el de su última secuencia.

- [ ] Actualizar o borrar una fila del libro falla
- [ ] El acumulado del ejercicio coincide con la suma de las boletas cerradas

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-PER-01 a RN-PER-14

### PER-12 · Importación de acumulados de planilla del ejercicio

*Historia* · MVP · depende de IMP-1, PER-1, PER-5, PER-11

Importar, para una organización que empieza a usar el producto a mitad de año, los acumulados del ejercicio por trabajador: remuneraciones y retenciones de quinta, aportes previsionales, bases computables de gratificación y CTS del semestre en curso y depósitos de CTS previos. Cada acumulado entra como saldo de apertura con fecha de corte y sustento, nunca como boletas inventadas, y usa el motor de importación con su plantilla descargable.

- [ ] La proyección de quinta del primer periodo usa lo importado y la explica
- [ ] La gratificación y la CTS del primer semestre suman lo importado y lo calculado en el producto
- [ ] Un acumulado importado se revierte con el informe de la importación mientras no haya un periodo cerrado que lo use
- [ ] El total importado por concepto cuadra con el reporte del sistema anterior

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §71 · E-27 · RN-PER-01 a RN-PER-14 · Prototipo: Configuración → Importar datos

### PER-13 · Comparación con el sistema anterior durante la planilla en paralelo

*Historia* · MVP · depende de PER-12, PER-7, IMP-7

Cargar las boletas o el resumen de planilla del sistema anterior de la organización y compararlos, trabajador por trabajador y concepto por concepto, con el periodo calculado en el producto. Cada diferencia se clasifica (dato de entrada, parámetro, regla o redondeo) y se puede marcar como explicada con un comentario. El informe sirve para decidir el cierre en el producto durante la migración.

- [ ] La comparación muestra totales por concepto y el detalle por trabajador
- [ ] Una diferencia por debajo de la tolerancia configurada se marca como redondeo
- [ ] El periodo no puede declararse listo para salir mientras haya diferencias sin explicar
- [ ] El informe se exporta y queda guardado con la migración

Referencias: docs/modulos/M-PER.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §71 · E-27 · RN-PER-01 a RN-PER-14

## BOL · Boletas de pago

Boletas en PDF, publicación en el portal con constancia de entrega e historial.

*Componente: Backend + Frontend*

### BOL-1 · Generación de boletas en PDF

*Tarea técnica* · Backend · MVP · depende de PER-8, DOC-5

Generar la boleta de cada trabajador al cerrar el periodo con plantilla configurable por empresa: datos del empleador y del trabajador, días y horas, ingresos, descuentos, aportes y neto. La plantilla admite el logo y la firma del representante de la empresa.

- [ ] La boleta contiene los datos exigidos por la norma laboral
- [ ] Las boletas de un periodo se generan en segundo plano y se guardan en el legajo

Referencias: docs/modulos/M-BOL.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §31 · RN-BOL-01 a RN-BOL-08

### BOL-2 · Publicación en el portal y constancia de entrega

*Historia* · MVP · depende de BOL-1, ESS-3, DOC-7

Publicar la boleta en el portal del colaborador de forma automática, avisarle y registrar la constancia de puesta a disposición y de acceso. Permitir la firma de recepción cuando la empresa lo exige.

- [ ] El trabajador recibe aviso cuando su boleta está disponible
- [ ] Queda evidencia de emisión, puesta a disposición y primera apertura
- [ ] La boleta puede firmarse desde el móvil

Referencias: docs/modulos/M-BOL.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · Pedido §31 · Pedido §8 · Pedido §85 · C-12 · ME-20 · RN-BOL-01 a RN-BOL-08 · Prototipo: Nómina → Mis boletas

### BOL-3 · Historial y descarga masiva

*Historia* · MVP · depende de BOL-2, SEG-12

Mostrar el historial de boletas por trabajador y permitir a Payroll la descarga individual y masiva por periodo, unidad o selección. El historial incluye boletas de periodos migrados de otros sistemas.

- [ ] El colaborador ve y descarga todas sus boletas históricas
- [ ] La descarga masiva queda registrada

Referencias: docs/modulos/M-BOL.md · docs/06-reglas-de-negocio.md · Pedido §31 · Pedido §108 · RN-BOL-01 a RN-BOL-08 · Prototipo: Nómina → Planilla

### BOL-4 · Conservación de boletas y constancias

*Tarea técnica* · Backend · MVP · depende de BOL-2

Aplicar a boletas y constancias de entrega el plazo de conservación legal como política de retención, con bloqueo de borrado durante ese plazo. La política se aplica también a los archivos almacenados.

- [ ] Una boleta dentro del plazo de conservación no puede eliminarse
- [ ] El plazo es un parámetro por país

Referencias: docs/modulos/M-BOL.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §91 · RN-BOL-01 a RN-BOL-08

### BOL-5 · Explicación del cambio de neto en la boleta

*Historia* · MVP · depende de BOL-2, NOM-6

Mostrar junto a cada boleta publicada qué cambió respecto al mes anterior y por qué: conceptos nuevos o que desaparecen, días no laborados, horas extra, cambio de sueldo, retención de quinta o descuentos. La explicación se arma desde la traza del cálculo con plantillas de texto, sin inventar datos; si el módulo de IA está activo, solo mejora la redacción. Sirve al colaborador y al analista de planilla.

- [ ] Cada diferencia de neto mayor que la tolerancia configurada tiene al menos un motivo explicado
- [ ] La explicación cita los conceptos y montos de la traza del cálculo
- [ ] El colaborador solo ve la explicación de sus propias boletas
- [ ] Sin el módulo de IA la explicación funciona igual con plantillas

Referencias: docs/modulos/M-BOL.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §31 · C-08 · RN-BOL-01 a RN-BOL-08 · Prototipo: Nómina → Mis boletas

## BSO · Beneficios sociales

Gratificaciones, CTS, utilidades, asignación familiar y remuneración vacacional.

*Componente: Backend + Frontend*

### BSO-1 · Gratificaciones

*Historia* · MVP · depende de PER-8, PAR-5

Calcular las gratificaciones de julio y diciembre por meses completos, con remuneración computable, bonificación extraordinaria y proporción según régimen, como planilla propia. La gratificación trunca se calcula con la misma regla al cese.

- [ ] Un trabajador de pequeña empresa recibe la proporción que fija su régimen
- [ ] El cálculo detalla meses computables y conceptos incluidos

Referencias: docs/modulos/M-BSO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-BSO-01 a RN-BSO-12

### BSO-2 · CTS

*Historia* · MVP · depende de BSO-1, COL-8

Calcular los depósitos semestrales de CTS con remuneración computable (incluido el sexto de la gratificación), por entidad depositaria y moneda, y generar la liquidación de depósito para el trabajador. El cálculo trunco al cese reutiliza la misma regla.

- [ ] La liquidación de depósito se publica en el portal del trabajador
- [ ] El cálculo aplica la proporción del régimen

Referencias: docs/modulos/M-BSO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-BSO-01 a RN-BSO-12

### BSO-3 · Utilidades

*Historia* · MVP · depende de PER-8, AUS-6

Calcular la participación en utilidades: monto a distribuir según actividad, reparto por días laborados y por remuneraciones, tope individual y liquidación individual para cada trabajador, incluidos los cesados del ejercicio. Los porcentajes por actividad y el tope son parámetros con vigencia.

- [ ] La liquidación individual muestra los dos componentes del reparto
- [ ] Los cesados con derecho aparecen en la distribución

Referencias: docs/modulos/M-BSO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-21 · RN-BSO-01 a RN-BSO-12

### BSO-4 · Asignación familiar

*Tarea técnica* · Backend · MVP · depende de PER-4, COL-6

Otorgar la asignación familiar a quienes tienen hijos menores o estudiando según los derechohabientes registrados, como porcentaje de la remuneración mínima vigente. La condición se revisa en cada periodo contra la vigencia de los sustentos.

- [ ] El monto cambia solo cuando cambia el parámetro de remuneración mínima
- [ ] Sin derechohabiente con sustento vigente no se otorga

Referencias: docs/modulos/M-BSO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-BSO-01 a RN-BSO-12

### BSO-5 · Remuneración vacacional e indemnización vacacional

*Tarea técnica* · Backend · MVP · depende de PER-4, VAC-6

Calcular el pago de vacaciones gozadas, la compensación por venta de días y la indemnización por vacaciones no gozadas a tiempo, a partir del libro de saldos. Los días y periodos provienen del módulo de vacaciones sin redigitar.

- [ ] El pago se vincula al periodo vacacional correspondiente
- [ ] Las vacaciones vencidas generan el cálculo de indemnización para revisión

Referencias: docs/modulos/M-BSO.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-BSO-01 a RN-BSO-12

## PRE · Préstamos, adelantos y adelanto de sueldo

Préstamos con cronograma, adelantos, descuento automático y adelanto de sueldo sobre lo devengado.

*Componente: Backend + Frontend*

### PRE-1 · Préstamos con cronograma

*Historia* · depende de WFL-2, DOC-6

Registrar préstamos al personal con monto, cuotas, interés opcional y cronograma, con aprobación y documento firmado. El tope de endeudamiento y las condiciones se definen por política de la empresa.

- [ ] El cronograma muestra cuotas, fechas de periodo y saldo
- [ ] El préstamo puede refinanciarse o cancelarse por adelantado con registro

Referencias: docs/modulos/M-PRE.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · Pedido §32 · ME-21 · RN-PRE-01 a RN-PRE-09 · Prototipo: Nómina → Préstamos y adelantos

### PRE-2 · Adelantos

*Historia* · depende de PRE-1

Registrar adelantos de remuneración y de quincena con su regla de descuento en el periodo. Los adelantos comparten el libro de saldos con los préstamos.

- [ ] Un adelanto se descuenta en el periodo que se indica
- [ ] El saldo pendiente del trabajador es consultable

Referencias: docs/modulos/M-PRE.md · docs/06-reglas-de-negocio.md · Pedido §32 · RN-PRE-01 a RN-PRE-09

### PRE-3 · Descuento automático en planilla

*Tarea técnica* · Backend · depende de PRE-2, PER-2, LIQ-1

Generar en cada periodo las incidencias de descuento de cuotas y adelantos, respetando el límite legal de descuentos sobre la remuneración, y entregar el saldo pendiente a la liquidación de beneficios en caso de cese. El límite de descuento es un parámetro con vigencia.

- [ ] La cuota se descuenta sin intervención manual
- [ ] Si el descuento supera el límite, se reprograma y se informa

Referencias: docs/modulos/M-PRE.md · docs/06-reglas-de-negocio.md · Pedido §32 · RN-PRE-01 a RN-PRE-09

### PRE-4 · Adelanto de sueldo sobre lo devengado

*Historia* · depende de PRE-3, ESS-5, ASI-8

Permitir que el colaborador solicite desde su portal un adelanto sobre los días ya trabajados del periodo, con tope por política (porcentaje, frecuencia), aprobación automática dentro del tope y descuento en la planilla del periodo. El devengado se obtiene de la asistencia y de la compensación vigente.

- [ ] El monto disponible se calcula con los días devengados y el tope de la política
- [ ] La solicitud dentro del tope no requiere aprobación manual
- [ ] El descuento aparece en la boleta

Referencias: docs/modulos/M-PRE.md · docs/05-maquinas-de-estado.md · docs/06-reglas-de-negocio.md · E-09 · ME-22 · RN-PRE-01 a RN-PRE-09 · Prototipo: Nómina → Préstamos y adelantos

### PRE-5 · Pago por billeteras digitales

*Tarea técnica* · Backend · depende de PRE-4, PLT-13

Agregar el adaptador de pago por billetera digital para adelantos y pagos menores, con implementación falsa y conciliación de resultados. El adaptador sigue el patrón común de integraciones y usa outbox.

- [ ] Un pago rechazado por la billetera revierte el adelanto
- [ ] El medio de pago queda registrado

Referencias: docs/modulos/M-PRE.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · E-09 · Pedido §75 · RN-PRE-01 a RN-PRE-09

## DEC · Declaraciones y archivos

Archivos de PLAME, T-Registro, AFPnet, bancos y certificados, con validación previa.

*Componente: Backend + Frontend*

### DEC-1 · Mapeo de conceptos a tablas SUNAT

*Tarea técnica* · Datos · MVP · depende de CPT-5

Mantener el mapeo de conceptos propios a los códigos de las tablas paramétricas de SUNAT y verificar que todo concepto usado en un periodo esté mapeado. El paquete Perú trae el mapeo de los conceptos precargados.

- [ ] Un concepto sin código SUNAT impide generar los archivos e informa cuál
- [ ] El mapeo tiene vigencia

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-DEC-01 a RN-DEC-10

### DEC-2 · Archivos de PLAME

*Historia* · MVP · depende de DEC-1, PER-8

Generar los archivos de importación del PLAME (jornada, ingresos, descuentos, aportes y demás estructuras) por empresa y periodo, con validación previa de formato y consistencia, y soporte de rectificatoria. Las estructuras de archivo se definen en el paquete Perú para poder actualizarlas cuando cambien.

- [ ] La validación previa lista errores por trabajador antes de generar
- [ ] Los totales de los archivos cuadran con la planilla cerrada
- [ ] Se conserva cada archivo generado con su fecha y autor

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · Pedido §28 · ME-24 · RN-DEC-01 a RN-DEC-10 · Prototipo: Nómina → Declaraciones

### DEC-3 · T-Registro

*Historia* · MVP · depende de COL-6, COL-7

Preparar altas, bajas y modificaciones de trabajadores, derechohabientes y personal en formación, con control de los plazos de alta y baja y archivos de carga masiva. Los plazos son parámetros con vigencia.

- [ ] Un ingreso sin alta en T-Registro aparece como pendiente con su plazo de alta y de modificación tomado de los parámetros `treg_*`
- [ ] Los datos requeridos faltantes se listan antes de generar
- [ ] Lo declarado se guarda por tipo de registro y se concilia con la historia con vigencia del núcleo

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · E-19 · RN-DEC-01 a RN-DEC-10 · Prototipo: Nómina → Declaraciones

### DEC-4 · Planilla de aportes para AFPnet

*Historia* · MVP · depende de PER-6, PER-8

Generar la planilla de declaración de aportes del periodo en el formato de AFPnet (17 columnas, Excel o texto de ancho fijo; docs/18 §3.5) con los indicadores de relación laboral, la remuneración asegurable y los aportes voluntarios, y conciliar el cálculo propio de aporte, comisión y prima contra la liquidación que devuelve AFPnet. El formato vive como estructura con vigencia en el paquete Perú.

- [ ] El archivo lleva una línea por trabajador con los indicadores de inicio, cese y excepción de aportar del mes
- [ ] Los trabajadores sin código de afiliado se reportan
- [ ] El calendario alerta el vencimiento en los días hábiles del mes siguiente que fija el parámetro

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-DEC-01 a RN-DEC-16 · Prototipo: Nómina → Declaraciones

### DEC-5 · Archivos bancarios

*Historia* · MVP · depende de PER-10

Generar los archivos de pago de haberes, CTS y otros en el formato de cada banco, configurables por plantilla de formato. El paquete Perú incluye las plantillas de los bancos de mayor uso.

- [ ] Agregar un banco nuevo se hace con una plantilla, sin programar
- [ ] El archivo se valida contra las cuentas registradas

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §28 · RN-DEC-01 a RN-DEC-10 · Prototipo: Nómina → Declaraciones

### DEC-6 · Certificados de renta y de retenciones

*Historia* · MVP · depende de PER-5, DOC-5

Emitir el certificado de rentas y retenciones de quinta categoría y las constancias de aportes, por trabajador y ejercicio, y publicarlos en el portal. La entrega del certificado no es obligatoria desde 2017: se genera al cese, al cierre del ejercicio o a solicitud, sin alerta de incumplimiento, y puede haber más de uno por ejercicio. Los documentos se generan desde plantilla y se archivan en el legajo.

- [ ] El certificado se genera al cese, al cierre y a solicitud, cada uno con su motivo y su huella
- [ ] Los montos coinciden con el libro de retenciones del ejercicio, incluidas devoluciones

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-DEC-01 a RN-DEC-10

### DEC-7 · Bitácora de declaraciones

*Historia* · MVP · depende de DEC-2, DEC-4

Llevar por empresa y periodo el estado de cada obligación (generada, presentada, pagada, rectificada) con constancia adjunta y responsable. La bitácora alimenta el tablero de cumplimiento y el modo estudio contable.

- [ ] Se ve qué falta presentar en cada periodo
- [ ] La constancia de presentación se adjunta al registro

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · ME-24 · RN-DEC-01 a RN-DEC-10

### DEC-8 · Ficha del registro laboral y validación previa al alta

*Historia* · MVP · depende de DEC-3, COL-6

Mantener por relación laboral, con vigencia, los datos del registro laboral que el núcleo no tiene (tipo de trabajador, ocupación, situación educativa, indicadores de jornada, tipo de pago, seguros de riesgo, direcciones y estudios codificados, derechohabientes) y validar con las comprobaciones TR-01 a TR-22 antes de permitir el alta. Los códigos se validan contra las tablas paramétricas vigentes.

- [ ] Un código fuera de la tabla paramétrica vigente se rechaza al guardar
- [ ] El alta queda bloqueada con la lista de faltantes y la pantalla de origen de cada uno
- [ ] Cambiar un dato declarable crea una fila nueva y la novedad de modificación
- [ ] Las tablas paramétricas se cargan del anexo oficial con vigencia; un código retirado no invalida filas históricas

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · E-19 · RN-DEC-01 a RN-DEC-20 · Prototipo: Nómina → Declaraciones

### DEC-9 · Declaración jurada de rentas de otros empleadores

*Historia* · MVP · depende de PER-5, COL-6

Recibir del trabajador, en el ingreso o cuando cambie, la declaración jurada de rentas y retenciones de empleadores anteriores (con detalle mensual) o simultáneos (quién retiene), con su documento firmado. Una declaración nueva reemplaza a la anterior sin sobrescribirla.

- [ ] Sin declaración, el motor proyecta solo con lo pagado por la empresa y la ficha lo advierte
- [ ] Si otro empleador retiene, la retención es cero y queda el motivo
- [ ] La declaración aplica desde el mes en que se valida; no rectifica meses cerrados

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-DEC-01 a RN-DEC-20

### DEC-10 · Generador de archivos con estructuras versionadas

*Tarea técnica* · Backend · MVP · depende de DEC-2

Serializar PLAME, T-Registro y AFPnet desde estructuras guardadas como datos con vigencia (campo, tipo, longitud, obligatoriedad, tabla), con un serializador para archivos delimitados y otro para ancho fijo. El archivo generado guarda huella, tamaño, totales de control, estructura y versión del generador.

- [ ] Una modificación de la autoridad se carga como estructura nueva con su vigencia, sin cambiar código
- [ ] Regenerar un periodo cerrado produce los mismos bytes y la misma huella
- [ ] Con una estructura no verificada el archivo solo sale como borrador
- [ ] Cada estructura verificada tiene un archivo dorado aceptado por la herramienta oficial, comparado byte a byte

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §28 · RN-DEC-01 a RN-DEC-20

### DEC-11 · Devolución de retenciones de quinta en exceso

*Historia* · MVP · depende de PER-11

Registrar el exceso al cese, en la regularización de diciembre o por declaración del trabajador. El exceso se devuelve, se compensa contra las retenciones del periodo y su saldo se lleva hasta el cierre o la solicitud a la administración tributaria. Reglas RN-PE-QTA-11 a RN-PE-QTA-18 del documento 18.

- [ ] Quien calcula la devolución no la aprueba
- [ ] El saldo no compensado se arrastra o queda con la referencia del trámite

Referencias: docs/modulos/M-DEC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · ME-49 · RN-DEC-01 a RN-DEC-20

## PRV · Provisiones y asientos contables

Provisiones mensuales, asiento por centro de costo y exportación a ERP.

*Componente: Backend + Frontend*

### PRV-1 · Provisiones mensuales

*Tarea técnica* · Backend · depende de PER-8, BSO-2

Calcular en cada cierre la provisión de CTS, gratificaciones y vacaciones por trabajador, con reversión al pago efectivo. Las reglas de provisión siguen el régimen de cada trabajador.

- [ ] La provisión acumulada se concilia con lo pagado
- [ ] El detalle por trabajador exige permiso de sueldos

Referencias: docs/modulos/M-PRV.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-03 · RN-PRV-01 a RN-PRV-08

### PRV-2 · Asiento contable de planilla

*Historia* · depende de PRV-1, CPT-4

Generar el asiento del periodo agrupado por cuenta y centro de costo, con la distribución porcentual de cada trabajador, y verificar que cuadre. El asiento se genera al cerrar el periodo y se regenera si el periodo se reabre.

- [ ] El asiento cuadra entre debe y haber
- [ ] Cada línea se puede abrir hasta los conceptos y trabajadores que la componen

Referencias: docs/modulos/M-PRV.md · docs/06-reglas-de-negocio.md · E-03 · Pedido §27 · Pedido §29 · RN-PRV-01 a RN-PRV-08 · Prototipo: Nómina → Declaraciones

### PRV-3 · Exportación contable configurable

*Historia* · depende de PRV-2

Exportar el asiento en formatos configurables por plantilla y por API, con marca de exportado y control de reenvío. La exportación por API usa el mecanismo de outbox.

- [ ] El formato de exportación se define sin programar
- [ ] Un asiento exportado y luego modificado se marca para reenvío

Referencias: docs/modulos/M-PRV.md · docs/06-reglas-de-negocio.md · E-03 · Pedido §28 · Pedido §75 · RN-PRV-01 a RN-PRV-08

### PRV-4 · Reporte de costo laboral

*Historia* · depende de PRV-2

Reportar el costo laboral total por empresa, unidad y centro de costo, incluidos aportes del empleador y provisiones. El reporte lee el asiento y las provisiones, sin recalcular.

- [ ] El reporte coincide con el asiento del periodo
- [ ] Sin permiso de sueldos solo se ven totales por unidad

Referencias: docs/modulos/M-PRV.md · docs/06-reglas-de-negocio.md · E-03 · Pedido §64 · RN-PRV-01 a RN-PRV-08

### PRV-5 · Simulador público de costo laboral

*Historia* · Ola 2 · depende de NOM-9, PER-7

Publicar en la web pública un simulador que calcula el costo mensual y anual de contratar a una persona según el régimen laboral, el sueldo y la asignación familiar, con aportes, beneficios sociales y provisiones. Usa el mismo motor y los mismos parámetros legales que la planilla, por un endpoint de simulación sin datos de organizaciones. Al final ofrece crear una cuenta de prueba con lo simulado.

- [ ] El resultado coincide con el que da la planilla para el mismo caso
- [ ] El simulador no guarda datos personales y limita el uso por dirección de red
- [ ] Cada monto muestra el parámetro y la norma de los que sale

Referencias: docs/modulos/M-PRV.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-03 · RN-PRV-01 a RN-PRV-08

## LIQ · Liquidación de beneficios sociales

Cálculo y simulación de la liquidación por cese y documentos de salida.

*Componente: Backend + Frontend*

### LIQ-1 · Cálculo de la liquidación

*Tarea técnica* · Backend · MVP · depende de BSO-2, BSO-5, COL-11

Calcular al cese los conceptos truncos (CTS, gratificación y vacaciones), vacaciones no gozadas, remuneración pendiente, saldo de préstamos y adelantos (si la organización tiene ese módulo) y, cuando corresponde, la indemnización según régimen y motivo. El cálculo usa el motor de nómina con un tipo de periodo propio.

- [ ] La indemnización usa los parámetros del régimen del trabajador
- [ ] El saldo de préstamos se descuenta respetando el límite legal
- [ ] Cada monto tiene su explicación

Referencias: docs/modulos/M-LIQ.md · docs/14-localizacion-peru.md · docs/06-reglas-de-negocio.md · E-04 · Pedido §28 · RN-LIQ-01 a RN-LIQ-10

### LIQ-2 · Simulador de liquidación

*Historia* · MVP · depende de LIQ-1

Simular la liquidación de un trabajador para una fecha y motivo de cese hipotéticos, sin registrar el cese. El acceso al simulador exige permiso sobre sueldos.

- [ ] La simulación no altera ningún dato
- [ ] Se puede comparar dos fechas de cese

Referencias: docs/modulos/M-LIQ.md · docs/06-reglas-de-negocio.md · E-04 · RN-LIQ-01 a RN-LIQ-10 · Prototipo: Nómina → Beneficios sociales → Liquidación de beneficios

### LIQ-3 · Aprobación, pago y plazo de la liquidación

*Historia* · MVP · depende de LIQ-1, WFL-2

Enviar la liquidación a aprobación, generar el pago y controlar el plazo legal de pago desde el cese con alertas. El plazo es un parámetro con vigencia.

- [ ] La liquidación muestra el plazo de pago y avisa antes de vencer
- [ ] El pago se registra y cierra la liquidación

Referencias: docs/modulos/M-LIQ.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · E-04 · ME-23 · RN-LIQ-01 a RN-LIQ-10

### LIQ-4 · Documentos de cese

*Historia* · MVP · depende de LIQ-3, DOC-6

Generar desde plantilla la hoja de liquidación, el certificado de trabajo, la carta de liberación de CTS y la constancia de cese, y enviarlos a firma. Los documentos usan plantillas editables por empresa.

- [ ] Los documentos se generan con los datos de la liquidación aprobada
- [ ] Quedan archivados en el legajo y disponibles para el ex trabajador

Referencias: docs/modulos/M-LIQ.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-04 · Pedido §17 · RN-LIQ-01 a RN-LIQ-10

## LOC · Locadores y cuarta categoría

Prestadores de servicios con recibos por honorarios, separados de la planilla.

*Componente: Backend + Frontend*

### LOC-1 · Registro de prestadores

*Historia* · depende de COL-1, DOC-1

Registrar prestadores de servicios como personas con un vínculo de locación (no relación laboral): contrato, servicio, honorario, vigencia y suspensión de retenciones. El vínculo tiene su propia ficha con contrato y pagos.

- [ ] Un locador no aparece en planilla ni en conteos de trabajadores
- [ ] La misma persona puede tener historia como locador y como trabajador, sin mezclarse

Referencias: docs/modulos/M-LOC.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · E-18 · C-01 · RN-LOC-01 a RN-LOC-06

### LOC-2 · Recibos por honorarios

*Historia* · depende de LOC-1, WFL-2

Registrar los recibos por honorarios de cada prestador con su archivo, validar datos básicos y enviarlos a aprobación del responsable del servicio. El recibo se vincula al contrato y al periodo del servicio.

- [ ] Un recibo duplicado por serie y número se rechaza
- [ ] El responsable aprueba la conformidad del servicio

Referencias: docs/modulos/M-LOC.md · docs/06-reglas-de-negocio.md · E-18 · RN-LOC-01 a RN-LOC-06

### LOC-3 · Retención de cuarta categoría y pago

*Tarea técnica* · Backend · depende de LOC-2, PAR-1

Calcular la retención de cuarta categoría según parámetros (tasa, monto mínimo, suspensión vigente) y generar el lote de pago. El pago usa los mismos lotes y archivos bancarios que la planilla.

- [ ] La retención no se aplica si hay suspensión vigente registrada
- [ ] La tasa y el monto mínimo son parámetros con vigencia

Referencias: docs/modulos/M-LOC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-18 · RN-LOC-01 a RN-LOC-06

### LOC-4 · Declaración y reportes de locadores

*Historia* · depende de LOC-3, DEC-2

Incluir a los prestadores en los archivos de declaración que corresponden y reportar pagos y retenciones por periodo. Las reglas de indicios (exclusividad, horario, permanencia) las define cada organización.

- [ ] El archivo de cuarta categoría cuadra con los recibos pagados
- [ ] El reporte alerta sobre locadores con indicios de relación laboral según reglas configurables

Referencias: docs/modulos/M-LOC.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-18 · RN-LOC-01 a RN-LOC-06

# Fase 4 · Talento

## ATS · Reclutamiento, portal de empleos y base de talento

Vacantes ligadas a posiciones, portal público, pipeline configurable, ficha del candidato y base de talento.

*Componente: Backend + Frontend*

### ATS-1 · Vacantes ligadas a posiciones

*Historia* · depende de POS-6, POS-2

Crear vacantes desde una posición aprobada con puesto, descripción, requisitos, modalidad, sede, rango salarial opcional y equipo de selección. La vacante pone la posición en reclutamiento.

- [ ] Con la política de dotación activa no se crea una vacante sin posición libre
- [ ] El rango salarial solo lo ven los roles con permiso

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · Pedido §10 · Pedido §5 · C-02 · ME-05 · RN-ATS-01 a RN-ATS-12 · Prototipo: Talento → Reclutamiento

### ATS-2 · Aprobación y publicación de la vacante

*Historia* · depende de ATS-1, WFL-2

Enviar la vacante a aprobación y, una vez aprobada, publicarla en el portal de empleos, internamente y en bolsas externas por adaptador, con fechas de apertura y cierre. Las bolsas externas se conectan por adaptador con implementación falsa.

- [ ] Una vacante no se publica sin aprobación cuando el flujo lo exige
- [ ] Despublicar retira la vacante del portal de inmediato

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §10 · Pedido §56 · ME-05 · RN-ATS-01 a RN-ATS-12

### ATS-3 · Portal público de empleos

*Historia* · depende de ATS-2

Construir el portal de empleos por organización con su marca: listado de vacantes con ubicación, modalidad, descripción, requisitos y beneficios, filtros y páginas preparadas para buscadores. El portal se sirve sin sesión y solo expone lo publicado.

- [ ] Cada vacante tiene dirección propia, metadatos y datos estructurados de oferta de empleo
- [ ] El portal solo muestra vacantes publicadas de esa organización
- [ ] El portal carga rápido y funciona en el móvil

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · Pedido §11 · RN-ATS-01 a RN-ATS-12 · Prototipo: Talento → Portal de empleos

### ATS-4 · Formulario de postulación configurable

*Historia* · depende de ATS-3

Definir por vacante el formulario de postulación (datos, CV, preguntas filtro, pretensión salarial, fuente) con consentimiento de tratamiento de datos y protección contra envíos automáticos. Las respuestas se guardan en la ficha del candidato.

- [ ] El postulante acepta la finalidad del tratamiento antes de enviar
- [ ] Las preguntas filtro pueden marcar a un candidato para revisión, nunca descartarlo de forma automática
- [ ] La postulación confirma la recepción al candidato

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · Pedido §11 · Pedido §10 · C-08 · E-16 · RN-ATS-01 a RN-ATS-12

### ATS-5 · Pipeline configurable con arrastrar y soltar

*Historia* · depende de ATS-4, UI-12

Configurar etapas por vacante (nuevo, preselección, entrevista RRHH, entrevista técnica, evaluación, referencias, oferta, contratado) y mover candidatos en un tablero kanban, con motivo de descarte y comunicaciones por etapa. Cada etapa puede disparar acciones automáticas como correos o tareas.

- [ ] Mover un candidato de etapa queda en su actividad
- [ ] Descartar exige motivo de un catálogo
- [ ] Las etapas se configuran por plantilla de proceso

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §10 · ME-06 · RN-ATS-01 a RN-ATS-12 · Prototipo: Talento → Reclutamiento

### ATS-6 · Ficha del candidato

*Historia* · depende de ATS-5

Reunir en la ficha del candidato datos, CV, experiencia, educación, pretensión salarial, fuente, vacantes, notas, documentos, entrevistas, evaluaciones y actividad, con comentarios del equipo de selección. La ficha deduplica candidatos por documento y por correo.

- [ ] Un candidato que postula a varias vacantes tiene una sola ficha
- [ ] Las notas internas no son visibles para el candidato

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §10 · RN-ATS-01 a RN-ATS-12

### ATS-7 · Base de talento

*Historia* · depende de ATS-6

Guardar candidatos no contratados en la base de talento con etiquetas, búsqueda y filtros avanzados (habilidades, experiencia, ubicación, fuente) y reutilizarlos en nuevas vacantes. La búsqueda usa texto completo sobre el CV y los campos estructurados.

- [ ] Un candidato de la base se agrega a una vacante sin volver a postular
- [ ] La base respeta el plazo de conservación consentido por el candidato

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · Pedido §12 · E-16 · RN-ATS-01 a RN-ATS-12 · Prototipo: Talento → Base de talento

### ATS-8 · Referidos y movilidad interna

*Historia* · depende de ATS-5, ESS-1

Publicar vacantes internas para que los colaboradores postulen desde su portal y permitir que refieran candidatos, con seguimiento del referido y regla de reconocimiento. Las postulaciones internas se tratan con confidencialidad frente al jefe actual.

- [ ] El colaborador ve el estado de sus referidos
- [ ] Una postulación interna avisa al jefe actual solo en la etapa que la política define

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · E-14 · Pedido §12 · RN-ATS-01 a RN-ATS-12

### ATS-9 · Indicadores de reclutamiento

*Historia* · depende de ATS-5

Medir tiempo de cobertura, embudo por etapa, fuentes y costo por contratación por vacante y por reclutador. Los datos se exponen también a la capa analítica.

- [ ] Los indicadores se calculan desde los movimientos reales del pipeline
- [ ] Cada indicador muestra su definición

Referencias: docs/modulos/M-ATS.md · docs/06-reglas-de-negocio.md · Pedido §64 · Pedido §10 · RN-ATS-01 a RN-ATS-12

## ENT · Entrevistas y ofertas

Agenda de entrevistas, evaluación estructurada y ofertas con plantilla y aprobación.

*Componente: Backend + Frontend*

### ENT-1 · Agenda de entrevistas

*Historia* · depende de ATS-5, NOT-3

Programar entrevistas con fecha, hora, entrevistadores, tipo, lugar o enlace, e invitación al candidato y a los entrevistadores con archivo de calendario. La agenda se muestra en el calendario de RRHH y en la ficha del candidato.

- [ ] Se detectan choques de horario de los entrevistadores
- [ ] El candidato puede confirmar o pedir reprogramación desde el enlace recibido

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · Pedido §13 · Pedido §78 · RN-ENT-01 a RN-ENT-09 · Prototipo: Talento → Reclutamiento

### ENT-2 · Guías y scorecards

*Historia* · depende de ENT-1

Crear guías de entrevista y scorecards por puesto con criterios, escala y preguntas sugeridas, reutilizables por vacante. Las guías se versionan y se asignan por etapa del proceso.

- [ ] Una vacante puede usar scorecards distintos por etapa
- [ ] Los criterios pueden vincularse a competencias del puesto

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · Pedido §13 · RN-ENT-01 a RN-ENT-09

### ENT-3 · Evaluación del candidato por los entrevistadores

*Historia* · depende de ENT-2

Permitir que cada entrevistador complete su scorecard de forma independiente y mostrar la consolidación cuando todos hayan evaluado. La evaluación se completa desde el móvil.

- [ ] Un entrevistador no ve las evaluaciones de otros hasta enviar la suya
- [ ] La consolidación muestra promedio y dispersión por criterio

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · Pedido §13 · RN-ENT-01 a RN-ENT-09

### ENT-4 · Plantillas y generación de la oferta

*Historia* · depende de ENT-3, DOC-4

Generar la carta oferta desde plantillas con variables (`{{nombre}}`, `{{cargo}}`, `{{salario}}`, `{{fecha_ingreso}}`) y los datos de la posición. Las plantillas se gestionan con el generador de documentos.

- [ ] La oferta valida que el sueldo esté dentro de la banda o pide justificación
- [ ] La vista previa muestra el documento final

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · Pedido §14 · Pedido §20 · RN-ENT-01 a RN-ENT-09

### ENT-5 · Flujo de la oferta

*Historia* · depende de ENT-4, WFL-2, DOC-6

Llevar la oferta por sus estados: borrador, revisión, aprobación, enviada y aceptada o rechazada, con vencimiento y firma del candidato. El flujo usa el motor de aprobaciones y el flujo de firma.

- [ ] La oferta no se envía sin las aprobaciones configuradas
- [ ] El candidato acepta o rechaza desde un enlace seguro y queda la evidencia

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §14 · C-12 · ME-07 · RN-ENT-01 a RN-ENT-09

### ENT-6 · Contratación del candidato

*Historia* · depende de ENT-5, COL-2

Convertir al candidato con oferta aceptada en futuro colaborador: crear o reutilizar la persona, preparar el alta de la relación laboral con los datos de la oferta y emitir `candidate.hired`. La conversión es una acción explícita con permiso propio.

- [ ] La persona no se duplica si ya existía
- [ ] La posición pasa a ocupada en la fecha de ingreso
- [ ] El resto de candidatos de la vacante recibe la comunicación de cierre que se configure

Referencias: docs/modulos/M-ENT.md · docs/06-reglas-de-negocio.md · Pedido §10 · Pedido §15 · Pedido §74 · C-01 · RN-ENT-01 a RN-ENT-09

## ONB · Pre-onboarding y onboarding

Recolección previa de datos y documentos, diseñador de procesos de ingreso y seguimiento.

*Componente: Backend + Frontend*

### ONB-1 · Portal de pre-onboarding

*Historia* · depende de ENT-6, DOC-3

Dar al futuro colaborador un acceso previo al ingreso para completar datos, documentos, cuenta bancaria, contactos de emergencia, derechohabientes y firmar su contrato. El acceso usa un enlace personal con verificación por documento y código.

- [ ] El futuro colaborador accede sin correo corporativo
- [ ] El avance de lo solicitado es visible para RRHH
- [ ] Los datos entran a la ficha tras la validación de RRHH

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · Pedido §15 · C-09 · RN-ONB-01 a RN-ONB-10

### ONB-2 · Validación y alta desde el pre-onboarding

*Historia* · depende de ONB-1, CON-2

Permitir que RRHH revise lo recibido, observe lo incorrecto y confirme el alta de la relación laboral con contrato firmado. La validación campo a campo deja registro de quién aprobó cada dato.

- [ ] Un documento observado vuelve al futuro colaborador con el motivo
- [ ] El alta confirmada emite `employee.hired`

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · Pedido §15 · Pedido §103 · RN-ONB-01 a RN-ONB-10

### ONB-3 · Diseñador de plantillas de onboarding

*Historia* · depende de WFL-8, WFL-5

Diseñar plantillas por empresa, sede, puesto y área con tareas por hito relativo a la fecha de ingreso (antes del ingreso, primer día, primeros siete días, 30, 60 y 90 días), responsable por rol y tipo de tarea. Las plantillas se versionan y se prueban con un ingreso de ejemplo.

- [ ] Una plantilla se asigna sola según las condiciones del nuevo ingreso
- [ ] Las tareas pueden ser de TI, jefe, RRHH o del propio colaborador

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · Pedido §16 · Pedido §55 · RN-ONB-01 a RN-ONB-10 · Prototipo: Talento → Onboarding

### ONB-4 · Ejecución del proceso de onboarding

*Historia* · depende de ONB-3, ONB-2

Crear el proceso de cada ingreso desde su plantilla con tareas fechadas, recordatorios, cursos asignados, documentos por firmar y reuniones con el jefe. Las tareas se crean con el motor de tareas y sus fechas se recalculan si cambia la fecha de ingreso.

- [ ] El alta de un colaborador crea su proceso sin intervención
- [ ] Cada responsable ve sus tareas en la bandeja de pendientes

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §16 · Pedido §103 · Pedido §77 · ME-08 · RN-ONB-01 a RN-ONB-10

### ONB-5 · Avance y seguimiento

*Historia* · depende de ONB-4

Mostrar el porcentaje de avance por colaborador, el tablero de procesos en curso y los hitos de seguimiento (30, 60 y 90 días) con cierre formal. El nuevo colaborador ve su propio avance en el portal.

- [ ] El avance se calcula sobre las tareas obligatorias
- [ ] Los procesos atrasados se resaltan con su causa

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · Pedido §16 · RN-ONB-01 a RN-ONB-10 · Prototipo: Talento → Onboarding

### ONB-6 · Evaluación del periodo de prueba

*Historia* · depende de ONB-5, CON-2

Incluir en el onboarding la evaluación del periodo de prueba por el jefe antes de su vencimiento, con recomendación y registro de la decisión tomada por una persona. La evaluación usa un formulario breve configurable.

- [ ] El jefe recibe el aviso con anticipación configurable
- [ ] La decisión queda registrada con autor

Referencias: docs/modulos/M-ONB.md · docs/06-reglas-de-negocio.md · Pedido §16 · Pedido §18 · C-08 · RN-ONB-01 a RN-ONB-10

## OFF · Offboarding

Proceso de salida con motivos, lista de verificación, entrevista de salida y cierre de accesos.

*Componente: Backend + Frontend*

### OFF-1 · Inicio del proceso de salida

*Historia* · depende de COL-11, WFL-2

Iniciar la salida por renuncia, despido, fin de contrato, jubilación u otro motivo, con fecha de cese, carta adjunta y aprobaciones según el tipo. El inicio registra la fecha de comunicación y la del último día de labores.

- [ ] El motivo de salida se registra con catálogo y detalle
- [ ] El proceso de despido exige los documentos del procedimiento antes de avanzar

Referencias: docs/modulos/M-OFF.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §17 · ME-09 · RN-OFF-01 a RN-OFF-09 · Prototipo: Talento → Offboarding

### OFF-2 · Lista de verificación de salida

*Historia* · depende de OFF-1, WFL-8

Generar desde plantilla las tareas de salida: carta, liquidación, devolución de activos, desactivación de accesos, entrega de documentos, encuesta y entrevista de salida. La plantilla varía según el motivo de salida y el puesto.

- [ ] Cada tarea tiene responsable y estado
- [ ] El proceso no se cierra con tareas obligatorias pendientes

Referencias: docs/modulos/M-OFF.md · docs/06-reglas-de-negocio.md · Pedido §17 · Pedido §77 · RN-OFF-01 a RN-OFF-09

### OFF-3 · Entrevista y encuesta de salida

*Historia* · depende de OFF-2

Registrar la entrevista de salida con guía y notas confidenciales, y enviar la encuesta de salida. La encuesta se envía con el módulo de encuestas cuando está activo.

- [ ] Las notas de la entrevista solo las ve RRHH con permiso
- [ ] Los motivos de salida alimentan la analítica de rotación

Referencias: docs/modulos/M-OFF.md · docs/06-reglas-de-negocio.md · Pedido §17 · RN-OFF-01 a RN-OFF-09

### OFF-4 · Desactivación de accesos al cese

*Tarea técnica* · Backend · depende de OFF-1, SEG-6

Desactivar en la fecha de cese los accesos del usuario, conservando el acceso de ex colaborador a sus boletas y documentos de cese. La desactivación se dispara con el evento de cese y alcanza a integraciones y tokens.

- [ ] El usuario cesado no puede operar como colaborador activo
- [ ] El ex colaborador descarga sus documentos de cese durante el plazo configurado

Referencias: docs/modulos/M-OFF.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §17 · RN-OFF-01 a RN-OFF-09

### OFF-5 · Liquidación y documentos de salida en el proceso

*Historia* · depende de OFF-2, LIQ-4

Enlazar el proceso de salida con la liquidación de beneficios y los documentos de cese, mostrando su estado dentro de la lista de verificación. El vínculo es de solo lectura: cada módulo conserva su propio proceso.

- [ ] La tarea de liquidación se completa sola cuando la liquidación se paga
- [ ] Los documentos de cese firmados se ven en el proceso

Referencias: docs/modulos/M-OFF.md · docs/06-reglas-de-negocio.md · Pedido §17 · E-04 · RN-OFF-01 a RN-OFF-09

## DES · Evaluación de desempeño

Campañas de 90° a 360° con formularios, escalas, calibración y resultados.

*Componente: Backend + Frontend*

### DES-1 · Configuración de campañas

*Historia* · depende de HIS-6, WFL-1

Crear campañas de evaluación (90°, 180°, 270°, 360°) con población, periodo evaluado, etapas, fechas y pesos por tipo de evaluador. Una campaña puede clonarse de otra anterior.

- [ ] La población se define por filtros y se puede ajustar persona a persona
- [ ] La campaña sigue su máquina de estados hasta el cierre

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §38 · ME-25 · RN-DES-01 a RN-DES-10 · Prototipo: Talento → Evaluaciones

### DES-2 · Formularios, preguntas y escalas

*Historia* · depende de DES-1, CMP-2

Diseñar formularios con secciones de competencias, objetivos y preguntas abiertas, y escalas configurables con descriptores. Los formularios se versionan por campaña.

- [ ] Un formulario puede variar por nivel de puesto
- [ ] Las competencias evaluadas salen del perfil del puesto

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · Pedido §38 · Pedido §39 · RN-DES-01 a RN-DES-10

### DES-3 · Red de evaluadores

*Historia* · depende de DES-1

Asignar autoevaluación, jefe, pares, subordinados y otros, con propuesta de pares por el evaluado y validación del jefe. RRHH puede ajustar la red y resolver casos sin jefe o con jefe nuevo.

- [ ] La red respeta un mínimo de evaluadores por tipo para proteger el anonimato de pares y subordinados
- [ ] El jefe se toma de la línea de reporte vigente al periodo evaluado

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · Pedido §38 · C-06 · RN-DES-01 a RN-DES-10

### DES-4 · Ejecución de la evaluación

*Historia* · depende de DES-2, DES-3, WFL-9

Permitir a cada participante completar sus evaluaciones con guardado parcial, recordatorios y avance visible para RRHH. Las respuestas se guardan por evaluador y no se muestran al evaluado hasta la publicación.

- [ ] Las evaluaciones pendientes aparecen en la bandeja de cada persona
- [ ] RRHH ve el avance por unidad y puede reabrir una evaluación

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §38 · Pedido §8 · ME-26 · RN-DES-01 a RN-DES-10

### DES-5 · Calibración

*Historia* · depende de DES-4

Dar a RRHH y a los líderes una sesión de calibración por unidad con distribución de resultados y ajuste justificado. La calibración se hace antes de publicar los resultados.

- [ ] Todo ajuste en calibración guarda el valor original, el nuevo y el motivo
- [ ] La distribución se ve por unidad y por jefe

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · Pedido §38 · RN-DES-01 a RN-DES-10

### DES-6 · Resultados y devolución

*Historia* · depende de DES-5

Publicar el informe individual con resultados por competencia y por tipo de evaluador, registrar la reunión de devolución y la conformidad del evaluado. El informe se genera en PDF y se guarda en el legajo.

- [ ] Las respuestas de pares y subordinados se muestran agregadas
- [ ] El resultado queda en la pestaña de desempeño de la ficha

Referencias: docs/modulos/M-DES.md · docs/06-reglas-de-negocio.md · Pedido §38 · Pedido §6 · RN-DES-01 a RN-DES-10

## OKR · Objetivos

Objetivos corporativos, de área e individuales con resultados clave y avance.

*Componente: Backend + Frontend*

### OKR-1 · Ciclos y objetivos

*Historia* · depende de HIS-6, WFL-2

Crear ciclos de objetivos y objetivos de nivel corporativo, de área e individual, con responsable, peso y visibilidad. Los ciclos tienen fechas de definición, seguimiento y cierre.

- [ ] Un objetivo pertenece a un ciclo y a un nivel
- [ ] Los objetivos individuales pueden requerir aprobación del jefe

Referencias: docs/modulos/M-OKR.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · Pedido §40 · ME-27 · RN-OKR-01 a RN-OKR-08 · Prototipo: Talento → Objetivos

### OKR-2 · Resultados clave y avance

*Historia* · depende de OKR-1

Definir resultados clave con métrica, valor inicial, meta y unidad, y registrar avances con comentario y evidencia. Los resultados clave admiten avance numérico, porcentual o por hitos.

- [ ] El avance del objetivo se deriva de sus resultados clave
- [ ] Cada actualización queda en el historial

Referencias: docs/modulos/M-OKR.md · docs/06-reglas-de-negocio.md · Pedido §40 · RN-OKR-01 a RN-OKR-08

### OKR-3 · Alineación jerárquica

*Historia* · depende de OKR-2, UI-12

Enlazar objetivos con el objetivo superior al que contribuyen y mostrar el árbol de alineación. La alineación puede cruzar unidades.

- [ ] El árbol muestra cómo los objetivos individuales aportan a los corporativos
- [ ] Un objetivo sin alinear se identifica

Referencias: docs/modulos/M-OKR.md · docs/06-reglas-de-negocio.md · Pedido §40 · RN-OKR-01 a RN-OKR-08

### OKR-4 · Seguimiento periódico y tablero

*Historia* · depende de OKR-3, ESS-1

Pedir actualizaciones periódicas de avance con nivel de confianza y mostrar el tablero por unidad, además de las tarjetas en los portales. La frecuencia de actualización se define por ciclo.

- [ ] Los objetivos sin actualización reciente se resaltan
- [ ] El colaborador ve sus objetivos en su inicio

Referencias: docs/modulos/M-OKR.md · docs/06-reglas-de-negocio.md · Pedido §40 · Pedido §108 · Pedido §107 · RN-OKR-01 a RN-OKR-08

### OKR-5 · Objetivos en la evaluación de desempeño

*Tarea técnica* · Backend · depende de OKR-2, DES-2

Exponer el cumplimiento de objetivos del periodo para que la evaluación de desempeño lo incorpore como sección con su peso. El dato se lee del módulo de objetivos al abrir la evaluación y se congela al cerrar la campaña.

- [ ] La evaluación muestra los objetivos y su cumplimiento sin redigitar
- [ ] El peso de la sección de objetivos se define en el formulario de la campaña

Referencias: docs/modulos/M-OKR.md · docs/06-reglas-de-negocio.md · Pedido §40 · Pedido §38 · C-11 · RN-OKR-01 a RN-OKR-08

## CMP · Competencias y habilidades

Catálogo de competencias con niveles, perfiles por puesto, matriz de habilidades y brechas.

*Componente: Backend + Frontend*

### CMP-1 · Catálogo de competencias y niveles

*Historia* · depende de ORG-5

Mantener el catálogo de competencias (liderazgo, comunicación, trabajo en equipo, orientación a resultados, innovación, organización y las propias) con niveles configurables y conductas observables por nivel. Las competencias se agrupan por familia y tienen vigencia.

- [ ] Cada nivel tiene su descripción de conductas
- [ ] El catálogo distingue competencias transversales y específicas

Referencias: docs/modulos/M-CMP.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §39 · RN-CMP-01 a RN-CMP-06 · Prototipo: Talento → Competencias

### CMP-2 · Perfil de competencias por puesto

*Historia* · depende de CMP-1

Asignar a cada puesto de catálogo las competencias requeridas y su nivel esperado. El perfil alimenta evaluaciones, brechas, scorecards y rutas de carrera.

- [ ] El perfil del puesto se versiona
- [ ] Un puesto sin perfil se identifica en un reporte

Referencias: docs/modulos/M-CMP.md · docs/06-reglas-de-negocio.md · Pedido §39 · RN-CMP-01 a RN-CMP-06

### CMP-3 · Matriz de habilidades

*Historia* · depende de CMP-1, COL-3

Registrar habilidades técnicas y certificaciones por colaborador con nivel declarado y validado por el jefe, y mostrar la matriz por equipo. Las habilidades usan un catálogo propio, separado del de competencias conductuales.

- [ ] Se busca en la organización quién tiene una habilidad y con qué nivel
- [ ] Las certificaciones con vencimiento generan alerta

Referencias: docs/modulos/M-CMP.md · docs/06-reglas-de-negocio.md · E-14 · Pedido §39 · RN-CMP-01 a RN-CMP-06

### CMP-4 · Brechas de competencias

*Historia* · depende de CMP-2, CMP-3

Comparar el nivel evaluado o validado de cada persona con el requerido por su puesto y mostrar brechas individuales y por equipo. La brecha agregada por equipo orienta el plan de capacitación.

- [ ] La brecha se actualiza con el resultado de la última evaluación
- [ ] La pestaña de competencias de la ficha muestra nivel actual y requerido

Referencias: docs/modulos/M-CMP.md · docs/06-reglas-de-negocio.md · Pedido §39 · Pedido §44 · Pedido §6 · RN-CMP-01 a RN-CMP-06

## FBK · Reuniones 1:1 y feedback

Reuniones individuales con agenda y compromisos, y feedback continuo integrado al desempeño.

*Componente: Backend + Frontend*

### FBK-1 · Reuniones 1:1

*Historia* · depende de HIS-6, UI-12

Crear reuniones individuales recurrentes entre manager y colaborador con agenda compartida, temas propuestos por ambos, notas y compromisos. La recurrencia se configura por pareja de manager y colaborador.

- [ ] Ambos agregan temas antes de la reunión
- [ ] Los compromisos pendientes pasan a la siguiente reunión

Referencias: docs/modulos/M-FBK.md · docs/06-reglas-de-negocio.md · Pedido §41 · RN-FBK-01 a RN-FBK-06 · Prototipo: Talento → 1:1 y feedback → Reuniones 1:1

### FBK-2 · Notas privadas y seguimiento de compromisos

*Historia* · depende de FBK-1

Permitir notas privadas visibles solo para su autor y dar seguimiento a los compromisos con responsable y fecha. La privacidad de las notas se aplica en el backend, no solo en la pantalla.

- [ ] Las notas privadas no las ve la otra parte ni RRHH
- [ ] Los compromisos vencidos se notifican

Referencias: docs/modulos/M-FBK.md · docs/06-reglas-de-negocio.md · Pedido §41 · docs/07-permisos-y-roles.md · RN-FBK-01 a RN-FBK-06

### FBK-3 · Feedback continuo

*Historia* · depende de FBK-1, WFL-9

Permitir solicitar feedback a otras personas, darlo de forma espontánea, reconocer y crear un seguimiento, con visibilidad configurable (privado, compartido con el jefe). El feedback puede vincularse a una competencia o a un objetivo.

- [ ] Quien recibe feedback sabe quién lo dio salvo que la política permita anonimato
- [ ] La solicitud de feedback aparece en pendientes

Referencias: docs/modulos/M-FBK.md · docs/06-reglas-de-negocio.md · Pedido §42 · RN-FBK-01 a RN-FBK-06

### FBK-4 · Feedback y 1:1 en la evaluación de desempeño

*Tarea técnica* · Backend · depende de FBK-3, DES-4

Mostrar al evaluador, durante la evaluación, el feedback recibido y los compromisos del periodo de su evaluado según la visibilidad definida. La vista es de solo lectura y respeta el periodo evaluado.

- [ ] El evaluador consulta el feedback del periodo sin salir del formulario
- [ ] El feedback privado no se expone

Referencias: docs/modulos/M-FBK.md · docs/06-reglas-de-negocio.md · Pedido §42 · Pedido §38 · RN-FBK-01 a RN-FBK-06

# Fase 5 · Desarrollo y compensación

## LMS · Aprendizaje y plan anual

Cursos, lecciones, evaluaciones, certificados, asignaciones, seguimiento y plan anual de capacitación.

*Componente: Backend + Frontend*

### LMS-1 · Cursos, categorías y lecciones

*Historia* · depende de PLT-7, UI-12

Crear cursos con categoría, descripción, duración declarada, lecciones ordenadas y estado de publicación. Los cursos pueden ser internos o de proveedor externo.

- [ ] Un curso no publicado no es visible para los colaboradores
- [ ] Las lecciones se reordenan arrastrando

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §47 · RN-LMS-01 a RN-LMS-09 · Prototipo: Aprendizaje → Cursos

### LMS-2 · Contenidos de las lecciones

*Historia* · depende de LMS-1

Soportar lecciones de video, PDF, texto enriquecido, enlace y paquete SCORM, con registro de avance por lección. Los contenidos se almacenan con el servicio de archivos.

- [ ] El avance se guarda y el colaborador retoma donde quedó
- [ ] Los videos se reproducen por transmisión protegida, sin enlace público

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §47 · RN-LMS-01 a RN-LMS-09

### LMS-3 · Evaluaciones del curso

*Historia* · depende de LMS-2

Crear evaluaciones con banco de preguntas, nota mínima, intentos permitidos y retroalimentación. El resultado de la evaluación determina si el curso se da por completado.

- [ ] La nota mínima y los intentos son configurables por curso
- [ ] Las preguntas se presentan en orden aleatorio

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §47 · RN-LMS-01 a RN-LMS-09

### LMS-4 · Certificados

*Historia* · depende de LMS-3, DOC-5

Emitir el certificado al completar el curso con plantilla, código de verificación y vigencia, y archivarlo en el legajo. El certificado se genera con el generador de documentos.

- [ ] El certificado se verifica con su código
- [ ] Un certificado con vigencia genera alerta antes de vencer

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §47 · Pedido §20 · RN-LMS-01 a RN-LMS-09

### LMS-5 · Asignación de cursos

*Historia* · depende de LMS-1, WFL-5

Asignar cursos a una persona, equipo, puesto o empresa, con fecha límite y obligatoriedad, y mediante reglas automáticas al ingreso o al cambio de puesto. Las reglas usan el motor de automatizaciones.

- [ ] Un nuevo ingreso recibe los cursos obligatorios de su puesto sin intervención
- [ ] La asignación indica si el curso es obligatorio y su vencimiento

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §47 · Pedido §103 · Pedido §56 · RN-LMS-01 a RN-LMS-09 · Prototipo: Aprendizaje → Cursos

### LMS-6 · Seguimiento del aprendizaje

*Historia* · depende de LMS-5, ESS-1

Seguir cada inscripción por estado (no iniciado, en progreso, completado, vencido) con tableros por unidad y por curso, recordatorios y la tarjeta de cursos en el portal. El estado vencido se calcula con la fecha límite de la asignación.

- [ ] Los cursos vencidos se listan por jefe y por unidad
- [ ] El colaborador ve sus cursos y su avance en el inicio

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §47 · Pedido §108 · Pedido §68 · ME-28 · RN-LMS-01 a RN-LMS-09 · Prototipo: Aprendizaje → Cursos

### LMS-7 · Plan anual de capacitación

*Historia* · depende de LMS-5, WFL-2

Planificar por año los cursos, presupuesto, participantes, horas, proveedores y resultados esperados, con aprobación del plan. El plan parte de las brechas de competencias y de las capacitaciones obligatorias.

- [ ] El plan asigna presupuesto por unidad y por curso
- [ ] El plan aprobado genera las actividades de capacitación a ejecutar

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §48 · RN-LMS-01 a RN-LMS-09 · Prototipo: Aprendizaje → Plan anual

### LMS-8 · Planificado frente a ejecutado

*Historia* · depende de LMS-7, LMS-6

Comparar lo planificado con lo ejecutado en cursos, participantes, horas y presupuesto, y registrar la capacitación presencial o externa con lista de asistencia. El comparativo se actualiza con las inscripciones y los registros de ejecución.

- [ ] El comparativo muestra la desviación por curso y por unidad
- [ ] Una capacitación externa se registra con sus participantes y sustento

Referencias: docs/modulos/M-LMS.md · docs/06-reglas-de-negocio.md · Pedido §48 · RN-LMS-01 a RN-LMS-09

## CAR · Desarrollo, carrera, sucesión y 9-box

Planes de desarrollo, rutas de carrera, sucesión de posiciones críticas y matriz 9-box.

*Componente: Backend + Frontend*

### CAR-1 · Planes de desarrollo individual

*Historia* · depende de DES-6, CMP-4, LMS-5

Crear planes de desarrollo tras una evaluación: competencia, nivel actual, nivel objetivo y acciones (curso, mentoría, proyecto, seguimiento) con responsable y fechas. El plan se acuerda entre colaborador y jefe y queda con vigencia.

- [ ] El plan parte de las brechas detectadas y permite agregar otras
- [ ] El avance de las acciones se actualiza y se ve en la ficha

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §43 · RN-CAR-01 a RN-CAR-09 · Prototipo: Talento → Desarrollo y sucesión → Planes de desarrollo

### CAR-2 · Rutas de carrera

*Historia* · depende de CMP-2

Definir rutas entre puestos (junior, semi sénior, sénior, líder, manager y rutas laterales) con los requisitos de cada paso. Las rutas se apoyan en los perfiles de competencias de cada puesto.

- [ ] Una ruta enlaza puestos de catálogo y admite bifurcaciones
- [ ] El colaborador ve las rutas posibles desde su puesto

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §44 · RN-CAR-01 a RN-CAR-09 · Prototipo: Talento → Desarrollo y sucesión → Plan de carrera

### CAR-3 · Brechas frente al siguiente paso

*Historia* · depende de CAR-2, CAR-1

Mostrar al colaborador y a su jefe las competencias requeridas por el puesto destino y la brecha frente a su nivel actual, con sugerencia de acciones. La vista no promete promociones: informa requisitos y brechas.

- [ ] La brecha se calcula contra el perfil del puesto destino
- [ ] Las acciones sugeridas pueden agregarse al plan de desarrollo

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §44 · RN-CAR-01 a RN-CAR-09

### CAR-4 · Posiciones críticas y sucesores

*Historia* · depende de POS-1, CAR-1

Marcar posiciones críticas y proponer sucesores con nivel de preparación (preparado ahora, en un año, en dos años, en desarrollo) y riesgo de salida del titular. Cada sucesor se evalúa de forma individual y con comentario.

- [ ] La sucesión se define sobre posiciones, no sobre puestos de catálogo
- [ ] La lista de sucesores es confidencial y exige permiso propio

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §45 · C-02 · RN-CAR-01 a RN-CAR-09 · Prototipo: Talento → Desarrollo y sucesión

### CAR-5 · Matriz de sucesión

*Historia* · depende de CAR-4

Mostrar la matriz de sucesión por unidad con cobertura de cada posición crítica y las posiciones sin sucesor. La matriz se arma con las posiciones críticas y sus sucesores vigentes.

- [ ] Las posiciones críticas sin sucesor preparado se resaltan
- [ ] La matriz se exporta para el comité de talento

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §45 · RN-CAR-01 a RN-CAR-09

### CAR-6 · Matriz 9-box

*Historia* · depende de DES-6

Ubicar a las personas en la matriz de desempeño por potencial, con el desempeño tomado de la última evaluación y el potencial valorado por el jefe con criterios definidos, y permitir análisis por unidad. La valoración de potencial usa un cuestionario configurable.

- [ ] La matriz no dispara ninguna acción automática sobre las personas
- [ ] Cada ubicación guarda quién la valoró y con qué criterios
- [ ] La pantalla recuerda que la matriz es insumo de análisis, no una decisión

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §46 · C-08 · RN-CAR-01 a RN-CAR-09 · Prototipo: Talento → Desarrollo y sucesión → Matriz 9-box

### CAR-7 · Sesión de revisión de talento

*Historia* · depende de CAR-5, CAR-6, WFL-8

Preparar la sesión de revisión de talento por unidad con 9-box, sucesión y planes de desarrollo, y registrar los acuerdos como acciones con responsable. La sesión conserva una foto de los datos tal como estaban al realizarse.

- [ ] Los acuerdos de la sesión generan tareas con seguimiento
- [ ] La información de la sesión solo la ven los participantes autorizados

Referencias: docs/modulos/M-CAR.md · docs/06-reglas-de-negocio.md · Pedido §45 · Pedido §46 · RN-CAR-01 a RN-CAR-09

## COM · Compensaciones, bandas, revisión salarial e igualdad salarial

Estructura de compensación, bandas, campañas de revisión con presupuesto e igualdad salarial.

*Componente: Backend + Frontend*

### COM-1 · Estructura de compensación

*Historia* · depende de HIS-3, PER-8

Presentar la compensación total de cada colaborador (sueldo base, variable, bonos, comisiones y beneficios) a partir de la fuente única, con historial y comparación en el tiempo. Es una vista de consulta para RRHH y, si la política lo permite, para el propio colaborador.

- [ ] La pantalla no guarda otra copia del sueldo: lee la compensación con vigencia
- [ ] El total anual se calcula con los conceptos de planilla

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · Pedido §33 · C-11 · RN-COM-01 a RN-COM-12 · Prototipo: Personas → Ficha del colaborador → Compensación

### COM-2 · Bandas salariales

*Historia* · depende de ORG-5, CFG-7

Definir bandas por nivel o familia de puestos con mínimo, medio y máximo, moneda y vigencia. Las bandas admiten variantes por sede o por país.

- [ ] Una banda se asocia a puestos o niveles
- [ ] Las bandas se actualizan con fecha efectiva

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §33 · RN-COM-01 a RN-COM-12 · Prototipo: Compensación → Bandas salariales

### COM-3 · Posición del trabajador en la banda

*Historia* · depende de COM-2, COM-1

Calcular la posición de cada trabajador dentro de su banda y listar quiénes están por debajo del mínimo o por encima del máximo. El cálculo usa la compensación vigente convertida a la moneda de la banda.

- [ ] La posición en banda se ve en la ficha con el permiso de sueldos
- [ ] El listado de casos fuera de banda se filtra por unidad

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · Pedido §33 · RN-COM-01 a RN-COM-12

### COM-4 · Campañas de revisión salarial

*Historia* · depende de COM-3, WFL-2, DES-6

Crear campañas de revisión con población, criterios y flujo: el manager propone, RRHH revisa, Finanzas aprueba y Dirección aprueba. La campaña usa el motor de aprobaciones con etapas propias.

- [ ] El manager propone aumentos para su equipo viendo desempeño y posición en banda
- [ ] Cada propuesta recorre las etapas con comentarios

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §34 · Pedido §56 · ME-29 · RN-COM-01 a RN-COM-12 · Prototipo: Compensación → Revisión salarial

### COM-5 · Control de presupuesto de la campaña

*Historia* · depende de COM-4

Asignar presupuesto por unidad y controlar en tiempo real lo propuesto frente a lo disponible, con guías de aumento por desempeño y posición en banda. Las guías son matrices configurables por campaña.

- [ ] Una propuesta que excede el presupuesto de la unidad se señala y requiere justificación
- [ ] El consumo del presupuesto se ve por unidad y total

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · Pedido §34 · RN-COM-01 a RN-COM-12

### COM-6 · Aplicación de los aumentos aprobados

*Tarea técnica* · Backend · depende de COM-5, HIS-3, CON-3

Al cerrar la campaña, registrar los aumentos aprobados como nuevos registros de compensación con fecha efectiva, generar las adendas y las cartas de comunicación. La aplicación se ejecuta como una acción explícita de RRHH tras la última aprobación.

- [ ] Ningún sueldo cambia sin la aprobación final de una persona
- [ ] Cada aumento queda en la historia con la campaña como motivo

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · Pedido §34 · C-03 · C-08 · RN-COM-01 a RN-COM-12

### COM-7 · Cuadro de categorías y política salarial

*Historia* · depende de COM-2, DOC-6

Elaborar el cuadro de categorías y funciones (categoría, puestos, funciones, responsabilidad, requisitos), registrar la política salarial y dejar evidencia de su comunicación al ingreso, al cambio de puesto y al cambio de remuneración. El cuadro se construye a partir del catálogo de puestos.

- [ ] Todo puesto pertenece a una categoría del cuadro
- [ ] La comunicación de la política queda firmada por el trabajador
- [ ] El cuadro se exporta en el formato para inspección

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-05 · RN-COM-01 a RN-COM-12 · Prototipo: Compensación → Igualdad salarial

### COM-8 · Reporte de brecha salarial

*Historia* · depende de COM-7, COM-3

Reportar la brecha por categoría y sexo, con los criterios objetivos que explican diferencias (experiencia, formación, antigüedad, desempeño) y registro de justificaciones y planes de corrección. El reporte se guarda por fecha de corte para comparar su evolución.

- [ ] La brecha se calcula por categoría del cuadro
- [ ] Los grupos pequeños se agregan para no exponer sueldos individuales

Referencias: docs/modulos/M-COM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-05 · RN-COM-01 a RN-COM-12

## BNF · Beneficios

Catálogo de beneficios con elegibilidad, inscripción y consulta por el colaborador.

*Componente: Backend + Frontend*

### BNF-1 · Catálogo de beneficios

*Historia* · depende de CFG-7

Administrar beneficios (seguro, alimentación, movilidad, educación, descuentos, bienestar y otros) con proveedor, costo para la empresa y para el trabajador, y vigencia. El catálogo se define por empresa.

- [ ] Un beneficio puede tener planes o niveles
- [ ] El costo para la empresa exige permiso para verse

Referencias: docs/modulos/M-BNF.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §35 · RN-BNF-01 a RN-BNF-06 · Prototipo: Compensación → Beneficios

### BNF-2 · Reglas de elegibilidad

*Historia* · depende de BNF-1, HIS-6

Definir quién accede a cada beneficio por empresa, sede, puesto, nivel, antigüedad o tipo de trabajador. Las reglas se evalúan de forma automática al cambiar los datos del colaborador.

- [ ] La elegibilidad se evalúa con los datos vigentes de la ficha
- [ ] Un cambio de puesto actualiza los beneficios disponibles

Referencias: docs/modulos/M-BNF.md · docs/06-reglas-de-negocio.md · Pedido §35 · RN-BNF-01 a RN-BNF-06

### BNF-3 · Inscripción y dependientes

*Historia* · depende de BNF-2, COL-6

Permitir la inscripción del colaborador a los beneficios elegibles, con dependientes tomados de sus derechohabientes y aprobación cuando corresponda. La inscripción puede tener ventanas de apertura y cierre.

- [ ] El colaborador solo puede inscribirse en lo que le corresponde
- [ ] Los dependientes se eligen de los derechohabientes registrados

Referencias: docs/modulos/M-BNF.md · docs/06-reglas-de-negocio.md · Pedido §35 · RN-BNF-01 a RN-BNF-06

### BNF-4 · Mis beneficios

*Historia* · depende de BNF-3, ESS-1

Mostrar al colaborador sus beneficios vigentes, los disponibles y cómo usarlos, en el portal y en la pestaña de beneficios de la ficha. La vista distingue beneficios legales de beneficios otorgados por la empresa.

- [ ] El colaborador consulta sus beneficios desde el móvil
- [ ] Se muestra el valor del beneficio cuando la política lo permite

Referencias: docs/modulos/M-BNF.md · docs/06-reglas-de-negocio.md · Pedido §35 · Pedido §8 · Pedido §6 · RN-BNF-01 a RN-BNF-06

### BNF-5 · Beneficios con efecto en planilla

*Tarea técnica* · Backend · depende de BNF-3, PER-2

Generar las incidencias de planilla de los beneficios con descuento o aporte, y reportar el costo total de beneficios. Las incidencias llegan al periodo abierto con su origen identificado.

- [ ] Un beneficio con copago genera su descuento en el periodo
- [ ] El costo de beneficios se reporta por unidad

Referencias: docs/modulos/M-BNF.md · docs/06-reglas-de-negocio.md · Pedido §35 · C-11 · RN-BNF-01 a RN-BNF-06

# Fase 6 · Experiencia

## ENC · Encuestas, clima y eNPS

Constructor de encuestas, anonimato real, eNPS, mapa de calor y planes de acción.

*Componente: Backend + Frontend*

### ENC-1 · Constructor de encuestas

*Historia* · depende de UI-5

Crear encuestas con tipos de pregunta (escala, opción única y múltiple, texto, eNPS, matriz), secciones, lógica de salto y vista previa. El constructor guarda borradores y admite traducciones de cada pregunta.

- [ ] La vista previa muestra la encuesta como la verá el colaborador en el móvil
- [ ] Una encuesta publicada no cambia sus preguntas: se versiona

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · Pedido §49 · ME-30 · RN-ENC-01 a RN-ENC-10 · Prototipo: Experiencia → Encuestas

### ENC-2 · Tipos y plantillas de encuesta

*Historia* · depende de ENC-1

Ofrecer plantillas de clima, compromiso, onboarding, salida, pulso, eNPS y personalizada, con dimensiones para agrupar resultados. Las dimensiones permiten comparar mediciones sucesivas.

- [ ] Cada plantilla trae sus dimensiones y preguntas sugeridas
- [ ] La organización guarda sus propias plantillas

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · Pedido §49 · RN-ENC-01 a RN-ENC-10

### ENC-3 · Distribución, segmentos y recordatorios

*Historia* · depende de ENC-2, NOT-1

Enviar la encuesta a una población definida por filtros, con fechas, recordatorios, enlace para personal sin correo y seguimiento de participación. La población queda fijada al momento del envío.

- [ ] La participación se ve por segmento sin identificar quién respondió en encuestas anónimas
- [ ] El colaborador responde desde el móvil o desde un kiosko

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · Pedido §49 · Pedido §8 · Pedido §85 · C-09 · RN-ENC-01 a RN-ENC-10

### ENC-4 · Anonimato real con umbral mínimo

*Tarea técnica* · Backend · depende de ENC-3

En encuestas anónimas, guardar las respuestas sin vínculo con la persona (solo los atributos de segmento necesarios) y registrar por separado quién ya respondió. Ningún resultado se muestra para un segmento con menos respuestas que el umbral configurado, ni por combinación de filtros.

- [ ] No existe consulta que relacione una respuesta anónima con una persona
- [ ] Un segmento bajo el umbral se muestra como «insuficiente» y se agrupa con el nivel superior
- [ ] Los comentarios abiertos se muestran solo cuando el segmento supera el umbral

Referencias: docs/modulos/M-ENC.md · docs/04-modelo-de-datos.md · docs/06-reglas-de-negocio.md · C-06 · Pedido §49 · Pedido §50 · RN-ENC-01 a RN-ENC-10

### ENC-5 · eNPS y tendencias

*Historia* · depende de ENC-4

Calcular el eNPS con promotores, pasivos y detractores, su evolución entre mediciones y su comparación por segmento. La pregunta de eNPS puede incluirse en cualquier encuesta.

- [ ] El eNPS se calcula con la fórmula estándar y muestra el número de respuestas
- [ ] La tendencia compara mediciones sucesivas

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · Pedido §50 · RN-ENC-01 a RN-ENC-10 · Prototipo: Experiencia → eNPS

### ENC-6 · Resultados de clima con mapa de calor

*Historia* · depende de ENC-4, UI-12

Presentar resultados por dimensión y pregunta, con mapa de calor por área frente al promedio de la organización, y resumen de comentarios. Los resultados se comparan con la medición anterior.

- [ ] El mapa de calor respeta el umbral de anonimato en cada celda
- [ ] Los resultados se filtran por empresa, sede y unidad

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · E-25 · Pedido §49 · RN-ENC-01 a RN-ENC-10 · Prototipo: Experiencia → Encuestas → Mapa de calor

### ENC-7 · Planes de acción con seguimiento

*Historia* · depende de ENC-6, WFL-8

Crear planes de acción por área a partir de los resultados, con responsable, acciones, fechas y avance, y compararlos con la medición siguiente. Los planes usan el motor de tareas para sus acciones.

- [ ] Cada plan se vincula a la dimensión que busca mejorar
- [ ] El avance de los planes se ve en un tablero por unidad

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · E-25 · RN-ENC-01 a RN-ENC-10

### ENC-8 · Encuestas automáticas por evento

*Tarea técnica* · Backend · depende de ENC-3, WFL-5

Disparar encuestas por eventos del ciclo de vida (hitos de onboarding, salida) y pulsos recurrentes programados. Las reglas se configuran con el motor de automatizaciones.

- [ ] Un nuevo ingreso recibe la encuesta de onboarding en el hito configurado
- [ ] La encuesta de salida se envía al iniciarse el proceso de salida

Referencias: docs/modulos/M-ENC.md · docs/06-reglas-de-negocio.md · Pedido §49 · Pedido §103 · RN-ENC-01 a RN-ENC-10

## REC · Reconocimientos

Reconocimientos entre compañeros ligados a valores, insignias y muro opcional.

*Componente: Backend + Frontend*

### REC-1 · Valores e insignias

*Historia* · depende de CFG-1

Configurar los valores de la empresa y las insignias asociadas, con imagen y descripción. Cada organización define su propio conjunto.

- [ ] Los valores e insignias se activan y desactivan sin perder el historial
- [ ] Cada insignia se asocia a un valor de la empresa

Referencias: docs/modulos/M-REC.md · docs/06-reglas-de-negocio.md · Pedido §51 · RN-REC-01 a RN-REC-06 · Prototipo: Experiencia → Reconocimientos

### REC-2 · Reconocer a un compañero

*Historia* · depende de REC-1, NOT-1

Permitir reconocer a una o varias personas con valor, mensaje e insignia, con aviso al reconocido y a su jefe. El reconocimiento puede ser público o privado.

- [ ] El reconocimiento aparece en la ficha del reconocido
- [ ] Nadie puede reconocerse a sí mismo

Referencias: docs/modulos/M-REC.md · docs/06-reglas-de-negocio.md · Pedido §51 · Pedido §6 · RN-REC-01 a RN-REC-06 · Prototipo: Experiencia → Reconocimientos

### REC-3 · Muro de reconocimientos

*Historia* · depende de REC-2

Mostrar el muro opcional con los reconocimientos públicos, reacciones y comentarios, y moderación por RRHH. El muro respeta la segmentación por empresa cuando se configura.

- [ ] La organización puede desactivar el muro y mantener los reconocimientos privados
- [ ] RRHH puede ocultar una publicación con registro del motivo

Referencias: docs/modulos/M-REC.md · docs/06-reglas-de-negocio.md · Pedido §51 · RN-REC-01 a RN-REC-06

### REC-4 · Reportes de reconocimiento

*Historia* · depende de REC-2

Reportar reconocimientos por valor, unidad y periodo, y quiénes reconocen y son reconocidos. Los datos se exponen también a la capa analítica.

- [ ] El reporte muestra las unidades sin reconocimientos en el periodo
- [ ] El reporte se filtra por empresa y sede y se exporta

Referencias: docs/modulos/M-REC.md · docs/06-reglas-de-negocio.md · Pedido §51 · Pedido §64 · RN-REC-01 a RN-REC-06

## CIN · Comunicación interna

Noticias, comunicados y eventos segmentados con confirmación de lectura.

*Componente: Backend + Frontend*

### CIN-1 · Publicaciones

*Historia* · depende de PLT-7, UI-5

Publicar noticias, comunicados y eventos con texto enriquecido, imágenes, adjuntos, programación y vigencia. El editor permite guardar borradores y pedir revisión antes de publicar.

- [ ] Una publicación programada aparece sola en su fecha
- [ ] Las publicaciones vencidas pasan al archivo

Referencias: docs/modulos/M-CIN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §52 · RN-CIN-01 a RN-CIN-07 · Prototipo: Experiencia → Comunicaciones

### CIN-2 · Segmentación de la audiencia

*Historia* · depende de CIN-1, NOT-1

Dirigir cada publicación por empresa, sede, área, equipo o grupo, y enviarla por los canales elegidos. La audiencia se calcula con los datos vigentes de cada colaborador.

- [ ] Un colaborador solo ve lo dirigido a su segmento
- [ ] El autor ve el tamaño de la audiencia antes de publicar

Referencias: docs/modulos/M-CIN.md · docs/06-reglas-de-negocio.md · Pedido §52 · RN-CIN-01 a RN-CIN-07

### CIN-3 · Confirmación de lectura

*Historia* · depende de CIN-2, WFL-9

Exigir confirmación de lectura en los comunicados que lo requieren y dar seguimiento de quién confirmó y quién falta, con recordatorios. La confirmación queda registrada con fecha y medio.

- [ ] El comunicado obligatorio aparece en pendientes hasta confirmarse
- [ ] El reporte de lectura se exporta como evidencia

Referencias: docs/modulos/M-CIN.md · docs/06-reglas-de-negocio.md · Pedido §52 · Pedido §87 · RN-CIN-01 a RN-CIN-07

### CIN-4 · Cumpleaños, aniversarios y eventos

*Historia* · depende de CIN-1, CFG-8

Publicar de forma automática cumpleaños y aniversarios laborales según la política de privacidad, y gestionar eventos con inscripción. Las publicaciones automáticas usan plantillas editables.

- [ ] Un colaborador puede optar por no mostrar su cumpleaños
- [ ] Los eventos aparecen en el calendario de RRHH

Referencias: docs/modulos/M-CIN.md · docs/06-reglas-de-negocio.md · Pedido §52 · Pedido §78 · RN-CIN-01 a RN-CIN-07

## HSD · Mesa de ayuda y base de conocimiento

Solicitudes a RRHH con colas, SLA y base de conocimiento vinculada.

*Componente: Backend + Frontend*

### HSD-1 · Catálogo de solicitudes y formularios

*Historia* · depende de ESS-5, CFG-4

Configurar los tipos de solicitud (certificado, planilla, vacaciones, beneficios, documentos, consulta, otro) con su formulario, cola responsable, prioridad y confidencialidad. Los formularios admiten campos personalizados.

- [ ] Cada tipo define a qué cola llega y qué datos pide
- [ ] Las solicitudes del portal del colaborador pasan a gestionarse aquí

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §53 · RN-HSD-01 a RN-HSD-10 · Prototipo: Experiencia → Mesa de ayuda

### HSD-2 · Tickets y estados

*Historia* · depende de HSD-1

Gestionar cada solicitud como ticket con estados nuevo, en atención, esperando información, resuelto y cerrado, conversación con el colaborador, notas internas y adjuntos. Cada ticket conserva su historial completo de cambios.

- [ ] El colaborador ve el estado y responde desde su portal
- [ ] Las notas internas no son visibles para el solicitante
- [ ] Un ticket resuelto se cierra solo tras el plazo configurado sin respuesta

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §53 · Pedido §8 · ME-31 · RN-HSD-01 a RN-HSD-10 · Prototipo: Experiencia → Mesa de ayuda

### HSD-3 · SLA configurable

*Historia* · depende de HSD-2, CFG-8

Definir tiempos objetivo de primera respuesta y de resolución por tipo y prioridad, en horario hábil de la empresa, con pausas cuando se espera al solicitante y escalamiento al acercarse el vencimiento. El horario hábil y los feriados se toman del calendario de la empresa.

- [ ] El reloj del SLA se detiene en «esperando información»
- [ ] Un ticket por vencer avisa al responsable y a su supervisor

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · Pedido §53 · RN-HSD-01 a RN-HSD-10

### HSD-4 · Colas, asignación y respuestas tipo

*Historia* · depende de HSD-2

Organizar la atención en colas con asignación manual o por turno rotativo, respuestas predefinidas y vista de carga por agente. La asignación puede reasignarse y queda registrada.

- [ ] Un agente solo ve las colas a las que pertenece
- [ ] Los tickets confidenciales solo los ve la cola autorizada

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · Pedido §53 · docs/07-permisos-y-roles.md · RN-HSD-01 a RN-HSD-10

### HSD-5 · Base de conocimiento

*Historia* · depende de AYU-4, CIN-2

Ampliar los artículos de la organización del centro de ayuda (AYU-4) con categorías y preguntas frecuentes de la mesa de ayuda, sin crear un segundo repositorio. El editor es el mismo de las comunicaciones internas.

- [ ] Un artículo solo lo ve la audiencia a la que va dirigido
- [ ] Los artículos de la mesa de ayuda aparecen también en el centro de ayuda y en el asistente

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · Pedido §54 · RN-HSD-01 a RN-HSD-10 · Prototipo: Experiencia → Mesa de ayuda

### HSD-6 · Buscador y artículos sugeridos

*Historia* · depende de HSD-5, HSD-2

Ofrecer el buscador de la base de conocimiento, sugerir artículos al crear una solicitud y permitir enlazar artículos a tickets. Las sugerencias consideran solo los artículos visibles para el solicitante.

- [ ] Al escribir el asunto se sugieren artículos relacionados
- [ ] Un agente responde insertando un artículo

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · Pedido §54 · RN-HSD-01 a RN-HSD-10

### HSD-7 · Satisfacción y reportes de la mesa

*Historia* · depende de HSD-3

Medir satisfacción al cierre y reportar volumen, tiempos, cumplimiento de SLA y motivos frecuentes. Los artículos más consultados y los motivos sin artículo orientan qué documentar.

- [ ] La encuesta de satisfacción se envía al cerrar el ticket
- [ ] El reporte muestra cumplimiento de SLA por tipo y cola

Referencias: docs/modulos/M-HSD.md · docs/06-reglas-de-negocio.md · Pedido §53 · Pedido §64 · RN-HSD-01 a RN-HSD-10

# Fase 7 · Operación y cumplimiento

## SST · Seguridad y salud en el trabajo

Accidentes, riesgos, inspecciones, salud ocupacional, comité y registros obligatorios.

*Componente: Backend + Frontend*

### SST-1 · Accidentes, incidentes y enfermedades ocupacionales

*Historia* · depende de COL-3, PLT-7

Registrar accidentes de trabajo, incidentes peligrosos, incidentes y enfermedades ocupacionales con los datos del formato referencial, investigación, causas y días de descanso. Los plazos de notificación a la autoridad son parámetros y generan alerta.

- [ ] El registro contiene los campos de los formatos de la RM 050-2013-TR
- [ ] Un accidente abre su investigación con responsable
- [ ] El reporte desde el móvil admite fotos

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · docs/14-localizacion-peru.md · Pedido §57 · ME-35 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Accidentes e incidentes

### SST-2 · Matriz de riesgos IPERC

*Historia* · depende de ORG-2, ORG-5

Gestionar la identificación de peligros, evaluación de riesgos y controles por puesto, sede y actividad, con nivel de riesgo calculado y versiones. La matriz se relaciona con los puestos y con el EPP requerido.

- [ ] El nivel de riesgo se calcula con la metodología configurada
- [ ] La matriz se versiona y conserva las anteriores

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §57 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Matriz IPERC

### SST-3 · Inspecciones y observaciones

*Historia* · depende de SST-2, UI-9

Programar y ejecutar inspecciones con listas de verificación desde el móvil, registrar observaciones con foto y generar hallazgos. Las listas de verificación son plantillas configurables por tipo de inspección.

- [ ] Una inspección se completa sin conexión y se sincroniza después
- [ ] Cada hallazgo queda vinculado a su inspección

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §57 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Registros obligatorios

### SST-4 · Medidas correctivas

*Historia* · depende de SST-1, SST-3, WFL-8

Dar seguimiento a las medidas correctivas y preventivas originadas en accidentes, inspecciones y auditorías, con responsable, plazo, evidencia de cierre y verificación de eficacia. Las medidas usan el motor de tareas.

- [ ] Una medida no se cierra sin evidencia
- [ ] Las medidas vencidas se escalan

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §57 · Pedido §77 · ME-36 · RN-SST-01 a RN-SST-12

### SST-5 · Exámenes médicos ocupacionales

*Historia* · depende de SST-2, SEG-11

Programar exámenes de ingreso, periódicos y de retiro según el protocolo por puesto, y registrar la aptitud y sus restricciones. El detalle clínico no se almacena salvo configuración expresa y con permiso propio.

- [ ] RRHH ve solo la aptitud y las restricciones; el detalle es dato sensible del médico ocupacional
- [ ] Los exámenes por vencer se listan por sede
- [ ] Cada consulta del resultado queda registrada

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/07-permisos-y-roles.md · Pedido §57 · Pedido §105 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Registros obligatorios

### SST-6 · Capacitaciones y simulacros de SST

*Historia* · depende de SST-1, LMS-5

Planificar las capacitaciones obligatorias y los simulacros, registrar asistencia y evaluar, apoyándose en el módulo de aprendizaje cuando está activo. El plan anual de SST se registra con su cumplimiento.

- [ ] El registro muestra las capacitaciones del año por trabajador
- [ ] Quien no completó una capacitación obligatoria aparece en una lista con su jefe

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §57 · Pedido §68 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Registros obligatorios

### SST-7 · Comité o supervisor de SST

*Historia* · depende de ORG-1, WFL-8

Registrar el comité (con 20 o más trabajadores) o el supervisor (con menos), sus miembros con paridad entre empleador y trabajadores, el mandato y las vacancias. Los umbrales se leen de parámetros con vigencia; el proceso electoral y las sesiones tienen historias propias.

- [ ] El sistema indica si corresponde comité o supervisor según la dotación y el parámetro vigente, con comparación mayor o igual
- [ ] Los acuerdos del acta generan tareas con seguimiento

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §57 · RN-SST-01 a RN-SST-12

### SST-8 · Registros obligatorios y plazos de conservación

*Tarea técnica* · Backend · depende de SST-1, SST-3, SST-5, SST-6

Generar los registros obligatorios de SST (accidentes, enfermedades, exámenes, monitoreo, inspecciones, estadísticas, equipos de seguridad, capacitaciones y simulacros, auditorías) en su formato, incluida la versión simplificada para MYPE, con el plazo de conservación de cada uno como política de retención. Los registros se arman con los datos operativos del módulo, sin doble digitación.

- [ ] Cada registro se exporta en su formato por periodo
- [ ] El plazo de conservación de cada registro es un parámetro y bloquea su eliminación

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §57 · Pedido §91 · RN-SST-01 a RN-SST-12

### SST-9 · Tablero y estadísticas de SST

*Historia* · depende de SST-4, SST-8

Mostrar el tablero de SST con índices de frecuencia, gravedad y accidentabilidad, días sin accidentes, medidas pendientes y vencimientos de documentos del sistema de gestión. Los índices se calculan por sede y por periodo.

- [ ] Los índices se calculan con las horas trabajadas reales
- [ ] Los documentos y vencimientos de SST se ven en una sola lista

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · Pedido §57 · RN-SST-01 a RN-SST-12 · Prototipo: SST → Tablero SST

### SST-10 · Proceso electoral del comité de SST

*Historia* · depende de SST-7, CUM-8

Conducir la elección de los representantes de los trabajadores: comunicación, convocatoria por el sindicato mayoritario o el empleador, junta electoral, inscripción y publicación de candidatos, votación secreta presencial o electrónica, escrutinio, proclamación e instalación. El plazo de cada etapa es un parámetro con vigencia.

- [ ] El voto guarda quién votó y el contador por candidato, sin vínculo entre ambos
- [ ] Un integrante de la junta no puede ser candidato
- [ ] Cada etapa deja un asiento en el libro inalterable

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · ME-46 · Pedido §57 · RN-SST-01 a RN-SST-20

### SST-11 · Sesiones, quórum y libro de actas

*Historia* · depende de SST-7, CUM-8, DOC-6

Programar una sesión ordinaria por mes, registrar asistencia y quórum, reprogramar cuando no lo hay, redactar el acta con acuerdos y firmarla; el acta firmada se asienta en el libro inalterable con su huella. Controla vacancia por inasistencias y licencias de los representantes.

- [ ] Sin quórum se crea la sesión reprogramada dentro del plazo del parámetro
- [ ] Un acta firmada no se edita; su huella queda en el libro
- [ ] El mes que cierra sin sesión ordinaria deja la obligación en incumplimiento con explicación

Referencias: docs/modulos/M-SST.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · ME-47 · Pedido §57 · RN-SST-01 a RN-SST-20

## DEN · Canal de denuncias y hostigamiento

Canal confidencial independiente, anonimato real, investigación restringida y procedimiento de hostigamiento sexual.

*Componente: Backend + Frontend*

### DEN-1 · Canal público de denuncias

*Historia* · depende de PLT-7, TEN-1

Publicar el canal de denuncias en una dirección independiente, accesible sin iniciar sesión, con categorías, relato, personas involucradas y adjuntos. La denuncia puede ser identificada o anónima.

- [ ] El canal se usa sin cuenta y desde cualquier dispositivo
- [ ] Los adjuntos se limpian de metadatos antes de guardarse

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §58 · RN-DEN-01 a RN-DEN-13 · Prototipo: Cumplimiento → Canal de denuncias

### DEN-2 · Anonimato sin rastro técnico

*Tarea técnica* · Backend · depende de DEN-1, PLT-10

En denuncias anónimas no registrar usuario, IP, dispositivo ni identificadores en logs técnicos, auditoría o analítica. La trazabilidad del caso se lleva sobre el caso, no sobre el denunciante.

- [ ] Una prueba verifica que una denuncia anónima no deja IP ni usuario en ningún almacén
- [ ] La auditoría del caso registra las acciones de los investigadores

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · C-06 · Pedido §58 · RN-DEN-01 a RN-DEN-13

### DEN-3 · Código de seguimiento y comunicación con el denunciante

*Historia* · depende de DEN-2

Entregar al denunciante un código seguro con el que consulta el estado, responde preguntas del investigador y agrega evidencia, sin identificarse. El código se muestra una sola vez al enviar la denuncia.

- [ ] El código no se puede recuperar ni deducir
- [ ] El denunciante y el investigador intercambian mensajes por el caso

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · Pedido §58 · RN-DEN-01 a RN-DEN-13

### DEN-4 · Gestión del caso

*Historia* · depende de DEN-3, PLT-8

Gestionar cada denuncia con estados (recibida, en evaluación, en investigación, resuelta, archivada), clasificación, bitácora de actuaciones, evidencias y resolución. Las denuncias identificadas protegen la identidad del denunciante frente a los denunciados.

- [ ] Cada cambio de estado tiene responsable y fecha
- [ ] La resolución registra medidas y comunicación a las partes

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §58 · ME-37 · RN-DEN-01 a RN-DEN-13 · Prototipo: Cumplimiento → Casos de denuncia

### DEN-5 · Investigadores y acceso restringido

*Historia* · depende de DEN-4, SEG-11

Limitar el acceso a cada caso a los investigadores asignados, excluir a cualquier persona mencionada en la denuncia y dejar fuera a administradores, soporte y búsqueda global. La asignación la hace un responsable del canal con permiso propio.

- [ ] Un administrador de la empresa no ve los casos si no es investigador asignado
- [ ] Una persona involucrada en el caso no puede ser asignada ni verlo
- [ ] Todo acceso al caso queda registrado

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §58 · Pedido §105 · RN-DEN-01 a RN-DEN-13

### DEN-6 · Procedimiento de hostigamiento sexual

*Historia* · depende de DEN-5, CFG-3

Implementar el procedimiento de la Ley 27942 y su reglamento (docs/18 §5.2): recepción, atención médica y psicológica (1 día hábil), traslado al comité de intervención o al delegado (1 día hábil), medidas de protección (3 días hábiles), informe del comité (15 días calendario) y decisión (10 días calendario), con cada plazo como parámetro y alertas de vencimiento. No existe un plazo total configurable. Los plazos se computan por día: una denuncia no guarda hora.

- [ ] El caso muestra la etapa actual y el plazo restante de cada hito
- [ ] Las medidas de protección se registran con su fecha de otorgamiento y de ejecución
- [ ] Los plazos se leen de parámetros con vigencia y distinguen días hábiles de calendario
- [ ] Un miembro mencionado en la queja es reemplazado antes del traslado

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · docs/05-maquinas-de-estado.md · E-15 · ME-48 · RN-DEN-01 a RN-DEN-13

### DEN-7 · Comité de intervención y capacitación obligatoria

*Historia* · depende de DEN-6, ORG-1

Registrar el comité de intervención o el delegado según la dotación, su conformación y vigencia, y controlar la capacitación de prevención al ingreso y anual. La capacitación se apoya en el módulo de aprendizaje cuando está activo.

- [ ] El sistema indica si corresponde comité o delegado según el parámetro vigente
- [ ] Se lista quién no tiene la capacitación de prevención al día

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-15 · RN-DEN-01 a RN-DEN-13

### DEN-8 · Comunicaciones a la autoridad y expediente del procedimiento

*Historia* · depende de DEN-6, CUM-8

Generar y registrar las dos comunicaciones a la autoridad de trabajo (queja recibida con medidas, y decisión) con su plazo y su cargo de presentación. Cada acto del procedimiento se asienta en el libro inalterable del caso sin identidad del denunciante ni hora.

- [ ] Una comunicación solo se da por cumplida con el cargo adjunto
- [ ] El libro del caso no contiene identidad, IP ni hora
- [ ] Solo los asignados al caso leen sus asientos, y la lectura queda registrada

Referencias: docs/modulos/M-DEN.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · docs/05-maquinas-de-estado.md · E-15 · ME-48 · RN-DEN-01 a RN-DEN-17

## DIS · Casos disciplinarios

Procedimientos disciplinarios confidenciales con descargos, medidas y seguimiento.

*Componente: Backend + Frontend*

### DIS-1 · Registro del caso disciplinario

*Historia* · depende de COL-3, SEG-11

Abrir casos con hechos, fecha, involucrados, tipo de falta según el reglamento interno y evidencias, con acceso por permiso especial. El caso puede originarse en una denuncia resuelta o en un reporte del jefe.

- [ ] El caso solo lo ven los roles autorizados y nunca aparece en búsqueda global para otros
- [ ] Los hechos se vinculan a la norma interna infringida

Referencias: docs/modulos/M-DIS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · Pedido §59 · Pedido §105 · ME-38 · RN-DIS-01 a RN-DIS-09 · Prototipo: Cumplimiento → Casos disciplinarios

### DIS-2 · Imputación y descargos

*Historia* · depende de DIS-1, DOC-6

Generar la carta de imputación desde plantilla, notificarla con constancia y recibir los descargos del trabajador dentro del plazo configurado. La notificación se hace por firma electrónica o por cargo físico digitalizado.

- [ ] El plazo de descargos se controla y avisa antes de vencer
- [ ] Los descargos quedan archivados con su fecha de presentación

Referencias: docs/modulos/M-DIS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §59 · RN-DIS-01 a RN-DIS-09

### DIS-3 · Medidas disciplinarias

*Historia* · depende de DIS-2, AUS-2

Registrar la decisión (amonestación, suspensión, despido u otra) tomada por una persona autorizada, generar el documento y, si corresponde, sus efectos en asistencia y planilla. La IA no interviene en la decisión.

- [ ] La decisión siempre la registra una persona, con motivación
- [ ] Una suspensión sin goce genera la ausencia correspondiente

Referencias: docs/modulos/M-DIS.md · docs/06-reglas-de-negocio.md · Pedido §59 · C-08 · RN-DIS-01 a RN-DIS-09

### DIS-4 · Seguimiento e historial disciplinario

*Historia* · depende de DIS-3

Mostrar el historial disciplinario por colaborador a quien tiene permiso, con reincidencias y vigencia de las sanciones. La vigencia de cada tipo de sanción es un parámetro de política.

- [ ] El historial no es visible para el jefe salvo permiso expreso
- [ ] Las sanciones vencidas se distinguen de las vigentes

Referencias: docs/modulos/M-DIS.md · docs/06-reglas-de-negocio.md · Pedido §59 · docs/07-permisos-y-roles.md · RN-DIS-01 a RN-DIS-09

## ACT · Activos, EPP y uniformes

Inventario y asignación de activos, y entrega de equipos de protección y uniformes con firma.

*Componente: Backend + Frontend*

### ACT-1 · Inventario de activos

*Historia* · depende de ORG-2

Registrar activos (laptop, celular, monitor, tarjeta, equipo, licencia de software y otros) con tipo, serie, valor, ubicación y estado: disponible, asignado, en mantenimiento, perdido y de baja. Las licencias de software se gestionan como activos con fecha de vencimiento.

- [ ] Cada activo tiene un identificador único y código para etiqueta
- [ ] El cambio de estado sigue su máquina de estados

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/05-maquinas-de-estado.md · Pedido §37 · ME-34 · RN-ACT-01 a RN-ACT-08 · Prototipo: Operaciones → Activos

### ACT-2 · Asignación y devolución con acta firmada

*Historia* · depende de ACT-1, DOC-6

Asignar activos a colaboradores con acta de entrega firmada, y registrar la devolución con estado del bien y acta. Las actas se generan desde plantilla y se firman con el flujo de firma.

- [ ] La pestaña de activos de la ficha muestra lo que tiene asignado cada persona
- [ ] Un activo asignado no puede asignarse a otra persona

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · Pedido §37 · Pedido §6 · RN-ACT-01 a RN-ACT-08

### ACT-3 · Historial y activos en ingresos y salidas

*Historia* · depende de ACT-2, OFF-2

Mostrar el historial completo de cada activo y conectar con onboarding (solicitud de equipos) y offboarding (devolución pendiente). La integración se hace mediante tareas de los procesos de ingreso y salida.

- [ ] El proceso de salida lista los activos por devolver
- [ ] El historial muestra todos los responsables anteriores

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · Pedido §37 · Pedido §17 · RN-ACT-01 a RN-ACT-08

### ACT-4 · Matriz de EPP por puesto

*Historia* · depende de SST-2, ACT-1

Definir los equipos de protección requeridos por puesto y actividad según la matriz de riesgos, con vida útil. La matriz se revisa cuando cambia la matriz de riesgos.

- [ ] Cada puesto con riesgo tiene su lista de EPP requerido
- [ ] La vida útil se define por tipo de equipo

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-12 · Pedido §57 · RN-ACT-01 a RN-ACT-08

### ACT-5 · Entrega de EPP con firma y vencimientos

*Historia* · depende de ACT-4, DOC-7

Registrar la entrega de EPP con firma del trabajador, controlar existencias y alertar la reposición por vencimiento de vida útil. Alimenta el registro obligatorio de equipos de seguridad.

- [ ] La entrega queda firmada desde el móvil o el kiosko
- [ ] El EPP por vencer se lista por trabajador y sede

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-12 · RN-ACT-01 a RN-ACT-08

### ACT-6 · Uniformes y tallas

*Historia* · depende de ACT-5

Registrar las tallas de cada colaborador y las entregas de uniformes con periodicidad por política. Las tallas se actualizan desde el portal del colaborador.

- [ ] El pedido de uniformes se arma con las tallas registradas
- [ ] La siguiente entrega se programa según la política

Referencias: docs/modulos/M-ACT.md · docs/06-reglas-de-negocio.md · E-12 · Pedido §37 · RN-ACT-01 a RN-ACT-08

## GAS · Gastos, viáticos y reembolsos

Registro de gastos con comprobante, políticas por puesto, viáticos, rendiciones y reembolso.

*Componente: Backend + Frontend*

### GAS-1 · Registro de gastos

*Historia* · depende de ESS-1, ORG-4, CFG-7

Permitir que el colaborador registre un gasto desde el móvil con foto del comprobante, categoría, centro de costo, moneda y proyecto. El registro admite varios gastos agrupados en un informe.

- [ ] El comprobante es obligatorio según la categoría
- [ ] Un comprobante duplicado se detecta por emisor, serie y número

Referencias: docs/modulos/M-GAS.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · Pedido §36 · Pedido §8 · RN-GAS-01 a RN-GAS-10 · Prototipo: Operaciones → Gastos

### GAS-2 · Categorías y políticas por puesto

*Historia* · depende de GAS-1, HIS-6

Configurar categorías de gasto y políticas con topes por categoría, puesto, nivel y destino, y validar cada gasto contra la política. Las políticas tienen vigencia y moneda.

- [ ] Un gasto que excede el tope se marca y exige justificación
- [ ] La política aplicable se determina con el puesto vigente

Referencias: docs/modulos/M-GAS.md · docs/06-reglas-de-negocio.md · E-23 · RN-GAS-01 a RN-GAS-10

### GAS-3 · Aprobación de gastos

*Historia* · depende de GAS-2, WFL-2

Enviar los gastos por el flujo empleado → manager → finanzas, con devolución para corrección. El flujo usa el motor de aprobaciones y admite montos límite por aprobador.

- [ ] Finanzas ve los gastos aprobados por el manager con sus alertas de política
- [ ] El colaborador sigue el estado de cada gasto

Referencias: docs/modulos/M-GAS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · Pedido §36 · Pedido §56 · ME-32 · RN-GAS-01 a RN-GAS-10

### GAS-4 · Solicitud de viáticos y anticipo

*Historia* · depende de GAS-3

Solicitar viáticos para un viaje con destino, fechas, motivo y monto calculado según la política, y entregar el anticipo. La solicitud pasa por el flujo de aprobación antes de la entrega.

- [ ] El monto sugerido sale de la política por destino y nivel
- [ ] El anticipo queda como saldo por rendir del colaborador

Referencias: docs/modulos/M-GAS.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · E-23 · ME-33 · RN-GAS-01 a RN-GAS-10

### GAS-5 · Rendición de viáticos

*Historia* · depende de GAS-4, PRE-3

Rendir el anticipo con los gastos del viaje, calcular el saldo a favor o por devolver y controlar el plazo de rendición. La rendición reutiliza el registro de gastos.

- [ ] Una rendición fuera de plazo avisa al colaborador y a su jefe
- [ ] El saldo por devolver puede descontarse por planilla con autorización

Referencias: docs/modulos/M-GAS.md · docs/05-maquinas-de-estado.md · docs/06-reglas-de-negocio.md · E-23 · ME-33 · RN-GAS-01 a RN-GAS-10

### GAS-6 · Reembolso y contabilización

*Historia* · depende de GAS-3, PRV-3

Generar el lote de reembolso de gastos aprobados, marcar pagados y generar el asiento por centro de costo. El lote usa los archivos bancarios configurados.

- [ ] El gasto pasa a pagado al confirmarse el lote
- [ ] El asiento se exporta con el mismo mecanismo de la planilla

Referencias: docs/modulos/M-GAS.md · docs/06-reglas-de-negocio.md · Pedido §36 · Pedido §75 · RN-GAS-01 a RN-GAS-10

## CUM · Cumplimiento Perú y SUNAFIL

Tablero de obligaciones laborales por empresa, pólizas, expediente de inspección y casilla electrónica.

*Componente: Backend + Frontend*

### CUM-1 · Catálogo de obligaciones laborales

*Tarea técnica* · Datos · depende de CFG-3, ORG-1

Cargar el catálogo de obligaciones del Perú con su condición de aplicación (umbral de trabajadores, tipo de actividad), periodicidad, norma y evidencia esperada: comité o supervisor de SST, comité de hostigamiento, política salarial, Vida Ley, SCTR, cuota de discapacidad, lactario, T-Registro y registros de SST. El catálogo forma parte del paquete Perú y se actualiza con los cambios normativos.

- [ ] Cada obligación indica la norma y la condición que la hace exigible
- [ ] Los umbrales son parámetros con vigencia

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-01 · RN-CUM-01 a RN-CUM-11

### CUM-2 · Tablero de cumplimiento con semáforo

*Historia* · depende de CUM-1, CUM-9, SST-7, DEN-7, COM-7

Evaluar por empresa qué obligaciones aplican y su estado (al día, por vencer, incumplida, no aplica) con datos del propio sistema cuando existen, y mostrarlo en un tablero con semáforo, responsable y evidencia. Donde el sistema no tiene datos, la evaluación se basa en la evidencia cargada.

- [ ] Cada semáforo explica por qué está en ese estado
- [ ] El estado se recalcula cuando cambian la dotación o las evidencias

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · E-01 · RN-CUM-01 a RN-CUM-11 · Prototipo: Cumplimiento → Cumplimiento Perú → Tablero

### CUM-3 · Responsables, evidencias y calendario

*Historia* · depende de CUM-2, CFG-8

Asignar responsable a cada obligación, adjuntar evidencias con vigencia y generar el calendario de vencimientos con recordatorios. Los recordatorios usan el motor de notificaciones.

- [ ] Una evidencia vencida devuelve la obligación a estado pendiente
- [ ] Los vencimientos aparecen en el calendario de RRHH

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · E-01 · Pedido §78 · RN-CUM-01 a RN-CUM-11

### CUM-4 · Pólizas de Vida Ley y SCTR

*Historia* · depende de CUM-1, COL-7

Registrar pólizas de Vida Ley y de SCTR (salud y pensión) con aseguradora, vigencia y trabajadores cubiertos por periodo, y detectar trabajadores sin cobertura. Las pólizas se registran por empresa y sus vencimientos generan alerta.

- [ ] Un ingreso sin Vida Ley registrada aparece como pendiente
- [ ] Los trabajadores de puestos o sedes de riesgo sin SCTR se listan

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-19 · E-01 · RN-CUM-01 a RN-CUM-11

### CUM-5 · Cuota de discapacidad y lactario

*Historia* · depende de CUM-2, COL-4

Calcular la cuota de empleo de personas con discapacidad sobre el promedio anual, registrar convocatorias y ajustes razonables, y contar por sede el indicador que hace exigible el lactario con registro de su implementación. Ambos indicadores usan parámetros con vigencia para sus umbrales.

- [ ] El cálculo de la cuota usa solo datos registrados con consentimiento y los muestra agregados
- [ ] El indicador de lactario se calcula por centro de trabajo

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-01 · E-16 · RN-CUM-01 a RN-CUM-11

### CUM-6 · Expediente de inspección SUNAFIL

*Historia* · depende de BOL-3, ASI-10, CON-2, SST-8

Armar por trabajador o grupo y por periodo el expediente con boletas y constancias de entrega, registro de asistencia, contratos, documentos de SST y constancias de pago, con índice, en un paquete descargable. La generación corre en segundo plano y respeta los permisos por campo de quien la solicita.

- [ ] El expediente se genera para un trabajador y un rango de periodos en una sola acción
- [ ] El índice lista cada documento con su fecha
- [ ] La generación queda registrada con autor y contenido

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-02 · RN-CUM-01 a RN-CUM-11 · Prototipo: Cumplimiento → Cumplimiento Perú → Expediente de inspección

### CUM-7 · Bandeja de casilla electrónica

*Historia* · depende de CUM-3

Registrar las notificaciones recibidas en la casilla electrónica de SUNAFIL con el instante de su depósito, plazo de respuesta, responsable, documentos y estado, con alertas previas al vencimiento. Los efectos y el vencimiento se calculan desde el depósito, en días hábiles con el calendario de la empresa. La revisión diaria de la casilla por razón social se registra como evidencia de diligencia.

- [ ] Cada requerimiento muestra su plazo restante, contado desde el día hábil siguiente a los efectos de la notificación
- [ ] La respuesta enviada se archiva con su cargo
- [ ] Cada día hábil sin revisión registrada alerta al responsable

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · E-02 · ME-45 · RN-CUM-01 a RN-CUM-14

### CUM-8 · Libro inalterable de cumplimiento encadenado por huella

*Tarea técnica* · Backend · depende de AUD-1

Implementar el libro de solo inserción en el que cada fila guarda la huella de la anterior de su cadena, con contenido canónico, verificación de la cadena y ancla periódica con sello de tiempo detrás de un adaptador. El rol de aplicación solo tiene lectura e inserción.

- [ ] Actualizar, borrar o vaciar el libro falla
- [ ] Una fila alterada por fuera de la aplicación se detecta al verificar, con la fila y el motivo
- [ ] Un asiento anónimo no admite usuario ni firma
- [ ] El expediente se exporta con un manifiesto de huellas verificable sin acceso al sistema, y la exportación queda asentada

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · E-01 · E-02 · RN-CUM-01 a RN-CUM-14

### CUM-9 · Tablero mínimo de obligaciones de planilla

*Historia* · MVP · depende de DEC-3, DEC-4, BSO-2, CON-4, NOT-2

Mostrar a RRHH, por empresa, un tablero con los vencimientos que salen de los datos de planilla y Core HR: altas, bajas y modificaciones del T-Registro, presentación de PLAME y AFPnet, depósitos de CTS, pago de gratificaciones, contratos por vencer y el umbral de trabajadores que obliga a comité de SST. Cada vencimiento tiene responsable, estado y aviso previo configurable. Es el primer paso del tablero de cumplimiento completo (CUM-2), que lo amplía con el catálogo de obligaciones.

- [ ] Cada vencimiento se calcula desde un parámetro legal con vigencia, nunca desde una fecha fija
- [ ] Un vencimiento cumplido muestra la evidencia (archivo generado, constancia o depósito)
- [ ] El responsable recibe aviso antes del vencimiento y al vencer sin cumplir
- [ ] El tablero funciona sin los módulos de SST ni denuncias contratados

Referencias: docs/modulos/M-CUM.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · E-01 · RN-CUM-01 a RN-CUM-14 · Prototipo: Cumplimiento → Cumplimiento Perú → Tablero

## PRI · Privacidad y retención

Consentimientos, derechos del titular, banco de datos, incidentes, evaluación de impacto y retención.

*Componente: Backend + Frontend*

### PRI-1 · Consentimientos y finalidades

*Historia* · depende de COL-1, DOC-7

Registrar las finalidades de tratamiento y los consentimientos de colaboradores y candidatos, con versión del texto, fecha, medio y revocación. Los textos de consentimiento se gestionan como plantillas versionadas.

- [ ] Cada consentimiento guarda el texto aceptado y su versión
- [ ] La revocación detiene el tratamiento asociado y queda registrada

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-16 · Pedido §92 · RN-PRI-01 a RN-PRI-11

### PRI-2 · Solicitudes de derechos del titular

*Historia* · depende de PRI-1, WFL-8

Atender solicitudes de acceso, rectificación, cancelación, oposición y portabilidad con plazo de respuesta controlado, y generar la exportación de los datos de la persona. El titular presenta la solicitud desde su portal o por un formulario público con verificación de identidad.

- [ ] Cada solicitud muestra su plazo legal y avisa antes de vencer
- [ ] La exportación de datos de una persona se genera en formato estructurado

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · docs/05-maquinas-de-estado.md · docs/14-localizacion-peru.md · E-16 · Pedido §92 · Pedido §91 · ME-39 · RN-PRI-01 a RN-PRI-11

### PRI-3 · Banco de datos y registro de tratamientos

*Historia* · depende de PRI-1

Mantener el inventario de bancos de datos y tratamientos con finalidad, categorías de datos, encargados, transferencias y medidas de seguridad, y el dato del oficial de datos personales. El inventario se mantiene por organización y por empresa.

- [ ] El inventario se exporta para la inscripción y actualización del banco de datos
- [ ] Cada integración con datos personales figura como encargo o transferencia

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-16 · RN-PRI-01 a RN-PRI-11

### PRI-4 · Registro de incidentes de seguridad

*Historia* · depende de PRI-3

Registrar incidentes de seguridad de datos personales con su evaluación, afectados, medidas y la notificación a la autoridad dentro del plazo de 48 horas, con cuenta regresiva y alertas. El plazo es un parámetro por jurisdicción.

- [ ] El incidente muestra el tiempo restante para notificar
- [ ] La notificación realizada se archiva con su constancia

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-16 · RN-PRI-01 a RN-PRI-11

### PRI-5 · Evaluación de impacto

*Historia* · depende de PRI-3, ASI-3

Documentar la evaluación de impacto para tratamientos de riesgo (biometría, perfilado con IA) con plantilla, riesgos, medidas y aprobación, como requisito para activar esas funciones. La plantilla sigue el contenido que pide el reglamento.

- [ ] La marcación facial no se activa sin una evaluación de impacto aprobada
- [ ] La evaluación se versiona y tiene responsable

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · E-16 · C-08 · RN-PRI-01 a RN-PRI-11

### PRI-6 · Políticas de retención, anonimización y eliminación

*Tarea técnica* · Backend · depende de PRI-1, CFG-9

Aplicar políticas de retención por tipo de dato, organización y jurisdicción: al vencer, anonimizar o eliminar con un proceso programado que respeta los plazos legales de conservación y los bloqueos por litigio. Las políticas se definen sobre un catálogo de tipos de dato que cada módulo declara.

- [ ] Los datos de candidatos no contratados se anonimizan al vencer el plazo consentido
- [ ] Un bloqueo por litigio suspende la eliminación
- [ ] Cada ejecución deja un informe de lo anonimizado y eliminado

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · Pedido §91 · Pedido §92 · E-16 · RN-PRI-01 a RN-PRI-11

### PRI-7 · Registro de accesos y descargas de datos personales

*Historia* · depende de SEG-11, AUD-3

Dar al responsable de privacidad la consulta de quién accedió o descargó datos personales sensibles y cuándo, y las solicitudes de información atendidas. La consulta se apoya en el registro de accesos de la plataforma.

- [ ] Se obtiene el historial de accesos a los datos de una persona
- [ ] El registro no puede modificarse

Referencias: docs/modulos/M-PRI.md · docs/06-reglas-de-negocio.md · Pedido §92 · Pedido §60 · RN-PRI-01 a RN-PRI-11

## SIN · Sindicatos y convenios

Afiliación sindical, cuota, licencias sindicales y cláusulas de convenio como conceptos.

*Componente: Backend + Frontend*

### SIN-1 · Sindicatos y afiliación

*Historia* · depende de COL-3, SEG-9

Registrar sindicatos, su junta directiva y la afiliación de trabajadores con fechas de alta y baja. La afiliación es dato sensible con permiso por campo.

- [ ] La afiliación solo la ven los roles con el permiso específico
- [ ] La afiliación no aparece en reportes ni exportaciones generales

Referencias: docs/modulos/M-SIN.md · docs/06-reglas-de-negocio.md · docs/04-modelo-de-datos.md · docs/07-permisos-y-roles.md · E-17 · RN-SIN-01 a RN-SIN-07

### SIN-2 · Cuota sindical en planilla

*Tarea técnica* · Backend · depende de SIN-1, PER-2

Descontar la cuota sindical autorizada por el trabajador como concepto de planilla y generar la relación de aportes para el sindicato. El concepto forma parte del paquete Perú.

- [ ] El descuento solo se aplica con autorización registrada
- [ ] La relación de aportes por periodo se exporta

Referencias: docs/modulos/M-SIN.md · docs/06-reglas-de-negocio.md · E-17 · docs/14-localizacion-peru.md · RN-SIN-01 a RN-SIN-07

### SIN-3 · Licencias sindicales

*Historia* · depende de SIN-1, AUS-1

Registrar las licencias sindicales de los dirigentes con el tope de días por año que fija la norma o el convenio, como tipo de ausencia con saldo. El tope es un parámetro que el convenio puede mejorar.

- [ ] El saldo de licencia sindical se controla por dirigente y año
- [ ] La licencia aparece como ausencia justificada

Referencias: docs/modulos/M-SIN.md · docs/06-reglas-de-negocio.md · E-17 · docs/14-localizacion-peru.md · RN-SIN-01 a RN-SIN-07

### SIN-4 · Convenios colectivos y cláusulas

*Historia* · depende de SIN-1, CPT-1

Registrar convenios con vigencia, ámbito y cláusulas, y convertir las cláusulas económicas (bonos, asignaciones, incrementos) en conceptos de planilla aplicables a los trabajadores comprendidos. El ámbito se define por sindicato, empresa y grupo de trabajadores.

- [ ] Una cláusula económica se calcula solo para los trabajadores del ámbito del convenio
- [ ] El vencimiento del convenio avisa con anticipación configurable

Referencias: docs/modulos/M-SIN.md · docs/06-reglas-de-negocio.md · E-17 · C-05 · RN-SIN-01 a RN-SIN-07

# Fase 8 · Inteligencia

## ANA · People analytics y tableros

Capa analítica con indicadores definidos, tablero ejecutivo y tableros personalizables por rol.

*Componente: Backend + Frontend*

### ANA-1 · Capa de datos analítica

*Tarea técnica* · Datos · depende de TEN-2, HIS-6, PLT-6

Construir la capa analítica que consolida datos de los módulos en tablas de hechos y dimensiones por organización, con actualización incremental y las mismas reglas de aislamiento, alcance y permisos por campo. La carga se alimenta de los eventos de dominio y de procesos programados.

- [ ] Los indicadores se calculan sobre la capa analítica, no sobre las tablas operativas
- [ ] La capa aplica RLS y el alcance del usuario
- [ ] Cada carga registra su hora y resultado

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · docs/04-modelo-de-datos.md · Pedido §64 · Pedido §101 · RN-ANA-01 a RN-ANA-08

### ANA-2 · Catálogo de indicadores

*Tarea técnica* · Backend · depende de ANA-1, PRV-4, ATS-9

Definir cada indicador con fórmula, fuente y dimensiones: dotación, altas, bajas, rotación, ausentismo, tardanzas, horas extra, costo laboral, vacaciones, desempeño, capacitación, compromiso, eNPS, tiempo de cobertura, costo por contratación, vacantes, antigüedad, distribución salarial, promociones, movilidad y SST. Los módulos registran sus indicadores en el catálogo.

- [ ] Cada indicador tiene una definición única visible para el usuario
- [ ] Un indicador de un módulo no contratado no aparece
- [ ] Las fórmulas están cubiertas con pruebas sobre datos conocidos

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · Pedido §64 · C-11 · RN-ANA-01 a RN-ANA-08

### ANA-3 · Tablero ejecutivo

*Historia* · depende de ANA-2, UI-12

Construir el tablero ejecutivo con los indicadores principales, su tendencia y comparación con el periodo anterior, y navegación al detalle. El tablero se calcula sobre la capa analítica.

- [ ] Cada gráfico permite llegar al listado que lo explica, según permisos
- [ ] El tablero carga con rapidez en organizaciones grandes

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · Pedido §64 · RN-ANA-01 a RN-ANA-08 · Prototipo: Analytics → Tableros

### ANA-4 · Filtros analíticos

*Historia* · depende de ANA-3

Aplicar filtros de empresa, sede, área, puesto, fecha y manager a todos los tableros, con comparación entre segmentos. Los filtros disponibles dependen del alcance del usuario.

- [ ] Los filtros se combinan y se guardan como vista
- [ ] El filtro por manager incluye a sus subordinados indirectos

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · Pedido §64 · RN-ANA-01 a RN-ANA-08 · Prototipo: Analytics → People Analytics

### ANA-5 · Tableros personalizables por rol

*Historia* · depende de ANA-3

Permitir armar tableros con widgets que se arrastran y redimensionan, con plantillas distintas para RRHH, dirección, manager y Payroll, y tableros propios por usuario. Cada widget se basa en un indicador del catálogo o en un reporte guardado.

- [ ] Cada rol tiene un tablero por defecto distinto
- [ ] Un widget con datos sensibles solo se puede agregar con el permiso correspondiente

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · Pedido §65 · RN-ANA-01 a RN-ANA-08 · Prototipo: Analytics → Tableros

### ANA-6 · Diversidad y distribución salarial con resguardo

*Historia* · depende de ANA-2, SEG-9

Mostrar indicadores de diversidad cuando legalmente corresponde y de distribución salarial, siempre agregados y con un tamaño mínimo de grupo. La activación por país se controla con una bandera de funcionalidad.

- [ ] Ningún grupo menor al mínimo configurado se muestra
- [ ] Los indicadores de diversidad pueden desactivarse por país

Referencias: docs/modulos/M-ANA.md · docs/06-reglas-de-negocio.md · Pedido §64 · Pedido §105 · E-05 · RN-ANA-01 a RN-ANA-08

## RPT · Reportes y constructor

Centro de reportes predefinidos y constructor de reportes con permisos sobre datos sensibles.

*Componente: Backend + Frontend*

### RPT-1 · Centro de reportes predefinidos

*Historia* · depende de UI-11, ANA-1

Reunir los reportes predefinidos de cada módulo en un centro con buscador, categorías, favoritos y parámetros de ejecución. Cada módulo registra sus reportes con su permiso.

- [ ] Solo aparecen reportes de módulos contratados y con permiso
- [ ] Cada reporte se ejecuta con filtros y se exporta

Referencias: docs/modulos/M-RPT.md · docs/06-reglas-de-negocio.md · Pedido §66 · RN-RPT-01 a RN-RPT-08 · Prototipo: Analytics → Reportes

### RPT-2 · Constructor de reportes

*Historia* · depende de RPT-1, CFG-5

Permitir construir reportes eligiendo fuente de datos, campos, filtros, agrupaciones, métricas y visualización (tabla, barras, líneas, dona), y guardarlos. Las fuentes de datos disponibles las publica cada módulo con sus campos.

- [ ] El usuario arma un reporte sin escribir consultas
- [ ] Los campos personalizados están disponibles como columnas y filtros

Referencias: docs/modulos/M-RPT.md · docs/06-reglas-de-negocio.md · Pedido §67 · RN-RPT-01 a RN-RPT-08 · Prototipo: Analytics → Reportes → Constructor

### RPT-3 · Permisos sobre datos sensibles en reportes

*Tarea técnica* · Backend · depende de RPT-2, SEG-9, SEG-10

Aplicar en el motor de reportes los permisos por campo y el alcance del usuario que ejecuta, también en reportes compartidos y programados. El control se ejecuta en el servidor al momento de cada ejecución.

- [ ] Un reporte compartido muestra a cada persona solo lo que puede ver
- [ ] Un campo sensible no puede elegirse sin el permiso correspondiente

Referencias: docs/modulos/M-RPT.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §67 · Pedido §105 · RN-RPT-01 a RN-RPT-08

### RPT-4 · Guardar, compartir y exportar

*Historia* · depende de RPT-3

Guardar reportes propios, compartirlos con usuarios o roles y exportarlos a Excel, CSV y PDF. La exportación usa el servicio común de exportación.

- [ ] Un reporte compartido puede ser de solo ejecución
- [ ] La exportación queda registrada

Referencias: docs/modulos/M-RPT.md · docs/06-reglas-de-negocio.md · Pedido §66 · Pedido §72 · RN-RPT-01 a RN-RPT-08

### RPT-5 · Programación de reportes

*Historia* · depende de RPT-4, NOT-3

Programar la entrega automática de un reporte por correo o notificación, con periodicidad, destinatarios y formato. La programación usa el planificador de la plataforma.

- [ ] El reporte programado se ejecuta con los permisos de su dueño y lo indica
- [ ] Un destinatario sin acceso recibe solo el aviso, no los datos

Referencias: docs/modulos/M-RPT.md · docs/06-reglas-de-negocio.md · Pedido §66 · RN-RPT-01 a RN-RPT-08

## ALE · Alertas explicadas

Motor de alertas con explicación obligatoria y riesgo de rotación con factores visibles.

*Componente: Backend + Frontend*

### ALE-1 · Motor de alertas

*Tarea técnica* · Backend · depende de ANA-1, WFL-5

Implementar el motor que evalúa reglas de alerta de forma programada o por evento, agrupa casos, evita repeticiones y entrega las alertas por alcance. Las reglas las registran los módulos y las organizaciones pueden agregar las suyas.

- [ ] Una alerta no se repite mientras su causa siga igual
- [ ] Cada usuario recibe solo alertas de su alcance

Referencias: docs/modulos/M-ALE.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §68 · RN-ALE-01 a RN-ALE-08

### ALE-2 · Catálogo de alertas

*Historia* · depende de ALE-1

Entregar el catálogo inicial: contratos por vencer, vacaciones acumuladas, cursos obligatorios incompletos, aumento de rotación de un área, documentos por firmar, evaluaciones vencidas y otros, con umbrales configurables. Cada alerta del catálogo trae su texto de explicación.

- [ ] Cada alerta se activa, se desactiva y se ajusta por organización
- [ ] Los umbrales son parámetros

Referencias: docs/modulos/M-ALE.md · docs/06-reglas-de-negocio.md · Pedido §68 · RN-ALE-01 a RN-ALE-08 · Prototipo: Analytics → Alertas

### ALE-3 · Explicación obligatoria y gestión de la alerta

*Historia* · depende de ALE-2

Mostrar en cada alerta por qué se generó (regla, datos y casos), y permitir asignarla, posponerla o descartarla con motivo. La explicación se genera con los datos de la regla, sin depender de la IA.

- [ ] Ninguna alerta se muestra sin su explicación y el enlace a los registros
- [ ] Descartar una alerta exige motivo y queda registrado

Referencias: docs/modulos/M-ALE.md · docs/06-reglas-de-negocio.md · Pedido §68 · C-08 · RN-ALE-01 a RN-ALE-08

### ALE-4 · Riesgo de rotación explicable

*Historia* · depende de ALE-3, PRI-5, COM-3

Calcular un indicador de riesgo de rotación por colaborador con factores visibles (tiempo sin aumento, posición en banda, ausentismo, resultados de clima del área, cambios de jefe), para uso de RRHH y del jefe con permiso. Los factores y sus pesos son configurables y transparentes para RRHH.

- [ ] Cada puntaje muestra los factores que lo componen y su peso
- [ ] El indicador no dispara ninguna acción automática sobre la persona
- [ ] Su activación exige la evaluación de impacto aprobada

Referencias: docs/modulos/M-ALE.md · docs/06-reglas-de-negocio.md · E-26 · C-08 · RN-ALE-01 a RN-ALE-08

## IAR · Asistente de IA y gobierno

Capa de IA opcional con registro de uso, permisos del usuario y sin decisiones automáticas de alto impacto.

*Componente: Backend + Frontend*

### IAR-1 · Capa de proveedor de IA

*Tarea técnica* · Backend · MVP · depende de PLT-13, SAS-7

Implementar el adaptador de modelos de lenguaje con implementación falsa, selección de proveedor por organización, límites de consumo y filtrado de datos personales no necesarios antes de enviar. El adaptador permite cambiar de proveedor sin tocar los casos de uso.

- [ ] La IA es opcional y se activa por organización
- [ ] Ningún dato sale a un proveedor si la organización no lo habilitó

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §69 · Pedido §75 · RN-IAR-01 a RN-IAR-16

### IAR-2 · Registro de uso de IA

*Tarea técnica* · Backend · MVP · depende de IAR-1, AUD-3

Registrar cada uso: quién, función, qué datos se consultaron, qué se envió al modelo y qué respondió, con consulta para el administrador y el responsable de privacidad. El registro es de solo inserción.

- [ ] Cada respuesta de IA tiene su registro consultable
- [ ] El registro tiene retención configurable

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · C-08 · Pedido §69 · RN-IAR-01 a RN-IAR-16

### IAR-3 · La IA respeta los permisos del usuario

*Tarea técnica* · Backend · depende de IAR-1, SEG-9, SEG-10

Ejecutar toda consulta de datos de la IA con los permisos, el alcance y los permisos por campo del usuario que pregunta, usando los mismos servicios que la API. La IA no tiene acceso directo a la base de datos.

- [ ] La IA no revela a un usuario datos que no podría ver en pantalla
- [ ] Una prueba automática intenta extraer sueldos con un rol sin permiso

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · C-08 · Pedido §69 · RN-IAR-01 a RN-IAR-16

### IAR-4 · Redacción asistida

*Historia* · depende de IAR-3, ATS-1, CIN-1

Ofrecer borradores de descripción de puesto, comunicados, preguntas de entrevista y devoluciones, siempre como texto editable que una persona revisa y publica. Los textos se generan con el contexto que el usuario ya puede ver.

- [ ] Todo texto generado se marca como borrador de IA hasta que una persona lo acepta
- [ ] El usuario puede regenerar o descartar

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · Pedido §69 · RN-IAR-01 a RN-IAR-16

### IAR-5 · Resúmenes de CV y de encuestas

*Historia* · depende de IAR-3, ATS-6, ENC-6

Resumir el CV de un candidato y los comentarios abiertos de una encuesta, sin puntuar ni ordenar candidatos de forma automática. Los resúmenes citan la parte del documento en la que se basan.

- [ ] El resumen de CV no descarta ni ordena candidatos
- [ ] El resumen de comentarios respeta el umbral de anonimato

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · Pedido §69 · C-08 · C-06 · RN-IAR-01 a RN-IAR-16

### IAR-6 · Consulta en lenguaje natural

*Historia* · depende de IAR-3, ANA-2, RPT-2

Permitir preguntas como «¿cuántas personas ingresaron este trimestre?»: la IA traduce la pregunta a una consulta sobre el catálogo de indicadores y reportes, responde con la cifra, su definición y el enlace al detalle, y puede generar un reporte. La consulta se resuelve con herramientas tipadas, no con SQL generado.

- [ ] La respuesta cita el indicador y los filtros usados
- [ ] Si la pregunta excede los permisos, lo indica sin revelar datos

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · Pedido §69 · Pedido §64 · RN-IAR-01 a RN-IAR-16

### IAR-7 · Explicación de métricas y detección de anomalías

*Historia* · depende de IAR-6, ALE-3, PER-7

Explicar en lenguaje claro la variación de un indicador y señalar anomalías operativas (planilla, asistencia, gastos) como alertas con su explicación. La detección combina reglas estadísticas con la explicación redactada por la IA.

- [ ] Cada anomalía indica los datos que la sustentan
- [ ] La anomalía es una sugerencia de revisión para una persona

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · Pedido §69 · Pedido §68 · RN-IAR-01 a RN-IAR-16

### IAR-8 · Salvaguardas contra decisiones automáticas

*Tarea técnica* · Backend · depende de IAR-3

Impedir por diseño que la IA ejecute acciones de alto impacto: contratar, cesar, sancionar, promover o cambiar sueldo. La IA solo puede preparar borradores que una persona confirma con su propio permiso.

- [ ] Las herramientas disponibles para la IA no incluyen acciones de alto impacto
- [ ] Una prueba verifica que ningún flujo de IA cambia sueldo, estado laboral ni sanciones

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · C-08 · Pedido §69 · Pedido §46 · RN-IAR-01 a RN-IAR-16

### IAR-9 · Asistente de ayuda documental

*Historia* · MVP · depende de IAR-1, IAR-2, AYU-5, AYU-6

Ofrecer dentro del centro de ayuda un asistente que responde preguntas sobre cómo usar GestiaHR y sobre las políticas de la organización, usando solo los artículos que el usuario puede ver. Cada respuesta cita los artículos usados; si no hay fuente suficiente lo dice y ofrece derivar la consulta. En esta versión no lee datos de colaboradores ni ejecuta acciones.

- [ ] Cada respuesta cita los artículos usados con su enlace
- [ ] Sin fuente suficiente, el asistente lo dice y ofrece derivar a RRHH
- [ ] Una prueba confirma que el asistente no consulta datos de colaboradores
- [ ] Cada pregunta y respuesta queda en el registro de IA con el caso de uso «ayuda»

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/12-seguridad-y-privacidad.md · Pedido §69 · C-08 · RN-IAR-01 a RN-IAR-16 · Prototipo: Barra superior → Ayuda → Asistente

### IAR-10 · Activación del asistente de ayuda desde la administración

*Historia* · MVP · depende de IAR-9, SAS-7, SEG-7, AUD-2

Controlar el asistente de ayuda en tres niveles: GestiaHR lo habilita por plan con una función de la consola, el administrador de la organización lo enciende o apaga, y elige para qué roles queda disponible y con qué límite de preguntas por usuario. Si está apagado, el asistente no aparece y la API responde que no está disponible, mientras el centro de ayuda sigue funcionando.

- [ ] El administrador enciende o apaga el asistente y elige los roles habilitados
- [ ] Con el asistente apagado no aparece en ninguna pantalla y la API lo rechaza
- [ ] Cada cambio de activación queda en auditoría con antes, después y autor
- [ ] Si el plan no lo incluye, la pantalla de administración lo indica y no permite encenderlo

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · RN-IAR-01 a RN-IAR-16 · Prototipo: Configuración → Ayuda y asistente → Asistente

### IAR-11 · Calidad de las respuestas del asistente de ayuda

*Tarea técnica* · Calidad · MVP · depende de IAR-9, AYU-5

Mantener un conjunto de preguntas de referencia por rol con los artículos que deben citarse, que se ejecuta en integración continua con el proveedor simulado y periódicamente con el proveedor real. Los resultados y las valoraciones de los usuarios alimentan la revisión del contenido de ayuda.

- [ ] Cada rol de sistema tiene preguntas de referencia con su respuesta esperada
- [ ] Una respuesta que cita un artículo no visible para el rol hace fallar la prueba
- [ ] El tablero de calidad muestra preguntas sin respuesta y respuestas mal valoradas

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/12-seguridad-y-privacidad.md · RN-IAR-01 a RN-IAR-16

### IAR-12 · Asistente de ayuda con los datos del propio usuario

*Historia* · depende de IAR-3, IAR-10, VAC-2, BOL-2

Ampliar el asistente de ayuda para responder preguntas sobre los datos del propio usuario, como su saldo de vacaciones o su última boleta, con herramientas tipadas que aplican sus permisos y su alcance. Es un caso de uso distinto que el administrador activa por separado.

- [ ] El asistente solo responde con datos que el usuario ya puede ver en pantalla
- [ ] El caso de uso con datos se activa aparte del asistente documental
- [ ] Una prueba intenta obtener datos de otra persona y debe fallar

Referencias: docs/modulos/M-IAR.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · C-08 · RN-IAR-01 a RN-IAR-16

# Fase 9 · Ecosistema y expansión

## API · API pública, webhooks y MCP

API pública versionada con tokens y scopes, webhooks firmados y servidor MCP para asistentes externos.

*Componente: Backend*

### API-1 · API pública con tokens y scopes

*Historia* · Backend + Frontend · depende de PLT-5, SEG-8, SAS-5

Publicar la API `/api/v1` para terceros (colaboradores, asistencia, ausencias, reclutamiento y demás recursos) con tokens por organización, scopes por recurso y acción, vencimiento y revocación. Los tokens se administran desde la configuración de la organización.

- [ ] Un token solo accede a los recursos de sus scopes y de su organización
- [ ] Los tokens se muestran una sola vez y se pueden revocar
- [ ] La API pública usa los mismos servicios y validaciones que la aplicación

Referencias: docs/modulos/M-API.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §73 · Pedido §117 · E-22 · RN-API-01 a RN-API-09 · Prototipo: Configuración → Integraciones y API → Tokens de API

### API-2 · Portal de desarrolladores

*Historia* · Backend + Frontend · depende de API-1

Publicar la documentación de la API con referencia navegable, guías, ejemplos, registro de cambios y entorno de pruebas con datos demo. El portal es público y no requiere cuenta para leer la documentación.

- [ ] La referencia se genera desde el contrato OpenAPI
- [ ] El entorno de pruebas no toca datos reales

Referencias: docs/modulos/M-API.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §73 · E-22 · RN-API-01 a RN-API-09

### API-3 · Límites de uso y versionado

*Tarea técnica* · depende de API-1, SAS-6

Aplicar límites de uso por token y por plan, medir el consumo y definir la política de versionado y retiro de versiones. Las versiones retiradas se anuncian con anticipación en el registro de cambios.

- [ ] Superar el límite responde 429 con el tiempo de espera
- [ ] El consumo de API por organización se ve en la consola SaaS

Referencias: docs/modulos/M-API.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §73 · Pedido §111 · RN-API-01 a RN-API-09

### API-4 · Webhooks

*Historia* · Backend + Frontend · depende de API-1, PLT-6

Permitir suscribir direcciones a eventos (`employee.created`, `employee.updated`, `employee.terminated`, `leave.approved`, `payroll.closed`, `candidate.hired`) con firma del mensaje, reintentos y registro de entregas. Los eventos salen del outbox y llevan identificador para deduplicar.

- [ ] Cada entrega va firmada y el receptor puede verificarla
- [ ] Una entrega fallida se reintenta con espera creciente y queda en el registro
- [ ] El administrador reenvía una entrega desde el registro

Referencias: docs/modulos/M-API.md · docs/06-reglas-de-negocio.md · docs/08-api.md · Pedido §74 · Pedido §103 · RN-API-01 a RN-API-09 · Prototipo: Configuración → Integraciones y API → Tokens de API

### API-5 · Servidor MCP

*Tarea técnica* · depende de API-1, IAR-2, IAR-8

Publicar un servidor MCP que expone a asistentes de IA externos herramientas de consulta y acciones de bajo impacto, autenticado con el usuario y limitado por sus permisos. La conexión se autoriza con un flujo de consentimiento del usuario.

- [ ] Un asistente externo consulta datos con los permisos del usuario que lo conecta
- [ ] Las herramientas no incluyen acciones de alto impacto
- [ ] Cada llamada queda en el registro de uso de IA

Referencias: docs/modulos/M-API.md · docs/06-reglas-de-negocio.md · docs/08-api.md · E-22 · C-08 · RN-API-01 a RN-API-09

## INT · Integraciones

Marco de conectores desacoplados para ERP, bancos, biométricos, SSO, calendario y otros.

*Componente: Backend + Frontend*

### INT-1 · Marco y catálogo de integraciones

*Historia* · depende de PLT-13, SEG-11

Construir el catálogo de integraciones por organización con activación, credenciales cifradas, estado, registro de sincronizaciones y errores, sobre el patrón de adaptadores. Cada conector declara qué datos lee y escribe.

- [ ] Activar una integración no requiere desplegar
- [ ] Cada sincronización muestra resultado y errores
- [ ] El núcleo no depende de ningún proveedor concreto

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §75 · RN-INT-01 a RN-INT-08 · Prototipo: Configuración → Integraciones y API → Conectores

### INT-2 · Conector contable y de ERP

*Historia* · depende de INT-1, PRV-3

Enviar asientos de planilla y gastos, y sincronizar centros de costo y plan de cuentas con sistemas contables por archivo y por API. El mapeo de cuentas se configura por empresa.

- [ ] El asiento exportado se marca con el identificador del sistema destino
- [ ] Un rechazo del ERP se muestra con su motivo

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · Pedido §75 · E-03 · RN-INT-01 a RN-INT-08

### INT-3 · Conectores bancarios

*Historia* · depende de INT-1, DEC-5

Integrar el envío de lotes de pago y la lectura de resultados con bancos que ofrecen conexión directa, manteniendo el archivo como alternativa. Las credenciales bancarias se guardan cifradas y su uso exige doble aprobación.

- [ ] El resultado del banco concilia el lote pago por pago
- [ ] Sin conexión directa, el flujo por archivo sigue funcionando

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · Pedido §75 · Pedido §28 · RN-INT-01 a RN-INT-08

### INT-4 · Conectores de relojes biométricos

*Historia* · depende de INT-1, ASI-7

Entregar conectores para los fabricantes de relojes más usados sobre la interfaz abierta de marcaciones, con sincronización de trabajadores hacia el reloj. Los conectores se construyen como adaptadores del marco de integraciones.

- [ ] Un reloj compatible se conecta desde la configuración sin programar
- [ ] El estado de cada reloj es visible

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · Pedido §75 · E-07 · RN-INT-01 a RN-INT-08

### INT-5 · Inicio de sesión único (SSO)

*Historia* · depende de INT-1, SEG-6

Soportar SSO por SAML y OpenID Connect por organización, con aprovisionamiento de usuarios y convivencia con el ingreso por documento. La asignación de roles puede mapearse desde los grupos del proveedor de identidad.

- [ ] La organización obliga SSO a ciertos dominios y mantiene el ingreso con documento para el personal operativo
- [ ] La baja en el proveedor de identidad desactiva al usuario

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · docs/07-permisos-y-roles.md · Pedido §75 · Pedido §60 · RN-INT-01 a RN-INT-08

### INT-6 · Calendario y correo

*Historia* · depende de INT-1, ENT-1, FBK-1

Sincronizar entrevistas, reuniones 1:1, capacitaciones y ausencias con los calendarios corporativos, y permitir el envío de correo desde el dominio del cliente. La sincronización es opcional por usuario y por organización.

- [ ] Una entrevista programada aparece en el calendario de los entrevistadores
- [ ] Una ausencia aprobada puede bloquear el calendario del colaborador

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · Pedido §75 · Pedido §78 · RN-INT-01 a RN-INT-08

### INT-7 · Conectores de selección y aprendizaje externos

*Historia* · depende de INT-1, ATS-6, LMS-6

Integrar bolsas de empleo, evaluaciones psicométricas y verificación de antecedentes en el reclutamiento, y catálogos de cursos externos en el aprendizaje. Cada conector sigue el patrón de adaptador con implementación falsa.

- [ ] El resultado de una evaluación externa llega a la ficha del candidato
- [ ] Un curso externo completado actualiza la inscripción

Referencias: docs/modulos/M-INT.md · docs/06-reglas-de-negocio.md · Pedido §75 · RN-INT-01 a RN-INT-08

## WHA · Autoservicio por WhatsApp

Canal de WhatsApp para boletas, vacaciones, constancias y marcación, sobre la capa de mensajería.

*Componente: Backend*

### WHA-1 · Vinculación de identidad y sesión en WhatsApp

*Tarea técnica* · Ola 2 · depende de NOT-5, SEG-2

Vincular el número de celular con el usuario mediante verificación con documento y OTP, y mantener sesiones cortas con reverificación para operaciones sensibles. El canal se apoya en la capa de mensajería de la plataforma.

- [ ] Un número no vinculado no obtiene ningún dato
- [ ] Las operaciones sensibles piden un código adicional
- [ ] El colaborador desvincula su número desde el portal

Referencias: docs/modulos/M-WHA.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · E-11 · C-09 · Pedido §86 · RN-WHA-01 a RN-WHA-07

### WHA-2 · Menú conversacional y consultas

*Historia* · Ola 2 · depende de WHA-1, VAC-2

Construir el menú conversacional con opciones guiadas para consultar saldo de vacaciones, horario, pendientes y estado de solicitudes. Las plantillas de mensaje se gestionan y aprueban por organización.

- [ ] Las respuestas usan los mismos servicios y permisos que el portal
- [ ] El menú funciona sin IA, con opciones numeradas

Referencias: docs/modulos/M-WHA.md · docs/06-reglas-de-negocio.md · E-11 · Pedido §85 · RN-WHA-01 a RN-WHA-07

### WHA-3 · Boletas y constancias por WhatsApp

*Historia* · Ola 2 · depende de WHA-2, BOL-2, ESS-4

Entregar boletas y constancias por enlace seguro de un solo uso tras reverificación, sin enviar el archivo en el chat. El enlace abre el documento en el portal con una sesión limitada.

- [ ] El documento se abre con un enlace que caduca
- [ ] La entrega queda registrada como acceso del trabajador

Referencias: docs/modulos/M-WHA.md · docs/06-reglas-de-negocio.md · E-11 · Pedido §31 · RN-WHA-01 a RN-WHA-07

### WHA-4 · Solicitud de vacaciones por WhatsApp

*Historia* · depende de WHA-2, VAC-3

Permitir solicitar vacaciones en la conversación y que el jefe apruebe o rechace desde WhatsApp. Los mensajes muestran saldo y fechas antes de confirmar.

- [ ] La solicitud sigue el mismo flujo de aprobación que en el portal
- [ ] El jefe aprueba respondiendo a la notificación

Referencias: docs/modulos/M-WHA.md · docs/06-reglas-de-negocio.md · E-11 · Pedido §25 · RN-WHA-01 a RN-WHA-07

### WHA-5 · Marcación por WhatsApp

*Historia* · depende de WHA-2, ASI-3

Permitir marcar asistencia enviando la ubicación en la conversación, con las mismas validaciones de geocerca y política. La organización decide si habilita este medio de marcación.

- [ ] La marca queda con medio «WhatsApp» y su ubicación
- [ ] Una ubicación fuera de la geocerca se registra como observada

Referencias: docs/modulos/M-WHA.md · docs/06-reglas-de-negocio.md · E-11 · E-07 · RN-WHA-01 a RN-WHA-07

## PAI · Paquetes de país

Estructura de paquetes de país para extender el producto a Latinoamérica sin tocar el núcleo.

*Componente: Backend*

### PAI-1 · Contrato del paquete de país

*Tarea técnica* · depende de NOM-1, CUM-1

Formalizar lo que aporta un paquete de país: parámetros, conceptos, regímenes, tipos de contrato y de ausencia, feriados, documentos, declaraciones, validaciones de identidad y obligaciones de cumplimiento, con su mecanismo de instalación y versionado. El contrato se documenta para que un equipo pueda construir un paquete nuevo.

- [ ] Un paquete se instala y actualiza sin modificar el núcleo
- [ ] El paquete declara su versión y la norma de cada regla

Referencias: docs/modulos/M-PAI.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · Pedido §82 · C-05 · RN-PAI-01 a RN-PAI-06

### PAI-2 · Conformidad del paquete Perú

*Tarea técnica* · depende de PAI-1

Verificar que toda la lógica peruana construida en las fases anteriores vive dentro del paquete Perú y mover lo que haya quedado en el núcleo. El resultado es un paquete Perú instalable y versionado.

- [ ] Una prueba de arquitectura confirma que el núcleo no referencia normas ni conceptos peruanos
- [ ] El paquete Perú pasa las pruebas doradas de planilla

Referencias: docs/modulos/M-PAI.md · docs/06-reglas-de-negocio.md · docs/14-localizacion-peru.md · Pedido §82 · Pedido §28 · RN-PAI-01 a RN-PAI-06

### PAI-3 · Paquete de un segundo país como prueba

*Tarea técnica* · depende de PAI-2

Construir el esqueleto de un segundo paquete de país (documentos de identidad, feriados, moneda, tipos de contrato, un régimen y conceptos básicos) para probar que la arquitectura lo soporta. El país de la prueba lo define el negocio.

- [ ] Una empresa del segundo país opera Core HR y tiempo con su paquete
- [ ] Las brechas del núcleo detectadas quedan documentadas y resueltas

Referencias: docs/modulos/M-PAI.md · docs/06-reglas-de-negocio.md · Pedido §82 · Pedido §81 · RN-PAI-01 a RN-PAI-06

### PAI-4 · Grupos con empresas en varios países

*Historia* · Backend + Frontend · depende de PAI-3, CFG-7

Permitir que una organización tenga empresas en distintos países, cada una con su paquete, y consolidar dotación y costo en una moneda de reporte. Los reportes de grupo se arman sobre la capa analítica.

- [ ] Cada empresa calcula con el paquete de su país
- [ ] El consolidado indica moneda y tipo de cambio

Referencias: docs/modulos/M-PAI.md · docs/06-reglas-de-negocio.md · Pedido §82 · Pedido §81 · Pedido §1 · RN-PAI-01 a RN-PAI-06

## WLB · Marca blanca

Personalización de marca por organización según plan.

*Componente: Backend + Frontend*

### WLB-1 · Marca de la organización

*Historia* · depende de UI-1, SAS-5

Permitir configurar logo, nombre visible, color principal y favicon, aplicados al armazón, al ingreso, a los documentos y al portal de empleos, en tema claro y oscuro. La marca se configura por organización y puede variar por empresa.

- [ ] El color elegido se valida para mantener el contraste
- [ ] La marca se aplica sin desplegar

Referencias: docs/modulos/M-WLB.md · docs/06-reglas-de-negocio.md · Pedido §83 · RN-WLB-01 a RN-WLB-05 · Prototipo: Configuración → Centro de configuración → Marca de la organización

### WLB-2 · Dominio personalizado

*Historia* · depende de WLB-1

Permitir usar un dominio propio para la aplicación y el portal de empleos, con verificación de propiedad y certificado automático. La configuración guía al cliente con los registros de DNS requeridos.

- [ ] El dominio se activa tras verificar su propiedad
- [ ] El dominio anterior sigue funcionando y redirige

Referencias: docs/modulos/M-WLB.md · docs/06-reglas-de-negocio.md · Pedido §83 · RN-WLB-01 a RN-WLB-05

### WLB-3 · Correos con la marca del cliente

*Historia* · depende de WLB-1, NOT-3

Personalizar remitente, dominio de envío verificado y plantillas de correo con la marca. La verificación del dominio de envío se guía paso a paso.

- [ ] Los correos salen del dominio del cliente cuando está verificado
- [ ] Las plantillas muestran logo y colores de la organización

Referencias: docs/modulos/M-WLB.md · docs/06-reglas-de-negocio.md · Pedido §83 · Pedido §86 · RN-WLB-01 a RN-WLB-05

### WLB-4 · Marca blanca según plan

*Tarea técnica* · Backend · depende de WLB-1, SAS-3

Controlar cada nivel de personalización (marca, dominio, correos, retiro de la marca del producto) como funcionalidad del plan. El control usa el mecanismo de módulos y funcionalidades de la suscripción.

- [ ] Una organización sin el nivel contratado no ve las opciones
- [ ] Al bajar de plan, la personalización no incluida se desactiva sin perder su configuración

Referencias: docs/modulos/M-WLB.md · docs/06-reglas-de-negocio.md · Pedido §83 · Pedido §3 · RN-WLB-01 a RN-WLB-05

## EST · Modo estudio contable

Consola de socio para que un estudio contable administre las planillas de muchas empresas pequeñas.

*Componente: Backend + Frontend*

### EST-1 · Consola de socio

*Historia* · Ola 2 · depende de TEN-1, SEG-10

Crear la cuenta de tipo estudio con su consola: lista de empresas cliente, estado de cada una y cambio rápido entre ellas, sin mezclar datos. El estudio tiene sus propios usuarios y roles internos.

- [ ] El estudio ve solo las organizaciones que lo autorizaron
- [ ] Cada organización cliente sigue aislada de las demás

Referencias: docs/modulos/M-EST.md · docs/06-reglas-de-negocio.md · docs/02-arquitectura.md · E-24 · RN-EST-01 a RN-EST-07 · Prototipo: Consola de la plataforma → Socios

### EST-2 · Alta de empresas cliente y delegación

*Historia* · Ola 2 · depende de EST-1, IMP-4

Permitir que el estudio cree organizaciones cliente con las plantillas peruanas, o que una empresa existente le delegue acceso con un rol y alcance definidos, revocable. La relación entre estudio y empresa queda registrada con su vigencia.

- [ ] La delegación la otorga y la revoca el administrador de la empresa
- [ ] Las acciones del estudio quedan en la auditoría de la empresa identificadas como tales

Referencias: docs/modulos/M-EST.md · docs/06-reglas-de-negocio.md · E-24 · E-27 · RN-EST-01 a RN-EST-07

### EST-3 · Procesos de planilla en lote

*Historia* · Ola 2 · depende de EST-2, PER-8, DEC-2

Permitir al estudio ver y avanzar en una sola pantalla el proceso de planilla de todas sus empresas: periodo, cálculo, cierre y archivos de declaración. Cada planilla se procesa dentro del contexto de su propia organización.

- [ ] La pantalla muestra en qué estado está la planilla de cada empresa
- [ ] Los archivos de declaración de varias empresas se generan en lote

Referencias: docs/modulos/M-EST.md · docs/06-reglas-de-negocio.md · E-24 · RN-EST-01 a RN-EST-07

### EST-4 · Tablero de obligaciones por cliente

*Historia* · Ola 2 · depende de EST-2, CUM-9

Mostrar al estudio el calendario de vencimientos y el semáforo de cumplimiento de todas sus empresas. El tablero se nutre del cumplimiento y de la bitácora de declaraciones de cada empresa.

- [ ] El tablero ordena por vencimiento más próximo
- [ ] Cada elemento lleva a la empresa y la obligación

Referencias: docs/modulos/M-EST.md · docs/06-reglas-de-negocio.md · E-24 · E-01 · RN-EST-01 a RN-EST-07

### EST-5 · Portal de la empresa cliente

*Historia* · Ola 2 · depende de EST-3

Dar a la empresa cliente del estudio un acceso simple para enviar novedades del periodo, aprobar la planilla y descargar boletas y reportes. El portal usa un rol predefinido de cliente de estudio.

- [ ] La empresa cliente envía sus incidencias sin operar la planilla
- [ ] La aprobación de la empresa queda registrada antes del cierre

Referencias: docs/modulos/M-EST.md · docs/06-reglas-de-negocio.md · E-24 · RN-EST-01 a RN-EST-07

# Transversal · Calidad

## QA · Calidad, seguridad y salida a producción

Pruebas de extremo a extremo por fase, pruebas doradas de planilla, rendimiento, seguridad, protección de datos, migración, ayuda y salida a producción.

*Componente: Calidad*

### QA-1 · Estrategia de pruebas y definición de hecho

*Tarea técnica* · MVP · depende de PLT-3, PLT-14

Definir la pirámide de pruebas (unitarias, de API, de contrato, de extremo a extremo), los datos de prueba, los entornos y la definición de «hecho» que aplica a cada actividad del backlog, incluida la revisión de que ningún botón queda sin función y de que los cambios persisten. La estrategia se publica en el repositorio.

- [ ] La definición de hecho está publicada y la aplican todas las solicitudes de cambio
- [ ] Existe un conjunto de datos de prueba reproducible por fase

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · Pedido §121 · Pedido §114 · Pedido §115

### QA-2 · Recorrido de extremo a extremo de cimientos y Core HR

*Tarea técnica* · MVP · depende de QA-1, IMP-5, DOC-7, SAS-5, MSS-3

Automatizar el recorrido: crear organización desde la consola, ingresar con correo y con documento, configurar estructura, dar de alta un colaborador, registrar un movimiento, generar y firmar un documento y verificar auditoría, permisos por campo y alcance. El recorrido usa la organización demo como datos de base.

- [ ] El recorrido corre en la integración continua contra un entorno limpio
- [ ] Verifica que un rol sin permiso de sueldos no los ve en pantalla, API ni exportación

Referencias: docs/modulos/M-QA.md · Pedido §120 · Pedido §114

### QA-3 · Recorrido de extremo a extremo de tiempo

*Tarea técnica* · depende de QA-2, VAC-3, ASI-9, BHO-4, TSH-3

Automatizar el recorrido: asignar horario, marcar por web y móvil, corregir una marca, solicitar y aprobar vacaciones y una ausencia, autorizar horas extra y verificar saldos, calendario y cálculo diario. El recorrido cubre también la marcación de kiosko y la bolsa de horas.

- [ ] Aprobar vacaciones actualiza solicitud, saldo, calendario, tablero y actividad
- [ ] La corrección de una marca conserva el registro original

Referencias: docs/modulos/M-QA.md · Pedido §115

### QA-4 · Recorrido de extremo a extremo de nómina

*Tarea técnica* · depende de QA-3, DEC-7, PRV-2, LIQ-4, BOL-2

Automatizar el recorrido de un periodo completo: incidencias, cálculo, simulación, aprobación, cierre, boletas, pago, archivos de declaración, provisiones y asiento, más una liquidación por cese. El recorrido verifica las constancias de entrega de boletas.

- [ ] Los totales de boletas, archivos y asiento coinciden entre sí
- [ ] Un periodo cerrado no admite cambios

Referencias: docs/modulos/M-QA.md · Pedido §27 · Pedido §115

### QA-5 · Pruebas doradas de planilla validadas por especialista laboral

*Tarea técnica* · depende de QA-4, BSO-3, PER-9, LOC-3, NOM-7

Construir un conjunto de casos de planilla con resultado esperado, calculado y firmado por un especialista laboral peruano: regímenes, AFP y ONP, quinta, gratificación, CTS, utilidades, horas extra, subsidios, ingresos y ceses a mitad de periodo, retroactivos y liquidaciones. El conjunto se ejecuta en cada cambio del motor o de los parámetros.

- [ ] Cada caso tiene entradas, resultado esperado y la validación del especialista registrada
- [ ] La integración continua falla si un caso difiere en un céntimo
- [ ] Cada cambio normativo agrega o actualiza casos

Referencias: docs/modulos/M-QA.md · docs/14-localizacion-peru.md · docs/06-reglas-de-negocio.md · Pedido §28 · C-05

### QA-6 · Recorrido de extremo a extremo de talento, desarrollo y compensación

*Tarea técnica* · depende de QA-2, OFF-5, CAR-7, COM-6, LMS-8

Automatizar el recorrido: vacante, postulación en el portal, entrevistas, oferta, contratación, pre-onboarding y onboarding, evaluación de desempeño, plan de desarrollo, curso con certificado, revisión salarial y salida. El recorrido se ejecuta en la integración continua.

- [ ] El recorrido cubre de la vacante al offboarding con los datos demo
- [ ] La contratación no duplica personas y ocupa la posición

Referencias: docs/modulos/M-QA.md · Pedido §113 · Pedido §124

### QA-7 · Recorrido de extremo a extremo de experiencia, operación y cumplimiento

*Tarea técnica* · depende de QA-2, ENC-7, HSD-7, DEN-7, CUM-7

Automatizar los recorridos de encuesta anónima con umbral, ticket con SLA, comunicado con lectura, denuncia anónima con código, caso disciplinario, entrega de EPP, gasto con rendición y tablero de cumplimiento con expediente de inspección. Cada recorrido verifica también permisos y alcance.

- [ ] La prueba confirma que una denuncia anónima no deja rastro técnico del denunciante
- [ ] La prueba confirma que un segmento bajo el umbral no muestra resultados

Referencias: docs/modulos/M-QA.md · C-06 · Pedido §114

### QA-8 · Recorrido de extremo a extremo de inteligencia y ecosistema

*Tarea técnica* · depende de QA-2, IAR-8, API-5, WHA-5, EST-5

Automatizar los recorridos de tablero, constructor de reportes, alerta con explicación, consulta de IA con permisos, token de API, webhook, MCP, WhatsApp y modo estudio. Los recorridos usan las implementaciones falsas de los proveedores externos.

- [ ] Un rol sin permiso no obtiene sueldos por reportes, IA, API ni MCP
- [ ] Los webhooks de los eventos del catálogo se entregan firmados

Referencias: docs/modulos/M-QA.md · C-08 · E-22 · Pedido §114

### QA-9 · Pruebas de concurrencia

*Tarea técnica* · depende de QA-3, PER-8, WFL-2

Probar las operaciones con riesgo de carrera: aprobaciones simultáneas, doble envío, cierre de periodo durante el cálculo, marcaciones duplicadas, consumo de saldos y ocupación de una misma posición. Las pruebas se ejecutan con peticiones en paralelo contra un entorno real.

- [ ] Dos aprobaciones simultáneas de la misma solicitud producen un solo efecto
- [ ] Dos altas simultáneas sobre la misma posición vacante dejan una sola ocupación
- [ ] Los saldos nunca quedan negativos por concurrencia

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · docs/05-maquinas-de-estado.md

### QA-10 · Accesibilidad

*Tarea técnica* · depende de QA-2, UI-9

Auditar la aplicación contra WCAG 2.2 nivel AA con pruebas automáticas en la integración continua y revisión manual con lector de pantalla y teclado en los recorridos principales. La auditoría cubre el tema claro y el oscuro.

- [ ] Las pruebas automáticas de accesibilidad corren en cada cambio de interfaz
- [ ] Los recorridos del colaborador se completan solo con teclado y con lector de pantalla
- [ ] Los hallazgos graves se corrigen antes de la salida

Referencias: docs/modulos/M-QA.md · Pedido §96 · Pedido §84

### QA-11 · Pruebas de carga con 50 000 colaboradores

*Tarea técnica* · depende de QA-4, ANA-3

Generar una organización de 50 000 colaboradores con historia y probar listados, búsqueda, cálculo de planilla, generación de boletas, cálculo de asistencia, tableros y exportaciones, además de la carga simultánea de muchas organizaciones. Las pruebas se ejecutan en un entorno equivalente a producción.

- [ ] Los objetivos de respuesta por tipo de operación están definidos y se cumplen
- [ ] El cálculo de planilla y la emisión de boletas terminan dentro del objetivo definido
- [ ] Los cuellos de botella hallados quedan corregidos o documentados con plan

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · Pedido §101

### QA-12 · Revisión de seguridad

*Tarea técnica* · depende de QA-8, TEN-4

Ejecutar análisis estático y de dependencias, prueba de penetración por un tercero, revisión del aislamiento entre organizaciones, de permisos por campo, de carga de archivos y de gestión de secretos, y corregir los hallazgos. La revisión se repite ante cambios mayores de arquitectura.

- [ ] No queda ningún hallazgo crítico ni alto abierto
- [ ] La prueba de aislamiento cubre todos los endpoints publicados
- [ ] El informe y las correcciones quedan archivados

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · docs/07-permisos-y-roles.md · Pedido §60 · Pedido §1

### QA-13 · Verificación de protección de datos

*Tarea técnica* · depende de QA-7, PRI-7, IAR-2

Verificar el cumplimiento de la Ley 29733 y su reglamento: consentimientos, derechos del titular, banco de datos, flujo de incidentes, evaluaciones de impacto de biometría e IA, retención, cifrado y transferencias, con un especialista en protección de datos. El resultado se documenta como informe de cumplimiento.

- [ ] Cada requisito del reglamento tiene su evidencia en el producto o en un procedimiento
- [ ] La evaluación de impacto de marcación facial y de IA está aprobada

Referencias: docs/modulos/M-QA.md · docs/14-localizacion-peru.md · E-16 · C-08 · Pedido §92

### QA-14 · Migración de los datos de una empresa

*Tarea técnica* · depende de QA-5, IMP-6, VAC-7, ASI-11, PER-12

Ejecutar de punta a punta la migración de una empresa real o representativa desde su sistema anterior: estructura, colaboradores, históricos, contratos, saldos de vacaciones, acumulados de planilla del ejercicio y documentos, con conciliación y planilla en paralelo. La migración usa los importadores del producto, sin cargas directas a la base.

- [ ] Los saldos y acumulados migrados cuadran con el sistema de origen
- [ ] Una planilla calculada en paralelo coincide con la del sistema anterior o la diferencia está explicada
- [ ] El procedimiento de migración queda documentado y es repetible

Referencias: docs/modulos/M-QA.md · Pedido §71 · E-27 · Pedido §122

### QA-15 · Material de ayuda

*Tarea técnica* · depende de QA-6, QA-7, QA-8, AYU-1

Producir la ayuda del producto: guías por rol, ayuda contextual en pantalla, guía de puesta en marcha, guía de planilla peruana y notas de versión, en español y preparadas para traducción. La ayuda se versiona junto con el producto y se publica en el centro de ayuda (AYU-1).

- [ ] Cada módulo tiene su guía y su ayuda contextual
- [ ] La guía de puesta en marcha acompaña al asistente de primeros pasos
- [ ] Cada artículo declara sus roles y pantallas para que el centro de ayuda y el asistente lo usen

Referencias: docs/modulos/M-QA.md · Pedido §80 · E-27 · Pedido §124

### QA-16 · Salida a producción

*Tarea técnica* · depende de QA-9, QA-10, QA-11, QA-12, QA-13

Preparar y ejecutar la salida: lista de verificación de infraestructura, copias y restauración probadas, monitoreo y alertas, guardias y procedimiento de incidentes, plan de reversión, página de estado y verificación posterior con una organización piloto. La salida se hace primero con la organización piloto y después se abre al resto.

- [ ] La restauración de una copia se probó con éxito antes de salir
- [ ] El procedimiento de incidentes y el de reversión están ensayados
- [ ] La organización piloto completa un ciclo de planilla en producción

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · Pedido §89 · Pedido §111 · Pedido §124

### QA-17 · Salida a producción del primer corte comercial

*Tarea técnica* · MVP · depende de QA-2, PER-13, BOL-2, DEC-4, LIQ-3

Llevar a producción solo las actividades con etiqueta mvp (plataforma, Core HR, vacaciones y ausencias, nómina Perú del régimen general y MYPE) con una organización piloto. La organización migra con los importadores del producto, incluidos los acumulados del ejercicio, y calcula al menos un periodo en paralelo con su sistema anterior antes de cerrar en el producto. Las pruebas doradas de planilla del alcance mvp deben estar validadas por el especialista laboral.

- [ ] Ningún parámetro legal que use el alcance mvp sigue sin valor ni sin validar
- [ ] La planilla en paralelo coincide con la del sistema anterior o cada diferencia está explicada
- [ ] La organización piloto publica boletas, genera los archivos de PLAME, AFPnet y T-Registro, y liquida un cese en producción
- [ ] La restauración de una copia y el procedimiento de incidentes se probaron antes de salir

Referencias: docs/modulos/M-QA.md · docs/02-arquitectura.md · docs/14-localizacion-peru.md · docs/18-localizacion-peru-detalle-tecnico.md · Pedido §122 · Pedido §124 · E-27
