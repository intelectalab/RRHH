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

    /**
     * Pide entregar el evento al terminar la petición, sin esperar al despacho periódico.
     * Para mensajes que el usuario espera en el momento (códigos de un solo uso). Si falla,
     * el despacho periódico lo reintenta: el evento sigue en el outbox.
     */
    public function deliverSoon(OutboxEvent $event): void;
}
