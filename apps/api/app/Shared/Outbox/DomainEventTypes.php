<?php

namespace App\Shared\Outbox;

use InvalidArgumentException;

final class DomainEventTypes
{
    // Empleo / Colaborador
    public const EMPLOYEE_HIRED = 'employee.hired.v1';

    public const EMPLOYEE_UPDATED = 'employee.updated.v1';

    public const EMPLOYEE_TERMINATED = 'employee.terminated.v1';

    public const EMPLOYEE_COMPENSATION_CHANGED = 'employment.compensation_changed.v1';

    // COL-2 · ME-01: activación del ingreso (manual o por la fecha) y anulación de un ingreso que no empezó.
    public const EMPLOYMENT_ACTIVATED = 'employment.activated.v1';

    public const EMPLOYEE_HIRE_CANCELLED = 'employee.hire_cancelled.v1';

    // CON-2 · CON-3 · ME-11: ciclo del contrato (envío, emisión con documento, firma, inicio, renovación,
    // adenda, vencimiento sin regularizar, término, anulación) y aviso del fin del periodo de prueba.
    public const CONTRACT_CREATED = 'contract.created.v1';

    public const CONTRACT_ISSUED = 'contract.issued.v1';

    public const CONTRACT_SIGNED = 'contract.signed.v1';

    public const CONTRACT_STARTED = 'contract.started.v1';

    public const CONTRACT_RENEWED = 'contract.renewed.v1';

    public const CONTRACT_AMENDED = 'contract.amended.v1';

    public const CONTRACT_EXPIRED = 'contract.expired.v1';

    public const CONTRACT_TERMINATED = 'contract.terminated.v1';

    public const CONTRACT_VOIDED = 'contract.voided.v1';

    public const CONTRACT_PROBATION_ENDING = 'contract.probation_ending.v1';

    // CON-4 · RN-CON-04: vencimiento próximo y tope acumulado cercano.
    public const CONTRACT_EXPIRING = 'contract.expiring.v1';

    public const CONTRACT_CAP_APPROACHING = 'contract.cap_approaching.v1';

    /** MSS-4 · Un cumpleaños o aniversario de un integrante del equipo se acerca (recordatorio al jefe). */
    public const TEAM_KEY_DATE_UPCOMING = 'team.key_date_upcoming.v1';

    // COL-10 · COL-11 · ME-01: reingreso y cese (registro, reversión) de la relación laboral.
    public const EMPLOYEE_REHIRED = 'employee.rehired.v1';

    public const OFFBOARDING_STARTED = 'offboarding.started.v1';

    public const OFFBOARDING_CANCELLED = 'offboarding.cancelled.v1';

    // HIS-2 · HIS-4: movimientos de personal (promoción y traslado para la analítica) y su trámite.
    public const EMPLOYEE_PROMOTED = 'employee.promoted.v1';

    public const EMPLOYEE_TRANSFERRED = 'employee.transferred.v1';

    public const PERSONNEL_ACTION_REQUESTED = 'personnel_action.requested.v1';

    public const PERSONNEL_ACTION_CANCELLED = 'personnel_action.cancelled.v1';

    public const EMPLOYMENT_RETROACTIVE_CHANGE = 'employment.retroactive_change.v1';

    // COL-4 · RN-COL-06: cambio de datos personales propuesto por el colaborador y su aprobación.
    public const PERSON_DATA_CHANGE_REQUESTED = 'person.data_change_requested.v1';

    public const PERSON_DATA_CHANGE_APPROVED = 'person.data_change_approved.v1';

    // COL-6 · COL-7 · COL-8: derechohabientes, previsión y salud, cuentas de pago.
    public const DEPENDENT_REACHED_MAJORITY = 'dependent.reached_majority.v1';

    public const PENSION_AFFILIATION_CHANGED = 'employment.pension_changed.v1';

    public const HEALTH_REGIME_CHANGED = 'employment.health_changed.v1';

    public const BANK_ACCOUNT_CHANGE_REQUESTED = 'bank_account.change_requested.v1';

    public const BANK_ACCOUNT_CHANGED = 'bank_account.changed.v1';

    // Asistencia / Vacaciones
    public const LEAVE_REQUESTED = 'leave.requested.v1';

    public const LEAVE_APPROVED = 'leave.approved.v1';

    public const LEAVE_REJECTED = 'leave.rejected.v1';

    public const LEAVE_CANCELLED = 'leave.cancelled.v1';

    // VAC-3 · Inicio y fin del goce de vacaciones aprobadas (la reserva pasa a «tomadas» al iniciar).
    public const LEAVE_STARTED = 'leave.started.v1';

    public const LEAVE_COMPLETED = 'leave.completed.v1';

    // VAC-2 · Libro de vacaciones: ajuste de saldo (RN-VAC-13), periodo ganado y periodo vencido (RN-VAC-09).
    public const LEAVE_BALANCE_ADJUSTED = 'leave.balance_adjusted.v1';

    public const LEAVE_PERIOD_EARNED = 'leave.period_earned.v1';

    public const LEAVE_PERIOD_EXPIRING = 'leave.period_expiring.v1';

    public const ATTENDANCE_PUNCHED = 'attendance.punched.v1';

    public const ATTENDANCE_PERIOD_CLOSED = 'attendance.period_closed.v1';

    // Planilla / Nómina
    public const PAYROLL_CALCULATED = 'payroll.calculated.v1';

    public const PAYROLL_APPROVED = 'payroll.approved.v1';

    public const PAYROLL_CLOSED = 'payroll.closed.v1';

    public const PAYSLIP_PUBLISHED = 'payslip.published.v1';

    // Selección / Reclutamiento
    public const CANDIDATE_HIRED = 'candidate.hired.v1';

    public const OFFER_ACCEPTED = 'offer.accepted.v1';

    // Documentos / Archivos
    public const DOCUMENT_UPLOADED = 'document.uploaded.v1';

    // DOC-1 · DOC-2 · ME-12: legajo digital (revisión, archivo y vencimientos, RN-DOC-09).
    public const DOCUMENT_APPROVED = 'document.approved.v1';

    public const DOCUMENT_REJECTED = 'document.rejected.v1';

    public const DOCUMENT_ARCHIVED = 'document.archived.v1';

    public const DOCUMENT_EXPIRING = 'document.expiring.v1';

    public const DOCUMENT_EXPIRED = 'document.expired.v1';

    // DOC-3 · DOC-5 · Solicitudes de documentos y documentos generados desde plantilla.
    public const DOCUMENT_REQUESTED = 'document.requested.v1';

    public const DOCUMENT_GENERATED = 'document.generated.v1';

    public const DOCUMENT_GENERATION_STARTED = 'document.generation_started.v1';

    // UI-11 · Exportación grande pedida: se genera en segundo plano y avisa cuando está lista.
    public const EXPORT_REQUESTED = 'export.requested.v1';

    public const SIGNATURE_COMPLETED = 'signature.completed.v1';

    // Identidad
    public const USER_PASSWORD_RESET_REQUESTED = 'user.password_reset_requested.v1';

    public const USER_INVITED = 'user.invited.v1';

    /** SEG-2: código de ingreso o de activación por SMS/WhatsApp (cifrado, nunca en claro). */
    public const USER_MESSAGE_CODE_ISSUED = 'user.message_code_issued.v1';

    // Aprobaciones (WFL)
    public const APPROVAL_REQUESTED = 'approval.requested.v1';

    public const APPROVAL_STEP_APPROVED = 'approval.step_approved.v1';

    public const APPROVAL_APPROVED = 'approval.approved.v1';

    public const APPROVAL_REJECTED = 'approval.rejected.v1';

    public const APPROVAL_ESCALATED = 'approval.escalated.v1';

    public const APPROVAL_CANCELLED = 'approval.cancelled.v1';

    public const APPROVAL_REMINDER = 'approval.reminder.v1';

    public const APPROVAL_TASK_ASSIGNED = 'approval.task_assigned.v1';

    public const TASK_ASSIGNED = 'task.assigned.v1';

    // Notificaciones (NOT)
    public const NOTIFICATION_DELIVERY_REQUESTED = 'notification.delivery_requested.v1';

    /** NOT-5: mensaje directo por SMS/WhatsApp (variables cifradas: pueden llevar un código). */
    public const MESSAGE_SEND_REQUESTED = 'message.send_requested.v1';

    /** NOT-5: plantilla de WhatsApp enviada a aprobación del proveedor. */
    public const MESSAGE_TEMPLATE_APPROVAL_REQUESTED = 'message.template_approval_requested.v1';

    /** NOT-5: mensaje entrante de un colaborador por SMS/WhatsApp (lo consume M-WHA). */
    public const MESSAGE_RECEIVED = 'message.received.v1';

    /** NOT-3: verificar en el proveedor el remitente de correo propio de la organización. */
    public const EMAIL_SENDER_VERIFICATION_REQUESTED = 'message_channel.sender_verification_requested.v1';

    /** NOT-3: una dirección de correo rebotó (o se quejó) y quedó marcada como inválida. */
    public const EMAIL_ADDRESS_BOUNCED = 'user_identity.email_bounced.v1';

    public const TASK_OVERDUE = 'task.overdue.v1';

    // Auditoría (AUD-5)
    public const AUDIT_INTEGRITY_FAILED = 'audit.integrity_failed.v1';

    // Importaciones (IMP-1)
    public const IMPORT_VALIDATED = 'import.validated.v1';

    public const IMPORT_STARTED = 'import.started.v1';

    public const IMPORT_COMPLETED = 'import.completed.v1';

    public const IMPORT_FAILED = 'import.failed.v1';

    public const IMPORT_REVERTED = 'import.reverted.v1';

    // Puesta en marcha (IMP-3, IMP-4)
    public const COUNTRY_PACK_APPLIED = 'country_pack.applied.v1';

    public const ONBOARDING_SETUP_COMPLETED = 'onboarding_setup.completed.v1';

    // Organización
    public const COMPANY_CREATED = 'company.created.v1';

    public const COMPANY_ARCHIVED = 'company.archived.v1';

    // Catálogo de puestos (ORG-5).
    public const JOB_PROFILE_CREATED = 'job_profile.created.v1';

    public const JOB_PROFILE_UPDATED = 'job_profile.updated.v1';

    // Posiciones (POS-1 · POS-2 · ME-03).
    public const POSITION_APPROVED = 'position.approved.v1';

    public const POSITION_OPENED = 'position.opened.v1';

    public const POSITION_FILLED = 'position.filled.v1';

    public const POSITION_VACATED = 'position.vacated.v1';

    public const POSITION_FROZEN = 'position.frozen.v1';

    public const POSITION_UNFROZEN = 'position.unfrozen.v1';

    public const POSITION_CLOSED = 'position.closed.v1';

    // Solicitudes de posición (POS-3 · ME-04).
    public const POSITION_REQUESTED = 'position.requested.v1';

    public const POSITION_REQUEST_APPROVED = 'position_request.approved.v1';

    public const POSITION_REQUEST_REJECTED = 'position_request.rejected.v1';

    public const POSITION_REQUEST_CANCELLED = 'position_request.cancelled.v1';

    // Ausencias y permisos (AUS-2 · ME-16).
    public const ABSENCE_REQUESTED = 'absence.requested.v1';

    public const ABSENCE_APPROVED = 'absence.approved.v1';

    public const ABSENCE_REJECTED = 'absence.rejected.v1';

    public const ABSENCE_CANCELLED = 'absence.cancelled.v1';

    public const ABSENCE_STARTED = 'absence.started.v1';

    public const ABSENCE_ENDED = 'absence.ended.v1';

    // AUS-3: un descanso aprobado pasó a subsidio (expediente de reembolso abierto).
    public const ABSENCE_SUBSIDY_STARTED = 'absence.subsidy_started.v1';

    // Organización (SAS-2): cambios de estado decididos desde la consola.
    public const TENANT_STATUS_CHANGED = 'tenant.status_changed.v1';

    // Módulos de la organización (SAS-5).
    public const TENANT_MODULE_CHANGED = 'tenant.module_changed.v1';

    // Unidades organizativas (ORG-3)
    public const ORG_UNIT_CREATED = 'org_unit.created.v1';

    public const ORG_UNIT_MOVED = 'org_unit.moved.v1';

    public const ORG_UNIT_MANAGER_CHANGED = 'org_unit.manager_changed.v1';

    public const ORG_UNIT_ARCHIVED = 'org_unit.archived.v1';

    // Suscripción de la organización (SAS-4): plan, complementos o estado.
    public const SUBSCRIPTION_CHANGED = 'subscription.changed.v1';

    // Límites y consumo (SAS-6): el consumo se acercó al tope del plan o llegó a él (RN-SAS-02, RN-SAS-03).
    public const LIMIT_WARNING = 'limit.warning.v1';

    public const LIMIT_REACHED = 'limit.reached.v1';

    public const USER_ACTIVATED = 'user.activated.v1';

    public const USER_SUSPENDED = 'user.suspended.v1';

    /**
     * Catálogo de esquemas de payload versionados por tipo de evento.
     *
     * @var array<string, array{version: int, required_fields: array<int, string>, description: string}>
     */
    private static array $catalog = [
        self::EMPLOYEE_HIRED => [
            'version' => 1,
            'required_fields' => ['employee_id', 'first_name', 'last_name', 'hire_date'],
            'description' => 'Colaborador contratado en la organización',
        ],
        self::EMPLOYEE_UPDATED => [
            'version' => 1,
            'required_fields' => ['employee_id', 'updated_fields'],
            'description' => 'Datos de colaborador actualizados',
        ],
        self::EMPLOYEE_TERMINATED => [
            'version' => 1,
            'required_fields' => ['employee_id', 'termination_date', 'reason'],
            'description' => 'Cese registrado de colaborador',
        ],
        self::PERSON_DATA_CHANGE_REQUESTED => [
            'version' => 1,
            'required_fields' => ['request_id', 'person_id', 'fields'],
            'description' => 'El colaborador propuso cambios de datos personales que esperan a RRHH',
        ],
        self::PERSON_DATA_CHANGE_APPROVED => [
            'version' => 1,
            'required_fields' => ['request_id', 'person_id', 'fields'],
            'description' => 'RRHH aprobó y aplicó cambios de datos personales propuestos por el colaborador',
        ],
        self::EMPLOYMENT_ACTIVATED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'person_id', 'legal_entity_id', 'hire_date'],
            'description' => 'Relación laboral activa: empieza a marcar, a generar saldos y a entrar en planilla (ME-01)',
        ],
        self::DEPENDENT_REACHED_MAJORITY => [
            'version' => 1,
            'required_fields' => ['dependent_id', 'person_id', 'reached_on'],
            'description' => 'Un hijo derechohabiente cumplió la mayoría de edad: RRHH revisa si sigue como derechohabiente (COL-6)',
        ],
        self::PENSION_AFFILIATION_CHANGED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'pension_system', 'effective_from'],
            'description' => 'Cambio de sistema, administradora o tipo de comisión con fecha efectiva; fuente de los aportes (COL-7)',
        ],
        self::HEALTH_REGIME_CHANGED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'health_scheme', 'effective_from'],
            'description' => 'Cambio del régimen de salud (EsSalud, EPS con plan) con fecha efectiva (COL-7)',
        ],
        self::BANK_ACCOUNT_CHANGE_REQUESTED => [
            'version' => 1,
            'required_fields' => ['bank_account_id', 'employment_id', 'person_id', 'purpose'],
            'description' => 'Se registró una cuenta de pago nueva o cambiada que espera aprobación; se avisa al titular (RN-COL-11)',
        ],
        self::BANK_ACCOUNT_CHANGED => [
            'version' => 1,
            'required_fields' => ['bank_account_id', 'employment_id', 'person_id', 'purpose', 'effective_from'],
            'description' => 'La cuenta de pago cambió y rige desde la fecha; se avisa al titular por un canal distinto (RN-COL-11)',
        ],
        self::EMPLOYEE_HIRE_CANCELLED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'person_id', 'legal_entity_id'],
            'description' => 'Ingreso anulado antes de empezar: libera la posición y cancela el onboarding (ME-01)',
        ],
        self::EMPLOYEE_REHIRED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'person_id', 'legal_entity_id', 'hire_date', 'rehire_of_employment_id'],
            'description' => 'Reingreso: relación nueva de una persona con una relación anterior cesada, que queda intacta (COL-10)',
        ],
        self::CONTRACT_CREATED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id'], 'description' => 'Contrato enviado a aprobación o a firma (ME-11)'],
        self::CONTRACT_ISSUED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'document_id'], 'description' => 'Contrato aprobado: documento generado y listo para firmar (ME-11)'],
        self::CONTRACT_SIGNED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'signature_origin'], 'description' => 'Contrato firmado por ambas partes (RN-CON-06)'],
        self::CONTRACT_STARTED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'starts_on'], 'description' => 'Contrato vigente desde su fecha de inicio (ME-11)'],
        self::CONTRACT_RENEWED => ['version' => 1, 'required_fields' => ['contract_id', 'renewal_of_contract_id', 'employment_id'], 'description' => 'Renovación firmada que sucede al contrato anterior (CON-3)'],
        self::CONTRACT_AMENDED => ['version' => 1, 'required_fields' => ['contract_id', 'addendum_id', 'kind', 'effective_on'], 'description' => 'Adenda firmada; aplica sus cambios con vigencia (RN-CON-08)'],
        self::CONTRACT_EXPIRED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'ends_on'], 'description' => 'Contrato a plazo vencido sin renovación ni cese: riesgo de desnaturalización (RN-CON-05)'],
        self::CONTRACT_TERMINATED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'reason'], 'description' => 'Contrato terminado (cese, renovación o término registrado)'],
        self::CONTRACT_VOIDED => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id'], 'description' => 'Contrato anulado antes de empezar a regir'],
        self::CONTRACT_PROBATION_ENDING => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'trial_period_ends_on'], 'description' => 'El periodo de prueba termina pronto: aviso al jefe y a RRHH (CON-2)'],
        self::CONTRACT_EXPIRING => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'ends_on', 'days_left'], 'description' => 'Un contrato vence dentro de la anticipación configurada: aviso al jefe y a RRHH con el acumulado (RN-CON-04)'],
        self::CONTRACT_CAP_APPROACHING => ['version' => 1, 'required_fields' => ['contract_id', 'employment_id', 'explanation'], 'description' => 'El tiempo acumulado a modalidad se acerca al tope: renovar podría desnaturalizar el contrato (RN-CON-02/03)'],
        self::TEAM_KEY_DATE_UPCOMING => ['version' => 1, 'required_fields' => ['employment_id', 'kind', 'date'], 'description' => 'Se acerca el cumpleaños o el aniversario de ingreso de un integrante del equipo (MSS-4)'],
        self::OFFBOARDING_STARTED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'termination_id', 'termination_date', 'reason'],
            'description' => 'Cese registrado: la relación pasa a «En proceso de cese»; inicia el offboarding y la liquidación (ME-01)',
        ],
        self::OFFBOARDING_CANCELLED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'termination_id'],
            'description' => 'Cese revertido antes de su fecha: la relación vuelve a «Activa» (ME-01)',
        ],
        self::EMPLOYEE_PROMOTED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'personnel_action_id', 'effective_on', 'updated_fields'],
            'description' => 'Promoción aplicada (cambio de puesto) con su movimiento de personal (HIS-2)',
        ],
        self::EMPLOYEE_TRANSFERRED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'personnel_action_id', 'effective_on', 'updated_fields'],
            'description' => 'Traslado aplicado (unidad, sede o posición) con su movimiento de personal (HIS-2)',
        ],
        self::PERSONNEL_ACTION_REQUESTED => [
            'version' => 1,
            'required_fields' => ['personnel_action_id', 'employment_id', 'kind', 'effective_on', 'status'],
            'description' => 'Movimiento de personal registrado: aplicado, programado o en aprobación (HIS-2)',
        ],
        self::PERSONNEL_ACTION_CANCELLED => [
            'version' => 1,
            'required_fields' => ['personnel_action_id', 'employment_id'],
            'description' => 'Movimiento programado o en aprobación anulado antes de aplicarse (HIS-4)',
        ],
        self::EMPLOYMENT_RETROACTIVE_CHANGE => [
            'version' => 1,
            'required_fields' => ['employment_id', 'personnel_action_id', 'affected_from', 'affected_to'],
            'description' => 'Cambio con fecha pasada: rango de fechas afectado para recalcular planilla y asistencia sin alterar periodos cerrados (HIS-4 · RN-HIS-05)',
        ],
        self::EMPLOYEE_COMPENSATION_CHANGED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'new_amount', 'currency', 'effective_from'],
            'description' => 'Cambio de sueldo registrado',
        ],
        self::LEAVE_REQUESTED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id', 'start_date', 'end_date'],
            'description' => 'Solicitud de ausencia o vacación creada',
        ],
        self::LEAVE_APPROVED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id', 'approved_by'],
            'description' => 'Solicitud de vacación o ausencia aprobada',
        ],
        self::LEAVE_REJECTED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id', 'rejected_by', 'reason'],
            'description' => 'Solicitud de vacación rechazada',
        ],
        self::LEAVE_CANCELLED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id'],
            'description' => 'Solicitud de vacación cancelada',
        ],
        self::LEAVE_STARTED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id', 'start_date', 'end_date', 'days'],
            'description' => 'Empezó el goce de una vacación aprobada (VAC-3)',
        ],
        self::LEAVE_COMPLETED => [
            'version' => 1,
            'required_fields' => ['leave_id', 'employee_id', 'start_date', 'end_date'],
            'description' => 'Terminó el goce de una vacación (VAC-3)',
        ],
        self::LEAVE_BALANCE_ADJUSTED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'entry_id', 'days', 'reason'],
            'description' => 'RRHH ajustó el saldo de vacaciones con motivo y sustento (RN-VAC-13)',
        ],
        self::LEAVE_PERIOD_EARNED => [
            'version' => 1,
            'required_fields' => ['employment_id', 'period_id', 'period_start', 'period_end', 'days'],
            'description' => 'Se completó un año vacacional y sus días pasaron al saldo',
        ],
        self::LEAVE_PERIOD_EXPIRING => [
            'version' => 1,
            'required_fields' => ['employment_id', 'period_id', 'enjoy_by', 'available_days'],
            'description' => 'Un periodo vacacional venció sin gozarse por completo (RN-VAC-09)',
        ],
        self::ATTENDANCE_PUNCHED => [
            'version' => 1,
            'required_fields' => ['punch_id', 'employee_id', 'punch_time', 'direction'],
            'description' => 'Marcación registrada',
        ],
        self::ATTENDANCE_PERIOD_CLOSED => [
            'version' => 1,
            'required_fields' => ['period_id', 'cutoff_date'],
            'description' => 'Cierre de asistencia de periodo',
        ],
        self::PAYROLL_CALCULATED => [
            'version' => 1,
            'required_fields' => ['payroll_run_id', 'total_net', 'currency'],
            'description' => 'Cálculo de periodo de planilla completado',
        ],
        self::PAYROLL_APPROVED => [
            'version' => 1,
            'required_fields' => ['payroll_run_id', 'approved_by'],
            'description' => 'Periodo de planilla aprobado',
        ],
        self::PAYROLL_CLOSED => [
            'version' => 1,
            'required_fields' => ['payroll_run_id', 'closed_at'],
            'description' => 'Periodo de nómina cerrado definitivamente',
        ],
        self::PAYSLIP_PUBLISHED => [
            'version' => 1,
            'required_fields' => ['payslip_id', 'employee_id', 'period_id'],
            'description' => 'Boleta publicada y lista para entrega',
        ],
        self::CANDIDATE_HIRED => [
            'version' => 1,
            'required_fields' => ['candidate_id', 'job_opening_id'],
            'description' => 'Candidato seleccionado y contratado',
        ],
        self::OFFER_ACCEPTED => [
            'version' => 1,
            'required_fields' => ['offer_id', 'candidate_id'],
            'description' => 'Oferta laboral aceptada',
        ],
        self::USER_PASSWORD_RESET_REQUESTED => [
            'version' => 1,
            'required_fields' => ['user_id', 'user_token_id', 'token_enc', 'expires_at'],
            'description' => 'Se solicitó el enlace de recuperación de clave (token cifrado, nunca en claro)',
        ],
        self::APPROVAL_REQUESTED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Solicitud de aprobación iniciada y asignada al primer nivel',
        ],
        self::APPROVAL_STEP_APPROVED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Un nivel de la aprobación quedó aprobado',
        ],
        self::APPROVAL_APPROVED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Aprobación final: el módulo de origen ejecuta su transición',
        ],
        self::APPROVAL_REJECTED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Aprobación rechazada con motivo',
        ],
        self::APPROVAL_ESCALATED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Tarea vencida reasignada por escalamiento',
        ],
        self::APPROVAL_CANCELLED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Solicitud cancelada antes de la decisión final',
        ],
        self::APPROVAL_REMINDER => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id'],
            'description' => 'Recordatorio de tarea de aprobación por vencer',
        ],
        self::APPROVAL_TASK_ASSIGNED => [
            'version' => 1,
            'required_fields' => ['approval_request_id', 'subject_type', 'subject_id', 'task_id'],
            'description' => 'Una tarea de aprobación quedó a cargo de una persona o un rol (nuevo nivel, siguiente turno o delegación)',
        ],
        self::NOTIFICATION_DELIVERY_REQUESTED => [
            'version' => 1,
            'required_fields' => ['delivery_id', 'category', 'locale'],
            'description' => 'Entrega de un aviso por canal externo (correo); la dirección va cifrada en la entrega, no en el evento',
        ],
        self::MESSAGE_SEND_REQUESTED => [
            'version' => 1,
            'required_fields' => ['delivery_id', 'template_code', 'locale', 'variables_enc'],
            'description' => 'Mensaje directo por SMS/WhatsApp; el número va cifrado en la entrega y las variables cifradas en el evento',
        ],
        self::MESSAGE_TEMPLATE_APPROVAL_REQUESTED => [
            'version' => 1,
            'required_fields' => ['template_id'],
            'description' => 'Plantilla de WhatsApp enviada a aprobación del proveedor',
        ],
        self::MESSAGE_RECEIVED => [
            'version' => 1,
            'required_fields' => ['channel', 'from_enc', 'body_enc', 'provider', 'provider_message_id'],
            'description' => 'Mensaje entrante por SMS/WhatsApp (remitente y texto cifrados)',
        ],
        self::EMAIL_SENDER_VERIFICATION_REQUESTED => [
            'version' => 1,
            'required_fields' => ['channel_id'],
            'description' => 'Verificar en el proveedor el remitente de correo propio de la organización',
        ],
        self::EMAIL_ADDRESS_BOUNCED => [
            'version' => 1,
            'required_fields' => ['user_id', 'delivery_id', 'kind'],
            'description' => 'Una dirección de correo rebotó o se quejó: ya no se usa y se avisa a RRHH (la dirección no viaja en el evento)',
        ],
        self::AUDIT_INTEGRITY_FAILED => [
            'version' => 1,
            'required_fields' => ['check_id', 'issues_count'],
            'description' => 'La verificación programada encontró entradas de auditoría alteradas o faltantes',
        ],
        self::TASK_ASSIGNED => [
            'version' => 1,
            'required_fields' => ['task_id'],
            'description' => 'Tarea interna asignada a una persona o a un rol',
        ],
        self::TASK_OVERDUE => [
            'version' => 1,
            'required_fields' => ['task_id', 'due_on'],
            'description' => 'Tarea interna vencida sin completar (se avisa una vez)',
        ],
        self::IMPORT_VALIDATED => [
            'version' => 1,
            'required_fields' => ['import_job_id', 'entity', 'valid_rows', 'error_rows'],
            'description' => 'Importación validada: vista previa lista (con o sin filas con error)',
        ],
        self::IMPORT_STARTED => [
            'version' => 1,
            'required_fields' => ['import_job_id', 'entity'],
            'description' => 'Importación confirmada: se procesa en segundo plano por lotes',
        ],
        self::IMPORT_COMPLETED => [
            'version' => 1,
            'required_fields' => ['import_job_id', 'entity', 'imported_rows'],
            'description' => 'Importación terminada con su informe de lote',
        ],
        self::IMPORT_FAILED => [
            'version' => 1,
            'required_fields' => ['import_job_id', 'entity'],
            'description' => 'Importación fallida: se deshizo lo que alcanzó a cargar',
        ],
        self::IMPORT_REVERTED => [
            'version' => 1,
            'required_fields' => ['import_job_id', 'entity', 'reverted_rows'],
            'description' => 'Lote importado deshecho',
        ],
        self::COUNTRY_PACK_APPLIED => [
            'version' => 1,
            'required_fields' => ['country_pack_code', 'version', 'created'],
            'description' => 'Paquete de país aplicado a la organización con lo que creó cada parte (IMP-4)',
        ],
        self::ONBOARDING_SETUP_COMPLETED => [
            'version' => 1,
            'required_fields' => ['steps'],
            'description' => 'Puesta en marcha guiada terminada: todos los pasos hechos u omitidos (IMP-3)',
        ],
        self::TENANT_STATUS_CHANGED => [
            'version' => 1,
            'required_fields' => ['tenant_id', 'from_status', 'to_status', 'reason'],
            'description' => 'La organización cambió de estado desde la consola (suspensión, bloqueo, reactivación, baja)',
        ],
        self::TENANT_MODULE_CHANGED => [
            'version' => 1,
            'required_fields' => ['tenant_id', 'module_code', 'status'],
            'description' => 'Un módulo comercial se activó o se retiró en la organización',
        ],
        self::SUBSCRIPTION_CHANGED => [
            'version' => 1,
            'required_fields' => ['tenant_id', 'subscription_id', 'plan_code', 'status'],
            'description' => 'La suscripción de la organización cambió de plan, complementos, datos o estado',
        ],
        self::LIMIT_WARNING => [
            'version' => 1,
            'required_fields' => ['metric', 'current', 'limit', 'percent'],
            'description' => 'El consumo de una métrica del plan llegó al porcentaje de aviso (limites.porcentaje_alerta)',
        ],
        self::LIMIT_REACHED => [
            'version' => 1,
            'required_fields' => ['metric', 'current', 'limit', 'percent'],
            'description' => 'El consumo de una métrica llegó al tope del plan: se bloquean nuevas altas o subidas',
        ],
        self::COMPANY_CREATED => [
            'version' => 1,
            'required_fields' => ['legal_entity_id', 'country_code'],
            'description' => 'Empresa (razón social) creada en el grupo',
        ],
        self::COMPANY_ARCHIVED => [
            'version' => 1,
            'required_fields' => ['legal_entity_id'],
            'description' => 'Empresa archivada: deja de aceptar altas',
        ],
        self::ORG_UNIT_CREATED => [
            'version' => 1,
            'required_fields' => ['org_unit_id', 'parent_id', 'effective_on'],
            'description' => 'Unidad organizativa creada (rige desde effective_on)',
        ],
        self::ORG_UNIT_MOVED => [
            'version' => 1,
            'required_fields' => ['org_unit_id', 'from_parent_id', 'to_parent_id', 'effective_on'],
            'description' => 'Unidad movida a otra superior desde una fecha; organigrama y alcances se recalculan ese día',
        ],
        self::ORG_UNIT_MANAGER_CHANGED => [
            'version' => 1,
            'required_fields' => ['org_unit_id', 'from_position_id', 'to_position_id', 'effective_on'],
            'description' => 'Cambió el responsable de la unidad desde una fecha (RN-ORG-03)',
        ],
        self::ORG_UNIT_ARCHIVED => [
            'version' => 1,
            'required_fields' => ['org_unit_id', 'effective_on'],
            'description' => 'Unidad archivada: sin posiciones activas ni subunidades (RN-ORG-01)',
        ],
        self::POSITION_APPROVED => [
            'version' => 1,
            'required_fields' => ['position_id', 'effective_on'],
            'description' => 'Posición aprobada (ME-03)',
        ],
        self::POSITION_OPENED => [
            'version' => 1,
            'required_fields' => ['position_id', 'effective_on'],
            'description' => 'Reclutamiento abierto sobre la posición',
        ],
        self::POSITION_FILLED => [
            'version' => 1,
            'required_fields' => ['position_id', 'occupied_seats', 'effective_on'],
            'description' => 'La posición quedó ocupada según sus asignaciones vigentes (POS-2)',
        ],
        self::POSITION_VACATED => [
            'version' => 1,
            'required_fields' => ['position_id', 'occupied_seats', 'effective_on'],
            'description' => 'La posición quedó vacante: cese, traslado o reclutamiento cancelado (RN-POS-04)',
        ],
        self::POSITION_FROZEN => [
            'version' => 1,
            'required_fields' => ['position_id', 'reason', 'effective_on'],
            'description' => 'Posición congelada: no admite vacantes ni ocupantes (RN-POS-03)',
        ],
        self::POSITION_UNFROZEN => [
            'version' => 1,
            'required_fields' => ['position_id', 'effective_on'],
            'description' => 'Posición descongelada: vuelve a estar vacante',
        ],
        self::POSITION_CLOSED => [
            'version' => 1,
            'required_fields' => ['position_id', 'reason', 'effective_on'],
            'description' => 'Posición rechazada o cerrada; libera presupuesto (RN-POS-06)',
        ],
        self::POSITION_REQUESTED => [
            'version' => 1,
            'required_fields' => ['position_request_id', 'org_unit_id', 'job_profile_id', 'quantity', 'over_budget', 'approval_request_id'],
            'description' => 'Solicitud de posición enviada al flujo de aprobación; over_budget si supera el presupuesto de la unidad (RN-POS-05)',
        ],
        self::POSITION_REQUEST_APPROVED => [
            'version' => 1,
            'required_fields' => ['position_request_id', 'org_unit_id', 'quantity'],
            'description' => 'Solicitud de posición aprobada: sus posiciones quedaron en «aprobada» (ME-04)',
        ],
        self::POSITION_REQUEST_REJECTED => [
            'version' => 1,
            'required_fields' => ['position_request_id', 'reason'],
            'description' => 'Solicitud de posición rechazada con motivo (ME-04)',
        ],
        self::POSITION_REQUEST_CANCELLED => [
            'version' => 1,
            'required_fields' => ['position_request_id'],
            'description' => 'Solicitud de posición cancelada por el solicitante (ME-04)',
        ],
        self::ABSENCE_REQUESTED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'Ausencia enviada: en aprobación o pendiente de sustento (RN-AUS-01)',
        ],
        self::ABSENCE_APPROVED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'Ausencia aprobada por el flujo o registrada por RRHH: ocupa calendario y asistencia',
        ],
        self::ABSENCE_REJECTED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'Ausencia rechazada por el flujo o vencida sin sustento',
        ],
        self::ABSENCE_CANCELLED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'Ausencia cancelada antes de su inicio: revierte sus efectos',
        ],
        self::ABSENCE_STARTED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'La ausencia aprobada empezó (en curso)',
        ],
        self::ABSENCE_ENDED => [
            'version' => 1,
            'required_fields' => ['absence_request_id', 'employment_id', 'absence_type_id', 'starts_on', 'ends_on', 'affects_attendance', 'status'],
            'description' => 'La ausencia terminó (finalizada); cierra su efecto en planilla',
        ],
        self::ABSENCE_SUBSIDY_STARTED => [
            'version' => 1,
            'required_fields' => ['subsidy_case_id', 'absence_request_id', 'employment_id', 'kind', 'subsidized_days'],
            'description' => 'Un descanso aprobado tiene días de subsidio: se abre su expediente de reembolso (AUS-3)',
        ],
        self::JOB_PROFILE_CREATED => [
            'version' => 1,
            'required_fields' => ['job_profile_id', 'version'],
            'description' => 'Puesto de catálogo creado (ORG-5)',
        ],
        self::JOB_PROFILE_UPDATED => [
            'version' => 1,
            'required_fields' => ['job_profile_id', 'version', 'description_changed'],
            'description' => 'Puesto de catálogo actualizado; version es la de su descripción vigente (ORG-5)',
        ],
        self::USER_INVITED => [
            'version' => 1,
            'required_fields' => ['user_id', 'user_token_id', 'token_enc', 'expires_at'],
            'description' => 'Invitación emitida o reenviada (token cifrado, nunca en claro)',
        ],
        self::USER_MESSAGE_CODE_ISSUED => [
            'version' => 1,
            'required_fields' => ['user_id', 'purpose', 'channel', 'code_enc', 'minutes'],
            'description' => 'Código de un solo uso para ingresar o activar la cuenta sin correo (cifrado, nunca en claro)',
        ],
        self::USER_ACTIVATED => [
            'version' => 1,
            'required_fields' => ['user_id'],
            'description' => 'Cuenta activada o reactivada',
        ],
        self::USER_SUSPENDED => [
            'version' => 1,
            'required_fields' => ['user_id', 'reason'],
            'description' => 'Cuenta bloqueada; sesiones cerradas',
        ],
        self::DOCUMENT_UPLOADED => [
            'version' => 1,
            'required_fields' => ['stored_file_id', 'original_name', 'mime_type'],
            'description' => 'Documento almacenado en repositorio',
        ],
        self::DOCUMENT_APPROVED => ['version' => 1, 'required_fields' => ['document_id', 'category_id'], 'description' => 'Un documento entregado por el colaborador fue aprobado en la revisión (ME-12)'],
        self::DOCUMENT_REJECTED => ['version' => 1, 'required_fields' => ['document_id', 'category_id', 'reason'], 'description' => 'Un documento entregado fue rechazado con motivo: se avisa a quien lo subió (ME-12)'],
        self::DOCUMENT_ARCHIVED => ['version' => 1, 'required_fields' => ['document_id', 'category_id'], 'description' => 'Un documento del legajo se archivó (sigue consultable; no se borra)'],
        self::DOCUMENT_EXPIRING => ['version' => 1, 'required_fields' => ['document_id', 'category_id', 'expires_on', 'days_left'], 'description' => 'Un documento vence dentro de la anticipación de su tipo: aviso al responsable y al titular (RN-DOC-09)'],
        self::DOCUMENT_EXPIRED => ['version' => 1, 'required_fields' => ['document_id', 'category_id', 'expires_on'], 'description' => 'Un documento llegó a su fecha de vencimiento y pasó a Vencido (RN-DOC-09)'],
        self::DOCUMENT_REQUESTED => ['version' => 1, 'required_fields' => ['document_request_id', 'category_id', 'employment_id'], 'description' => 'RRHH pidió un documento a un colaborador con fecha límite (DOC-3): se le avisa para que lo entregue desde el portal'],
        self::DOCUMENT_GENERATION_STARTED => ['version' => 1, 'required_fields' => ['batch_id', 'template_id'], 'description' => 'Se pidió generar documentos para varias personas: el lote se procesa en segundo plano con su avance (DOC-5)'],
        self::EXPORT_REQUESTED => ['version' => 1, 'required_fields' => ['export_id', 'resource'], 'description' => 'Se pidió una exportación que pasa el umbral de segundo plano: se genera fuera de la solicitud y se avisa con enlace (UI-11)'],
        self::DOCUMENT_GENERATED => ['version' => 1, 'required_fields' => ['document_id', 'category_id', 'template_version_id'], 'description' => 'Se generó un documento PDF desde una plantilla y entró al legajo con la versión de la plantilla con que se emitió (DOC-5 · RN-DOC-11)'],
        self::SIGNATURE_COMPLETED => [
            'version' => 1,
            'required_fields' => ['signature_request_id', 'completed_at'],
            'description' => 'Solicitud de firma completada',
        ],
    ];

    /**
     * @return array<string, array{version: int, required_fields: array<int, string>, description: string}>
     */
    public static function all(): array
    {
        return self::$catalog;
    }

    public static function exists(string $eventType): bool
    {
        return isset(self::$catalog[$eventType]);
    }

    /**
     * @return array{version: int, required_fields: array<int, string>, description: string}|null
     */
    public static function getSchema(string $eventType): ?array
    {
        return self::$catalog[$eventType] ?? null;
    }

    /**
     * Valida que el payload cumpla con los campos obligatorios del catálogo versionado.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function validatePayload(string $eventType, array $payload): void
    {
        $schema = self::getSchema($eventType);

        if ($schema === null) {
            throw new InvalidArgumentException("Evento '{$eventType}' no está registrado en el catálogo de eventos de dominio.");
        }

        foreach ($schema['required_fields'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Payload de evento '{$eventType}' (v{$schema['version']}) carece del campo obligatorio '{$field}'.");
            }
        }
    }
}
