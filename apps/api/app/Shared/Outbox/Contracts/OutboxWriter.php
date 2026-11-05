<?php

namespace App\Shared\Outbox\Contracts;

use App\Shared\Outbox\Models\OutboxEvent;

interface OutboxWriter
{
    /**
     * Registra un evento en la bandeja de salida (outbox) dentro de la transacción activa.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $tenantId = null,
        ?string $correlationId = null
    ): OutboxEvent;
}
