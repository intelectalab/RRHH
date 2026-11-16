<?php

namespace App\Shared\Team;

/** MSS-4 · Registro de fuentes de fechas clave del equipo. */
final class TeamDateRegistry
{
    /** @var array<string, TeamDateSource> */
    private array $sources = [];

    public function register(TeamDateSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /**
     * @return array<string, TeamDateSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
