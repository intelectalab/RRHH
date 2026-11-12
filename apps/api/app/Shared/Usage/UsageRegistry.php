<?php

namespace App\Shared\Usage;

final class UsageRegistry
{
    /** @var array<string, UsageSource> */
    private array $sources = [];

    public function register(UsageSource $source): void
    {
        $this->sources[$source->metric()] = $source;
    }

    public function get(string $metric): ?UsageSource
    {
        return $this->sources[$metric] ?? null;
    }

    /**
     * @return array<string, UsageSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
