#F 0 | Cimientos

#E TEN | Multiempresa y aislamiento
@countries P cat | Catálogo de países (ISO 3166-1 alfa-2) que usan los paquetes de país y las razones sociales.
  code text! CHECK (char_length(code) = 2) ; name ; default_currency char(3) ; default_locale text ; uniq code
@currencies P cat | Catálogo de monedas (ISO 4217) admitidas en montos y compensaciones.
  code char(3)! ; name ; symbol text ; decimals smallint=2 ; uniq code
@tenants P main soft lock | Organización cliente (tenant). Raíz del aislamiento: toda tabla de organización referencia su id. beta_groups: grupos beta a los que pertenece, para las banderas de funcionalidad (SAS-7).
  slug text! ; name ; status enum(trial,active,suspended,blocked,cancelled,grace_period,deleted)=trial ; country_code ~> countries.code
  default_currency char(3)! ; default_locale text=es ; timezone text=America/Lima ; is_demo ; is_partner_managed
  trial_ends_at ; suspended_at ; suspension_reason ; storage_region ; contact_name ; contact_email ; contact_phone ; cancelled_at ; retention_until date
  beta_groups text[] ; uniq slug
@tenant_domains P | Dominios y subdominios por los que se resuelve la organización (incluye dominio propio de marca blanca).
  tenant_id => tenants ; domain text! ; kind enum(subdomain,custom)=subdomain ; verified_at ; is_primary ; uniq domain
@tenant_status_events P imm | Historial inalterable de cambios de estado de la organización (pruebas, suspensiones, bloqueos, reactivaciones).
  tenant_id => tenants ; from_status ; to_status text! ; reason ; platform_user_id ?=> platform_users ; occurred_at ts=now

#E SAS | Consola SaaS: organizaciones, planes, módulos, límites, feature flags, soporte y salud
@modules P cat main | Módulos comerciales que una organización puede contratar; agrupan épicas del backlog. is_published: el módulo está a la venta (WEB-2: el sitio lo muestra «Disponible»; si no, «Próximamente» con lista de espera); lo cambia la consola.
  code ; name ; phase smallint! ; is_core ; epic_codes text[]! ; description ; is_published bool=f ; published_at ; uniq code
@module_dependencies P cat | Dependencias entre módulos comerciales (un módulo no se activa sin sus requisitos).
  module_code ~> modules.code ; depends_on_code ~> modules.code ; is_required bool=t ; uniq module_code, depends_on_code
@plans P main soft lock | Planes comerciales definidos por el superadministrador (nombres libres, duplicables).
  code ; name ; description ; status enum(draft,active,inactive)=draft ; billing_cycle enum(monthly,annual)=monthly
  price money ; currency cur? ; trial_days integer ; is_public bool=t ; duplicated_from_plan_id ?=> plans
  version smallint=1 ; superseded_by_plan_id ?=> plans ; uniq code, version
@plan_modules P | Módulos incluidos en cada plan.
  plan_id => plans ; module_code ~> modules.code ; uniq plan_id, module_code
@plan_limits P | Límites por plan (colaboradores, administradores, almacenamiento, razones sociales, llamadas de API).
  plan_id => plans ; limit_code text! ; limit_value numeric(14,2) ; is_unlimited ; uniq plan_id, limit_code
@plan_features P | Funcionalidades puntuales habilitadas por plan.
  plan_id => plans ; feature_code text! ; enabled bool=t ; uniq plan_id, feature_code
@addons P soft | Complementos contratables sobre un plan (módulo extra, ampliación de límite, funcionalidad o servicio).
  code ; name ; kind enum(module,limit,feature,service)! ; web_product_code ; limit_code ; limit_increment numeric(14,2)
  price money ; currency cur? ; billing_cycle enum(monthly,annual,one_time)=monthly ; status ; uniq code
@addon_modules P | Módulos que habilita un complemento de tipo módulo (un producto de la web puede habilitar varios, docs/01 §4.2).
  addon_id => addons ; module_code ~> modules.code ; uniq addon_id, module_code
@subscriptions P main lock | Suscripción de una organización a un plan, con su ciclo, estado y periodo de prueba.
  tenant_id => tenants ; plan_id => plans ; status enum(trialing,active,past_due,suspended,cancelled,expired)=trialing
  billing_cycle enum(monthly,annual)=monthly ; price money ; currency cur? ; starts_on date! ; ends_on ; trial_ends_on
  cancelled_at ; cancel_reason ; license_key ; expired_at ts ; uniq tenant_id
@subscription_addons P | Complementos contratados dentro de una suscripción.
  subscription_id => subscriptions ; addon_id => addons ; quantity integer=1 ; price money ; currency cur? ; starts_on date! ; ends_on
@saas_invoices P lock | Facturación del SaaS a la organización cliente (no es la nómina del cliente). SAS-11: el cargo del periodo nace en borrador con los precios guardados en la suscripción; emitirlo lo envía como comprobante electrónico al proveedor OSE o PSE (adaptador) y solo queda emitido con la constancia de aceptación; uno rechazado queda observado. La tasa del impuesto es la del parámetro legal vigente al emitir.
  tenant_id => tenants ; subscription_id ?=> subscriptions ; number text! ; status enum(draft,observed,issued,paid,overdue,void)=draft
  period_start date! ; period_end date! ; subtotal money! ; tax money=0 ; total money! ; currency cur ; issued_at ; due_on ; paid_at
  external_ref ; uniq number
  tax_rate numeric(7,4) ; tax_treatment enum(taxed,export_exempt) ; amount_paid money=0 ; buyer_tax_id ; buyer_name
  einvoice_status enum(not_sent,pending,accepted,rejected)=not_sent ; einvoice_series ; einvoice_number ; einvoice_ticket ; einvoice_hash
  einvoice_message ; einvoice_accepted_at ts ; einvoice_attempts integer=0 ; reminded_at ts ; overdue_at ts ; voided_at ts ; void_reason
  created_by_platform_user_id ?=> platform_users
@saas_invoice_lines P | Líneas de la factura SaaS.
  saas_invoice_id => saas_invoices ; description text! ; quantity numeric(14,2)=1 ; unit_price money! ; amount money! ; currency cur
@saas_payments P | SAS-11 · Pagos de la organización a un comprobante del SaaS, registrados a mano en la consola o llegados por la pasarela (adaptador de cobro). Un pago no se borra: se anula con motivo.
  tenant_id => tenants ; saas_invoice_id => saas_invoices ; amount money! ; currency cur ; paid_on date!
  method enum(transfer,card,wallet,cash,other)=transfer ; reference ; source enum(manual,gateway)=manual ; gateway_ref
  status enum(confirmed,reversed)=confirmed ; recorded_by_platform_user_id ?=> platform_users ; reversed_at ts ; reverse_reason
@tenant_modules main | Módulos activos por organización (resultado de plan, complemento o activación manual). Condiciona menú, API y permisos.
  module_code ~> modules.code ; status enum(active,inactive,trial)=active ; source enum(plan,addon,manual,trial)=plan
  activated_at ; deactivated_at ; read_only_until ts ; uniq module_code
@tenant_limits | Límites efectivos de la organización (plan + complementos + ajustes). warned_at / reached_at marcan el aviso ya enviado al acercarse o llegar al tope (SAS-6, RN-SAS-02); se limpian cuando el consumo baja.
  limit_code text! ; limit_value numeric(14,2) ; is_unlimited ; source enum(plan,addon,override)=plan ; warned_at ts ; reached_at ts ; uniq limit_code
@tenant_usage_counters | Consumo medido por organización y periodo para comparar con límites (SAS-6): colaboradores, administradores, empresas y almacenamiento en MB guardan una foto por día (period_start = día); las llamadas de API acumulan por mes (period_start = primer día del mes).
  metric_code text! ; period_start date! ; value numeric(18,2)=0 ; measured_at ts! ; uniq metric_code, period_start
@feature_flags P cat | Banderas de funcionalidad para liberar funciones de forma gradual.
  code ; name ; description ; default_enabled ; is_beta ; status enum(active,archived)=active ; uniq code
@feature_flag_rules P cat | Reglas de activación de una bandera por plan, país, grupo beta o porcentaje. Orden de evaluación (RN-SAS-07): organización, plan, grupo beta, país; después el porcentaje gradual y, al final, el valor por defecto.
  feature_flag_id => feature_flags ; target_kind enum(plan,country,beta_group,percentage)! ; target_value text! ; enabled bool=t
  uniq feature_flag_id, target_kind, target_value
@tenant_feature_flags | Activación explícita de una bandera para una organización (prevalece sobre las reglas).
  feature_flag_code ~> feature_flags.code ; enabled boolean! ; reason ; set_by_platform_user_id ?=> platform_users ; expires_at
  uniq feature_flag_code
@platform_users P soft lock | Usuarios de la consola del propietario del SaaS, separados por completo de los usuarios de las organizaciones.
  email text! ; full_name text! ; password_hash text #S ; role enum(superadmin,support,billing,readonly)=support ; mfa_secret_enc
  status enum(active,blocked)=active ; last_login_at ; mfa_confirmed_at ; mfa_last_used_step bigint ; failed_attempts integer=0
  locked_until ts ; uniq email
@platform_sessions P | Sesiones de la consola SaaS (SAS-1): cookie propia, segundo factor siempre obligatorio, revocables.
  platform_user_id => platform_users ; session_hash text! #S ; ip inet ; user_agent ; mfa_passed bool=f ; last_seen_at
  expires_at ts! ; revoked_at ; uniq session_hash
@platform_audit_events P imm part | Auditoría inalterable de la consola SaaS (quién, qué, sobre qué organización, antes y después).
  platform_user_id ?=> platform_users ; tenant_id ?=> tenants ; action text! ; entity_type ; entity_id uuid ; before_data j ; after_data j
  ip inet ; user_agent ; occurred_at ts=now
@platform_settings P | Configuraciones globales de la plataforma.
  key text! ; value j! ; description ; uniq key
@support_tickets P lock | Incidencias de soporte abiertas por las organizaciones ante el proveedor del SaaS.
  tenant_id => tenants ; opened_by_email ; subject text! ; body ; status enum(new,in_progress,waiting,resolved,closed)=new
  priority enum(low,normal,high,urgent)=normal ; assigned_platform_user_id ?=> platform_users ; resolved_at
@support_ticket_messages P | Mensajes de una incidencia de soporte.
  support_ticket_id => support_tickets ; author_kind enum(platform,tenant)! ; author_ref ; body text!
@support_access_grants | Autorización explícita y temporal de la organización para que soporte acceda a sus datos.
  granted_by_user_id -> users ; platform_user_id ?=> platform_users ; reason text! ; access_level enum(read_only,full)=read_only
  starts_at ts! ; expires_at ts! ; revoked_at ; revoked_by_user_id ?-> users
@support_sessions | Sesiones de soporte con suplantación (SAS-8, RN-SAS-08): motivo, usuario suplantado, vencimiento propio, ingreso por enlace de un solo uso y marca «soporte» en la auditoría de la organización. Sin campos sensibles salvo autorización «full» vigente del cliente.
  platform_user_id => platform_users ; agent_name text! ; target_user_id uuid! ; reason text! ; ticket_reference
  status enum(pending,active,ended,expired)=pending ; allow_sensitive bool=f ; support_access_grant_id uuid ; entry_token_hash text #S
  entry_used_at ts ; user_session_id uuid ; expires_at ts! ; started_at ts ; ended_at ts ; ended_by_platform_user_id ?=> platform_users
  idx expires_at ; idx user_session_id
@backup_runs P | Respaldos automáticos y manuales, con estado, tamaño y retención.
  tenant_id ?=> tenants ; kind enum(automatic,manual)! ; coverage enum(platform,tenant)! ; status enum(running,completed,failed,expired)=running
  storage_key ; size_bytes bigint ; checksum ; started_at ts! ; finished_at ; retention_until date
  requested_by_platform_user_id ?=> platform_users ; error
@backup_restores P | Solicitudes de restauración con aprobación; por defecto se restaura a un entorno aislado.
  backup_run_id => backup_runs ; tenant_id ?=> tenants ; status enum(requested,approved,running,completed,failed,cancelled)=requested
  target enum(sandbox,production)=sandbox ; kind enum(manual,scheduled_test)=manual ; requested_by_platform_user_id ?=> platform_users
  approved_by_platform_user_id ?=> platform_users ; approved_at ts ; reason text! ; verification j ; started_at ; finished_at ; error
@platform_notices P cat | Avisos de la plataforma (mantenimiento, incidencias) para todas las organizaciones o las seleccionadas, con vigencia (SAS-13).
  title text! ; message text! ; severity enum(info,warning,critical)=info ; audience enum(all,selected)=all ; tenant_ids j
  starts_at ts! ; ends_at ts ; cancelled_at ts ; created_by_platform_user_id ?=> platform_users ; idx starts_at
@platform_incidents P | Incidentes de servicio de la plataforma (salud).
  title ; severity enum(minor,major,critical)! ; status enum(open,monitoring,resolved)=open ; description ; started_at ts! ; resolved_at
  affected_tenant_id ?=> tenants
@platform_usage_snapshots P | Indicadores diarios del tablero SaaS (organizaciones activas, usuarios, colaboradores administrados, almacenamiento, uso por módulo).
  snapshot_date date! ; metric_code text! ; dimension text= ; value numeric(18,2)! ; uniq snapshot_date, metric_code, dimension

#E WEB | Sitio comercial gestiahr.com: productos, leads, demos, registros, chatbot y libro de reclamaciones
#N Páginas, blog, comentarios, popups y testimonios viven en el CMS del sitio (`apps/site`), no en esta base. Aquí solo lo que la plataforma necesita para vender y crear organizaciones.
@web_products P cat main | Productos de la web (Personas, Tiempo, Planilla, Talento, Formación y Suite) según docs/01 §4.1; agrupan módulos comerciales.
  code ; name ; acronym text! ; slug text! ; tagline ; is_suite bool=f ; sold_standalone bool=f ; sort_order integer=0 ; status enum(draft,published,hidden)=published ; uniq code ; uniq slug
@web_product_modules P cat | Módulos comerciales que agrupa cada producto de la web.
  web_product_id => web_products ; module_code ~> modules.code ; uniq web_product_id, module_code
@web_leads P lock | Leads del sitio: demo, contacto, partner, boletín, calculadora, lista de espera o registro abandonado.
  kind enum(demo,contact,partner,newsletter,calculator,waitlist,signup)! ; full_name ; email text! ; phone ; company_name ; ruc ; employees_range
  web_product_id ?=> web_products ; module_code ?~> modules.code ; source_page ; utm j ; consent_purpose text! ; consent_at ts!
  status enum(new,contacted,qualified,converted,discarded)=new ; assigned_platform_user_id ?=> platform_users ; tenant_id ?=> tenants ; notified_at ; notes
  message ; idempotency_key ; uniq idempotency_key
  idx kind, status ; idx email
@web_demo_bookings P lock | Demos agendadas desde el sitio.
  web_lead_id => web_leads ; starts_at ts! ; ends_at ts! ; channel enum(video,phone)=video ; host_platform_user_id ?=> platform_users ; meeting_url
  manage_token_hash text! ; status enum(scheduled,rescheduled,cancelled,done,no_show)=scheduled ; reminded_at ; uniq manage_token_hash
@web_signups P lock | Registros de prueba o suscripción en curso desde el sitio (cuenta, empresa, plan).
  token_hash text! ; email text! ; full_name ; ruc ; company_name ; employees_range ; labor_regime ; subdomain ; plan_id ?=> plans ; addon_codes text[]
  billing_cycle enum(monthly,annual)=monthly ; step enum(account,company,plan,payment,done)=account ; tenant_id ?=> tenants ; web_lead_id ?=> web_leads ; completed_at
  verification_code_hash ; code_expires_at ; code_attempts integer=0 ; email_verified_at ; locale ; idempotency_key ; uniq idempotency_key
  uniq token_hash ; idx email
@web_messages P | Correos del sitio (código de verificación, confirmación y recordatorio de demo, aviso de lista de espera) en cola, enviados por el puerto de correo y reintentables (WEB-5 a WEB-7). Hace de outbox de la plataforma: no hay organización.
  kind text! ; to_email text! ; locale ; subject text! ; body text! ; ref_type text! ; ref_id uuid! ; status enum(pending,sent,failed)=pending ; attempts integer=0 ; last_error ; sent_at ; provider_ref
  idx status ; idx ref_type, ref_id
@web_chat_sessions P | Conversaciones del chatbot comercial (solo contenido público), con borrado por retención.
  visitor_ref text! ; started_at ts! ; ended_at ; transcript j ; handoff enum(none,sales,whatsapp,lead)=none ; web_lead_id ?=> web_leads ; delete_after_on
@web_complaints P lock | Libro de reclamaciones virtual del sitio.
  code text! ; kind enum(claim,complaint)! ; consumer_name text! ; consumer_doc ; consumer_email text! ; consumer_phone ; detail text! ; request text!
  status enum(received,in_review,answered,closed)=received ; answer ; answered_at ; due_on ; assigned_platform_user_id ?=> platform_users ; uniq code
#E PLT | Plataforma técnica: archivos, eventos, idempotencia
@stored_files soft | Archivo en almacenamiento compatible con S3 (solo metadatos; el binario vive fuera de la base).
  storage_key text! ; original_name text! ; mime_type text! ; size_bytes bigint! ; sha256 text!
  classification enum(public,internal,confidential,restricted)=internal ; uploaded_by_user_id ?-> users
  scan_status enum(pending,clean,infected,skipped)=pending ; retention_until date ; uniq storage_key
@attachments | Vínculo polimórfico entre un archivo y cualquier registro.
  stored_file_id -> stored_files ; subject_type text! ; subject_id uuid! ; label ; idx subject_type, subject_id
@comments soft | Comentarios y notas sobre cualquier registro (candidato, solicitud, caso), con marca de privado.
  subject_type text! ; subject_id uuid! ; author_user_id -> users ; body text! ; is_private ; idx subject_type, subject_id
@tags | Etiquetas reutilizables (base de talento, documentos, solicitudes).
  namespace text=general ; name ; color ; uniq namespace, name
@taggings | Asignación de etiquetas a registros.
  tag_id -> tags ; subject_type text! ; subject_id uuid! ; uniq tag_id, subject_type, subject_id ; idx subject_type, subject_id
@number_sequences | Correlativos por organización y razón social (boletas, casos, solicitudes).
  code ; legal_entity_id ?-> legal_entities ; prefix ; next_value bigint=1 ; padding smallint=6
  reset_policy enum(never,yearly,monthly)=never ; raw UNIQUE NULLS NOT DISTINCT (tenant_id, code, legal_entity_id)
@outbox_events main | Eventos de dominio pendientes de publicar (patrón outbox): se escriben en la misma transacción que el cambio y los despacha un proceso aparte.
  event_type text! ; aggregate_type text! ; aggregate_id uuid! ; payload j! ; occurred_at ts=now
  status enum(pending,processing,published,failed,dead)=pending ; attempts integer=0 ; next_attempt_at ; published_at ; last_error
  correlation_id uuid ; idx status, next_attempt_at ; idx aggregate_type, aggregate_id
@inbox_events | Eventos externos ya recibidos, para no procesar dos veces el mismo mensaje.
  source text! ; external_id text! ; event_type ; payload j ; processed_at ; uniq source, external_id
@idempotency_keys | Claves de idempotencia de peticiones con efecto (API, pagos, envíos): la repetición devuelve la misma respuesta.
  key text! ; user_id ?-> users ; api_token_id ?-> api_tokens ; request_method text! ; request_path text! ; request_hash text!
  response_status integer ; response_body j ; locked_until ts ; expires_at ts! ; uniq key
@processed_events ao | Eventos de dominio ya procesados por cada consumidor interno: garantiza el efecto una sola vez por consumidor.
  consumer text! ; event_id uuid! ; processed_at ts=now ; uniq consumer, event_id
@search_documents | Proyección para la búsqueda global, alimentada por eventos; se filtra por organización, permiso del tipo y claves de alcance.
  entity_type text! ; entity_id uuid! ; title ; subtitle ; body ; scope_keys j={} ; required_permission ?~> permissions.code
  search_vector tsvector ; uniq entity_type, entity_id
@async_operations | Operaciones largas en cola visibles para el usuario, con avance y resultado.
  kind text! ; status enum(queued,running,completed,failed,cancelled)=queued ; progress smallint=0
  requested_by_user_id ?-> users ; result j ; error ; started_at ; finished_at

#E SEG | Identidad, roles, permisos por campo y alcance
@users main soft lock | Cuenta de acceso (C-01). Puede o no estar ligada a una persona; el correo no es obligatorio (C-09).
  person_id ?-> people ; display_name text! ; email text #S ; locale ; timezone ; status enum(invited,active,locked,disabled,alumni)=invited
  password_hash text #S ; password_changed_at ; must_change_password bool=f ; failed_attempts integer=0 ; locked_until ts
  last_login_at ; is_service_account ; uniq person_id
@user_identities | Identificadores con los que un usuario inicia sesión: correo, documento de identidad, teléfono, usuario o SSO (C-09).
  user_id -> users ; kind enum(email,national_id,phone,username,sso)! ; identifier_hash text! ; identifier_enc text!
  verified_at ; is_primary ; bounced_at ; bounce_reason ; uniq kind, identifier_hash
@user_mfa_factors | Factores de segundo paso por usuario.
  user_id -> users ; kind enum(totp,sms,email,whatsapp,webauthn)! ; secret_enc ; label ; confirmed_at ; last_used_at ; disabled_at
@user_mfa_recovery_codes | Códigos de respaldo del segundo factor (SEG-3): de un solo uso, guardados como HMAC.
  user_id -> users ; code_hash text! #S ; used_at
@password_history | Hashes de claves anteriores para impedir reutilizarlas (SEG-5, parámetro seguridad.clave_historial).
  user_id -> users ; password_hash text! #S
@user_tokens | Tokens de un solo uso: invitación, clave temporal (OTP), restablecimiento, verificación y OTP de firma.
  user_id -> users ; purpose enum(invite,otp_login,password_reset,email_verify,phone_verify,signature_otp)! ; token_hash text!
  channel enum(email,sms,whatsapp,in_app) ; expires_at ts! ; consumed_at ; attempts integer=0 ; uniq token_hash
@user_sessions | Sesiones activas, revocables, con marca de suplantación por soporte.
  user_id -> users ; session_hash text! ; ip inet ; user_agent ; device_label ; mfa_passed bool=f ; last_seen_at
  expires_at ts! ; revoked_at ; impersonated_by_platform_user_id ?=> platform_users ; uniq session_hash
@user_quick_unlocks | Reingreso rápido en un teléfono (ESS-6): token de dispositivo (solo su SHA-256, en cookie HttpOnly) más PIN con hash; caduca, se olvida tras varios PIN errados y se revoca.
  user_id -> users ; device_token_hash text! ; pin_hash text! #S ; device_label ; mfa_passed bool=f ; failed_attempts integer=0
  last_used_at ; expires_at ts! ; revoked_at ; uniq device_token_hash
@login_attempts ao part | Intentos de inicio de sesión (éxito o fallo) para bloqueo y detección de abuso.
  identifier_hash ; user_id ?-> users ; succeeded boolean! ; failure_reason ; ip inet ; user_agent ; occurred_at ts=now
  idx identifier_hash, occurred_at
@security_policies lock | Política de seguridad de la organización: contraseñas, bloqueo, sesiones, segundo paso y rangos de IP.
  password_min_length smallint=10 ; password_require_mixed bool=t ; password_expiry_days integer ; password_history smallint=5
  max_failed_attempts smallint=5 ; lockout_minutes integer=15 ; session_idle_minutes integer=30 ; session_absolute_hours integer=12
  mfa_requirement enum(off,optional,admins,all)=optional ; allowed_ip_ranges cidr[] ; allow_national_id_login bool=t
  download_watermark bool=f ; raw UNIQUE (tenant_id)
@system_roles P cat | Catálogo de los roles de sistema que se copian a cada organización (y el rol de la consola SaaS).
  code ; name ; description ; realm enum(platform,tenant)=tenant ; mfa_required_default bool=f ; uniq code
@permissions P cat main | Catálogo global de permisos modulo.recurso.accion, incluidos los permisos por campo sensible (C-10).
  code ; module_code ~> modules.code ; resource text! ; action text! ; kind enum(action,field)=action ; is_sensitive
  description ; uniq code
@system_role_permissions P cat | Matriz por defecto rol de sistema × permiso × alcance; plantilla que usa seed_tenant_defaults.
  role_code ~> system_roles.code ; permission_code ~> permissions.code
  scope enum(platform,organization,legal_entity,site,org_unit,team,own,assigned)! ; uniq role_code, permission_code
@roles main soft lock | Roles de la organización: los de sistema (copiados de la plantilla) y los personalizados.
  code ; name ; description ; is_system ; system_role_code ?~> system_roles.code ; mfa_required boolean ; uniq code
@role_permissions | Permisos concedidos a un rol, con el alcance por defecto (toda la organización, empresa, sede, unidad, equipo, propio).
  role_id -> roles ; permission_code ~> permissions.code
  scope enum(organization,legal_entity,site,org_unit,team,own,assigned)=organization ; uniq role_id, permission_code
@user_roles | Roles asignados a un usuario, con vigencia opcional y alcance que puede restringir el del rol.
  user_id -> users ; role_id -> roles ; scope_override enum(organization,legal_entity,site,org_unit,team,own,assigned)
  valid_from date ; valid_to date ; granted_by_user_id ?-> users ; uniq user_id, role_id
@user_role_scopes | Unidades concretas (empresa, sede o unidad) a las que se limita una asignación de rol.
  user_role_id -> user_roles ; legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units
  check num_nonnulls(legal_entity_id, site_id, org_unit_id) = 1

#E AUD | Auditoría, actividad y accesos a datos sensibles
@audit_events imm main part | Auditoría de negocio de solo inserción (C-07): quién, qué, cuándo, desde dónde, valor anterior y nuevo.
  actor_user_id ?-> users ; actor_kind enum(user,api_token,system,platform_support,ai_assistant)=user ; action text!
  entity_type text! ; entity_id uuid ; before_data j #S ; after_data j #S ; changed_fields text[] ; reason ; ip inet ; user_agent
  request_id uuid ; occurred_at ts=now ; chain_seq bigint ; prev_hash ; row_hash ; idx entity_type, entity_id ; idx occurred_at ; idx chain_seq
@audit_chain_heads | Cabeza de la cadena de hash de la auditoría por organización (AUD-5): último número de secuencia y último hash. Solo la escribe el trigger.
  last_seq bigint=0 ; last_hash ; raw UNIQUE (tenant_id)
@audit_integrity_checks ao | Resultado de cada verificación programada de la cadena de hash de la auditoría (AUD-5).
  status enum(ok,failed)! ; checked_rows integer=0 ; archived_rows integer=0 ; unchained_rows integer=0 ; first_seq bigint ; last_seq bigint
  issues j ; started_at ts! ; finished_at ts ; idx started_at
@audit_archives | Manifiesto del archivado en frío de la auditoría por mes (AUD-5): rango de la cadena, huella del archivo y plazo de consulta.
  period_month date! ; from_seq bigint ; to_seq bigint ; row_count integer! ; first_prev_hash ; last_hash ; storage_disk text! ; storage_key text!
  sha256 text! ; size_bytes bigint ; archived_at ts=now ; purge_after date! ; purged_at ts ; uniq period_month
@activity_events ao part | Actividad del usuario (navegación y acciones no sensibles), separada de la auditoría y de los logs técnicos.
  user_id ?-> users ; activity text! ; subject_type ; subject_id uuid ; metadata j ; occurred_at ts=now ; idx occurred_at
@data_access_logs imm part | Registro inalterable de cada acceso a datos sensibles (sueldo, salud, denuncias, sanciones, biometría).
  user_id ?-> users ; access_kind enum(view,export,download,print,api,ai)!
  data_category enum(salary,health,complaint,disciplinary,biometric,bank_account,identity_document,union,other)!
  subject_person_id ?-> people ; entity_type ; entity_id uuid ; fields text[] ; purpose ; ip inet ; occurred_at ts=now

#E UI | Sistema de diseño y armazón (preferencias y vistas guardadas)
@user_preferences | Preferencias de interfaz por usuario (tema claro/oscuro, idioma, densidad, inicio).
  user_id -> users ; theme enum(light,dark,system)=system ; locale ; density enum(comfortable,compact)=comfortable
  home_layout j ; settings j={} ; uniq user_id
@saved_views soft | Vistas guardadas de tablas (columnas, filtros, orden), propias o compartidas.
  owner_user_id -> users ; resource text! ; name ; config j! ; is_shared ; is_default

#E WFL | Motor de aprobaciones y workflows
@workflow_definitions main soft lock | Definición de un flujo reutilizable: aprobación, automatización o proceso.
  code ; name ; kind enum(approval,automation,process)=approval ; subject_type ; description
  status enum(draft,active,inactive,archived)=draft ; current_version_id ?-> workflow_definition_versions ; uniq code
@workflow_definition_versions | Versión publicada de un flujo; las instancias en curso siguen con la versión con la que empezaron.
  workflow_definition_id -> workflow_definitions ; version integer! ; graph j ; published_at ; published_by_user_id ?-> users
  uniq workflow_definition_id, version
@workflow_steps | Nodos del flujo: inicio, condición, aprobación, acción, notificación, espera y fin.
  workflow_definition_version_id -> workflow_definition_versions ; key text! ; name
  kind enum(start,condition,approval,action,notification,wait,end)! ; sort_order
  approver_kind enum(manager,manager_of_manager,role,user,position,org_unit_head,dynamic) ; approver_role_id ?-> roles
  approver_user_id ?-> users ; approval_mode enum(sequential,parallel_all,parallel_any)=sequential ; condition j ; action_config j
  sla_hours integer ; escalation_config j ; uniq workflow_definition_version_id, key
@workflow_transitions | Aristas entre nodos, con condición opcional.
  workflow_definition_version_id -> workflow_definition_versions ; from_step_id -> workflow_steps ; to_step_id -> workflow_steps
  condition j ; label ; sort_order
@workflow_instances main lock | Ejecución de un flujo sobre un registro concreto (una solicitud de vacaciones, un gasto, una posición).
  workflow_definition_version_id -> workflow_definition_versions ; subject_type text! ; subject_id uuid!
  status enum(running,approved,rejected,cancelled,completed,failed,pending,escalated)=running ; started_by_user_id ?-> users
  current_step_id ?-> workflow_steps ; context j={} ; started_at ts=now ; finished_at ; idx subject_type, subject_id ; idx status
@workflow_tasks main lock | Tarea de aprobación pendiente de una persona o rol; alimenta la bandeja de pendientes y el centro de aprobaciones.
  workflow_instance_id -> workflow_instances ; workflow_step_id -> workflow_steps ; assignee_user_id ?-> users
  assignee_role_id ?-> roles ; delegated_from_user_id ?-> users
  status enum(pending,approved,rejected,skipped,expired,cancelled)=pending ; due_at ; resume_at ; completed_at ; sequence integer=1
  idx assignee_user_id, status
@workflow_decisions imm | Decisión tomada sobre una tarea (aprobar, rechazar, devolver, delegar); inalterable.
  workflow_task_id -> workflow_tasks ; decided_by_user_id -> users ; decision enum(approve,reject,return,delegate,comment)!
  comment ; on_behalf_of_user_id ?-> users ; decided_at ts=now ; ip inet
@approval_delegations | Delegación temporal de aprobaciones de un usuario a otro.
  from_user_id -> users ; to_user_id -> users ; subject_type ; starts_on date! ; ends_on date! ; reason ; revoked_at
@automation_rules soft lock | Regla sin código CUANDO → SI → ENTONCES que reacciona a eventos de dominio.
  name ; trigger_event text! ; conditions j ; actions j! ; status enum(active,paused,draft)=draft
  workflow_definition_id ?-> workflow_definitions ; schedule_cron ; last_run_at ; next_run_at ; owner_user_id ?-> users ; failing_since ts
@automation_runs ao | Ejecuciones de reglas con su resultado y error (centro de automatizaciones).
  automation_rule_id -> automation_rules ; trigger_event_id uuid ; status enum(running,succeeded,failed,skipped,queued,waiting,cancelled)!
  started_at ts! ; finished_at ; log j ; error
@hr_tasks soft lock | Tareas internas de RRHH con responsable, fecha, prioridad y vínculo a un proceso.
  title ; description ; assignee_user_id ?-> users ; assignee_role_id ?-> roles ; created_by_user_id ?-> users ; subject_type ; subject_id uuid
  related_employment_id ?-> employments ; due_on ; priority enum(low,normal,high,urgent)=normal
  status enum(pending,in_progress,done,cancelled)=pending ; completed_at

#E NOT | Notificaciones y mensajería
@notification_templates soft | Plantillas de mensaje por código, canal e idioma. Las de WhatsApp requieren aprobación del proveedor (approval_status) antes de usarse; sin aprobación el mensaje sale por SMS.
  code ; channel enum(in_app,email,push,whatsapp,sms)! ; locale text=es ; subject ; body text! ; variables j ; is_system
  approval_status enum(not_required,draft,pending,approved,rejected)=not_required ; provider_template_ref ; approval_requested_at ; approval_decided_at ; rejection_reason
  uniq code, channel, locale
@notifications main | Notificación dentro de la aplicación para un usuario, enlazada al registro que la origina.
  recipient_user_id -> users ; category text! ; title ; body ; subject_type ; subject_id uuid ; action_url
  priority enum(low,normal,high)=normal ; read_at ; archived_at ; idx recipient_user_id, read_at
@notification_deliveries | Envío de una notificación o de un mensaje directo (código de ingreso, activación) por un canal externo (correo, push, WhatsApp, SMS) con su estado; idempotency_key evita enviar dos veces el mismo mensaje.
  notification_id ?-> notifications ; channel enum(email,push,whatsapp,sms)! ; recipient_address_enc
  status enum(queued,sent,delivered,failed,bounced,suppressed)=queued ; provider ; provider_message_id ; attempts integer=0
  sent_at ; delivered_at ; error ; purpose ; template_code ; fallback_from ; idempotency_key ; uniq idempotency_key
@notification_preferences | Preferencias del usuario por categoría y canal.
  user_id -> users ; category text! ; channel enum(in_app,email,push,whatsapp,sms)! ; enabled bool=t ; uniq user_id, category, channel
@push_devices | Dispositivos registrados para notificaciones push.
  user_id -> users ; platform enum(ios,android,web)! ; token_enc text! ; token_hash text! ; label ; last_seen_at ; revoked_at ; uniq token_hash
@message_channels lock | Configuración de cada canal de mensajería de la organización (proveedor, remitente, credenciales cifradas). El remitente propio de correo solo se usa verificado (NOT-3); mientras tanto sale el de la plataforma.
  channel enum(email,push,whatsapp,sms)! ; provider text! ; config_enc ; sender_identity ; status enum(active,inactive)=inactive
  verification_status enum(not_required,pending,verified,failed)=not_required ; verified_at ; verification_error
  uniq channel, provider

#E CFG | Configuración, parámetros, campos personalizados, i18n y multimoneda
@parameter_definitions P cat | Catálogo de parámetros configurables del producto, con tipo, valor por defecto y alcances admitidos.
  code ; module_code ~> modules.code ; name ; description ; data_type enum(boolean,integer,decimal,text,date,time,select,list,json)!
  default_value j ; allowed_scopes text[]! ; options j ; uniq code
@tenant_settings lock | Valor de un parámetro para la organización o para una empresa, sede o unidad, con vigencia opcional.
  parameter_code ~> parameter_definitions.code ; scope_kind enum(organization,legal_entity,site,org_unit)=organization
  legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units ; value j! ; valid_from date ; valid_to date
  raw UNIQUE NULLS NOT DISTINCT (tenant_id, parameter_code, legal_entity_id, site_id, org_unit_id, valid_from)
@custom_field_definitions main soft lock | Definición de un campo personalizado sobre una entidad (persona, relación laboral, candidato, activo...).
  entity_type text! ; module_code ?~> modules.code ; key text! ; label text!
  data_type enum(text,number,date,select,multiselect,checkbox,user,file,url,currency,percent)! ; options j ; is_required
  is_sensitive ; is_searchable ; visibility_permission_code ?~> permissions.code ; sort_order ; section ; validation j
  uniq entity_type, key
@custom_field_values | Valor (jsonb) de un campo personalizado para un registro concreto.
  custom_field_definition_id -> custom_field_definitions ; entity_type text! ; entity_id uuid! ; value j!
  uniq custom_field_definition_id, entity_id ; idx entity_type, entity_id
@catalogs | Listas configurables por la organización (motivos, parentescos, tipos).
  code ; name ; is_system ; uniq code
@catalog_items soft | Elementos de una lista configurable.
  catalog_id -> catalogs ; code ; label text! ; labels j ; sort_order ; is_active bool=t ; metadata j ; uniq catalog_id, code
@master_catalogs P cat | Catálogos maestros comunes (tipos de documento, países, ubigeo, bancos, motivos de cese). Los mantiene la plataforma sin desplegar; is_extensible permite que cada organización agregue elementos propios en catalogs con el mismo código.
  code ; name ; description ; is_extensible ; country_code ?~> countries.code ; source ; uniq code
@master_catalog_items P cat | Elementos de un catálogo maestro con código estable para integraciones y declaraciones (statutory_code), etiquetas por idioma y vigencia. is_verified = falso mientras no se contraste con la fuente oficial.
  master_catalog_id => master_catalogs ; code ; label text! ; labels j ; statutory_code ; parent_code ; sort_order ; metadata j ; is_verified ; vigcols ; uniq master_catalog_id, code
@translation_overrides | Textos de interfaz que la organización reemplaza sobre los archivos de traducción (es, en, pt).
  locale text! ; key text! ; value text! ; uniq locale, key
@exchange_rates | Tipos de cambio por fecha para consolidar montos en grupos con varias monedas.
  from_currency cur ; to_currency cur ; rate_date date! ; rate numeric(18,8)! ; source ; uniq from_currency, to_currency, rate_date

#F 1 | Core HR

#E ORG | Estructura organizacional
@legal_entities main soft lock | Empresa o razón social de la organización (C-01); empleador de las relaciones laborales.
  legal_name text! ; trade_name ; country_code ~> countries.code ; tax_id text! ; tax_regime ; economic_activity_code
  profit_sharing_sector ; currency cur ; timezone text! ; locale ; address ; district_code ; logo_file_id ?-> stored_files
  legal_representative_name ; legal_representative_document_enc ; started_operations_on ; status ; uniq country_code, tax_id
@sites main soft lock | Sede, sucursal o ubicación física; base de feriados, geocercas e indicadores por centro de trabajo.
  legal_entity_id ?-> legal_entities ; code ; name ; kind enum(headquarters,branch,plant,store,site,remote)=branch ; address
  district_code ; country_code ; latitude numeric(9,6) ; longitude numeric(9,6) ; geofence_radius_m integer ; timezone ; establishment_code
  holiday_calendar_id ?-> holiday_calendars ; is_risk_site ; status ; uniq code
  check establishment_code IS NULL OR country_code IS DISTINCT FROM 'PE' OR establishment_code ~ '^[0-9]{4}$'
  check (latitude IS NULL) = (longitude IS NULL) AND (latitude IS NULL OR latitude BETWEEN -90 AND 90) AND (longitude IS NULL OR longitude BETWEEN -180 AND 180)
  check geofence_radius_m IS NULL OR (geofence_radius_m > 0 AND latitude IS NOT NULL)
@org_unit_types soft lock | Tipos o niveles de unidad con los nombres que usa cada organización (gerencia, área, departamento, equipo…); rank ordena del nivel más alto al más bajo (ORG-3).
  code ; name ; rank smallint=0 ; status enum(active,archived)=active ; uniq code
@org_units main soft lock | Unidad organizativa jerárquica. Las columnas de estructura (superior, nombre, tipo, responsable, centro de costo, ruta) reflejan la versión vigente hoy; la historia y los cambios programados viven en org_unit_versions (ORG-3). valid_from / valid_to marcan la vida de la unidad.
  legal_entity_id ?-> legal_entities ; parent_id ?-> org_units ; code ; name
  org_unit_type_id ?-> org_unit_types ; head_position_id ?-> positions
  cost_center_id ?-> cost_centers ; path ; depth smallint ; valid_from date ; valid_to date ; status enum(active,archived)=active ; uniq code
@org_unit_versions lock | Estructura de una unidad con vigencia (ORG-3): superior, nombre, tipo, responsable y centro de costo desde una fecha. Mover o renombrar cierra la versión vigente e inserta otra; los reportes de periodos anteriores leen la versión de su fecha.
  org_unit_id -> org_units ; parent_id ?-> org_units ; name text! ; org_unit_type_id ?-> org_unit_types
  head_position_id ?-> positions ; cost_center_id ?-> cost_centers ; reason ; changed_by_user_id ?-> users ; vig org_unit_id
@cost_centers soft lock | Centro de costo para imputar planilla, gastos y horas. opened_on / closed_on delimitan su vigencia (closed_on = último día que admite imputaciones); cerrado no admite nuevas asignaciones.
  legal_entity_id ?-> legal_entities ; parent_id ?-> cost_centers ; code ; name ; accounting_code ; opened_on date ; closed_on date
  status enum(active,closed,inactive)=active ; uniq code
  check closed_on IS NULL OR opened_on IS NULL OR closed_on >= opened_on
@job_families soft lock | Familias de puestos (catálogo configurable; se archivan, no se borran).
  code ; name ; description ; status enum(active,inactive)=active ; uniq code
@job_levels soft lock | Niveles jerárquicos del catálogo de puestos (catálogo configurable; se archivan, no se borran).
  code ; name ; rank smallint! ; description ; status enum(active,inactive)=active ; uniq code
@job_categories soft lock | Categorías laborales; es el cuadro de categorías y funciones que exige la Ley 30709 (E-05).
  code ; name ; description ; functions ; responsibility_level ; requirements ; rank smallint ; status enum(active,inactive)=active ; uniq code
@job_profiles main soft lock | Puesto de catálogo (C-02), p. ej. «Analista Contable»; no es una plaza.
  code ; name ; job_family_id ?-> job_families ; job_level_id ?-> job_levels ; job_category_id ?-> job_categories
  mission ; functions ; requirements ; occupation_code ; is_risk_job
  trust_classification enum(none,trust,management)=none ; status enum(draft,active,inactive)=active ; uniq code
@job_profile_versions imm | Versiones inalterables de la descripción de un puesto de catálogo (misión, funciones y requisitos), ORG-5.
  job_profile_id -> job_profiles ; version integer! ; name text! ; mission ; functions ; requirements ; change_reason
  changed_by_user_id ?-> users ; uniq job_profile_id, version

#E POS | Posiciones y headcount
@positions main soft lock | Posición o plaza presupuestada (C-02): una silla concreta con puesto de catálogo, unidad, sede, centro de costo, posición jefe y banda de referencia (POS-1). Admite un ocupante, o tantos como seats si es compartida (RN-POS-02). Ocupada o vacante se deriva de las asignaciones vigentes (POS-2). valid_from / valid_to: vigencia de la plaza. is_implicit: la creó el sistema al registrar un ingreso sin posición con la política «contratar solo contra posición» desactivada (POS-6).
  code ; title ; job_profile_id -> job_profiles ; org_unit_id -> org_units ; site_id ?-> sites
  legal_entity_id -> legal_entities ; reports_to_position_id ?-> positions ; cost_center_id ?-> cost_centers
  salary_band_id ?-> salary_bands
  status enum(proposed,approved,open,filled,frozen,closed,recruiting)=proposed ; is_critical ; fte numeric(4,2)=1
  is_shared ; seats smallint=1 ; is_implicit bool=f ; valid_from date=today ; valid_to date
  budgeted_from date ; budgeted_to date ; budgeted_cost money #S ; currency cur?
  headcount_plan_line_id ?-> headcount_plan_lines ; position_request_id ?-> position_requests ; uniq code
  check seats >= 1 AND (is_shared OR seats = 1)
  check valid_to IS NULL OR valid_to >= valid_from
  check reports_to_position_id IS NULL OR reports_to_position_id <> id
@position_status_events imm | Historial inalterable de estados de una posición (aprobada, congelada, cerrada...) con la acción, el motivo y quién la hizo (POS-2).
  position_id -> positions ; action ; from_status ; to_status text! ; reason ; changed_by_user_id ?-> users ; effective_on date!
@headcount_plans soft lock | Plan de dotación por ejercicio.
  name ; fiscal_year integer! ; legal_entity_id ?-> legal_entities ; status enum(draft,in_review,approved,closed)=draft
@headcount_scenarios lock | Escenarios de un plan: actual, conservador, crecimiento o personalizado (POS-5). Copian la dotación vigente a base_as_of (líneas «baseline») y suman, congelan o quitan plazas sin tocar las posiciones reales (RN-POS-08) hasta aplicarse con aprobación: aplicar crea solicitudes de posición en borrador para lo agregado y congela o cierra las plazas marcadas (applied_summary).
  headcount_plan_id -> headcount_plans ; name ; kind enum(current,conservative,growth,custom)! ; is_baseline ; assumptions j
  legal_entity_id ?-> legal_entities ; base_as_of date! ; status enum(draft,in_review,approved,applied,archived)=draft
  created_by_user_id ?-> users ; approved_by_user_id ?-> users ; approved_at ts ; applied_at ts ; applied_summary j
@headcount_plan_lines | Dotación planificada por unidad y puesto dentro de un escenario, con costo proyectado. change: baseline (copia de una posición real), add (plaza nueva), freeze (congelar la posición real) o remove (cerrarla); position_request_id: solicitud creada al aplicar.
  headcount_scenario_id -> headcount_scenarios ; org_unit_id -> org_units ; job_profile_id -> job_profiles
  site_id ?-> sites ; planned_count integer! ; planned_start_on ; projected_cost money #S ; currency cur? ; notes
  position_id ?-> positions ; change enum(baseline,add,freeze,remove)=add ; title text ; position_request_id ?-> position_requests
@position_requests soft lock | Solicitud de posición (nueva, reemplazo o temporal) sujeta a aprobación antes de reclutar (POS-3, ME-04). Al aprobarse por el motor de aprobaciones crea sus posiciones en «aprobada» (positions.position_request_id). over_budget: al enviarla superaba el presupuesto disponible de la unidad (RN-POS-05).
  requested_by_user_id -> users ; legal_entity_id ?-> legal_entities ; org_unit_id -> org_units ; job_profile_id -> job_profiles ; site_id ?-> sites
  title text ; cost_center_id ?-> cost_centers ; reports_to_position_id ?-> positions ; salary_band_id ?-> salary_bands
  quantity integer=1 ; justification text! ; kind enum(new,replacement,temporary)=new ; replaces_employment_id ?-> employments
  estimated_cost money #S ; currency cur? ; status enum(draft,submitted,approved,rejected,cancelled,fulfilled)=draft
  workflow_instance_id ?-> workflow_instances ; needed_by date ; over_budget bool=f ; submitted_at ts ; decided_at ts ; decision_reason
  check quantity >= 1
@headcount_budgets soft lock | Presupuesto de dotación por unidad y periodo (POS-4): plazas y costo mensual presupuestados. Un solo presupuesto vigente por unidad a la vez (valid_from / valid_to); el tablero compara contra él las posiciones aprobadas, ocupadas, vacantes y congeladas.
  legal_entity_id -> legal_entities ; org_unit_id -> org_units ; budgeted_positions integer! ; monthly_cost money #S ; currency cur?
  notes ; check budgeted_positions >= 0 ; vig org_unit_id

#E COL | Colaboradores y ficha 360
@people main soft lock | Persona natural (C-01). No se duplica en reingresos ni entre empresas del grupo.
  first_names text! ; paternal_surname text! ; maternal_surname ; preferred_name ; birth_date date #S
  sex enum(female,male,unspecified) #S ; nationality_code ; marital_status enum(single,married,cohabiting,divorced,widowed) #S
  photo_file_id ?-> stored_files ; personal_email text #S ; mobile_phone text #S ; anonymized_at
@person_documents soft | Documentos de identidad de la persona; el número se guarda cifrado y con huella para búsqueda exacta.
  person_id -> people ; doc_type enum(dni,ce,passport,ptp,cpp,ruc,refugee_card,foreign_affairs_id,foreign_id,other)! ; issuing_country text! ; number_enc text! ; number_hash text!
  expires_on ; is_primary ; file_id ?-> stored_files ; uniq doc_type, issuing_country, number_hash
@person_addresses soft | Direcciones de la persona.
  person_id -> people ; kind enum(home,mailing,other)=home ; address_enc text! ; district_code ; country_code ; valid_from date
  is_current bool=t
@emergency_contacts soft | Contactos de emergencia.
  person_id -> people ; full_name text! #S ; relationship ; phone_enc text! ; sort_order
@person_bank_accounts soft lock sens | Cuentas de pago de haberes y depósito de CTS por relación laboral y finalidad, con vigencia (COL-8). Un cambio queda pendiente hasta que otra persona lo aprueba (RN-COL-11); la exclusión solo rige entre cuentas activas.
  person_id -> people ; employment_id -> employments ; purpose enum(salary,cts,other)! ; bank_code text! ; account_type enum(savings,checking,wallet)!
  account_number_enc text! ; interbank_code_enc ; currency cur ; is_active bool=t ; verified_at
  status enum(pending_approval,active,rejected,cancelled)=pending_approval ; requested_by_user_id ?-> users ; decided_by_user_id ?-> users
  decided_at ts ; decision_reason ; vig employment_id, purpose | status = 'active'
@person_education | Formación académica.
  person_id -> people ; level enum(primary,secondary,technical,university,postgraduate,other)! ; institution ; degree ; field
  started_on ; finished_on ; is_completed ; file_id ?-> stored_files
@person_work_experiences | Experiencia laboral previa.
  person_id -> people ; employer text! ; job_title text! ; started_on ; finished_on ; description
@person_sensitive_data sens lock | Datos sensibles que solo se registran con consentimiento (condición de discapacidad, grupo sanguíneo).
  person_id -> people ; disability_enc ; disability_certificate_file_id ?-> stored_files ; blood_type_enc
  consent_id ?-> consents ; uniq person_id
@dependents soft lock | Derechohabientes del trabajador (E-19): cónyuge, conviviente, hijos; sustento de asignación familiar y registro laboral.
  person_id -> people ; full_name text! #S ; relationship enum(spouse,partner,pregnant_partner,child,disabled_adult_child,parent,other)! ; doc_type ; number_enc
  number_hash ; birth_date date #S ; is_student ; disability_enc ; registered_on ; deregistered_on ; deregistration_reason
  registry_status enum(pending,registered,deregistered)=pending ; entitles_family_allowance ; proof_file_id ?-> stored_files
  majority_alerted_on date
@employments main soft lock | Relación laboral (C-01) entre una persona y una razón social. Todo lo laboral cuelga de aquí. work_email, work_phone y work_phone_ext son el contacto laboral que el directorio puede publicar (ORG-7 · RN-ORG-08); nunca el correo o celular personal.
  person_id -> people ; legal_entity_id -> legal_entities ; employee_code text! ; kind enum(employee,intern,apprentice)=employee
  worker_category enum(executive,employee,worker)=employee ; hire_date date! ; seniority_date date ; termination_date date
  status enum(pending,active,suspended,terminated,terminating,cancelled)=pending ; rehire_of_employment_id ?-> employments
  source_application_id ?-> applications ; probation_ends_on ; uniq legal_entity_id, employee_code
  work_email ; work_phone ; work_phone_ext
  check termination_date IS NULL OR termination_date >= hire_date
@employment_status_events imm | Historial inalterable de estados de la relación laboral.
  employment_id -> employments ; from_status ; to_status text! ; reason_code ; effective_on date! ; changed_by_user_id ?-> users ; notes

#E HIS | Historia con vigencia
@personnel_actions main soft lock | Movimiento de personal con fecha efectiva (ingreso, promoción, traslado, cambio de sueldo, de jefe...); agrupa los registros con vigencia que genera. HIS-2: queda en aprobación según la configuración; HIS-4: con fecha futura queda «programado» (scheduled) y se aplica solo ese día; una corrección deja el original como «reemplazado» (replaced) y enlazado.
  employment_id -> employments
  kind enum(hire,promotion,transfer,salary_change,manager_change,schedule_change,contract_change,cost_center_change,regime_change,suspension,reinstatement,termination,data_correction)!
  effective_on date! ; reason ; status enum(draft,pending_approval,approved,scheduled,applied,rejected,cancelled,replaced)=draft
  requested_by_user_id ?-> users ; workflow_instance_id ?-> workflow_instances ; applied_at ; payload j
  changes j ; decided_at ts ; decision_reason ; cancelled_at ts ; cancelled_by_user_id ?-> users
  replaces_personnel_action_id ?-> personnel_actions ; replaced_by_personnel_action_id ?-> personnel_actions
  is_retroactive bool=f ; affected_from date ; affected_to date ; idx status, effective_on
@employment_assignments main soft lock | Asignación con vigencia (C-03): puesto, posición, unidad, sede, jefe y centro de costo. Fuente única de «dónde trabaja y a quién reporta» (C-11). Los cupos de la posición (seats) los controla un disparador (RN-POS-02).
  employment_id -> employments ; job_profile_id -> job_profiles ; position_id ?-> positions ; org_unit_id -> org_units
  site_id -> sites ; manager_employment_id ?-> employments ; cost_center_id ?-> cost_centers
  work_modality enum(onsite,remote,hybrid)=onsite ; reason ; personnel_action_id ?-> personnel_actions ; vig employment_id
@employment_cost_distributions lock | Distribución con vigencia de una relación laboral entre varios centros de costo (ORG-4 · RN-ORG-06); sus líneas suman siempre 100 %.
  employment_id -> employments ; reason ; vig employment_id
@employment_cost_distribution_lines | Línea de una distribución: centro de costo y porcentaje (dos decimales).
  employment_cost_distribution_id -> employment_cost_distributions ; cost_center_id -> cost_centers ; percentage numeric(5,2)!
  uniq employment_cost_distribution_id, cost_center_id
  check percentage > 0 AND percentage <= 100
@employment_compensations main soft lock sens | Compensación con vigencia (C-03): sueldo base y periodicidad. Fuente única del sueldo (C-11).
  employment_id -> employments ; base_salary money! #S ; currency cur ; pay_frequency enum(monthly,biweekly,weekly,daily,hourly)=monthly
  salary_basis enum(monthly,daily,hourly)=monthly ; is_integral_salary
  change_reason enum(hire,merit,promotion,adjustment,legal,review_cycle,correction) ; salary_band_id ?-> salary_bands
  personnel_action_id ?-> personnel_actions ; compensation_review_proposal_id ?-> compensation_review_proposals ; vig employment_id
@employment_compensation_items lock sens | Conceptos fijos recurrentes con vigencia (bonos, asignaciones, movilidad) atados a un concepto de planilla.
  employment_id -> employments ; payroll_concept_id -> payroll_concepts ; amount money #S ; percentage numeric(7,4) ; currency cur?
  notes ; vig employment_id, payroll_concept_id
@employment_labor_regimes soft lock | Régimen laboral y de salud del trabajador con vigencia (C-05).
  employment_id -> employments ; labor_regime_code ~> labor_regimes.code ; health_scheme enum(essalud,eps,sis,other)=essalud
  health_provider_id ?-> health_providers ; health_plan_code ; has_risk_insurance ; has_family_allowance
  personnel_action_id ?-> personnel_actions ; vig employment_id
@employment_pension_affiliations lock | Afiliación previsional del trabajador con vigencia (sistema, administradora, tipo de comisión).
  employment_id -> employments ; pension_system enum(afp,onp,none,other)! ; pension_fund_code ?~> pension_funds.code
  affiliate_code_enc ; commission_scheme enum(flow,mixed) ; affiliated_on ; vig employment_id

#E CON | Contratos y adendas
@contract_types soft | Tipos y modalidades de contrato, con el parámetro legal que fija su plazo máximo.
  code ; name ; country_code ; category enum(indefinite,fixed_term,part_time,internship,other)! ; modality_code
  max_duration_parameter_code ; trial_period_parameter_code ; requires_objective_cause ; requires_end_date bool=f ; is_system
  document_template_id ?-> document_templates ; uniq code
@contracts main soft lock | Contrato de la relación laboral con vigencia (C-03); controla vencimiento y tope acumulado (E-08). CON-4: expiry_alerted_days/expiry_alerted_for recuerdan la última anticipación avisada para esa fecha de fin (no se repite el aviso), cap_alerted_on el aviso de tope acumulado y over_limit_reason el motivo con que se confirmó una renovación por encima del tope.
  employment_id -> employments ; contract_type_id -> contract_types ; number ; objective_cause ; trial_period_ends_on
  weekly_hours numeric(5,2) ; work_modality enum(onsite,remote,hybrid)=onsite
  status enum(draft,pending_signature,active,expired,terminated,superseded,cancelled,pending_approval,signed)=draft
  renewal_of_contract_id ?-> contracts ; document_id ?-> documents ; signature_request_id ?-> signature_requests
  accumulated_days integer ; signed_at ; signature_origin enum(physical_scan,electronic) ; approved_by_user_id ?-> users ; approved_at
  termination_reason ; probation_alerted_on ; expiry_alerted_days integer ; expiry_alerted_for date ; cap_alerted_on date ; over_limit_reason
  vig employment_id | status NOT IN ('draft','pending_approval','superseded','cancelled')
@contract_amendments soft lock | Adendas al contrato.
  contract_id -> contracts ; number ; kind enum(salary,position,schedule,extension,modality,other)! ; effective_on date!
  description text! ; document_id ?-> documents ; signature_request_id ?-> signature_requests
  status enum(draft,pending_signature,active,cancelled)=draft ; changes j ; applied_refs j ; signed_at
  signature_origin enum(physical_scan,electronic) ; cancellation_reason
@contract_renewal_batches lock | Lote de renovación masiva de contratos con firma (E-08).
  name ; legal_entity_id ?-> legal_entities ; status enum(draft,in_review,approved,sent_to_signature,completed,cancelled)=draft
  new_end_date date ; created_by_user_id -> users ; sent_at ; completed_at
@contract_renewal_batch_items | Decisión por contrato dentro de un lote de renovación, con el control de tope acumulado.
  contract_renewal_batch_id -> contract_renewal_batches ; contract_id -> contracts
  decision enum(renew,not_renew,convert_indefinite,pending)=pending ; new_valid_to date ; new_contract_id ?-> contracts
  accumulated_days_after integer ; exceeds_limit ; notes ; uniq contract_renewal_batch_id, contract_id
@remote_work_agreements soft lock | Acuerdo de teletrabajo con su contenido mínimo (E-20, Ley 31572; CON-6). Nace como solicitud de cambio de modalidad con su plazo de respuesta (response_due_on, parámetro); aprobada, se emite solo con el contenido mínimo completo (RN-CON-10), se registra firmada y queda vigente hasta terminarse.
  employment_id -> employments ; modality enum(total,partial)! ; starts_on date! ; ends_on ; work_place ; schedule_description
  disconnection_hours smallint ; supervision_mechanism ; equipment_provided_by enum(employer,worker,mixed)
  expense_compensation money ; currency cur? ; payroll_concept_id ?-> payroll_concepts ; requested_on ; responded_on
  status enum(requested,approved,rejected,pending_signature,active,ended)=requested ; document_id ?-> documents
  request_reason ; requested_by enum(employee,employer)=employee ; requested_by_user_id ?-> users ; response_due_on date ; decided_by_user_id ?-> users ; decision_reason
  signed_at ; signature_origin enum(physical_scan,electronic) ; end_reason ; due_reminder_sent_at ts

#E DOC | Gestión documental, plantillas y firma
@document_categories soft | Carpetas o categorías documentales (personal, contratos, boletas, SST...), con permiso requerido y retención. DOC-1: las carpetas no tienen parent_id; los tipos documentales cuelgan de una carpeta y definen confidencialidad, visibilidad para el colaborador, revisión y, con DOC-2, si exigen vencimiento y con qué anticipación se avisa (RN-DOC-09).
  code ; name ; parent_id ?-> document_categories ; default_classification enum(public,internal,confidential,restricted)=internal
  retention_policy_id ?-> retention_policies ; required_permission_code ?~> permissions.code ; scope enum(employee,company)=employee
  description ; requires_expiry bool=f ; expiry_notice_days smallint ; visible_to_employee bool=f ; requires_review bool=f
  is_system bool=f ; sort_order smallint=0 ; status enum(active,archived)=active ; uniq code
@documents main soft lock | Documento del repositorio, ligado a una persona, relación laboral, empresa u otro registro.
  document_category_id -> document_categories ; title ; owner_person_id ?-> people ; employment_id ?-> employments
  legal_entity_id ?-> legal_entities ; subject_type ; subject_id uuid ; current_version_id ?-> document_versions
  classification enum(public,internal,confidential,restricted)=internal
  status enum(draft,pending_approval,active,archived,expired,rejected,requested)=draft ; issued_on ; expires_on
  source enum(upload,generated,import,signature,system)=upload ; document_template_version_id ?-> document_template_versions
  visible_to_employee bool=f ; responsible_user_id ?-> users ; description ; expiry_alerted_on ; archived_at ; archive_reason
  idx subject_type, subject_id ; idx status, expires_on
@document_versions | Versiones de un documento; cada una apunta a un archivo y guarda su huella. DOC-2 · RN-DOC-01: una versión nueva no reemplaza a la anterior, que queda consultable con sus propias fechas de emisión y vencimiento.
  document_id -> documents ; version integer! ; stored_file_id -> stored_files ; content_hash text!
  uploaded_by_user_id ?-> users ; notes ; issued_on ; expires_on ; uniq document_id, version
@document_access_grants | Permiso puntual sobre un documento para un usuario o rol.
  document_id -> documents ; user_id ?-> users ; role_id ?-> roles ; access enum(view,download,edit)=view ; expires_at
  check num_nonnulls(user_id, role_id) = 1
@document_requests lock | Solicitud de un documento a un colaborador o candidato, con revisión. DOC-3: RRHH pide un tipo documental a una o varias personas (request_group_id agrupa el envío) con fecha límite; el colaborador lo entrega desde el portal (submitted_at), la revisión pasa por el motor de aprobaciones y, si se rechaza, vuelve al colaborador con la observación (rejection_reason) para entregarlo de nuevo.
  employment_id ?-> employments ; person_id ?-> people ; document_category_id -> document_categories
  requested_by_user_id ?-> users ; due_on ; status enum(requested,submitted,approved,rejected,cancelled)=requested
  document_id ?-> documents ; rejection_reason ; instructions ; request_group_id uuid ; title ; submitted_at
  reviewed_by_user_id ?-> users ; reviewed_at ; cancellation_reason ; idx status, due_on
@document_templates soft lock | Plantilla con variables para generar documentos (contratos, certificados, cartas, ofertas). DOC-4: se organiza por tipo documental y empresa (legal_entity_id vacío = todas); current_version_id es la versión publicada con la que se genera (active = publicada, inactive = retirada).
  code ; name ; document_category_id ?-> document_categories
  kind enum(contract,amendment,certificate,letter,offer,policy,payslip,settlement,other)! ; locale text=es ; country_code
  current_version_id ?-> document_template_versions ; is_system ; status enum(draft,active,inactive)=draft ; uniq code
  legal_entity_id ?-> legal_entities ; description
@document_template_versions | Versión del cuerpo de una plantilla. DOC-4: la versión sin published_at es el borrador que se edita; al publicarse queda fija y los documentos generados conservan la suya (RN-DOC-11). page_settings guarda el encabezado de la empresa.
  document_template_id -> document_templates ; version integer! ; body text! ; variables j ; page_settings j ; published_at
  created_by_user_id ?-> users ; notes ; uniq document_template_id, version
@document_generation_batches lock | DOC-5: generación de documentos PDF desde una plantilla para una o varias relaciones laborales, en segundo plano: avance (generados y con error) y el resultado por persona en items, con el motivo de los omitidos.
  document_template_id -> document_templates ; document_template_version_id -> document_template_versions
  requested_by_user_id ?-> users ; status enum(queued,running,completed,completed_with_errors,failed)=queued
  total integer=0 ; generated integer=0 ; failed integer=0 ; items j ; started_at ; finished_at
@document_acknowledgements | Constancia de lectura o recepción de un documento por un colaborador.
  document_id -> documents ; employment_id -> employments ; acknowledged_at ; method enum(click,signature,otp)
  uniq document_id, employment_id
@signature_requests main lock | Solicitud de firma de un documento (C-12): firmantes, orden, nivel de firma y proveedor.
  document_id -> documents ; document_version_id -> document_versions ; title ; signing_order enum(sequential,parallel)=sequential
  signature_level enum(simple,advanced,digital_certificate)=simple ; provider enum(internal,external)=internal
  integration_id ?-> integrations ; provider_envelope_id
  status enum(draft,sent,in_progress,completed,declined,expired,cancelled,viewed)=draft ; sent_at ; completed_at ; expires_at
  requested_by_user_id ?-> users ; final_file_id ?-> stored_files ; final_hash ; cancellation_reason
@signature_request_signers lock | Firmantes de una solicitud y su estado.
  signature_request_id -> signature_requests ; user_id ?-> users ; person_id ?-> people ; signer_name text! ; signer_role
  sort_order integer=1 ; status enum(pending,sent,viewed,signed,declined)=pending ; viewed_at ; signed_at ; declined_reason
  last_reminded_at ts
@signature_events imm | Evidencia inalterable de firma (C-12): huella del documento, sello de tiempo, IP, verificación por OTP y evidencia del proveedor.
  signature_request_id -> signature_requests ; signer_id ?-> signature_request_signers
  event enum(created,sent,viewed,otp_sent,otp_verified,signed,declined,completed,expired,cancelled)! ; document_hash text!
  hash_algorithm text=SHA-256 ; ip inet ; user_agent ; otp_channel enum(email,sms,whatsapp) ; otp_reference_hash
  timestamp_token ; provider_evidence j ; occurred_at ts=now ; previous_hash ; event_hash

#E ESS | Portal del colaborador
@profile_change_requests lock | Cambio de datos personales pedido por el colaborador, pendiente de validación de RRHH.
  person_id -> people ; requested_by_user_id -> users ; changes j! #S ; status enum(pending,approved,rejected,cancelled)=pending
  reviewed_by_user_id ?-> users ; reviewed_at ; rejection_reason ; workflow_instance_id ?-> workflow_instances
@certificate_requests lock | Solicitud de constancias y certificados desde el portal.
  employment_id -> employments ; kind enum(employment_certificate,income_certificate,vacation_certificate,cts_letter,withholding_certificate,other)!
  purpose ; status enum(requested,in_progress,issued,rejected)=requested ; document_id ?-> documents ; issued_at
  requested_by_user_id ?-> users ; workflow_instance_id ?-> workflow_instances ; decided_by_user_id ?-> users ; decided_at
  rejection_reason ; auto_issued bool=f

#E MSS | Portal del manager
#N El portal del manager no tiene tablas propias: consume asignaciones (jefe), tareas de aprobación, asistencia, vacaciones, objetivos y evaluaciones con alcance «equipo».

#E IMP | Importador y puesta en marcha
@import_templates soft | Plantilla de mapeo de columnas para el importador.
  entity enum(legal_entities,employees,contracts,vacation_balances,salaries,attendance,concepts,history,org_structure,dependents,positions,job_profiles)!
  name ; column_mapping j! ; is_system
  source_code ; version integer=1 ; value_maps j ; default_values j ; description ; created_by_user_id ?-> users ; uniq source_code, entity, version
@import_jobs main lock | Importación masiva por etapas: subir, mapear, validar, previsualizar e importar (nunca directo).
  entity enum(legal_entities,employees,contracts,vacation_balances,salaries,attendance,concepts,history,org_structure,dependents,positions,job_profiles)!
  import_template_id ?-> import_templates ; stored_file_id -> stored_files
  status enum(uploaded,mapped,validating,validated,previewed,importing,completed,failed,cancelled,with_errors,reverted)=uploaded ; column_mapping j
  total_rows integer ; valid_rows integer ; error_rows integer ; imported_rows integer ; created_by_user_id -> users
  started_at ; finished_at ; dry_run bool=t ; file_name ; headers j ; batch_size integer ; summary j ; error_message
@import_job_rows | Filas de una importación con su validación y el registro creado.
  import_job_id -> import_jobs ; row_number integer! ; raw_data j! ; parsed_data j
  status enum(pending,valid,invalid,imported,skipped,failed)=pending ; errors j ; warnings j ; entity_id uuid ; previous_data j ; batch_number integer ; uniq import_job_id, row_number
@setup_checklist_items | Pasos del asistente de puesta en marcha (E-27) y su avance.
  code ; title ; module_code ?~> modules.code ; status enum(pending,in_progress,done,skipped)=pending
  completed_by_user_id ?-> users ; completed_at ; sort_order ; uniq code

#E AYU | Centro de ayuda
#N La ayuda del producto es de plataforma (la publica GestiaHR); los artículos de la organización son de cada organización y la mesa de ayuda (HSD) los reutiliza. La búsqueda usa índices GIN sobre `search_vector` (ver `epilogue.sql`).
@help_articles P cat soft lock | Artículo de ayuda del producto que publica GestiaHR: módulo, roles, pantallas, idioma y versión del producto.
  slug text! ; locale text=es ; title ; summary ; body text! ; kind enum(guide,screen,field,tour,release_note,faq)=guide
  module_code ?~> modules.code ; roles text[] ; screen_keys text[] ; product_version text! ; status enum(draft,published,archived)=draft
  published_at ; search_vector tsvector GENERATED ALWAYS AS (to_tsvector('spanish', coalesce(title, '') || ' ' || coalesce(body, ''))) STORED ; uniq slug, locale
@help_article_versions P cat imm | Versión publicada de un artículo del producto; no se modifica.
  help_article_id => help_articles ; version_number integer! ; product_version text! ; title ; body text! ; published_at ts!
  uniq help_article_id, version_number
@help_article_feedback | Valoración «¿te sirvió?» de un artículo del producto por un usuario de la organización.
  help_article_id => help_articles ; user_id ?-> users ; is_helpful boolean! ; comment ; screen_key
@kb_categories soft | Categorías de los artículos de la organización (también las usa la mesa de ayuda).
  name ; parent_id ?-> kb_categories ; sort_order
@kb_articles soft lock | Artículos y políticas de la organización (reglamento, vacaciones, permisos) con audiencia, responsable y revisión.
  kb_category_id ?-> kb_categories ; title ; slug text! ; body text! ; locale text=es
  status enum(draft,published,in_review,archived)=draft ; audience j ; owner_user_id ?-> users ; review_on date ; version integer=1
  view_count integer=0 ; published_at ; search_vector tsvector GENERATED ALWAYS AS (to_tsvector('spanish', coalesce(title, '') || ' ' || coalesce(body, ''))) STORED ; uniq slug, locale
@kb_article_versions imm | Versión publicada de un artículo de la organización; no se modifica.
  kb_article_id -> kb_articles ; version_number integer! ; title ; body text! ; published_by_user_id ?-> users ; published_at ts!
  uniq kb_article_id, version_number
@kb_article_feedback | Valoración de utilidad de un artículo de la organización.
  kb_article_id -> kb_articles ; user_id ?-> users ; is_helpful boolean! ; comment
@help_search_queries ao part | Búsquedas en la ayuda y preguntas al asistente, sin identificar a la persona (RN-AYU-05).
  query_text text! ; screen_key ; role_code ; result_count integer! ; source enum(search,assistant)=search ; occurred_at ts=now
@help_escalations lock | Consulta derivada desde la ayuda a RRHH o, si la envía el administrador, al soporte de GestiaHR.
  user_id -> users ; target enum(hr,platform_support)=hr ; question text! ; screen_key ; consulted_articles j
  workflow_instance_id ?-> workflow_instances ; support_ticket_id ?=> support_tickets ; status enum(open,answered,closed)=open
  answer ; answered_at
@help_tour_progress | Recorridos guiados vistos o saltados por cada usuario.
  user_id -> users ; tour_key text! ; status enum(completed,skipped)! ; completed_at ts=now ; uniq user_id, tour_key
