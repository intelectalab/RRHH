<?php

namespace App\Shared\Outbox\Contracts;

use App\Shared\Outbox\Models\OutboxEvent;

interface DomainEventSubscriber
{
    /**
     * Identificador único del consumidor para registro de idempotencia.
     */
    public function consumerName(): string;

    /**
     * Lista de tipos de eventos que maneja este suscriptor.
     *
     * @return array<int, string>
     */
    public function handles(): array;

    /**
     * Ejecuta el efecto del suscriptor.
     */
    public function handle(OutboxEvent $event): void;
}
