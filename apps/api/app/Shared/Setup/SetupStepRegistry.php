<?php

namespace App\Shared\Setup;

/**
 * IMP-3 · Registro de los pasos del asistente de primeros pasos. Cada módulo registra los suyos.
 */
final class SetupStepRegistry
{
    /** @var array<string, SetupStep> */
    private array $steps = [];

    public function register(SetupStep $step): void
    {
        $this->steps[$step->code()] = $step;
    }

    public function get(string $code): ?SetupStep
    {
        return $this->steps[$code] ?? null;
    }

    /** @return list<SetupStep> */
    public function all(): array
    {
        $steps = array_values($this->steps);
        usort($steps, fn (SetupStep $a, SetupStep $b) => $a->order() <=> $b->order());

        return $steps;
    }
}
