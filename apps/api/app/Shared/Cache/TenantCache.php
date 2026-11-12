<?php

namespace App\Shared\Cache;

use App\Shared\Tenancy\TenancyScope;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * TenantCache — Cache isolation per tenant.
 *
 * Ensures all cache operations are automatically partitioned by tenant_id.
 * Keys are namespaced as `tenant:{tenant_id}:{key}` to prevent cross-tenant data leaks.
 */
final class TenantCache
{
    public function __construct(
        private readonly ?string $tenantId = null,
        private readonly ?CacheRepository $store = null,
    ) {}

    /**
     * Resolve the active tenant ID, throwing if none is active.
     */
    public function activeTenantId(): string
    {
        $id = $this->tenantId ?? TenancyScope::current();

        if ($id === null || trim($id) === '') {
            throw new RuntimeException('TenantCache requires an active tenant context in TenancyScope or an explicit tenant ID.');
        }

        return $id;
    }

    /**
     * Format the scoped cache key for the active tenant.
     */
    public function scopedKey(string $key): string
    {
        return sprintf('tenant:%s:%s', $this->activeTenantId(), $key);
    }

    /**
     * Return a new TenantCache instance bound to the specified tenant ID.
     */
    public function forTenant(string $tenantId): self
    {
        return new self($tenantId, $this->store);
    }

    private function getStore(): CacheRepository
    {
        return $this->store ?? Cache::store();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getStore()->get($this->scopedKey($key), $default);
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->getStore()->put($this->scopedKey($key), $value, $ttl);
    }

    /**
     * @template TCacheValue
     *
     * @param  Closure(): TCacheValue  $callback
     * @return TCacheValue
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return $this->getStore()->remember($this->scopedKey($key), $ttl, $callback);
    }

    public function has(string $key): bool
    {
        return $this->getStore()->has($this->scopedKey($key));
    }

    public function forget(string $key): bool
    {
        return $this->getStore()->forget($this->scopedKey($key));
    }

    /** Suma 1 a un contador de la organización (SAS-6: llamadas a la API). */
    public function increment(string $key): int
    {
        return (int) $this->getStore()->increment($this->scopedKey($key));
    }
}
