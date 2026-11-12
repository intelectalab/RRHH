<?php

namespace App\Shared\Usage\Sources;

use App\Shared\Cache\TenantCache;
use App\Shared\Usage\UsageAmount;
use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · Llamadas a la API del mes en curso. Cada petición suma en la caché (sin escribir en la base por
 * petición); la foto diaria (usage:snapshot) la guarda en tenant_usage_counters. Si la caché se perdió,
 * se parte del último valor guardado.
 */
final class ApiCallsUsageSource implements UsageSource
{
    public function __construct(private readonly TenantCache $cache) {}

    public function metric(): string
    {
        return UsageMetrics::API_CALLS;
    }

    public function measure(): int
    {
        return $this->count() * 100;
    }

    public function count(): int
    {
        $cached = $this->cache->get($this->key());
        if (is_numeric($cached)) {
            return (int) $cached;
        }
        $stored = DB::table('tenant_usage_counters')->where('metric_code', $this->metric())
            ->where('period_start', UsageMetrics::periodStart($this->metric()))->value('value');

        return $stored === null ? 0 : intdiv(UsageAmount::toCents((string) $stored), 100);
    }

    public function increment(): void
    {
        $key = $this->key();
        if (! $this->cache->has($key)) {
            $this->cache->put($key, $this->count(), now()->addDays(40));
        }
        $this->cache->increment($key);
    }

    private function key(): string
    {
        return 'usage:api_calls:'.now()->format('Y-m');
    }
}
