<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\Automation\RuleConditions;
use App\Shared\Outbox\DomainEventTypes;

/**
 * WFL-5 · Lo que una regla puede usar: eventos del catálogo de dominio (CUANDO), operadores (SI) y la
 * lista cerrada de acciones (ENTONCES). Nada de alto impacto (RN-WFL-09): no hay acción para contratar,
 * cesar, sancionar, cambiar sueldo ni aprobar; iniciar una aprobación deja la decisión a una persona.
 */
final class AutomationCatalog
{
    public const ACTIONS = ['create_task', 'notify', 'start_approval'];

    /**
     * Eventos que no se ofrecen como disparador: llevan credenciales (enlaces o códigos cifrados) o son
     * la propia entrega de avisos (una regla sobre ellos se dispararía a sí misma).
     */
    private const EXCLUDED_EVENTS = [
        DomainEventTypes::USER_PASSWORD_RESET_REQUESTED,
        DomainEventTypes::USER_INVITED,
        DomainEventTypes::USER_MESSAGE_CODE_ISSUED,
        DomainEventTypes::NOTIFICATION_DELIVERY_REQUESTED,
    ];

    /**
     * Campos que nunca llegan a condiciones, plantillas ni al registro de ejecuciones: montos de sueldo o
     * planilla (permiso por campo, regla 6) y secretos.
     */
    private const REDACTED_FIELDS = ['new_amount', 'old_amount', 'amount', 'total_net', 'total_gross', 'salary'];

    private const REDACTED_PATTERN = '/(_enc|_hash|password|token|secret)$/i';

    /**
     * @return list<string>
     */
    public function triggerEvents(): array
    {
        return array_values(array_filter(array_keys(DomainEventTypes::all()), fn (string $type) => ! in_array($type, self::EXCLUDED_EVENTS, true)));
    }

    public function isTrigger(string $eventType): bool
    {
        return in_array($eventType, $this->triggerEvents(), true);
    }

    /**
     * Datos del evento que la regla puede ver.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && (in_array($key, self::REDACTED_FIELDS, true) || preg_match(self::REDACTED_PATTERN, $key) === 1)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $out;
    }

    /**
     * @return array{triggers: list<array{event: string, description: string, fields: list<string>}>, operators: list<string>, actions: list<array{type: string, params: list<string>}>, subject_types: list<string>}
     */
    public function describe(): array
    {
        $triggers = [];
        foreach ($this->triggerEvents() as $type) {
            $schema = DomainEventTypes::getSchema($type) ?? ['required_fields' => [], 'description' => ''];
            $fields = array_values(array_filter($schema['required_fields'], fn (string $f) => ! in_array($f, self::REDACTED_FIELDS, true) && preg_match(self::REDACTED_PATTERN, $f) !== 1));
            $triggers[] = ['event' => $type, 'description' => (string) $schema['description'], 'fields' => $fields];
        }

        return [
            'triggers' => $triggers,
            'operators' => RuleConditions::OPERATORS,
            'actions' => [
                ['type' => 'create_task', 'params' => ['title', 'description', 'assignee_user_id', 'assignee_role_id', 'assignee_field', 'due_in_days', 'priority']],
                ['type' => 'notify', 'params' => ['title', 'message', 'user_ids', 'role_ids', 'user_field']],
                ['type' => 'start_approval', 'params' => ['subject_type', 'subject_id_field', 'requester_field']],
            ],
            'subject_types' => $this->subjectTypes(),
        ];
    }

    /**
     * Tipos de trámite que admiten aprobación (config/workflow.php).
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        $types = config('workflow.subject_types', []);

        return is_array($types) ? array_map('strval', array_keys($types)) : [];
    }
}
