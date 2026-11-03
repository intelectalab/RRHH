<?php

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\PlatformModule;

/**
 * PlatformService — Caso de uso de plataforma.
 */
class PlatformService
{
    public function getModule(string $code): PlatformModule
    {
        return new PlatformModule(
            code: $code,
            name: 'Platform',
            active: true,
        );
    }
}
