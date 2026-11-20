#!/usr/bin/env python3
"""Genera kit/api/openapi.yaml y la tabla de endpoints de kit/docs/08-api.md.

Uso:  python3 kit/tools/gen_openapi.py          (desde la raíz del kit o del repositorio)
      python3 kit/tools/gen_openapi.py --check  (solo valida el archivo existente)

La lista RES es la fuente: un recurso por línea. Para añadir un recurso se agrega
una entrada y se vuelve a ejecutar; no se edita openapi.yaml a mano.

Mini-lenguaje de campos: nombre:tipo  con tipo en
  s texto · i entero · n decimal · b booleano · d fecha · dt fecha-hora · id identificador
  m dinero (objeto Money) · j objeto libre · a lista de textos · e(a|b|c) enumeración
Un «?» al final del nombre marca el campo como opcional/nulo. Un «!» lo marca como
sensible (requiere permiso por campo; se omite de la respuesta si el usuario no lo tiene).
"""
import re
import sys
from pathlib import Path

import yaml

KIT = Path(__file__).resolve().parents[1]
OUT = KIT / "api" / "openapi.yaml"
DOC = KIT / "docs" / "08-api.md"

PHASES = {
    0: "Cimientos", 1: "Core HR", 2: "Tiempo", 3: "Nómina Perú", 4: "Talento",
    5: "Desarrollo y compensación", 6: "Experiencia", 7: "Operación y cumplimiento",
    8: "Inteligencia", 9: "Ecosistema y expansión",
}

EPICS = {
    "SEG": "Identidad, roles y permisos", "SAS": "Consola SaaS", "TEN": "Organización (tenant)",
    "AUD": "Auditoría", "WFL": "Aprobaciones y workflows", "NOT": "Notificaciones",
    "CFG": "Configuración y campos personalizados", "PLT": "Plataforma técnica", "WEB": "Sitio comercial",
    "ORG": "Estructura organizacional", "POS": "Posiciones y headcount", "COL": "Colaboradores",
    "HIS": "Historia con vigencia", "CON": "Contratos y adendas", "DOC": "Documentos y firma",
    "ESS": "Portal del colaborador", "MSS": "Portal del manager", "IMP": "Importador y puesta en marcha", "AYU": "Centro de ayuda",
    "ASI": "Asistencia y marcación", "TUR": "Horarios y turnos", "VAC": "Vacaciones",
    "AUS": "Ausencias y permisos", "BHO": "Horas extra y bolsa de horas", "TSH": "Hojas de tiempo",
    "NOM": "Motor de nómina", "CPT": "Conceptos y fórmulas", "PAR": "Parámetros legales",
    "PER": "Periodos de planilla", "BOL": "Boletas", "BSO": "Beneficios sociales",
    "PRE": "Préstamos y adelantos", "DEC": "Declaraciones y archivos", "PRV": "Provisiones y asientos",
    "LIQ": "Liquidaciones", "LOC": "Locadores",
    "ATS": "Reclutamiento", "ENT": "Entrevistas y ofertas", "ONB": "Onboarding", "OFF": "Offboarding",
    "DES": "Desempeño", "OKR": "Objetivos", "CMP": "Competencias y habilidades", "FBK": "1:1 y feedback",
    "LMS": "Aprendizaje", "CAR": "Carrera y sucesión", "COM": "Compensaciones", "BNF": "Beneficios",
    "ENC": "Encuestas y clima", "REC": "Reconocimientos", "CIN": "Comunicación interna", "HSD": "Mesa de ayuda",
    "SST": "Seguridad y salud", "DEN": "Denuncias y hostigamiento", "DIS": "Casos disciplinarios",
    "ACT": "Activos y EPP", "GAS": "Gastos y viáticos", "CUM": "Cumplimiento Perú", "PRI": "Privacidad",
    "SIN": "Sindicatos y convenios",
    "ANA": "People analytics", "RPT": "Reportes", "ALE": "Alertas", "IAR": "Asistente de IA",
    "API": "API pública y webhooks", "INT": "Integraciones", "WHA": "WhatsApp", "PAI": "Paquetes de país",
    "WLB": "Marca blanca", "EST": "Modo estudio contable",
}

# (épica, fase, permiso-base, ruta, Esquema, campos, operaciones LCRUD, acciones)
RES = [
    # ---------- Fase 0 ----------
    ("SAS", 0, "platform.tenants", "/platform/tenants", "Tenant", "name:s slug:s country:s currency:s timezone:s locale:s status:e(trial|active|grace_period|suspended|blocked|cancelled|deleted) trial_ends_on?:d contact_name?:s contact_email?:s contact_phone?:s suspension_reason?:s cancelled_at?:dt retention_until?:d beta_groups:a", "LCRU", "suspend reactivate cancel"),
    ("SAS", 0, "platform.plans", "/platform/plans", "Plan", "code:s name:s description?:s billing_cycle:e(monthly|annual) price?:m trial_days?:i is_public:b status:e(draft|active|inactive) revision:i superseded_by_plan_id?:id duplicated_from_plan_id?:id modules:a limits:ao features:a subscribers:i allowed_actions:a", "LCRU", "duplicate activate retire"),
    ("SAS", 0, "platform.plans", "/platform/addons", "Addon", "code:s name:s kind:e(module|limit|feature|service) web_product_code?:s modules:a limit_code?:s limit_increment?:s price?:m billing_cycle:e(monthly|annual|one_time) status:e(active|inactive) allowed_actions:a", "LRU", "activate retire"),
    ("SAS", 0, "platform.subscriptions", "/platform/subscriptions", "Subscription", "tenant_id:id tenant?:j plan_id:id plan?:j status:e(trialing|active|past_due|suspended|cancelled|expired) billing_cycle:e(monthly|annual) price?:m starts_on:d ends_on?:d trial_ends_on?:d expired_at?:dt cancelled_at?:dt cancel_reason?:s addons:ao allowed_actions:a", "LCRU", "change-plan suspend reactivate"),
    ("SAS", 0, "platform.feature_flags", "/platform/feature-flags", "FeatureFlag", "code:s name:s description?:s default_enabled:b is_beta:b status:e(active|archived) rules:ao overrides:i allowed_actions:a", "LCRU", ""),
    # SAS-8 · Sesión de soporte con suplantación (RN-SAS-08): motivo, usuario, vencimiento propio y enlace de un solo uso (entry_url solo al crearla).
    ("SAS", 0, "platform.support_sessions", "/platform/support-sessions", "SupportSession", "tenant_id:id tenant?:j agent?:j target_user_id:id target_user?:j reason:s ticket_reference?:s status:e(pending|active|ended|expired) allow_sensitive:b expires_at:dt started_at?:dt ended_at?:dt entry_url?:s actions?:ao allowed_actions:a", "LCR", "end"),
    ("WEB", 0, "platform.web_products", "/platform/web-products", "WebProduct", "code:s name:s acronym:s slug:s tagline?:s module_codes:a is_suite:b sort_order:i status:e(draft|published|hidden)", "LRU", "publish hide"),
    ("WEB", 0, "platform.web_leads", "/platform/web-leads", "WebLead", "kind:e(demo|waitlist|partner|contact|newsletter|calculator|signup) full_name?:s email:s phone?:s company_name?:s ruc?:s product_code?:s module_code?:s source_page?:s utm?:j status:e(new|contacted|qualified|converted|discarded) assigned_platform_user_id?:id notified_at?:dt", "LRU", "assign convert discard"),
    ("WEB", 0, "platform.web_demo_bookings", "/platform/web-demo-bookings", "WebDemoBooking", "web_lead_id:id starts_at:dt ends_at:dt channel:e(video|phone) host_platform_user_id?:id meeting_url?:s status:e(scheduled|rescheduled|cancelled|done|no_show)", "LRU", "cancel complete"),
    ("WEB", 0, "platform.web_complaints", "/platform/web-complaints", "WebComplaint", "code:s kind:e(claim|complaint) consumer_name:s consumer_email:s detail:s request:s status:e(received|in_review|answered|closed) answer?:s answered_at?:dt", "LR", "answer close"),
    ("SEG", 0, "identity.users", "/users", "User", "person_id?:id login?:s display_name:s email?:s document_type?:e(dni|ce|passport|ptp|cpp|other) document_number?:s phone?:s document_number_masked?:s phone_masked?:s status:e(invited|active|locked|alumni|disabled) mfa_enabled:b role_ids:a role_assignments?:ao last_login_at?:dt", "LCRU", "invite suspend reactivate reset-mfa reset-password"),
    ("SEG", 0, "identity.roles", "/roles", "Role", "name:s system:b mfa_required?:b permissions:a field_permissions:a scope:e(organization|legal_entity|site|unit|team|self)", "LCRUD", "duplicate"),
    ("SEG", 0, "identity.api_tokens", "/api-tokens", "ApiToken", "name:s scopes:a expires_at?:dt last_used_at?:dt status:e(active|revoked)", "LCR", "revoke"),
    ("AUD", 0, "audit.logs", "/audit-logs", "AuditLog", "actor_id?:id action:s entity:s entity_id:s before?:j after?:j ip?:s occurred_at:dt sensitive:b", "LR", ""),
    ("AUD", 0, "audit.sensitive_access", "/sensitive-access-logs", "SensitiveAccessLog", "actor_id:id entity:s entity_id:s fields:a purpose?:s occurred_at:dt", "L", ""),
    ("AUD", 0, "audit.activity", "/activity-events", "ActivityEvent", "user_id?:id user_name?:s activity:s subject_type?:s subject_id?:s metadata?:j occurred_at:dt", "L", ""),
    ("AUD", 0, "audit.logs", "/audit-integrity-checks", "AuditIntegrityCheck", "status:e(ok|failed) checked_rows:i archived_rows:i unchained_rows:i first_seq?:i last_seq?:i issues:ao started_at:dt finished_at?:dt", "L", ""),
    ("AUD", 0, "audit.logs", "/audit-archives", "AuditArchive", "period_month:d from_seq?:i to_seq?:i row_count:i sha256:s size_bytes?:i archived_at:dt purge_after:d purged_at?:dt available:b", "LR", ""),
    ("WFL", 0, "workflow.approval_flows", "/approval-flows", "ApprovalFlow", "name:s subject_type:s steps:j conditions:j status:e(draft|published|retired) version:i has_draft?:b allowed_actions?:a", "LCRU", "publish retire"),
    ("WFL", 0, "workflow.approval_requests", "/approval-requests", "ApprovalRequest", "subject_type:s subject_id:id flow_id:id flow_name?:s current_step:i current_step_name?:s levels?:i status:e(pending|approved|rejected|cancelled|expired) escalated?:b due_at?:dt started_by_user_id?:id requester_name?:s employment_id?:id employee_name?:s tasks?:ao can_decide?:b bulk_safe?:b finished_at?:dt", "LR", "approve reject delegate"),
    ("WFL", 0, "workflow.workflows", "/workflows", "Workflow", "name:s trigger_event:s conditions:j actions:ao status:e(draft|active|paused|retired) allowed_actions:a last_run_at?:dt next_run_at?:dt owner_user_id?:id owner_name?:s failing:b failing_since?:dt managed_by_flow?:id stats:j version:i", "LCRU", "activate pause retire"),
    ("WFL", 0, "workflow.runs", "/workflow-runs", "WorkflowRun", "workflow_id:id workflow_name?:s trigger_event_id?:id event_type?:s status:e(running|succeeded|failed|skipped|queued|waiting|cancelled) attempts:i next_retry_at?:dt actions:ao payload:j conditions:ao path:ao resume_at?:dt allowed_actions:a started_at:dt finished_at?:dt error?:s", "LR", "retry cancel"),
    # WFL-6 · Constructor visual: el borrador se guarda con PATCH; publicar, pausar, reanudar y retirar son acciones.
    ("WFL", 0, "workflow.workflows", "/visual-flows", "VisualFlow", "name:s description?:s graph:j issues:ao status:e(draft|active|paused|retired) version:i has_draft:b published_at?:dt workflow_id?:id allowed_actions:a created_at:dt updated_at:dt", "LCRU", "activate pause retire"),
    ("WFL", 0, "workflow.tasks", "/tasks", "Task", "title:s description?:s assignee_user_id?:id assignee_role_id?:id assignee_name?:s created_by_name?:s due_on?:d priority:e(low|normal|high|urgent) status:e(pending|in_progress|done|cancelled) overdue?:b related_type?:s related_id?:id completed_at?:dt allowed_actions?:a custom_fields?:j", "LCRU", "complete cancel"),
    ("NOT", 0, "notifications.templates", "/notification-templates", "NotificationTemplate", "key:s channel:e(in_app|email|push|whatsapp|sms) locale:s subject?:s body:s variables?:a mandatory?:b customized?:b default_subject?:s default_body?:s approval_status?:e(not_required|draft|pending|approved|rejected) rejection_reason?:s approval_decided_at?:dt", "LCRU", ""),
    ("CFG", 0, "settings.custom_fields", "/custom-fields", "CustomField", "entity:s key:s label:s type:e(text|number|date|select|multiselect|checkbox|user|file|url|currency|percent) options?:a required:b sensitive:b permission?:s section?:s sort_order?:i values_count?:i", "LCRUD", ""),
    ("CFG", 0, "settings.exchange_rates", "/exchange-rates", "ExchangeRate", "from_currency:s to_currency:s rate:s valid_on:d source:s", "LC", ""),
    ("CFG", 0, "settings.saved_views", "/saved-views", "SavedView", "resource:s name:s filters:j columns:a sort:s shared:b mine:b", "LCRUD", ""),
    # ---------- Fase 1 ----------
    ("ORG", 1, "org.legal_entities", "/legal-entities", "LegalEntity", "name:s trade_name?:s tax_id:s country:s currency:s timezone:s locale?:s payroll_regime:s tax_regime?:s economic_activity_code?:s address?:s district_code?:s legal_representative_name?:s has_logo?:b status:e(active|archived) custom_fields?:j", "LCRU", "archive restore"),
    ("ORG", 1, "org.sites", "/sites", "Site", "legal_entity_id?:id code:s name:s kind:e(headquarters|branch|plant|store|site|remote) address?:s district_code?:s country?:s latitude?:n longitude?:n geofence_radius_m?:i timezone?:s effective_timezone?:s holiday_calendar_id?:id establishment_code?:s risk_site:b status:e(active|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("ORG", 1, "org.units", "/org-units", "OrgUnit", "code:s name:s type_id:id type_name?:s legal_entity_id?:id parent_id?:id parent_name?:s head_position_id?:id head_position_title?:s head_person_name?:s cost_center_id?:id cost_center_name?:s path?:s depth?:i children_count?:i valid_from?:d valid_to?:d effective_from?:d reason?:s scheduled_change?:j as_of?:d status:e(active|archived)", "LCRU", "archive restore move"),
    ("ORG", 1, "org.cost_centers", "/cost-centers", "CostCenter", "code:s name:s legal_entity_id?:id parent_id?:id path?:a accounting_code?:s opened_on?:d closed_on?:d status:e(active|closed|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("ORG", 1, "org.job_profiles", "/job-profiles", "JobProfile", "code:s name:s job_family_id?:id job_level_id?:id job_category_id?:id mission?:s functions?:s requirements?:s occupation_code?:s risk_job:b trust_classification:e(none|trust|management) category_pending?:b positions_count?:i current_version?:i status:e(active|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("ORG", 1, "org.job_profiles", "/job-families", "JobFamily", "code:s name:s description?:s profiles_count?:i status:e(active|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("ORG", 1, "org.job_profiles", "/job-levels", "JobLevel", "code:s name:s rank:i description?:s profiles_count?:i status:e(active|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("ORG", 1, "org.job_profiles", "/job-categories", "JobCategory", "code:s name:s rank?:i description?:s functions?:s responsibility_level?:s requirements?:s profiles_count?:i status:e(active|archived) allowed_actions?:a version?:i", "LCRU", "archive restore"),
    ("POS", 1, "org.positions", "/positions", "Position", "code:s title:s job_profile_id:id job_profile_name?:s legal_entity_id:id legal_entity_name?:s org_unit_id:id org_unit_name?:s site_id?:id site_name?:s cost_center_id?:id cost_center_name?:s reports_to_position_id?:id reports_to_title?:s effective_reports_to_position_id?:id salary_band_id?:id is_critical:b fte:n is_shared:b is_implicit?:b seats:i occupied_seats?:i occupants?:ao heads_units?:ao valid_from:d valid_to?:d status:e(proposed|approved|open|recruiting|filled|frozen|closed) position_request_id?:id budget!?:m allowed_actions?:a version?:i", "LCRU", "approve freeze unfreeze close"),
    ("POS", 1, "org.headcount_plans", "/headcount-plans", "HeadcountPlan", "name:s scenario:e(current|conservative|growth|custom) year:i status:e(draft|submitted|approved|closed) totals:j", "LCRU", "submit approve close"),
    # POS-5 · Escenarios: copian la dotación a una fecha y suman, congelan o quitan plazas sin tocar las reales (RN-POS-08); el costo solo con org.positions.budget.view.
    ("POS", 1, "org.headcount_plans", "/headcount-scenarios", "HeadcountScenario", "name:s kind:e(current|conservative|growth|custom) legal_entity_id?:id legal_entity_name?:s base_as_of:d assumptions?:j status:e(draft|in_review|approved|applied|archived) created_by_name?:s approved_by_name?:s approved_at?:dt applied_at?:dt applied_summary?:j totals?:j projected_cost!?:m lines?:ao allowed_actions?:a version?:i", "LCRU", ""),
    ("COL", 1, "people.people", "/people", "Person", "first_names:s paternal_surname:s maternal_surname?:s preferred_name?:s full_name?:s document_type:e(dni|ce|passport|ptp|cpp|other) document_country?:s document_number!?:s document_number_masked?:s birth_date!?:d sex!?:e(female|male|unspecified) nationality_code?:s marital_status!?:e(single|married|cohabiting|divorced|widowed) personal_email!?:s mobile_phone!?:s employments?:ao version?:i", "LCRU", "merge"),
    ("COL", 1, "people.employments", "/employments", "Employment", "person_id:id person_name?:s document_number_masked?:s legal_entity_id:id legal_entity_name?:s employee_code:s kind:e(employee|intern|apprentice) worker_category:e(executive|employee|worker) hire_date:d seniority_date?:d probation_ends_on?:d termination_date?:d status:e(pending|active|suspended|terminating|terminated|cancelled) rehire_of_employment_id?:id work_email?:s work_phone?:s work_phone_ext?:s job_profile_id?:id job_profile_name?:s position_id?:id position_title?:s org_unit_id?:id org_unit_name?:s site_id?:id site_name?:s cost_center_id?:id cost_center_name?:s manager_employment_id?:id manager_name?:s work_modality?:e(onsite|remote|hybrid) labor_regime_code?:s contract_type_id?:id contract_end_date?:d base_salary!?:m termination?:j other_employments?:ao custom_fields?:j allowed_actions?:a version?:i", "LCRU", "activate suspend terminate cancel-termination"),
    ("COL", 1, "people.dependents", "/employments/{employment_id}/dependents", "Dependent", "person_id?:id full_name:s relationship:e(spouse|partner|pregnant_partner|child|disabled_adult_child|parent|other) document_type?:e(dni|ce|passport|ptp|cpp|other) document_number!?:s document_number_masked?:s birth_date!?:d is_student?:b has_disability!?:b registered_on?:d deregistered_on?:d deregistration_reason?:s registry_status:e(pending|registered|deregistered) entitles_family_allowance:b has_proof:b missing_proof:b majority_alerted_on?:d version?:i", "LCRUD", ""),
    ("HIS", 1, "people.assignments", "/employments/{employment_id}/assignments", "Assignment", "job_profile_id:id job_profile_name?:s position_id?:id position_title?:s org_unit_id:id org_unit_name?:s site_id:id site_name?:s cost_center_id?:id cost_center_name?:s manager_employment_id?:id manager_name?:s work_modality:e(onsite|remote|hybrid) valid_from:d valid_to?:d reason?:s kind?:e(hire|promotion|transfer|manager_change|cost_center_change|data_correction) personnel_action_id?:id", "LR", ""),
    # HIS-3 · Compensación con vigencia: la historia se consulta aquí; los cambios entran por compensation-changes (con aprobación).
    ("HIS", 1, "people.compensations", "/employments/{employment_id}/compensations", "Compensation", "base_salary!:m pay_frequency:e(monthly|biweekly|weekly|daily|hourly) salary_basis:e(monthly|daily|hourly) is_integral_salary:b change_reason?:e(hire|merit|promotion|adjustment|legal|review_cycle|correction) components!?:ao valid_from:d valid_to?:d personnel_action_id?:id", "LR", ""),
    ("HIS", 1, "people.compensations", "/employments/{employment_id}/compensation-changes", "CompensationChange", "base_salary!:m pay_frequency:e(monthly|biweekly|weekly|daily|hourly) salary_basis?:e(monthly|daily|hourly) is_integral_salary?:b components!?:ao effective_on:d reason:e(merit|promotion|adjustment|legal|review_cycle|correction) notes?:s status:e(pending_approval|applied|rejected|cancelled) previous_salary!?:m band_position?:e(below|within|above|none) compensation_id?:id approval_request_id?:id decided_at?:dt decision_reason?:s requested_by_user_id?:id requested_by_name?:s", "LCR", "cancel"),
    ("CON", 1, "contracts.contract_types", "/contract-types", "ContractType", "code:s name:s country_code?:s category:e(indefinite|fixed_term|part_time|internship|other) modality_code?:s requires_end_date:b requires_objective_cause:b max_duration_parameter_code?:s trial_period_parameter_code?:s document_template_id?:id is_system:b max_duration?:j trial_period?:j", "LCRU", ""),
    ("CON", 1, "contracts.contracts", "/contracts", "Contract", "employment_id:id person_name?:s employee_code?:s legal_entity_id?:id legal_entity_name?:s contract_type_id:id contract_type_name?:s contract_type_category?:s number?:s starts_on:d ends_on?:d trial_period_ends_on?:d weekly_hours?:n work_modality:e(onsite|remote|hybrid) objective_cause?:s status:e(draft|pending_approval|pending_signature|signed|active|expired|terminated|superseded|cancelled) renewal_of_contract_id?:id renewed_by_contract_id?:id has_document?:b document_version?:i signature_origin?:e(physical_scan|electronic) signed_at?:dt approved_at?:dt accumulated_days?:i termination_reason?:s over_limit_reason?:s accumulation?:j warnings?:ao allowed_actions?:a version?:i", "LCRU", ""),
    ("CON", 1, "contracts.addenda", "/contract-addenda", "ContractAddendum", "contract_id:id employment_id?:id number?:s kind:e(salary|position|schedule|extension|modality|other) effective_on:d description:s changes?:j salary_change!?:m status:e(draft|pending_signature|active|cancelled) has_document?:b document_version?:i signed_at?:dt signature_origin?:e(physical_scan|electronic) applied_refs?:j cancellation_reason?:s allowed_actions?:a version?:i", "LCR", ""),
    # CON-6 · Teletrabajo (Ley 31572): solicitud con plazo legal de respuesta; el acuerdo se emite solo con su contenido mínimo (RN-CON-10).
    ("CON", 1, "contracts.remote_work", "/remote-work-agreements", "RemoteWorkAgreement", "employment_id:id person_name?:s employee_code?:s legal_entity_id?:id modality:e(total|partial) starts_on:d ends_on?:d work_place?:s schedule_description?:s disconnection_hours?:i supervision_mechanism?:s equipment_provided_by?:e(employer|worker|mixed) expense_compensation?:m requested_by:e(employee|employer) request_reason?:s requested_on?:d response_due_on?:d responded_on?:d due_soon?:b overdue?:b decision_reason?:s status:e(requested|approved|rejected|pending_signature|active|ended) missing_content?:a has_document?:b signed_at?:dt signature_origin?:e(physical_scan|electronic) end_reason?:s allowed_actions?:a version?:i", "LCRU", ""),
    # DOC-1 · DOC-2 · Legajo: el alta y la versión nueva van por multipart (EXTRA); el recurso lista, muestra y edita metadatos.
    ("DOC", 1, "documents.documents", "/documents", "Document", "category_id:id title:s description?:s employment_id?:id legal_entity_id?:id person_id?:id person_name?:s category_name?:s folder_id?:id folder_code?:s folder_name?:s classification:e(public|internal|confidential|restricted) status:e(draft|pending_approval|active|archived|expired|rejected|requested) issued_on?:d expires_on?:d expiring?:b days_to_expiry?:i visible_to_employee:b responsible_user_id?:id responsible_name?:s source:e(upload|generated|import|signature|system) managed_by?:s current_version?:i file_name?:s mime_type?:s size_bytes?:i archived_at?:dt archive_reason?:s version:i allowed_actions:a", "LRU", "archive restore"),
    # DOC-4 · Plantillas por tipo documental y empresa con versiones (active = publicada, inactive = retirada); generar es EXTRA (lote).
    ("DOC", 1, "documents.templates", "/document-templates", "DocumentTemplate", "code:s name:s description?:s kind:e(contract|amendment|certificate|letter|offer|policy|payslip|settlement|other) category_id?:id category_name?:s legal_entity_id?:id legal_entity_name?:s locale:s is_system:b status:e(draft|active|inactive) body:s variables:a page_settings?:j has_draft:b current_version?:i published_at?:dt versions?:ao allowed_actions:a version:i", "LCRU", "publish retire"),
    # DOC-5 · Lotes de generación de PDF en segundo plano: avance y resultado por persona (los omitidos con su motivo).
    ("DOC", 1, "documents.templates", "/document-generation-batches", "DocumentGenerationBatch", "template_id:id template_name?:s template_version:i status:e(queued|running|completed|completed_with_errors|failed) total:i generated:i failed:i items:ao requested_by_user_id?:id requested_by_name?:s started_at?:dt finished_at?:dt", "LR", ""),
    # DOC-3 · Solicitudes de documentos: se crean (una por persona) y se entregan por EXTRA; la revisión se decide en /approval-requests/{id}.
    ("DOC", 1, "documents.documents", "/document-requests", "DocumentRequest", "employment_id:id person_id?:id person_name?:s employee_code?:s category_id:id category_name?:s title:s instructions?:s due_on?:d overdue:b status:e(requested|submitted|approved|rejected|cancelled) document_id?:id rejection_reason?:s request_group_id?:id requested_by_user_id?:id requested_by_name?:s submitted_at?:dt reviewed_at?:dt cancellation_reason?:s approval_request_id?:id approval?:j allowed_actions:a version:i", "LR", ""),
    ("DOC", 1, "documents.signature_requests", "/signature-requests", "SignatureRequest", "document_id:id document_title?:s category_name?:s employment_id?:id subject_type?:s title:s signing_order:e(sequential|parallel) signature_kind:e(electronic|certificate) signature_level:e(simple|advanced|digital_certificate) status:e(draft|sent|viewed|in_progress|completed|declined|expired|cancelled) sent_at?:dt expires_at?:dt completed_at?:dt final_hash?:s cancellation_reason?:s has_certificate?:b signers:ao allowed_actions?:a my_turn?:b my_status?:s version?:i", "LCR", "send cancel remind"),
    ("ESS", 1, "people.change_requests", "/profile-change-requests", "ProfileChangeRequest", "person_id?:id person_name?:s changes:j applied_fields?:a pending_fields?:a status:e(pending|approved|rejected|cancelled) requested_by_user_id?:id requested_by_name?:s reviewed_at?:dt rejection_reason?:s allowed_actions?:a", "LCR", "approve reject cancel"),
    ("IMP", 1, "imports.imports", "/imports", "ImportJob", "entity:e(legal_entities|employees|contracts|vacation_balances|salaries|attendance|concepts|history|org_structure|dependents|positions|job_profiles) file_name?:s file_id?:id headers?:a mapping?:j suggested_mapping?:j suggested_profiles?:ao status:e(uploaded|mapped|validated|with_errors|importing|completed|failed|cancelled|reverted) total_rows?:i valid_rows?:i error_rows?:i imported_rows?:i created_rows?:i updated_rows?:i rejected_rows?:i batch_size?:i summary?:j error_message?:s created_by_user_id?:id created_by_name?:s started_at?:dt finished_at?:dt allowed_actions?:a", "LCR", "map validate execute cancel revert"),
    # AYU-1 · Ayuda del producto (uno por slug e idioma): publicar copia el borrador a una versión nueva e inmutable (RN-AYU-02); versions solo en el detalle.
    ("AYU", 1, "platform.help_articles", "/platform/help-articles", "HelpArticle", "slug:s locale:s title:s summary?:s body:s kind?:e(guide|screen|field|tour|release_note|faq) module_code:s roles:a screen_keys:a product_version:s version_number?:i has_unpublished_changes?:b published_at?:dt versions?:ao status:e(draft|published|archived)", "LCRU", "publish archive"),
    # AYU-4 · Artículos y políticas de la organización: audiencia (roles, empresas y sedes), responsable, versión y
    # revisión; publicar copia el borrador a una versión nueva; al vencer la revisión pasa a «por revisar» sin ocultarse.
    ("AYU", 1, "help.org_articles", "/help/org-articles", "HelpOrgArticle", "title:s category_id?:id body:s locale?:s audience:j owner_user_id:id owner_name?:s review_on?:d version:i status:e(draft|published|in_review|archived) has_unpublished_changes?:b review_due?:b published_at?:dt helpful_count?:i not_helpful_count?:i versions?:ao", "LCRU", "publish archive"),
    # ---------- Fase 2 ----------
    ("ASI", 2, "time.punches", "/time-punches", "TimePunch", "employment_id:id punched_at:dt kind:e(in|out|break_start|break_end) method:e(web|app|kiosk|qr|pin|crew|biometric|whatsapp) site_id?:id geo?:j device_id?:id offline:b corrects_id?:id reason?:s", "LCR", ""),
    ("ASI", 2, "time.attendance_days", "/attendance-days", "AttendanceDay", "employment_id:id date:d scheduled:j worked_minutes:i late_minutes:i overtime_minutes:i night_minutes:i status:e(ok|late|absent|justified|holiday|rest|incomplete)", "LR", "justify recalculate"),
    ("ASI", 2, "time.devices", "/punch-devices", "PunchDevice", "name:s kind:e(kiosk|biometric|tablet) site_id:id connector?:s last_seen_at?:dt status:e(active|disabled)", "LCRU", "disable enable"),
    ("ASI", 2, "time.geofences", "/geofences", "Geofence", "site_id:id name:s center:j radius_m:i status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.schedules", "/work-schedules", "WorkSchedule", "name:s weekly_hours:n days:j tolerance_minutes:i break_minutes:i status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.shifts", "/shifts", "Shift", "name:s starts_at:s ends_at:s crosses_midnight:b color?:s status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.shift_assignments", "/shift-assignments", "ShiftAssignment", "employment_id:id shift_id:id date:d status:e(draft|published|swapped|cancelled)", "LCRUD", "publish"),
    ("TUR", 2, "time.holiday_calendars", "/holiday-calendars", "HolidayCalendar", "code?:s name:s country:s scope:j holidays:ao is_default?:b", "LCRU", ""),
    # VAC-2 · Saldo por relación laboral ({id} = relación): la lista trae el resumen por colaborador; el detalle, columnas, periodos y política.
    ("VAC", 2, "time.vacation_balances", "/vacation-balances", "VacationBalance", "employment_id:id employee_code?:s person_name?:s legal_entity_name?:s status?:s hire_date?:d available_days?:s accruing_days?:s overdue_periods?:i as_of?:d totals?:j accruing?:j advance_available?:s overdue_days?:s pending_validation?:b periods?:ao policy?:j", "LR", "adjust"),
    # VAC-1 · Políticas por empresa, régimen y categoría; las escrituras van por EXTRA con time.vacation_policies.update.
    ("VAC", 2, "time.vacation_policies", "/vacation-policies", "VacationPolicy", "code:s name:s legal_entity_id?:id legal_entity_name?:s labor_regime_code?:s job_category_id?:id job_category_name?:s status:e(active|archived) allowed_actions:a rules?:j legal?:j", "LR", ""),
    # VAC-3 · Solicitudes de vacaciones: aprobar y rechazar se deciden en /approval-requests/{id} (motor de aprobaciones).
    ("VAC", 2, "time.vacation_requests", "/vacation-requests", "VacationRequest", "employment_id:id person_id?:id person_name?:s employee_code?:s legal_entity_id?:id starts_on:d ends_on:d days:s calendar_days?:s advance_days?:s advance:b kind?:e(leave|advance|sale) status:e(draft|pending|hr_review|approved|in_progress|taken|rejected|cancelled) notes?:s exception_reason?:s requested_by_user_id?:id requested_by_name?:s submitted_at?:dt decided_at?:dt decision_reason?:s cancelled_at?:dt cancellation_reason?:s started_at?:dt completed_at?:dt approval_request_id?:id approval?:j allowed_actions?:a version?:i", "LCRU", "submit cancel"),
    ("AUS", 2, "time.absence_types", "/absence-types", "AbsenceType", "code:s name:s category:e(medical_leave|maternity|paternity|personal|bereavement|union|compensatory|remote_work|unpaid|suspension|other) country_code?:s is_system:b is_confidential:b legal_duration_parameter_code?:s legal_duration?:j rules:j effective_from?:d status:e(active|archived)", "LCRU", "archive"),
    # AUS-2 · Ausencias (ME-16): aprobar y rechazar los decide el flujo (centro de aprobaciones); RRHH registra con «register».
    ("AUS", 2, "time.absences", "/absences", "Absence", "employment_id:id person_id?:id person_name?:s employee_code?:s legal_entity_id?:id absence_type_id?:id absence_type_name?:s absence_type_category?:s is_confidential?:b unit?:e(days|hours) starts_on:d ends_on?:d start_time?:s end_time?:s days?:s hours?:s calendar_days?:s return_on?:d legal_extensions?:a required_documents?:ao employer_paid_days!?:s subsidized_days!?:s subsidy_status!?:s is_paid?:b affects_attendance?:b reason?:s medical_reason!?:s attachments?:ao medical_attachments!?:ao status:e(draft|pending|pending_document|approved|in_progress|completed|rejected|cancelled) registered_directly?:b document_required?:b document_due_on?:d has_document?:b exception_reason?:s requested_by_user_id?:id requested_by_name?:s submitted_at?:dt decided_at?:dt decision_reason?:s cancelled_at?:dt cancellation_reason?:s approval_request_id?:id approval?:j allowed_actions?:a version?:i", "LCRU", "submit cancel"),
    ("BHO", 2, "time.overtime_requests", "/overtime-requests", "OvertimeRequest", "employment_id:id date:d minutes:i compensation:e(pay|time_bank) reason:s status:e(pending|approved|rejected|cancelled)", "LCR", "approve reject cancel"),
    ("BHO", 2, "time.time_bank", "/time-bank-movements", "TimeBankMovement", "employment_id:id date:d minutes:i kind:e(credit|debit|expiry|adjustment) source_type?:s source_id?:id", "LC", ""),
    ("TSH", 2, "time.projects", "/projects", "Project", "code:s name:s cost_center_id?:id billable:b status:e(active|closed)", "LCRU", "close"),
    ("TSH", 2, "time.timesheets", "/timesheets", "Timesheet", "employment_id:id week_start:d entries:j total_minutes:i status:e(draft|submitted|approved|rejected)", "LCRU", "submit approve reject"),
    # ---------- Fase 3 ----------
    ("PAR", 3, "payroll.legal_parameters", "/legal-parameters", "LegalParameter", "key:s country:s regime?:s description:s unit:e(amount|percent|days|count) values:j source_reference?:s", "LCR", "add-value"),
    ("PAR", 3, "payroll.wage_tables", "/wage-tables", "WageTable", "country:s regime:s name:s legal_source:s valid_from:d valid_to?:d values:j verified:b", "LCR", "verify"),
    ("CPT", 3, "payroll.concepts", "/payroll-concepts", "PayrollConcept", "code:s name:s kind:e(earning|deduction|employer_contribution|tax|provision|informative) formula:s conditions?:j affects:j accounting?:j official_code?:s valid_from:d valid_to?:d status:e(draft|published|retired) version:i", "LCRU", "publish retire test"),
    ("NOM", 3, "payroll.regimes", "/payroll-regimes", "PayrollRegime", "code:s country:s name:s rules:j status:e(active|retired)", "LR", ""),
    ("NOM", 3, "payroll.entity_annual_figures", "/legal-entity-annual-figures", "LegalEntityAnnualFigure", "legal_entity_id:id fiscal_year:i net_sales:m uit_value:m sales_in_uit:n average_headcount?:n source_document_id?:id", "LCRU", ""),
    ("NOM", 3, "payroll.construction_works", "/construction-works", "ConstructionWork", "legal_entity_id:id site_id?:id code:s name:s budget?:m budget_in_uit?:n special_regime_applies?:b starts_on:d ends_on?:d status:e(planned|in_progress|suspended|finished)", "LCRU", "finish"),
    ("NOM", 3, "payroll.construction_assignments", "/construction-assignments", "ConstructionAssignment", "employment_id:id construction_work_id:id category:e(operario|oficial|peon) specialty_code?:s valid_from:d valid_to?:d", "LCR", "cancel"),
    ("PER", 3, "payroll.periods", "/payroll-periods", "PayrollPeriod", "legal_entity_id:id period:s kind:e(regular|gratification|cts|profit_sharing|settlement|off_cycle) pay_date:d status:e(open|calculated|in_review|approved|closed|paid) totals!?:j engine_version?:s", "LCR", "calculate simulate submit approve reject close reopen mark-paid"),
    ("PER", 3, "payroll.incidents", "/payroll-incidents", "PayrollIncident", "period_id:id employment_id:id concept_code:s quantity?:n amount?:m source:e(manual|attendance|absence|loan|import|retroactive) note?:s", "LCRUD", ""),
    ("PER", 3, "payroll.results", "/payroll-results", "PayrollResult", "period_id:id employment_id:id earnings!:m deductions!:m employer_contributions!:m net!:m lines!:j trace!:j", "LR", ""),
    ("BOL", 3, "payroll.payslips", "/payslips", "Payslip", "period_id:id employment_id:id net!:m file_id?:id published_at?:dt acknowledged_at?:dt status:e(generated|published|acknowledged|voided)", "LR", "publish acknowledge void"),
    ("BSO", 3, "payroll.benefit_runs", "/benefit-runs", "BenefitRun", "legal_entity_id:id kind:e(cts|gratification|profit_sharing) period:s status:e(open|calculated|approved|closed) totals!?:j", "LCR", "calculate approve close"),
    ("PRE", 3, "payroll.loans", "/loans", "Loan", "employment_id:id principal!:m installments:i schedule!:j balance!:m status:e(requested|approved|rejected|active|settled|cancelled)", "LCRU", "approve reject disburse cancel"),
    ("PRE", 3, "payroll.salary_advances", "/salary-advances", "SalaryAdvance", "employment_id:id amount!:m accrued_basis!:m channel:e(bank|wallet) status:e(requested|approved|rejected|paid|deducted|cancelled)", "LCR", "approve reject pay cancel"),
    ("DEC", 3, "payroll.declaration_files", "/declaration-files", "DeclarationFile", "legal_entity_id:id kind:e(plame|t_registro|afpnet|bank|accounting) period:s file_id?:id validation?:j status:e(generated|validated|with_errors|submitted|rectified)", "LCR", "validate mark-submitted rectify"),
    ("DEC", 3, "payroll.registry_profiles", "/employments/{employment_id}/registry-profiles", "RegistryProfile", "worker_type_code:s labor_regime_code:s occupational_category_code?:s occupation_code:s education_status_code:s contract_type_code:s has_disability!:b is_unionized!:b alternative_working_regime:b subject_to_max_working_time:b subject_to_night_shift:b special_situation_code:s payment_type_code:s pay_periodicity_code:s initial_basic_remuneration!:m worker_situation_code:s fifth_category_exempt:b tax_treaty_code:s is_tax_resident:b sctr_health:e(none|essalud|eps) sctr_pension:e(none|onp|insurer) agrarian_benefit_payment?:e(prorated_daily|semiannual) completeness:e(draft|ready|blocked) missing_fields:a valid_from:d valid_to?:d", "LCR", "validate"),
    ("DEC", 3, "payroll.external_income_declarations", "/external-income-declarations", "ExternalIncomeDeclaration", "employment_id:id fiscal_year:i kind:e(previous_employer|concurrent_employer) employer_tax_id?:s employer_name?:s period_from?:d period_to?:d gross_income!:m exempt_income!?:m tax_withheld!:m monthly_detail!?:j this_employer_withholds?:b document_id?:id received_on?:d status:e(received|validated|superseded|rejected)", "LCR", "validate reject supersede"),
    ("DEC", 3, "payroll.fifth_category_ledger", "/fifth-category-ledger", "FifthCategoryLedgerEntry", "employment_id:id fiscal_year:i period_month:i sequence:i entry_kind:e(monthly|final_adjustment|refund|prior_employer|correction) gross_annual_income!:m deduction_amount:m net_annual_income!:m annual_tax!:m prior_withholdings_deducted:m divisor?:i ordinary_withholding!:m additional_withholding!:m withholding_amount!:m uit_value:m parameters:j trace:j engine_version:s", "LR", ""),
    ("DEC", 3, "payroll.fifth_category_refunds", "/fifth-category-refunds", "FifthCategoryRefund", "employment_id:id fiscal_year:i origin:e(termination|december_adjustment|worker_declaration) amount!:m refunded_on?:d compensated_amount:m compensation_period?:s authority_refund_reference?:s status:e(pending|refunded|compensated|authority_requested|closed)", "LCR", "refund compensate request-authority-refund close"),
    ("DEC", 3, "payroll.file_layouts", "/statutory-file-layouts", "StatutoryFileLayout", "country:s filing_kind:s structure_code:s name:s file_name_pattern:s file_extension?:s layout_version:s field_separator?:s encoding:s line_ending:e(CRLF|LF) fields:j verified:b valid_from:d valid_to?:d", "LR", ""),
    ("DEC", 3, "payroll.parametric_tables", "/parametric-tables", "ParametricTable", "table_number:i name:s source_annex?:s items:j", "LR", ""),
    ("PRV", 3, "payroll.provisions", "/provisions", "Provision", "legal_entity_id:id period:s kind:e(cts|gratification|vacation) employment_id:id amount!:m", "L", ""),
    ("PRV", 3, "payroll.accounting_entries", "/accounting-entries", "AccountingEntry", "legal_entity_id:id period:s lines!:j status:e(draft|posted|exported)", "LCR", "post export"),
    ("LIQ", 3, "payroll.settlements", "/settlements", "Settlement", "employment_id:id termination_date:d reason:s lines!:j total!:m documents:a status:e(simulated|calculated|approved|paid|voided)", "LCRU", "calculate approve mark-paid void"),
    ("LOC", 3, "payroll.contractors", "/contractors", "Contractor", "person_id:id legal_entity_id:id service:s starts_on:d ends_on?:d fee!:m status:e(active|ended)", "LCRU", "end"),
    ("LOC", 3, "payroll.contractor_invoices", "/contractor-invoices", "ContractorInvoice", "contractor_id:id number:s issued_on:d gross!:m withholding!:m net!:m status:e(received|approved|rejected|paid)", "LCRU", "approve reject mark-paid"),
    # ---------- Fase 4 ----------
    ("ATS", 4, "recruiting.requisitions", "/job-requisitions", "JobRequisition", "title:s position_id?:id job_profile_id:id org_unit_id:id description:s salary_range!?:j internal:b pipeline:j status:e(draft|pending_approval|approved|published|on_hold|closed)", "LCRU", "submit approve publish hold close"),
    ("ATS", 4, "recruiting.candidates", "/candidates", "Candidate", "first_name:s last_name:s email?:s phone?:s source:s tags:a resume_file_id?:id consent_at?:dt", "LCRU", "anonymize"),
    ("ATS", 4, "recruiting.applications", "/applications", "Application", "candidate_id:id requisition_id:id stage:s expected_salary!?:m referred_by_employment_id?:id status:e(active|rejected|withdrawn|hired)", "LCRU", "move-stage reject withdraw hire"),
    ("ATS", 4, "recruiting.talent_pools", "/talent-pools", "TalentPool", "name:s description?:s candidate_ids:a", "LCRUD", ""),
    ("ENT", 4, "recruiting.interviews", "/interviews", "Interview", "application_id:id starts_at:dt ends_at:dt kind:s location?:s interviewer_ids:a guide?:s status:e(scheduled|completed|cancelled|no_show)", "LCRU", "complete cancel"),
    ("ENT", 4, "recruiting.scorecards", "/scorecards", "Scorecard", "interview_id:id interviewer_id:id ratings:j recommendation:e(strong_yes|yes|no|strong_no) notes?:s", "LCR", ""),
    ("ENT", 4, "recruiting.offers", "/offers", "Offer", "application_id:id template_id:id salary!:m start_date:d status:e(draft|in_review|approved|sent|accepted|declined|expired|withdrawn)", "LCRU", "submit approve send accept decline withdraw"),
    ("ONB", 4, "onboarding.templates", "/process-templates", "ProcessTemplate", "kind:e(pre_onboarding|onboarding|offboarding) name:s applies_to:j tasks:j status:e(draft|published|retired)", "LCRU", "publish retire"),
    ("ONB", 4, "onboarding.processes", "/onboarding-processes", "OnboardingProcess", "employment_id:id template_id:id progress:i tasks:j status:e(pending|in_progress|completed|cancelled)", "LCR", "start complete cancel"),
    ("OFF", 4, "onboarding.offboardings", "/offboarding-processes", "OffboardingProcess", "employment_id:id reason:s last_day:d tasks:j exit_interview?:j status:e(pending|in_progress|completed|cancelled)", "LCRU", "start complete cancel"),
    ("DES", 4, "performance.cycles", "/review-cycles", "ReviewCycle", "name:s kind:e(90|180|270|360) starts_on:d ends_on:d participants:j form:j status:e(draft|launched|calibration|closed)", "LCRU", "launch start-calibration close"),
    ("DES", 4, "performance.reviews", "/reviews", "Review", "cycle_id:id employment_id:id reviewer_id:id relation:e(self|manager|peer|report|other) answers:j score?:n status:e(pending|in_progress|submitted|shared)", "LRU", "submit share reopen"),
    ("OKR", 4, "performance.objectives", "/objectives", "Objective", "level:e(company|unit|individual) owner_employment_id?:id org_unit_id?:id parent_id?:id title:s key_results:j progress:n period:s status:e(draft|active|closed|cancelled)", "LCRU", "activate check-in close"),
    ("CMP", 4, "performance.competencies", "/competencies", "Competency", "name:s description?:s levels:j job_profile_ids:a status:e(active|archived)", "LCRU", "archive"),
    ("CMP", 4, "performance.skills", "/skills", "Skill", "name:s category?:s status:e(active|archived)", "LCRU", "archive"),
    ("FBK", 4, "performance.one_on_ones", "/one-on-ones", "OneOnOne", "manager_employment_id:id employment_id:id scheduled_at:dt agenda:j notes?:s private_notes?:s commitments:j status:e(scheduled|completed|cancelled)", "LCRU", "complete cancel"),
    ("FBK", 4, "performance.feedback", "/feedback", "Feedback", "from_employment_id:id to_employment_id:id kind:e(requested|given|praise) body:s visibility:e(private|manager|public)", "LCR", ""),
    # ---------- Fase 5 ----------
    ("LMS", 5, "learning.courses", "/courses", "Course", "title:s category?:s mandatory:b duration_minutes:i content:j validity_months?:i status:e(draft|published|archived)", "LCRU", "publish archive"),
    ("LMS", 5, "learning.enrollments", "/enrollments", "Enrollment", "course_id:id employment_id:id due_on?:d progress:i score?:n certificate_file_id?:id status:e(not_started|in_progress|completed|expired)", "LCRU", "complete reset"),
    ("LMS", 5, "learning.training_plans", "/training-plans", "TrainingPlan", "year:i legal_entity_id?:id items:j status:e(draft|approved|closed)", "LCRU", "approve close"),
    ("CAR", 5, "careers.development_plans", "/development-plans", "DevelopmentPlan", "employment_id:id goals:j actions:j status:e(draft|active|completed|cancelled)", "LCRU", "activate complete"),
    ("CAR", 5, "careers.paths", "/career-paths", "CareerPath", "name:s steps:j status:e(active|archived)", "LCRU", "archive"),
    ("CAR", 5, "careers.succession_plans", "/succession-plans", "SuccessionPlan", "position_id:id critical:b successors!:j status:e(active|archived)", "LCRU", "archive"),
    ("CAR", 5, "careers.talent_reviews", "/talent-reviews", "TalentReview", "name:s cycle_id?:id placements!:j status:e(draft|in_session|closed)", "LCRU", "close"),
    ("COM", 5, "compensation.bands", "/salary-bands", "SalaryBand", "job_profile_id?:id category?:s currency:s min!:n mid!:n max!:n valid_from:d valid_to?:d", "LCRU", ""),
    ("COM", 5, "compensation.cycles", "/compensation-cycles", "CompensationCycle", "name:s budget!:m effective_on:d scope:j status:e(draft|open|in_review|approved|applied|closed)", "LCRU", "open submit approve apply close"),
    ("COM", 5, "compensation.proposals", "/compensation-proposals", "CompensationProposal", "cycle_id:id employment_id:id current!:m proposed!:m reason:s status:e(draft|submitted|approved|rejected|applied)", "LCRU", "submit approve reject"),
    ("COM", 5, "compensation.pay_equity", "/pay-equity-categories", "PayEquityCategory", "name:s job_profile_ids:a functions:s responsibility:s requirements:s valid_from:d", "LCRU", ""),
    ("BNF", 5, "benefits.benefits", "/benefits", "Benefit", "name:s category:s eligibility:j cost!?:m payroll_concept_code?:s status:e(active|archived)", "LCRU", "archive"),
    ("BNF", 5, "benefits.enrollments", "/benefit-enrollments", "BenefitEnrollment", "benefit_id:id employment_id:id starts_on:d ends_on?:d status:e(requested|active|rejected|ended)", "LCR", "approve reject end"),
    # ---------- Fase 6 ----------
    ("ENC", 6, "engagement.surveys", "/surveys", "Survey", "title:s kind:e(climate|engagement|onboarding|exit|pulse|enps|custom) anonymous:b min_segment_size?:i audience:j questions:j status:e(draft|open|closed)", "LCRU", "launch close"),
    ("ENC", 6, "engagement.action_plans", "/action-plans", "ActionPlan", "survey_id?:id org_unit_id?:id title:s owner_employment_id:id due_on:d progress:i status:e(open|in_progress|done|cancelled)", "LCRU", "complete"),
    ("REC", 6, "engagement.recognitions", "/recognitions", "Recognition", "from_employment_id:id to_employment_ids:a value?:s badge?:s message:s public:b", "LCR", "hide"),
    ("CIN", 6, "engagement.announcements", "/announcements", "Announcement", "title:s body:s kind:e(news|notice|event) audience:j requires_ack:b publish_at?:dt status:e(draft|scheduled|published|archived)", "LCRU", "publish archive acknowledge"),
    # ESS-5 · Solicitudes a RRHH desde el portal (ME-31); la mesa de ayuda (HSD, fase 6) suma colas, SLA por categoría y base de conocimiento.
    ("ESS", 1, "helpdesk.tickets", "/tickets", "Ticket", "number:s category_id:id category_name?:s employment_id?:id person_name?:s requester_user_id:id requester_name?:s subject:s description?:s status:e(new|in_progress|waiting_info|resolved|closed|cancelled) priority:e(low|normal|high|urgent) assignee_user_id?:id assignee_name?:s assignee_role_id?:id first_response_due_at?:dt resolution_due_at?:dt first_responded_at?:dt resolved_at?:dt closed_at?:dt overdue?:b channel:e(portal|email|whatsapp|ai_assistant) messages?:ao allowed_actions:a version:i", "LCRU", "assign request-info resolve close reopen"),
    # ---------- Fase 7 ----------
    ("SST", 7, "sst.incidents", "/sst/incidents", "SstIncident", "occurred_at:dt kind:e(accident|incident|dangerous_incident|occupational_disease) severity:s site_id:id employment_id?:id description:s medical_details!?:s status:e(reported|investigating|closed)", "LCRU", "investigate close"),
    ("SST", 7, "sst.risks", "/sst/risks", "SstRisk", "site_id:id job_profile_id?:id hazard:s risk:s probability:i severity:i controls:j status:e(active|archived)", "LCRU", "archive"),
    ("SST", 7, "sst.inspections", "/sst/inspections", "SstInspection", "site_id:id date:d inspector_id:id findings:j status:e(scheduled|done|closed)", "LCRU", "close"),
    ("SST", 7, "sst.medical_exams", "/sst/medical-exams", "MedicalExam", "employment_id:id kind:e(pre_employment|periodic|exit) date:d expires_on?:d fitness!:e(fit|fit_with_restrictions|unfit) restrictions!?:s file_id!?:id", "LCRU", ""),
    ("SST", 7, "sst.committees", "/sst/committees", "SstCommittee", "legal_entity_id:id kind:e(committee|supervisor) members:j term_start:d term_end:d minutes:j", "LCRU", ""),
    ("SST", 7, "sst.elections", "/sst/elections", "SstElection", "legal_entity_id:id site_id?:id body_kind:e(committee|subcommittee|supervisor) headcount_at_start:i total_members:i worker_seats:i convened_by?:e(majority_union|employer) voting_mode?:e(in_person|electronic) schedule:j board:j candidates:j status:e(planned|notified|called|board_formed|nominations|candidates_published|voting|counted|proclaimed|installed|annulled)", "LCR", "communicate call form-board open-nominations publish-candidates open-voting count proclaim install annul"),
    ("SST", 7, "sst.committee_meetings", "/sst/committee-meetings", "SstCommitteeMeeting", "sst_committee_id:id held_on:d kind:e(ordinary|extraordinary) agenda?:s attendees:j quorum_required?:i quorum_present?:i agreements?:j minutes_document_id?:id status:e(scheduled|called|held|no_quorum|minutes_drafted|minutes_signed|cancelled)", "LCRU", "call record-session record-no-quorum draft-minutes sign-minutes cancel"),
    ("SST", 7, "sst.corrective_actions", "/sst/corrective-actions", "CorrectiveAction", "source_type:s source_id:id description:s owner_id:id due_on:d status:e(open|in_progress|done|verified)", "LCRU", "complete verify"),
    ("DEN", 7, "grievances.cases", "/grievances", "Grievance", "tracking_code:s kind:e(harassment|sexual_harassment|fraud|discrimination|safety|other) anonymous:b description!:s investigator_ids:a protection_measures!?:j deadlines:j status:e(received|admitted|investigating|resolved|closed|dismissed)", "LR", "admit assign add-protection-measure resolve close dismiss offer-care refer-to-committee start-investigation issue-report decide"),
    ("DEN", 7, "grievances.harassment_committees", "/harassment-committees", "HarassmentCommittee", "legal_entity_id:id site_id?:id kind:e(committee|delegate) members:j term_start:d term_end?:d status:e(active|inactive)", "LCRU", ""),
    ("DIS", 7, "discipline.cases", "/disciplinary-cases", "DisciplinaryCase", "employment_id:id facts!:s statement!?:s measure!?:e(verbal_warning|written_warning|suspension|dismissal|none) status:e(open|awaiting_statement|in_review|resolved|closed)", "LCRU", "notify record-statement resolve close"),
    ("ACT", 7, "assets.assets", "/assets", "Asset", "kind:s name:s serial?:s employment_id?:id value?:m status:e(available|assigned|maintenance|lost|retired)", "LCRU", "assign return send-to-maintenance report-lost retire"),
    ("ACT", 7, "assets.ppe_deliveries", "/ppe-deliveries", "PpeDelivery", "employment_id:id items:j delivered_on:d expires_on?:d signature_request_id?:id status:e(pending_signature|signed|expired)", "LCR", "sign"),
    ("GAS", 7, "expenses.policies", "/expense-policies", "ExpensePolicy", "name:s applies_to:j limits:j categories:a status:e(active|archived)", "LCRU", "archive"),
    ("GAS", 7, "expenses.reports", "/expense-reports", "ExpenseReport", "employment_id:id title:s items:j total:m cost_center_id?:id advance_id?:id status:e(draft|submitted|approved|rejected|paid)", "LCRU", "submit approve reject mark-paid"),
    ("GAS", 7, "expenses.advances", "/travel-advances", "TravelAdvance", "employment_id:id amount:m purpose:s due_on:d status:e(requested|approved|rejected|paid|settled)", "LCR", "approve reject mark-paid settle"),
    ("CUM", 7, "compliance.obligations", "/compliance/obligations", "ComplianceObligation", "legal_entity_id:id key:s applies:b reason:s owner_id?:id due_on?:d evidence_file_ids:a status:e(ok|at_risk|overdue|not_applicable)", "LRU", "attach-evidence reevaluate"),
    ("CUM", 7, "compliance.inspection_files", "/compliance/inspection-files", "InspectionFile", "legal_entity_id:id employment_ids:a period_from:s period_to:s sections:a file_id?:id status:e(queued|building|ready|failed)", "LCR", ""),
    ("CUM", 7, "compliance.notices", "/compliance/sunafil-notices", "SunafilNotice", "legal_entity_id:id reference:s received_on:d due_on:d owner_id?:id summary:s status:e(new|assigned|answered|closed)", "LCRU", "assign answer close"),
    ("CUM", 7, "compliance.ledger", "/compliance/ledger-entries", "ComplianceLedgerEntry", "chain_key:s seq:i entry_type:s subject_type:s subject_id:id payload!:j payload_hash:s prev_hash:s entry_hash:s actor_kind:e(user|system|anonymous_reporter|committee) actor_user_id?:id signer_role?:s occurred_at:dt recorded_at:dt", "LR", ""),
    ("CUM", 7, "compliance.deadlines", "/compliance/deadlines", "ComplianceDeadline", "legal_entity_id:id subject_type:s subject_id:id deadline_code:s parameter_code:s parameter_value:n day_kind:e(business|calendar|hours) starts_at:dt due_at:dt met_at?:dt responsible_user_id?:id status:e(running|met|met_late|overdue|cancelled)", "LR", ""),
    ("CUM", 7, "compliance.authority_communications", "/compliance/authority-communications", "AuthorityCommunication", "legal_entity_id:id authority:e(mtpe|sunafil|sunat|essalud|other) subject_type:s subject_id:id kind:s due_at?:dt sent_at?:dt receipt_reference?:s receipt_document_id?:id status:e(pending|sent|acknowledged|overdue|not_required)", "LRU", "mark-sent"),
    ("CUM", 7, "compliance.evidence_exports", "/compliance/evidence-exports", "EvidenceExport", "subject_type:s subject_id:id purpose:s includes_identity:b manifest:j manifest_sha256:s chain_heads:j package_file_id?:id package_sha256?:s", "LCR", ""),
    ("PRI", 7, "privacy.consents", "/privacy/consents", "Consent", "person_id:id purpose:s version:s granted_at:dt revoked_at?:dt channel:s", "LCR", "revoke"),
    ("PRI", 7, "privacy.subject_requests", "/privacy/subject-requests", "SubjectRequest", "person_id:id right:e(access|rectification|cancellation|opposition|portability) due_on:d resolution?:s status:e(received|in_progress|resolved|rejected)", "LCRU", "resolve reject"),
    ("PRI", 7, "privacy.incidents", "/privacy/incidents", "PrivacyIncident", "detected_at:dt description:s affected:j notify_before:dt notified_at?:dt status:e(open|contained|notified|closed)", "LCRU", "contain mark-notified close"),
    ("PRI", 7, "privacy.retention_policies", "/privacy/retention-policies", "RetentionPolicy", "entity:s country:s keep_years:n action:e(anonymize|delete|archive) legal_basis:s status:e(active|retired)", "LCRU", ""),
    ("SIN", 7, "unions.unions", "/unions", "Union", "name:s legal_entity_id:id registration?:s dues_concept_code?:s status:e(active|archived)", "LCRU", "archive"),
    ("SIN", 7, "unions.memberships", "/union-memberships", "UnionMembership", "union_id!:id employment_id!:id valid_from:d valid_to?:d role?:s", "LCRU", ""),
    ("SIN", 7, "unions.agreements", "/collective-agreements", "CollectiveAgreement", "union_id:id valid_from:d valid_to:d clauses:j status:e(draft|active|expired)", "LCRU", "activate"),
    # ---------- Fase 8 ----------
    ("ANA", 8, "analytics.dashboards", "/dashboards", "Dashboard", "name:s role_ids:a widgets:j shared:b", "LCRUD", ""),
    ("RPT", 8, "reports.reports", "/reports", "Report", "name:s source:s fields:a filters:j group_by:a metrics:j visualization:s schedule?:j shared:b", "LCRUD", "run"),
    ("RPT", 0, "reports.exports", "/exports", "ExportJob", "resource:s format:e(csv|xlsx|pdf) filters:j row_count?:i contains_sensitive?:b requested_by_user_id?:id requested_by_name?:s file_id?:id download_url?:s download_count?:i expires_at?:dt error?:s created_at?:dt status:e(queued|running|completed|failed|expired)", "LCR", ""),
    ("ALE", 8, "alerts.rules", "/alert-rules", "AlertRule", "key:s name:s params:j audience:j status:e(active|paused)", "LCRU", "pause resume"),
    ("ALE", 8, "alerts.alerts", "/alerts", "Alert", "rule_key:s title:s explanation:s factors:j subject_type?:s subject_id?:id severity:e(info|warning|critical) status:e(open|snoozed|dismissed|resolved)", "LR", "snooze dismiss resolve"),
    ("IAR", 8, "ai.conversations", "/ai/conversations", "AiConversation", "title?:s user_id:id messages:j", "LCR", "send-message"),
    ("IAR", 8, "ai.usage_logs", "/ai/usage-logs", "AiUsageLog", "user_id:id use_case:s provider:s model:s data_scopes:a tools_called:a input_ref:s output_ref:s occurred_at:dt", "LR", ""),
    ("IAR", 8, "ai.use_case_settings", "/ai/use-case-settings", "AiUseCaseSetting", "use_case:s enabled:b role_ids:a question_limit?:i notice?:s plan_allows:b", "LRU", ""),
    # ---------- Fase 9 ----------
    ("API", 9, "integrations.webhooks", "/webhook-endpoints", "WebhookEndpoint", "url:s events:a secret_hint:s status:e(active|paused|failing)", "LCRUD", "rotate-secret test pause resume"),
    ("API", 9, "integrations.webhook_deliveries", "/webhook-deliveries", "WebhookDelivery", "endpoint_id:id event:s event_id:id attempts:i response_code?:i next_attempt_at?:dt status:e(pending|delivered|failed|exhausted)", "LR", "retry"),
    ("INT", 9, "integrations.connections", "/integrations", "IntegrationConnection", "port:s driver:s config:j health:e(ok|degraded|down|unknown) last_checked_at?:dt status:e(connected|disconnected|error)", "LCRU", "test disconnect sync"),
    ("PAI", 9, "platform.country_packs", "/country-packs", "CountryPack", "country:s version:s contents:j status:e(available|active|retired)", "LR", "activate"),
    ("EST", 9, "partner.clients", "/partner/clients", "PartnerClient", "tenant_id:id name:s tax_id:s headcount:i next_deadline?:d status:e(active|paused)", "LCR", "enter"),
]

# Operaciones que no son CRUD: (épica, fase, método, ruta, permiso|None, resumen, público)
EXTRA = [
    # ESS-4 · Constancias desde el portal: catálogo, solicitud propia, emisión automática (constancias.emision_automatica), flujo de aprobación o emisión por RRHH; el PDF queda en el legajo.
    ("ESS", 1, "get", "/certificate-types", "people.certificates.request", "Constancias que el colaborador puede pedir para su relación laboral (?employment_id propia): trabajo, vacaciones y remuneraciones (esta solo con people.employments.salary.view propio); indica si se emite al instante", False),
    ("ESS", 1, "get", "/certificate-requests", "people.certificates.view", "Solicitudes de constancias: las propias para el colaborador, las del alcance para RRHH; ?filter[status], ?filter[employment_id], ?q", False),
    ("ESS", 1, "post", "/certificate-requests", "people.certificates.request", "Pedir una constancia propia (employment_id, kind, purpose). Si su tipo es de emisión automática se emite al instante (201 issued); si hay flujo publicado para certificate_request entra a aprobación; si no, espera a RRHH. 422 kind_not_available si el tipo no está disponible", False),
    ("ESS", 1, "get", "/certificate-requests/{id}", "people.certificates.view", "Detalle de una solicitud de constancia con su aprobación y el documento emitido", False),
    ("ESS", 1, "post", "/certificate-requests/{id}/issue", "people.certificates.issue", "RRHH emite la constancia pedida: genera el PDF y lo guarda en el legajo del colaborador (visible para él). 409 invalid_transition si ya se emitió o se rechazó", False),
    ("ESS", 1, "post", "/certificate-requests/{id}/reject", "people.certificates.issue", "RRHH rechaza la solicitud con motivo (reason). 409 invalid_transition si ya se emitió o se rechazó", False),
    ("ESS", 1, "get", "/certificate-requests/{id}/file", "people.certificates.view", "Descargar el PDF de una constancia emitida (cada descarga queda en el registro de accesos)", False),
    # ESS-5 · Tipos de solicitud a RRHH configurables, mensajes con adjunto e historial.
    ("ESS", 1, "get", "/ticket-categories", "helpdesk.tickets.create", "Tipos de solicitud a RRHH activos (los archivados con ?include_archived=true y helpdesk.settings.update): nombre, descripción, rol que la atiende, si exige adjunto y si es confidencial", False),
    ("ESS", 1, "post", "/ticket-categories", "helpdesk.settings.update", "Crear un tipo de solicitud a RRHH (code, name, description, default_assignee_role_id, requires_attachment, is_confidential, sort_order)", False),
    ("ESS", 1, "patch", "/ticket-categories/{id}", "helpdesk.settings.update", "Editar un tipo de solicitud a RRHH; status active|archived para archivarlo o reactivarlo (las solicitudes existentes lo conservan)", False),
    ("ESS", 1, "post", "/tickets/{id}/messages", "helpdesk.tickets.create", "Agregar un mensaje con adjunto opcional (multipart: body, file, is_internal_note). Si la solicitud espera información y responde el solicitante, vuelve a «en atención» (ME-31 responder). La nota interna solo la ve RRHH (helpdesk.tickets.update)", False),
    ("ESS", 1, "get", "/tickets/{id}/messages/{message_id}/file", "helpdesk.tickets.view", "Descargar el adjunto de un mensaje (se registra la descarga)", False),
    # DOC-1 · DOC-2 · Legajo digital: carpetas y tipos, alta y versiones por multipart, descarga y vista previa, vencimientos y papelera.
    ("DOC", 1, "get", "/document-categories", "documents.documents.view", "Carpetas del legajo (personal, contratos, boletas, certificados, vacaciones, SST, evaluaciones, capacitaciones) con sus tipos documentales: confidencialidad, visibilidad para el colaborador, revisión, vencimiento y anticipación del aviso (RN-DOC-09)", False),
    ("DOC", 1, "post", "/document-categories", "documents.document_types.update", "Crear un tipo documental dentro de una carpeta (o una carpeta propia de la organización)", False),
    ("DOC", 1, "patch", "/document-categories/{id}", "documents.document_types.update", "Editar nombre, confidencialidad, visibilidad, revisión y vencimiento de un tipo o carpeta; los documentos ya subidos conservan su clasificación", False),
    ("DOC", 1, "post", "/document-categories/{id}/archive", "documents.document_types.update", "Archivar un tipo documental: deja de ofrecerse al subir; sus documentos siguen en el legajo", False),
    ("DOC", 1, "post", "/document-categories/{id}/restore", "documents.document_types.update", "Restaurar un tipo documental archivado", False),
    ("DOC", 1, "post", "/documents", "documents.documents.create", "Subir un documento al legajo (multipart: file, category_id, title, employment_id o legal_entity_id, issued_on, expires_on, classification, visible_to_employee, description). Nace vigente; si lo sube el propio colaborador y su tipo exige revisión, queda en revisión", False),
    ("DOC", 1, "post", "/documents/{id}/approve", "documents.documents.approve", "Aprobar un documento en revisión (ME-12): queda vigente y, si su tipo vence, se agenda el aviso", False),
    ("DOC", 1, "post", "/documents/{id}/reject", "documents.documents.approve", "Rechazar un documento en revisión con motivo; se avisa al titular", False),
    ("DOC", 1, "delete", "/documents/{id}", "documents.documents.update", "Mover a la papelera un documento subido por error (no los que generan o firman otros módulos); se restaura desde la papelera y solo se depura al vencer la conservación legal", False),
    ("DOC", 1, "get", "/documents/expiring", "documents.documents.view", "Documentos por vencer y vencidos con su titular y su responsable (DOC-2 · RN-DOC-09), dentro del alcance del usuario", False),
    ("DOC", 1, "get", "/documents/{id}/versions", "documents.documents.view", "Versiones del documento, de la más reciente a la primera, con su huella, fechas y autor (RN-DOC-01)", False),
    ("DOC", 1, "post", "/documents/{id}/versions", "documents.documents.new_version", "Subir una versión nueva (multipart: file, issued_on, expires_on, notes); la anterior se conserva. No se admite en documentos firmados ni en los que gestiona otro módulo (RN-DOC-02)", False),
    ("DOC", 1, "get", "/documents/{id}/file", "documents.documents.view", "Descargar o previsualizar (inline=true) la versión vigente; si el documento es confidencial la lectura queda en el registro de accesos (RN-DOC-08)", False),
    ("DOC", 1, "get", "/documents/{id}/versions/{version}/file", "documents.documents.view", "Descargar o previsualizar una versión anterior", False),
    ("DOC", 1, "get", "/employments/{employment_id}/documents", "documents.documents.view", "Legajo de la relación laboral por carpeta: documentos propios y los que aportan otros módulos (contratos, sustentos y certificados de ausencias) sin duplicar archivos; cada uno según los permisos de quien consulta", False),
    ("DOC", 1, "get", "/employments/{employment_id}/linked-documents/{ref}/file", "documents.documents.view", "Descargar o previsualizar un documento que aporta otro módulo al legajo (exige además el permiso de ese módulo)", False),
    # DOC-3 · Solicitud de documentos: RRHH pide, el colaborador entrega desde el portal y la revisión va por el motor de aprobaciones.
    ("DOC", 1, "post", "/document-requests", "documents.documents.request", "Solicitar un tipo documental a una o varias personas (category_id, employment_ids o filter {legal_entity_id, org_unit_id, site_id}, title, instructions, due_on): crea una solicitud por persona en el mismo envío y avisa a cada colaborador", False),
    ("DOC", 1, "post", "/document-requests/{id}/submit", "documents.documents.create", "Entregar el documento solicitado (multipart: file, issued_on, expires_on): entra al legajo en revisión y la aprobación sigue el flujo publicado (document_request); una solicitud rechazada se vuelve a entregar", False),
    ("DOC", 1, "post", "/document-requests/{id}/cancel", "documents.documents.request", "Cancelar una solicitud pendiente, entregada o rechazada (reason); anula la aprobación en curso", False),
    # DOC-4 · DOC-5 · Plantillas: catálogo de variables, vista previa con datos reales y generación de PDF al legajo.
    ("DOC", 1, "get", "/document-templates/variables", "documents.templates.view", "Catálogo de variables seguras de la ficha, la empresa, el contrato y la compensación ({{grupo.variable}}), con su descripción, ejemplo y el permiso de campo que exigen (RN-DOC-10); también la sintaxis de bloques condicionales {{#if variable}}…{{else}}…{{/if}}", False),
    ("DOC", 1, "post", "/document-templates/{id}/preview", "documents.templates.generate", "Vista previa con los datos vigentes de una relación laboral (employment_id; body opcional para probar el borrador sin guardarlo): texto resuelto, variables desconocidas y las omitidas por falta de permiso de campo. La lectura de un dato sensible queda en el registro de accesos", False),
    ("DOC", 1, "post", "/document-templates/{id}/generate", "documents.templates.generate", "Generar el PDF desde la versión publicada para una o varias relaciones laborales (employment_ids): una sola se genera en el acto y varias en segundo plano; devuelve el lote con su avance y el documento de cada persona en el legajo (versión nueva si ya se generó antes). 403 si la plantilla usa una variable cuyo permiso de campo no se tiene (RN-DOC-10)", False),
    # CON-2 · CON-3 · Contrato (ME-11): acciones, documento, firma registrada, renovación y adendas.
    ("CON", 1, "post", "/contracts/{id}/submit", "contracts.contracts.create", "Enviar el borrador: valida tipo, fechas, causa objetiva, periodo de prueba y topes (RN-CON-01/02/03/07); queda en aprobación o, si la organización no la exige, en firma con su documento", False),
    ("CON", 1, "post", "/contracts/{id}/approve", "contracts.contracts.approve", "Aprobar el contrato: genera el documento desde la plantilla y lo deja listo para firmar", False),
    ("CON", 1, "post", "/contracts/{id}/return", "contracts.contracts.approve", "Devolver el contrato a borrador con observaciones (reason)", False),
    ("CON", 1, "post", "/contracts/{id}/register-signature", "contracts.contracts.issue", "Registrar el ejemplar firmado por ambas partes (multipart: file, signed_on, origin); desde borrador solo el contrato físico escaneado (RN-CON-06). Si la fecha de inicio llegó, rige", False),
    ("CON", 1, "post", "/contracts/{id}/decline-signature", "contracts.contracts.issue", "La firma no se completó: el contrato vuelve a borrador (reason)", False),
    ("CON", 1, "post", "/contracts/{id}/void", "contracts.contracts.update", "Anular el contrato antes de que empiece a regir (reason)", False),
    ("CON", 1, "post", "/contracts/{id}/terminate", "contracts.contracts.terminate", "Terminar el contrato vigente o vencido (reason, effective_on)", False),
    ("CON", 1, "post", "/contracts/{id}/renew", "contracts.contracts.renew", "Renovar o convertir: crea el contrato sucesor en borrador enlazado al anterior; superar los topes acumulados responde 422 contract_limit_exceeded con la explicación, salvo confirmación expresa (confirm_over_limit=true) con motivo (over_limit_reason), que queda auditada (CON-4)", False),
    # CON-4 · Por vencer y vencidos sin regularizar, con el acumulado frente al tope y la acción sugerida.
    ("CON", 1, "get", "/contracts/expiring", "contracts.contracts.view", "Contratos que vencen dentro de la ventana (por defecto, la mayor anticipación de aviso de su empresa) y vencidos sin regularizar (RN-CON-05), con días para vencer, acumulado frente al tope (RN-CON-02/03) y acción sugerida (renew, convert_indefinite, continue_renewal, regularize); excluye los regularizados", False),
    ("CON", 1, "get", "/contracts/{id}/document", "contracts.contracts.view", "Descargar el documento vigente del contrato (generado o firmado)", False),
    ("CON", 1, "get", "/employments/{employment_id}/contracts", "contracts.contracts.view", "Cadena de contratos de la relación en orden: contrato inicial, renovaciones y sus adendas (CON-3)", False),
    ("CON", 1, "post", "/contract-addenda/{id}/issue", "contracts.addenda.issue", "Emitir la adenda: genera su documento y la deja en firma", False),
    ("CON", 1, "post", "/contract-addenda/{id}/register-signature", "contracts.addenda.issue", "Registrar la adenda firmada (multipart: file, signed_on, origin); aplica sus cambios con vigencia (RN-CON-08)", False),
    ("CON", 1, "post", "/contract-addenda/{id}/cancel", "contracts.addenda.cancel", "Cancelar la adenda antes de firmarse (reason)", False),
    ("CON", 1, "get", "/contract-addenda/{id}/document", "contracts.addenda.view", "Descargar el documento vigente de la adenda", False),
    # DOC-6 · DOC-7 · DOC-8 · Flujo de firma: evidencia, documento y constancia; el firmante firma desde el portal.
    ("DOC", 1, "get", "/signature-requests/{id}/evidence", "documents.signature_requests.evidence", "Evidencia de la firma (DOC-7): eventos encadenados (huella SHA-256 del documento, IP, dispositivo, canal del código, evidencia del proveedor con certificado y sello de tiempo), si la cadena está íntegra (chain_valid) y si el archivo firmado no cambió (document_intact)", False),
    ("DOC", 1, "get", "/signature-requests/{id}/document", "documents.signature_requests.view", "Versión del documento que se envió a firma", False),
    ("DOC", 1, "get", "/signature-requests/{id}/certificate", "documents.signature_requests.view", "Constancia de firma (PDF) con la huella del documento y la evidencia de cada firmante; existe al completarse", False),
    ("DOC", 1, "get", "/me/signature-requests", "documents.signature_requests.sign", "Mis documentos por firmar y firmados (portal): los abiertos primero, con my_turn y my_status", False),
    ("DOC", 1, "get", "/me/signature-requests/{id}", "documents.signature_requests.sign", "Una solicitud de firma donde soy firmante", False),
    ("DOC", 1, "get", "/me/signature-requests/{id}/document", "documents.signature_requests.sign", "El documento que debo firmar (o la constancia, si ya se completó)", False),
    ("DOC", 1, "post", "/me/signature-requests/{id}/view", "documents.signature_requests.sign", "Registrar que abrí el documento (fecha, hora, IP y dispositivo)", False),
    ("DOC", 1, "post", "/me/signature-requests/{id}/code", "documents.signature_requests.sign", "Enviarme el código de un solo uso para firmar (correo o SMS); 409 not_your_turn si aún no me toca", False),
    ("DOC", 1, "post", "/me/signature-requests/{id}/sign", "documents.signature_requests.sign", "Firmar con el código (code); 422 si el código no es válido, 409 document_changed si el documento cambió después del envío", False),
    ("DOC", 1, "post", "/me/signature-requests/{id}/decline", "documents.signature_requests.decline", "Rechazar la firma con motivo (reason); la solicitud termina y se avisa a quien la envió", False),
    # CON-5 · Renovación masiva con firma.
    ("CON", 1, "get", "/contract-renewal-batches", "contracts.contracts.view", "Lotes de renovación masiva con su avance", False),
    ("CON", 1, "post", "/contract-renewal-batches", "contracts.contracts.bulk_renew", "Crear un lote de renovación (name, new_end_date, contract_ids): cada contrato se revisa contra el tope acumulado (RN-CON-02/03) y el que lo supera queda excluido con la explicación", False),
    ("CON", 1, "get", "/contract-renewal-batches/{id}", "contracts.contracts.view", "Lote con cada contrato: decisión, control del tope, contrato sucesor y avance de firma", False),
    ("CON", 1, "post", "/contract-renewal-batches/{id}/send", "contracts.contracts.bulk_renew", "Generar las renovaciones (contrato sucesor y documento desde plantilla) y enviarlas a firma en lote; lo que falla queda informado en su fila", False),
    ("CON", 1, "post", "/contract-renewal-batches/{id}/cancel", "contracts.contracts.bulk_renew", "Cancelar un lote en borrador", False),
    ("CON", 1, "post", "/remote-work-agreements/{id}/approve", "contracts.remote_work.decide", "Aprobar la solicitud de teletrabajo (responded_on = hoy)", False),
    ("CON", 1, "post", "/remote-work-agreements/{id}/reject", "contracts.remote_work.decide", "Rechazar la solicitud de teletrabajo con su motivo (reason)", False),
    ("CON", 1, "post", "/remote-work-agreements/{id}/issue", "contracts.remote_work.issue", "Emitir el acuerdo: exige el contenido mínimo completo (RN-CON-10, 422 con missing_content), genera su documento y lo deja en firma", False),
    ("CON", 1, "post", "/remote-work-agreements/{id}/register-signature", "contracts.remote_work.issue", "Registrar el acuerdo firmado (multipart: file, signed_on, origin); queda vigente", False),
    ("CON", 1, "post", "/remote-work-agreements/{id}/end", "contracts.remote_work.issue", "Terminar el acuerdo vigente (ends_on, reason)", False),
    ("CON", 1, "get", "/remote-work-agreements/{id}/document", "contracts.remote_work.view", "Descargar el documento vigente del acuerdo de teletrabajo", False),
    ("COL", 1, "get", "/people/document-match", "people.people.view", "Buscar una persona por su documento (RN-COL-01): si ya existe, devuelve su identificación y sus relaciones para ofrecer reingreso o relación adicional", False),
    ("COL", 1, "get", "/employment-options", "people.employments.view", "Opciones del formulario de ingreso: empresas, puestos, posiciones libres, unidades, sedes, centros de costo, tipos de contrato, regímenes y jefes", False),
    ("COL", 1, "post", "/employments/{id}/cancel", "people.employments.update", "Anular un ingreso que aún no empezó (ME-01: Por ingresar → Anulada); libera la posición", False),
    ("HIS", 1, "get", "/compensation-concepts", "people.compensations.view", "Conceptos fijos recurrentes del paquete de país que se pueden asignar como componentes de la compensación (HIS-3)", False),
    ("COL", 1, "get", "/termination-reasons", "people.employments.terminate", "Motivos de cese de la organización, enlazados a la Tabla 17 del paquete de país (COL-11)", False),
    # COL-3 · COL-5 · Ficha 360: cabecera y datos laborales vigentes (la asignación cambia con un movimiento).
    ("COL", 1, "get", "/employments/{id}/profile", "people.employments.view", "Cabecera de la ficha 360: nombre, foto, puesto, unidad, estado, antigüedad calculada por el servidor y las otras relaciones laborales de la persona (dentro del alcance) para cambiar de ficha", False),
    # HIS-5 · Línea de tiempo laboral.
    ("HIS", 1, "get", "/employments/{id}/timeline", "people.employments.view", "Línea de tiempo laboral de la relación: ingreso o reingreso, movimientos con el antes y el después de cada campo, cambios de sueldo (solo con people.employments.salary.view; lectura registrada), contratos y adendas, estados y cese; cada tipo exige además el permiso de su recurso (meta.types)", False),
    ("COL", 1, "get", "/employments/{id}/work-data", "people.employments.view", "Datos laborales vigentes a una fecha (código, empresa, sede, unidad, puesto, posición, jefe, centro de costo, modalidad, régimen, contrato, estado): cada uno con su valor, desde cuándo rige y la historia a la que enlaza; antigüedad calculada", False),
    # COL-4 · Datos personales de la persona (compartidos entre sus relaciones laborales).
    ("COL", 1, "get", "/people/{id}/photo", "people.people.view", "Foto de la persona (404 si no tiene)", False),
    ("COL", 1, "post", "/people/{id}/photo", "people.people.update", "Subir o reemplazar la foto (PNG, JPEG o WebP hasta 1 MB)", False),
    ("COL", 1, "delete", "/people/{id}/photo", "people.people.update", "Quitar la foto", False),
    ("COL", 1, "get", "/people/{id}/addresses", "people.people.view", "Direcciones vigentes de la persona (con history=true también las anteriores); exige people.people.personal_data.view y cada lectura queda registrada", False),
    ("COL", 1, "post", "/people/{id}/addresses", "people.people.update", "Registrar una dirección: la anterior del mismo tipo queda como histórica (exige people.people.personal_data.edit)", False),
    ("COL", 1, "patch", "/people/{id}/addresses/{address_id}", "people.people.update", "Corregir una dirección (exige people.people.personal_data.edit; queda auditado)", False),
    ("COL", 1, "delete", "/people/{id}/addresses/{address_id}", "people.people.update", "Eliminar una dirección (pasa a la papelera; exige people.people.personal_data.edit)", False),
    ("COL", 1, "get", "/people/{id}/emergency-contacts", "people.people.view", "Contactos de emergencia de la persona; exige people.people.personal_data.view y cada lectura queda registrada", False),
    ("COL", 1, "post", "/people/{id}/emergency-contacts", "people.people.update", "Agregar un contacto de emergencia (exige people.people.personal_data.edit)", False),
    ("COL", 1, "patch", "/people/{id}/emergency-contacts/{contact_id}", "people.people.update", "Editar un contacto de emergencia (exige people.people.personal_data.edit)", False),
    ("COL", 1, "delete", "/people/{id}/emergency-contacts/{contact_id}", "people.people.update", "Eliminar un contacto de emergencia (exige people.people.personal_data.edit)", False),
    ("COL", 1, "get", "/people/{id}/sensitive-data", "people.people.view", "Condición de discapacidad y grupo sanguíneo con su consentimiento (exige people.people.health_data.view; cada lectura queda registrada)", False),
    ("COL", 1, "put", "/people/{id}/sensitive-data", "people.people.update", "Registrar la condición de discapacidad o el grupo sanguíneo; la discapacidad exige consentimiento expreso (RN-COL-08) y los permisos health_data.view y personal_data.edit", False),
    # COL-6 · Derechohabientes: baja con fecha y motivo (no se borran) y documento de sustento.
    ("COL", 1, "post", "/employments/{employment_id}/dependents/{id}/deregister", "people.dependents.update", "Dar de baja a un derechohabiente con fecha y motivo (registro laboral y seguros); queda en la historia", False),
    ("COL", 1, "get", "/employments/{employment_id}/dependents/{id}/proof", "people.dependents.view", "Descargar el documento de sustento del derechohabiente (se registra la descarga)", False),
    ("COL", 1, "post", "/employments/{employment_id}/dependents/{id}/proof", "people.dependents.update", "Subir o reemplazar el documento de sustento (multipart, campo file; PDF, JPG o PNG). Con sustento, un hijo cuenta para la asignación familiar (RN-COL-07)", False),
    # COL-7 · Régimen pensionario, salud y seguros del trabajador (con vigencia).
    ("COL", 1, "get", "/employments/{employment_id}/pension-affiliations", "people.employments.view", "Historia de la afiliación previsional (sistema, administradora, tipo de comisión) con vigencia; el código de afiliado solo con permiso de datos personales", False),
    ("COL", 1, "post", "/employments/{employment_id}/pension-affiliations", "people.employments.update", "Registrar un cambio de AFP, de tipo de comisión o de sistema desde una fecha efectiva: cierra la afiliación vigente el día anterior", False),
    ("COL", 1, "get", "/employments/{employment_id}/health-regimes", "people.employments.view", "Historia del régimen de salud (EsSalud, EPS con plan, SIS) con vigencia", False),
    ("COL", 1, "post", "/employments/{employment_id}/health-regimes", "people.employments.update", "Registrar un cambio del régimen de salud desde una fecha efectiva (EPS exige entidad y plan)", False),
    ("COL", 1, "get", "/employments/{employment_id}/insurance-coverages", "people.employments.view", "Coberturas del trabajador en las pólizas del empleador (Vida Ley, SCTR salud y pensión, plan de salud)", False),
    ("COL", 1, "post", "/employments/{employment_id}/insurance-coverages", "people.employments.update", "Incluir al trabajador en una póliza de su empresa desde una fecha", False),
    ("COL", 1, "post", "/employments/{employment_id}/insurance-coverages/{id}/end", "people.employments.update", "Terminar la cobertura del trabajador en una póliza (fecha de fin)", False),
    ("COL", 1, "get", "/employments/{employment_id}/life-insurance-declarations", "people.employments.view", "Declaraciones de beneficiarios de Vida Ley, la vigente primero; los beneficiarios solo con permiso de datos personales", False),
    ("COL", 1, "post", "/employments/{employment_id}/life-insurance-declarations", "people.employments.update", "Archivar una declaración firmada de beneficiarios de Vida Ley (multipart: beneficiaries en JSON que suman 100 %, signed_on y file); la anterior queda reemplazada", False),
    ("COL", 1, "get", "/employments/{employment_id}/life-insurance-declarations/{id}/file", "people.employments.view", "Descargar la declaración firmada (exige además permiso de datos personales; se registra la descarga)", False),
    ("COL", 1, "get", "/pension-funds", "people.employments.view", "Administradoras y sistemas de pensiones del país (?country=PE)", False),
    ("COL", 1, "get", "/health-providers", "people.employments.view", "Entidades de salud y aseguradoras con las que trabaja la organización", False),
    ("COL", 1, "post", "/health-providers", "org.legal_entities.update", "Registrar una EPS o aseguradora de la organización", False),
    ("COL", 1, "get", "/insurance-policies", "org.legal_entities.view", "Pólizas del empleador por empresa (Vida Ley, SCTR, plan de salud), con el alcance de empresa del usuario", False),
    ("COL", 1, "post", "/insurance-policies", "org.legal_entities.update", "Registrar una póliza del empleador para una empresa", False),
    ("COL", 1, "patch", "/insurance-policies/{id}", "org.legal_entities.update", "Actualizar una póliza (aseguradora, número, fin, estado)", False),
    # COL-8 · Cuentas de pago con vigencia y aprobación de cuatro ojos (RN-COL-11).
    ("COL", 1, "get", "/employments/{employment_id}/bank-accounts", "people.employments.view", "Cuentas de pago (haberes, CTS) con vigencia y estado; el número y el CCI completos solo con people.people.bank_account.view", False),
    ("COL", 1, "post", "/employments/{employment_id}/bank-accounts", "people.people.bank_account.edit", "Registrar una cuenta nueva o el cambio de la vigente: queda pendiente de aprobación y se avisa al titular por un canal distinto", False),
    ("COL", 1, "post", "/employments/{employment_id}/bank-accounts/{id}/approve", "people.change_requests.approve", "Aprobar el cambio de cuenta (otra persona que quien lo registró): cierra la cuenta anterior y rige desde su fecha", False),
    ("COL", 1, "post", "/employments/{employment_id}/bank-accounts/{id}/reject", "people.change_requests.approve", "Rechazar el cambio de cuenta con motivo", False),
    ("COL", 1, "post", "/employments/{employment_id}/bank-accounts/{id}/cancel", "people.people.bank_account.edit", "Cancelar un cambio de cuenta pendiente con motivo", False),
    ("SEG", 0, "post", "/auth/login", None, "Iniciar sesión (correo o documento de identidad + clave)", True),
    ("SEG", 0, "post", "/auth/password/forgot", None, "Solicitar el enlace de recuperación de clave (misma respuesta exista o no el correo)", True),
    ("SEG", 0, "post", "/auth/password/reset", None, "Restablecer la clave con el enlace de un solo uso", True),
    ("SEG", 0, "post", "/auth/invitation/accept", None, "Activar la cuenta con el enlace de invitación y definir la clave", True),
    ("SEG", 0, "post", "/auth/otp/request", None, "Pedir un código de ingreso por SMS/WhatsApp con el documento (responde igual exista o no)", True),
    ("SEG", 0, "post", "/auth/otp/verify", None, "Ingresar con documento y código de un solo uso (caduca, un uso, tope de intentos)", True),
    ("SEG", 0, "post", "/auth/activation/code", None, "Activar la cuenta sin correo: documento, código de activación y clave nueva", True),
    ("SEG", 0, "get", "/me/sessions", "-", "Sesiones abiertas del usuario actual (dispositivo, IP, último uso) y márgenes de sesión", False),
    ("SEG", 0, "post", "/me/sessions/{id}/revoke", "-", "Cerrar una de las propias sesiones", False),
    ("SEG", 0, "post", "/me/password", "-", "Cambiar la propia clave (exige la actual, aplica la política y cierra las demás sesiones)", False),
    ("SEG", 0, "post", "/users/{id}/revoke-sessions", "identity.users.suspend", "Forzar el cierre de todas las sesiones de un usuario (con motivo, auditado)", False),
    ("SEG", 0, "post", "/auth/mfa/verify", "-", "Segundo paso del ingreso: código de la aplicación autenticadora o código de respaldo", False),
    ("SEG", 0, "get", "/me/mfa", "-", "Estado del segundo factor del usuario actual (activo, exigido, códigos de respaldo restantes, paso pendiente)", False),
    ("SEG", 0, "post", "/me/mfa/totp", "-", "Iniciar la configuración de la aplicación autenticadora (secreto y URI otpauth)", False),
    ("SEG", 0, "post", "/me/mfa/totp/confirm", "-", "Confirmar la aplicación autenticadora con un código; devuelve los códigos de respaldo una sola vez", False),
    ("SEG", 0, "post", "/me/mfa/recovery-codes", "-", "Generar códigos de respaldo nuevos (exige la clave; invalida los anteriores)", False),
    ("SEG", 0, "post", "/me/mfa/disable", "-", "Desactivar el segundo factor (exige la clave, auditado; no procede si un rol lo exige)", False),
    ("SEG", 0, "post", "/users/{id}/activation-code", "identity.users.invite", "Emitir el código de activación de un usuario sin correo (se muestra una vez o se envía por SMS/WhatsApp)", False),
    ("SEG", 0, "post", "/roles/{id}/mfa-requirement", "identity.security_policies.update", "Exigir o no el segundo factor a quienes tengan el rol (RN-SEG-08, con motivo, auditado)", False),
    ("PLT", 0, "get", "/health", None, "Salud de la API: disponible sin sesión ni organización", True),
    ("TEN", 0, "get", "/catalogs/organization", "org.legal_entities.view", "Catálogos para registrar empresas: países, monedas y regímenes laborales", False),
    ("SEG", 0, "post", "/auth/logout", "-", "Cerrar sesión", False),
    ("SEG", 0, "get", "/me", "-", "Usuario actual, permisos efectivos, alcance y módulos activos", False),
    ("SEG", 0, "get", "/permissions", "identity.roles.view", "Catálogo de permisos y permisos por campo", False),
    ("TEN", 0, "get", "/tenant", "settings.organization.view", "Organización actual: datos, plan, módulos y límites", False),
    ("TEN", 0, "patch", "/tenant", "settings.organization.update", "Actualizar datos de la organización", False),
    ("SAS", 0, "get", "/platform/health", "platform.health.view", "Salud de la plataforma: colas, trabajos fallidos, webhooks, conectores", False),
    ("SAS", 0, "get", "/platform/metrics", "platform.metrics.view", "Indicadores de la consola SaaS (SAS-10), calculados desde suscripciones, consumo y auditoría, cada uno con su definición: organizaciones activas, nuevas y suspendidas, usuarios, colaboradores administrados, ingresos recurrentes mensuales y anuales por moneda y consolidados, cancelaciones, pruebas, conversiones, almacenamiento, módulos más usados e incidencias; filtros from, to y plan_id", False),
    ("SAS", 0, "post", "/platform/auth/login", None, "Consola SaaS: ingresar con correo y clave; abre la sesión de la consola con el segundo paso pendiente (siempre obligatorio)", True),
    ("SAS", 0, "post", "/platform/auth/logout", "platform", "Consola SaaS: cerrar la sesión de la consola", False),
    ("SAS", 0, "get", "/platform/me", "platform", "Consola SaaS: usuario actual, rol interno, paso del segundo factor y permisos de la consola", False),
    ("SAS", 0, "post", "/platform/auth/mfa/totp", "platform", "Consola SaaS: iniciar la configuración de la aplicación autenticadora (primer ingreso)", False),
    ("SAS", 0, "post", "/platform/auth/mfa/totp/confirm", "platform", "Consola SaaS: confirmar la aplicación autenticadora con un código; completa el ingreso", False),
    ("SAS", 0, "post", "/platform/auth/mfa/verify", "platform", "Consola SaaS: segundo paso del ingreso con el código de la aplicación autenticadora", False),
    ("SAS", 0, "get", "/platform/audit-events", "platform.audit.view", "Consola SaaS: auditoría propia de la consola (solo inserción)", False),
    ("SAS", 0, "post", "/platform/tenants/{id}/activate", "platform.tenants.reactivate", "Consola SaaS: convertir una organización en prueba en activa (ME-40 convertir)", False),
    ("SAS", 0, "post", "/platform/tenants/{id}/block", "platform.tenants.suspend", "Consola SaaS: bloquear una organización por falta de pago (con motivo; sus usuarios no ingresan)", False),
    ("SAS", 0, "get", "/platform/tenants/{id}/status-events", "platform.tenants.view", "Consola SaaS: historial inalterable de estados de la organización", False),
    ("SAS", 0, "get", "/platform/tenants/{id}/modules", "platform.subscriptions.view", "Consola SaaS: módulos comerciales de la organización (activos, en solo lectura o no contratados) y sus dependencias", False),
    ("SAS", 0, "post", "/platform/tenants/{id}/modules/{code}/activate", "platform.subscriptions.update", "Consola SaaS: activar un módulo en la organización sin migrar datos (activa también sus dependencias)", False),
    ("SAS", 0, "post", "/platform/tenants/{id}/modules/{code}/deactivate", "platform.subscriptions.update", "Consola SaaS: retirar un módulo; sus datos se conservan en solo lectura durante la transición (RN-SAS-06)", False),
    ("SAS", 0, "get", "/platform/plans/{id}/versions", "platform.plans.view", "Consola SaaS: versiones de un plan (editar un plan con suscriptores crea la siguiente y conserva la anterior para ellos)", False),
    ("SAS", 0, "get", "/platform/subscriptions/{id}/events", "platform.subscriptions.view", "Consola SaaS: historial de la suscripción, cada cambio con su autor", False),
    ("SAS", 0, "post", "/platform/subscriptions/{id}/convert", "platform.subscriptions.change_plan", "Consola SaaS: convertir una suscripción en prueba (o vencida) en activa; la organización vuelve a operar (ME-40 convertir o regularizar)", False),
    ("SAS", 0, "post", "/platform/subscriptions/{id}/cancel", "platform.tenants.cancel", "Consola SaaS: cancelar la suscripción y dar de baja la organización (con motivo; los datos se conservan, RN-SAS-10)", False),
    ("SAS", 0, "post", "/platform/subscriptions/{id}/addons", "platform.subscriptions.update", "Consola SaaS: sumar un complemento; su módulo queda activo al instante, sin migrar", False),
    ("SAS", 0, "post", "/platform/subscriptions/{id}/addons/{addonId}/remove", "platform.subscriptions.update", "Consola SaaS: quitar un complemento desde hoy; sus módulos pasan a solo lectura (RN-SAS-06)", False),
    ("SAS", 0, "get", "/platform/tenants/{id}/usage", "platform.subscriptions.view", "Consola SaaS: consumo de la organización frente a los límites de su plan (actual e historia por día; SAS-6, RN-SAS-12: solo cifras)", False),
    ("SAS", 0, "get", "/platform/subscriptions/{id}/plan-fit", "platform.subscriptions.view", "Consola SaaS: comparar el consumo actual con los límites de otro plan antes de cambiarlo (over = las altas quedarían bloqueadas hasta regularizar)", False),
    ("SAS", 0, "post", "/platform/feature-flags/{id}/archive", "platform.feature_flags.update", "Consola SaaS: archivar una bandera (deja de evaluarse; reglas y activaciones se conservan)", False),
    ("SAS", 0, "post", "/platform/feature-flags/{id}/restore", "platform.feature_flags.update", "Consola SaaS: restaurar una bandera archivada", False),
    ("SAS", 0, "get", "/platform/feature-flags/{id}/tenants", "platform.feature_flags.view", "Consola SaaS: organizaciones con activación explícita de la bandera (prevalece sobre las reglas, RN-SAS-07)", False),
    ("SAS", 0, "post", "/platform/feature-flags/{id}/tenants", "platform.feature_flags.update", "Consola SaaS: activar o desactivar la bandera para una organización (con motivo y vencimiento opcional; auditado)", False),
    ("SAS", 0, "post", "/platform/feature-flags/{id}/tenants/{tenantId}/remove", "platform.feature_flags.update", "Consola SaaS: quitar la activación explícita; la organización vuelve a seguir las reglas", False),
    ("SAS", 0, "get", "/platform/tenants/{id}/feature-flags", "platform.feature_flags.view", "Consola SaaS: resultado de cada bandera para la organización y qué regla decidió (misma evaluación que su API y su web)", False),
    # SAS-8 · Soporte auditado: a quién suplantar (solo nombre, roles y estado; RN-SAS-12) y, en la organización, su control del acceso.
    ("SAS", 0, "get", "/platform/tenants/{id}/users", "platform.support_sessions.create", "Consola SaaS: usuarios de la organización para elegir a quién suplantar en una sesión de soporte (solo nombre, roles y estado; RN-SAS-12)", False),
    ("SAS", 0, "post", "/auth/support/enter", None, "Ingresar con el enlace de un solo uso de una sesión de soporte (SAS-8): abre una sesión marcada «soporte» que caduca sola; 422 si el enlace no vale o el acceso de soporte está desactivado", True),
    ("SAS", 0, "get", "/support-access", "identity.security_policies.view", "Acceso del soporte de GestiaHR a la organización: si está habilitado, autorizaciones vigentes y sesiones de soporte con motivo, inicio y fin (SAS-8)", False),
    ("SAS", 0, "patch", "/support-access", "identity.security_policies.update", "Habilitar o desactivar por completo el acceso del soporte (desactivarlo cierra las sesiones abiertas); queda auditado", False),
    ("SAS", 0, "post", "/support-access/grants", "identity.security_policies.update", "Autorizar al soporte por un tiempo (read_only, o full para ver campos sensibles) con motivo", False),
    ("SAS", 0, "post", "/support-access/grants/{id}/revoke", "identity.security_policies.update", "Revocar una autorización al soporte antes de su vencimiento", False),
    # SAS-9 · Monitoreo: trabajos y eventos fallidos con reintento (sin datos de RRHH).
    ("SAS", 0, "get", "/platform/health/failed-jobs", "platform.health.view", "Consola SaaS: trabajos de cola fallidos (cola, error resumido, organización y fecha)", False),
    ("SAS", 0, "post", "/platform/health/failed-jobs/{id}/retry", "platform.health.manage", "Consola SaaS: reintentar un trabajo fallido (sale de la lista y vuelve a la cola); auditado", False),
    ("SAS", 0, "get", "/platform/health/outbox-events", "platform.health.view", "Consola SaaS: eventos de dominio fallidos o descartados por organización (tipo, intentos y error, sin su contenido)", False),
    ("SAS", 0, "post", "/platform/health/outbox-events/{id}/retry", "platform.health.manage", "Consola SaaS: volver a encolar un evento fallido o descartado (409 si ya no lo está); auditado", False),
    # SAS-12 · Copias de seguridad y restauración en aislado con doble aprobación (RN-SAS-09).
    ("SAS", 0, "get", "/platform/backups", "platform.backups.view", "Consola SaaS: historial de copias con resultado, tamaño, retención y restauraciones", False),
    ("SAS", 0, "post", "/platform/backups", "platform.backups.create", "Consola SaaS: lanzar una copia manual de la plataforma o de una organización (la de una organización es su exportación completa)", False),
    ("SAS", 0, "get", "/platform/backups/{id}", "platform.backups.view", "Consola SaaS: detalle de una copia y sus restauraciones", False),
    ("SAS", 0, "post", "/platform/backups/{id}/restores", "platform.backups.restore", "Consola SaaS: pedir una restauración (sandbox por defecto; a producción solo tras una restauración aislada verificada de la misma copia); espera la aprobación de otro usuario", False),
    ("SAS", 0, "get", "/platform/backup-restores", "platform.backups.view", "Consola SaaS: restauraciones pedidas, aprobadas, de prueba programada y su evidencia de verificación", False),
    ("SAS", 0, "post", "/platform/backup-restores/{id}/approve", "platform.backups.restore", "Consola SaaS: segunda confirmación de una restauración por otro usuario de la consola; la ejecuta y deja la verificación (409 si la aprueba quien la pidió)", False),
    ("SAS", 0, "post", "/platform/backup-restores/{id}/cancel", "platform.backups.restore", "Consola SaaS: cancelar una restauración pendiente de aprobación", False),
    # SAS-11 · ADR 0010 · Cobro de la suscripción: cargos, comprobante electrónico (OSE o PSE por adaptador), pagos y estado de cuenta.
    ("SAS", 0, "get", "/platform/invoices", "platform.billing.view", "Consola SaaS: comprobantes del SaaS por organización, estado y estado del comprobante electrónico", False),
    ("SAS", 0, "get", "/platform/invoices/{id}", "platform.billing.view", "Consola SaaS: comprobante con sus líneas, impuesto aplicado, constancia de aceptación o motivo del rechazo, y pagos", False),
    ("SAS", 0, "post", "/platform/subscriptions/{id}/invoices", "platform.billing.manage", "Consola SaaS: generar en borrador el cargo del periodo siguiente (o del que empieza en period_start) con los precios guardados en la suscripción y sus complementos; 409 si el periodo ya tiene cargo", False),
    ("SAS", 0, "post", "/platform/invoices/{id}/issue", "platform.billing.manage", "Consola SaaS: emitir el comprobante electrónico (impuesto del parámetro legal vigente; 422 si está por validar); lo envía al proveedor y solo queda emitido con la constancia de aceptación, un rechazo lo deja observado", False),
    ("SAS", 0, "post", "/platform/invoices/{id}/void", "platform.billing.manage", "Consola SaaS: anular con motivo un borrador o un comprobante observado (uno aceptado no se anula aquí)", False),
    ("SAS", 0, "post", "/platform/invoices/{id}/payments", "platform.billing.manage", "Consola SaaS: registrar un pago manual ({amount, currency}, fecha, medio) o uno de la pasarela (gateway_ref, verificado por el adaptador de cobro); al cubrir el total queda pagado y se regulariza la suscripción", False),
    ("SAS", 0, "get", "/platform/tenants/{id}/account-statement", "platform.billing.view", "Consola SaaS: estado de cuenta de una organización (plan, saldo por moneda, comprobantes, pagos y regla de mora)", False),
    ("SAS", 0, "get", "/billing/account", "settings.organization.view", "Estado de cuenta de la organización: plan, comprobantes electrónicos con su constancia de aceptación, pagos, saldo, próximo vencimiento y regla de mora", False),
    # SAS-13 · Configuración global, avisos de mantenimiento y registros de la consola.
    ("SAS", 0, "get", "/platform/settings", "platform.settings.view", "Consola SaaS: configuración global vigente (valor, valor por defecto y descripción de cada clave)", False),
    ("SAS", 0, "patch", "/platform/settings/{key}", "platform.settings.update", "Consola SaaS: cambiar una configuración global (validada por clave); auditado con antes y después", False),
    ("SAS", 0, "get", "/platform/notices", "platform.settings.view", "Consola SaaS: avisos de la plataforma (vigentes, programados, vencidos y cancelados)", False),
    ("SAS", 0, "post", "/platform/notices", "platform.settings.update", "Consola SaaS: publicar un aviso de mantenimiento para todas las organizaciones o las seleccionadas", False),
    ("SAS", 0, "patch", "/platform/notices/{id}", "platform.settings.update", "Consola SaaS: editar un aviso no cancelado", False),
    ("SAS", 0, "post", "/platform/notices/{id}/cancel", "platform.settings.update", "Consola SaaS: cancelar un aviso (deja de mostrarse)", False),
    ("SAS", 0, "get", "/system-notices", "-", "Avisos de la plataforma vigentes para la organización (mantenimiento e incidencias; SAS-13)", False),
    ("SAS", 0, "get", "/platform/activity-events", "platform.logs.view", "Consola SaaS: actividad de los usuarios de una organización (acción y fecha, sin datos de RRHH)", False),
    ("SAS", 0, "get", "/platform/technical-logs", "platform.logs.view", "Consola SaaS: logs técnicos estructurados (ya sin datos sensibles) filtrados por organización, usuario, nivel y fecha", False),
    ("SAS", 0, "get", "/usage", "settings.organization.view", "Consumo de la organización frente a los límites de su plan (colaboradores, administradores, empresas, almacenamiento, llamadas a la API; SAS-6)", False),
    ("WEB", 0, "get", "/public/catalog", None, "Sitio: productos, planes públicos con módulos, límites y complementos, y estado de cada módulo", True),
    ("WEB", 0, "get", "/public/ruc/{ruc}", None, "Sitio: validar un RUC (dígito verificador, razón social y si ya está registrado)", True),
    ("WEB", 0, "get", "/public/subdomains/{slug}", None, "Sitio: disponibilidad de un subdominio", True),
    ("WEB", 0, "post", "/public/signups", None, "Sitio: iniciar un registro de prueba o suscripción (paso cuenta)", True),
    ("WEB", 0, "patch", "/public/signups/{token}", None, "Sitio: guardar el paso empresa o plan de un registro en curso", True),
    ("WEB", 0, "post", "/public/signups/{token}/complete", None, "Sitio: completar el registro; crea la organización o inicia el pago", True),
    ("WEB", 0, "post", "/public/leads", None, "Sitio: formulario de contacto, partner, boletín, calculadora o lista de espera (con consentimiento)", True),
    ("WEB", 0, "get", "/public/demo-slots", None, "Sitio: horarios libres para agendar una demo", True),
    ("WEB", 0, "post", "/public/demo-bookings", None, "Sitio: agendar una demo", True),
    ("WEB", 0, "post", "/public/demo-bookings/{token}/cancel", None, "Sitio: cancelar o reprogramar una demo desde el enlace del correo", True),
    ("WEB", 0, "post", "/public/chat/messages", None, "Sitio: mensaje al chatbot comercial (solo contenido público)", True),
    ("WEB", 0, "post", "/public/complaints", None, "Sitio: registrar un reclamo o queja en el libro de reclamaciones virtual", True),
    ("WEB", 0, "get", "/platform/web-metrics", "platform.web_leads.view", "Embudo del sitio: visitas, registros, demos, pagos y lista de espera", False),
    ("WEB", 0, "get", "/platform/web-settings", "platform.web_settings.view", "Ajustes del sitio: chatbot, popups, cookies y prueba social", False),
    ("WEB", 0, "patch", "/platform/web-settings", "platform.web_settings.update", "Cambiar los ajustes del sitio", False),
    ("WFL", 0, "get", "/inbox", "-", "Bandeja de pendientes del usuario actual", False),
    ("NOT", 0, "get", "/notifications", "-", "Notificaciones del usuario actual", False),
    ("NOT", 0, "post", "/notifications/read", "-", "Marcar notificaciones como leídas", False),
    ("NOT", 0, "get", "/notification-preferences", "-", "Preferencias de notificación del usuario", False),
    ("NOT", 0, "put", "/notification-preferences", "-", "Guardar preferencias de notificación", False),
    # NOT-4 · Push web: registrar un dispositivo es aceptar push; quitarlo (o que caduque) es la baja. El aviso no lleva datos.
    ("NOT", 0, "get", "/push/config", "-", "Configuración de push web: available y la clave pública VAPID con que el navegador se suscribe (null si no hay proveedor en este entorno)", False),
    ("NOT", 0, "get", "/push-devices", "-", "Dispositivos push del usuario actual (navegador, etiqueta, último uso)", False),
    ("NOT", 0, "post", "/push-devices", "-", "Registrar la suscripción push de este navegador (subscription.endpoint https, keys.p256dh, keys.auth; label opcional). Reemplaza la anterior del mismo navegador", False),
    ("NOT", 0, "post", "/push-devices/{id}/revoke", "-", "Quitar un dispositivo push propio (baja); 409 si ya estaba quitado", False),
    # ESS-6 · Reingreso rápido con PIN en el teléfono: cookie HttpOnly del dispositivo + PIN con hash; caduca y se olvida tras varios errores.
    ("ESS", 1, "get", "/auth/quick-unlock", None, "Estado del reingreso con PIN de este teléfono (available, display_name, days). Sin cookie vigente responde available=false", True),
    ("ESS", 1, "post", "/auth/quick-unlock/sign-in", None, "Reingresar con el PIN de este teléfono: abre la sesión (conserva el segundo factor solo si la sesión que activó el PIN lo superó); 422 si el PIN no coincide, 401 si el teléfono ya no tiene PIN vigente", True),
    ("ESS", 1, "post", "/auth/quick-unlock/forget", None, "Olvidar el reingreso con PIN de este teléfono (quita la cookie del dispositivo)", True),
    ("ESS", 1, "post", "/me/quick-unlock", "-", "Activar o cambiar el reingreso con PIN en este teléfono (pin y pin_confirmation, 4 a 8 dígitos no triviales); deja la cookie HttpOnly del dispositivo. 422 si la organización lo desactivó", False),
    ("WFL", 0, "post", "/approval-requests/bulk-approve", "workflow.approval_requests.approve", "Aprobar en masa solicitudes de tipos marcados como seguros (RN-WFL-12)", False),
    ("WFL", 0, "get", "/workflows/catalog", "workflow.workflows.view", "Catálogo para armar reglas: eventos disparadores con sus campos, operadores de condición y la lista cerrada de acciones (WFL-5)", False),
    ("WFL", 0, "post", "/visual-flows/{id}/publish", "workflow.workflows.activate", "Publicar el borrador del constructor (WFL-6): exige un diseño válido (sin nodos sueltos ni sin salida), materializa nodos y conexiones en una versión nueva y deja la regla asociada escuchando el disparador; lo que está en curso sigue con su versión (RN-WFL-08)", False),
    ("WFL", 0, "post", "/visual-flows/{id}/test", "workflow.workflows.update", "Modo de prueba del constructor (WFL-6): el recorrido que seguiría un caso de ejemplo sobre el borrador (o el diseño enviado), sin efectos", False),
    ("WFL", 0, "post", "/workflows/{id}/test", "workflow.workflows.update", "Probar una regla en seco con datos de ejemplo: si se cumple y qué haría cada acción, sin efectos (WFL-5)", False),
    ("NOT", 0, "get", "/notification-deliveries", "notifications.deliveries.view", "Registro de entregas por canal externo (correo, SMS, WhatsApp) con estado, propósito, canal de respaldo y error", False),
    ("NOT", 0, "post", "/notification-templates/{id}/request-approval", "notifications.templates.update", "Enviar una plantilla de WhatsApp a aprobación del proveedor (NOT-5); 409 si su estado no lo permite", False),
    ("NOT", 0, "post", "/webhooks/email", None, "Avisos del proveedor de correo (rebotes, quejas, entregas) firmados por el proveedor; un rebote marca la dirección como inválida y avisa a RRHH (NOT-3). 401 sin firma válida", True),
    ("NOT", 0, "get", "/message-channels/email", "notifications.channels.update", "Remitente de correo de la organización (el de la plataforma o uno propio), su verificación y la dirección para los avisos de rebote (NOT-3)", False),
    ("NOT", 0, "put", "/message-channels/email", "notifications.channels.update", "Usar el remitente de la plataforma o uno propio; el propio queda pendiente hasta que el proveedor lo verifique (NOT-3)", False),
    ("NOT", 0, "post", "/message-channels/email/verify", "notifications.channels.update", "Volver a pedir la verificación del remitente propio; 409 si no hay uno pendiente (NOT-3)", False),
    ("NOT", 0, "post", "/notification-deliveries/{id}/clear-bounce", "notifications.channels.update", "Reactivar la dirección de correo que rebotó en esta entrega (ya corregida); 409 si no hay rebote que reactivar (NOT-3)", False),
    ("CFG", 0, "get", "/settings", "settings.parameters.view", "Secciones de parámetros de los módulos contratados (para el centro de configuración y su buscador)", False),
    ("CFG", 0, "get", "/settings/{group}", "settings.parameters.view", "Parámetros de una sección con valor vigente, origen (empresa, organización o por defecto) e historial; ?legal_entity_id= y ?as_of=", False),
    ("CFG", 0, "put", "/settings/{group}", "settings.parameters.update", "Cambiar parámetros desde una fecha (cierra la vigencia anterior); con legal_entity_id sobrescribe solo esa empresa y value null vuelve a heredar", False),
    ("CFG", 0, "get", "/custom-fields/form", "-", "Campos personalizados del formulario de una entidad (?entity=) para quien puede verla; los sensibles solo con su permiso por campo", False),
    ("CFG", 0, "get", "/legal-entities/{id}/logo", "org.legal_entities.view", "Logo de la empresa (imagen PNG, JPEG o WebP); 404 si no tiene", False),
    ("CFG", 0, "post", "/legal-entities/{id}/logo", "org.legal_entities.update", "Subir o reemplazar el logo de la empresa (multipart, campo file; PNG, JPEG o WebP de hasta 1 MB)", False),
    ("CFG", 0, "delete", "/legal-entities/{id}/logo", "org.legal_entities.update", "Quitar el logo de la empresa", False),
    ("CFG", 0, "get", "/exchange-rates/convert", "settings.exchange_rates.view", "Convertir un monto a otra moneda con el tipo de cambio vigente a una fecha: indica el tipo aplicado, su fecha y si es referencial (RN-CFG-07: sin tipo del mismo día se usa el último anterior, o se rechaza si org.tipo_cambio_faltante = block)", False),
    ("CFG", 0, "post", "/exchange-rates/import", "settings.exchange_rates.create", "Traer de la fuente de tipos de cambio (puerto con implementación falsa en desarrollo) los tipos de una fecha y publicarlos con su fuente; por defecto los pares registrados y los de cada empresa con otra moneda. Lo ya publicado se omite: repetirla no duplica", False),
    ("CFG", 0, "get", "/catalogs", "-", "Catálogos maestros y de la organización (CFG-10): código, nombre, si la organización puede extenderlo y cantidad de elementos", False),
    ("CFG", 0, "post", "/catalogs", "settings.catalogs.update", "Crear un catálogo propio de la organización (tipos, motivos, categorías)", False),
    ("CFG", 0, "get", "/catalogs/{code}/items", "-", "Elementos de un catálogo con código estable y código oficial, etiqueta en el idioma del usuario y vigencia; ?as_of= y ?include_archived=1. Une los del catálogo maestro con los propios de la organización", False),
    ("CFG", 0, "post", "/catalogs/{code}/items", "settings.catalogs.update", "Agregar un elemento propio a un catálogo de la organización o a un catálogo maestro extensible (etiquetas por idioma)", False),
    ("CFG", 0, "patch", "/catalog-items/{id}", "settings.catalogs.update", "Cambiar etiquetas u orden de un elemento propio (el código no cambia)", False),
    ("CFG", 0, "post", "/catalog-items/{id}/archive", "settings.catalogs.update", "Archivar un elemento propio: deja de ofrecerse para registros nuevos y no se elimina (RN-CFG-09)", False),
    ("CFG", 0, "post", "/catalog-items/{id}/reactivate", "settings.catalogs.update", "Volver a ofrecer un elemento archivado", False),
    ("CFG", 0, "patch", "/me/preferences", "-", "Preferencias propias (CFG-6): idioma de la interfaz y de las notificaciones (es, en, pt); null vuelve al idioma de la organización", False),
    ("PLT", 0, "get", "/search", "-", "Búsqueda global filtrada por permisos y alcance", False),
    ("PLT", 0, "post", "/files", "-", "Crear un archivo y obtener URL de subida firmada", False),
    ("PLT", 0, "get", "/files/{id}/download-url", "-", "URL de descarga firmada de corta vida (se valida el permiso del recurso dueño)", False),
    ("RPT", 0, "get", "/exports/{id}/download", "reports.exports.view", "Descargar el archivo de una exportación propia (CSV, Excel o PDF); cuenta la descarga y la registra. Una exportación en cola o fallida responde 409 (UI-11)", False),
    # UI-12 · El tablero de tareas mueve una tarjeta de «Pendiente» a «En curso» con esta acción.
    ("WFL", 0, "post", "/tasks/{id}/start", "workflow.tasks.complete", "Empezar una tarea pendiente (pasa a en curso); la usa el tablero kanban de tareas al arrastrar la tarjeta", False),
    ("AUD", 0, "get", "/trash", "audit.trash.view", "Papelera: registros eliminados que se pueden restaurar", False),
    ("AUD", 0, "post", "/trash/{id}/restore", "audit.trash.restore", "Restaurar un registro de la papelera", False),
    ("AUD", 0, "get", "/audit-archives/{id}/entries", "audit.logs.view", "Entradas de un mes de auditoría archivado en frío, consultables hasta el fin del plazo de conservación (los campos sensibles se enmascaran sin su permiso)", False),
    ("AUD", 0, "get", "/audit-retention", "audit.logs.view", "Políticas de conservación por separado: auditoría (años y meses en línea), actividad de usuarios (días) y logs técnicos (días)", False),
    ("ORG", 1, "get", "/org-chart", "people.directory.view", "Organigrama a una fecha (ORG-6): view=units dibuja el árbol de unidades con su responsable y dotación; view=people, la línea de reporte (jefe directo por asignación o por posición jefe). Solo nombre, puesto, unidad, sede y el contacto laboral que la organización publica", False),
    ("ORG", 1, "get", "/job-profiles/{id}/versions", "org.job_profiles.view", "Versiones de la descripción del puesto (misión, funciones y requisitos), de la más reciente a la más antigua; inalterables (ORG-5)", False),
    ("ORG", 1, "get", "/legal-entities/{id}/regimes", "org.legal_entities.view", "Historia del régimen laboral de la empresa con su vigencia (valid_from / valid_to), de la más reciente a la más antigua (ORG-1)", False),
    ("POS", 1, "get", "/headcount", "org.positions.view", "Resumen de dotación: aprobadas, ocupadas, vacantes, congeladas. Con report_currency (y org.positions.budget.view) agrega totals.consolidated: los costos de todas las monedas convertidos a esa moneda con el tipo de cambio de la fecha, indicando cada tipo aplicado (CFG-7)", False),
    ("POS", 1, "get", "/positions/org-chart", "org.positions.view", "Organigrama por posiciones a una fecha (POS-7): cada plaza con su estado (ocupada, vacante, en reclutamiento, congelada), ocupantes y, por rama, plazas ocupadas sobre aprobadas; el costo proyectado de la rama solo con org.positions.budget.view", False),
    ("ORG", 1, "get", "/directory", "people.directory.view", "Directorio de personas (ORG-7): búsqueda por nombre, puesto, unidad y sede; solo nombre, puesto, unidad, sede y el contacto laboral publicado por la organización (RN-ORG-08), nunca datos personales ni sensibles", False),
    ("ORG", 1, "get", "/directory/{employment_id}", "people.directory.view", "Tarjeta de contacto (ORG-7) con su equipo: jefe directo, compañeros con el mismo jefe y reportes directos", False),
    ("HIS", 1, "get", "/employments/{employment_id}/timeline", "people.employments.view", "Línea de tiempo de la relación laboral", False),
    # HIS-2 · HIS-4 · Movimientos de personal: única vía para cambiar la asignación (aprobación, programación, corrección).
    ("HIS", 1, "get", "/employments/{employment_id}/personnel-actions", "people.assignments.view", "Movimientos de personal de una relación laboral (aplicados, programados, en aprobación, rechazados, anulados y reemplazados) con el antes y el después", False),
    ("HIS", 1, "post", "/employments/{employment_id}/personnel-actions", "people.assignments.create", "Registrar un movimiento (tipo, fecha efectiva, cambios de la asignación y motivo). Con fecha futura queda programado (RN-HIS-04); los tipos de movimientos.tipos_con_aprobacion pasan por el flujo «personnel_action»; con fecha en un periodo de planilla cerrado exige people.assignments.correct (RN-HIS-05)", False),
    ("HIS", 1, "post", "/employments/{employment_id}/personnel-actions/propose", "people.assignments.propose", "Un jefe propone un movimiento para su equipo (mismos datos que el registro): siempre pasa por el flujo «personnel_action» antes de aplicarse o programarse", False),
    ("HIS", 1, "get", "/personnel-actions", "people.assignments.view", "Movimientos de la organización dentro del alcance; por defecto los programados y en aprobación (Movimientos programados)", False),
    ("HIS", 1, "get", "/personnel-actions/{id}", "people.assignments.view", "Ver un movimiento de personal con su aprobación y su antes y después", False),
    ("HIS", 1, "patch", "/personnel-actions/{id}", "people.assignments.create", "Editar un movimiento programado antes de su fecha (fecha futura, cambios, motivo)", False),
    ("HIS", 1, "post", "/personnel-actions/{id}/cancel", "people.assignments.cancel", "Anular un movimiento programado o en aprobación antes de aplicarse (motivo obligatorio)", False),
    ("HIS", 1, "post", "/personnel-actions/{id}/correct", "people.assignments.correct", "Corregir un movimiento aplicado con los valores correctos y el motivo: crea el movimiento de corrección y el original queda reemplazado (RN-HIS-06); con fecha pasada emite employment.retroactive_change con el rango afectado", False),
    ("ESS", 1, "get", "/me/employment", "-", "Mi perfil (ESS-2): ficha propia del colaborador con datos laborales vigentes, datos personales propios (cada lectura en el registro de accesos), contactos de emergencia, solicitudes de cambio, campos que puede proponer cambiar (colaboradores.campos_editables_portal), los que esperan a RRHH y los que tienen un cambio pendiente. Solo la relación propia (RN-ESS-01)", False),
    ("ESS", 1, "get", "/me/home", "-", "Inicio del colaborador (ESS-1): relaciones propias y la elegida (RN-ESS-04), pendientes de la bandeja y tarjetas de los módulos contratados (RN-ESS-02): perfil, vacaciones, ausencias. Solo datos propios (RN-ESS-01)", False),
    ("COL", 1, "get", "/home", "-", "Vistas de inicio del usuario según su rol (RRHH, manager o colaborador) y la vista por defecto", False),
    ("COL", 1, "get", "/home/hr", "people.employments.view", "Tablero de inicio de RRHH: dotación, ingresos y ceses del mes, vencimientos y pendientes (solo tarjetas de módulos contratados)", False),
    ("MSS", 1, "get", "/home/manager", "people.team.view", "Tablero de inicio del manager: aprobaciones, ausencias y vencimientos de su equipo", False),
    ("MSS", 1, "get", "/my-team", "people.team.view", "Equipo directo e indirecto del manager", False),
    ("MSS", 1, "get", "/my-team/key-dates", "people.team.view", "Fechas clave del equipo (MSS-4): cumpleaños (solo el día; ocultables con celebraciones.mostrar_cumpleanos), aniversarios de ingreso, fin del periodo de prueba y fin de contrato (con contracts.contracts.view) de quienes reportan al usuario (RN-MSS-01). Rango de hasta 90 días; leer cumpleaños queda en el registro de accesos", False),
    ("MSS", 1, "get", "/my-team/calendar", "people.team.view", "Calendario de ausencias del equipo", False),
    ("IMP", 1, "get", "/import-types", "imports.imports.view", "Tipos de datos que el usuario puede importar: columnas con obligatoriedad, ejemplo y si son sensibles, para armar la plantilla (IMP-1)", False),
    ("IMP", 1, "get", "/imports/{id}/rows", "imports.imports.view", "Vista previa de una importación: filas con su estado, datos interpretados, errores y advertencias; ?filter[status]=valid|invalid|imported|skipped|failed", False),
    ("IMP", 1, "get", "/imports/{id}/errors/download", "imports.imports.view", "Descargar en CSV las filas con error de una importación, con el motivo por fila", False),
    # IMP-3 · Primeros pasos: cada paso se da por hecho cuando su dato existe; se salta y se retoma por acciones.
    ("IMP", 1, "get", "/setup-checklist", "imports.setup.use", "Asistente de primeros pasos (IMP-3): pasos de los módulos contratados con su estado (pending, done, skipped), la pantalla real a la que llevan, el porcentaje de avance y si el asistente se muestra (visible) u ocultó la organización (dismissed)", False),
    ("IMP", 1, "post", "/setup-checklist/dismiss", "imports.setup.use", "Ocultar (hidden=true) o volver a mostrar (hidden=false) el asistente de primeros pasos; queda en el parámetro puesta_en_marcha.omitida con vigencia y auditoría", False),
    ("IMP", 1, "post", "/setup-checklist/{code}/skip", "imports.setup.use", "Saltar un paso pendiente del asistente (un paso hecho no se salta: 409)", False),
    ("IMP", 1, "post", "/setup-checklist/{code}/reopen", "imports.setup.use", "Retomar un paso saltado del asistente", False),
    # IMP-4 · Plantillas peruanas listas para usar: paquete versionado que declara qué crea; aplicar es idempotente.
    ("IMP", 1, "get", "/setup/country-packs", "imports.setup.use", "Paquetes de configuración inicial del país de la organización (IMP-4): versión, partes con lo que crea cada una (items), cuánto ya existe (present), la pantalla donde se edita y la versión instalada", False),
    ("IMP", 1, "post", "/setup/country-packs/{code}/apply", "imports.setup.use", "Aplicar el paquete del país: crea solo lo que falta (tipos de contrato y de ausencia, feriados, plantillas, políticas de vacaciones, motivos de cese), no pisa lo editado y registra la versión instalada; responde lo creado por parte", False),
    # IMP-7 · Perfiles de origen: exportaciones de otros sistemas traducidas a los importadores de Core HR (versionados).
    ("IMP", 1, "get", "/import-profiles", "imports.imports.view", "Perfiles de origen vigentes (IMP-7): por tipo de datos, cabeceras que reconoce cada columna, equivalencias de códigos, valores por defecto, versión, si es del paquete del país o de la organización, si está «por validar» y su historia de versiones; ?filter[entity]=&filter[code]=", False),
    ("IMP", 1, "post", "/import-profiles", "imports.imports.map", "Agregar o corregir un perfil de origen como configuración: guarda una versión nueva (code, entity, name, columns, value_maps, defaults) sin tocar las anteriores; luego se usa con profile_code al mapear", False),
    ("IMP", 1, "get", "/imports/{id}/file", "imports.imports.view", "Descargar el archivo original de una importación (IMP-6); la descarga queda registrada", False),
    ("AYU", 1, "get", "/help/articles", "help.articles.view", "Artículos de ayuda visibles para el usuario (producto y organización), filtrados por rol, módulo y pantalla", False),
    ("AYU", 1, "get", "/help/articles/{id}", "help.articles.view", "Detalle de un artículo de ayuda con su versión, en el idioma del usuario o en español con translation_missing (RN-AYU-07); 404 si el usuario no puede verlo (RN-AYU-01)", False),
    ("AYU", 1, "post", "/help/articles/{id}/feedback", "help.articles.view", "Valorar un artículo («¿te sirvió?»)", False),
    ("AYU", 1, "get", "/help/search", "help.articles.view", "Búsqueda de texto completo en la ayuda visible para el usuario", False),
    ("AYU", 1, "get", "/help/screens/{screen_key}", "help.articles.view", "Artículos de una pantalla (primero su ayuda propia, después las guías que la incluyen) y sus ayudas de campo por clave (fields); 404 si la pantalla no existe o el usuario no puede abrirla", False),
    ("AYU", 1, "get", "/help/whats-new", "help.articles.view", "Novedades de versión de los módulos contratados", False),
    ("AYU", 1, "get", "/help/tours", "help.articles.view", "Recorridos guiados del usuario (uno por rol de sistema y módulo contratado) con su estado: pending, completed o skipped (AYU-7)", False),
    ("AYU", 1, "post", "/help/tours/{tour_key}/complete", "help.articles.view", "Marcar un recorrido guiado como visto o saltado", False),
    ("AYU", 1, "get", "/help/escalations", "help.escalations.view", "Consultas derivadas desde la ayuda y su estado", False),
    ("AYU", 1, "post", "/help/escalations", "help.escalations.create", "Derivar una consulta a RRHH (o a soporte de GestiaHR, solo el administrador) con la pregunta y los artículos consultados", False),
    ("AYU", 1, "get", "/help/insights", "help.insights.view", "Consultas, búsquedas sin resultado y valoración de la ayuda en la organización", False),
    ("AYU", 1, "get", "/platform/help-escalations", "platform.help_articles.view", "Consultas de soporte enviadas por administradores de las organizaciones", False),
    ("AYU", 1, "get", "/platform/help-insights", "platform.help_articles.view", "Búsquedas sin resultado y valoración de la ayuda del producto en todas las organizaciones", False),
    ("ASI", 2, "post", "/time-punches/batch", "time.punches.create", "Sincronizar marcaciones sin conexión o de un dispositivo (idempotente por client_id)", False),
    ("ASI", 2, "post", "/attendance-periods/{period}/close", "time.attendance_days.close", "Cerrar la asistencia de un periodo para planilla", False),
    ("TUR", 2, "post", "/shift-assignments/bulk", "time.shift_assignments.create", "Asignación masiva de turnos", False),
    ("CFG", 0, "get", "/calendar", "time.calendar.view", "Calendario central de RRHH (CFG-8): elementos entre from y to de las fuentes de los módulos contratados que el usuario puede ver (feriados, tareas y las que sumen los módulos), cada uno con el enlace a su registro", False),
    ("PER", 3, "get", "/payroll-periods/{id}/variance", "payroll.periods.view", "Comparación contra el periodo anterior y anomalías explicadas", False),
    ("BOL", 3, "get", "/me/payslips", "-", "Boletas propias", False),
    ("LIQ", 3, "post", "/settlements/simulate", "payroll.settlements.simulate", "Simular una liquidación sin guardarla", False),
    ("DEC", 3, "post", "/declaration-files/preview", "payroll.declaration_files.create", "Validación previa y borrador de PLAME, AFPnet o T-Registro sin guardar", False),
    ("DEC", 3, "post", "/fifth-category/simulate", "payroll.fifth_category_ledger.view", "Simular la retención de quinta categoría de una relación y mes", False),
    ("DEC", 3, "post", "/employments/{employment_id}/registry-validation", "payroll.registry_profiles.view", "Validación previa al alta en el registro laboral (comprobaciones TR)", False),
    ("SST", 7, "post", "/sst/elections/{id}/votes", "sst.elections.vote", "Emitir el voto en una elección del comité de SST (secreto)", False),
    ("ATS", 4, "get", "/public/jobs", None, "Portal de empleos: vacantes publicadas", True),
    ("ATS", 4, "get", "/public/jobs/{slug}", None, "Portal de empleos: detalle de una vacante", True),
    ("ATS", 4, "post", "/public/jobs/{slug}/applications", None, "Postular a una vacante (con consentimiento)", True),
    ("CMP", 4, "get", "/employments/{employment_id}/skills", "performance.skills.view", "Habilidades y brechas de un colaborador", False),
    ("CMP", 4, "put", "/employments/{employment_id}/skills", "performance.skills.update", "Actualizar habilidades de un colaborador", False),
    ("CAR", 5, "get", "/talent-reviews/{id}/nine-box", "careers.talent_reviews.view", "Matriz 9-box de una sesión de talento", False),
    ("COM", 5, "get", "/pay-equity/report", "compensation.pay_equity.view", "Brecha salarial por categoría y sexo", False),
    ("ENC", 6, "post", "/surveys/{id}/responses", "-", "Responder una encuesta (si es anónima no se guarda el vínculo con la persona)", False),
    ("ENC", 6, "get", "/surveys/{id}/results", "engagement.surveys.results", "Resultados agregados respetando el umbral mínimo por segmento", False),
    ("DEN", 7, "post", "/public/grievances", None, "Registrar una denuncia (identificada o anónima)", True),
    ("DEN", 7, "get", "/public/grievances/{tracking_code}", None, "Consultar el estado de una denuncia con su código", True),
    ("DEN", 7, "post", "/public/grievances/{tracking_code}/messages", None, "Añadir información a una denuncia con su código", True),
    ("CUM", 7, "get", "/compliance/board", "compliance.obligations.view", "Tablero de cumplimiento con semáforo por empresa", False),
    ("CUM", 7, "post", "/compliance/ledger/verify", "compliance.ledger.verify", "Verificar la integridad de una cadena del libro de cumplimiento", False),
    ("CUM", 7, "post", "/compliance/mailbox-checks", "compliance.notices.update", "Registrar la revisión diaria de la casilla electrónica", False),
    ("PRI", 7, "post", "/privacy/people/{person_id}/anonymize", "privacy.people.anonymize", "Anonimizar a una persona según la política de retención", False),
    ("ANA", 8, "get", "/analytics/metrics", "analytics.metrics.view", "Catálogo de indicadores disponibles", False),
    ("ANA", 8, "post", "/analytics/query", "analytics.metrics.view", "Consultar indicadores con dimensiones y filtros", False),
    ("ALE", 8, "get", "/analytics/turnover-risk", "analytics.turnover_risk.view", "Riesgo de rotación con factores explicados", False),
    ("IAR", 8, "post", "/ai/help-assistant/ask", "ai.help_assistant.use", "Preguntar al asistente de ayuda; responde solo con artículos visibles para el usuario y los cita (404 si el asistente no está habilitado para el usuario)", False),
    ("IAR", 8, "post", "/ai/help-assistant/answers/{id}/feedback", "ai.help_assistant.use", "Valorar una respuesta del asistente de ayuda", False),
    ("IAR", 8, "get", "/ai/help-assistant/availability", "-", "Si el asistente de ayuda está disponible para el usuario actual (plan, organización y rol)", False),
    ("WHA", 9, "post", "/channels/whatsapp/webhook", None, "Entrada de mensajes del proveedor de WhatsApp (firma verificada)", True),
    ("WHA", 9, "get", "/channels/whatsapp/settings", "integrations.whatsapp.view", "Configuración del canal WhatsApp", False),
    ("WHA", 9, "put", "/channels/whatsapp/settings", "integrations.whatsapp.update", "Guardar configuración del canal WhatsApp", False),
    ("INT", 9, "get", "/integrations/health", "integrations.connections.view", "Salud de todos los conectores", False),
    ("INT", 9, "post", "/integrations/biometric/{device_id}/punches", "integrations.biometric.push", "Entrada de marcaciones desde un reloj biométrico", False),
    ("ORG", 1, "post", "/cost-centers/{id}/close", "org.cost_centers.update", "Cerrar un centro de costo desde una fecha (closed_on, último día que admite imputaciones); cerrado no admite nuevas asignaciones ni distribuciones", False),
    ("ORG", 1, "post", "/cost-centers/{id}/reopen", "org.cost_centers.update", "Reabrir un centro de costo cerrado", False),
    ("ORG", 1, "get", "/employments/{employment_id}/cost-distributions", "people.assignments.view", "Historia de la distribución de una relación laboral entre centros de costo (de la más reciente a la más antigua); con as_of devuelve solo la vigente a esa fecha", False),
    ("ORG", 1, "post", "/employments/{employment_id}/cost-distributions", "people.assignments.create", "Registrar una distribución entre centros de costo desde valid_from (cierra la vigente el día anterior); las líneas suman 100 % (RN-ORG-06)", False),
    ("ORG", 1, "get", "/org-units/{id}/history", "org.units.view", "Historia de la estructura de una unidad (superior, nombre, nivel, responsable y centro de costo por vigencia, incluidos cambios programados)", False),
    ("ORG", 1, "get", "/org-units/{id}/impact", "org.units.view", "Impacto de mover o archivar una unidad: subunidades, posiciones activas y personas de la rama", False),
    ("POS", 1, "post", "/positions/{id}/reject", "org.positions.approve", "Rechazar una posición propuesta con motivo; pasa a cerrada (ME-03)", False),
    ("POS", 1, "post", "/positions/{id}/open-recruitment", "org.positions.update", "Abrir reclutamiento sobre una posición aprobada o vacante; no procede si está congelada o cerrada (RN-POS-03)", False),
    ("POS", 1, "post", "/positions/{id}/cancel-recruitment", "org.positions.update", "Cancelar el reclutamiento sin contratación; la posición vuelve a vacante", False),
    ("POS", 1, "get", "/positions/{id}/occupants", "org.positions.view", "Historia de ocupantes de la posición (asignaciones con vigencia), del más reciente al más antiguo; con as_of solo los vigentes a ese día", False),
    ("POS", 1, "get", "/positions/{id}/status-events", "org.positions.view", "Historial inalterable de estados de la posición con acción, motivo, autor y fecha efectiva (POS-2)", False),
    ("POS", 1, "get", "/positions/{id}/reporting-line", "org.positions.view", "Línea de reporte derivada de la posición jefe (o del responsable de la unidad), de la más cercana a la más alta, con su ocupante", False),
    ("POS", 1, "get", "/position-options", "org.positions.view", "Opciones para el formulario de posiciones: puestos, empresas, unidades, sedes, centros de costo y posiciones jefe activos", False),
    # POS-3 · Solicitudes de posición (ME-04): se aprueban o rechazan en el centro de aprobaciones (motor WFL).
    ("POS", 1, "get", "/position-requests", "org.position_requests.view", "Solicitudes de posición: las propias; con org.positions.approve, todas las del alcance. El costo estimado (estimated_cost) solo viaja con org.positions.budget.view o al solicitante", False),
    ("POS", 1, "post", "/position-requests", "org.position_requests.create", "Registrar una solicitud de posición en borrador (puesto, unidad, motivo, cantidad, fecha y costo estimado)", False),
    ("POS", 1, "get", "/position-requests/{id}", "org.position_requests.view", "Ver una solicitud de posición con su aprobación, el presupuesto disponible de la unidad y las posiciones que creó", False),
    ("POS", 1, "patch", "/position-requests/{id}", "org.position_requests.create", "Editar una solicitud en borrador (solo el solicitante)", False),
    ("POS", 1, "post", "/position-requests/{id}/submit", "org.position_requests.create", "Enviar la solicitud al flujo de aprobación configurado; si supera el presupuesto disponible de la unidad se marca over_budget y el flujo puede exigir un nivel adicional (RN-POS-05)", False),
    ("POS", 1, "post", "/position-requests/{id}/cancel", "org.position_requests.create", "Cancelar una solicitud en borrador o en aprobación (anula los pasos pendientes)", False),
    # POS-4 · Presupuesto de dotación y desviaciones.
    ("POS", 1, "get", "/headcount-budgets", "org.headcount_plans.view", "Presupuestos de dotación por unidad y periodo (plazas y costo mensual; el costo solo con org.positions.budget.view)", False),
    ("POS", 1, "post", "/headcount-budgets", "org.headcount_plans.create", "Registrar el presupuesto de una unidad desde una fecha; cierra el presupuesto vigente de la unidad", False),
    ("POS", 1, "get", "/headcount-budgets/{id}", "org.headcount_plans.view", "Ver un presupuesto de dotación", False),
    ("POS", 1, "patch", "/headcount-budgets/{id}", "org.headcount_plans.update", "Corregir un presupuesto de dotación (plazas, costo, notas)", False),
    ("POS", 1, "get", "/headcount-scenarios/compare", "org.headcount_plans.view", "Comparar escenarios lado a lado (ids=a,b,...): plazas, ocupadas, vacantes, congeladas, agregadas y quitadas por escenario y por unidad; el costo proyectado solo con org.positions.budget.view", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/lines", "org.headcount_plans.update", "Agregar plazas nuevas al escenario en borrador (org_unit_id, job_profile_id, site_id, planned_count, planned_start_on, title, projected_cost)", False),
    ("POS", 1, "patch", "/headcount-scenario-lines/{id}", "org.headcount_plans.update", "Cambiar una línea del escenario en borrador: change (baseline, freeze o remove en las posiciones reales; add o remove en las nuevas), planned_count, planned_start_on, projected_cost, notes", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/submit", "org.headcount_plans.submit", "Enviar el escenario a revisión", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/approve", "org.headcount_plans.approve", "Aprobar el escenario (no cambia posiciones reales)", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/return", "org.headcount_plans.approve", "Devolver el escenario a borrador (reason)", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/apply", "org.headcount_plans.approve", "Aplicar el escenario aprobado (RN-POS-08): crea solicitudes de posición en borrador por lo agregado y congela o cierra las posiciones marcadas; lo que no se pudo aplicar queda en applied_summary con su motivo", False),
    ("POS", 1, "post", "/headcount-scenarios/{id}/archive", "org.headcount_plans.close", "Archivar el escenario", False),
    ("POS", 1, "get", "/headcount/positions", "org.positions.view", "Desviación explicada por posición de una unidad: base del costo (sueldo del ocupante, punto medio de la banda o costo presupuestado), cargas y si entra en el presupuesto; los montos solo con org.positions.budget.view", False),
    ("ORG", 1, "get", "/org-unit-options", "org.units.view", "Opciones para el formulario de unidades: niveles, posiciones que pueden ser responsables y centros de costo activos", False),
    ("ORG", 1, "get", "/org-unit-types", "org.units.view", "Niveles de unidad con los nombres de la organización (gerencia, área, departamento…), en orden", False),
    ("ORG", 1, "post", "/org-unit-types", "org.units.update", "Agregar un nivel de unidad propio", False),
    ("ORG", 1, "patch", "/org-unit-types/{id}", "org.units.update", "Renombrar o reordenar un nivel de unidad", False),
    ("AUS", 2, "get", "/absence-types/{id}/rules", "time.absence_types.view", "Historia de las reglas de un tipo de ausencia (vigencias de la más reciente a la más antigua)", False),
    # VAC-1 · Políticas de vacaciones: crear y cambiar con vigencia, archivar y restaurar, historia de reglas y límites legales.
    ("VAC", 2, "post", "/vacation-policies", "time.vacation_policies.update", "Crear una política de vacaciones (alcance por empresa, régimen y categoría; reglas desde effective_from). Días por año vacíos = los del régimen en los parámetros legales; una regla menor que la ley verificada responde 422 y lo «por validar» vuelve en meta.warnings", False),
    ("VAC", 2, "patch", "/vacation-policies/{id}", "time.vacation_policies.update", "Cambiar nombre o alcance y registrar reglas nuevas desde effective_from: se cierra la regla vigente y se inserta otra (nunca se sobrescribe la historia)", False),
    ("VAC", 2, "post", "/vacation-policies/{id}/archive", "time.vacation_policies.update", "Archivar la política: deja de aplicarse en los cálculos nuevos; los periodos ya ganados no cambian", False),
    ("VAC", 2, "post", "/vacation-policies/{id}/restore", "time.vacation_policies.update", "Restaurar una política archivada", False),
    ("VAC", 2, "get", "/vacation-policies/{id}/rules", "time.vacation_policies.view", "Historia de las reglas de la política (vigencias de la más reciente a la más antigua)", False),
    ("VAC", 2, "get", "/vacation-legal-limits", "time.vacation_policies.view", "Límites legales del régimen a una fecha (días por año, plazo de goce, récord, venta, fraccionamiento y adelanto), cada uno con su estado de validación", False),
    # VAC-2 · Movimientos que explican el saldo (libro de solo inserción).
    ("VAC", 2, "get", "/vacation-balances/{id}/ledger", "time.vacation_balances.view", "Movimientos del libro de vacaciones de la relación (devengo, reservas, goce, ajustes, liquidación), del más reciente al más antiguo; el saldo es su suma", False),
    # AUS-2 · Ausencias: simulación, registro directo de RRHH y sustentos.
    ("VAC", 2, "post", "/vacation-requests/simulate", "time.vacation_requests.create", "Simular una solicitud de vacaciones sin registrarla: días que descuenta según la política (calendario o hábiles, sin descanso semanal ni feriados de la sede), saldo disponible y adelanto, problemas que impedirían enviarla (saldo, anticipación, fraccionamiento, solapes, planilla cerrada) y avisos (adelanto, cobertura del equipo, reglas por validar)", False),
    ("VAC", 2, "post", "/vacation-requests/{id}/register", "time.vacation_requests.validate", "RRHH registra la solicitud directamente aprobada, sin flujo: reserva los días en el libro (RN-VAC-03); admite excepción de anticipación o fraccionamiento con motivo y periodos de planilla cerrados como ajuste", False),
    ("VAC", 2, "get", "/vacation-requests/{id}/overlaps", "time.vacation_requests.view", "Cruces de la solicitud con su unidad antes de aprobar (VAC-4): personas ausentes en esas fechas (con el alcance de quien consulta) y tramos en que se supera el máximo de ausentes simultáneos", False),
    ("VAC", 2, "get", "/team-calendar", "time.vacation_requests.view", "Calendario de ausencias del equipo (VAC-4): vacaciones y ausencias aprobadas y en trámite por persona y día, con el alcance del usuario; en alcance propio, solo los compañeros de su unidad y lo aprobado, sin tipo ni motivo. Incluye ausentes por día y alertas de cobertura por unidad (vacaciones.maximo_ausentes_simultaneos)", False),
    ("AUS", 2, "post", "/absences/simulate", "time.absences.create", "Simular una ausencia sin registrarla: días calendario y hábiles (sin descanso semanal ni feriados de la sede), horas, reglas del tipo vigentes, problemas que impedirían enviarla y avisos", False),
    ("AUS", 2, "post", "/absences/{id}/register", "time.absences.create_for_others", "RRHH registra la ausencia directamente, sin flujo: queda aprobada (o pendiente de sustento por el plazo del tipo, RN-AUS-01); admite excepción al tope con motivo (RN-AUS-07) y periodos de planilla cerrados como ajuste (RN-AUS-08)", False),
    ("AUS", 2, "post", "/absences/{id}/attachments", "time.absences.update", "Adjuntar un sustento (multipart: file, label; PDF, PNG o JPEG). Si la ausencia esperaba el documento, la enviada por el titular pasa a aprobación y la registrada por RRHH queda aprobada", False),
    ("AUS", 2, "get", "/absences/{id}/attachments/{attachment_id}", "time.absences.view", "Descargar un sustento (en tipos confidenciales exige time.absences.medical_certificate.view o ser el titular; se registra la descarga)", False),
    ("AUS", 2, "get", "/absences/{id}/medical-certificate", "time.absences.medical_certificate.view", "AUS-3 · Certificado médico del descanso (CITT o particular): tipo, número y diagnóstico (sensibles, x-sensitive-fields de AbsenceMedicalCertificate: certificate_number, diagnosis; la lectura queda en data_access_logs), emisor, fecha, días otorgados frente a los días del descanso y origen laboral", False),
    ("AUS", 2, "put", "/absences/{id}/medical-certificate", "time.absences.create_for_others", "AUS-3 · Registrar o corregir el certificado médico (kind citt|private, certificate_number, issuer, diagnosis, issued_on, days_granted, is_work_related, validated). Exige además time.absences.medical_certificate.view (403); 422 not_medical_absence si el tipo no genera subsidio. Número y diagnóstico se guardan cifrados y no van a la auditoría", False),
    ("AUS", 2, "get", "/employments/{employment_id}/medical-leave-summary", "time.absences.manage_subsidy", "AUS-3 · Acumulado anual de descansos médicos del trabajador: umbral a cargo del empleador (parámetro descanso_medico_dias_cargo_empleador, «por validar» si no tiene valor), días acumulados, del empleador y de subsidio, si se alcanzó el umbral y cada descanso con su expediente", False),
    # AUS-5 · AUS-6 · Resumen de ausencias del periodo (incidencias para planilla) con cada día explicado y los días remotos.
    ("AUS", 2, "get", "/employments/{employment_id}/absence-summary", "time.absences.view", "AUS-6 · Resumen de ausencias del trabajador en el periodo (hasta 93 días): cada día explicado (fuera de la relación, descanso, feriado, laborado, laborado en remoto por acuerdo o por trabajo remoto aprobado, ausencia con su efecto: pagada, sin goce, a cargo del empleador o subsidio; permisos por horas), totales por tipo con su concepto de planilla, incidencias importadas, huella reproducible y, si el periodo se cerró, las diferencias contra el último cierre. Un tipo confidencial se muestra genérico sin permiso de datos de salud", False),
    ("AUS", 2, "post", "/absence-summaries/close", "time.absences.create_for_others", "AUS-6 · Cerrar (congelar) el resumen de ausencias de un periodo para las relaciones laborales del alcance (opcional: empresa o lista de relaciones); volver a cerrar sin cambios no agrega nada y un cambio posterior se informa como diferencia", False),
    ("AUS", 2, "get", "/absence-subsidies", "time.absences.manage_subsidy", "AUS-3 · Expedientes de subsidio (reembolso ante la seguridad social) en el alcance: estado, días de subsidio, plazo de presentación (parámetro subsidio_reembolso_plazo_meses), montos {amount, currency} y acciones permitidas", False),
    ("AUS", 2, "get", "/absence-subsidies/{id}", "time.absences.manage_subsidy", "AUS-3 · Ver un expediente de subsidio", False),
    ("AUS", 2, "post", "/absence-subsidies/{id}/file", "time.absences.manage_subsidy", "AUS-3 · Presentar el expediente (filed_on, filing_reference, amount_requested {amount, currency}); también vuelve a presentar uno observado. 409 invalid_transition fuera de por presentar u observado", False),
    ("AUS", 2, "post", "/absence-subsidies/{id}/observe", "time.absences.manage_subsidy", "AUS-3 · Registrar la observación de la seguridad social (reason)", False),
    ("AUS", 2, "post", "/absence-subsidies/{id}/approve", "time.absences.manage_subsidy", "AUS-3 · Registrar la aprobación del subsidio (amount_approved {amount, currency})", False),
    ("AUS", 2, "post", "/absence-subsidies/{id}/reject", "time.absences.manage_subsidy", "AUS-3 · Registrar el rechazo del subsidio (reason)", False),
    ("AUS", 2, "post", "/absence-subsidies/{id}/reimburse", "time.absences.manage_subsidy", "AUS-3 · Registrar el reembolso recibido (reimbursed_on, amount_reimbursed {amount, currency})", False),
    ("WLB", 9, "get", "/branding", "settings.branding.view", "Marca de la organización", False),
    ("WLB", 9, "put", "/branding", "settings.branding.update", "Guardar marca de la organización", False),
    ("API", 9, "get", "/events", "integrations.events.view", "Catálogo de eventos de dominio publicables por webhook", False),
    ("API", 9, "post", "/mcp", "ai.mcp.use", "Punto de entrada del servidor MCP (JSON-RPC sobre HTTP)", False),
]

TYPES = {
    "s": {"type": "string"}, "i": {"type": "integer"}, "n": {"type": "number"},
    "b": {"type": "boolean"}, "d": {"type": "string", "format": "date"},
    "dt": {"type": "string", "format": "date-time"}, "id": {"type": "string", "description": "Identificador opaco"},
    "j": {"type": "object", "additionalProperties": True},
    "a": {"type": "array", "items": {"type": "string"}},
    "ao": {"type": "array", "items": {"type": "object", "additionalProperties": True}},
}
# Operaciones de EXTRA que crean un registro: responden 201 en lugar de 200.
# Rutas extra que son transiciones de estado (responden 409 invalid_transition).
STATE_ACTIONS = {('delete', '/documents/{id}'), ('patch', '/headcount-budgets/{id}'), ('patch', '/personnel-actions/{id}'), ('post', '/absence-subsidies/{id}/approve'), ('post', '/absence-subsidies/{id}/file'), ('post', '/absence-subsidies/{id}/observe'), ('post', '/absence-subsidies/{id}/reimburse'), ('post', '/absence-subsidies/{id}/reject'), ('post', '/absences/{id}/attachments'), ('post', '/absences/{id}/register'), ('post', '/catalogs'), ('post', '/catalogs/{code}/items'), ('post', '/certificate-requests/{id}/issue'), ('post', '/certificate-requests/{id}/reject'), ('post', '/contract-addenda/{id}/cancel'), ('post', '/contract-addenda/{id}/issue'), ('post', '/contract-addenda/{id}/register-signature'), ('post', '/contract-renewal-batches/{id}/cancel'), ('post', '/contract-renewal-batches/{id}/send'), ('post', '/contracts/{id}/approve'), ('post', '/contracts/{id}/decline-signature'), ('post', '/contracts/{id}/register-signature'), ('post', '/contracts/{id}/renew'), ('post', '/contracts/{id}/return'), ('post', '/contracts/{id}/submit'), ('post', '/contracts/{id}/terminate'), ('post', '/contracts/{id}/void'), ('post', '/cost-centers/{id}/close'), ('post', '/cost-centers/{id}/reopen'), ('post', '/document-categories/{id}/archive'), ('post', '/document-categories/{id}/restore'), ('post', '/document-requests/{id}/cancel'), ('post', '/document-requests/{id}/submit'), ('post', '/document-templates/{id}/generate'), ('post', '/documents/{id}/approve'), ('post', '/documents/{id}/reject'), ('post', '/employments/{employment_id}/bank-accounts'), ('post', '/employments/{employment_id}/bank-accounts/{id}/approve'), ('post', '/employments/{employment_id}/bank-accounts/{id}/cancel'), ('post', '/employments/{employment_id}/bank-accounts/{id}/reject'), ('post', '/employments/{employment_id}/cost-distributions'), ('post', '/employments/{employment_id}/dependents/{id}/deregister'), ('post', '/employments/{employment_id}/health-regimes'), ('post', '/employments/{employment_id}/insurance-coverages'), ('post', '/employments/{employment_id}/insurance-coverages/{id}/end'), ('post', '/employments/{employment_id}/life-insurance-declarations'), ('post', '/employments/{employment_id}/pension-affiliations'), ('post', '/employments/{employment_id}/personnel-actions'), ('post', '/employments/{employment_id}/personnel-actions/propose'), ('post', '/employments/{id}/cancel'), ('post', '/headcount-budgets'), ('post', '/headcount-scenarios/{id}/apply'), ('post', '/headcount-scenarios/{id}/approve'), ('post', '/headcount-scenarios/{id}/archive'), ('post', '/headcount-scenarios/{id}/return'), ('post', '/headcount-scenarios/{id}/submit'), ('post', '/health-providers'), ('post', '/insurance-policies'), ('post', '/me/signature-requests/{id}/code'), ('post', '/me/signature-requests/{id}/decline'), ('post', '/me/signature-requests/{id}/sign'), ('post', '/me/signature-requests/{id}/view'), ('post', '/message-channels/email/verify'), ('post', '/notification-deliveries/{id}/clear-bounce'), ('post', '/notification-templates/{id}/request-approval'), ('post', '/org-unit-types'), ('post', '/people/{id}/addresses'), ('post', '/people/{id}/emergency-contacts'), ('post', '/personnel-actions/{id}/cancel'), ('post', '/personnel-actions/{id}/correct'), ('post', '/platform/backup-restores/{id}/approve'), ('post', '/platform/backup-restores/{id}/cancel'), ('post', '/platform/feature-flags/{id}/archive'), ('post', '/platform/feature-flags/{id}/restore'), ('post', '/platform/health/outbox-events/{id}/retry'), ('post', '/platform/invoices/{id}/issue'), ('post', '/platform/invoices/{id}/payments'), ('post', '/platform/invoices/{id}/void'), ('post', '/platform/notices/{id}/cancel'), ('post', '/platform/subscriptions/{id}/cancel'), ('post', '/platform/subscriptions/{id}/convert'), ('post', '/platform/tenants/{id}/activate'), ('post', '/platform/tenants/{id}/block'), ('post', '/platform/tenants/{id}/modules/{code}/activate'), ('post', '/platform/tenants/{id}/modules/{code}/deactivate'), ('post', '/position-requests'), ('post', '/position-requests/{id}/cancel'), ('post', '/position-requests/{id}/submit'), ('post', '/positions/{id}/cancel-recruitment'), ('post', '/positions/{id}/open-recruitment'), ('post', '/positions/{id}/reject'), ('post', '/push-devices/{id}/revoke'), ('post', '/remote-work-agreements/{id}/approve'), ('post', '/remote-work-agreements/{id}/end'), ('post', '/remote-work-agreements/{id}/issue'), ('post', '/remote-work-agreements/{id}/register-signature'), ('post', '/remote-work-agreements/{id}/reject'), ('post', '/setup-checklist/{code}/reopen'), ('post', '/setup-checklist/{code}/skip'), ('post', '/support-access/grants/{id}/revoke'), ('post', '/tasks/{id}/start'), ('post', '/tickets/{id}/messages'), ('post', '/vacation-policies'), ('post', '/vacation-policies/{id}/archive'), ('post', '/vacation-policies/{id}/restore'), ('post', '/vacation-requests/{id}/register'), ('post', '/visual-flows/{id}/publish'), ('put', '/absences/{id}/medical-certificate')}
CONFLICTS = {('patch', '/document-categories/{id}'), ('patch', '/headcount-budgets/{id}'), ('patch', '/headcount-scenario-lines/{id}'), ('patch', '/platform/notices/{id}'), ('patch', '/support-access'), ('post', '/absences/{id}/register'), ('post', '/document-categories'), ('post', '/documents/{id}/versions'), ('post', '/employments/{employment_id}/bank-accounts'), ('post', '/employments/{employment_id}/health-regimes'), ('post', '/employments/{employment_id}/insurance-coverages'), ('post', '/employments/{employment_id}/pension-affiliations'), ('post', '/employments/{employment_id}/personnel-actions'), ('post', '/employments/{employment_id}/personnel-actions/propose'), ('post', '/headcount-budgets'), ('post', '/headcount-scenarios/{id}/lines'), ('post', '/health-providers'), ('post', '/platform/backups'), ('post', '/platform/backups/{id}/restores'), ('post', '/platform/health/failed-jobs/{id}/retry'), ('post', '/platform/subscriptions/{id}/invoices'), ('post', '/vacation-requests/simulate')}
CREATED = {('post', '/absences/{id}/attachments'), ('post', '/catalogs'), ('post', '/catalogs/{code}/items'), ('post', '/certificate-requests'), ('post', '/contract-renewal-batches'), ('post', '/contracts/{id}/renew'), ('post', '/document-categories'), ('post', '/document-requests'), ('post', '/document-templates/{id}/generate'), ('post', '/documents'), ('post', '/documents/{id}/versions'), ('post', '/employments/{employment_id}/bank-accounts'), ('post', '/employments/{employment_id}/cost-distributions'), ('post', '/employments/{employment_id}/health-regimes'), ('post', '/employments/{employment_id}/insurance-coverages'), ('post', '/employments/{employment_id}/life-insurance-declarations'), ('post', '/employments/{employment_id}/pension-affiliations'), ('post', '/employments/{employment_id}/personnel-actions'), ('post', '/employments/{employment_id}/personnel-actions/propose'), ('post', '/headcount-budgets'), ('post', '/headcount-scenarios/{id}/lines'), ('post', '/health-providers'), ('post', '/help/escalations'), ('post', '/import-profiles'), ('post', '/insurance-policies'), ('post', '/me/quick-unlock'), ('post', '/org-unit-types'), ('post', '/people/{id}/addresses'), ('post', '/people/{id}/emergency-contacts'), ('post', '/platform/backups'), ('post', '/platform/backups/{id}/restores'), ('post', '/platform/invoices/{id}/payments'), ('post', '/platform/notices'), ('post', '/platform/subscriptions/{id}/invoices'), ('post', '/position-requests'), ('post', '/push-devices'), ('post', '/support-access/grants'), ('post', '/ticket-categories'), ('post', '/tickets/{id}/messages'), ('post', '/vacation-policies')}

# Parámetros de consulta declarados de algunas operaciones de EXTRA (además responden 400 invalid_filter).
_q = lambda name, desc, required=False, fmt=None: {"name": name, "in": "query", "required": required, "description": desc,
                                                   "schema": {"type": "string", **({"format": fmt} if fmt else {})}}
QUERY = {
    ("get", "/import-profiles"): [_q("filter[entity]", "Tipo de datos (employees, org_structure, salaries…)"), _q("filter[code]", "Código del perfil")],
    ("get", "/platform/health"): [_q("tenant_id", "Organización (uuid): uso de API, errores y almacenamiento solo de ella")],
    ("get", "/platform/health/failed-jobs"): [_q("tenant_id", "Organización (uuid)"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/health/outbox-events"): [_q("tenant_id", "Organización (uuid)"), _q("status", "failed o dead")],
    ("get", "/platform/invoices"): [_q("tenant_id", "Organización (uuid)"), _q("status", "draft, observed, issued, paid, overdue o void"), _q("einvoice_status", "not_sent, pending, accepted o rejected"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/backups"): [_q("tenant_id", "Organización (uuid)"), _q("kind", "automatic o manual"), _q("status", "running, completed, failed o expired"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/backup-restores"): [_q("status", "requested, approved, running, completed, failed o cancelled"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/audit-events"): [_q("action", "Código de la acción"), _q("tenant_id", "Organización (uuid)"), _q("platform_user_id", "Usuario de la consola (uuid)"), _q("from", "Desde (fecha)", fmt="date"), _q("to", "Hasta (fecha)", fmt="date"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/activity-events"): [_q("tenant_id", "Organización (uuid, obligatoria)", True), _q("user_id", "Usuario de la organización (uuid)"), _q("from", "Desde (fecha)", fmt="date"), _q("to", "Hasta (fecha)", fmt="date"), _q("page", "Página"), _q("per_page", "Tamaño de página (máx. 100)")],
    ("get", "/platform/technical-logs"): [_q("tenant_id", "Organización (uuid)"), _q("user_id", "Usuario (uuid)"), _q("level", "debug, info, notice, warning, error, critical, alert o emergency"), _q("from", "Desde (fecha)", fmt="date"), _q("to", "Hasta (fecha)", fmt="date"), _q("limit", "Máximo de líneas (máx. 500)")],
    ("get", "/me/home"): [
        _q("employment_id", "Relación laboral propia con la que opera (RN-ESS-04); una ajena responde 404. Por defecto la activa más reciente"),
    ],
    ("get", "/me/employment"): [
        _q("employment_id", "Relación laboral propia que se muestra (RN-ESS-04); una ajena responde 404. Por defecto la activa más reciente"),
    ],
    ("get", "/my-team"): [
        _q("filter[level]", "direct: solo reportes directos; indirect: solo indirectos (requiere people.team.view_indirect)"),
        _q("q", "Nombre o código del colaborador"),
    ],
    ("get", "/help/articles"): [
        _q("filter[kind]", "guide, screen, field, tour, release_note, faq o policy (artículos de la organización, AYU-4)"),
        _q("filter[module]", "Código del módulo"),
        _q("screen_key", "Solo los artículos de esa pantalla"),
    ],
    ("get", "/help/search"): [
        _q("q", "Texto a buscar (mínimo 2 caracteres); sin resultados se registra la búsqueda sin datos de la persona (RN-AYU-05)", required=True),
        _q("screen_key", "Pantalla desde la que se busca"),
    ],
    ("get", "/my-team/key-dates"): [
        _q("from", "Desde (AAAA-MM-DD); por defecto hoy", fmt="date"),
        _q("to", "Hasta (AAAA-MM-DD); por defecto 30 días después de from; como máximo 90 días", fmt="date"),
        _q("filter[kind]", "Tipos separados por coma: birthday, anniversary, probation_end, contract_end"),
    ],
    ("get", "/documents/expiring"): [
        _q("within", "Días hacia adelante (1 a 365); por defecto, la anticipación de cada tipo o el parámetro documentos.aviso_vencimiento_dias"),
        _q("include_expired", "true (por defecto) incluye los vencidos"),
        _q("category_id", "Solo los de ese tipo o carpeta"),
        _q("q", "Nombre o código del titular, o título del documento"),
        _q("page", "Página (desde 1)"),
        _q("per_page", "Tamaño de página (hasta 100)"),
    ],
    ("get", "/employments/{employment_id}/documents"): [
        _q("include_archived", "true incluye los archivados"),
        _q("folder", "Código de la carpeta"),
    ],
    ("get", "/documents/{id}/file"): [_q("inline", "true para previsualizar en el navegador (Content-Disposition inline)")],
    ("get", "/documents/{id}/versions/{version}/file"): [_q("inline", "true para previsualizar en el navegador")],
    ("get", "/employments/{employment_id}/linked-documents/{ref}/file"): [_q("inline", "true para previsualizar en el navegador")],
    ("get", "/document-categories"): [_q("include_archived", "true incluye los tipos archivados")],
    ("get", "/absence-subsidies"): [
        _q("filter[status]", "Estados separados por coma (pending_filing, filed, observed, approved, reimbursed, rejected, cancelled)"),
        _q("filter[employment_id]", "Solo los expedientes de esa relación laboral"),
        _q("filter[year]", "Año del descanso (AAAA)"),
        _q("filter[overdue]", "true: solo los por presentar con el plazo vencido"),
        _q("page", "Página (desde 1)"),
        _q("per_page", "Tamaño de página (hasta 100)"),
    ],
    ("get", "/employments/{employment_id}/absence-summary"): [
        _q("from", "Inicio del periodo (AAAA-MM-DD)", True, "date"),
        _q("to", "Fin del periodo (AAAA-MM-DD); hasta 93 días después del inicio", True, "date"),
    ],
    ("get", "/employments/{employment_id}/medical-leave-summary"): [
        _q("year", "Año calendario (AAAA); por defecto el actual"),
    ],
    ("get", "/contracts/expiring"): [
        _q("within", "Días hacia adelante (1 a 365); por defecto, la mayor anticipación de aviso de la empresa de cada contrato"),
        _q("legal_entity_id", "Solo los contratos de esa empresa"),
        _q("include_expired", "true (por defecto) incluye los vencidos sin regularizar"),
        _q("q", "Nombre o código del colaborador, o número del contrato"),
        _q("page", "Página (desde 1)"),
        _q("per_page", "Tamaño de página (hasta 100)"),
    ],
    ("get", "/employments/{id}/timeline"): [
        _q("types", "Tipos separados por coma: hire, movement, compensation, contract, status, termination; vacío = todos los que el usuario puede ver"),
    ],
    ("get", "/employments/{id}/work-data"): [
        _q("as_of", "Fecha (AAAA-MM-DD) de los datos; por defecto hoy (o el ingreso si aún no empezó)", False, "date"),
    ],
    ("get", "/people/{id}/addresses"): [
        _q("history", "true: incluye las direcciones anteriores"),
    ],
    ("get", "/headcount"): [
        _q("as_of", "Fecha (AAAA-MM-DD) de la dotación; por defecto hoy", False, "date"),
        _q("legal_entity_id", "Solo esa empresa"),
        _q("org_unit_id", "Solo esa unidad y sus subunidades"),
        _q("report_currency", "Moneda ISO 4217 del consolidado de costos (CFG-7)"),
    ],
    ("get", "/positions/org-chart"): [
        _q("as_of", "Fecha (AAAA-MM-DD); por defecto hoy", False, "date"),
        _q("legal_entity_id", "Solo esa empresa"),
        _q("org_unit_id", "Solo las posiciones de esa unidad y sus subunidades"),
    ],
    ("get", "/headcount/positions"): [
        _q("org_unit_id", "Unidad cuyas posiciones se explican", True),
        _q("as_of", "Fecha (AAAA-MM-DD); por defecto hoy", False, "date"),
    ],
    ("get", "/personnel-actions"): [
        _q("filter[status]", "Estados separados por coma (draft, pending_approval, approved, scheduled, applied, rejected, cancelled, replaced); por defecto pending_approval, approved y scheduled"),
        _q("filter[kind]", "Tipo de movimiento (promotion, transfer, manager_change, cost_center_change, data_correction)"),
        _q("filter[employment_id]", "Solo los de esa relación laboral"),
        _q("filter[from]", "Desde esa fecha efectiva (AAAA-MM-DD)"),
        _q("filter[to]", "Hasta esa fecha efectiva (AAAA-MM-DD)"),
        _q("page", "Página (desde 1)"),
        _q("per_page", "Tamaño de página (hasta 100)"),
    ],
    ("get", "/position-requests"): [
        _q("status", "draft, submitted, approved, rejected, cancelled o fulfilled (separados por coma)"),
        _q("org_unit_id", "Solo esa unidad"),
        _q("mine", "true: solo las propias"),
    ],
    ("get", "/headcount-scenarios/compare"): [
        _q("ids", "Identificadores de los escenarios separados por coma (de 2 a 4)", True),
    ],
    ("get", "/platform/metrics"): [
        _q("from", "Inicio del periodo (AAAA-MM-DD); por defecto, hace 30 días", False, "date"),
        _q("to", "Fin del periodo (AAAA-MM-DD); por defecto, hoy", False, "date"),
        _q("plan_id", "Solo las organizaciones suscritas a ese plan"),
    ],
    ("get", "/headcount-budgets"): [
        _q("org_unit_id", "Solo esa unidad"),
        _q("legal_entity_id", "Solo esa empresa"),
        _q("as_of", "Vigentes a esa fecha (AAAA-MM-DD)", False, "date"),
    ],
    ("get", "/people/document-match"): [
        _q("document_type", "Tipo de documento (dni, ce, passport, ptp, cpp, other)", True),
        _q("document_number", "Número del documento", True),
        _q("document_country", "País emisor (ISO 3166-1 alfa-2); por defecto PE"),
    ],
    ("get", "/positions/{id}/occupants"): [
        _q("as_of", "Fecha (AAAA-MM-DD): solo los ocupantes vigentes a ese día", False, "date"),
    ],
    ("get", "/employments/{employment_id}/cost-distributions"): [
        _q("as_of", "Fecha (AAAA-MM-DD): solo la distribución vigente a ese día", False, "date"),
    ],
    ("get", "/org-chart"): [
        _q("as_of", "Fecha (AAAA-MM-DD) de la estructura; por defecto hoy", False, "date"),
        _q("view", "units (por unidades, por defecto) o people (por línea de reporte)"),
    ],
    ("get", "/directory"): [
        _q("q", "Nombre, código de colaborador o puesto"),
        _q("org_unit_id", "Solo la unidad y sus subunidades"),
        _q("site_id", "Solo esa sede"),
        _q("job_profile_id", "Solo ese puesto"),
        _q("page", "Página (desde 1)"),
        _q("per_page", "Tamaño de página (hasta 100)"),
    ],
    ("get", "/platform/tenants/{id}/usage"): [
        _q("from", "Primer día de la historia (AAAA-MM-DD); por defecto, 90 días antes de to", False, "date"),
        _q("to", "Último día de la historia (AAAA-MM-DD); por defecto, hoy", False, "date"),
    ],
    ("get", "/platform/subscriptions/{id}/plan-fit"): [
        _q("plan_id", "Plan contra el que se compara el consumo actual", True),
    ],
    ("get", "/vacation-legal-limits"): [
        _q("labor_regime_code", "Régimen laboral (pe_general, pe_micro, pe_pequena…)", True),
        _q("as_of", "Fecha (AAAA-MM-DD); por defecto hoy", False, "date"),
    ],
    ("get", "/team-calendar"): [
        _q("from", "Primer día (AAAA-MM-DD)", True, "date"),
        _q("to", "Último día, inclusive; hasta 92 días después de from", True, "date"),
        _q("org_unit_id", "Solo las personas de esa unidad"),
    ],
    ("get", "/vacation-balances/{id}/ledger"): [
        _q("period_id", "Solo los movimientos de ese periodo vacacional"),
    ],
    ("get", "/calendar"): [
        _q("from", "Primer día (AAAA-MM-DD)", True, "date"),
        _q("to", "Último día, inclusive; hasta 100 días después de from", True, "date"),
        _q("sources", "Fuentes separadas por coma (holidays, tasks…); vacío = todas las visibles"),
    ],
}

# Campos que se envían al crear pero no vuelven en la respuesta (p. ej. el contenido del archivo).
INPUT_EXTRA = {
    "TenantInput": {
        "admin_name": {"type": "string", "description": "Alta (SAS-2): nombre del primer administrador, que recibe la invitación"},
        "admin_email": {"type": "string", "format": "email", "description": "Alta (SAS-2): correo del primer administrador"},
        "legal_name": {"type": "string", "description": "Alta (SAS-2): razón social de la primera empresa"},
        "tax_id": {"type": "string", "description": "Alta (SAS-2): número fiscal de la primera empresa (RUC en Perú)"},
        "module_codes": {"type": "array", "items": {"type": "string"}, "description": "Alta (SAS-2): módulos que se activan además de los base"},
        "plan_id": {"type": "string", "nullable": True, "description": "Alta (SAS-4): plan de la suscripción; activa sus módulos (en prueba si hay fecha de fin)"},
    },
    "EmploymentInput": {
        "person": {"type": "object", "description": "Ingreso (COL-2) de una persona nueva: nombres, apellidos y documento (document_type, document_number, document_country); si el documento ya existe responde 409 person_exists con la persona"},
        "health_scheme": {"type": "string", "enum": ["essalud", "eps", "sis", "other"], "description": "Régimen de salud inicial (por defecto essalud)"},
        "pay_frequency": {"type": "string", "enum": ["monthly", "biweekly", "weekly", "daily", "hourly"], "description": "Periodicidad del sueldo inicial (exige people.employments.salary.edit junto con base_salary)"},
    },
    "JobProfileInput": {"change_reason": {"type": "string", "description": "Motivo del cambio de la descripción (queda en la nueva versión, ORG-5)"}},
    "SupportSessionInput": {"duration_minutes": {"type": "integer", "description": "Duración de la sesión en minutos (hasta la configuración global support.max_session_minutes)"}},
    "SignatureRequestInput": {"send": {"type": "boolean", "description": "Enviar a firma al crear (requiere documents.signature_requests.send)"}},
    "ImportJobInput": {"content_base64": {"type": "string", "description": "Contenido del archivo CSV o XLSX en base64 (IMP-1)"}},
}
READONLY = {"status", "person_name", "category_name", "folder_id", "folder_code", "folder_name", "expiring", "days_to_expiry", "responsible_name", "managed_by", "file_name", "mime_type", "size_bytes", "archived_at", "archive_reason", "return_on", "required_documents", "employer_paid_days", "subsidized_days", "subsidy_status", "absence_type_name", "absence_type_category", "is_confidential", "unit", "days", "hours", "calendar_days", "is_paid", "affects_attendance", "medical_attachments", "attachments", "registered_directly", "document_required", "document_due_on", "submitted_at", "cancelled_at", "approval", "legal", "job_category_name", "totals", "accruing", "advance_available", "overdue_days", "overdue_periods", "accruing_days", "available_days", "periods", "policy", "pending_validation", "employee_code", "contract_type_name", "contract_type_category", "renewed_by_contract_id", "has_document", "document_version", "signature_origin", "signed_at", "approved_at", "accumulated_days", "termination_reason", "accumulation", "warnings", "salary_change", "applied_refs", "cancellation_reason", "addenda", "previous_salary", "band_position", "compensation_id", "approval_request_id", "decided_at", "decision_reason", "registry_status", "deregistered_on", "deregistration_reason", "entitles_family_allowance", "has_proof", "missing_proof", "majority_alerted_on", "applied_fields", "pending_fields", "requested_by_name", "requested_by_user_id", "reviewed_at", "personnel_action_id", "termination", "other_employments", "full_name", "employments", "person_name", "position_title", "manager_name", "position_request_id", "overrides", "job_profile_name", "legal_entity_name", "org_unit_name", "site_name", "cost_center_name", "reports_to_title", "effective_reports_to_position_id", "occupied_seats", "occupants", "heads_units", "revision", "subscribers", "superseded_by_plan_id", "duplicated_from_plan_id", "version", "profiles_count", "allowed_actions", "category_pending", "positions_count", "current_version", "path", "effective_timezone", "has_logo", "mine", "last_login_at", "last_used_at", "last_seen_at", "last_checked_at", "mfa_required", "document_number_masked", "phone_masked", "issues", "has_draft", "workflow_id", "owner_name", "failing", "failing_since", "managed_by_flow", "stats", "next_run_at", "resume_at"}


def parse_fields(spec):
    props, req, sens = {}, [], []
    for tok in spec.split():
        name, typ = tok.split(":", 1)
        optional = name.endswith("?")
        name = name.rstrip("?")
        sensitive = name.endswith("!")
        name = name.rstrip("!")
        if typ == "m":
            sch = {"$ref": "#/components/schemas/Money"}
        elif typ.startswith("e("):
            sch = {"type": "string", "enum": typ[2:-1].split("|")}
        else:
            sch = dict(TYPES[typ])
        if optional and "$ref" not in sch:
            sch["nullable"] = True
        if sensitive:
            sens.append(name)
        props[name] = sch
        if not optional and not sensitive:
            req.append(name)
    return props, req, sens


def op_id(method, path):
    parts = [p for p in re.split(r"[/{}\-_]", path) if p]
    return method + "".join(p[:1].upper() + p[1:] for p in parts)


def path_params(path):
    return [{"name": n, "in": "path", "required": True, "schema": {"type": "string"}}
            for n in re.findall(r"{(\w+)}", path)]


def ref(n):
    return {"$ref": f"#/components/{n}"}


ERRS = {
    "400": ref("responses/BadRequest"), "401": ref("responses/Unauthorized"),
    "403": ref("responses/Forbidden"), "404": ref("responses/NotFound"),
    "409": ref("responses/Conflict"), "422": ref("responses/Unprocessable"),
    "429": ref("responses/TooManyRequests"), "503": ref("responses/UpstreamUnavailable"),
}

# Consultas que pueden responder 422 por una regla de negocio (CFG-7: falta el tipo de cambio).
GET_RULES = {('get', '/employments/{employment_id}/absence-summary'), ('get', '/exchange-rates/convert'), ('get', '/headcount'), ('get', '/platform/activity-events'), ('get', '/platform/audit-events'), ('get', '/platform/backup-restores'), ('get', '/platform/backups'), ('get', '/platform/health'), ('get', '/platform/health/failed-jobs'), ('get', '/platform/health/outbox-events'), ('get', '/platform/invoices'), ('get', '/platform/support-sessions'), ('get', '/platform/technical-logs')}
# Operaciones que dependen de un proveedor externo por su puerto (503 upstream_unavailable).
UPSTREAM = {("post", "/exchange-rates/import")}


def errs(*codes):
    return {c: ERRS[c] for c in codes}


def body(schema):
    return {"required": True, "content": {"application/json": {"schema": schema}}}


def ok(schema, desc="Correcto"):
    return {"description": desc, "content": {"application/json": {"schema": schema}}}


def one(name):
    return {"type": "object", "required": ["data"], "properties": {"data": ref(f"schemas/{name}")}}


def many(name):
    return {"type": "object", "required": ["data", "meta"], "properties": {
        "data": {"type": "array", "items": ref(f"schemas/{name}")}, "meta": ref("schemas/PageMeta")}}


def build():
    paths, schemas, rows = {}, {}, []
    used_ids = set()

    def add(path, method, op, epic, phase, perm, public=False):
        op["tags"] = [epic]
        op["x-phase"] = phase
        op["x-permission"] = perm if perm else ("public" if public else "authenticated")
        oid = op_id(method, path)
        assert oid not in used_ids, oid
        used_ids.add(oid)
        op["operationId"] = oid
        if public:
            op["security"] = []
        pp = path_params(path)
        if pp:
            op["parameters"] = pp + op.get("parameters", [])
        paths.setdefault(path, {})[method] = op

    for epic, phase, perm, path, name, fields, ops, actions in RES:
        props, req, sens = parse_fields(fields)
        full = {"id": {"type": "string", "description": "Identificador opaco"}}
        full.update(props)
        full["created_at"] = {"type": "string", "format": "date-time"}
        full["updated_at"] = {"type": "string", "format": "date-time"}
        sch = {"type": "object", "required": ["id"] + req, "properties": full}
        if sens:
            sch["x-sensitive-fields"] = sens
            sch["description"] = ("Campos sensibles (requieren permiso por campo; se omiten si no se tiene): "
                                  + ", ".join(sens))
        schemas[name] = sch
        if set("CU") & set(ops):
            inp = {k: v for k, v in props.items() if k not in READONLY}
            inp.update(INPUT_EXTRA.get(name + "Input", {}))
            schemas[name + "Input"] = {"type": "object", "properties": inp}
        label = name
        item = path + "/{id}"
        crud_c, crud_i = [], []
        if "L" in ops:
            add(path, "get", {
                "summary": f"Listar {label}",
                "parameters": [ref("parameters/Page"), ref("parameters/PerPage"), ref("parameters/Cursor"),
                               ref("parameters/Sort"), ref("parameters/Filter"), ref("parameters/Q"),
                               ref("parameters/AsOf"), ref("parameters/Fields")],
                "responses": {"200": ok(many(name)), **errs("400", "401", "403", "429", *(("404",) if "{" in path else ()))},
            }, epic, phase, perm + ".view")
            crud_c.append("GET")
        if "C" in ops:
            add(path, "post", {
                "summary": f"Crear {label}",
                "parameters": [ref("parameters/IdempotencyKey")],
                "requestBody": body(ref(f"schemas/{name}Input")),
                "responses": {"201": ok(one(name), "Creado"), **errs("400", "401", "403", "409", "422", "429", *(("404",) if "{" in path else ()))},
            }, epic, phase, perm + ".create")
            crud_c.append("POST")
        if "R" in ops:
            add(item, "get", {
                "summary": f"Ver {label}",
                "parameters": [ref("parameters/AsOf"), ref("parameters/Fields")],
                "responses": {"200": ok(one(name)), **errs("401", "403", "404")},
            }, epic, phase, perm + ".view")
            crud_i.append("GET")
        if "U" in ops:
            add(item, "patch", {
                "summary": f"Actualizar {label}",
                "parameters": [ref("parameters/IfMatch")],
                "requestBody": body(ref(f"schemas/{name}Input")),
                "responses": {"200": ok(one(name)), **errs("400", "401", "403", "404", "409", "422")},
            }, epic, phase, perm + ".update")
            crud_i.append("PATCH")
        if "D" in ops:
            add(item, "delete", {
                "summary": f"Eliminar {label} (pasa a la papelera)",
                "responses": {"204": {"description": "Eliminado"}, **errs("401", "403", "404", "409")},
            }, epic, phase, perm + ".delete")
            crud_i.append("DELETE")
        if crud_c:
            acts = [a for a, m in (("view", "GET"), ("create", "POST")) if m in crud_c]
            rows.append((phase, epic, ", ".join(crud_c), path, perm + "." + brace(acts)))
        if crud_i:
            acts = [a for a, m in (("view", "GET"), ("update", "PATCH"), ("delete", "DELETE")) if m in crud_i]
            rows.append((phase, epic, ", ".join(crud_i), item, perm + "." + brace(acts)))
        acts = actions.split()
        for act in acts:
            add(f"{item}/{act}", "post", {
                "summary": f"Acción «{act}» sobre {label}",
                "description": "Transición explícita; valida la máquina de estados y deja auditoría.",
                "parameters": [ref("parameters/IdempotencyKey")],
                "requestBody": body(ref("schemas/ActionRequest")),
                "responses": {"200": ok(one(name)), **errs("401", "403", "404", "409", "422")},
            }, epic, phase, perm + "." + act.replace("-", "_"))
        if acts:
            rows.append((phase, epic, "POST", f"{item}/{brace(acts)}",
                         perm + "." + brace([a.replace("-", "_") for a in acts])))

    for epic, phase, method, path, perm, summary, public in EXTRA:
        op = {"summary": summary}
        res = {"201": ok(ref("schemas/GenericEnvelope"), "Creado")} if (method, path) in CREATED else {"200": ok(ref("schemas/GenericEnvelope"))}
        if (method, path) in STATE_ACTIONS:
            # Transición de estado: 409 invalid_transition si el estado actual no la admite (regla 3).
            res.update(errs("409"))
        if (method, path) in CONFLICTS:
            res.update(errs("409"))
        if path.endswith("/download"):
            # Descarga directa del archivo (no es JSON).
            res["200"] = {"description": "Archivo", "content": {"text/csv": {"schema": {"type": "string"}}}}
            if path == "/exports/{id}/download":
                # UI-11 · La exportación sale en CSV, Excel o PDF.
                res["200"] = {"description": "Archivo", "content": {
                    "text/csv": {"schema": {"type": "string"}},
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": {"schema": {"type": "string", "format": "binary"}},
                    "application/pdf": {"schema": {"type": "string", "format": "binary"}}}}
            res.update(errs("409"))
        if method == "get" and (path.endswith("/proof") or path.endswith("/file") or path.endswith("/document") or path.endswith("/certificate") or path.endswith("/attachments/{attachment_id}")):
            res["200"] = {"description": "Archivo", "content": {m: {"schema": {"type": "string", "format": "binary"}} for m in ("application/pdf", "image/png", "image/jpeg")}}
        if (method, path) == ("get", "/imports/{id}/file"):
            # IMP-6 · El archivo original tal como se subió (CSV o Excel); 409 si la importación se descartó.
            res["200"] = {"description": "Archivo", "content": {m: {"schema": {"type": "string", "format": "binary"}} for m in ("text/csv", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/octet-stream")}}
            res.update(errs("409"))
        if (path.endswith("/logo") or path.endswith("/photo")) and method == "get":
            res["200"] = {"description": "Imagen", "content": {m: {"schema": {"type": "string", "format": "binary"}} for m in ("image/png", "image/jpeg", "image/webp")}}
        if (method, path) in QUERY:
            op["parameters"] = QUERY[(method, path)]
            res.update(errs("400"))
        if (method, path) in GET_RULES:
            res.update(errs("422"))
        if (method, path) in UPSTREAM:
            res.update(errs("503"))
        if method in ("post", "put", "patch"):
            op["requestBody"] = body(ref("schemas/GenericInput"))
            if method == "post":
                op["parameters"] = [ref("parameters/IdempotencyKey")]
            res.update(errs("400", "422", "429"))
            if path.endswith("/logo") or path.endswith("/photo"):
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file"], "properties": {"file": {"type": "string", "format": "binary"}}}}}}
            if path.endswith("/register-signature"):
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file", "signed_on"], "properties": {
                        "file": {"type": "string", "format": "binary"},
                        "signed_on": {"type": "string", "format": "date"},
                        "origin": {"type": "string", "enum": ["physical_scan", "electronic"]}}}}}}
            if path == "/documents" and method == "post":
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file", "category_id", "title"], "properties": {
                        "file": {"type": "string", "format": "binary"},
                        "category_id": {"type": "string", "format": "uuid"},
                        "title": {"type": "string"},
                        "description": {"type": "string"},
                        "employment_id": {"type": "string", "format": "uuid"},
                        "legal_entity_id": {"type": "string", "format": "uuid"},
                        "issued_on": {"type": "string", "format": "date"},
                        "expires_on": {"type": "string", "format": "date"},
                        "classification": {"type": "string", "enum": ["public", "internal", "confidential", "restricted"]},
                        "visible_to_employee": {"type": "boolean"},
                        "responsible_user_id": {"type": "string", "format": "uuid"}}}}}}
            if path == "/documents/{id}/versions":
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file"], "properties": {
                        "file": {"type": "string", "format": "binary"},
                        "issued_on": {"type": "string", "format": "date"},
                        "expires_on": {"type": "string", "format": "date"},
                        "notes": {"type": "string"}}}}}}
            if path == "/document-requests/{id}/submit":
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file"], "properties": {
                        "file": {"type": "string", "format": "binary"},
                        "issued_on": {"type": "string", "format": "date"},
                        "expires_on": {"type": "string", "format": "date"}}}}}}
            if path == "/tickets/{id}/messages":
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["body"], "properties": {
                        "body": {"type": "string"}, "file": {"type": "string", "format": "binary"},
                        "is_internal_note": {"type": "boolean"}}}}}}
            if path == "/absences/{id}/attachments":
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file"], "properties": {"file": {"type": "string", "format": "binary"}, "label": {"type": "string"}}}}}}
            if path.endswith("/proof"):
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["file"], "properties": {"file": {"type": "string", "format": "binary"}}}}}}
            if path.endswith("/life-insurance-declarations"):
                op["requestBody"] = {"required": True, "content": {"multipart/form-data": {"schema": {
                    "type": "object", "required": ["beneficiaries", "signed_on", "file"], "properties": {
                        "beneficiaries": {"type": "string", "description": "JSON: [{full_name, document_number?, relationship, percentage}] que suman 100"},
                        "signed_on": {"type": "string", "format": "date"},
                        "file": {"type": "string", "format": "binary"}}}}}}
        if not public:
            res.update(errs("401", "403"))
        elif path.startswith("/webhooks/"):
            # Webhook de un proveedor: 401 si la firma no es válida.
            res.update(errs("401"))
        # La consola SaaS responde 404 desde el host de una organización (SAS-1).
        # ESS: ?employment_id que no es propio responde 404 (RN-ESS-01); también al pedir una constancia.
        if "{" in path or path.startswith("/platform/") or path in ("/me/home", "/me/employment", "/certificate-types", "/certificate-requests"):
            res.update(errs("404"))
        op["responses"] = dict(sorted(res.items()))
        p = None if perm in (None, "-") else perm
        add(path, method, op, epic, phase, p, public)
        shown = "público" if public else (perm if p else "sesión")
        rows.append((phase, epic, method.upper(), path, shown))

    err_resp = lambda d: {"description": d, "content": {"application/json": {"schema": ref("schemas/Error")}}}
    spec = {
        "openapi": "3.0.3",
        "info": {
            "title": "GestiaHR API", "version": "1.0.0",
            "description": (
                "Contrato de la API REST de GestiaHR. Generado por kit/tools/gen_openapi.py; no editar a mano.\n\n"
                "Extensiones: `x-permission` (permiso exigido; `authenticated` = basta la sesión, "
                "`public` = sin autenticación, `platform` = basta la sesión de la consola SaaS) y `x-phase` (fase en la que se construye). "
                "Los campos listados en `x-sensitive-fields` solo se devuelven con permiso por campo."),
        },
        "servers": [{"url": "https://{tenant}.example.test/api/v1", "variables": {"tenant": {"default": "demo"}}}],
        "security": [{"sessionCookie": []}, {"bearerToken": []}],
        "tags": [{"name": k, "description": v} for k, v in EPICS.items()],
        "paths": dict(sorted(paths.items())),
        "components": {
            "securitySchemes": {
                "sessionCookie": {"type": "apiKey", "in": "cookie", "name": "gestia_session",
                                  "description": "Sesión web (cookie HttpOnly + cabecera X-XSRF-TOKEN en escrituras)."},
                "bearerToken": {"type": "http", "scheme": "bearer",
                                "description": "Token personal o de integración con scopes."},
            },
            "parameters": {
                "Page": {"name": "page", "in": "query", "schema": {"type": "integer", "minimum": 1, "default": 1}},
                "PerPage": {"name": "per_page", "in": "query", "schema": {"type": "integer", "minimum": 1, "maximum": 100, "default": 25}},
                "Cursor": {"name": "cursor", "in": "query", "description": "Cursor opaco; sustituye a page en colecciones de solo inserción.", "schema": {"type": "string"}},
                "Sort": {"name": "sort", "in": "query", "description": "Campos separados por coma; prefijo - para descendente.", "schema": {"type": "string"}},
                "Filter": {"name": "filter", "in": "query", "style": "deepObject", "explode": True,
                           "description": "filter[campo]=valor, filter[campo][gte]=valor. Operadores: eq, ne, in, gte, lte, like, null.",
                           "schema": {"type": "object", "additionalProperties": True}},
                "Q": {"name": "q", "in": "query", "description": "Búsqueda de texto sobre los campos indexados del recurso.", "schema": {"type": "string"}},
                "AsOf": {"name": "as_of", "in": "query", "description": "Fecha de vigencia con la que se resuelven los datos (por defecto hoy en la zona horaria de la empresa).", "schema": {"type": "string", "format": "date"}},
                "Fields": {"name": "fields", "in": "query", "description": "Lista de campos a devolver.", "schema": {"type": "string"}},
                "IdempotencyKey": {"name": "Idempotency-Key", "in": "header", "description": "Clave única por intento lógico; repetirla devuelve la respuesta original.", "schema": {"type": "string", "maxLength": 64}},
                "IfMatch": {"name": "If-Match", "in": "header", "description": "Versión del registro (ETag) para control de concurrencia.", "schema": {"type": "string"}},
            },
            "responses": {
                "BadRequest": err_resp("Solicitud mal formada"),
                "Unauthorized": err_resp("No autenticado"),
                "Forbidden": err_resp("Sin permiso, fuera de alcance o módulo no contratado"),
                "NotFound": err_resp("No existe en esta organización"),
                "Conflict": err_resp("Conflicto: transición inválida, versión desactualizada o duplicado"),
                "Unprocessable": err_resp("Validación de datos o regla de negocio"),
                "TooManyRequests": err_resp("Límite de solicitudes superado"),
                "UpstreamUnavailable": err_resp("Un servicio externo requerido no responde (upstream_unavailable)"),
            },
            "schemas": {
                "Error": {"type": "object", "required": ["error"], "properties": {"error": {
                    "type": "object", "required": ["code", "message", "request_id"], "properties": {
                        "code": {"type": "string", "example": "validation_failed"},
                        "message": {"type": "string", "description": "Texto traducido al idioma del usuario"},
                        "request_id": {"type": "string"},
                        "details": {"type": "array", "items": {"type": "object", "properties": {
                            "field": {"type": "string"}, "rule": {"type": "string"}, "message": {"type": "string"}}}},
                        "meta": {"type": "object", "additionalProperties": True}}}}},
                "PageMeta": {"type": "object", "properties": {
                    "page": {"type": "integer"}, "per_page": {"type": "integer"},
                    "total": {"type": "integer", "nullable": True},
                    "next_cursor": {"type": "string", "nullable": True},
                    "as_of": {"type": "string", "format": "date"}}},
                "Money": {"type": "object", "required": ["amount", "currency"], "properties": {
                    "amount": {"type": "string", "description": "Decimal como texto, sin pérdida", "example": "3500.00"},
                    "currency": {"type": "string", "description": "ISO 4217", "example": "PEN"}}},
                "ActionRequest": {"type": "object", "additionalProperties": True, "properties": {
                    "comment": {"type": "string"}, "effective_on": {"type": "string", "format": "date"},
                    "reason": {"type": "string"}}},
                "GenericInput": {"type": "object", "additionalProperties": True},
                "GenericEnvelope": {"type": "object", "required": ["data"], "properties": {
                    "data": {}, "meta": {"type": "object", "additionalProperties": True}}},
                **dict(sorted(schemas.items())),
            },
        },
    }
    return spec, rows


def brace(items):
    return items[0] if len(items) == 1 else "{" + ",".join(items) + "}"


def table(rows):
    out = []
    order = list(EPICS)
    rows = sorted(rows, key=lambda r: (r[0], order.index(r[1])))
    cur = None
    for phase, epic, method, path, perm in rows:
        if (phase, epic) != cur:
            if cur is None or cur[0] != phase:
                out.append(f"\n### Fase {phase} · {PHASES[phase]}\n")
            out.append(f"\n**{epic} · {EPICS[epic]}**\n")
            out.append("| Método | Ruta | Permiso | Fase |")
            out.append("|---|---|---|---|")
            cur = (phase, epic)
        perm_md = "sesión" if perm == "sesión" else ("público" if perm == "público" else f"`{perm}`")
        out.append(f"| {method} | `{path}` | {perm_md} | {phase} |")
    return "\n".join(out) + "\n"


def validate():
    from openapi_spec_validator import validate as v
    spec = yaml.safe_load(OUT.read_text(encoding="utf-8"))
    v(spec)
    n_paths = len(spec["paths"])
    n_ops = sum(1 for p in spec["paths"].values() for m in p if m in ("get", "post", "put", "patch", "delete"))
    missing = [f"{m.upper()} {p}" for p, it in spec["paths"].items() for m, o in it.items()
               if "x-permission" not in o or "x-phase" not in o]
    assert not missing, missing
    epics = {o["tags"][0] for it in spec["paths"].values() for o in it.values()}
    print(f"OpenAPI válido: {n_paths} rutas, {n_ops} operaciones, {len(spec['components']['schemas'])} esquemas, "
          f"{len(epics)} épicas con endpoints")
    sin = sorted(set(EPICS) - epics)
    print("Épicas sin endpoints propios:", ", ".join(sin) if sin else "ninguna")


def main():
    if "--check" not in sys.argv:
        spec, rows = build()
        OUT.parent.mkdir(parents=True, exist_ok=True)
        OUT.write_text(yaml.safe_dump(spec, sort_keys=False, allow_unicode=True, width=120), encoding="utf-8")
        if DOC.exists():
            txt = DOC.read_text(encoding="utf-8")
            a, b = "<!-- ENDPOINTS:INICIO -->", "<!-- ENDPOINTS:FIN -->"
            if a in txt and b in txt:
                txt = txt[:txt.index(a) + len(a)] + "\n" + table(rows) + "\n" + txt[txt.index(b):]
                DOC.write_text(txt, encoding="utf-8")
                print("Tabla de endpoints actualizada en docs/08-api.md")
    validate()


if __name__ == "__main__":
    main()
