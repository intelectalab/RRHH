#F 6 | Experiencia

#E ENC | Encuestas, clima y eNPS
@surveys main soft lock | Encuesta (clima, compromiso, ingreso, salida, pulso, eNPS, personalizada) con anonimato configurable y umbral mínimo por segmento (C-06).
  title ; kind enum(climate,engagement,onboarding,exit,pulse,enps,custom)! ; description ; is_anonymous bool=t
  min_responses_per_segment smallint=5 ; segment_dimensions text[] ; opens_at ; closes_at
  status enum(draft,scheduled,open,closed,archived,results_published)=draft ; recurrence enum(none,weekly,monthly,quarterly)=none
  legal_entity_id ?-> legal_entities ; created_by_user_id ?-> users
@survey_questions soft | Preguntas de una encuesta, con la dimensión de clima a la que aportan.
  survey_id -> surveys ; section ; text text! ; kind enum(scale,enps,single_choice,multiple_choice,text,matrix)!
  dimension ; options j ; scale_min smallint ; scale_max smallint ; is_required bool=t ; sort_order
@survey_audiences | Segmentos invitados a una encuesta.
  survey_id -> surveys ; legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units
  job_level_id ?-> job_levels ; include_all bool=f
@survey_participations lock | Quién fue invitado y si ya respondió. No se enlaza con la respuesta: permite recordatorios sin romper el anonimato.
  survey_id -> surveys ; employment_id -> employments ; invited_at ; reminded_at ; has_responded
  uniq survey_id, employment_id
@survey_responses nots | Respuesta a una encuesta SIN vínculo con la persona (C-06): solo guarda el segmento y la fecha (sin hora). No tiene marcas de tiempo.
  survey_id -> surveys ; segment_legal_entity_id ?-> legal_entities ; segment_site_id ?-> sites
  segment_org_unit_id ?-> org_units ; segment_job_level_id ?-> job_levels ; tenure_band ; submitted_on date!
@survey_response_identities | Identidad del respondiente SOLO en encuestas no anónimas; un trigger impide insertar aquí si la encuesta es anónima.
  survey_response_id -> survey_responses ; employment_id -> employments ; uniq survey_response_id
@survey_answers nots | Respuestas por pregunta.
  survey_response_id -> survey_responses ; survey_question_id -> survey_questions ; value_numeric numeric(6,2)
  value_text ; value_options j
@survey_action_plans soft lock | Plan de acción derivado de los resultados, por unidad y dimensión (E-25).
  survey_id -> surveys ; org_unit_id ?-> org_units ; title ; dimension ; owner_user_id ?-> users
  status enum(draft,active,completed,cancelled)=draft ; due_on
@survey_action_items lock | Acciones de un plan con responsable y seguimiento.
  survey_action_plan_id -> survey_action_plans ; description text! ; owner_user_id ?-> users ; due_on
  status enum(pending,in_progress,done,dropped)=pending ; progress_note

#E REC | Reconocimientos
@company_values soft | Valores de la empresa.
  name ; description ; icon ; sort_order
@recognition_badges soft | Insignias.
  name ; description ; icon_file_id ?-> stored_files ; points integer
@recognitions soft | Reconocimiento entre compañeros, ligado a un valor o insignia.
  giver_user_id -> users ; company_value_id ?-> company_values ; recognition_badge_id ?-> recognition_badges
  message text! ; visibility enum(public,team,private)=public
@recognition_recipients | Personas reconocidas.
  recognition_id -> recognitions ; employment_id -> employments ; uniq recognition_id, employment_id
@recognition_reactions | Reacciones a un reconocimiento.
  recognition_id -> recognitions ; user_id -> users ; kind text=like ; uniq recognition_id, user_id, kind

#E CIN | Comunicación interna
@posts main soft lock | Noticias, comunicados y eventos, con segmentación y confirmación de lectura opcional.
  kind enum(news,announcement,event,birthday,anniversary,policy)! ; title ; body ; cover_file_id ?-> stored_files
  author_user_id ?-> users ; publish_at ts ; expires_at ; status enum(draft,scheduled,published,archived)=draft
  requires_read_confirmation bool=f ; is_pinned ; allow_comments bool=t
@post_audiences | Segmentos a los que se dirige una publicación.
  post_id -> posts ; legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units
  include_all bool=f
@post_reads | Lecturas y confirmaciones de lectura.
  post_id -> posts ; user_id -> users ; read_at ts! ; confirmed_at ; uniq post_id, user_id
@post_reactions | Reacciones a una publicación.
  post_id -> posts ; user_id -> users ; kind text=like ; uniq post_id, user_id, kind
@calendar_events soft lock | Eventos del calendario de RRHH (los de vacaciones, contratos o cumpleaños se derivan de sus tablas).
  title ; kind enum(company_event,training,evaluation,interview,holiday,deadline,other)! ; starts_at ts! ; ends_at
  all_day bool=f ; location_text ; subject_type ; subject_id uuid ; visibility enum(public,audience,private)=public
  post_id ?-> posts ; created_by_user_id ?-> users ; legal_entity_id ?-> legal_entities ; site_id ?-> sites
  org_unit_id ?-> org_units

#E HSD | Mesa de ayuda y base de conocimiento
@sla_policies soft | Plazos de primera respuesta y de resolución.
  name ; first_response_hours integer ; resolution_hours integer ; business_hours_only bool=t ; escalation j
@ticket_categories soft | Categorías de solicitudes a RRHH.
  code ; name ; parent_id ?-> ticket_categories ; default_assignee_role_id ?-> roles ; sla_policy_id ?-> sla_policies
  form j ; is_confidential ; description ; requires_attachment bool=f ; sort_order integer=100
  status enum(active,archived)=active ; uniq code
@tickets main soft lock | Solicitud del colaborador a RRHH con estados y plazos.
  number text! ; ticket_category_id -> ticket_categories ; requester_user_id -> users ; employment_id ?-> employments
  subject text! ; description ; status enum(new,in_progress,waiting_info,resolved,closed,cancelled)=new
  priority enum(low,normal,high,urgent)=normal ; assignee_user_id ?-> users ; sla_policy_id ?-> sla_policies
  first_response_due_at ; resolution_due_at ; first_responded_at ; resolved_at ; closed_at ; satisfaction_rating smallint
  channel enum(portal,email,whatsapp,ai_assistant,help_center)=portal ; help_escalation_id ?-> help_escalations ; uniq number
@ticket_messages | Mensajes y notas internas de una solicitud.
  ticket_id -> tickets ; author_user_id ?-> users ; body text! ; is_internal_note ; via enum(portal,email,whatsapp,system)=portal
  event enum(created,assigned,info_requested,replied,resolved,reopened,closed,comment)=comment ; stored_file_id ?-> stored_files ; file_name
@ticket_article_links | Artículos sugeridos o usados para resolver una solicitud.
  ticket_id -> tickets ; kb_article_id -> kb_articles ; kind enum(suggested,used_in_resolution)=suggested
  uniq ticket_id, kb_article_id

#F 7 | Operación y cumplimiento

#E SST | Seguridad y salud en el trabajo
@sst_programs soft lock | Programa anual de seguridad y salud por empresa.
  legal_entity_id -> legal_entities ; fiscal_year smallint! ; title ; objectives ; document_id ?-> documents
  status enum(draft,approved,in_progress,closed)=draft
@sst_program_activities lock | Actividades del programa con responsable y evidencia.
  sst_program_id -> sst_programs ; title ; responsible_user_id ?-> users ; planned_on ; done_on
  status enum(planned,done,overdue,cancelled)=planned ; evidence_document_id ?-> documents
@risk_matrices soft lock | Matriz de identificación de peligros y evaluación de riesgos por empresa, sede o unidad, versionada.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units ; title ; version integer=1
  approved_on ; approved_by_user_id ?-> users ; status enum(draft,approved,superseded)=draft
@risk_matrix_items lock | Peligro, riesgo, valoración y controles por actividad o puesto.
  risk_matrix_id -> risk_matrices ; job_profile_id ?-> job_profiles ; activity text! ; hazard text! ; risk text!
  probability smallint ; severity smallint ; risk_level enum(trivial,tolerable,moderate,important,intolerable)
  existing_controls ; proposed_controls ; residual_level ; responsible_user_id ?-> users
@sst_incidents main soft lock sens | Accidente de trabajo, incidente peligroso, incidente o enfermedad ocupacional, con plazo de conservación.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; number text!
  kind enum(work_accident,dangerous_incident,incident,occupational_disease)! ; severity enum(none,minor,disabling,fatal)
  occurred_at ts! ; description text! #S ; immediate_actions ; reported_by_user_id ?-> users ; reported_to_authority_at
  authority_reference ; lost_days integer ; status enum(reported,under_investigation,closed,classified,actions_in_progress,dismissed)=reported
  retention_until date ; uniq number
@sst_incident_people sens | Personas involucradas en un incidente (lesionado, testigo); descripción de la lesión cifrada.
  sst_incident_id -> sst_incidents ; employment_id ?-> employments ; external_person_name
  role enum(injured,witness,involved)! ; injury_description_enc ; body_part ; medical_leave_days integer
  absence_request_id ?-> absence_requests
@sst_investigations lock | Investigación de un incidente: causas, método y conclusiones.
  sst_incident_id -> sst_incidents ; lead_user_id ?-> users ; root_causes ; method ; conclusions ; started_on ; closed_on
  report_document_id ?-> documents
@sst_inspections soft lock | Inspecciones internas de seguridad.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; kind enum(planned,unplanned,safety_equipment,emergency)!
  inspected_on date! ; inspector_user_id ?-> users ; area ; checklist j ; result_summary ; document_id ?-> documents
  status enum(scheduled,done,cancelled)=scheduled
@sst_inspection_findings lock | Hallazgos y observaciones de una inspección.
  sst_inspection_id -> sst_inspections ; description text!
  kind enum(unsafe_condition,unsafe_act,observation,nonconformity)! ; risk_level ; photo_file_id ?-> stored_files
@corrective_actions lock | Medidas correctivas derivadas de incidentes, inspecciones, auditorías o monitoreos, con seguimiento.
  source_type text! ; source_id uuid! ; description text! ; responsible_user_id ?-> users ; due_on ; completed_on
  status enum(open,in_progress,done,verified,overdue,cancelled)=open ; verification_notes
  evidence_document_id ?-> documents ; idx source_type, source_id
@occupational_exams soft lock sens | Examen médico ocupacional: aptitud y restricciones; resultados cifrados y de acceso restringido.
  employment_id -> employments ; kind enum(pre_employment,periodic,exit,return_to_work,special)! ; exam_on date! ; provider
  aptitude enum(fit,fit_with_restrictions,unfit,pending) #S ; restrictions_enc ; results_file_id ?-> stored_files #S
  next_exam_on ; delivered_to_worker_at ; retention_until date
@agent_monitorings soft | Monitoreo de agentes físicos, químicos, biológicos, psicosociales y disergonómicos.
  legal_entity_id -> legal_entities ; site_id ?-> sites
  agent_kind enum(physical,chemical,biological,psychosocial,ergonomic)! ; agent text! ; measured_on date! ; result
  limit_reference ; exceeds_limit boolean ; document_id ?-> documents
@sst_committees soft lock | Comité o supervisor de seguridad y salud, con su periodo.
  legal_entity_id -> legal_entities ; kind enum(committee,subcommittee,supervisor)! ; term_start date! ; term_end date
  election_document_id ?-> documents ; status enum(in_election,active,expired)=active ; site_id ?-> sites
  installation_document_id ?-> documents
@sst_committee_members | Miembros del comité y a quién representan.
  sst_committee_id -> sst_committees ; employment_id -> employments ; represents enum(employer,workers)!
  role enum(president,secretary,member,alternate,supervisor)=member ; starts_on ; ends_on
@sst_committee_meetings lock | Reuniones del comité con acta y acuerdos.
  sst_committee_id -> sst_committees ; held_on date! ; kind enum(ordinary,extraordinary)=ordinary ; agenda
  minutes_document_id ?-> documents ; agreements j
  status enum(scheduled,called,held,no_quorum,minutes_drafted,minutes_signed,cancelled)=scheduled ; called_on ; quorum_required smallint
  quorum_present smallint ; rescheduled_from_meeting_id ?-> sst_committee_meetings ; minutes_ledger_entry_id ?-> compliance_ledger_entries
@sst_committee_meeting_attendees | Asistencia por miembro a cada sesión: base del quórum y de la causal de vacancia por inasistencias.
  sst_committee_meeting_id -> sst_committee_meetings ; sst_committee_member_id -> sst_committee_members
  attendance enum(present,absent,excused,replaced_by_alternate)! ; signed_minutes_at ; uniq sst_committee_meeting_id, sst_committee_member_id
@sst_committee_elections lock | Proceso de elección de los representantes de los trabajadores ante el comité, subcomité o del supervisor de SST.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; body_kind enum(committee,subcommittee,supervisor)!
  outgoing_committee_id ?-> sst_committees ; resulting_committee_id ?-> sst_committees ; headcount_at_start integer!
  total_members smallint! ; worker_seats smallint! ; convened_by enum(majority_union,employer) ; voting_mode enum(in_person,electronic)
  call_published_on ; nominations_close_on ; candidates_published_on ; election_date date
  status enum(planned,notified,called,board_formed,nominations,candidates_published,voting,counted,proclaimed,installed,annulled)=planned
  minutes_document_id ?-> documents ; check headcount_at_start >= 1 AND total_members >= 1 AND worker_seats >= 1
  check body_kind = 'supervisor' OR total_members = worker_seats * 2
@sst_election_participants lock | Junta electoral, candidatos y observadores de una elección. La unicidad por elección impide que un integrante de la junta sea candidato.
  sst_committee_election_id -> sst_committee_elections ; employment_id -> employments
  role enum(board_president,board_secretary,board_member,candidate,observer)!
  candidacy_status enum(registered,eligible,challenged,excluded,withdrawn) ; votes integer
  result enum(elected_member,elected_alternate,not_elected) ; uniq sst_committee_election_id, employment_id
  check (role = 'candidate') = (candidacy_status IS NOT NULL) ; check votes IS NULL OR votes >= 0
@sst_election_voters nots | Padrón electoral y constancia de participación. No tiene columna de preferencia: el voto es secreto.
  sst_committee_election_id -> sst_committee_elections ; employment_id -> employments ; voted_on
  uniq sst_committee_election_id, employment_id
@sst_drills soft | Simulacros.
  legal_entity_id -> legal_entities ; site_id ?-> sites
  kind enum(earthquake,fire,evacuation,spill,first_aid,other)! ; held_on date! ; participants_count integer ; evaluation
  document_id ?-> documents
@sst_audits soft | Auditorías del sistema de gestión de seguridad y salud.
  legal_entity_id -> legal_entities ; audited_on date! ; auditor ; kind enum(internal,external)! ; audit_scope
  findings_summary ; document_id ?-> documents
@safety_equipment soft lock | Equipos de seguridad y emergencia por sede, con inspección y vencimiento.
  site_id -> sites ; kind enum(extinguisher,first_aid_kit,alarm,emergency_light,eyewash,defibrillator,other)!
  code ; position_text ; last_inspected_on ; next_inspection_on ; expires_on
  status enum(operational,needs_service,out_of_service)=operational ; uniq code

#E DEN | Canal de denuncias y hostigamiento
@harassment_committees soft lock | Comité de intervención frente al hostigamiento sexual o delegado (E-15).
  legal_entity_id -> legal_entities ; kind enum(committee,delegate)! ; term_start date! ; term_end date ; status ; site_id ?-> sites
@harassment_committee_members | Miembros del comité y a quién representan.
  harassment_committee_id -> harassment_committees ; employment_id -> employments ; represents enum(employer,workers)!
  is_alternate ; starts_on ; ends_on ; is_hr_representative
@complaint_channels soft lock | Canal de denuncias configurable por empresa, con enlace público propio.
  legal_entity_id ?-> legal_entities ; name ; public_slug text! ; allow_anonymous bool=t ; categories j ; intro_text
  is_active bool=t ; uniq public_slug
@complaints main lock sens nots | Denuncia (C-06). NO guarda usuario, persona, IP, agente de navegador ni marcas de hora: solo la fecha y huellas del código de seguimiento. Contenido cifrado.
  complaint_channel_id -> complaint_channels ; legal_entity_id ?-> legal_entities ; tracking_code_hash text!
  access_secret_hash text! ; is_anonymous boolean!
  category enum(sexual_harassment,workplace_harassment,discrimination,fraud,corruption,safety,ethics,retaliation,other)!
  description_enc text! ; involved_people_enc ; incident_period_text ; location_text_enc
  status enum(received,admitted,under_investigation,protection_measures,resolved,dismissed,closed,report_issued)=received
  severity enum(low,medium,high,critical) ; received_on date! ; admitted_on ; due_on ; resolved_on ; resolution_enc
  harassment_committee_id ?-> harassment_committees ; uniq tracking_code_hash
  procedure_stage enum(received,referred_to_committee,investigating,report_issued,decided,closed) ; started_ex_officio bool=f ; ledger_chain_key
@complaint_identities sens nots | Identidad del denunciante SOLO en denuncias identificadas, cifrada y sin clave foránea a persona o usuario; un trigger impide insertarla si la denuncia es anónima.
  complaint_id -> complaints ; reporter_name_enc text! ; reporter_contact_enc ; reporter_reference_enc
  consent_to_disclose bool=f ; uniq complaint_id
@complaint_messages sens nots | Mensajes entre el denunciante (por código de seguimiento) y los investigadores; cifrados y solo con fecha.
  complaint_id -> complaints ; author_kind enum(reporter,investigator,system)! ; investigator_user_id ?-> users
  body_enc text! ; sent_on date! ; read_by_counterpart bool=f
  check author_kind <> 'reporter' OR investigator_user_id IS NULL
@complaint_attachments sens nots | Evidencia adjunta; los metadatos del archivo se eliminan antes de guardar.
  complaint_id -> complaints ; complaint_message_id ?-> complaint_messages ; stored_file_id -> stored_files
  description_enc ; metadata_stripped bool=t
@complaint_assignments | Investigadores y miembros de comité autorizados sobre una denuncia. Sin asignación no hay acceso.
  complaint_id -> complaints ; user_id -> users ; role enum(lead_investigator,investigator,committee_member,observer)!
  assigned_at ts=now ; revoked_at ; has_conflict_of_interest ; uniq complaint_id, user_id
@complaint_events imm sens nots | Trazabilidad inalterable del caso (admisión, asignación, medidas, resolución, accesos) sin datos del denunciante.
  complaint_id -> complaints
  event enum(received,admitted,assigned,status_changed,measure_issued,hearing,report_issued,resolution,notified,closed,accessed)!
  actor_user_id ?-> users ; detail_enc ; occurred_on date!
@complaint_protection_measures lock sens | Medidas de protección a la persona afectada, con plazo de dictado e implementación (E-15).
  complaint_id -> complaints
  measure enum(rotation,suspension_of_accused,restraining,remote_work,psychological_support,other)!
  beneficiary_employment_id ?-> employments ; issued_on date! ; due_on ; implemented_on ; detail_enc
  status enum(ordered,implemented,lifted)=ordered

#E DIS | Casos disciplinarios
@disciplinary_cases main soft lock sens | Caso disciplinario; información altamente confidencial, cifrada.
  employment_id -> employments ; number text! ; opened_on date!
  kind enum(misconduct,performance,attendance,policy_violation,other)! ; summary_enc text!
  severity enum(minor,serious,very_serious)
  status enum(open,charges_notified,defense_received,under_review,sanctioned,dismissed,closed,awaiting_defense,decided)=open
  owner_user_id ?-> users ; defense_due_on ; closed_on ; uniq number
@disciplinary_case_events sens | Hitos del caso: incidente, carta de imputación, descargo, audiencia, pruebas, decisión.
  disciplinary_case_id -> disciplinary_cases
  kind enum(incident,charge_letter,defense,hearing,evidence,decision,appeal,note)! ; occurred_on date! ; detail_enc
  document_id ?-> documents ; author_user_id ?-> users
@disciplinary_sanctions lock sens | Medida disciplinaria aplicada; siempre decidida por una persona identificada (regla 9).
  disciplinary_case_id -> disciplinary_cases ; employment_id -> employments
  kind enum(verbal_warning,written_warning,suspension,dismissal,other)! ; effective_on date! ; suspension_days smallint
  detail_enc ; document_id ?-> documents ; decided_by_user_id -> users ; payroll_incident_id ?-> payroll_incidents
  expires_on

#E ACT | Activos, EPP y uniformes
@asset_categories soft | Categorías de activos.
  code ; name ; kind enum(equipment,software_license,uniform,card,vehicle,other)! ; requires_return bool=t ; uniq code
@assets main soft lock | Activo de la empresa asignable a colaboradores.
  asset_category_id -> asset_categories ; code ; name ; serial_number ; brand ; model
  status enum(available,assigned,maintenance,lost,retired)=available ; site_id ?-> sites
  legal_entity_id ?-> legal_entities ; purchased_on ; purchase_value money ; currency cur? ; warranty_until date
  license_expires_on ; notes ; uniq code
@asset_assignments lock | Asignación de un activo a un colaborador, con acta de entrega y devolución. Un activo no puede tener dos asignaciones a la vez.
  asset_id -> assets ; employment_id -> employments ; assigned_on date! ; expected_return_on ; returned_on
  condition_out ; condition_in ; delivery_document_id ?-> documents ; return_document_id ?-> documents
  status enum(active,returned,lost,damaged)=active
  raw EXCLUDE USING gist (tenant_id WITH =, asset_id WITH =, daterange(assigned_on, returned_on, '[]') WITH &&)
@asset_events imm | Historial inalterable del activo.
  asset_id -> assets ; event enum(created,assigned,returned,maintenance_start,maintenance_end,lost,retired,updated)!
  employment_id ?-> employments ; note ; actor_user_id ?-> users ; occurred_at ts=now
@ppe_items soft lock | Catálogo de equipos de protección personal y uniformes (E-12), con escala de tallas y vida útil.
  code ; name ; kind enum(ppe,uniform)! ; size_scale ; useful_life_days integer ; standard_reference ; stock_tracking bool=f
  uniq code
@ppe_requirements | Qué equipo corresponde por puesto, sede o riesgo identificado.
  ppe_item_id -> ppe_items ; job_profile_id ?-> job_profiles ; site_id ?-> sites
  risk_matrix_item_id ?-> risk_matrix_items ; quantity smallint=1 ; renewal_days integer
@employment_sizes | Tallas del colaborador por escala.
  employment_id -> employments ; size_scale text! ; size_value text! ; uniq employment_id, size_scale
@ppe_deliveries lock | Entrega de equipos firmada por el colaborador.
  employment_id -> employments ; delivered_on date! ; delivered_by_user_id ?-> users
  signature_request_id ?-> signature_requests ; signature_file_id ?-> stored_files ; document_id ?-> documents
  status enum(draft,delivered,signed)=draft
@ppe_delivery_items | Equipos entregados, con talla, vencimiento y motivo de reposición.
  ppe_delivery_id -> ppe_deliveries ; ppe_item_id -> ppe_items ; quantity smallint=1 ; size_value ; expires_on
  returned_on ; replacement_reason enum(initial,renewal,damage,loss)

#E GAS | Gastos, viáticos y reembolsos
@expense_categories soft | Categorías de gasto.
  code ; name ; accounting_account_id ?-> accounting_accounts ; requires_receipt bool=t ; is_per_diem ; uniq code
@expense_policies soft lock | Política de gastos con vigencia (E-23).
  name ; legal_entity_id ?-> legal_entities ; currency cur ; receipt_required_above money
  submission_deadline_days integer ; approval_workflow_definition_id ?-> workflow_definitions ; vigcols
@expense_policy_limits | Topes por categoría, nivel o puesto y zona de destino.
  expense_policy_id -> expense_policies ; expense_category_id -> expense_categories ; job_level_id ?-> job_levels
  job_profile_id ?-> job_profiles ; destination_zone ; limit_amount money!
  limit_period enum(per_item,per_day,per_trip,per_month)! ; currency cur
@travel_requests soft lock | Solicitud de viaje o viáticos con anticipo.
  employment_id -> employments ; purpose text! ; destination text! ; starts_on date! ; ends_on date!
  estimated_amount money ; advance_amount money ; currency cur ; cost_center_id ?-> cost_centers
  project_id ?-> projects ; status enum(draft,pending,approved,rejected,advanced,settled,cancelled)=draft
  workflow_instance_id ?-> workflow_instances
@expense_advances lock | Anticipo entregado y su rendición; el saldo no rendido puede descontarse por planilla.
  employment_id -> employments ; travel_request_id ?-> travel_requests ; amount money! ; currency cur ; paid_on
  payroll_payment_item_id ?-> payroll_payment_items ; settled_amount money=0
  status enum(requested,paid,partially_settled,settled,deducted,approved,pending_settlement,overdue,closed,rejected)=requested ; payroll_incident_id ?-> payroll_incidents
@expense_reports main soft lock | Rendición de gastos con su flujo colaborador → jefe → finanzas → pago.
  employment_id -> employments ; number text! ; title ; travel_request_id ?-> travel_requests
  expense_policy_id ?-> expense_policies ; total_amount money=0 ; reimbursable_amount money=0 ; currency cur
  cost_center_id ?-> cost_centers
  status enum(draft,submitted,manager_approved,finance_approved,rejected,paid,cancelled,observed)=draft ; submitted_at
  workflow_instance_id ?-> workflow_instances ; paid_on ; payroll_payment_item_id ?-> payroll_payment_items ; uniq number
@expense_items lock | Gasto con comprobante, categoría, centro de costo y control de política.
  expense_report_id -> expense_reports ; expense_category_id -> expense_categories ; expense_date date! ; merchant
  merchant_tax_id ; receipt_kind enum(invoice,receipt,ticket,affidavit,none) ; receipt_number ; amount money!
  tax_amount money ; currency cur ; exchange_rate numeric(18,8) ; amount_in_report_currency money
  receipt_file_id ?-> stored_files ; cost_center_id ?-> cost_centers ; project_id ?-> projects ; policy_violation
  is_reimbursable bool=t ; description

#E CUM | Cumplimiento Perú y SUNAFIL
@compliance_obligation_templates P cat | Catálogo de obligaciones por país (comité de SST, comité de hostigamiento, política salarial, seguros, cuota, lactario, registros) con su regla de aplicabilidad.
  country_code ~> countries.code ; code ; name ; legal_basis ; description ; applicability_rule j
  frequency enum(once,monthly,semiannual,annual,on_event,continuous)! ; evidence_hint ; module_code ?~> modules.code
  uniq code
@compliance_obligations main soft lock | Obligación de una empresa o sede con semáforo, responsable, vencimiento y explicación de por qué aplica (E-01).
  legal_entity_id -> legal_entities ; site_id ?-> sites ; template_code ?~> compliance_obligation_templates.code
  name ; applies boolean ; applicability_explanation
  status enum(compliant,at_risk,non_compliant,not_applicable,unknown)=unknown ; responsible_user_id ?-> users ; due_on
  last_checked_at ; next_review_on ; notes
@compliance_evidences | Evidencias que sustentan el cumplimiento de una obligación.
  compliance_obligation_id -> compliance_obligations ; document_id ?-> documents ; description text! ; period_code
  valid_until date ; uploaded_by_user_id ?-> users
@compliance_indicators | Indicadores calculados por empresa, sede y periodo (número de trabajadores, cuota de discapacidad, mujeres en edad fértil) con explicación.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; indicator_code text! ; period_code text!
  value numeric(14,4)! ; threshold numeric(14,4) ; explanation ; computed_at ts! ; idx indicator_code, period_code
@inspection_cases main soft lock | Actuación de la autoridad inspectiva: orden, requerimiento, acta o procedimiento sancionador (E-02).
  legal_entity_id -> legal_entities ; authority enum(sunafil,mtpe,essalud,municipal,other)=sunafil ; case_number text!
  kind enum(inspection_order,requirement,infraction_record,sanction_procedure,other)! ; subject ; opened_on date!
  status enum(open,responding,hearing,resolved,sanctioned,appealed,closed)=open ; responsible_user_id ?-> users
  fine_amount money ; currency cur?
@inspection_requirements lock | Requerimientos de información de una inspección, con responsable y plazo.
  inspection_case_id -> inspection_cases ; description text! ; due_at ts! ; responsible_user_id ?-> users
  status enum(pending,in_progress,submitted,overdue)=pending ; submitted_at ; response_document_id ?-> documents
@mailbox_notices lock | Bandeja de la casilla electrónica: notificaciones recibidas con responsable y plazo (E-02).
  legal_entity_id -> legal_entities ; authority enum(sunafil,mtpe,sunat,other)=sunafil ; notice_reference text!
  received_on date! ; effective_on ; subject ; document_id ?-> documents ; inspection_case_id ?-> inspection_cases
  responsible_user_id ?-> users ; due_on ; status enum(new,assigned,answered,archived,in_preparation,overdue)=new ; read_at
  uniq legal_entity_id, authority, notice_reference
  deposited_at ; notice_kind enum(information_requirement,appearance_summons,inspection_measure,infraction_record,charges,final_report,resolution,coercive,other)
  response_days numeric(6,2) ; response_day_kind enum(business,calendar) ; captured_by_user_id ?-> users ; content_sha256
  check content_sha256 IS NULL OR content_sha256 ~ '^[0-9a-f]{64}$'
@inspection_dossiers lock | Expediente de inspección exportable por trabajador y periodo (E-02).
  legal_entity_id -> legal_entities ; inspection_case_id ?-> inspection_cases ; title ; period_from date! ; period_to date!
  dossier_scope j ; status enum(building,ready,exported,failed)=building ; stored_file_id ?-> stored_files
  generated_by_user_id ?-> users ; generated_at
@inspection_dossier_items | Piezas del expediente (boletas, asistencia, contratos, SST, constancias) y marca de faltante.
  inspection_dossier_id -> inspection_dossiers ; employment_id ?-> employments
  section enum(payslips,attendance,contracts,safety,certificates,insurance,other)! ; document_id ?-> documents
  source_type ; source_id uuid ; period_code ; is_missing
@compliance_ledger_entries imm nots sens | Registro inalterable de actos de cumplimiento (comités, elecciones, actas, procedimiento de hostigamiento, casilla). Cada fila guarda la huella de la anterior de su cadena. Sin IP, agente de usuario ni sesión.
  chain_key text! ; seq bigint! ; entry_type text! ; subject_type text! ; subject_id uuid! ; payload j! #S ; payload_canonical text! #S
  payload_hash text! ; prev_hash text! ; entry_hash text! ; hash_algorithm text=SHA-256
  actor_kind enum(user,system,anonymous_reporter,committee)! ; actor_user_id ?-> users ; signer_role
  signature_event_id ?-> signature_events ; occurred_at ts! ; recorded_at ts=now ; timestamp_token bytea ; timestamp_authority
  retain_until date ; uniq chain_key, seq ; uniq chain_key, entry_hash ; idx subject_type, subject_id ; check seq >= 1
  check payload_hash ~ '^[0-9a-f]{64}$' AND prev_hash ~ '^[0-9a-f]{64}$' AND entry_hash ~ '^[0-9a-f]{64}$'
  check payload_canonical::jsonb = payload
  check actor_kind <> 'anonymous_reporter' OR (actor_user_id IS NULL AND signature_event_id IS NULL)
  check actor_kind <> 'user' OR actor_user_id IS NOT NULL
@compliance_ledger_anchors imm nots | Huella raíz de todas las cabezas de cadena de la organización en un instante, sellada por una autoridad de sellado de tiempo.
  anchored_at ts=now ; chain_heads j! ; root_hash text! ; timestamp_token bytea ; timestamp_authority
  check root_hash ~ '^[0-9a-f]{64}$'
@compliance_deadlines lock | Plazo legal en curso sobre un sujeto: guarda el parámetro y el valor con que se calculó el vencimiento, para que un cambio posterior del parámetro no altere plazos ya iniciados.
  legal_entity_id -> legal_entities ; subject_type text! ; subject_id uuid! ; deadline_code text! ; parameter_code text!
  parameter_value numeric(18,6)! ; day_kind enum(business,calendar,hours)! ; starts_at ts! ; due_at ts! ; met_at
  responsible_user_id ?-> users ; status enum(running,met,met_late,overdue,cancelled)=running
  ledger_entry_id ?-> compliance_ledger_entries ; uniq subject_type, subject_id, deadline_code ; idx status, due_at
  check due_at >= starts_at
@authority_communications lock | Comunicaciones obligatorias a la autoridad (MTPE, SUNAFIL) con su plazo, cargo de presentación y estado.
  legal_entity_id -> legal_entities ; authority enum(mtpe,sunafil,sunat,essalud,other)! ; subject_type text! ; subject_id uuid!
  kind enum(harassment_complaint_received,harassment_decision,work_accident,committee_installation,requirement_response,other)!
  due_at ; sent_at ; channel ; receipt_reference ; receipt_document_id ?-> documents ; contains_identity bool=f
  status enum(pending,sent,acknowledged,overdue,not_required)=pending ; uniq subject_type, subject_id, kind
@evidence_exports imm | Paquete de expediente exportado: manifiesto con la huella de cada pieza y las cabezas de cadena en el momento de la exportación.
  subject_type text! ; subject_id uuid! ; requested_by_user_id -> users ; purpose text! ; includes_identity bool=f
  manifest j! ; manifest_sha256 text! ; chain_heads j! ; package_file_id ?-> stored_files ; package_sha256 ; timestamp_token bytea
  idx subject_type, subject_id ; check manifest_sha256 ~ '^[0-9a-f]{64}$' ; check package_sha256 IS NULL OR package_sha256 ~ '^[0-9a-f]{64}$'

#E PRI | Privacidad y retención
@data_banks soft lock | Bancos de datos personales y su inscripción (E-16).
  legal_entity_id -> legal_entities ; name ; registry_code ; purpose ; data_categories text[] ; contains_sensitive
  hosting_country ; cross_border_transfer ; security_measures ; registered_on
  status enum(draft,registered,updated,cancelled)=draft
@processing_purposes soft | Finalidades de tratamiento con su base legal.
  code ; name ; legal_basis enum(consent,contract,legal_obligation,legitimate_interest,vital_interest)! ; description
  data_categories text[] ; requires_consent ; data_bank_id ?-> data_banks ; uniq code
@consents main lock | Consentimiento otorgado o revocado por una persona o candidato para una finalidad, con evidencia.
  person_id ?-> people ; candidate_id ?-> candidates ; processing_purpose_id -> processing_purposes ; version text!
  text_hash text! ; status enum(granted,revoked,expired)=granted ; granted_at ts! ; revoked_at
  channel enum(portal,career_site,paper,whatsapp,kiosk)! ; evidence j ; document_id ?-> documents
  check num_nonnulls(person_id, candidate_id) = 1
@data_subject_requests lock | Solicitudes de derechos del titular (acceso, rectificación, cancelación, oposición, portabilidad) con plazo.
  person_id ?-> people ; requester_name_enc ; requester_contact_enc
  kind enum(access,rectification,cancellation,opposition,portability,information)! ; description ; received_on date!
  due_on date! ; status enum(received,identity_verified,in_progress,answered,rejected,closed,verifying_identity)=received
  assignee_user_id ?-> users ; response_document_id ?-> documents ; answered_on
@privacy_incidents lock sens | Incidente de seguridad de datos personales, con el plazo de notificación a la autoridad.
  legal_entity_id ?-> legal_entities ; detected_at ts! ; notification_due_at ts! ; description_enc text!
  data_categories text[] ; affected_count integer ; severity enum(low,medium,high) ; authority_notified_at
  subjects_notified_at ; containment ; status enum(detected,assessing,notified,contained,closed)=detected
  owner_user_id ?-> users
@impact_assessments soft lock | Evaluación de impacto para tratamientos de alto riesgo: biometría, perfilado con IA, datos sensibles (C-08).
  legal_entity_id ?-> legal_entities ; title
  processing_kind enum(biometric,ai_profiling,large_scale,sensitive_data,cross_border,other)! ; description ; risks j
  mitigations j ; conclusion ; approved_by_user_id ?-> users ; approved_on ; next_review_on
  status enum(draft,in_review,approved,rejected,outdated)=draft ; related_feature ; document_id ?-> documents
@retention_policies soft lock | Política de retención, anonimización o eliminación por tipo de dato y jurisdicción.
  code ; name ; entity_type text! ; data_category ; retention_years numeric(5,2)! ; retention_parameter_code
  start_event enum(creation,termination,closing,last_activity)! ; action enum(delete,anonymize,archive)! ; jurisdiction
  legal_basis ; uniq code
@retention_runs ao | Ejecuciones de una política de retención (incluye simulaciones).
  retention_policy_id -> retention_policies ; executed_at ts! ; action text! ; affected_count integer! ; dry_run bool=t
  executed_by_user_id ?-> users ; summary j
@legal_holds soft | Suspensión de la eliminación por litigio o inspección.
  name ; reason text! ; entity_type ; entity_id uuid ; person_id ?-> people ; starts_on date! ; released_on
  created_by_user_id ?-> users

#E SIN | Sindicatos y convenios
@unions soft lock | Organización sindical y regla de cuota (E-17).
  legal_entity_id -> legal_entities ; name ; registry_number ; kind enum(company,industry,federation)=company
  dues_concept_id ?-> payroll_concepts ; dues_calculation enum(fixed,percentage)=fixed ; dues_amount money
  dues_percentage numeric(7,4) ; currency cur? ; status
@union_memberships soft lock sens | Afiliación sindical con vigencia. Dato sensible: acceso por permiso de campo y registro de accesos.
  union_id -> unions ; employment_id -> employments ; is_representative ; representative_role ; dues_authorized
  authorization_document_id ?-> documents ; vig union_id, employment_id
@collective_agreements soft lock | Convenio colectivo.
  union_id -> unions ; legal_entity_id -> legal_entities ; title ; signed_on ; valid_from date! ; valid_to date
  coverage enum(members_only,all_workers)=members_only ; document_id ?-> documents
  status enum(negotiating,active,expired)=negotiating
@collective_agreement_clauses lock | Cláusulas del convenio; las económicas se enlazan a un concepto de planilla.
  collective_agreement_id -> collective_agreements ; clause_number ; title ; description
  kind enum(economic,working_conditions,leave,other)! ; payroll_concept_id ?-> payroll_concepts ; amount money
  currency cur? ; valid_from date ; valid_to date
@union_leave_balances | Licencias sindicales concedidas y usadas por año.
  union_id -> unions ; employment_id -> employments ; fiscal_year smallint! ; granted_days numeric(6,2)!
  used_days numeric(6,2)=0 ; uniq union_id, employment_id, fiscal_year

#F 8 | Inteligencia

#E ANA | People analytics y tableros
@metric_definitions P cat | Catálogo de indicadores con la descripción de su fórmula y el permiso que exigen.
  code ; name ; description ; module_code ?~> modules.code ; unit ; formula_description text! ; is_sensitive
  required_permission_code ?~> permissions.code ; uniq code
@metric_snapshots | Valores agregados de un indicador por periodo y segmento; guarda el tamaño de muestra para no exponer grupos pequeños.
  metric_code ~> metric_definitions.code ; period_code text! ; legal_entity_id ?-> legal_entities
  site_id ?-> sites ; org_unit_id ?-> org_units ; dimension_key text= ; value numeric(18,4)!
  sample_size integer ; computed_at ts! ; idx metric_code, period_code
@dashboards main soft lock | Tablero personalizable por usuario o por rol.
  name ; owner_user_id ?-> users ; role_id ?-> roles ; is_default ; is_shared ; layout j
@dashboard_widgets lock | Componentes de un tablero.
  dashboard_id -> dashboards ; kind enum(kpi,chart,table,list,alert_feed,shortcut)! ; title
  metric_code ?~> metric_definitions.code ; report_definition_id ?-> report_definitions ; config j={} ; grid_position j

#E RPT | Reportes y constructor
@report_definitions main soft lock | Reporte guardado (predefinido o del constructor): fuente, campos, filtros, agrupaciones, métricas y visualización.
  name ; description ; kind enum(predefined,custom)=custom ; data_source text! ; fields j! ; filters j ; groupings j
  metrics j ; visualization j ; owner_user_id ?-> users ; is_shared ; contains_sensitive
  required_permission_codes text[] ; system_code
@report_schedules lock | Programación de entrega automática de un reporte.
  report_definition_id -> report_definitions ; cron text! ; timezone ; format enum(xlsx,csv,pdf)! ; recipients j!
  is_active bool=t ; last_run_at ; next_run_at
@export_jobs lock | Exportación masiva en cola (hoja de cálculo, CSV, PDF, comprimido) que respeta permisos y marca si lleva datos sensibles.
  resource text! ; format enum(xlsx,csv,pdf,zip)! ; filters j ; columns j ; requested_by_user_id -> users
  status enum(queued,running,completed,failed,expired)=queued ; stored_file_id ?-> stored_files ; row_count integer
  contains_sensitive ; expires_at ; download_count integer=0 ; error
@report_runs ao | Ejecuciones de reportes.
  report_definition_id -> report_definitions ; report_schedule_id ?-> report_schedules ; requested_by_user_id ?-> users
  parameters j ; status enum(queued,running,completed,failed)=queued ; row_count integer
  export_job_id ?-> export_jobs ; started_at ; finished_at ; error

#E ALE | Alertas explicadas
@alert_rules soft lock | Regla de alerta con su plantilla de explicación y audiencia.
  code ; name ; module_code ?~> modules.code ; description ; condition j! ; threshold_parameter_code
  severity enum(info,warning,critical)=warning ; audience j ; schedule_cron ; is_system ; is_active bool=t
  explanation_template text! ; uniq code
@alerts main lock | Alerta generada. La explicación es obligatoria: nunca se muestra una alerta sin decir por qué.
  alert_rule_id ?-> alert_rules ; title ; explanation text! CHECK (char_length(btrim(explanation)) > 0)
  severity enum(info,warning,critical)! ; subject_type ; subject_id uuid ; legal_entity_id ?-> legal_entities
  org_unit_id ?-> org_units ; data j ; status enum(open,acknowledged,resolved,dismissed,expired)=open
  acknowledged_by_user_id ?-> users ; acknowledged_at ; resolved_at ; dedupe_key ; idx status
@alert_recipients | Destinatarios de una alerta.
  alert_id -> alerts ; user_id -> users ; seen_at ; uniq alert_id, user_id
@attrition_risk_scores sens | Riesgo de rotación explicable (E-26): factores visibles y explicación obligatoria. No dispara decisiones automáticas.
  employment_id -> employments ; computed_on date! ; risk_level enum(low,medium,high)! ; score numeric(5,4)
  factors j! ; model_version text! ; explanation text! ; reviewed_by_user_id ?-> users
  uniq employment_id, computed_on

#E IAR | Asistente de IA y gobierno
@ai_policies lock | Gobierno de IA por organización: casos de uso permitidos, proveedor, residencia de datos, retención y evaluación de impacto.
  enabled bool=f ; allowed_use_cases text[] ; provider ; data_residency ; redact_sensitive bool=t ; retention_days integer
  impact_assessment_id ?-> impact_assessments ; human_review_required bool=t ; raw UNIQUE (tenant_id)
@ai_interactions imm main sens | Bitácora inalterable de cada uso de IA (C-08): quién, con qué permisos, qué datos consultó y qué respondió.
  user_id ?-> users ; actor_kind enum(user,mcp_client,automation)=user ; use_case text! ; prompt_enc text! ; response_enc
  data_scopes text[]! ; permissions_snapshot text[] ; referenced_entities j ; model ; provider ; input_tokens integer
  output_tokens integer ; was_blocked bool=f ; block_reason ; high_impact_flag bool=f ; api_token_id ?-> api_tokens
  occurred_at ts=now
@ai_use_case_settings lock | Activación por caso de uso de IA, incluido el asistente de ayuda (`help_assistant`): encendido, límite de preguntas por usuario y aviso. Sin fila, el caso de uso está apagado (RN-IAR-05).
  use_case text! ; is_enabled bool=f ; question_limit integer ; limit_period enum(day,week,month)=day ; notice
  changed_by_user_id ?-> users ; uniq use_case
@ai_use_case_roles | Roles habilitados para un caso de uso de IA; sin filas, ningún rol lo ve.
  ai_use_case_setting_id -> ai_use_case_settings ; role_id -> roles ; uniq ai_use_case_setting_id, role_id
@ai_feedback | Valoración del usuario sobre una respuesta de IA.
  ai_interaction_id -> ai_interactions ; user_id -> users ; rating enum(up,down)! ; comment
  uniq ai_interaction_id, user_id
@ai_suggestions lock | Borrador o sugerencia de IA que solo surte efecto si una persona lo acepta (regla 9).
  ai_interaction_id -> ai_interactions ; subject_type ; subject_id uuid ; kind text! ; content j!
  status enum(proposed,accepted,edited,rejected)=proposed ; decided_by_user_id ?-> users ; decided_at

#F 9 | Ecosistema y expansión

#E API | API pública, webhooks y MCP
@api_tokens soft lock | Token de API con alcances (scopes); solo se guarda su huella.
  name ; token_prefix text! ; token_hash text! ; owner_user_id ?-> users ; scopes text[]!
  kind enum(personal,service,mcp,partner)=service ; ip_allowlist cidr[] ; expires_at ; last_used_at ; revoked_at
  rate_limit_per_minute integer ; uniq token_hash
@oauth_clients soft lock | Clientes autorizados (asistentes de IA por MCP, integraciones, socios).
  name ; client_id text! ; client_secret_hash ; redirect_uris text[] ; scopes text[] ; kind enum(mcp,integration,partner)!
  is_confidential bool=t ; status ; uniq client_id
@webhook_endpoints main soft lock | Destino de webhooks con secreto de firma y tipos de evento suscritos.
  name ; url text! ; secret_enc text! ; event_types text[]! ; status enum(active,paused,disabled_by_failures)=active
  api_version ; failure_count integer=0 ; last_success_at ; last_failure_at ; created_by_user_id ?-> users
@webhook_deliveries | Entregas de webhooks con reintentos (salen del outbox).
  webhook_endpoint_id -> webhook_endpoints ; outbox_event_id ?-> outbox_events ; event_type text! ; payload j!
  attempt integer=1 ; status enum(pending,delivered,failed,dead)=pending ; response_status integer ; response_body
  duration_ms integer ; next_attempt_at ; delivered_at ; idx status, next_attempt_at
@api_request_logs ao part | Uso de la API por token o cliente (medición y límites); no es el log técnico.
  api_token_id ?-> api_tokens ; oauth_client_id ?-> oauth_clients ; method text! ; path text! ; status_code integer!
  duration_ms integer ; ip inet ; request_id uuid ; occurred_at ts=now ; idx occurred_at

#E INT | Integraciones
@integration_connectors P cat | Catálogo de conectores (ERP, contabilidad, bancos, biométricos, correo, calendario, SSO, firma, mensajería, billeteras), todos con adaptador falso.
  code ; name
  category enum(erp,accounting,bank,biometric,email,calendar,sso,signature,ats,lms,messaging,wallet,storage,other)!
  has_fake_adapter bool=t ; config_schema j ; status ; uniq code
@integrations main soft lock | Integración configurada por la organización; credenciales cifradas.
  connector_code ~> integration_connectors.code ; name ; legal_entity_id ?-> legal_entities ; config j ; credentials_enc
  status enum(draft,active,error,paused)=draft ; use_fake_adapter bool=f ; last_sync_at ; last_error
@integration_sync_runs ao | Ejecuciones de sincronización.
  integration_id -> integrations ; direction enum(inbound,outbound)! ; resource text!
  status enum(running,succeeded,partial,failed)! ; started_at ts! ; finished_at ; records_total integer
  records_failed integer ; log j ; error
@integration_mappings | Correspondencia entre registros propios e identificadores externos.
  integration_id -> integrations ; entity_type text! ; entity_id uuid! ; external_id text! ; external_data j ; synced_at
  uniq integration_id, entity_type, entity_id ; idx integration_id, entity_type, external_id
@sso_connections soft lock | Conexión de inicio de sesión único (SAML u OIDC).
  name ; protocol enum(saml,oidc)! ; issuer ; metadata_url ; client_id ; client_secret_enc ; certificate ; domains text[]
  auto_provision bool=f ; default_role_id ?-> roles ; status enum(draft,active,inactive)=draft

#E WHA | Autoservicio por WhatsApp
@whatsapp_contacts lock | Número de WhatsApp verificado de una persona, con consentimiento (E-11).
  person_id ?-> people ; user_id ?-> users ; phone_hash text! ; phone_enc text! ; opted_in_at ; opted_out_at
  verified_at ; consent_id ?-> consents ; uniq phone_hash
@whatsapp_conversations | Conversación de autoservicio.
  whatsapp_contact_id -> whatsapp_contacts ; status enum(open,closed)=open ; intent ; last_message_at
  session_expires_at ; ticket_id ?-> tickets
@whatsapp_messages ao | Mensajes entrantes y salientes y la acción que ejecutaron (boleta, vacaciones, constancia, marcación).
  whatsapp_conversation_id -> whatsapp_conversations ; direction enum(inbound,outbound)!
  kind enum(text,template,document,image,location,interactive)! ; body_enc ; template_code
  stored_file_id ?-> stored_files ; provider_message_id ; status enum(queued,sent,delivered,read,failed)=queued
  action_type ; action_ref uuid ; error ; occurred_at ts=now
@whatsapp_templates soft | Plantillas de mensaje aprobadas por el proveedor.
  code ; locale text=es ; category ; body text! ; provider_status enum(draft,submitted,approved,rejected)=draft
  variables j ; uniq code, locale

#E PAI | Paquetes de país
@country_packs P cat | Paquete de país: normativa, nómina, feriados, documentos, declaraciones y reglas.
  country_code ~> countries.code ; code ; name ; status enum(draft,available,deprecated)=draft ; current_version
  uniq code
@country_pack_versions P cat | Versiones publicadas de un paquete de país.
  country_pack_id => country_packs ; version text! ; released_on ; changelog ; contents j
  uniq country_pack_id, version
@country_holidays P cat | Feriados oficiales por país y región, base de los calendarios de cada organización.
  country_code ~> countries.code ; holiday_date date! ; name ; kind ; region_code
  uniq country_code, holiday_date, name
@country_concept_templates P cat | Plantillas de conceptos de planilla del paquete de país, con vigencia y marca de verificación.
  country_code ~> countries.code ; code ; name ; kind text! ; statutory_code ; formula ; affectations j
  labor_regime_code ?~> labor_regimes.code ; valid_from date! ; valid_to date ; verified bool=f
  uniq country_code, code, valid_from
@country_document_templates P cat | Plantillas de documentos del paquete de país (contratos, certificados, políticas).
  country_code ~> countries.code ; code ; name ; kind ; body text! ; locale text=es ; uniq country_code, code, locale
@tenant_country_packs lock | Paquetes de país instalados por la organización o por empresa.
  country_pack_code ~> country_packs.code ; legal_entity_id ?-> legal_entities ; installed_version text!
  installed_at ts! ; auto_update_parameters bool=t ; status
  raw UNIQUE NULLS NOT DISTINCT (tenant_id, country_pack_code, legal_entity_id)
@pe_parametric_tables P cat | Tablas paramétricas de la Planilla Electrónica del Perú (número y nombre oficial del Anexo 2).
  table_number smallint! ; name ; source_annex ; uniq table_number
@pe_parametric_items P cat | Códigos de cada tabla paramétrica con vigencia; attributes guarda datos propios de la tabla (por ejemplo afectaciones de la tabla 22).
  table_number smallint! ; code ; description text! ; short_description ; attributes j ; private_sector bool=t ; source
  verified bool=f ; idx table_number, code ; vig table_number, code
  raw FOREIGN KEY (table_number) REFERENCES pe_parametric_tables (table_number)
@peruvian_employee_profiles main lock sens | Datos laborales del T-Registro que no viven en el núcleo, con vigencia por relación laboral. No duplica people, employments, contracts ni afiliaciones.
  employment_id -> employments ; worker_type_code text! ; labor_regime_sunat_code text! ; occupational_category_code
  occupation_code text! ; education_status_code text! ; contract_type_sunat_code text! ; has_disability #S ; is_unionized #S
  alternative_working_regime bool=f ; subject_to_max_working_time bool=t ; subject_to_night_shift bool=f
  special_situation_code text=0 ; payment_type_code text! ; pay_periodicity_code text! ; initial_basic_remuneration money! #S
  currency cur ; worker_situation_code text=1 ; fifth_category_exempt bool=f ; tax_treaty_code text=0 ; is_tax_resident bool=t
  cas_tax_id ; eps_code ; sctr_health enum(none,essalud,eps)=none ; sctr_pension enum(none,onp,insurer)=none ; essalud_vida bool=f
  agrarian_benefit_payment enum(prorated_daily,semiannual) ; completeness_status enum(draft,ready,blocked)=draft
  missing_fields text[] ; personnel_action_id ?-> personnel_actions ; vig employment_id
  check initial_basic_remuneration > 0 ; check cas_tax_id IS NULL OR cas_tax_id ~ '^[0-9]{11}$'
@peruvian_employment_establishments lock | Establecimientos (RUC y código de establecimiento anexo) donde labora el trabajador, con vigencia.
  employment_id -> employments ; site_id ?-> sites ; establishment_tax_id text! ; establishment_code text! ; is_third_party
  check establishment_tax_id ~ '^[0-9]{11}$' ; check establishment_code ~ '^[0-9]{4}$'
  vig employment_id, establishment_tax_id, establishment_code
@peruvian_registry_periods lock | Periodos informados al T-Registro por tipo de registro (periodo laboral, tipo de trabajador, régimen de salud, régimen pensionario, SCTR salud): lo declarado, no la fuente del dato.
  employment_id -> employments ; category enum(worker,pensioner,third_party,trainee)=worker
  record_type enum(period,worker_type,health_regime,pension_regime,sctr_health)! ; starts_on date! ; ends_on ; indicator_code
  eps_code ; registry_event_id ?-> registry_events ; declared_at ; check ends_on IS NULL OR ends_on >= starts_on
  check record_type <> 'period' OR ends_on IS NULL OR indicator_code IS NOT NULL
  raw EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, record_type WITH =, daterange(starts_on, ends_on, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE
@peruvian_person_profiles lock | Códigos SUNAT de la persona: país emisor del documento (tabla 26), nacionalidad (tabla 4) y código de larga distancia (tabla 29).
  person_id -> people ; document_issuing_country_code ; nationality_sunat_code ; phone_area_code ; uniq person_id
@peruvian_person_addresses lock sens | Dirección estructurada del T-Registro (vía, zona, ubigeo) para una dirección del núcleo.
  person_address_id -> person_addresses ; address_slot smallint=1 ; via_type_code ; via_name_enc ; via_number ; department
  interior ; block_mz ; lot ; kilometer ; block ; stage ; zone_type_code ; zone_name ; reference_enc ; ubigeo_code text!
  is_essalud_reference ; uniq person_address_id ; check address_slot IN (1, 2) ; check ubigeo_code ~ '^[0-9]{6}$'
  check via_type_code IS NOT NULL OR zone_type_code IS NOT NULL
@peruvian_education_records lock | Estudios superiores concluidos codificados con la tabla 34 (institución y carrera) y la tabla 9.
  person_education_id -> person_education ; education_status_code text! ; is_peruvian_institution bool=t ; institution_code
  career_code ; graduation_year smallint ; uniq person_education_id ; check graduation_year IS NULL OR graduation_year BETWEEN 1940 AND 2100
  check NOT is_peruvian_institution OR (institution_code IS NOT NULL AND career_code IS NOT NULL)
@peruvian_dependent_profiles lock sens | Datos del derechohabiente que pide el T-Registro (vínculo, documento que lo acredita, mes de concepción, motivo de baja) y sustento de asignación escolar.
  dependent_id -> dependents ; family_link_code text! ; link_document_type_code ; link_document_number_enc ; conception_month date
  document_issuing_country_code ; deregistration_reason_code ; school_allowance_until date ; school_proof_file_id ?-> stored_files
  uniq dependent_id ; check conception_month IS NULL OR extract(day FROM conception_month) = 1

#E WLB | Marca blanca
@tenant_brandings lock | Marca propia por organización o empresa: nombre, logotipos, colores, favicon y remitente de correo (según plan).
  legal_entity_id ?-> legal_entities ; product_name ; logo_file_id ?-> stored_files ; logo_dark_file_id ?-> stored_files
  favicon_file_id ?-> stored_files ; primary_color ; accent_color ; email_sender_name ; email_sender_address
  email_footer ; login_message ; theme_tokens j ; raw UNIQUE NULLS NOT DISTINCT (tenant_id, legal_entity_id)

#E EST | Modo estudio contable
@partner_firms P soft | Estudio contable o socio que administra las planillas de varias organizaciones (E-24).
  name ; tax_id ; country_code ; contact_email ; status enum(active,suspended)=active
@partner_firm_users P | Usuarios del estudio en la consola de socio.
  partner_firm_id => partner_firms ; email text! ; full_name ; password_hash text #S
  role enum(owner,accountant,assistant)=accountant ; status ; uniq email
@partner_firm_tenants P | Organizaciones que administra un estudio.
  partner_firm_id => partner_firms ; tenant_id => tenants ; status enum(invited,active,revoked)=invited ; since date
  uniq partner_firm_id, tenant_id
@partner_access_grants lock | Autorización de la organización a un estudio: con qué rol y sobre qué empresa actúa.
  partner_firm_id => partner_firms ; partner_firm_user_id ?=> partner_firm_users ; role_id -> roles
  legal_entity_id ?-> legal_entities ; user_id ?-> users ; granted_by_user_id -> users ; granted_at ts=now ; revoked_at
