<?php

namespace App\Modules\Organization\Infrastructure\Usage;

use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Usage\UsageMetrics;
use App\Shared\Usage\UsageSource;

/** SAS-6 · RN-TEN-07 · Empresas activas frente al límite del plan. */
final class LegalEntitiesUsageSource implements UsageSource
{
    public function metric(): string
    {
        return UsageMetrics::LEGAL_ENTITIES;
    }

    public function measure(): int
    {
        return LegalEntity::query()->where('status', 'active')->count() * 100;
    }
}
