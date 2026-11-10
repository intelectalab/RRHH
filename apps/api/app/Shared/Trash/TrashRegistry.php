<?php

namespace App\Shared\Trash;

final class TrashRegistry
{
    /** @var array<string, TrashSource> */
    private array $sources = [];

    public function register(TrashSource $source): void
    {
        $this->sources[$source->type()] = $source;
    }

    /**
     * @return array<string, TrashSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
