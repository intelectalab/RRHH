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
        self::USER_INVITED => [
            'version' => 1,
            'required_fields' => ['user_id', 'user_token_id', 'token_enc', 'expires_at'],
            'description' => 'Invitación emitida o reenviada (token cifrado, nunca en claro)',
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
