#!/usr/bin/env python3
"""Genera api/openapi.yaml y la tabla de endpoints de docs/08-api.md.

Uso:  python3 tools/gen_openapi.py          (desde la raíz del repositorio)
      python3 tools/gen_openapi.py --check  (solo valida el archivo existente)

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
    "CFG": "Configuración y campos personalizados", "PLT": "Plataforma técnica",
    "ORG": "Estructura organizacional", "POS": "Posiciones y headcount", "COL": "Colaboradores",
    "HIS": "Historia con vigencia", "CON": "Contratos y adendas", "DOC": "Documentos y firma",
    "ESS": "Portal del colaborador", "MSS": "Portal del manager", "IMP": "Importador y puesta en marcha",
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
    ("SAS", 0, "platform.tenants", "/platform/tenants", "Tenant", "name:s slug:s country:s status:e(trial|active|suspended|cancelled) plan_id:id trial_ends_on?:d", "LCRU", "suspend reactivate cancel"),
    ("SAS", 0, "platform.plans", "/platform/plans", "Plan", "code:s name:s billing_cycle:e(monthly|yearly) currency:s limits:j modules:a status:e(draft|active|retired)", "LCRU", "duplicate activate retire"),
    ("SAS", 0, "platform.subscriptions", "/platform/subscriptions", "Subscription", "tenant_id:id plan_id:id status:e(trial|active|past_due|suspended|cancelled) addons:a starts_on:d ends_on?:d", "LCRU", "change-plan suspend reactivate"),
    ("SAS", 0, "platform.feature_flags", "/platform/feature-flags", "FeatureFlag", "key:s description:s default_on:b rules:j", "LCRU", ""),
    ("SAS", 0, "platform.support_sessions", "/platform/support-sessions", "SupportSession", "tenant_id:id reason:s status:e(requested|granted|ended|denied) expires_at:dt", "LCR", "end"),
    ("SEG", 0, "identity.users", "/users", "User", "person_id?:id login:s display_name:s email?:s status:e(invited|active|locked|alumni|disabled) mfa_enabled:b role_ids:a role_assignments?:ao last_login_at?:dt", "LCRU", "invite suspend reactivate reset-mfa reset-password"),
    ("SEG", 0, "identity.roles", "/roles", "Role", "name:s system:b permissions:a field_permissions:a scope:e(organization|legal_entity|site|unit|team|self)", "LCRUD", "duplicate"),
    ("SEG", 0, "identity.api_tokens", "/api-tokens", "ApiToken", "name:s scopes:a expires_at?:dt last_used_at?:dt status:e(active|revoked)", "LCR", "revoke"),
    ("AUD", 0, "audit.logs", "/audit-logs", "AuditLog", "actor_id?:id action:s entity:s entity_id:s before?:j after?:j ip?:s occurred_at:dt sensitive:b", "LR", ""),
    ("AUD", 0, "audit.sensitive_access", "/sensitive-access-logs", "SensitiveAccessLog", "actor_id:id entity:s entity_id:s fields:a purpose?:s occurred_at:dt", "L", ""),
    ("WFL", 0, "workflow.approval_flows", "/approval-flows", "ApprovalFlow", "name:s subject_type:s steps:j conditions:j status:e(draft|published|retired) version:i has_draft?:b allowed_actions?:a", "LCRU", "publish retire"),
    ("WFL", 0, "workflow.approval_requests", "/approval-requests", "ApprovalRequest", "subject_type:s subject_id:id flow_id:id flow_name?:s current_step:i current_step_name?:s levels?:i status:e(pending|approved|rejected|cancelled|expired) escalated?:b due_at?:dt started_by_user_id?:id requester_name?:s tasks?:ao can_decide?:b bulk_safe?:b finished_at?:dt", "LR", "approve reject delegate"),
    ("WFL", 0, "workflow.workflows", "/workflows", "Workflow", "name:s trigger_event:s graph:j status:e(draft|active|paused|retired) version:i", "LCRU", "activate pause retire"),
    ("WFL", 0, "workflow.runs", "/workflow-runs", "WorkflowRun", "workflow_id:id status:e(running|waiting|completed|failed|cancelled) started_at:dt finished_at?:dt error?:s", "LR", "retry cancel"),
    ("WFL", 0, "workflow.tasks", "/tasks", "Task", "title:s description?:s assignee_user_id?:id assignee_role_id?:id assignee_name?:s created_by_name?:s due_on?:d priority:e(low|normal|high|urgent) status:e(pending|in_progress|done|cancelled) overdue?:b related_type?:s related_id?:id completed_at?:dt allowed_actions?:a", "LCRU", "complete cancel"),
    ("NOT", 0, "notifications.templates", "/notification-templates", "NotificationTemplate", "key:s channel:e(in_app|email|push|whatsapp|sms) locale:s subject?:s body:s variables?:a mandatory?:b customized?:b default_subject?:s default_body?:s", "LCRU", ""),
    ("CFG", 0, "settings.custom_fields", "/custom-fields", "CustomField", "entity:s key:s label:j type:e(text|number|date|select|multiselect|checkbox|user|file|url|money|percent) options?:a required:b sensitive:b", "LCRUD", ""),
    ("CFG", 0, "settings.exchange_rates", "/exchange-rates", "ExchangeRate", "from_currency:s to_currency:s rate:n valid_on:d source:s", "LC", ""),
    ("CFG", 0, "settings.saved_views", "/saved-views", "SavedView", "resource:s name:s filters:j columns:a sort:s shared:b", "LCRUD", ""),
    # ---------- Fase 1 ----------
    ("ORG", 1, "org.legal_entities", "/legal-entities", "LegalEntity", "name:s tax_id:s country:s currency:s timezone:s payroll_regime:s status:e(active|archived)", "LCRU", "archive restore"),
    ("ORG", 1, "org.sites", "/sites", "Site", "legal_entity_id:id name:s address:s timezone:s risk_site:b status:e(active|archived)", "LCRU", "archive restore"),
    ("ORG", 1, "org.units", "/org-units", "OrgUnit", "name:s type:s parent_id?:id manager_position_id?:id valid_from:d valid_to?:d status:e(active|archived)", "LCRU", "archive restore move"),
    ("ORG", 1, "org.cost_centers", "/cost-centers", "CostCenter", "code:s name:s legal_entity_id:id status:e(active|archived)", "LCRU", "archive restore"),
    ("ORG", 1, "org.job_profiles", "/job-profiles", "JobProfile", "code:s name:s level?:s category?:s family?:s description?:s status:e(active|archived)", "LCRU", "archive restore"),
    ("POS", 1, "org.positions", "/positions", "Position", "job_profile_id:id org_unit_id:id site_id:id cost_center_id?:id reports_to_position_id?:id status:e(requested|approved|open|filled|frozen|closed) budget!?:m valid_from:d", "LCRU", "approve freeze unfreeze close"),
    ("POS", 1, "org.headcount_plans", "/headcount-plans", "HeadcountPlan", "name:s scenario:e(current|conservative|growth|custom) year:i status:e(draft|submitted|approved|closed) totals:j", "LCRU", "submit approve close"),
    ("COL", 1, "people.people", "/people", "Person", "first_name:s last_name:s document_type:s document_number:s birth_date?:d nationality?:s email?:s phone?:s address?:j", "LCRU", "merge"),
    ("COL", 1, "people.employments", "/employments", "Employment", "person_id:id legal_entity_id:id code:s hire_date:d termination_date?:d status:e(pre_hire|active|suspended|terminated) worker_type:s payroll_regime:s position_id?:id manager_employment_id?:id base_salary!?:m", "LCRU", "activate suspend terminate cancel-termination"),
    ("COL", 1, "people.dependents", "/employments/{employment_id}/dependents", "Dependent", "first_name:s last_name:s relationship:s document_number?:s birth_date?:d valid_from:d valid_to?:d", "LCRUD", ""),
    ("HIS", 1, "people.assignments", "/employments/{employment_id}/assignments", "Assignment", "position_id?:id org_unit_id:id site_id:id cost_center_id?:id manager_employment_id?:id valid_from:d valid_to?:d reason:s", "LCR", "cancel"),
    ("HIS", 1, "people.compensations", "/employments/{employment_id}/compensations", "Compensation", "base_salary!:m pay_frequency:e(monthly|biweekly|weekly) components!?:j valid_from:d valid_to?:d reason:s", "LCR", "cancel"),
    ("CON", 1, "contracts.contracts", "/contracts", "Contract", "employment_id:id type:s starts_on:d ends_on?:d probation_ends_on?:d workday:s cause?:s status:e(draft|issued|signed|active|expired|terminated)", "LCRU", "issue renew terminate"),
    ("CON", 1, "contracts.addenda", "/contract-addenda", "ContractAddendum", "contract_id:id kind:s effective_on:d changes:j status:e(draft|issued|signed|cancelled)", "LCR", "issue cancel"),
    ("DOC", 1, "documents.documents", "/documents", "Document", "employment_id?:id folder:s name:s file_id:id version:i confidentiality:e(normal|restricted|sensitive) expires_on?:d status:e(active|archived)", "LCRU", "archive restore new-version"),
    ("DOC", 1, "documents.templates", "/document-templates", "DocumentTemplate", "name:s kind:s locale:s body:s variables:a status:e(draft|published|retired) version:i", "LCRU", "publish retire generate"),
    ("DOC", 1, "documents.signature_requests", "/signature-requests", "SignatureRequest", "document_id:id signers:j ordered:b provider:s status:e(draft|sent|viewed|partially_signed|completed|declined|cancelled|expired) evidence?:j", "LCR", "send sign decline cancel remind"),
    ("ESS", 1, "people.change_requests", "/profile-change-requests", "ProfileChangeRequest", "employment_id:id changes:j attachments:a status:e(pending|approved|rejected|cancelled)", "LCR", "approve reject cancel"),
    ("IMP", 1, "imports.imports", "/imports", "ImportJob", "kind:e(employees|contracts|vacation_balances|salaries|attendance|concepts|history) file_id:id mapping:j status:e(uploaded|mapped|validated|importing|completed|failed|cancelled) summary?:j", "LCR", "map validate execute cancel"),
    # ---------- Fase 2 ----------
    ("ASI", 2, "time.punches", "/time-punches", "TimePunch", "employment_id:id punched_at:dt kind:e(in|out|break_start|break_end) method:e(web|app|kiosk|qr|pin|crew|biometric|whatsapp) site_id?:id geo?:j device_id?:id offline:b corrects_id?:id reason?:s", "LCR", ""),
    ("ASI", 2, "time.attendance_days", "/attendance-days", "AttendanceDay", "employment_id:id date:d scheduled:j worked_minutes:i late_minutes:i overtime_minutes:i night_minutes:i status:e(ok|late|absent|justified|holiday|rest|incomplete)", "LR", "justify recalculate"),
    ("ASI", 2, "time.devices", "/punch-devices", "PunchDevice", "name:s kind:e(kiosk|biometric|tablet) site_id:id connector?:s last_seen_at?:dt status:e(active|disabled)", "LCRU", "disable enable"),
    ("ASI", 2, "time.geofences", "/geofences", "Geofence", "site_id:id name:s center:j radius_m:i status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.schedules", "/work-schedules", "WorkSchedule", "name:s weekly_hours:n days:j tolerance_minutes:i break_minutes:i status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.shifts", "/shifts", "Shift", "name:s starts_at:s ends_at:s crosses_midnight:b color?:s status:e(active|archived)", "LCRU", "archive"),
    ("TUR", 2, "time.shift_assignments", "/shift-assignments", "ShiftAssignment", "employment_id:id shift_id:id date:d status:e(draft|published|swapped|cancelled)", "LCRUD", "publish"),
    ("TUR", 2, "time.holiday_calendars", "/holiday-calendars", "HolidayCalendar", "name:s country:s scope:j holidays:j", "LCRU", ""),
    ("VAC", 2, "time.vacation_balances", "/vacation-balances", "VacationBalance", "employment_id:id period_start:d period_end:d accrued_days:n taken_days:n scheduled_days:n available_days:n expires_on?:d", "LR", "adjust"),
    ("VAC", 2, "time.vacation_requests", "/vacation-requests", "VacationRequest", "employment_id:id starts_on:d ends_on:d days:n advance:b status:e(draft|pending|approved|rejected|cancelled|taken) approval_request_id?:id", "LCRU", "submit approve reject cancel"),
    ("AUS", 2, "time.absence_types", "/absence-types", "AbsenceType", "code:s name:s paid:b requires_attachment:b affects_payroll_concept?:s max_days?:i sensitive:b status:e(active|archived)", "LCRU", "archive"),
    ("AUS", 2, "time.absences", "/absences", "Absence", "employment_id:id absence_type_id:id starts_on:d ends_on:d days:n attachment_ids:a diagnosis!?:s status:e(draft|pending|approved|rejected|cancelled)", "LCRU", "submit approve reject cancel"),
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
    ("HSD", 6, "helpdesk.tickets", "/tickets", "Ticket", "employment_id:id category:s subject:s body:s assignee_id?:id sla_due_at?:dt status:e(new|in_progress|waiting_info|resolved|closed)", "LCRU", "assign request-info resolve close reopen"),
    ("HSD", 6, "helpdesk.articles", "/kb-articles", "KbArticle", "title:s category:s body:s audience:j status:e(draft|published|archived)", "LCRU", "publish archive"),
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
    ("RPT", 8, "reports.exports", "/exports", "ExportJob", "resource:s format:e(csv|xlsx|pdf) filters:j file_id?:id expires_at?:dt status:e(queued|running|ready|failed)", "LCR", ""),
    ("ALE", 8, "alerts.rules", "/alert-rules", "AlertRule", "key:s name:s params:j audience:j status:e(active|paused)", "LCRU", "pause resume"),
    ("ALE", 8, "alerts.alerts", "/alerts", "Alert", "rule_key:s title:s explanation:s factors:j subject_type?:s subject_id?:id severity:e(info|warning|critical) status:e(open|snoozed|dismissed|resolved)", "LR", "snooze dismiss resolve"),
    ("IAR", 8, "ai.conversations", "/ai/conversations", "AiConversation", "title?:s user_id:id messages:j", "LCR", "send-message"),
    ("IAR", 8, "ai.usage_logs", "/ai/usage-logs", "AiUsageLog", "user_id:id use_case:s provider:s model:s data_scopes:a tools_called:a input_ref:s output_ref:s occurred_at:dt", "LR", ""),
    # ---------- Fase 9 ----------
    ("API", 9, "integrations.webhooks", "/webhook-endpoints", "WebhookEndpoint", "url:s events:a secret_hint:s status:e(active|paused|failing)", "LCRUD", "rotate-secret test pause resume"),
    ("API", 9, "integrations.webhook_deliveries", "/webhook-deliveries", "WebhookDelivery", "endpoint_id:id event:s event_id:id attempts:i response_code?:i next_attempt_at?:dt status:e(pending|delivered|failed|exhausted)", "LR", "retry"),
    ("INT", 9, "integrations.connections", "/integrations", "IntegrationConnection", "port:s driver:s config:j health:e(ok|degraded|down|unknown) last_checked_at?:dt status:e(connected|disconnected|error)", "LCRU", "test disconnect sync"),
    ("PAI", 9, "platform.country_packs", "/country-packs", "CountryPack", "country:s version:s contents:j status:e(available|active|retired)", "LR", "activate"),
    ("EST", 9, "partner.clients", "/partner/clients", "PartnerClient", "tenant_id:id name:s tax_id:s headcount:i next_deadline?:d status:e(active|paused)", "LCR", "enter"),
]

# Operaciones que no son CRUD: (épica, fase, método, ruta, permiso|None, resumen, público)
EXTRA = [
    ("PLT", 0, "get", "/health", None, "Salud de la API: disponible sin sesión ni organización", True),
    ("SEG", 0, "post", "/auth/login", None, "Iniciar sesión (correo o documento de identidad + clave)", True),
    ("SEG", 0, "post", "/auth/password/forgot", None, "Solicitar el enlace de recuperación de clave (misma respuesta exista o no el correo)", True),
    ("SEG", 0, "post", "/auth/password/reset", None, "Restablecer la clave con el enlace de un solo uso", True),
    ("SEG", 0, "post", "/auth/invitation/accept", None, "Activar la cuenta con el enlace de invitación y definir la clave", True),
    ("SEG", 0, "post", "/auth/otp", None, "Solicitar o verificar un código de un solo uso", True),
    ("SEG", 0, "post", "/auth/mfa/verify", None, "Verificar segundo factor", True),
    ("SEG", 0, "post", "/auth/logout", "-", "Cerrar sesión", False),
    ("SEG", 0, "get", "/me", "-", "Usuario actual, permisos efectivos, alcance y módulos activos", False),
    ("SEG", 0, "get", "/permissions", "identity.roles.view", "Catálogo de permisos y permisos por campo", False),
    ("TEN", 0, "get", "/catalogs/organization", "org.legal_entities.view", "Catálogos para registrar empresas: países, monedas y regímenes laborales", False),
    ("TEN", 0, "get", "/tenant", "settings.organization.view", "Organización actual: datos, plan, módulos y límites", False),
    ("TEN", 0, "patch", "/tenant", "settings.organization.update", "Actualizar datos de la organización", False),
    ("SAS", 0, "get", "/platform/health", "platform.health.view", "Salud de la plataforma: colas, trabajos fallidos, webhooks, conectores", False),
    ("SAS", 0, "get", "/platform/metrics", "platform.metrics.view", "Indicadores de la consola SaaS", False),
    ("WFL", 0, "get", "/inbox", "-", "Bandeja de pendientes del usuario actual", False),
    ("WFL", 0, "post", "/approval-requests/bulk-approve", "workflow.approval_requests.approve", "Aprobar en masa solicitudes de tipos marcados como seguros (RN-WFL-12)", False),
    ("NOT", 0, "get", "/notifications", "-", "Notificaciones del usuario actual", False),
    ("NOT", 0, "post", "/notifications/read", "-", "Marcar notificaciones como leídas", False),
    ("NOT", 0, "get", "/notification-deliveries", "notifications.deliveries.view", "Registro de entregas por canal externo con estado y error", False),
    ("NOT", 0, "get", "/notification-preferences", "-", "Preferencias de notificación del usuario", False),
    ("NOT", 0, "put", "/notification-preferences", "-", "Guardar preferencias de notificación", False),
    ("CFG", 0, "get", "/settings/{group}", "settings.{group}.view", "Leer un grupo de configuración", False),
    ("CFG", 0, "put", "/settings/{group}", "settings.{group}.update", "Guardar un grupo de configuración", False),
    ("PLT", 0, "get", "/search", "-", "Búsqueda global filtrada por permisos y alcance", False),
    ("PLT", 0, "post", "/files", "-", "Crear un archivo y obtener URL de subida firmada", False),
    ("PLT", 0, "get", "/files/{id}/download-url", "-", "URL de descarga firmada de corta vida (se valida el permiso del recurso dueño)", False),
    ("AUD", 0, "get", "/trash", "audit.trash.view", "Papelera: registros eliminados que se pueden restaurar", False),
    ("AUD", 0, "post", "/trash/{id}/restore", "audit.trash.restore", "Restaurar un registro de la papelera", False),
    ("ORG", 1, "get", "/org-chart", "org.units.view", "Organigrama por posiciones en una fecha (as_of)", False),
    ("POS", 1, "get", "/headcount", "org.positions.view", "Resumen de dotación: aprobadas, ocupadas, vacantes, congeladas", False),
    ("COL", 1, "get", "/directory", "people.directory.view", "Directorio (solo datos no sensibles)", False),
    ("HIS", 1, "get", "/employments/{employment_id}/timeline", "people.employments.view", "Línea de tiempo de la relación laboral", False),
    ("ESS", 1, "get", "/me/employment", "-", "Ficha propia del colaborador", False),
    ("ESS", 1, "get", "/me/home", "-", "Inicio del colaborador: saldos, próximo pago, pendientes", False),
    ("MSS", 1, "get", "/my-team", "people.team.view", "Equipo directo e indirecto del manager", False),
    ("MSS", 1, "get", "/my-team/calendar", "people.team.view", "Calendario de ausencias del equipo", False),
    ("IMP", 1, "get", "/setup-checklist", "imports.imports.view", "Avance de la puesta en marcha guiada", False),
    ("ASI", 2, "post", "/time-punches/batch", "time.punches.create", "Sincronizar marcaciones sin conexión o de un dispositivo (idempotente por client_id)", False),
    ("ASI", 2, "post", "/attendance-periods/{period}/close", "time.attendance_days.close", "Cerrar la asistencia de un periodo para planilla", False),
    ("TUR", 2, "post", "/shift-assignments/bulk", "time.shift_assignments.create", "Asignación masiva de turnos", False),
    ("VAC", 2, "get", "/calendar", "time.calendar.view", "Calendario central de RRHH", False),
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
    ("WHA", 9, "post", "/channels/whatsapp/webhook", None, "Entrada de mensajes del proveedor de WhatsApp (firma verificada)", True),
    ("WHA", 9, "get", "/channels/whatsapp/settings", "integrations.whatsapp.view", "Configuración del canal WhatsApp", False),
    ("WHA", 9, "put", "/channels/whatsapp/settings", "integrations.whatsapp.update", "Guardar configuración del canal WhatsApp", False),
    ("INT", 9, "get", "/integrations/health", "integrations.connections.view", "Salud de todos los conectores", False),
    ("INT", 9, "post", "/integrations/biometric/{device_id}/punches", "integrations.biometric.push", "Entrada de marcaciones desde un reloj biométrico", False),
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
READONLY = {"status", "version", "last_login_at", "last_used_at", "last_seen_at", "last_checked_at"}


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
    "429": ref("responses/TooManyRequests"),
}


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
                "responses": {"200": ok(many(name)), **errs("400", "401", "403", "429")},
            }, epic, phase, perm + ".view")
            crud_c.append("GET")
        if "C" in ops:
            add(path, "post", {
                "summary": f"Crear {label}",
                "parameters": [ref("parameters/IdempotencyKey")],
                "requestBody": body(ref(f"schemas/{name}Input")),
                "responses": {"201": ok(one(name), "Creado"), **errs("400", "401", "403", "409", "422", "429")},
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
        res = {"200": ok(ref("schemas/GenericEnvelope"))}
        if method in ("post", "put", "patch"):
            op["requestBody"] = body(ref("schemas/GenericInput"))
            if method == "post":
                op["parameters"] = [ref("parameters/IdempotencyKey")]
            res.update(errs("400", "422", "429"))
        if not public:
            res.update(errs("401", "403"))
        if "{" in path:
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
            "title": "Ayni HR API", "version": "1.0.0",
            "description": (
                "Contrato de la API REST de Ayni HR. Generado por tools/gen_openapi.py; no editar a mano.\n\n"
                "Extensiones: `x-permission` (permiso exigido; `authenticated` = basta la sesión, "
                "`public` = sin autenticación) y `x-phase` (fase en la que se construye). "
                "Los campos listados en `x-sensitive-fields` solo se devuelven con permiso por campo."),
        },
        "servers": [{"url": "https://{tenant}.example.test/api/v1", "variables": {"tenant": {"default": "demo"}}}],
        "security": [{"sessionCookie": []}, {"bearerToken": []}],
        "tags": [{"name": k, "description": v} for k, v in EPICS.items()],
        "paths": dict(sorted(paths.items())),
        "components": {
            "securitySchemes": {
                "sessionCookie": {"type": "apiKey", "in": "cookie", "name": "ayni_session",
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
