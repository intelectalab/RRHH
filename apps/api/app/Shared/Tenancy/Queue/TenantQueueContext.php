<?php

namespace App\Shared\Tenancy\Queue;

use App\Shared\Tenancy\RunsWithoutTenant;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Tenancy\TenantContextRequiredException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Contexto de organización en las colas (docs/02-arquitectura.md §5.1, TEN-6).
 *
 * - Al encolar: el payload lleva `tenant_id` de la organización activa. Si no hay
 *   organización activa y el trabajo no implementa RunsWithoutTenant, no se encola.
 * - Al ejecutar: se fija la organización del payload antes del trabajo y se
 *   restaura el contexto previo al terminar (funciona también con la cola sync).
 */
final class TenantQueueContext
{
    /** @var array<int, string|null> */
    private static array $previous = [];

    public static function register(): void
    {
        Queue::createPayloadUsing(static fn (?string $connection, ?string $queue, array $payload): array => self::payloadFor($payload));

        Event::listen(JobProcessing::class, static fn (JobProcessing $e) => self::enter($e->job));
        Event::listen(JobProcessed::class, static fn () => self::leave());
        Event::listen(JobExceptionOccurred::class, static fn () => self::leave());
        Event::listen(JobFailed::class, static fn () => self::leave());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function payloadFor(array $payload): array
    {
        $tenantId = TenancyScope::current();
        $class = self::jobClass($payload);

        if ($tenantId === null) {
            if ($class !== null && is_subclass_of($class, RunsWithoutTenant::class)) {
                return ['tenant_id' => null];
            }

            throw new TenantContextRequiredException(
                'Un trabajo sin organización declarada no se encola: '.($class ?? 'desconocido').'.'
            );
        }

        return ['tenant_id' => $tenantId, 'tags' => array_values(array_unique(array_merge(
            (array) ($payload['tags'] ?? []),
            ['tenant:'.$tenantId],
        )))];
    }

    private static function enter(Job $job): void
    {
        self::$previous[] = TenancyScope::current();

        /** @var mixed $tenantId */
        $tenantId = $job->payload()['tenant_id'] ?? null;
        if (is_string($tenantId) && $tenantId !== '') {
            TenancyScope::set($tenantId);
        } else {
            TenancyScope::clear();
        }
    }

    private static function leave(): void
    {
        if (self::$previous === []) {
            return;
        }

        $previous = array_pop(self::$previous);
        if ($previous !== null) {
            TenancyScope::set($previous);
        } else {
            TenancyScope::clear();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function jobClass(array $payload): ?string
    {
        // Al ejecutarse el gancho, data.commandName aún es la instancia del trabajo.
        $name = $payload['data']['commandName'] ?? $payload['displayName'] ?? null;
        if (is_object($name)) {
            return $name::class;
        }

        return is_string($name) && class_exists($name) ? $name : null;
    }
}
