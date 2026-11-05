<?php

namespace App\Shared\Outbox;

use App\Shared\Outbox\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessedEventTracker
{
    /**
     * Comprueba si un evento ya fue procesado por un consumidor en un tenant.
     */
    public function hasBeenProcessed(string $tenantId, string $consumer, string $eventId): bool
    {
        return ProcessedEvent::where('tenant_id', $tenantId)
            ->where('consumer', $consumer)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * Marca el evento como procesado para dicho consumidor.
     */
    public function markAsProcessed(string $tenantId, string $consumer, string $eventId): ProcessedEvent
    {
        return ProcessedEvent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'consumer' => $consumer,
            'event_id' => $eventId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Ejecuta una acción de suscriptor de forma idempotente:
     * Si ya fue procesado, no repite el efecto.
     * Si no fue procesado, ejecuta la acción y registra en processed_events en una transacción.
     */
    public function executeIdempotently(string $tenantId, string $consumer, string $eventId, callable $action): bool
    {
        if ($this->hasBeenProcessed($tenantId, $consumer, $eventId)) {
            return false; // Ya procesado, no repetir efecto
        }

        DB::transaction(function () use ($tenantId, $consumer, $eventId, $action) {
            $action();
            $this->markAsProcessed($tenantId, $consumer, $eventId);
        });

        return true;
    }
}
