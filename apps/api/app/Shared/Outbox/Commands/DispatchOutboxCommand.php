<?php

namespace App\Shared\Outbox\Commands;

use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchOutboxCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'outbox:dispatch {--limit=50} {--max-attempts=3}';

    /**
     * @var string
     */
    protected $description = 'Despacha eventos de dominio pendientes en la tabla outbox_events';

    public function handle(OutboxService $outboxService): int
    {
        $limit = (int) $this->option('limit');
        $maxAttempts = (int) $this->option('max-attempts');

        return self::processOutbox($outboxService, $limit, $maxAttempts);
    }

    /**
     * Lógica central de despacho, extraída para ser llamable desde tests y desde el comando Artisan.
     * Implementada como método estático para no depender de la consola inicializada.
     */
    /**
     * @param  list<string>|null  $onlyIds  entrega inmediata de eventos concretos (OutboxService::deliverSoon)
     */
    public static function processOutbox(OutboxService $outboxService, int $limit = 50, int $maxAttempts = 3, ?array $onlyIds = null): int
    {
        $events = OutboxEvent::whereIn('status', [OutboxEvent::STATUS_PENDING, OutboxEvent::STATUS_FAILED])
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('occurred_at', 'asc')
            ->limit($limit)
            ->get();

        $dispatchedCount = 0;
        $failedCount = 0;

        foreach ($events as $event) {
            $event->update(['status' => OutboxEvent::STATUS_PROCESSING]);

            $subscribers = $outboxService->getSubscribersFor($event->event_type);
            $eventFailed = false;
            $errorMessage = null;

            foreach ($subscribers as $subscriber) {
                try {
                    $outboxService->getTracker()->executeIdempotently(
                        $event->tenant_id,
                        $subscriber->consumerName(),
                        $event->id,
                        fn () => $subscriber->handle($event)
                    );
                } catch (Throwable $e) {
                    $eventFailed = true;
                    $errorMessage = $e->getMessage();
                    break;
                }
            }

            if ($eventFailed) {
                $failedCount++;
                $newAttempts = $event->attempts + 1;
                $isDead = $newAttempts >= $maxAttempts;

                $event->update([
                    'attempts' => $newAttempts,
                    'status' => $isDead ? OutboxEvent::STATUS_DEAD : OutboxEvent::STATUS_FAILED,
                    'last_error' => $errorMessage,
                    'next_attempt_at' => $isDead ? null : now()->addSeconds((int) (2 ** $newAttempts * 5)),
                ]);

                Log::warning("outbox:dispatch — evento {$event->id} ({$event->event_type}) falló en intento {$newAttempts}: {$errorMessage}");
            } else {
                $dispatchedCount++;
                $event->update([
                    'status' => OutboxEvent::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'last_error' => null,
                ]);
            }
        }

        Log::info("outbox:dispatch completado: {$dispatchedCount} publicados, {$failedCount} fallidos.");

        return Command::SUCCESS;
    }
}
