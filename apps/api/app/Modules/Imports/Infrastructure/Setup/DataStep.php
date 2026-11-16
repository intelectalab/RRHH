<?php

namespace App\Modules\Imports\Infrastructure\Setup;

use App\Shared\Setup\SetupStep;
use Closure;

/**
 * IMP-3 · Paso del asistente que se da por hecho cuando su dato existe en la organización (se consulta
 * con el contexto de organización activo, bajo RLS).
 */
final class DataStep implements SetupStep
{
    /** @param  Closure(): bool  $done */
    public function __construct(
        private readonly string $code,
        private readonly int $order,
        private readonly string $link,
        private readonly Closure $done,
        private readonly string $module = 'core',
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function moduleCode(): string
    {
        return $this->module;
    }

    public function link(): string
    {
        return $this->link;
    }

    public function isDone(): bool
    {
        return ($this->done)();
    }
}
