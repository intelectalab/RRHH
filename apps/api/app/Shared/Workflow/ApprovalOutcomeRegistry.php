<?php

namespace App\Shared\Workflow;

/**
 * WFL-4 · Registro de los módulos que aplican el resultado de sus trámites al instante. Cada módulo
 * registra su ApprovalOutcomeHandler en su ServiceProvider; un tipo sin handler recibe el resultado
 * solo por el outbox (approval.approved.v1 / approval.rejected.v1).
 */
final class ApprovalOutcomeRegistry
{
    /** @var array<string, ApprovalOutcomeHandler> */
    private array $handlers = [];

    public function register(ApprovalOutcomeHandler $handler): void
    {
        $this->handlers[$handler->subjectType()] = $handler;
    }

    public function for(string $subjectType): ?ApprovalOutcomeHandler
    {
        return $this->handlers[$subjectType] ?? null;
    }

    /**
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return array_keys($this->handlers);
    }
}
