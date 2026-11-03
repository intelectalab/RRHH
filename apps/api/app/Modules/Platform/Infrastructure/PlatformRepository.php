<?php

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Platform\Domain\PlatformModule;

/**
 * PlatformRepository — Implementación de persistencia de plataforma.
 */
class PlatformRepository
{
    public function find(string $code): ?PlatformModule
    {
        return new PlatformModule(code: $code, name: 'Platform');
    }
}
