<?php

namespace App\Shared\Documents;

/**
 * DOC-1 · Fuentes de documentos que otros módulos aportan al legajo (ver LinkedDocumentSource).
 */
final class LinkedDocumentRegistry
{
    /** @var array<string, LinkedDocumentSource> */
    private array $sources = [];

    public function register(LinkedDocumentSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /**
     * @return array<string, LinkedDocumentSource>
     */
    public function all(): array
    {
        return $this->sources;
    }

    public function get(string $key): ?LinkedDocumentSource
    {
        return $this->sources[$key] ?? null;
    }
}
