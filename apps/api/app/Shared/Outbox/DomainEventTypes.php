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

    // Asistencia / Vacaciones
    public const LEAVE_REQUESTED = 'leave.requested.v1';

    public const LEAVE_APPROVED = 'leave.approved.v1';

    public const LEAVE_REJECTED = 'leave.rejected.v1';

    public const LEAVE_CANCELLED = 'leave.cancelled.v1';

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

    // Organización
    public const COMPANY_CREATED = 'company.created.v1';

    public const COMPANY_ARCHIVED = 'company.archived.v1';

    // Organización (SAS-2): cambios de estado decididos desde la consola.
    public const TENANT_STATUS_CHANGED = 'tenant.status_changed.v1';

    // Módulos de la organización (SAS-5).
    public const TENANT_MODULE_CHANGED = 'tenant.module_changed.v1';

    // Suscripción de la organización (SAS-4): plan, complementos o estado.
    public const SUBSCRIPTION_CHANGED = 'subscription.changed.v1';

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
