#F 2 | Tiempo

#E ASI | Asistencia y marcación
@attendance_devices soft lock | Dispositivos de marcación: reloj biométrico, kiosko, tableta, estación QR o conector por API (E-07).
  code ; name ; kind enum(biometric_clock,kiosk,tablet,mobile,qr_station,api)! ; site_id ?-> sites
  integration_id ?-> integrations ; serial_number ; device_token_hash ; timezone ; status enum(active,inactive,lost)=active
  last_sync_at ; uniq code
@attendance_geofences soft | Geocercas válidas para marcar desde la app.
  site_id ?-> sites ; name ; latitude numeric(9,6)! ; longitude numeric(9,6)! ; radius_m integer! ; is_active bool=t
@attendance_sync_batches | Lotes sincronizados por dispositivos o app en modo sin conexión; evita duplicar marcaciones.
  attendance_device_id ?-> attendance_devices ; submitted_by_user_id ?-> users ; client_batch_id text! ; received_at ts=now
  record_count integer! ; accepted_count integer ; rejected_count integer ; status enum(received,processed,partial,failed)=received
  uniq client_batch_id
@attendance_punches imm main | Marcación de asistencia inalterable (C-07, DS 004-2006-TR). Una corrección es una fila nueva que apunta a la original e indica el motivo. client_id deduplica las marcaciones sin conexión.
  employment_id -> employments ; punched_at ts! ; kind enum(in,out,break_start,break_end)!
  method enum(web,app,qr,kiosk,pin,biometric,crew,api,import,manual)! ; attendance_device_id ?-> attendance_devices
  site_id ?-> sites ; latitude numeric(9,6) #S ; longitude numeric(9,6) #S ; accuracy_m numeric(8,2)
  geofence_id ?-> attendance_geofences ; within_geofence boolean ; selfie_file_id ?-> stored_files #S ; liveness_score numeric(5,4)
  registered_by_user_id ?-> users ; is_offline ; device_recorded_at ts ; sync_batch_id ?-> attendance_sync_batches ; client_id ; source_hash
  entry_type enum(original,correction,annulment)=original ; corrects_punch_id ?-> attendance_punches ; correction_reason
  correction_request_id ?-> attendance_correction_requests ; ip inet
  check entry_type = 'original' OR (corrects_punch_id IS NOT NULL AND correction_reason IS NOT NULL)
  idx employment_id, punched_at ; raw UNIQUE (tenant_id, client_id)
@attendance_correction_requests lock | Solicitud de corrección de marcación; al aprobarse genera una marcación nueva, nunca modifica la original.
  employment_id -> employments ; work_date date! ; requested_by_user_id -> users
  kind enum(missing_punch,wrong_time,wrong_kind,annul)! ; proposed_punched_at ts ; proposed_kind ; target_punch_id ?-> attendance_punches
  reason text! ; status enum(pending,approved,rejected,cancelled)=pending ; workflow_instance_id ?-> workflow_instances
  resolved_by_user_id ?-> users ; resolved_at
@attendance_days main lock | Resumen diario calculado a partir de las marcaciones (recalculable): horas trabajadas, tardanza, sobretiempo, nocturnidad.
  employment_id -> employments ; work_date date! ; shift_id ?-> shifts ; scheduled_minutes integer ; worked_minutes integer=0
  break_minutes integer=0 ; late_minutes integer=0 ; early_leave_minutes integer=0 ; overtime_minutes integer=0
  overtime_tier1_minutes integer=0 ; overtime_tier2_minutes integer=0 ; night_minutes integer=0 ; holiday_worked bool=f
  rest_day_worked bool=f ; status enum(open,complete,incomplete,absent,justified,holiday,rest,leave)=open
  absence_request_id ?-> absence_requests ; payroll_period_id ?-> payroll_periods ; is_locked ; computed_at
  uniq employment_id, work_date
@attendance_incidents lock | Incidencias de asistencia (tardanza, falta, salida anticipada, marcación faltante) y su justificación.
  employment_id -> employments ; attendance_day_id ?-> attendance_days ; work_date date!
  kind enum(late,absence,early_leave,missing_punch,unscheduled,out_of_geofence,overtime_unapproved)! ; minutes integer
  status enum(open,justified,unjustified,dismissed)=open ; justification ; resolved_by_user_id ?-> users ; resolved_at
@biometric_enrollments soft sens | Plantilla biométrica (rostro o huella) cifrada; exige consentimiento y evaluación de impacto (C-08).
  employment_id -> employments ; kind enum(face,fingerprint)! ; template_enc text! ; consent_id ?-> consents
  attendance_device_id ?-> attendance_devices ; enrolled_at ts! ; revoked_at

#E TUR | Horarios, jornadas y turnos
@holiday_calendars soft | Calendarios de feriados por país, sede o empresa.
  code ; name ; country_code ; site_id ?-> sites ; is_default ; uniq code
@holidays | Feriados de un calendario.
  holiday_calendar_id -> holiday_calendars ; holiday_date date! ; name
  kind enum(national,regional,company,non_working_compensable)=national ; uniq holiday_calendar_id, holiday_date
@work_schedules main soft lock | Jornada u horario tipo: horas semanales, tolerancia, descansos; admite jornadas atípicas por ciclo.
  code ; name ; kind enum(fixed,flexible,rotating,shift_based,atypical)=fixed ; weekly_hours numeric(5,2)!
  tolerance_minutes smallint=0 ; break_minutes smallint=0 ; break_is_paid bool=f ; holiday_calendar_id ?-> holiday_calendars
  is_night ; cycle_days smallint ; uniq code
@work_schedule_days | Detalle por día del horario (entrada, salida, descanso).
  work_schedule_id -> work_schedules ; day_index smallint! ; is_working bool=t ; start_time time ; end_time time
  break_start time ; break_end time ; crosses_midnight bool=f ; expected_minutes integer ; uniq work_schedule_id, day_index
@shifts soft lock | Turnos (mañana, tarde, noche, partido, rotativo).
  code ; name ; kind enum(morning,afternoon,night,split,rotating,custom)! ; start_time time! ; end_time time!
  crosses_midnight bool=f ; break_minutes smallint=0 ; color ; second_start_time time ; second_end_time time ; uniq code
@shift_patterns soft | Patrones de rotación de turnos.
  code ; name ; cycle_length smallint! ; pattern j! ; uniq code
@shift_assignments lock | Turno planificado por colaborador y día (calendario de turnos, asignación masiva).
  employment_id -> employments ; work_date date! ; shift_id ?-> shifts ; is_rest_day ; site_id ?-> sites
  shift_pattern_id ?-> shift_patterns ; status enum(planned,published,changed,cancelled)=planned
  assigned_by_user_id ?-> users ; notes ; uniq employment_id, work_date
@shift_swap_requests lock | Intercambio de turno entre dos colaboradores, con aprobación.
  requester_employment_id -> employments ; counterpart_employment_id -> employments
  requester_assignment_id -> shift_assignments ; counterpart_assignment_id ?-> shift_assignments
  status enum(pending,accepted,approved,rejected,cancelled)=pending ; workflow_instance_id ?-> workflow_instances
@employment_work_schedules lock | Horario del trabajador con vigencia (C-03).
  employment_id -> employments ; work_schedule_id ?-> work_schedules ; shift_pattern_id ?-> shift_patterns
  attendance_exempt bool=f ; personnel_action_id ?-> personnel_actions ; vig employment_id
@time_policies soft lock | Política de tiempo por empresa, sede o unidad con vigencia: tolerancia, redondeo, sobretiempo, métodos de marcación.
  name ; legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units
  tolerance_minutes smallint ; rounding_minutes smallint ; overtime_requires_approval bool=t
  overtime_compensation enum(pay,time_bank,choice)=pay ; time_bank_expiry_days integer ; night_start time ; night_end time
  min_overtime_minutes smallint ; allowed_punch_methods text[] ; require_geofence bool=f ; require_selfie bool=f
  offline_allowed bool=t ; vigcols

#E VAC | Vacaciones
@vacation_policies soft lock | Política de vacaciones (VAC-1): identidad y alcance por empresa, régimen y categoría laboral. Las reglas viven en vacation_policy_rules con vigencia; la más específica que aplica a la relación manda.
  code ; name ; legal_entity_id ?-> legal_entities ; labor_regime_code ?~> labor_regimes.code ; job_category_id ?-> job_categories
  status enum(active,archived)=active ; uniq code
@vacation_policy_rules lock | Reglas de una política de vacaciones con vigencia (VAC-1): días por año (vacío = los del régimen en los parámetros legales), cómputo calendario o hábil, fraccionamiento mínimo, adelanto, venta, máximo de periodos acumulados y validación de RRHH. Cambiar una regla cierra la fila vigente e inserta otra.
  vacation_policy_id -> vacation_policies ; days_per_year numeric(5,2) ; counts_calendar_days bool=t
  min_days_per_request numeric(5,2) ; allow_advance bool=f ; max_advance_days numeric(5,2)
  allow_sale bool=f ; max_sale_days numeric(5,2) ; max_accumulated_periods smallint ; requires_hr_validation bool=f
  approval_workflow_definition_id ?-> workflow_definitions ; vig vacation_policy_id
@vacation_accrual_periods lock | Récord vacacional por año de servicio (VAC-2): días que corresponden (prorrateados por política y régimen vigentes en el año), fecha en que se ganan y fecha límite de goce.
  employment_id -> employments ; period_start date! ; period_end date! ; entitled_days numeric(6,2)!
  accrued_days numeric(6,2)=0 ; enjoy_by date ; status enum(accruing,earned,overdue,closed)=accruing
  vacation_policy_id ?-> vacation_policies ; entitlement_detail j ; earned_on date ; pending_validation bool=f
  uniq employment_id, period_start
@vacation_requests main soft lock | Solicitud de vacaciones (goce, adelanto o venta) con su flujo de aprobación. VAC-3: days es lo que descuenta del saldo según el cómputo de la política (calendario o hábil, con los feriados de la sede); advance_days es la parte tomada a cuenta del periodo en curso. La exclusión impide dos solicitudes activas solapadas del mismo trabajador.
  employment_id -> employments ; starts_on date! ; ends_on date! ; days numeric(6,2)! ; kind enum(leave,advance,sale)=leave
  status enum(draft,pending,approved,rejected,cancelled,taken,hr_review,in_progress)=draft ; workflow_instance_id ?-> workflow_instances ; notes
  requested_by_user_id ?-> users ; approved_at ; check ends_on >= starts_on ; idx employment_id, starts_on
  calendar_days numeric(6,2) ; advance_days numeric(6,2) ; exception_reason ; submitted_at ts ; decided_at ts ; decision_reason
  cancelled_at ts ; cancelled_by_user_id ?-> users ; cancellation_reason ; started_at ts ; completed_at ts ; idx status, starts_on
  raw EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&) WHERE (deleted_at IS NULL AND kind IN ('leave','advance') AND status IN ('pending','hr_review','approved','in_progress','taken')) DEFERRABLE INITIALLY IMMEDIATE
@vacation_ledger_entries imm | Libro de movimientos de vacaciones, de solo inserción. El saldo es la suma de movimientos (C-11); un error se corrige con un contraasiento. Los días llevan signo: suman el devengo, el saldo inicial y los ajustes a favor; restan la reserva (programadas), el goce, la venta y la liquidación.
  employment_id -> employments ; vacation_accrual_period_id ?-> vacation_accrual_periods
  entry_type enum(accrual,taken,scheduled,sold,adjustment,expired,opening_balance,reversal,settlement)! ; days numeric(6,2)!
  effective_on date! ; vacation_request_id ?-> vacation_requests ; reverses_entry_id ?-> vacation_ledger_entries ; reason
  created_by_user_id ?-> users ; evidence ; idempotency_key ; uniq idempotency_key ; idx employment_id, effective_on
@vacation_schedules lock | Rol anual de vacaciones por empresa y año (VAC-5): RRHH abre la campaña, los jefes proponen fechas, cada trabajador confirma y lo confirmado se convierte en solicitudes aprobadas. Cerrar la campaña impide nuevas propuestas.
  legal_entity_id -> legal_entities ; year smallint! ; name ; status enum(open,closed)=open ; opened_by_user_id ?-> users ; closed_at ts ; uniq legal_entity_id, year
@vacation_schedule_lines lock | Fechas propuestas para un trabajador en el rol anual (VAC-5). Estados solo por acciones: propuesta, confirmada o declinada por el trabajador, convertida en solicitud o retirada.
  vacation_schedule_id -> vacation_schedules ; employment_id -> employments ; starts_on date! ; ends_on date! ; days numeric(6,2)
  status enum(proposed,confirmed,declined,converted,withdrawn)=proposed ; proposed_by_user_id ?-> users ; responded_at ts ; response_note
  vacation_request_id ?-> vacation_requests ; check ends_on >= starts_on ; idx vacation_schedule_id, employment_id
@vacation_agreements soft lock | Acuerdo escrito de vacaciones (VAC-6, DL 713 · DL 1405): fraccionamiento del descanso, adelanto a cuenta del periodo en curso o venta de días (reducción del descanso). Se emite en PDF al legajo, se registra firmado y entonces queda vigente; la venta firmada descuenta el saldo con un movimiento «sold» y da el concepto de planilla. Los límites salen de parámetros legales y de la política.
  employment_id -> employments ; kind enum(fractionation,advance,sale)! ; vacation_accrual_period_id ?-> vacation_accrual_periods ; days numeric(6,2)
  effective_on date! ; status enum(draft,issued,active,cancelled)=draft ; document_id ?-> documents ; signed_at ; signature_origin enum(physical_scan,electronic)
  payroll_concept_code ; ledger_entry_id ?-> vacation_ledger_entries ; created_by_user_id ?-> users ; cancel_reason ; notes ; idx employment_id, kind

#E AUS | Ausencias y permisos
@absence_types soft lock | Tipo de ausencia configurable (descanso médico, licencias, permiso personal, compensación, trabajo remoto…). Las reglas viven en absence_type_rules con vigencia; los del paquete de país (is_system) no se editan.
  code ; name
  category enum(medical_leave,maternity,paternity,personal,bereavement,union,compensatory,remote_work,unpaid,suspension,other)!
  country_code ?~> countries.code ; is_system bool=f ; is_confidential bool=f ; legal_duration_parameter_code
  status enum(active,archived)=active ; uniq code
@absence_type_rules lock | Reglas de un tipo de ausencia con vigencia (AUS-1): goce, unidad, tope por periodo, anticipación, sustento, quién la solicita, flujo de aprobación, efecto en planilla y alcance por empresa o régimen. Cambiar una regla cierra la fila vigente e inserta otra.
  absence_type_id -> absence_types ; is_paid bool=t ; affects_attendance bool=t ; unit enum(days,hours)=days
  max_quantity numeric(8,2) ; max_period enum(event,month,year)=year ; min_notice_days integer
  requires_document bool=f ; document_grace_days integer ; requestable_by enum(employee,manager,hr)=employee
  approval_workflow_definition_id ?-> workflow_definitions ; payroll_concept_code ; statutory_suspension_code
  subsidy_kind enum(none,illness,maternity)=none ; counts_as_worked_for_benefits bool=t ; day_count enum(calendar,working)=calendar
  legal_entity_ids j ; labor_regime_codes j ; vig absence_type_id
@absence_requests main soft lock | Ausencia o permiso solicitado, con días a cargo del empleador y días subsidiados. AUS-2: las fechas laborales (starts_on, ends_on) son la referencia; starts_at y ends_at son el instante en la zona horaria de la empresa (fin exclusivo) y alimentan la exclusión que impide ausencias solapadas del mismo trabajador.
  employment_id -> employments ; absence_type_id -> absence_types ; starts_at ts! ; ends_at ts! ; days numeric(6,2)
  hours numeric(6,2) ; status enum(draft,pending,approved,rejected,cancelled,pending_document,in_progress,completed)=draft ; reason
  workflow_instance_id ?-> workflow_instances ; requested_by_user_id ?-> users ; employer_paid_days numeric(6,2)
  subsidized_days numeric(6,2) ; check ends_at >= starts_at ; idx employment_id, starts_at
  starts_on date ; ends_on date ; start_time time ; end_time time ; calendar_days numeric(6,2)
  registered_directly bool=f ; document_due_on date ; exception_reason ; submitted_at ts ; decided_at ts ; decision_reason
  cancelled_at ts ; cancelled_by_user_id ?-> users ; cancellation_reason ; idx status, starts_on
  legal_extensions j ; return_on date
  check starts_on IS NULL OR ends_on >= starts_on
  raw EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (deleted_at IS NULL AND status IN ('pending','pending_document','approved','in_progress','completed')) DEFERRABLE INITIALLY IMMEDIATE
@absence_medical_certificates soft sens | Certificado médico de un descanso; diagnóstico y número cifrados. AUS-3: uno por ausencia (CITT de EsSalud o certificado particular), con los días que otorga y si el descanso viene de un accidente o enfermedad de trabajo (SCTR).
  absence_request_id -> absence_requests ; certificate_number_enc ; issuer ; diagnosis_enc ; issued_on
  file_id ?-> stored_files ; validated_by_user_id ?-> users ; validated_at
  kind enum(citt,private)=citt ; days_granted integer ; is_work_related bool=f ; uniq absence_request_id
@absence_subsidy_cases soft lock | Expediente de subsidio de un descanso (AUS-3): los días que pasan a subsidio (incapacidad desde el umbral a cargo del empleador o maternidad completa) y el seguimiento del reembolso ante la seguridad social. Estados por acciones: por presentar → presentado → observado / aprobado → reembolsado, o rechazado.
  absence_request_id -> absence_requests ; employment_id -> employments ; kind enum(illness,maternity)!
  status enum(pending_filing,filed,observed,approved,reimbursed,rejected,cancelled)=pending_filing
  subsidized_days numeric(6,2)! ; starts_on date ; ends_on date ; year smallint ; filing_due_on date ; filed_on date
  filing_reference ; amount_requested money ; amount_approved money ; amount_reimbursed money ; currency cur?
  reimbursed_on date ; observation ; decision_reason ; uniq absence_request_id ; idx status, filing_due_on
@absence_period_closings imm | AUS-6 · Resumen de ausencias de un trabajador para un periodo, congelado al cerrar el periodo (solo inserción). Un cambio posterior se informa como diferencia contra el último cierre; cerrar otra vez con cambios agrega otra fila.
  employment_id -> employments ; period_from date! ; period_to date! ; fingerprint text! ; summary j!
  closed_by_user_id ?-> users ; idx employment_id, period_from, period_to

#E BHO | Horas extra y bolsa de horas
@overtime_requests soft lock | Autorización previa de sobretiempo.
  employment_id -> employments ; work_date date! ; planned_minutes integer! ; reason text! ; requested_by_user_id -> users
  status enum(pending,approved,rejected,cancelled,pending_review,reviewed,in_payroll,banked)=pending ; compensation enum(pay,time_bank)=pay
  workflow_instance_id ?-> workflow_instances
@overtime_records lock | Sobretiempo reconocido por día y tramo; va a pago (incidencia de planilla) o a bolsa de horas.
  employment_id -> employments ; attendance_day_id ?-> attendance_days ; overtime_request_id ?-> overtime_requests
  work_date date! ; minutes integer! ; tier enum(tier1,tier2,holiday,rest_day,night)! ; compensation enum(pay,time_bank)!
  status enum(pending,approved,rejected,paid,banked)=pending ; payroll_incident_id ?-> payroll_incidents
  approved_by_user_id ?-> users
@time_bank_entries imm | Libro de la bolsa de horas (E-06), de solo inserción: abonos, usos, vencimientos y ajustes.
  employment_id -> employments ; entry_type enum(credit,debit,expiry,adjustment,payout,reversal)! ; minutes integer!
  effective_on date! ; expires_on ; overtime_record_id ?-> overtime_records ; absence_request_id ?-> absence_requests
  reverses_entry_id ?-> time_bank_entries ; reason ; created_by_user_id ?-> users ; idx employment_id, effective_on

#E TSH | Hojas de tiempo por proyecto
@projects soft lock | Proyectos a los que se imputan horas y gastos (E-13).
  code ; name ; client_name ; legal_entity_id ?-> legal_entities ; cost_center_id ?-> cost_centers
  manager_employment_id ?-> employments ; starts_on ; ends_on ; budget_hours numeric(10,2)
  status enum(planned,active,on_hold,closed)=planned ; is_billable ; uniq code
@project_tasks soft | Tareas o fases de un proyecto.
  project_id -> projects ; code ; name ; is_billable ; status ; uniq project_id, code
@project_members | Miembros de un proyecto y su tarifa de costo.
  project_id -> projects ; employment_id -> employments ; role ; starts_on ; ends_on ; cost_rate money #S ; currency cur?
  uniq project_id, employment_id
@timesheets lock | Hoja de tiempo por colaborador y periodo, con aprobación.
  employment_id -> employments ; period_start date! ; period_end date! ; status enum(open,submitted,approved,rejected,locked)=open
  total_hours numeric(8,2)=0 ; workflow_instance_id ?-> workflow_instances ; submitted_at ; approved_at
  uniq employment_id, period_start
@timesheet_entries lock | Horas registradas por día, proyecto y tarea, con su costo.
  timesheet_id -> timesheets ; project_id -> projects ; project_task_id ?-> project_tasks ; work_date date!
  hours numeric(5,2)! ; notes ; cost_amount money #S ; currency cur?

#F 3 | Nómina Perú

#E NOM | Motor de nómina
@labor_regimes P cat | Regímenes laborales por país (general, microempresa, pequeña empresa, construcción civil, agrario, modalidades formativas) (C-05).
  code ; country_code ~> countries.code ; name ; kind enum(general,micro,small,construction,agrarian,training,public,other)!
  description ; uniq code
@pension_funds P cat | Administradoras y sistemas de pensiones por país.
  code ; country_code ~> countries.code ; name ; kind enum(private,public)! ; statutory_code ; afpnet_code ; status ; uniq code
@pension_fund_rates P cat | Tasas por administradora con vigencia (aporte, comisiones, prima, remuneración máxima asegurable). Se cargan de la fuente oficial cada periodo.
  pension_fund_id => pension_funds ; contribution_rate numeric(7,4) ; commission_flow_rate numeric(7,4)
  commission_mixed_rate numeric(7,4) ; insurance_premium_rate numeric(7,4) ; max_insurable_remuneration money ; currency cur?
  source ; verified bool=f ; vig pension_fund_id
@health_providers soft | Entidades de salud y aseguradoras con las que trabaja la organización (EPS, aseguradoras).
  code ; name ; kind enum(eps,insurer,public)! ; tax_id ; uniq code
@legal_entity_regimes lock | Régimen laboral de la empresa con vigencia (p. ej. acreditación como micro o pequeña empresa).
  legal_entity_id -> legal_entities ; labor_regime_code ~> labor_regimes.code ; registry_number ; registered_on ; notes
  accreditation_status enum(not_required,pending,accredited,in_grace_period,lost)=not_required
  accreditation_document_id ?-> documents ; grace_period_ends_on
  vig legal_entity_id
@legal_entity_annual_figures lock | Ventas netas anuales (y su equivalente en UIT del ejercicio) y dotación promedio por razón social: umbrales MYPE y tramos del aporte agrario.
  legal_entity_id -> legal_entities ; fiscal_year smallint! ; net_sales numeric(16,2)! ; currency cur ; uit_value money!
  sales_in_uit numeric(14,4) GENERATED ALWAYS AS (round(net_sales / uit_value, 4)) STORED ; average_headcount numeric(10,2)
  source_document_id ?-> documents ; uniq legal_entity_id, fiscal_year ; check net_sales >= 0 AND uit_value > 0
@pe_construction_works soft lock | Obra de construcción civil; special_regime_applies se evalúa con el costo de obra en UIT frente al parámetro del régimen.
  legal_entity_id -> legal_entities ; site_id ?-> sites ; code ; name ; budget_amount numeric(16,2) ; currency cur?
  budget_in_uit numeric(14,4) ; special_regime_applies boolean ; starts_on date! ; ends_on
  status enum(planned,in_progress,suspended,finished)=planned ; uniq legal_entity_id, code ; check ends_on IS NULL OR ends_on >= starts_on
@pe_construction_assignments lock | Categoría (operario, oficial, peón) y especialidad del trabajador en una obra, con vigencia; define el jornal y las bonificaciones del convenio.
  employment_id -> employments ; construction_work_id -> pe_construction_works ; category enum(operario,oficial,peon)! ; specialty_code
  check specialty_code IS NULL OR category = 'operario' ; vig employment_id
@payroll_groups main soft lock | Grupo de planilla: empresa, frecuencia de pago, régimen y moneda.
  code ; name ; legal_entity_id -> legal_entities ; pay_frequency enum(monthly,biweekly,weekly)=monthly
  labor_regime_code ?~> labor_regimes.code ; currency cur ; payment_day smallint ; cutoff_day smallint ; status ; uniq code
@payroll_group_members lock | Pertenencia del trabajador a un grupo de planilla, con vigencia.
  employment_id -> employments ; payroll_group_id -> payroll_groups ; vig employment_id

#E CPT | Conceptos y fórmulas
@payroll_concepts main soft lock | Concepto de planilla (ingreso, descuento, aporte, impuesto, provisión). Los personalizados conviven con los del paquete de país.
  code ; name ; kind enum(earning,deduction,employee_contribution,employer_contribution,tax,benefit,provision,informative)!
  category ; country_code ; statutory_code ; is_system ; is_remunerative bool=t
  calculation_method enum(fixed,formula,input,percentage,table)=input ; print_on_payslip bool=t ; sort_order
  status enum(draft,active,inactive)=active ; pack_version ; statutory_code_verified bool=f ; created_by_user_id ?-> users
  archived_at ; archive_reason ; uniq code
@payroll_concept_versions lock | Versión con vigencia de un concepto: fórmula, condición de aplicación, redondeo y regímenes a los que aplica. Publicar cierra la vigente e inserta otra (RN-CPT-01); la anterior no cambia.
  payroll_concept_id -> payroll_concepts ; formula ; condition ; conditions j ; rounding enum(none,half_up,down,up)=half_up
  applies_to_regimes text[] ; applies_to_period_kinds text[] ; parameters_used text[] ; depends_on text[] ; notes
  edited_by_user_id ?-> users ; published_by_user_id ?-> users ; published_at ; vig payroll_concept_id
@payroll_concept_drafts lock | CPT-2 · Borrador de la próxima versión de un concepto (uno por concepto): se edita, se prueba y se publica; no entra al cálculo hasta publicarse.
  payroll_concept_id -> payroll_concepts ; formula ; condition ; rounding enum(none,half_up,down,up)=half_up
  applies_to_regimes text[] ; applies_to_period_kinds text[] ; affectations j=[] ; valid_from date! ; notes
  validation j ; edited_by_user_id ?-> users ; uniq payroll_concept_id
@payroll_concept_affectations | Afectaciones de una versión de concepto: a qué bases suma (salud, pensión, renta, CTS, gratificación, vacaciones, utilidades). justification: sustento de una afectación de un concepto no remunerativo (RN-CPT-06).
  payroll_concept_version_id -> payroll_concept_versions ; base_code text! ; affects bool=t
  projection_kind enum(ordinary,gratification,extraordinary) ; justification
  uniq payroll_concept_version_id, base_code

#E PAR | Parámetros legales con vigencia
@legal_parameters P cat main | Parámetro legal global con vigencia por país y régimen (tasas, topes, plazos). Nunca constantes en código.
  country_code ~> countries.code ; labor_regime_code ?~> labor_regimes.code ; code ; name ; value_numeric numeric(18,6)
  value_json j ; unit enum(amount,percent,days,business_days,months,years,hours,count,multiple,text,scale)! ; currency cur?
  valid_from date! ; valid_to date ; legal_source ; verified bool=f ; notes
  check valid_to IS NULL OR valid_to >= valid_from
  raw EXCLUDE USING gist (country_code WITH =, (COALESCE(labor_regime_code, '')) WITH =, code WITH =, daterange(valid_from, valid_to, '[]') WITH &&)
@legal_parameter_overrides lock | Valor propio de la organización o de una empresa para un parámetro legal (p. ej. tasa de seguro de riesgo), con vigencia y motivo.
  legal_entity_id ?-> legal_entities ; parameter_code text! ; value_numeric numeric(18,6) ; value_json j ; reason text!
  approved_by_user_id ?-> users ; vigcols
  raw EXCLUDE USING gist (tenant_id WITH =, parameter_code WITH =, (COALESCE(legal_entity_id, '00000000-0000-0000-0000-000000000000'::uuid)) WITH =, daterange(valid_from, valid_to, '[]') WITH &&)

@legal_parameter_publications P | PAR-4 · Actualización normativa preparada en la consola: cambios de parámetros legales de un país con su norma; la publica una segunda persona del equipo del producto (ADR 0023). Publicar inserta las vigencias nuevas y cierra las anteriores, nunca las modifica.
  country_code ~> countries.code ; title text! ; summary ; legal_source text! ; changes j! ; status enum(draft,in_review,published,rejected)=draft
  prepared_by_platform_user_id ?=> platform_users ; reviewed_by_platform_user_id ?=> platform_users ; review_note ; submitted_at ts ; published_at ts
  idx country_code, status
@legal_parameter_changes P cat | PAR-7 · Registro de novedades normativas: cada vigencia nueva o valor completado de un parámetro legal, con el valor anterior y el nuevo, la norma y su origen (publicación de la consola o paquete de país). Lo leen las organizaciones del país.
  legal_parameter_publication_id ?=> legal_parameter_publications ; country_code ~> countries.code ; labor_regime_code ?~> labor_regimes.code
  code text! ; name ; unit text! ; previous_value j ; new_value j ; valid_from date! ; valid_to date ; legal_source
  origin enum(publication,country_pack)! ; published_at ts=now ; idx country_code, published_at ; idx code
@legal_parameter_confirmations imm | PAR-1 · RN-PAR-03 · Confirmación expresa de la organización de un parámetro legal «por validar» antes de cerrar un periodo que lo usa (solo inserción).
  legal_parameter_id uuid! ; parameter_code text! ; confirmed_by_user_id -> users ; note ; idx legal_parameter_id
@wage_table_verifications imm | PAR-6 · RN-PAR-10 · Verificación de la organización de una tabla salarial por convenio (lote de parámetros con la misma vigencia) contra el acta (solo inserción).
  table_code text! ; labor_regime_code ~> labor_regimes.code ; valid_from date! ; verified_by_user_id -> users ; acta_reference text! ; note
  uniq table_code, valid_from

#E PER | Periodos, incidencias, cálculo, simulación y cierre
@payroll_periods main lock | Periodo de planilla de un grupo, con su máquina de estados hasta el cierre, pago y contabilización.
  payroll_group_id -> payroll_groups ; period_year smallint! ; period_number smallint!
  kind enum(regular,gratification,cts,profit_sharing,settlement,special)=regular ; starts_on date! ; ends_on date!
  payment_date date ; attendance_from date ; attendance_to date ; statutory_period_code
  check statutory_period_code IS NULL OR statutory_period_code ~ '^[0-9]{4}(0[1-9]|1[0-2])$' ; idx payroll_group_id, statutory_period_code
  status enum(open,incidents_closed,calculated,in_review,approved,closed,paid,accounted)=open ; closed_at
  closed_by_user_id ?-> users ; uniq payroll_group_id, kind, period_year, period_number
  opened_by_user_id ?-> users ; submitted_by_user_id ?-> users ; submitted_at ; approved_by_user_id ?-> users ; approved_at
  return_reason ; reopen_reason ; time_consumed_at ; time_consumed_by_user_id ?-> users
  complements_period_id ?-> payroll_periods ; paid_at ; paid_by_user_id ?-> users ; payment_method
@payroll_incidents main soft lock | Incidencia o novedad del periodo: valores variables por trabajador y concepto, con su origen (asistencia, ausencia, préstamo, retroactivo...).
  payroll_period_id -> payroll_periods ; employment_id -> employments ; payroll_concept_id -> payroll_concepts
  quantity numeric(12,4) ; rate numeric(14,6) ; amount money ; currency cur?
  source enum(manual,import,attendance,absence,overtime,loan,advance,expense,retroactive,benefit,union,system)=manual
  source_type ; source_id uuid ; retro_period_id ?-> payroll_periods ; notes
  status enum(pending,approved,applied,rejected)=pending
  created_by_user_id ?-> users ; updated_by_user_id ?-> users ; decided_by_user_id ?-> users ; decided_at ; decision_reason
  idx payroll_period_id, employment_id
@payroll_period_time_cutoffs imm | PER-3 · Corte de tiempo de un trabajador para un periodo: resumen de ausencias y vacaciones tomado por la planilla (solo inserción). Permite reproducir el cálculo y detectar lo ocurrido después del corte.
  payroll_period_id -> payroll_periods ; employment_id -> employments ; period_from date! ; period_to date! ; fingerprint text! ; summary j!
  consumed_by_user_id ?-> users ; idx payroll_period_id, employment_id
@payroll_opening_balances soft lock sens | PER-12 · Acumulados del ejercicio traídos del sistema anterior (organización que empieza a mitad de año): saldo de apertura por trabajador con fecha de corte y sustento, nunca boletas inventadas. Lo leen la proyección de quinta (ingresos y retenciones previas) y la gratificación y CTS del semestre en curso. Se revierte con su importación mientras ningún periodo cerrado del ejercicio lo use.
  employment_id -> employments ; fiscal_year smallint! ; cutoff_date date! ; period_month smallint
  kind enum(fifth_income,fifth_withheld,pension_contribution,health_contribution,gratification_base,cts_base,cts_deposit)!
  semester smallint ; concept_code ; amount money! #S ; currency cur ; evidence text! ; import_job_id ?-> import_jobs ; created_by_user_id ?-> users
  idx employment_id, fiscal_year ; check period_month IS NULL OR period_month BETWEEN 1 AND 12 ; check semester IS NULL OR semester IN (1, 2)
@payroll_runs main lock | Corrida de cálculo o simulación de un periodo; guarda la versión del motor y los parámetros usados. Cerrada, no se modifica. NOM-8: scope (todas, relaciones o grupo calculado), avance (total, hechas, con error) y motivo si falló; se reanuda sin repetir lo hecho.
  payroll_period_id -> payroll_periods ; run_number integer! ; kind enum(simulation,calculation,recalculation)=calculation
  status enum(queued,running,completed,failed,discarded,approved,closed)=queued ; engine_version ; parameters_snapshot j
  started_at ; finished_at ; requested_by_user_id ?-> users ; approved_by_user_id ?-> users ; closed_at ; totals j
  compared_to_run_id ?-> payroll_runs ; scope j ; employments_total integer=0 ; employments_done integer=0 ; employments_failed integer=0
  failure_reason ; uniq payroll_period_id, run_number
@payroll_run_employments sens | Resultado por trabajador de una corrida: totales y fotografía de la asignación, compensación y régimen usados.
  payroll_run_id -> payroll_runs ; employment_id -> employments ; status enum(calculated,error,excluded)=calculated
  gross_amount money #S ; deductions_amount money #S ; net_amount money #S ; employer_cost money #S ; currency cur
  worked_days numeric(6,2) ; context_snapshot j #S ; error ; uniq payroll_run_id, employment_id
@payroll_run_lines main sens | Línea de cálculo por concepto con su traza (fórmula, insumos y parámetros aplicados).
  payroll_run_id -> payroll_runs ; payroll_run_employment_id -> payroll_run_employments
  payroll_concept_id -> payroll_concepts ; payroll_concept_version_id ?-> payroll_concept_versions ; quantity numeric(12,4)
  rate numeric(14,6) ; base_amount money ; amount money! #S ; currency cur
  origin enum(calculated,incident,manual,retroactive,carryover)=calculated ; payroll_incident_id ?-> payroll_incidents
  cost_center_id ?-> cost_centers ; trace j!
@payroll_run_anomalies lock | Anomalía detectada al comparar con el periodo anterior, siempre con explicación (p. ej. «el neto cayó 32 %»).
  payroll_run_id -> payroll_runs ; employment_id ?-> employments ; rule_code text! ; severity enum(info,warning,critical)!
  explanation text! ; previous_value numeric(14,2) ; current_value numeric(14,2) ; variation_pct numeric(9,4)
  status enum(open,reviewed,dismissed)=open ; reviewed_by_user_id ?-> users ; review_note
  payroll_concept_id ?-> payroll_concepts ; causes j ; reviewed_at ; idx payroll_run_id, status
@payroll_variance_thresholds soft lock | PER-7 · RN-PER-05 · Umbral de variación (%) contra el periodo anterior por empresa y concepto; sin concepto aplica al neto. Sin fila rige el parámetro nomina.variacion_neto_alerta_pct.
  legal_entity_id ?-> legal_entities ; payroll_concept_id ?-> payroll_concepts ; threshold_pct numeric(7,2)! ; uniq legal_entity_id, payroll_concept_id
@payroll_payment_batches lock | Lote de pago (archivo bancario o billetera) de haberes, adelantos, CTS, liquidaciones, locadores o gastos.
  payroll_period_id ?-> payroll_periods ; legal_entity_id -> legal_entities
  kind enum(salary,advance,cts,settlement,contractor,profit_sharing,expense)! ; channel enum(bank_file,wallet,cash,check)!
  bank_code ; status enum(draft,generated,sent,confirmed,rejected,cancelled)=draft ; total_amount money ; currency cur
  file_id ?-> stored_files ; payment_date date ; external_reference
  file_format ; generated_by_user_id ?-> users ; generated_at ; confirmed_by_user_id ?-> users ; confirmed_at ; cancel_reason ; items_count integer=0
@payroll_payment_items sens | Pago individual dentro de un lote.
  payroll_payment_batch_id -> payroll_payment_batches ; employment_id ?-> employments
  person_bank_account_id ?-> person_bank_accounts ; amount money! #S ; currency cur
  status enum(pending,paid,rejected,returned)=pending ; payslip_id ?-> payslips ; reference ; rejection_reason

#E BOL | Boletas
@payslips main lock sens | Boleta de pago. Generada, su contenido, montos y archivo no cambian ni se borra; una corrección la anula y genera otra versión que la referencia (RN-BOL-04). BOL-1 a BOL-5: estados por acciones (ME-20), documento del legajo y conservación por parámetro legal.
  payroll_run_employment_id ?-> payroll_run_employments ; payroll_period_id -> payroll_periods ; employment_id -> employments
  legal_entity_id -> legal_entities ; number text! ; kind enum(regular,gratification,cts,profit_sharing,settlement,rectification)=regular
  status enum(generated,published,viewed,acknowledged,voided)=generated ; gross_amount money #S ; deductions_amount money #S ; net_amount money #S
  employer_contributions money #S ; currency cur ; content j! #S ; file_id ?-> stored_files ; content_hash ; closed_at
  rectifies_payslip_id ?-> payslips ; uniq legal_entity_id, number ; idx employment_id, payroll_period_id
  version integer=1 ; document_id ?-> documents ; requires_acknowledgement bool=f ; is_imported bool=f
  published_at ; voided_at ; void_reason ; voided_by_user_id ?-> users ; generated_by_user_id ?-> users
@payslip_deliveries | Entrega de la boleta al trabajador: publicación, primera vista, descarga y acuse; o entrega en físico con cargo escaneado (RN-BOL-07).
  payslip_id -> payslips ; employment_id -> employments ; channel enum(portal,email,whatsapp,printed)=portal
  status enum(published,viewed,acknowledged)=published ; published_at
  first_viewed_at ; downloaded_at ; acknowledged_at ; signature_request_id ?-> signature_requests ; uniq payslip_id, channel
  first_viewed_ip ; acknowledged_ip ; acknowledged_user_agent ; last_reminded_at ; delivered_by_user_id ?-> users ; evidence_document_id ?-> documents ; note

#E BSO | Beneficios sociales, seguros y derechohabientes
@cts_deposits lock sens | Depósito semestral de CTS por trabajador: remuneración computable, monto, entidad depositaria y constancia.
  employment_id -> employments ; period_code text! ; computable_remuneration money #S ; sixth_of_bonus money
  computable_months smallint ; computable_days smallint ; amount money! #S ; currency cur ; deposit_bank_code
  person_bank_account_id ?-> person_bank_accounts ; due_on ; deposited_on
  status enum(calculated,approved,deposited,notified)=calculated ; payroll_run_employment_id ?-> payroll_run_employments
  statement_document_id ?-> documents ; uniq employment_id, period_code
  payroll_period_id ?-> payroll_periods ; deposit_reference ; deposited_by_user_id ?-> users
@profit_sharing_distributions lock | Distribución anual de utilidades por empresa (E-21).
  legal_entity_id -> legal_entities ; fiscal_year smallint! ; net_income_before_tax money #S ; participation_rate numeric(7,4)
  total_amount money ; currency cur ; total_days_worked numeric(12,2) ; total_remunerations numeric(16,2)
  status enum(draft,calculated,approved,paid)=draft ; due_on ; uniq legal_entity_id, fiscal_year
  activity enum(industria,comercio_restaurantes,otras_actividades)=otras_actividades ; workers_count integer ; calculated_at ; warnings j
  created_by_user_id ?-> users ; approved_by_user_id ?-> users ; approved_at ; excess_amount money
@profit_sharing_items sens | Liquidación individual de utilidades: parte por días y parte por remuneraciones.
  profit_sharing_distribution_id -> profit_sharing_distributions ; employment_id -> employments ; days_worked numeric(8,2)
  remunerations numeric(14,2) #S ; amount_by_days money ; amount_by_remuneration money ; amount money! #S ; cap_applied bool=f
  currency cur ; settlement_document_id ?-> documents ; uniq profit_sharing_distribution_id, employment_id
@insurance_policies soft lock | Pólizas del empleador (seguro de vida de ley, seguro de riesgo, plan de salud) con vigencia y registro (E-19).
  legal_entity_id -> legal_entities ; kind enum(life,risk_health,risk_pension,health_plan,other)! ; insurer text!
  policy_number text! ; starts_on date! ; ends_on ; registered_with_authority_on ; premium_rate numeric(7,4)
  health_provider_id ?-> health_providers ; document_id ?-> documents ; status enum(active,expired,cancelled)=active
@insurance_policy_members | Trabajadores cubiertos por una póliza y periodo de cobertura.
  insurance_policy_id -> insurance_policies ; employment_id -> employments ; covered_from date! ; covered_to date ; plan_code
  employee_contribution money ; currency cur?
@insurance_beneficiaries soft sens | Beneficiarios declarados por el trabajador para el seguro de vida (COL-7). Cada declaración (declaration_id) agrupa sus beneficiarios con el archivo firmado; una declaración nueva deja la anterior como superseded.
  employment_id -> employments ; insurance_policy_member_id ?-> insurance_policy_members ; full_name_enc text!
  document_number_enc ; relationship ; percentage numeric(5,2) ; declared_on ; declaration_document_id ?-> documents
  declaration_id uuid! ; declaration_file_id ?-> stored_files ; signed_on date ; status enum(current,superseded)=current
  check percentage IS NULL OR (percentage > 0 AND percentage <= 100)

#E PRE | Préstamos, adelantos y adelanto de sueldo
@loans main soft lock sens | Préstamo al trabajador con cronograma y descuento automático en planilla.
  employment_id -> employments ; number text! ; principal money! #S ; interest_rate numeric(7,4)=0 ; currency cur
  installments_count smallint! ; first_due_on date
  status enum(requested,approved,rejected,active,paid,cancelled,settled_on_termination,disbursed,refinanced)=requested ; balance money #S
  payroll_concept_id ?-> payroll_concepts ; workflow_instance_id ?-> workflow_instances ; approved_by_user_id ?-> users
  disbursed_on ; document_id ?-> documents ; uniq number
@loan_installments lock | Cuotas del préstamo y su descuento en un periodo.
  loan_id -> loans ; installment_number smallint! ; due_on date! ; amount money! ; principal_part money ; interest_part money
  currency cur ; status enum(pending,scheduled,deducted,paid_direct,skipped,cancelled)=pending
  payroll_period_id ?-> payroll_periods ; payroll_incident_id ?-> payroll_incidents ; uniq loan_id, installment_number
@advance_policies soft lock | Política de adelantos con vigencia: tope sobre lo devengado, número de solicitudes, antigüedad mínima, canales de pago (E-09).
  name ; legal_entity_id ?-> legal_entities ; max_percentage_of_earned numeric(5,2) ; max_amount money ; currency cur?
  max_requests_per_period smallint ; min_tenure_days integer ; requires_approval bool=f ; payout_channels text[] ; vigcols
@salary_advances soft lock sens | Adelanto programado o adelanto de sueldo sobre lo ya devengado (E-09); se descuenta solo en la planilla.
  employment_id -> employments ; payroll_period_id ?-> payroll_periods ; advance_policy_id ?-> advance_policies
  kind enum(scheduled_advance,earned_wage)=earned_wage ; requested_amount money! ; approved_amount money
  earned_to_date money #S ; currency cur ; status enum(requested,approved,rejected,paid,deducted,cancelled)=requested
  payout_channel enum(bank,wallet)=bank ; payroll_payment_item_id ?-> payroll_payment_items
  payroll_incident_id ?-> payroll_incidents ; requested_at ts=now ; paid_at
@judicial_withholdings soft lock sens | Retenciones por mandato judicial (alimentos, embargos); datos del expediente y del beneficiario cifrados.
  employment_id -> employments ; court_reference_enc ; kind enum(alimony,garnishment,other)!
  calculation enum(fixed,percentage_net,percentage_gross)! ; amount money ; percentage numeric(7,4) ; currency cur?
  beneficiary_name_enc ; beneficiary_account_enc ; starts_on date! ; ends_on ; payroll_concept_id ?-> payroll_concepts
  document_id ?-> documents ; status enum(active,suspended,ended)=active

#E DEC | Declaraciones y archivos
@statutory_filings main lock | Declaración o archivo oficial por empresa y periodo (planilla electrónica, registro laboral, aportes previsionales), con validación previa y rectificatorias.
  legal_entity_id -> legal_entities ; kind enum(plame,t_registro,afpnet,cts_report,profit_sharing,withholding_certificates,other)!
  period_code text! ; payroll_period_id ?-> payroll_periods ; is_rectification ; rectifies_filing_id ?-> statutory_filings
  status enum(draft,generated,validated,with_errors,submitted,accepted,rejected,observed,rectified)=draft ; validation_report j ; submitted_at
  submitted_by_user_id ?-> users ; external_reference ; due_on
  created_by_user_id ?-> users ; accepted_at ; observation ; rectification_reason ; submission_document_id ?-> documents
@statutory_filing_files | Archivos generados de una declaración.
  statutory_filing_id -> statutory_filings ; file_kind text! ; stored_file_id -> stored_files ; record_count integer ; checksum
  statutory_file_layout_id ?=> statutory_file_layouts ; file_name ; sha256 ; byte_size bigint ; control_totals j ; generator_version
  generated_by_user_id ?-> users ; supersedes_file_id ?-> statutory_filing_files
  check sha256 IS NULL OR sha256 ~ '^[0-9a-f]{64}$' ; check byte_size IS NULL OR byte_size >= 0
@statutory_file_layouts P cat | Estructura campo por campo de cada archivo oficial, con vigencia. fields = lista de campos con número, nombre, tipo, longitud máxima, decimales, obligatoriedad y tabla paramétrica.
  country_code ~> countries.code ; filing_kind text! ; structure_code text! ; name ; file_name_pattern text! ; file_extension
  layout_version text! ; field_separator ; encoding text=ISO-8859-1 ; line_ending enum(CRLF,LF)=CRLF ; has_header bool=f
  trailing_separator bool=t ; fields j! ; source ; verified bool=f
  check jsonb_typeof(fields) = 'array' AND jsonb_array_length(fields) > 0 ; vig country_code, filing_kind, structure_code
@registry_events lock | Altas, bajas y modificaciones de trabajadores, practicantes y derechohabientes pendientes ante el registro laboral, con plazo.
  legal_entity_id -> legal_entities ; employment_id ?-> employments ; dependent_id ?-> dependents
  kind enum(worker_registration,worker_termination,worker_update,dependent_registration,dependent_termination,dependent_update,establishment_update,period_update,intern_registration)!
  event_date date! ; due_on ; status enum(pending,included,submitted,confirmed,overdue)=pending
  statutory_filing_id ?-> statutory_filings ; confirmation_document_id ?-> documents
@bank_file_templates lock | DEC-5 · Plantilla de archivo de pago de un banco (haberes, CTS y otros) definida como datos: separador o ancho fijo, cabecera, detalle y validación de cuentas. Las del paquete de país quedan por validar con cada banco.
  code text! ; bank_code text! ; name text! ; kind enum(salary,cts,other)=salary ; definition j! ; verified bool=f
  status enum(active,inactive)=active ; source ; deleted_at ; uniq code
  check jsonb_typeof(definition) = 'object'
@concept_statutory_mappings | Mapeo de conceptos de planilla a códigos oficiales por tipo de declaración.
  payroll_concept_id -> payroll_concepts ; filing_kind text! ; statutory_code text! ; column_hint
  amount_role enum(accrued_and_paid,accrued_only,paid_only)=accrued_and_paid ; verified bool=f ; verified_by_user_id ?-> users
  vig payroll_concept_id, filing_kind
@external_income_declarations lock sens | Ingresos y retenciones de otros empleadores declarados por el trabajador (renta de quinta categoría).
  employment_id -> employments ; fiscal_year smallint! ; employer_tax_id ; employer_name ; gross_income money #S
  tax_withheld money #S ; currency cur ; document_id ?-> documents ; declared_on
  kind enum(previous_employer,concurrent_employer)=previous_employer ; period_from date ; period_to date ; exempt_income money #S
  monthly_detail j #S ; this_employer_withholds boolean ; status enum(received,validated,superseded,rejected)=received
  superseded_by_id ?-> external_income_declarations ; received_on
  check period_to IS NULL OR period_from IS NULL OR period_to >= period_from ; check employer_tax_id IS NULL OR employer_tax_id ~ '^[0-9]{11}$'
@withholding_certificates sens | Certificado de rentas y retenciones por trabajador y ejercicio: al cierre, al cese o a solicitud (puede haber más de uno).
  employment_id -> employments ; fiscal_year smallint! ; total_income money #S ; total_withheld money #S ; currency cur
  document_id ?-> documents ; issued_on ; reason enum(annual,termination,on_request)=on_request ; period_from date ; period_to date
  exempt_income money #S ; external_income money #S ; deduction_amount money ; annual_tax money #S ; refunded_amount money #S ; content_hash
  check content_hash IS NULL OR content_hash ~ '^[0-9a-f]{64}$' ; uniq employment_id, fiscal_year, reason, issued_on
@fifth_category_ledger imm sens | Libro de retenciones de quinta categoría por relación y ejercicio. Solo inserción: un recálculo es una fila nueva (sequence).
  employment_id -> employments ; fiscal_year smallint! ; period_month smallint! ; sequence smallint=1
  entry_kind enum(monthly,final_adjustment,refund,prior_employer,correction)! ; payroll_run_id ?-> payroll_runs
  ordinary_remuneration money=0 #S ; remaining_months smallint=0 ; projected_gratifications money=0 #S ; prior_income money=0 #S
  external_income money=0 #S ; extraordinary_income money=0 #S ; gross_annual_income money=0 #S ; deduction_amount money=0
  net_annual_income money=0 #S ; annual_tax money=0 #S ; credits money=0 ; prior_withholdings_deducted money=0 ; divisor smallint
  ordinary_withholding money=0 ; additional_withholding money=0 ; withholding_amount money! #S ; currency cur ; uit_value money!
  parameters j! ; trace j! ; engine_version text! ; created_by_user_id ?-> users
  uniq employment_id, fiscal_year, period_month, entry_kind, sequence ; idx employment_id, fiscal_year, period_month
  check period_month BETWEEN 1 AND 12 ; check remaining_months BETWEEN 0 AND 12 ; check divisor IS NULL OR divisor BETWEEN 1 AND 12
  check withholding_amount >= 0 OR entry_kind IN ('final_adjustment','refund','correction')
@fifth_category_refunds lock sens | Excesos de retención de quinta devueltos al trabajador y su compensación contra las retenciones del periodo o su devolución ante la administración tributaria.
  employment_id -> employments ; fiscal_year smallint! ; origin enum(termination,december_adjustment,worker_declaration)!
  amount money! #S ; currency cur ; refunded_on ; payroll_run_id ?-> payroll_runs ; compensated_amount money=0
  compensation_period_code ; authority_refund_reference ; worker_declaration_document_id ?-> documents
  status enum(pending,refunded,compensated,authority_requested,closed)=pending ; idx employment_id, fiscal_year
  check amount > 0 ; check compensated_amount >= 0 AND compensated_amount <= amount

#E PRV | Provisiones y asientos
@accounting_accounts soft | Cuentas contables usadas en la centralización de planilla.
  legal_entity_id ?-> legal_entities ; code ; name ; kind enum(expense,liability,asset,other)!
  raw UNIQUE NULLS NOT DISTINCT (tenant_id, legal_entity_id, code)
@concept_account_mappings lock | Contabilización de un concepto: cuentas de debe y haber por empresa y centro de costo.
  payroll_concept_id -> payroll_concepts ; legal_entity_id ?-> legal_entities ; cost_center_id ?-> cost_centers
  worker_category enum(executive,employee,worker) ; debit_account_id ?-> accounting_accounts ; credit_account_id ?-> accounting_accounts ; vigcols
@provision_runs lock | Cálculo mensual de provisiones (CTS, gratificaciones, vacaciones) por empresa (E-03).
  legal_entity_id -> legal_entities ; payroll_period_id ?-> payroll_periods ; period_code text!
  status enum(draft,calculated,approved,posted)=draft ; calculated_at
@provision_lines sens | Provisión por trabajador y tipo, con base, monto del mes, acumulado y traza.
  provision_run_id -> provision_runs ; employment_id -> employments
  kind enum(cts,gratification,vacation,extraordinary_bonus,other)! ; base_amount money #S ; amount money! #S
  accumulated_amount money #S ; currency cur ; cost_center_id ?-> cost_centers ; trace j
@journal_entries main lock | Asiento contable generado desde planilla, provisiones, liquidaciones, locadores o gastos.
  legal_entity_id -> legal_entities ; source enum(payroll,provision,settlement,contractor,expense,manual)!
  payroll_period_id ?-> payroll_periods ; provision_run_id ?-> provision_runs ; period_code text! ; entry_date date!
  description ; status enum(draft,approved,exported,voided)=draft ; total_debit money ; total_credit money ; currency cur
@journal_entry_lines | Líneas del asiento por cuenta y centro de costo.
  journal_entry_id -> journal_entries ; accounting_account_id ?-> accounting_accounts ; account_code text!
  cost_center_id ?-> cost_centers ; debit money=0 ; credit money=0 ; currency cur ; description
  payroll_concept_id ?-> payroll_concepts ; employment_id ?-> employments
@accounting_exports | Exportación de asientos al ERP o a archivo contable.
  legal_entity_id -> legal_entities ; journal_entry_id ?-> journal_entries ; format text! ; integration_id ?-> integrations
  stored_file_id ?-> stored_files ; status enum(generated,sent,acknowledged,failed)=generated
  exported_by_user_id ?-> users ; external_reference ; error

#E LIQ | Liquidación de beneficios
@settlements main soft lock sens | Liquidación de beneficios sociales al cese o su simulación (E-04). LIQ-1 a LIQ-4: inputs guarda los descuentos ingresados; totals, periods, errors y warnings el resultado del motor con tipo de periodo «settlement»; due_alerted_at recuerda el aviso de plazo de pago; cada cálculo suma calculation_number y sus líneas lo llevan (las de cálculos anteriores se conservan).
  employment_id -> employments ; termination_id ?-> terminations ; is_simulation ; termination_date date! ; reason_code
  status enum(draft,calculated,approved,paid,voided,pending_approval,documents_delivered)=draft ; total_amount money #S ; currency cur ; due_on ; paid_on
  payroll_run_id ?-> payroll_runs ; parameters_snapshot j ; document_id ?-> documents ; approved_by_user_id ?-> users
  severance_applies bool=f ; include_pending_salary bool=f ; inputs j ; totals j ; periods j ; errors j ; warnings j
  calculated_at ; calculated_by_user_id ?-> users ; submitted_at ; submitted_by_user_id ?-> users ; approved_at ; return_reason
  void_reason ; payment_reference ; paid_by_user_id ?-> users ; due_alerted_at ; documents_delivered_at ; calculation_number integer
@settlement_lines sens | Líneas de la liquidación (truncos, vacaciones no gozadas, indemnización, saldos por descontar), con traza.
  settlement_id -> settlements
  kind enum(cts_truncated,vacation_truncated,vacation_unused,vacation_indemnity,gratification_truncated,extraordinary_bonus,severance_indemnity,pending_salary,loan_balance,advance_balance,other_earning,other_deduction,tax,contribution)!
  payroll_concept_id ?-> payroll_concepts ; base_amount money ; quantity numeric(12,4) ; amount money! #S ; currency cur ; trace j!
  concept_code ; concept_name ; concept_kind ; explanation ; calculation_number integer
@termination_documents | Documentos de cese entregados (certificado de trabajo, carta de liberación de CTS, constancia de cese).
  employment_id -> employments ; settlement_id ?-> settlements
  kind enum(work_certificate,cts_release_letter,termination_record,settlement_statement,withholding_certificate)!
  document_id -> documents ; delivered_at ; delivery_method
  title ; status enum(generated,pending_signature,signed,declined)=generated ; signature_request_id ?-> signature_requests ; signed_at

#E LOC | Locadores
@contractors main soft lock | Prestador de servicios independiente (E-18), separado de la planilla.
  person_id ?-> people ; legal_entity_id -> legal_entities ; business_name ; tax_id_enc text! ; tax_id_hash text!
  service_description ; status ; has_withholding_suspension ; suspension_valid_until date
@contractor_engagements soft lock | Encargo o contrato de locación de servicios.
  contractor_id -> contractors ; title ; starts_on date! ; ends_on ; fee_amount money #S ; currency cur
  fee_basis enum(monthly,per_deliverable,hourly,fixed)=monthly ; org_unit_id ?-> org_units ; cost_center_id ?-> cost_centers
  document_id ?-> documents ; status enum(draft,active,ended,cancelled)=draft
@contractor_invoices lock | Recibos por honorarios del locador, con retención y pago.
  contractor_engagement_id -> contractor_engagements ; invoice_series ; invoice_number text! ; issued_on date! ; period_code
  gross_amount money! ; withholding_amount money=0 ; net_amount money! ; currency cur ; file_id ?-> stored_files
  status enum(received,approved,rejected,paid,voided)=received ; approved_by_user_id ?-> users
  payroll_payment_item_id ?-> payroll_payment_items ; paid_on

#F 4 | Talento

#E ATS | Reclutamiento, portal de empleos y base de talento
@recruitment_pipelines soft | Embudos de selección configurables.
  name ; is_default
@pipeline_stages soft | Etapas de un embudo.
  recruitment_pipeline_id -> recruitment_pipelines ; name
  kind enum(new,screening,interview,assessment,references,offer,hired,rejected,custom)! ; sort_order ; sla_days integer
@job_requisitions main soft lock | Vacante: se abre contra una posición o una solicitud aprobada cuando la política lo exige.
  code ; title ; position_id ?-> positions ; position_request_id ?-> position_requests ; job_profile_id ?-> job_profiles
  org_unit_id ?-> org_units ; site_id ?-> sites ; legal_entity_id ?-> legal_entities
  hiring_manager_user_id ?-> users ; recruiter_user_id ?-> users ; openings smallint=1 ; employment_kind
  work_modality enum(onsite,remote,hybrid) ; salary_min money #S ; salary_max money #S ; currency cur? ; description
  requirements ; benefits_text ; recruitment_pipeline_id ?-> recruitment_pipelines
  status enum(draft,pending_approval,approved,open,on_hold,filled,cancelled,closed)=draft ; is_internal_only
  workflow_instance_id ?-> workflow_instances ; opened_at ; closed_at ; uniq code
@career_sites soft lock | Portal público de empleos configurable, con formulario y metadatos para buscadores.
  slug text! ; legal_entity_id ?-> legal_entities ; title ; intro ; seo j ; branding j ; application_form j ; is_published
  uniq slug
@job_postings soft lock | Publicación de una vacante en un canal (portal propio, interno, bolsa externa, referidos).
  job_requisition_id -> job_requisitions ; career_site_id ?-> career_sites
  channel enum(career_site,internal,job_board,referral)=career_site ; slug ; title ; body ; seo_title ; seo_description
  show_salary bool=f ; published_at ; expires_at ; status enum(draft,published,paused,closed)=draft ; external_reference
@candidates main soft lock | Candidato. Se conserva en la base de talento aunque no sea contratado, con plazo de retención.
  person_id ?-> people ; first_names text! ; surnames text! ; email text #S ; phone text #S ; document_number_enc
  document_number_hash ; location_text
  source enum(career_site,referral,job_board,social_network,agency,internal,import,manual)=manual ; source_detail
  salary_expectation money #S ; currency cur? ; cv_file_id ?-> stored_files ; parsed_profile j ; summary ; is_internal
  employment_id ?-> employments ; consent_id ?-> consents ; retention_until date ; anonymized_at ; idx email
@candidate_experiences | Experiencia laboral del candidato.
  candidate_id -> candidates ; employer ; job_title ; started_on ; finished_on ; description
@candidate_education | Formación del candidato.
  candidate_id -> candidates ; institution ; degree ; level ; finished_on
@applications main soft lock | Postulación de un candidato a una vacante y su etapa actual.
  candidate_id -> candidates ; job_requisition_id -> job_requisitions ; job_posting_id ?-> job_postings
  pipeline_stage_id ?-> pipeline_stages ; status enum(active,hired,rejected,withdrawn,on_hold,new,offer)=new ; rejection_reason_code
  rejection_note ; score numeric(5,2) ; applied_at ts=now ; answers j ; referral_id ?-> referrals
  hired_employment_id ?-> employments ; uniq candidate_id, job_requisition_id
@application_stage_events imm | Historial inalterable de movimientos de una postulación entre etapas.
  application_id -> applications ; from_stage_id ?-> pipeline_stages ; to_stage_id ?-> pipeline_stages
  moved_by_user_id ?-> users ; note ; occurred_at ts=now
@talent_pools soft | Grupos de la base de talento (manuales o por filtro).
  name ; description ; is_dynamic ; filter j
@talent_pool_members | Candidatos de un grupo de talento.
  talent_pool_id -> talent_pools ; candidate_id -> candidates ; added_by_user_id ?-> users ; note
  uniq talent_pool_id, candidate_id
@referrals lock | Referidos por colaboradores (E-14), con su estado y recompensa.
  referrer_employment_id -> employments ; candidate_id ?-> candidates ; job_requisition_id ?-> job_requisitions
  referred_name ; referred_contact_enc ; relationship ; status enum(submitted,in_process,hired,rejected,rewarded)=submitted
  reward_amount money ; currency cur? ; reward_incident_id ?-> payroll_incidents
@background_checks lock sens | Verificaciones del candidato (identidad, referencias, estudios, antecedentes, psicometría) con consentimiento.
  candidate_id -> candidates ; application_id ?-> applications
  kind enum(identity,references,education,criminal_record,credit,psychometric,other)! ; provider
  integration_id ?-> integrations ; status enum(requested,in_progress,completed,failed,cancelled)=requested
  result_summary_enc ; report_file_id ?-> stored_files ; consent_id ?-> consents ; requested_by_user_id ?-> users ; completed_at

#E ENT | Entrevistas y ofertas
@scorecard_templates soft lock | Guía y formulario estructurado de evaluación de entrevistas.
  name ; job_profile_id ?-> job_profiles ; guide
@scorecard_template_criteria | Criterios de un formulario de entrevista.
  scorecard_template_id -> scorecard_templates ; name ; description ; competency_id ?-> competencies ; weight numeric(5,2)
  sort_order
@interviews main soft lock | Entrevista agendada para una postulación.
  application_id -> applications ; pipeline_stage_id ?-> pipeline_stages ; kind enum(hr,technical,panel,cultural,final,other)!
  mode enum(onsite,video,phone)=video ; scheduled_start ts! ; scheduled_end ts! ; location_text ; meeting_url
  scorecard_template_id ?-> scorecard_templates ; status enum(scheduled,completed,cancelled,no_show,rescheduled)=scheduled
  calendar_event_ref ; created_by_user_id ?-> users
@interview_participants | Entrevistadores y observadores.
  interview_id -> interviews ; user_id -> users ; role enum(lead,interviewer,observer)=interviewer
  response enum(pending,accepted,declined)=pending ; uniq interview_id, user_id
@interview_scorecards lock | Evaluación de un entrevistador sobre una entrevista.
  interview_id -> interviews ; interviewer_user_id -> users
  overall_recommendation enum(strong_no,no,neutral,yes,strong_yes) ; overall_score numeric(5,2) ; comments ; submitted_at
  uniq interview_id, interviewer_user_id
@interview_scorecard_ratings | Calificación por criterio.
  interview_scorecard_id -> interview_scorecards ; scorecard_template_criterion_id -> scorecard_template_criteria
  rating numeric(5,2) ; comment ; uniq interview_scorecard_id, scorecard_template_criterion_id
@job_offers main soft lock sens | Oferta laboral generada desde plantilla, con aprobación, envío y respuesta.
  application_id -> applications ; version integer=1 ; job_profile_id ?-> job_profiles ; position_id ?-> positions
  proposed_start_date date ; base_salary money #S ; currency cur ; contract_type_id ?-> contract_types ; benefits_text
  document_template_version_id ?-> document_template_versions ; document_id ?-> documents
  status enum(draft,in_review,approved,sent,accepted,declined,expired,withdrawn,pending_approval)=draft
  workflow_instance_id ?-> workflow_instances ; sent_at ; responded_at ; expires_at ; decline_reason
  signature_request_id ?-> signature_requests

#E ONB | Pre-onboarding y onboarding
@onboarding_templates soft lock | Plantilla de proceso (pre-ingreso, ingreso o salida) por empresa, sede, unidad o puesto.
  name ; kind enum(preboarding,onboarding,offboarding)! ; legal_entity_id ?-> legal_entities ; site_id ?-> sites
  org_unit_id ?-> org_units ; job_profile_id ?-> job_profiles ; is_default ; status enum(draft,active,inactive)=draft
@onboarding_template_tasks | Tareas de una plantilla con desfase en días respecto de la fecha de referencia y responsable.
  onboarding_template_id -> onboarding_templates ; title ; description ; phase_label ; offset_days integer=0
  assignee_kind enum(new_hire,manager,hr,it,buddy,role,user)! ; assignee_role_id ?-> roles
  task_kind enum(task,document_request,document_signature,course,form,asset_delivery,asset_return,access_grant,access_revoke,meeting,survey,review)=task
  config j ; is_required bool=t ; sort_order
@onboarding_processes main lock | Proceso en curso de pre-ingreso, ingreso o salida de una persona, con avance.
  onboarding_template_id ?-> onboarding_templates ; kind enum(preboarding,onboarding,offboarding)!
  employment_id ?-> employments ; application_id ?-> applications ; person_id ?-> people
  buddy_employment_id ?-> employments ; reference_date date! ; status enum(not_started,in_progress,completed,cancelled,preboarding,paused)=not_started
  progress_pct smallint=0 ; completed_at ; termination_id ?-> terminations
@onboarding_tasks lock | Tareas de un proceso, con responsable, vencimiento y vínculo al registro que generan.
  onboarding_process_id -> onboarding_processes ; onboarding_template_task_id ?-> onboarding_template_tasks ; title
  task_kind text! ; assignee_user_id ?-> users ; due_on ; status enum(pending,in_progress,done,skipped,blocked)=pending
  completed_at ; completed_by_user_id ?-> users ; subject_type ; subject_id uuid ; is_required bool=t ; sort_order
@preboarding_submissions lock sens | Datos que el futuro colaborador entrega antes de ingresar (personales, cuenta, contacto), cifrados hasta su validación.
  onboarding_process_id -> onboarding_processes ; access_token_hash text! ; personal_data_enc ; bank_data_enc
  emergency_contact_enc ; status enum(invited,in_progress,submitted,validated,rejected)=invited ; submitted_at
  validated_by_user_id ?-> users ; expires_at ; uniq access_token_hash

#E OFF | Offboarding
@termination_reasons soft | Motivos de cese.
  code ; name
  category enum(resignation,dismissal,contract_end,mutual_agreement,retirement,death,probation_failure,other)!
  is_voluntary ; statutory_code ; uniq code
@terminations main soft lock | Cese de una relación laboral: motivo, fechas y aprobación. Lo decide una persona (regla 9).
  employment_id -> employments ; termination_reason_id -> termination_reasons ; notice_date date ; last_working_day date!
  termination_date date! ; initiated_by enum(employee,employer,mutual)! ; detail text #S ; is_rehire_eligible boolean
  status enum(draft,pending_approval,approved,executed,cancelled,in_progress,pending_settlement)=draft ; resignation_document_id ?-> documents
  workflow_instance_id ?-> workflow_instances ; personnel_action_id ?-> personnel_actions
@exit_interviews lock sens | Entrevista de salida; respuestas cifradas.
  termination_id -> terminations ; interviewer_user_id ?-> users ; scheduled_at ; conducted_at ; answers_enc
  main_reason_code ; would_recommend smallint ; uniq termination_id

#E DES | Evaluación de desempeño
@rating_scales soft | Escalas de calificación.
  name ; min_value numeric(5,2)! ; max_value numeric(5,2)!
@rating_scale_levels | Niveles de una escala.
  rating_scale_id -> rating_scales ; value numeric(5,2)! ; label text! ; description ; uniq rating_scale_id, value
@review_templates soft lock | Plantilla de evaluación.
  name ; rating_scale_id ?-> rating_scales ; description
@review_template_sections | Secciones de una plantilla (competencias, objetivos, preguntas, resumen) con peso.
  review_template_id -> review_templates ; title ; kind enum(competencies,goals,questions,summary)! ; weight numeric(5,2)
  sort_order
@review_template_questions | Preguntas de una sección.
  review_template_section_id -> review_template_sections ; competency_id ?-> competencies ; text text!
  kind enum(rating,text,choice)=rating ; is_required bool=t ; applies_to text[] ; sort_order
@performance_cycles main soft lock | Campaña de evaluación (90°, 180°, 270° o 360°) con pesos por evaluador y reglas de anonimato de pares.
  name ; review_template_id -> review_templates ; kind enum(d90,d180,d270,d360)! ; period_start date! ; period_end date!
  legal_entity_id ?-> legal_entities ; status enum(draft,nomination,in_progress,calibration,results_published,closed,cancelled)=draft
  self_weight numeric(5,2) ; manager_weight numeric(5,2) ; peer_weight numeric(5,2) ; report_weight numeric(5,2)
  goals_weight numeric(5,2) ; anonymous_peers bool=t ; min_anonymous_responses smallint=3 ; goal_cycle_id ?-> goal_cycles
@performance_cycle_participants lock | Evaluado dentro de una campaña y su resultado final y calibrado.
  performance_cycle_id -> performance_cycles ; employment_id -> employments ; manager_employment_id ?-> employments
  status enum(pending,in_progress,completed,excluded)=pending ; final_score numeric(6,3) #S ; final_rating_label
  calibrated_score numeric(6,3) #S ; results_published_at ; acknowledged_at ; uniq performance_cycle_id, employment_id
@reviews lock | Evaluación de un evaluador sobre un evaluado (auto, jefe, par, subordinado u otro).
  performance_cycle_participant_id -> performance_cycle_participants ; reviewer_employment_id ?-> employments
  relationship enum(self,manager,peer,direct_report,other)! ; status enum(pending,in_progress,submitted,declined,calibrated,published,acknowledged,cancelled)=pending
  overall_score numeric(6,3) ; overall_comment ; submitted_at
@review_answers | Respuestas de una evaluación.
  review_id -> reviews ; review_template_question_id -> review_template_questions ; rating numeric(5,2) ; comment
  uniq review_id, review_template_question_id
@calibration_sessions lock | Sesión de calibración de resultados.
  performance_cycle_id -> performance_cycles ; name ; org_unit_id ?-> org_units ; facilitator_user_id ?-> users ; held_at
  status enum(planned,in_progress,closed)=planned
@calibration_adjustments imm | Ajuste de calibración con justificación obligatoria; inalterable.
  calibration_session_id -> calibration_sessions ; performance_cycle_participant_id -> performance_cycle_participants
  previous_score numeric(6,3) ; new_score numeric(6,3)! ; justification text! ; adjusted_by_user_id -> users

#E OKR | Objetivos
@goal_cycles soft | Periodos de objetivos.
  name ; starts_on date! ; ends_on date! ; status enum(draft,active,closed)=draft
@goals main soft lock | Objetivo corporativo, de unidad o individual, enlazado jerárquicamente.
  goal_cycle_id ?-> goal_cycles ; parent_goal_id ?-> goals ; level enum(company,org_unit,individual)!
  org_unit_id ?-> org_units ; owner_employment_id ?-> employments ; title ; description ; weight numeric(5,2)
  progress_pct numeric(5,2)=0 ; status enum(draft,active,at_risk,achieved,missed,cancelled,pending_approval,closed)=draft ; starts_on ; due_on
  visibility enum(public,team,private)=public
@key_results lock | Resultados clave de un objetivo.
  goal_id -> goals ; title ; metric_kind enum(number,percent,currency,boolean,milestone)=number
  start_value numeric(16,4) ; target_value numeric(16,4) ; current_value numeric(16,4) ; unit ; weight numeric(5,2)
  status enum(on_track,at_risk,off_track,done)=on_track ; sort_order
@goal_checkins | Actualizaciones de avance.
  goal_id -> goals ; key_result_id ?-> key_results ; author_user_id -> users ; value numeric(16,4) ; progress_pct numeric(5,2)
  confidence enum(low,medium,high) ; comment ; checked_on date!

#E CMP | Competencias y habilidades
@competencies soft lock | Catálogo de competencias.
  code ; name ; kind enum(core,leadership,functional,technical)=core ; description ; uniq code
@competency_levels | Niveles de una competencia con sus conductas.
  competency_id -> competencies ; level smallint! ; name ; behaviors ; uniq competency_id, level
@job_profile_competencies | Competencias requeridas por puesto y nivel esperado.
  job_profile_id -> job_profiles ; competency_id -> competencies ; required_level smallint! ; weight numeric(5,2)
  uniq job_profile_id, competency_id
@skills soft | Catálogo de habilidades (E-14).
  code ; name ; category ; description ; uniq code
@job_profile_skills | Habilidades requeridas por puesto.
  job_profile_id -> job_profiles ; skill_id -> skills ; required_level smallint ; is_required bool=t
  uniq job_profile_id, skill_id
@employment_skills lock | Matriz de habilidades del colaborador, con origen y validación.
  employment_id -> employments ; skill_id -> skills ; level smallint
  source enum(self,manager,assessment,course,import)=self ; validated_by_user_id ?-> users ; validated_at
  evidence_document_id ?-> documents ; uniq employment_id, skill_id
@employment_competency_levels | Nivel observado de una competencia por colaborador y fecha.
  employment_id -> employments ; competency_id -> competencies ; level numeric(4,2)! ; assessed_on date!
  source enum(review,assessment,manager)! ; review_id ?-> reviews

#E FBK | Reuniones 1:1 y feedback
@one_on_one_series soft | Serie de reuniones individuales entre un jefe y un colaborador.
  manager_employment_id -> employments ; report_employment_id -> employments
  cadence enum(weekly,biweekly,monthly,custom)=biweekly ; is_active bool=t
  uniq manager_employment_id, report_employment_id
@one_on_one_meetings lock | Reunión individual.
  one_on_one_series_id -> one_on_one_series ; scheduled_at ts! ; held_at ; status enum(scheduled,held,cancelled)=scheduled
  shared_notes
@one_on_one_items lock | Temas, notas y compromisos de una reunión; pueden ser privados de su autor.
  one_on_one_meeting_id -> one_on_one_meetings ; kind enum(topic,note,commitment)! ; author_user_id -> users ; content text!
  is_private ; owner_employment_id ?-> employments ; due_on ; status enum(open,done,dropped)=open
  carried_from_item_id ?-> one_on_one_items
@feedback_requests lock | Solicitud de feedback sobre un colaborador.
  requester_user_id -> users ; about_employment_id -> employments ; asked_user_id -> users ; question ; due_on
  status enum(pending,answered,declined,expired)=pending
@feedback_entries soft | Feedback continuo, con visibilidad y vínculo opcional a competencia, objetivo o campaña.
  about_employment_id -> employments ; author_user_id -> users ; feedback_request_id ?-> feedback_requests
  kind enum(praise,constructive,note)! ; content text!
  visibility enum(recipient,recipient_and_manager,manager_only,private)=recipient ; competency_id ?-> competencies
  goal_id ?-> goals ; performance_cycle_id ?-> performance_cycles

#F 5 | Desarrollo y compensación

#E LMS | Aprendizaje y plan anual
@course_categories soft | Categorías de cursos.
  code ; name ; parent_id ?-> course_categories ; uniq code
@training_providers soft | Proveedores de capacitación.
  name ; tax_id ; contact
@courses main soft lock | Curso interno o externo; puede ser obligatorio y con vigencia de recertificación.
  code ; title ; course_category_id ?-> course_categories ; description
  modality enum(self_paced,live_online,onsite,blended,external)=self_paced ; duration_minutes integer ; is_mandatory
  validity_months smallint ; training_provider_id ?-> training_providers ; cover_file_id ?-> stored_files
  passing_score numeric(5,2) ; certificate_template_id ?-> document_templates
  compliance_tag enum(none,safety,harassment,privacy,induction,other)=none ; status enum(draft,published,archived)=draft
  uniq code
@course_lessons soft | Lecciones de un curso (video, PDF, texto, paquete, enlace, evaluación, sesión en vivo).
  course_id -> courses ; title ; kind enum(video,pdf,text,scorm,link,quiz,live)! ; content ; stored_file_id ?-> stored_files
  external_url ; duration_minutes integer ; sort_order ; is_required bool=t
@course_assessments lock | Evaluaciones de un curso.
  course_id -> courses ; course_lesson_id ?-> course_lessons ; title ; passing_score numeric(5,2) ; max_attempts smallint
  time_limit_minutes integer ; shuffle bool=f
@assessment_questions | Preguntas de una evaluación.
  course_assessment_id -> course_assessments ; text text! ; kind enum(single,multiple,true_false,open)! ; options j
  correct_answer j ; points numeric(5,2)=1 ; sort_order
@course_assignment_rules soft lock | Regla de asignación de un curso: individual, equipo, unidad, puesto, sede, empresa, todos o ingresantes.
  course_id -> courses ; target_kind enum(individual,team,org_unit,job_profile,site,legal_entity,all,new_hires)!
  employment_id ?-> employments ; org_unit_id ?-> org_units ; job_profile_id ?-> job_profiles ; site_id ?-> sites
  legal_entity_id ?-> legal_entities ; due_days integer ; recurrence_months smallint ; is_active bool=t
@training_plans soft lock | Plan anual de capacitación con presupuesto.
  name ; fiscal_year smallint! ; legal_entity_id ?-> legal_entities ; budget_amount money ; currency cur?
  status enum(draft,approved,in_progress,closed)=draft
@training_plan_items lock | Actividad del plan: planificado frente a ejecutado (participantes, horas, costo, resultados).
  training_plan_id -> training_plans ; course_id ?-> courses ; title ; org_unit_id ?-> org_units
  planned_participants integer ; planned_hours numeric(8,2) ; planned_cost money ; executed_participants integer
  executed_hours numeric(8,2) ; executed_cost money ; currency cur? ; training_provider_id ?-> training_providers
  planned_month smallint ; status enum(planned,scheduled,executed,cancelled)=planned ; result_notes
@training_sessions soft lock | Sesión presencial o en vivo de un curso.
  course_id -> courses ; training_plan_item_id ?-> training_plan_items ; starts_at ts! ; ends_at ts!
  site_id ?-> sites ; location_text ; meeting_url ; instructor_name ; training_provider_id ?-> training_providers
  capacity integer ; status enum(scheduled,held,cancelled)=scheduled
@training_session_attendees | Asistencia a una sesión, con firma (sirve de registro de capacitación de SST).
  training_session_id -> training_sessions ; employment_id -> employments ; attended boolean
  signature_file_id ?-> stored_files ; hours numeric(5,2) ; uniq training_session_id, employment_id
@enrollments main lock | Inscripción de un colaborador en un curso y su avance.
  course_id -> courses ; employment_id -> employments ; course_assignment_rule_id ?-> course_assignment_rules
  training_session_id ?-> training_sessions
  source enum(assigned,self_enrolled,onboarding,development_plan,compliance)=assigned
  status enum(not_started,in_progress,completed,overdue,failed,cancelled)=not_started ; progress_pct smallint=0 ; due_on
  started_at ; completed_at ; score numeric(5,2) ; expires_on ; idx employment_id, status
@lesson_progress | Avance por lección.
  enrollment_id -> enrollments ; course_lesson_id -> course_lessons
  status enum(not_started,in_progress,completed)=not_started ; seconds_spent integer ; completed_at
  uniq enrollment_id, course_lesson_id
@assessment_attempts | Intentos de evaluación.
  enrollment_id -> enrollments ; course_assessment_id -> course_assessments ; attempt_number smallint! ; answers j
  score numeric(5,2) ; passed boolean ; started_at ; submitted_at
  uniq enrollment_id, course_assessment_id, attempt_number
@certificates | Certificados emitidos o externos, con código de verificación y vencimiento.
  employment_id -> employments ; enrollment_id ?-> enrollments ; course_id ?-> courses ; title ; issued_on date!
  expires_on ; verification_code text! ; document_id ?-> documents ; is_external ; uniq verification_code

#E CAR | Desarrollo, carrera, sucesión y 9-box
@development_plans soft lock | Plan de desarrollo individual, normalmente tras una evaluación.
  employment_id -> employments ; title ; origin enum(review,succession,career,self,manager)=review
  performance_cycle_id ?-> performance_cycles ; starts_on ; due_on ; status enum(draft,active,completed,cancelled)=draft
  progress_pct smallint=0
@development_plan_actions lock | Acciones del plan: brecha (nivel actual y objetivo) y acción (curso, mentoría, proyecto).
  development_plan_id -> development_plans ; competency_id ?-> competencies ; skill_id ?-> skills ; current_level smallint
  target_level smallint ; action_kind enum(course,mentoring,project,reading,coaching,rotation,other)! ; description text!
  course_id ?-> courses ; mentor_employment_id ?-> employments ; due_on ; status enum(pending,in_progress,done,dropped)=pending
  follow_up_notes
@career_paths soft | Rutas de carrera.
  name ; job_family_id ?-> job_families ; description
@career_path_steps | Pasos de una ruta (puesto y orden).
  career_path_id -> career_paths ; job_profile_id -> job_profiles ; step_order smallint! ; typical_months smallint
  uniq career_path_id, step_order
@succession_plans soft lock | Plan de sucesión de una posición crítica.
  position_id -> positions ; criticality enum(low,medium,high)=high ; vacancy_risk enum(low,medium,high) ; notes
  status ; uniq position_id
@succession_candidates lock | Posibles sucesores y su grado de preparación.
  succession_plan_id -> succession_plans ; employment_id -> employments
  readiness enum(ready_now,one_year,two_years,developing)! ; rank smallint ; notes
  development_plan_id ?-> development_plans ; uniq succession_plan_id, employment_id
@talent_assessments lock sens | Ubicación en la matriz 9-box (desempeño × potencial). Insumo de análisis: no desencadena decisiones automáticas (C-08).
  employment_id -> employments ; performance_cycle_id ?-> performance_cycles ; assessed_on date!
  performance_score smallint! CHECK (performance_score BETWEEN 1 AND 3)
  potential_score smallint! CHECK (potential_score BETWEEN 1 AND 3) ; assessed_by_user_id -> users ; notes ; is_calibrated

#E COM | Compensaciones, bandas, revisión salarial e igualdad salarial
@salary_bands soft lock sens | Banda salarial por nivel, categoría o puesto, con vigencia.
  name ; job_level_id ?-> job_levels ; job_category_id ?-> job_categories ; job_profile_id ?-> job_profiles
  legal_entity_id ?-> legal_entities ; site_id ?-> sites ; min_amount money! #S ; mid_amount money #S
  max_amount money! #S ; currency cur ; vigcols ; check max_amount >= min_amount
@compensation_review_cycles main soft lock | Campaña de revisión salarial con presupuesto y etapas de aprobación.
  name ; fiscal_year smallint! ; legal_entity_id ?-> legal_entities ; effective_on date! ; budget_amount money #S
  budget_pct numeric(6,3) ; currency cur
  status enum(draft,open,hr_review,finance_review,executive_review,approved,applied,cancelled)=draft ; guidelines j
@compensation_review_budgets | Presupuesto de la campaña por unidad.
  compensation_review_cycle_id -> compensation_review_cycles ; org_unit_id -> org_units ; manager_user_id ?-> users
  budget_amount money #S ; used_amount money=0 ; currency cur ; uniq compensation_review_cycle_id, org_unit_id
@compensation_review_proposals lock sens | Propuesta de incremento por colaborador; al aplicarse crea un registro de compensación con vigencia.
  compensation_review_cycle_id -> compensation_review_cycles ; employment_id -> employments ; proposed_by_user_id -> users
  current_salary money! #S ; proposed_salary money! #S ; increase_pct numeric(7,3) ; currency cur
  reason enum(merit,promotion,market,equity,other)! ; justification ; band_position_pct numeric(7,3)
  status enum(draft,submitted,hr_approved,finance_approved,approved,rejected,applied)=draft
  workflow_instance_id ?-> workflow_instances ; applied_compensation_id ?-> employment_compensations
  uniq compensation_review_cycle_id, employment_id
@salary_policies soft lock | Política salarial de la empresa (E-05).
  legal_entity_id -> legal_entities ; title ; document_id ?-> documents ; valid_from date! ; valid_to date ; criteria
@salary_policy_communications | Evidencia de comunicación de la política salarial al ingreso, cambio de puesto o de remuneración.
  salary_policy_id -> salary_policies ; employment_id -> employments
  trigger_kind enum(hire,position_change,salary_change,policy_update)! ; communicated_at ts!
  channel enum(portal,email,signature,printed)! ; evidence_document_id ?-> documents ; acknowledged_at
@pay_equity_reports lock | Reporte de brecha salarial por categoría y sexo a una fecha.
  legal_entity_id -> legal_entities ; reference_date date! ; status enum(draft,final)=draft
  generated_by_user_id ?-> users ; document_id ?-> documents ; min_group_size smallint
@pay_equity_report_lines sens | Líneas del reporte de brecha con criterios objetivos de justificación.
  pay_equity_report_id -> pay_equity_reports ; job_category_id -> job_categories ; headcount_female integer
  headcount_male integer ; avg_salary_female money #S ; avg_salary_male money #S ; median_female money #S
  median_male money #S ; gap_pct numeric(7,3) ; currency cur ; justification ; objective_criteria j

#E BNF | Beneficios
@benefits main soft lock | Catálogo de beneficios de la empresa.
  code ; name ; category enum(insurance,food,transport,education,discount,wellness,other)! ; description ; provider_name
  cost_employer money ; cost_employee money ; currency cur? ; payroll_concept_id ?-> payroll_concepts
  requires_enrollment ; starts_on ; ends_on ; status enum(draft,active,inactive)=active ; uniq code
@benefit_eligibility_rules | Reglas de elegibilidad de un beneficio.
  benefit_id -> benefits ; legal_entity_id ?-> legal_entities ; site_id ?-> sites ; org_unit_id ?-> org_units
  job_level_id ?-> job_levels ; job_category_id ?-> job_categories ; min_tenure_months smallint ; employment_kind
  conditions j
@benefit_enrollments lock | Beneficios de cada colaborador.
  benefit_id -> benefits ; employment_id -> employments ; status enum(eligible,requested,active,waived,ended)=eligible
  starts_on ; ends_on ; option_code ; dependents_covered j #S ; workflow_instance_id ?-> workflow_instances
