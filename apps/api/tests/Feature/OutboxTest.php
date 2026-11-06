<?php

namespace Tests\Feature;

use App\Shared\Outbox\Commands\DispatchOutboxCommand;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Outbox\ProcessedEventTracker;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\TenantTestHelper;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use DatabaseTransactions;
    use TenantTestHelper;

    private string $tenantId;

    private OutboxService $outboxService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->createAndBindTenant();

        // Instancia aislada por prueba para evitar que suscriptores se acumulen entre tests
        $this->outboxService = new OutboxService(app(ProcessedEventTracker::class));
        $this->app->instance(OutboxService::class, $this->outboxService);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ejecuta la lógica de despacho del outbox directamente (sin pasar por la consola Artisan).
     */
    private function runDispatch(int $maxAttempts = 3): void
    {
        DispatchOutboxCommand::processOutbox($this->outboxService, 50, $maxAttempts);
    }

    private function writeEvent(string $eventType, string $aggregateType, array $payload, ?string $aggregateId = null): OutboxEvent
    {
        return $this->outboxService->record(
            eventType: $eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId ?? (string) Str::uuid(),
            payload: $payload,
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_event_is_persisted_only_if_originating_transaction_commits(): void
    {
        $aggregateId = (string) Str::uuid();

        // Caso 1: Transacción anidada revertida → evento NO debe sobrevivir al rollback
        try {
            DB::transaction(function () use ($aggregateId) {
                $this->outboxService->record(
                    eventType: DomainEventTypes::EMPLOYEE_HIRED,
                    aggregateType: 'employee',
                    aggregateId: $aggregateId,
                    payload: [
                        'employee_id' => $aggregateId,
                        'first_name' => 'Carlos',
                        'last_name' => 'García',
                        'hire_date' => '2026-11-01',
                    ],
                );

                throw new Exception('Simulated rollback');
            });
        } catch (Exception) {
            // Esperado
        }

        $this->assertDatabaseMissing('outbox_events', [
            'tenant_id' => $this->tenantId,
            'aggregate_id' => $aggregateId,
        ]);

        // Caso 2: Inserción directa (commit) → evento persiste como pendiente
        $committedAggregateId = (string) Str::uuid();

        $this->outboxService->record(
            eventType: DomainEventTypes::EMPLOYEE_HIRED,
            aggregateType: 'employee',
            aggregateId: $committedAggregateId,
            payload: [
                'employee_id' => $committedAggregateId,
                'first_name' => 'María',
                'last_name' => 'Quispe',
                'hire_date' => '2026-11-01',
            ],
        );

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $this->tenantId,
            'aggregate_id' => $committedAggregateId,
            'status' => OutboxEvent::STATUS_PENDING,
        ]);
    }

    public function test_dispatch_command_publishes_pending_events_to_subscribers(): void
    {
        $aggregateId = (string) Str::uuid();
        $handled = false;

        $subscriber = new class($handled) implements DomainEventSubscriber
        {
            public function __construct(private bool &$handledRef) {}

            public function consumerName(): string
            {
                return 'test_notifier';
            }

            public function handles(): array
            {
                return [DomainEventTypes::EMPLOYEE_HIRED];
            }

            public function handle(OutboxEvent $event): void
            {
                $this->handledRef = true;
            }
        };

        $this->outboxService->subscribe($subscriber);

        $event = $this->writeEvent(
            eventType: DomainEventTypes::EMPLOYEE_HIRED,
            aggregateType: 'employee',
            payload: [
                'employee_id' => $aggregateId,
                'first_name' => 'Rosa',
                'last_name' => 'Flores',
                'hire_date' => '2026-11-01',
            ],
            aggregateId: $aggregateId,
        );

        $this->runDispatch();

        $this->assertTrue($handled, 'El suscriptor debió ejecutarse al despachar el evento');

        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_PUBLISHED, $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertNull($event->last_error);

        // El consumidor queda registrado en processed_events
        $this->assertDatabaseHas('processed_events', [
            'tenant_id' => $this->tenantId,
            'consumer' => 'test_notifier',
            'event_id' => $event->id,
        ]);
    }

    public function test_failing_subscriber_retries_and_lands_in_dead_letter_with_cause(): void
    {
        $aggregateId = (string) Str::uuid();

        $failingSubscriber = new class implements DomainEventSubscriber
        {
            public function consumerName(): string
            {
                return 'failing_integration';
            }

            public function handles(): array
            {
                return [DomainEventTypes::LEAVE_APPROVED];
            }

            public function handle(OutboxEvent $event): void
            {
                throw new Exception('External webhook down: HTTP 503');
            }
        };

        $this->outboxService->subscribe($failingSubscriber);

        $event = $this->writeEvent(
            eventType: DomainEventTypes::LEAVE_APPROVED,
            aggregateType: 'leave_request',
            payload: [
                'leave_id' => $aggregateId,
                'employee_id' => (string) Str::uuid(),
                'approved_by' => (string) Str::uuid(),
            ],
            aggregateId: $aggregateId,
        );

        // Primer intento → failed, attempts = 1
        $this->runDispatch(maxAttempts: 3);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_FAILED, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertStringContainsString('External webhook down: HTTP 503', (string) $event->last_error);
        $this->assertNotNull($event->next_attempt_at);

        // Segundo intento
        $event->update(['next_attempt_at' => now()->subMinute()]);
        $this->runDispatch(maxAttempts: 3);
        $event->refresh();
        $this->assertSame(2, $event->attempts);
        $this->assertSame(OutboxEvent::STATUS_FAILED, $event->status);

        // Tercer intento → supera max_attempts → DEAD
        $event->update(['next_attempt_at' => now()->subMinute()]);
        $this->runDispatch(maxAttempts: 3);
        $event->refresh();
        $this->assertSame(3, $event->attempts);
        $this->assertSame(OutboxEvent::STATUS_DEAD, $event->status);
        $this->assertStringContainsString('External webhook down: HTTP 503', (string) $event->last_error);
    }

    public function test_subscriber_does_not_duplicate_effects_on_replay(): void
    {
        $tracker = app(ProcessedEventTracker::class);
        $eventId = (string) Str::uuid();
        $counter = 0;

        $action = function () use (&$counter) {
            $counter++;
        };

        // Primera ejecución: ejecuta el efecto
        $executed = $tracker->executeIdempotently($this->tenantId, 'accounting_sync', $eventId, $action);
        $this->assertTrue($executed);
        $this->assertSame(1, $counter);

        // Segunda ejecución con mismo key: NO ejecuta (idempotente)
        $executed = $tracker->executeIdempotently($this->tenantId, 'accounting_sync', $eventId, $action);
        $this->assertFalse($executed);
        $this->assertSame(1, $counter);

        // Consumidor distinto para el mismo evento: sí ejecuta
        $executed = $tracker->executeIdempotently($this->tenantId, 'analytics_ingest', $eventId, $action);
        $this->assertTrue($executed);
        $this->assertSame(2, $counter);
    }

    public function test_event_catalog_has_versioned_payload_schema_and_validates(): void
    {
        // 1. Catálogo expone versiones
        $catalog = DomainEventTypes::all();
        $this->assertArrayHasKey(DomainEventTypes::EMPLOYEE_HIRED, $catalog);
        $this->assertSame(1, $catalog[DomainEventTypes::EMPLOYEE_HIRED]['version']);
        $this->assertNotEmpty($catalog[DomainEventTypes::EMPLOYEE_HIRED]['required_fields']);

        // 2. Payload válido no lanza excepción
        DomainEventTypes::validatePayload(DomainEventTypes::EMPLOYEE_HIRED, [
            'employee_id' => (string) Str::uuid(),
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'hire_date' => '2026-11-01',
        ]);

        // 3. Payload incompleto lanza excepción con detalle del campo faltante
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("campo obligatorio 'hire_date'");

        DomainEventTypes::validatePayload(DomainEventTypes::EMPLOYEE_HIRED, [
            'employee_id' => (string) Str::uuid(),
            'first_name' => 'Ana',
            'last_name' => 'Torres',
        ]);
    }

    public function test_record_throws_exception_if_no_active_database_transaction(): void
    {
        // Guardamos y liberamos transacciones activas de la prueba para simular ejecución fuera de transacción
        $level = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('OutboxService::record() debe llamarse dentro de una transacción de base de datos activa.');

            $this->outboxService->record(
                eventType: DomainEventTypes::DOCUMENT_UPLOADED,
                aggregateType: 'stored_file',
                aggregateId: (string) Str::uuid(),
                payload: [
                    'file_id' => (string) Str::uuid(),
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'storage_key' => 'tenants/test/file.pdf',
                ],
                tenantId: $this->tenantId,
            );
        } finally {
            // Restaurar nivel de transacción para la infraestructura de DatabaseTransactions de PHPUnit
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    }
}
