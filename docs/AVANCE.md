# AVANCE — Ayni HR Kit

Generado el **2026-11-02**. Actualizar manualmente al cerrar cada historia.

> **Leyenda de estado:** `pendiente` · `en_curso` · `hecha` · `bloqueada`

<!-- TABLE_START -->
| Código | Título | Épica | Fase | Tipo | Estado | Fecha | Nota |
|--------|--------|-------|------|------|--------|-------|------|
| PLT-1 | Monorepo y estructura de aplicaciones | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-02 | Monorepo con apps/api, apps/web y packages/contracts; boundaries test y linters en verde. |
| PLT-2 | Entorno local con Docker | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-02 | Entorno Docker completo con API, Web, Postgres, Redis, MinIO, Mailpit y Horizon; db-reset verificado. |
| PLT-3 | Integración continua | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-03 | CI pipeline en .github/workflows/ci.yml con 3 jobs (lint, contratos, pruebas); CiContractsTest en verde. |
| PLT-4 | Formato único de errores y validación en servidor | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-03 | ProblemDetailsResponse, AyniException y jerarquía de excepciones; middleware EnsureRequestId; catálogo de errores en es/en/pt; handler global en bootstrap/app.php; ErrorFormatTest en verde. |
| PLT-5 | Contratos OpenAPI y tipos compartidos | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-03 | Contratos OpenAPI sincronizados con packages/contracts; validación estricta en servidor con league/openapi-psr7-validator; web con cliente tipado sin tipos duplicados a mano; documentación navegable en /docs y /docs/openapi.yaml; ContractConformanceTest en verde. |
| PLT-6 | Colas, trabajos programados y outbox de eventos de dominio | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-04 | OutboxService con registro transaccional y guardia de transacción activa; DispatchOutboxCommand (artisan outbox:dispatch) con reintentos y dead-letter; ProcessedEventTracker para consumo idempotente; catálogo versionado DomainEventTypes; programador en console.php; OutboxTest con 6 pruebas en verde. |
| PLT-7 | Servicio de archivos | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-04 | FileService sobre MinIO con bucket privado y acceso firmado de corta duración (403 directo no firmado verificado); validación de tipos MIME y tamaño máximo parametrizado en config/files.php; registro de descargas en auditoría y outbox document.uploaded.v1; FileServiceTest con 6 pruebas en verde. |
| PLT-8 | Idempotencia y acciones de estado | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-04 | Middleware EnsureIdempotency aplicado a rutas API sobre tabla idempotency_keys por (tenant_id, key) con bloqueo activo (409 request_in_progress), detección de hash diferente (409 idempotency_conflict) y respuesta cacheada con cabecera Idempotent-Replay: true; StateMachine abstracta con validación de transiciones (409 invalid_transition); trait HasOptimisticLocking con lock_version (409 version_conflict); IdempotencyTest y StateMachineTest en verde. |
| PLT-9 | Entornos y despliegue | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-06 | Infraestructura como código con .github/workflows/deploy.yml (despliegue manual por workflow_dispatch tolerante a falta de secretos y reversión); scripts/deploy.sh y scripts/rollback.sh; comando artisan env:verify para validación de secretos y políticas de producción; DeploymentAndEnvironmentTest en verde. |
| PLT-10 | Observabilidad | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-06 | Logs estructurados JSON con request_id y tenant_id automáticos (RequestContextProcessor); enmascaramiento estricto de campos sensibles y salarios (SensitiveDataScrubber); canal structured configurable; EnsureRequestId enlazado al contenedor; ObservabilityTest con 9 pruebas en verde. |
| PLT-11 | Rendimiento de base: paginación, índices y caché | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-06 | Paginación en servidor con OffsetPaginator y CursorPaginator (límite máximo 100 en PaginationParams); filtros y orden declarados con QuerySpecification (400 invalid_filter); TenantCache con aislamiento estricto por tenant_id; detección de N+1 con preventLazyLoading y AssertsQueryCount; PerformanceAndPaginationTest con 11 pruebas en verde. |
| PLT-12 | Convenciones del modelo de datos y migraciones | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Verificador automático de convenciones DatabaseConventionsChecker (artisan db:verify-conventions) que valida tenant_id, RLS forzado, FK compuestas y prohíbe ON DELETE CASCADE en historia laboral; TenantMigrationHelper; DataDictionaryGenerator (artisan db:generate-data-dictionary); DatabaseConventionsTest con 6 pruebas en verde. |
| PLT-13 | Adaptadores de integración con implementación falsa | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Patrón de puertos de integración (Email, Signature, Messaging, Banking, Biometric, AI); implementaciones falsas deterministas para desarrollo/testing; IntegrationManager con resolución contextual (fakes por defecto en no-producción); IntegrationCallRecorder para observabilidad de duración, estado y proveedor; regla arquitectónica en ModuleBoundariesTest; IntegrationAdaptersTest con 12 pruebas en verde. |
| PLT-14 | Guía de trabajo del repositorio | PLT | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Guía de trabajo docs/guia-trabajo.md con orden de fases, DoD por actividad, evaluación de código y regla estricta de no regresión (prohibido eliminar funcionalidad sin justificación técnica escrita); plantilla de PR en .github/pull_request_template.md con enlace y checklist interactivo; CiContractsTest ampliado con 5 pruebas en verde. |
| TEN-1 | Contexto de organización en cada petición | TEN | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | La organización se resuelve por el host (subdominio {slug}.TENANCY_BASE_DOMAIN o dominio propio verificado en tenant_domains) mediante TenantResolverChain; X-Tenant-Id solo en el entorno de pruebas; se eliminó la elección por parámetro tenant_id; credencial de otra organización → 404; organización suspendida → 403 tenant_suspended; prueba de arquitectura que prohíbe runWithoutTenant, withoutGlobalScopes, SET ROLE y set_config fuera de Shared/Tenancy y Platform; TenantResolutionTest (15 casos). |
| TEN-2 | Políticas RLS en PostgreSQL | TEN | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Aislamiento RLS en 429 tablas de negocio con app.tenant_id y rol ayni_app (NOBYPASSRLS, NOSUPERUSER); consultas directas con rol de aplicación y otra org devuelven 0 filas; inserts con tenant_id ajeno rechazados con SQLSTATE 42501; verificador artisan db:verify-conventions integrado al CI; pruebas_aislamiento.sql completo y pasando al 100% integrado a CI y make test-isolation. |
| TEN-3 | Claves foráneas compuestas por organización | TEN | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | 1020 claves foráneas compuestas (tenant_id, id) en esquema; enlaces cruzados entre organizaciones rechazados con SQLSTATE 23503 incluso por el propietario sin RLS; claves únicas compuestas por organización (documento, código colaborador, RUC); verificado en pruebas_aislamiento.sql (bloques 6.1, 6.2, 6.3) y TenancyIsolationTest. |
| TEN-4 | Prueba automática de aislamiento sobre todos los endpoints | TEN | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | CrossTenantHarness recorre las operaciones de openapi.yaml con ruta registrada y, como organización B, pide por id, modifica, lista y busca (q=) recursos sembrados en A con la factory del modelo: exige 404 y que la respuesta no contenga el id; un endpoint cuyo recurso no tiene modelo con HasFactory se informa como no cubierto y la prueba falla; toda ruta /api/v1 registrada debe existir en el contrato (se agregó GET /health vía gen_openapi.py). |
| TEN-5 | Organización con varias empresas y selector de empresa | TEN | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Empresas (razones sociales) del grupo: listar (filtrado por alcance), crear con validación de RUC, régimen del país y moneda, editar con If-Match, régimen laboral con vigencia (cerrar e insertar), archivar/restaurar por acciones (RN-TEN-05, sin borrado), límite del plan (RN-TEN-07, 403 plan_limit_reached), eventos company.created/archived y auditoría. DataScope aplica alcance y filtro de empresa (legal_entity_id) en la consulta: fuera de alcance = 404. Selector de empresa en la barra superior (Todas las empresas solo con permiso de consolidado) y pantalla Organización → Empresas; seeder demo con Comercial Los Andes y Transportes Inti. |
| TEN-6 | Aislamiento en caché, colas, archivos, búsqueda y exportaciones | TEN | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Colas: el payload lleva tenant_id y la etiqueta tenant:{id}, el trabajo se ejecuta en la organización de origen y restaura el contexto; un trabajo sin organización no se encola salvo que implemente RunsWithoutTenant. Archivos: FileService exige organización y valida la ruta tenants/{tenant_id}/ antes de firmar o borrar. Caché solo vía TenantCache y almacenamiento solo vía FileService (prueba de arquitectura). Búsqueda, descargas y exportaciones quedan bajo la batería TEN-4. |
| SEG-1 | Ingreso con correo y contraseña | SEG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Backend: ingreso con correo y clave (argon2id, índice ciego HMAC para el correo, identificador cifrado), sesión por cookie HttpOnly ayni_session con solo el hash en user_sessions y CSRF de doble envío (XSRF-TOKEN / X-XSRF-TOKEN), errores que no revelan si la cuenta existe, límite de intentos (429), recuperación por enlace de un solo uso que caduca (hash en user_tokens, envío por outbox + EmailPort), cierre de sesión que revoca; guard resuelto bajo RLS (cookie de otra organización = 401); trait BelongsToTenant. Pantallas /ingresar, /recuperar-clave y /restablecer-clave con el diseño aprobado (petróleo/ocre, Nunito Sans local), textos en es/en/pt, cliente de API con X-XSRF-TOKEN y sin tokens en el navegador; verificado de punta a punta con Playwright contra demo.localhost (organización demo sembrada sin claves en el código). |
| SEG-2 | Ingreso con documento de identidad y clave u OTP | SEG | Fase 0 · Cimientos | Story | pendiente | — |  |
| SEG-3 | Segundo factor (MFA) | SEG | Fase 0 · Cimientos | Story | pendiente | — |  |
| SEG-4 | Sesiones y dispositivos | SEG | Fase 0 · Cimientos | Story | pendiente | — |  |
| SEG-5 | Políticas de contraseña, bloqueo y límite de peticiones | SEG | Fase 0 · Cimientos | Task | pendiente | — |  |
| SEG-6 | Usuarios e invitaciones | SEG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Backend: alta de usuario separada de la persona (login, nombre, correo opcional RN-SEG-09, roles), invitación por enlace de un solo uso que caduca y se reenvía (outbox + EmailPort), activación por el propio usuario con su clave (POST /auth/invitation/accept, abre sesión), máquina de estados ME-02 (invited/active/locked/alumni/disabled) con acciones suspend/reactivate/invite/reset-password/reset-mfa y 409 invalid_transition, PATCH sin estado (regla 3), RN-SEG-02 último administrador, RN-SEG-03 al asignar roles, auditoría con antes/después y motivo, ingreso por login además de correo. Pantalla Configuración → Usuarios: listado con búsqueda, roles, último ingreso y estado; nuevo usuario con invitación; reenviar invitación, bloquear con motivo, reactivar y restablecer contraseña según permisos y acciones permitidas; /activar-cuenta para el enlace de invitación. |
| SEG-7 | Roles del sistema y roles personalizados | SEG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Backend: roles de sistema sembrados con seed_tenant_defaults; CRUD de roles personalizados con matriz de permisos y permisos por campo, alcance por rol, duplicar (copia permisos y alcances), If-Match/ETag; RN-SEG-01 (sistema inmutable, solo se duplica), RN-SEG-03 (no conceder lo que no se tiene), 409 in_use si está asignado; auditoría con antes y después; el cambio aplica de inmediato. Pantalla Configuración → Roles y permisos: lista de roles (sistema/personalizado), matriz de permisos por módulo con permisos sensibles marcados, alcance, guardar con If-Match, duplicar, eliminar y nuevo rol. |
| SEG-8 | Catálogo de permisos `modulo.recurso.accion` | SEG | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Autorización única: middleware permission:<código> (sesión → módulo contratado 403 module_not_enabled → permiso 403 forbidden con meta.permission) y alcance concedido en la petición; permisos efectivos = unión de roles vigentes con el alcance más amplio, recalculados en cada petición; GET /me con usuario, organización, roles, permisos, permisos por campo, alcances y módulos; GET /permissions con el catálogo; PermissionDeclarationTest falla si una ruta no declara exactamente el x-permission del contrato. |
| SEG-9 | Permisos por campo sensible | SEG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | SensitiveFields: mapa esquema→campo→permiso de lectura y categoría (config/sensitive_fields.php, verificado contra todos los x-sensitive-fields del contrato y el catálogo); sin permiso el campo no viaja en la respuesta ni en listados; filtrar u ordenar por un campo sensible sin permiso → 400 invalid_filter; cada lectura de campos sensibles inserta en data_access_logs (regla 7); prueba «administrador que gestiona colaboradores y no ve sueldos». La matriz de roles ya marca los permisos por campo. |
| SEG-10 | Alcance de datos | SEG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | DataScope.employmentScope: alcance por organización, empresa, sede, unidad (con descendientes), equipo (línea vigente por posición con excepción por relación, directos e indirectos) y propio, calculado en cada consulta (cambios de jefe o estructura aplican solos); applyEmployment para cualquier consulta laboral; asignación de rol con alcance y unidades concretas por PATCH /users/{id} (role_assignments). Pantalla Usuarios → Editar: roles con alcance por rol (el del rol, organización, empresa con empresas elegidas, equipo, propio). |
| SEG-11 | Cifrado de datos sensibles y registro de acceso | SEG | Fase 0 · Cimientos | Task | pendiente | — |  |
| SEG-12 | Control de descargas y exportaciones | SEG | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-1 | Consola separada para el superadministrador | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-2 | Organizaciones: alta, suspensión y bloqueo | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-3 | Planes comerciales | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-4 | Suscripciones, complementos y pruebas gratuitas | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-5 | Módulos por organización | SAS | Fase 0 · Cimientos | Task | pendiente | — |  |
| SAS-6 | Límites y consumo | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-7 | Feature flags | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-8 | Acceso de soporte auditado | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-9 | Salud y monitoreo de la plataforma | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-10 | Tablero del negocio SaaS | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-11 | Facturación de la suscripción y estado de cuenta | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-12 | Copias de seguridad y restauración | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| SAS-13 | Configuración global y registros de la consola | SAS | Fase 0 · Cimientos | Story | pendiente | — |  |
| AUD-1 | Bitácora de auditoría de solo inserción | AUD | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | audit_events de solo inserción: triggers reject_mutation y REVOKE UPDATE/DELETE al rol de la aplicación (prueba con ayni_app); no existe ruta que la modifique (prueba sobre todas las rutas); cada entrada guarda organización, actor, acción, entidad, id, IP, agente, request_id y momento con microsegundos. |
| AUD-2 | Captura automática de antes y después | AUD | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | AuditLogger cifra los campos sensibles del esquema (config/audit.php → sensitive_fields) y calcula los campos cambiados; trait Auditable captura altas, cambios y bajas por campo (una entrada por registro, también en acciones masivas que iteran modelos); el visor enmascara el valor a quien no tiene el permiso del campo ni audit.logs.sensitive_values.view. |
| AUD-3 | Actividad de usuario y logs técnicos separados | AUD | Fase 0 · Cimientos | Task | pendiente | — |  |
| AUD-4 | Visor de auditoría | AUD | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | GET /audit-logs (filtros por usuario, entidad, registro, acción, fechas y búsqueda; cursor), GET /audit-logs/{id}, GET /sensitive-access-logs; pantallas Configuración → Auditoría (detalle antes/después, historia completa del registro, exportación CSV con campos ya enmascarados) y → Accesos sensibles; prueba de que el rol Auditor no tiene ningún permiso de escritura. |
| AUD-5 | Integridad y retención de la auditoría | AUD | Fase 0 · Cimientos | Task | pendiente | — |  |
| UI-1 | Fundamentos visuales y temas claro y oscuro | UI | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Tokens semánticos de componentes (primario, controles, barra, velo, avisos, avatar) definidos para claro y oscuro; ningún estilo ni componente usa colores literales (prueba que recorre src/ui y src/modules); contraste AA verificado por prueba en 18 pares de uso real en ambos temas; tema claro/oscuro/del sistema en el menú de la cuenta, aplicado antes de pintar; identidad propia (petróleo/ocre, Nunito Sans). |
| UI-2 | Armazón de navegación | UI | Fase 0 · Cimientos | Story | en_curso | 2026-11-10 | Barra de módulos horizontal del diseño aprobado (no barra lateral, por decisión del cliente) filtrada por módulo contratado y permiso; en pantallas angostas se pliega en «Menú de módulos» desplegable; rutas con dirección propia (/configuracion/usuarios, /organizacion/empresas…). Falta la vista móvil del resto de módulos a medida que existan. |
| UI-3 | Biblioteca de componentes | UI | Fase 0 · Cimientos | Task | pendiente | — |  |
| UI-4 | Tablas profesionales | UI | Fase 0 · Cimientos | Task | pendiente | — |  |
| UI-5 | Formularios y validación en interfaz | UI | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | formErrors coloca cada error del backend junto a su campo (también campos anidados) y deja un mensaje general para lo que no es de un campo; sin red, el cliente devuelve network_error y el formulario conserva lo escrito; mensajes del servidor en lenguaje claro y en el idioma del usuario. Aplicado en Empresas y Usuarios; prueba unitaria. |
| UI-6 | Estados vacíos, carga y error | UI | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Componentes EmptyState (explicación + acción si el usuario tiene permiso) y ErrorState (reintentar); esqueletos de carga en las tablas; aplicados a Usuarios, Empresas, Roles y Auditoría (ninguna pantalla muestra solo «No hay registros»). |
| UI-7 | Paleta de comandos y búsqueda global | UI | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | GET /search con registro de fuentes por módulo (usuarios, roles, empresas) que aplican permiso, módulo contratado y alcance en la consulta; cubierto por la batería de aislamiento; paleta de comandos con Ctrl/⌘+K y botón Buscar en la barra superior: resultados del servidor + accesos a pantallas permitidas, navegación con flechas, Enter y Esc. Las demás fuentes (personas, documentos, vacantes…) se registran al construir sus módulos. |
| UI-8 | Botón de creación rápida | UI | Fase 0 · Cimientos | Story | pendiente | — |  |
| UI-9 | Experiencia móvil y aplicación instalable | UI | Fase 0 · Cimientos | Task | en_curso | 2026-11-10 | Manifiesto de aplicación instalable con íconos 192/512 y maskable, color de tema por modo, viewport móvil; el armazón funciona a 360 px sin desplazamiento horizontal (verificado con Playwright). Falta el service worker para uso sin conexión del portal (va con el portal del colaborador). |
| UI-10 | Divulgación progresiva | UI | Fase 0 · Cimientos | Task | pendiente | — |  |
| UI-11 | Servicio de exportación | UI | Fase 0 · Cimientos | Task | pendiente | — |  |
| UI-12 | Componentes de calendario, kanban y gráficos | UI | Fase 0 · Cimientos | Task | pendiente | — |  |
| WFL-1 | Modelo de definiciones, versiones y ejecuciones | WFL | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Flujos de aprobación versionados (workflow_definitions + versions + steps): crear, editar (abre una versión borrador), publicar (materializa los niveles) y retirar por acciones con 409 invalid_transition; Pantalla Configuración → Flujos de aprobación (niveles, quién aprueba, modo, plazo y escalamiento; guardar borrador y publicar); RN-WFL-01 elige el flujo publicado más específico por condiciones del contexto; RN-WFL-08 una solicitud en curso sigue con la versión con la que empezó; la traza completa queda en tareas, decisiones inalterables y auditoría. API /approval-flows. |
| WFL-2 | Motor de aprobaciones de N niveles | WFL | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | ApprovalEngine: 1 a N niveles secuenciales, paralelos «todos» o «cualquiera» (con mínimo de aprobaciones para mayoría); aprobar/rechazar idempotente y auditado, rechazo con motivo obligatorio (RN-WFL-04); el resultado sale una sola vez por outbox (approval.approved/rejected.v1) hacia el módulo dueño; cancelación por el origen (RN-WFL-13). API /approval-requests (listado con alcance y filtro «asignadas a mí», detalle con tareas, approve/reject/delegate). La «devolución para corrección» queda para cuando la use el primer módulo (VAC). |
| WFL-3 | Resolución de aprobadores, delegación y escalamiento | WFL | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | ApproverResolver: usuario fijo, rol, jefe directo y jefe del jefe (por la asignación vigente, con excepción por relación); delegación vigente por ausencia (RN-WFL-07) y delegación manual; aprobador inactivo → su jefe; nadie aprueba lo suyo (RN-WFL-05: se salta el nivel o va a RRHH); vencimiento: primer aviso con recordatorio y luego escalamiento al destino configurado, al jefe del aprobador o a RRHH, auditado (comando workflow:process-overdue cada 15 min por organización). |
| WFL-4 | Centro de aprobaciones | WFL | Fase 0 · Cimientos | Story | en_curso | 2026-11-10 | Pantalla Aprobaciones (barra de módulos con contador): bandeja única «por resolver» (y «todas» con alcance amplio) con conteo por tipo, tarjeta con solicitante, tipo, nivel en curso, antigüedad y vencimiento, detalle con el recorrido completo, aprobar, rechazar con motivo obligatorio y delegar; aprobación masiva solo en tipos marcados como seguros (config/workflow.php, validado en POST /approval-requests/bulk-approve, RN-WFL-12, auditoría por solicitud). Falta verificar «actualiza de inmediato el módulo de origen» con el primer módulo consumidor (VAC): el motor ya emite approval.approved/rejected.v1 en la misma transacción. |
| WFL-5 | Reglas CUANDO → SI → ENTONCES | WFL | Fase 0 · Cimientos | Task | pendiente | — |  |
| WFL-6 | Constructor visual de flujos | WFL | Fase 0 · Cimientos | Story | pendiente | — |  |
| WFL-7 | Centro de automatizaciones | WFL | Fase 0 · Cimientos | Story | pendiente | — |  |
| WFL-8 | Tareas de RRHH | WFL | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Tareas de RRHH (hr_tasks + migración que agrega assignee_role_id con FK compuesta): a una persona o a un rol, prioridad, vencimiento, vínculo al registro relacionado; completar y cancelar por acciones (409 invalid_transition), edición solo abiertas, alcance (propio/asignado, equipo, organización); vencidas resaltadas y avisadas una sola vez (task.overdue.v1 desde workflow:process-overdue); evento task.assigned.v1. Pantalla Tareas con filtros (mías/todas, abiertas/vencidas/hechas) y panel de alta/edición; enlace directo /tareas?id=. Demo con tres tareas coherentes con las empresas demo. |
| WFL-9 | Bandeja de pendientes | WFL | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | GET /inbox reúne los pendientes de todos los módulos mediante un contrato común (Shared/Inbox: InboxSource + InboxRegistry, filtrado por permiso y módulo contratado); hoy aportan aprobaciones (nivel en curso, a nombre propio o de un rol) y tareas. Pantalla Pendientes (lo vencido primero, cada ítem lleva a donde se resuelve) y contador en la barra superior y en los módulos, calculado desde el mismo /inbox; se recalcula al resolver (sin recargar), al navegar, al volver a la pestaña y cada minuto. |
| NOT-1 | Motor de notificaciones y plantillas | NOT | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Módulo Notifications con puerto único Shared\Notifications\Notifier: destinatarios calculados al enviar (activos; a un rol, quienes lo tienen hoy, RN-NOT-01), preferencias, plantilla en el idioma del destinatario (es/en/pt; la organización las edita y las variables se validan contra las del tipo), aviso en la aplicación y entregas por canal externo vía outbox con estado, intentos y error (notification_deliveries, dirección cifrada). Reprocesar un evento no repite avisos (consumo idempotente y entrega que no se reenvía si ya salió). Catálogo de tipos en config/notifications.php (aprobación por aprobar/recordatorio/escalada/aprobada/rechazada, tarea asignada/vencida, seguridad de la cuenta); el motor de aprobaciones y las tareas avisan por eventos. Correos sin datos sensibles: solo el aviso y el enlace a la organización (RN-NOT-02). Configuración → Mensajes: plantillas con variables, vista previa y registro de envíos (GET /notification-deliveries, dirección enmascarada). Docker: servicio scheduler (schedule:work) que despacha el outbox cada minuto. |
| NOT-2 | Centro de notificaciones en la aplicación | NOT | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Campana en la barra superior con contador de no leídas y lista de los últimos avisos; marcar uno o todos como leído (persiste en el servidor); ir al registro desde el aviso; el contador se recalcula sin recargar (cada 30 s, al volver a la pestaña y cuando otra pantalla resuelve algo). Pantalla Notificaciones con todas o sin leer, filtros por tipo y paginación. Retención: los avisos leídos se archivan pasados los días del parámetro notificaciones.retencion_dias (comando diario notifications:archive-old). |
| NOT-3 | Canal de correo | NOT | Fase 0 · Cimientos | Task | en_curso | 2026-11-10 | Canal de correo por EmailPort con entrega registrada (enviado/falló, reintento con espera creciente del outbox, RN-NOT-06); fuera de producción el adaptador es falso: ningún correo sale a destinatarios reales; enlaces a la dirección de la organización (TENANCY_APP_URL). Falta: remitente propio verificado por organización (message_channels), rebotes que marquen la dirección y avisen a RRHH — requiere una lista de supresión por dirección (propuesta de migración pendiente). |
| NOT-4 | Canal push | NOT | Fase 0 · Cimientos | Task | pendiente | — |  |
| NOT-5 | Capa de mensajería para SMS y WhatsApp | NOT | Fase 0 · Cimientos | Task | pendiente | — |  |
| NOT-6 | Preferencias de notificación | NOT | Fase 0 · Cimientos | Story | en_curso | 2026-11-10 | Preferencias del usuario (Menú de usuario → Mis notificaciones): matriz tipo de aviso × canal; un aviso obligatorio no se desactiva en la aplicación (RN-NOT-05, 422) — obligatorios del producto y los que declare la organización (parámetro notificaciones.obligatorias); horario de silencio de la organización (notificaciones.silencio_desde/hasta, zona horaria de la organización): los avisos no urgentes por correo se difieren hasta que termina (RN-NOT-03); lo urgente sale de inmediato. La organización los edita en Configuración → Notificaciones (CFG-3). Falta la desconexión durante vacaciones (RN-NOT-08, con el módulo de Vacaciones). |
| CFG-1 | Centro de configuración | CFG | Fase 0 · Cimientos | Story | hecha | 2026-11-10 | Centro de configuración en /configuracion: secciones (Organización, Acceso y seguridad, Procesos y avisos, Control, Parámetros por módulo) que solo muestran lo permitido y los parámetros de módulos contratados (GET /settings filtra por módulo); buscador de ajustes que encuentra secciones y parámetros por nombre o descripción y lleva directo al parámetro; cada tarjeta y parámetro con ayuda breve. Reemplaza la redirección anterior de Configuración (SettingsRedirect, obsoleto). |
| CFG-2 | Configuración por empresa | CFG | Fase 0 · Cimientos | Story | en_curso | 2026-11-10 | Parámetros por empresa con herencia: cada parámetro que lo admite puede definirse para una empresa (scope legal_entity) y si no, hereda de la organización; la pantalla muestra el origen (por defecto, de la organización, propio de esta empresa), el valor heredado y permite volver a heredar; dos empresas del grupo pueden tener zona horaria y moneda distintas (probado). Falta: logo y datos fiscales en la ficha de la empresa, calendario por empresa (CFG-8) y mostrar las fechas en la zona horaria de la empresa en todas las pantallas. |
| CFG-3 | Parámetros con vigencia por fecha | CFG | Fase 0 · Cimientos | Task | hecha | 2026-11-10 | Shared\Settings\ParameterResolver: única función de consulta «valor vigente a una fecha» con herencia empresa → organización → valor por defecto del catálogo (parameter_definitions); cambiar un valor cierra la vigencia anterior el día previo e inserta otra (regla 2), un valor con vigencia futura no altera fechas anteriores y no se permite si ya hay uno programado después (409); validación por tipo (booleano, entero, decimal exacto como texto, hora, fecha, selector, lista con opciones); cada cambio auditado con motivo o fuente. API GET /settings, GET/PUT /settings/{group} (?legal_entity_id, ?as_of). Las notificaciones ya leen sus parámetros por aquí. Pantalla Parámetros por sección con historial de vigencias y «rige desde». Los parámetros legales por país/régimen (legal_parameters) se administran con PAR en la Fase 3. |
| CFG-4 | Campos personalizados | CFG | Fase 0 · Cimientos | Story | pendiente | — |  |
| CFG-5 | Campos personalizados en tablas, filtros, importación y API | CFG | Fase 0 · Cimientos | Task | pendiente | — |  |
| CFG-6 | Internacionalización | CFG | Fase 0 · Cimientos | Task | pendiente | — |  |
| CFG-7 | Multimoneda y tipos de cambio | CFG | Fase 0 · Cimientos | Task | pendiente | — |  |
| CFG-8 | Calendarios, feriados y calendario de RRHH | CFG | Fase 0 · Cimientos | Story | pendiente | — |  |
| CFG-9 | Papelera y restauración | CFG | Fase 0 · Cimientos | Story | pendiente | — |  |
| CFG-10 | Catálogos maestros | CFG | Fase 0 · Cimientos | Task | pendiente | — |  |
| ORG-1 | Empresas y razones sociales | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-2 | Sedes, sucursales y ubicaciones | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-3 | Árbol de unidades organizacionales | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-4 | Centros de costo | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-5 | Catálogo de puestos, cargos, niveles y categorías | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-6 | Organigrama visual dinámico | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| ORG-7 | Directorio de personas | ORG | Fase 1 · Core HR | Story | pendiente | — |  |
| POS-1 | Posiciones como plazas | POS | Fase 1 · Core HR | Story | pendiente | — |  |
| POS-2 | Estados de la posición | POS | Fase 1 · Core HR | Task | pendiente | — |  |
| POS-3 | Solicitud y aprobación de nuevas posiciones | POS | Fase 1 · Core HR | Story | pendiente | — |  |
| POS-4 | Presupuesto de dotación y desviaciones | POS | Fase 1 · Core HR | Story | pendiente | — |  |
| POS-5 | Escenarios de planificación | POS | Fase 1 · Core HR | Story | pendiente | — |  |
| POS-6 | Política de contratar solo contra posición | POS | Fase 1 · Core HR | Task | pendiente | — |  |
| POS-7 | Organigrama por posiciones | POS | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-1 | Personas y deduplicación | COL | Fase 1 · Core HR | Task | pendiente | — |  |
| COL-2 | Alta de relación laboral | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-3 | Ficha 360 del colaborador | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-4 | Datos personales y contactos de emergencia | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-5 | Datos laborales de la ficha | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-6 | Derechohabientes | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-7 | Régimen pensionario, salud y seguros del trabajador | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-8 | Cuentas bancarias y datos de pago | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-9 | Listado de colaboradores | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-10 | Reingreso y relaciones simultáneas | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| COL-11 | Registro del cese de la relación laboral | COL | Fase 1 · Core HR | Task | pendiente | — |  |
| COL-12 | Inicio de RRHH | COL | Fase 1 · Core HR | Story | pendiente | — |  |
| HIS-1 | Patrón de vigencia reutilizable | HIS | Fase 1 · Core HR | Task | pendiente | — |  |
| HIS-2 | Movimientos de personal | HIS | Fase 1 · Core HR | Story | pendiente | — |  |
| HIS-3 | Compensación con vigencia | HIS | Fase 1 · Core HR | Story | pendiente | — |  |
| HIS-4 | Cambios con fecha futura y correcciones retroactivas | HIS | Fase 1 · Core HR | Task | pendiente | — |  |
| HIS-5 | Línea de tiempo laboral | HIS | Fase 1 · Core HR | Story | pendiente | — |  |
| HIS-6 | Servicio de consulta de datos maestros | HIS | Fase 1 · Core HR | Task | pendiente | — |  |
| CON-1 | Tipos de contrato y modalidades | CON | Fase 1 · Core HR | Task | pendiente | — |  |
| CON-2 | Registro del contrato | CON | Fase 1 · Core HR | Story | pendiente | — |  |
| CON-3 | Renovaciones y adendas | CON | Fase 1 · Core HR | Story | pendiente | — |  |
| CON-4 | Alertas de vencimiento y de tope acumulado | CON | Fase 1 · Core HR | Story | pendiente | — |  |
| CON-5 | Renovación masiva con firma | CON | Fase 1 · Core HR | Story | pendiente | — |  |
| CON-6 | Acuerdo de teletrabajo | CON | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-1 | Repositorio y carpetas del legajo | DOC | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-2 | Versiones, archivo y vencimientos | DOC | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-3 | Solicitud y aprobación de documentos | DOC | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-4 | Plantillas dinámicas con variables | DOC | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-5 | Generación de PDF | DOC | Fase 1 · Core HR | Task | pendiente | — |  |
| DOC-6 | Flujo de firma | DOC | Fase 1 · Core HR | Story | pendiente | — |  |
| DOC-7 | Evidencia de firma electrónica propia | DOC | Fase 1 · Core HR | Task | pendiente | — |  |
| DOC-8 | Capa de proveedor de firma digital | DOC | Fase 1 · Core HR | Task | pendiente | — |  |
| ESS-1 | Inicio del colaborador | ESS | Fase 1 · Core HR | Story | pendiente | — |  |
| ESS-2 | Mi perfil y actualización de datos | ESS | Fase 1 · Core HR | Story | pendiente | — |  |
| ESS-3 | Mis documentos, contrato y firma | ESS | Fase 1 · Core HR | Story | pendiente | — |  |
| ESS-4 | Solicitud de certificados y constancias | ESS | Fase 1 · Core HR | Story | pendiente | — |  |
| ESS-5 | Solicitudes a RRHH desde el portal | ESS | Fase 1 · Core HR | Story | pendiente | — |  |
| ESS-6 | Portal del colaborador en el móvil | ESS | Fase 1 · Core HR | Task | pendiente | — |  |
| MSS-1 | Inicio del manager | MSS | Fase 1 · Core HR | Story | pendiente | — |  |
| MSS-2 | Mi equipo | MSS | Fase 1 · Core HR | Story | pendiente | — |  |
| MSS-3 | Aprobaciones y solicitudes del equipo | MSS | Fase 1 · Core HR | Story | pendiente | — |  |
| MSS-4 | Fechas clave y alertas del equipo | MSS | Fase 1 · Core HR | Story | pendiente | — |  |
| IMP-1 | Motor de importación | IMP | Fase 1 · Core HR | Task | pendiente | — |  |
| IMP-2 | Importadores de Core HR | IMP | Fase 1 · Core HR | Story | pendiente | — |  |
| IMP-3 | Asistente de primeros pasos | IMP | Fase 1 · Core HR | Story | pendiente | — |  |
| IMP-4 | Plantillas peruanas listas para usar | IMP | Fase 1 · Core HR | Task | pendiente | — |  |
| IMP-5 | Organización demo coherente | IMP | Fase 1 · Core HR | Task | pendiente | — |  |
| IMP-6 | Informe y reversión de importaciones | IMP | Fase 1 · Core HR | Story | pendiente | — |  |
| ASI-1 | Registro de marcaciones inalterable | ASI | Fase 2 · Tiempo | Task | pendiente | — |  |
| ASI-2 | Marcación web | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-3 | Marcación desde la app con selfie y geocerca | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-4 | Kiosko con QR y PIN | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-5 | Marcación de cuadrilla | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-6 | Marcación sin conexión | ASI | Fase 2 · Tiempo | Task | pendiente | — |  |
| ASI-7 | Conectores abiertos a relojes biométricos | ASI | Fase 2 · Tiempo | Task | pendiente | — |  |
| ASI-8 | Cálculo diario de asistencia | ASI | Fase 2 · Tiempo | Task | pendiente | — |  |
| ASI-9 | Incidencias y regularización | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-10 | Tablero y reporte legal de asistencia | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| ASI-11 | Importación de asistencia | ASI | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-1 | Jornadas y horarios | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-2 | Políticas de tiempo por empresa, sede o grupo | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-3 | Catálogo de turnos | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-4 | Calendario de turnos con arrastrar y soltar | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-5 | Asignación masiva y patrones rotativos | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| TUR-6 | Publicación de turnos y cambios entre compañeros | TUR | Fase 2 · Tiempo | Story | pendiente | — |  |
| VAC-1 | Política de vacaciones por régimen | VAC | Fase 2 · Tiempo | Task | pendiente | — |  |
| VAC-2 | Devengo y saldos de vacaciones | VAC | Fase 2 · Tiempo | Task | pendiente | — |  |
| VAC-3 | Solicitud y aprobación de vacaciones | VAC | Fase 2 · Tiempo | Story | pendiente | — |  |
| VAC-4 | Calendario de ausencias del equipo | VAC | Fase 2 · Tiempo | Story | pendiente | — |  |
| VAC-5 | Programación anual y vacaciones acumuladas | VAC | Fase 2 · Tiempo | Story | pendiente | — |  |
| VAC-6 | Fraccionamiento, adelanto y venta de vacaciones | VAC | Fase 2 · Tiempo | Story | pendiente | — |  |
| VAC-7 | Importación de saldos de vacaciones | VAC | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-1 | Tipos de ausencia con reglas propias | AUS | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-2 | Solicitud y registro de ausencias | AUS | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-3 | Descansos médicos y subsidios | AUS | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-4 | Licencias de maternidad, paternidad y otras de ley | AUS | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-5 | Trabajo remoto y desconexión digital | AUS | Fase 2 · Tiempo | Story | pendiente | — |  |
| AUS-6 | Ausencias como incidencias para planilla | AUS | Fase 2 · Tiempo | Task | pendiente | — |  |
| BHO-1 | Solicitud y autorización de horas extra | BHO | Fase 2 · Tiempo | Story | pendiente | — |  |
| BHO-2 | Cálculo de sobretiempo | BHO | Fase 2 · Tiempo | Task | pendiente | — |  |
| BHO-3 | Bolsa de horas con saldos | BHO | Fase 2 · Tiempo | Task | pendiente | — |  |
| BHO-4 | Compensación con descanso | BHO | Fase 2 · Tiempo | Story | pendiente | — |  |
| BHO-5 | Vencimiento de saldos y reporte | BHO | Fase 2 · Tiempo | Story | pendiente | — |  |
| TSH-1 | Proyectos y tareas imputables | TSH | Fase 2 · Tiempo | Story | pendiente | — |  |
| TSH-2 | Hoja de tiempo | TSH | Fase 2 · Tiempo | Story | pendiente | — |  |
| TSH-3 | Aprobación de hojas de tiempo | TSH | Fase 2 · Tiempo | Story | pendiente | — |  |
| TSH-4 | Costeo y reporte por proyecto | TSH | Fase 2 · Tiempo | Story | pendiente | — |  |
| NOM-1 | Arquitectura del motor: núcleo, paquete de país y régimen | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-2 | Regímenes laborales por empresa y por trabajador | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-3 | Intérprete de fórmulas seguro | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-4 | Orden de cálculo y dependencias entre conceptos | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-5 | Contexto de cálculo por relación laboral | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-6 | Trazabilidad del cálculo | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-7 | Recálculo retroactivo | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-8 | Cálculo masivo en segundo plano | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-9 | Estrategia por régimen laboral | NOM | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| NOM-10 | Micro y pequeña empresa: acreditación, ventas y permanencia | NOM | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| NOM-11 | Construcción civil: obras, categorías y planilla semanal | NOM | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| NOM-12 | Régimen agrario | NOM | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-1 | Catálogo de conceptos | CPT | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-2 | Editor de fórmulas y condiciones | CPT | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-3 | Afectaciones de cada concepto | CPT | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-4 | Contabilización por concepto | CPT | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-5 | Conceptos peruanos precargados | CPT | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| CPT-6 | Importación de conceptos e incidencias | CPT | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| CPT-7 | Códigos de la tabla de conceptos y naturaleza en la proyección de quinta | CPT | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PAR-1 | Parámetros legales generales | PAR | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PAR-2 | Tablas de AFP | PAR | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PAR-3 | Escala y deducciones de renta de quinta | PAR | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PAR-4 | Publicación de actualizaciones normativas | PAR | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PAR-5 | Parámetros por régimen laboral | PAR | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PAR-6 | Tabla salarial anual por convenio | PAR | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-1 | Periodos de planilla | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-2 | Incidencias del periodo | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-3 | Consumo de asistencia y ausencias | PER | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PER-4 | Cálculo de la planilla | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-5 | Renta de quinta categoría | PER | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PER-6 | Aportes previsionales y de salud | PER | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PER-7 | Simulación y detección de anomalías | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-8 | Revisión, aprobación y cierre | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-9 | Reapertura controlada y planilla complementaria | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-10 | Pago de la planilla | PER | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PER-11 | Libro de retenciones de quinta | PER | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| BOL-1 | Generación de boletas en PDF | BOL | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| BOL-2 | Publicación en el portal y constancia de entrega | BOL | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| BOL-3 | Historial y descarga masiva | BOL | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| BOL-4 | Conservación de boletas y constancias | BOL | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| BSO-1 | Gratificaciones | BSO | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| BSO-2 | CTS | BSO | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| BSO-3 | Utilidades | BSO | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| BSO-4 | Asignación familiar | BSO | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| BSO-5 | Remuneración vacacional e indemnización vacacional | BSO | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PRE-1 | Préstamos con cronograma | PRE | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRE-2 | Adelantos | PRE | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRE-3 | Descuento automático en planilla | PRE | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PRE-4 | Adelanto de sueldo sobre lo devengado | PRE | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRE-5 | Pago por billeteras digitales | PRE | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| DEC-1 | Mapeo de conceptos a tablas SUNAT | DEC | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| DEC-2 | Archivos de PLAME | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-3 | T-Registro | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-4 | Planilla de aportes para AFPnet | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-5 | Archivos bancarios | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-6 | Certificados de renta y de retenciones | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-7 | Bitácora de declaraciones | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-8 | Ficha del registro laboral y validación previa al alta | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-9 | Declaración jurada de rentas de otros empleadores | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| DEC-10 | Generador de archivos con estructuras versionadas | DEC | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| DEC-11 | Devolución de retenciones de quinta en exceso | DEC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRV-1 | Provisiones mensuales | PRV | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| PRV-2 | Asiento contable de planilla | PRV | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRV-3 | Exportación contable configurable | PRV | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| PRV-4 | Reporte de costo laboral | PRV | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LIQ-1 | Cálculo de la liquidación | LIQ | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| LIQ-2 | Simulador de liquidación | LIQ | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LIQ-3 | Aprobación, pago y plazo de la liquidación | LIQ | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LIQ-4 | Documentos de cese | LIQ | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LOC-1 | Registro de prestadores | LOC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LOC-2 | Recibos por honorarios | LOC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| LOC-3 | Retención de cuarta categoría y pago | LOC | Fase 3 · Nómina Perú | Task | pendiente | — |  |
| LOC-4 | Declaración y reportes de locadores | LOC | Fase 3 · Nómina Perú | Story | pendiente | — |  |
| ATS-1 | Vacantes ligadas a posiciones | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-2 | Aprobación y publicación de la vacante | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-3 | Portal público de empleos | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-4 | Formulario de postulación configurable | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-5 | Pipeline configurable con arrastrar y soltar | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-6 | Ficha del candidato | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-7 | Base de talento | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-8 | Referidos y movilidad interna | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ATS-9 | Indicadores de reclutamiento | ATS | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-1 | Agenda de entrevistas | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-2 | Guías y scorecards | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-3 | Evaluación del candidato por los entrevistadores | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-4 | Plantillas y generación de la oferta | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-5 | Flujo de la oferta | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ENT-6 | Contratación del candidato | ENT | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-1 | Portal de pre-onboarding | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-2 | Validación y alta desde el pre-onboarding | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-3 | Diseñador de plantillas de onboarding | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-4 | Ejecución del proceso de onboarding | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-5 | Avance y seguimiento | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| ONB-6 | Evaluación del periodo de prueba | ONB | Fase 4 · Talento | Story | pendiente | — |  |
| OFF-1 | Inicio del proceso de salida | OFF | Fase 4 · Talento | Story | pendiente | — |  |
| OFF-2 | Lista de verificación de salida | OFF | Fase 4 · Talento | Story | pendiente | — |  |
| OFF-3 | Entrevista y encuesta de salida | OFF | Fase 4 · Talento | Story | pendiente | — |  |
| OFF-4 | Desactivación de accesos al cese | OFF | Fase 4 · Talento | Task | pendiente | — |  |
| OFF-5 | Liquidación y documentos de salida en el proceso | OFF | Fase 4 · Talento | Story | pendiente | — |  |
| DES-1 | Configuración de campañas | DES | Fase 4 · Talento | Story | pendiente | — |  |
| DES-2 | Formularios, preguntas y escalas | DES | Fase 4 · Talento | Story | pendiente | — |  |
| DES-3 | Red de evaluadores | DES | Fase 4 · Talento | Story | pendiente | — |  |
| DES-4 | Ejecución de la evaluación | DES | Fase 4 · Talento | Story | pendiente | — |  |
| DES-5 | Calibración | DES | Fase 4 · Talento | Story | pendiente | — |  |
| DES-6 | Resultados y devolución | DES | Fase 4 · Talento | Story | pendiente | — |  |
| OKR-1 | Ciclos y objetivos | OKR | Fase 4 · Talento | Story | pendiente | — |  |
| OKR-2 | Resultados clave y avance | OKR | Fase 4 · Talento | Story | pendiente | — |  |
| OKR-3 | Alineación jerárquica | OKR | Fase 4 · Talento | Story | pendiente | — |  |
| OKR-4 | Seguimiento periódico y tablero | OKR | Fase 4 · Talento | Story | pendiente | — |  |
| OKR-5 | Objetivos en la evaluación de desempeño | OKR | Fase 4 · Talento | Task | pendiente | — |  |
| CMP-1 | Catálogo de competencias y niveles | CMP | Fase 4 · Talento | Story | pendiente | — |  |
| CMP-2 | Perfil de competencias por puesto | CMP | Fase 4 · Talento | Story | pendiente | — |  |
| CMP-3 | Matriz de habilidades | CMP | Fase 4 · Talento | Story | pendiente | — |  |
| CMP-4 | Brechas de competencias | CMP | Fase 4 · Talento | Story | pendiente | — |  |
| FBK-1 | Reuniones 1:1 | FBK | Fase 4 · Talento | Story | pendiente | — |  |
| FBK-2 | Notas privadas y seguimiento de compromisos | FBK | Fase 4 · Talento | Story | pendiente | — |  |
| FBK-3 | Feedback continuo | FBK | Fase 4 · Talento | Story | pendiente | — |  |
| FBK-4 | Feedback y 1:1 en la evaluación de desempeño | FBK | Fase 4 · Talento | Task | pendiente | — |  |
| LMS-1 | Cursos, categorías y lecciones | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-2 | Contenidos de las lecciones | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-3 | Evaluaciones del curso | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-4 | Certificados | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-5 | Asignación de cursos | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-6 | Seguimiento del aprendizaje | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-7 | Plan anual de capacitación | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| LMS-8 | Planificado frente a ejecutado | LMS | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-1 | Planes de desarrollo individual | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-2 | Rutas de carrera | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-3 | Brechas frente al siguiente paso | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-4 | Posiciones críticas y sucesores | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-5 | Matriz de sucesión | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-6 | Matriz 9-box | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| CAR-7 | Sesión de revisión de talento | CAR | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-1 | Estructura de compensación | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-2 | Bandas salariales | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-3 | Posición del trabajador en la banda | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-4 | Campañas de revisión salarial | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-5 | Control de presupuesto de la campaña | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-6 | Aplicación de los aumentos aprobados | COM | Fase 5 · Desarrollo y compensación | Task | pendiente | — |  |
| COM-7 | Cuadro de categorías y política salarial | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| COM-8 | Reporte de brecha salarial | COM | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| BNF-1 | Catálogo de beneficios | BNF | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| BNF-2 | Reglas de elegibilidad | BNF | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| BNF-3 | Inscripción y dependientes | BNF | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| BNF-4 | Mis beneficios | BNF | Fase 5 · Desarrollo y compensación | Story | pendiente | — |  |
| BNF-5 | Beneficios con efecto en planilla | BNF | Fase 5 · Desarrollo y compensación | Task | pendiente | — |  |
| ENC-1 | Constructor de encuestas | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-2 | Tipos y plantillas de encuesta | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-3 | Distribución, segmentos y recordatorios | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-4 | Anonimato real con umbral mínimo | ENC | Fase 6 · Experiencia | Task | pendiente | — |  |
| ENC-5 | eNPS y tendencias | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-6 | Resultados de clima con mapa de calor | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-7 | Planes de acción con seguimiento | ENC | Fase 6 · Experiencia | Story | pendiente | — |  |
| ENC-8 | Encuestas automáticas por evento | ENC | Fase 6 · Experiencia | Task | pendiente | — |  |
| REC-1 | Valores e insignias | REC | Fase 6 · Experiencia | Story | pendiente | — |  |
| REC-2 | Reconocer a un compañero | REC | Fase 6 · Experiencia | Story | pendiente | — |  |
| REC-3 | Muro de reconocimientos | REC | Fase 6 · Experiencia | Story | pendiente | — |  |
| REC-4 | Reportes de reconocimiento | REC | Fase 6 · Experiencia | Story | pendiente | — |  |
| CIN-1 | Publicaciones | CIN | Fase 6 · Experiencia | Story | pendiente | — |  |
| CIN-2 | Segmentación de la audiencia | CIN | Fase 6 · Experiencia | Story | pendiente | — |  |
| CIN-3 | Confirmación de lectura | CIN | Fase 6 · Experiencia | Story | pendiente | — |  |
| CIN-4 | Cumpleaños, aniversarios y eventos | CIN | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-1 | Catálogo de solicitudes y formularios | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-2 | Tickets y estados | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-3 | SLA configurable | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-4 | Colas, asignación y respuestas tipo | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-5 | Base de conocimiento | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-6 | Buscador y artículos sugeridos | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| HSD-7 | Satisfacción y reportes de la mesa | HSD | Fase 6 · Experiencia | Story | pendiente | — |  |
| SST-1 | Accidentes, incidentes y enfermedades ocupacionales | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-2 | Matriz de riesgos IPERC | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-3 | Inspecciones y observaciones | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-4 | Medidas correctivas | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-5 | Exámenes médicos ocupacionales | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-6 | Capacitaciones y simulacros de SST | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-7 | Comité o supervisor de SST | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-8 | Registros obligatorios y plazos de conservación | SST | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| SST-9 | Tablero y estadísticas de SST | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-10 | Proceso electoral del comité de SST | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SST-11 | Sesiones, quórum y libro de actas | SST | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-1 | Canal público de denuncias | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-2 | Anonimato sin rastro técnico | DEN | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| DEN-3 | Código de seguimiento y comunicación con el denunciante | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-4 | Gestión del caso | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-5 | Investigadores y acceso restringido | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-6 | Procedimiento de hostigamiento sexual | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-7 | Comité de intervención y capacitación obligatoria | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DEN-8 | Comunicaciones a la autoridad y expediente del procedimiento | DEN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DIS-1 | Registro del caso disciplinario | DIS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DIS-2 | Imputación y descargos | DIS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DIS-3 | Medidas disciplinarias | DIS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| DIS-4 | Seguimiento e historial disciplinario | DIS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-1 | Inventario de activos | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-2 | Asignación y devolución con acta firmada | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-3 | Historial y activos en ingresos y salidas | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-4 | Matriz de EPP por puesto | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-5 | Entrega de EPP con firma y vencimientos | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ACT-6 | Uniformes y tallas | ACT | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-1 | Registro de gastos | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-2 | Categorías y políticas por puesto | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-3 | Aprobación de gastos | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-4 | Solicitud de viáticos y anticipo | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-5 | Rendición de viáticos | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| GAS-6 | Reembolso y contabilización | GAS | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-1 | Catálogo de obligaciones laborales | CUM | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| CUM-2 | Tablero de cumplimiento con semáforo | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-3 | Responsables, evidencias y calendario | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-4 | Pólizas de Vida Ley y SCTR | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-5 | Cuota de discapacidad y lactario | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-6 | Expediente de inspección SUNAFIL | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-7 | Bandeja de casilla electrónica | CUM | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| CUM-8 | Libro inalterable de cumplimiento encadenado por huella | CUM | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| PRI-1 | Consentimientos y finalidades | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| PRI-2 | Solicitudes de derechos del titular | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| PRI-3 | Banco de datos y registro de tratamientos | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| PRI-4 | Registro de incidentes de seguridad | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| PRI-5 | Evaluación de impacto | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| PRI-6 | Políticas de retención, anonimización y eliminación | PRI | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| PRI-7 | Registro de accesos y descargas de datos personales | PRI | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SIN-1 | Sindicatos y afiliación | SIN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SIN-2 | Cuota sindical en planilla | SIN | Fase 7 · Operación y cumplimiento | Task | pendiente | — |  |
| SIN-3 | Licencias sindicales | SIN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| SIN-4 | Convenios colectivos y cláusulas | SIN | Fase 7 · Operación y cumplimiento | Story | pendiente | — |  |
| ANA-1 | Capa de datos analítica | ANA | Fase 8 · Inteligencia | Task | pendiente | — |  |
| ANA-2 | Catálogo de indicadores | ANA | Fase 8 · Inteligencia | Task | pendiente | — |  |
| ANA-3 | Tablero ejecutivo | ANA | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ANA-4 | Filtros analíticos | ANA | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ANA-5 | Tableros personalizables por rol | ANA | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ANA-6 | Diversidad y distribución salarial con resguardo | ANA | Fase 8 · Inteligencia | Story | pendiente | — |  |
| RPT-1 | Centro de reportes predefinidos | RPT | Fase 8 · Inteligencia | Story | pendiente | — |  |
| RPT-2 | Constructor de reportes | RPT | Fase 8 · Inteligencia | Story | pendiente | — |  |
| RPT-3 | Permisos sobre datos sensibles en reportes | RPT | Fase 8 · Inteligencia | Task | pendiente | — |  |
| RPT-4 | Guardar, compartir y exportar | RPT | Fase 8 · Inteligencia | Story | pendiente | — |  |
| RPT-5 | Programación de reportes | RPT | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ALE-1 | Motor de alertas | ALE | Fase 8 · Inteligencia | Task | pendiente | — |  |
| ALE-2 | Catálogo de alertas | ALE | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ALE-3 | Explicación obligatoria y gestión de la alerta | ALE | Fase 8 · Inteligencia | Story | pendiente | — |  |
| ALE-4 | Riesgo de rotación explicable | ALE | Fase 8 · Inteligencia | Story | pendiente | — |  |
| IAR-1 | Capa de proveedor de IA | IAR | Fase 8 · Inteligencia | Task | pendiente | — |  |
| IAR-2 | Registro de uso de IA | IAR | Fase 8 · Inteligencia | Task | pendiente | — |  |
| IAR-3 | La IA respeta los permisos del usuario | IAR | Fase 8 · Inteligencia | Task | pendiente | — |  |
| IAR-4 | Redacción asistida | IAR | Fase 8 · Inteligencia | Story | pendiente | — |  |
| IAR-5 | Resúmenes de CV y de encuestas | IAR | Fase 8 · Inteligencia | Story | pendiente | — |  |
| IAR-6 | Consulta en lenguaje natural | IAR | Fase 8 · Inteligencia | Story | pendiente | — |  |
| IAR-7 | Explicación de métricas y detección de anomalías | IAR | Fase 8 · Inteligencia | Story | pendiente | — |  |
| IAR-8 | Salvaguardas contra decisiones automáticas | IAR | Fase 8 · Inteligencia | Task | pendiente | — |  |
| API-1 | API pública con tokens y scopes | API | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| API-2 | Portal de desarrolladores | API | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| API-3 | Límites de uso y versionado | API | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| API-4 | Webhooks | API | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| API-5 | Servidor MCP | API | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| INT-1 | Marco y catálogo de integraciones | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-2 | Conector contable y de ERP | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-3 | Conectores bancarios | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-4 | Conectores de relojes biométricos | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-5 | Inicio de sesión único (SSO) | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-6 | Calendario y correo | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| INT-7 | Conectores de selección y aprendizaje externos | INT | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WHA-1 | Vinculación de identidad y sesión en WhatsApp | WHA | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| WHA-2 | Menú conversacional y consultas | WHA | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WHA-3 | Boletas y constancias por WhatsApp | WHA | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WHA-4 | Solicitud de vacaciones por WhatsApp | WHA | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WHA-5 | Marcación por WhatsApp | WHA | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| PAI-1 | Contrato del paquete de país | PAI | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| PAI-2 | Conformidad del paquete Perú | PAI | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| PAI-3 | Paquete de un segundo país como prueba | PAI | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| PAI-4 | Grupos con empresas en varios países | PAI | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WLB-1 | Marca de la organización | WLB | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WLB-2 | Dominio personalizado | WLB | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WLB-3 | Correos con la marca del cliente | WLB | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| WLB-4 | Marca blanca según plan | WLB | Fase 9 · Ecosistema y expansión | Task | pendiente | — |  |
| EST-1 | Consola de socio | EST | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| EST-2 | Alta de empresas cliente y delegación | EST | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| EST-3 | Procesos de planilla en lote | EST | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| EST-4 | Tablero de obligaciones por cliente | EST | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| EST-5 | Portal de la empresa cliente | EST | Fase 9 · Ecosistema y expansión | Story | pendiente | — |  |
| QA-1 | Estrategia de pruebas y definición de hecho | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-2 | Recorrido de extremo a extremo de cimientos y Core HR | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-3 | Recorrido de extremo a extremo de tiempo | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-4 | Recorrido de extremo a extremo de nómina | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-5 | Pruebas doradas de planilla validadas por especialista laboral | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-6 | Recorrido de extremo a extremo de talento, desarrollo y compensación | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-7 | Recorrido de extremo a extremo de experiencia, operación y cumplimiento | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-8 | Recorrido de extremo a extremo de inteligencia y ecosistema | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-9 | Pruebas de concurrencia | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-10 | Accesibilidad | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-11 | Pruebas de carga con 50 000 colaboradores | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-12 | Revisión de seguridad | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-13 | Verificación de protección de datos | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-14 | Migración de los datos de una empresa | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-15 | Material de ayuda | QA | Transversal · Calidad | Task | pendiente | — |  |
| QA-16 | Salida a producción | QA | Transversal · Calidad | Task | pendiente | — |  |
<!-- TABLE_END -->

**Total:** 480 actividades
