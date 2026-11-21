<?php

namespace App\Modules\Ai\Domain;

/**
 * IAR-1 · Pedido al proveedor de IA: el caso de uso, la pregunta y las fuentes permitidas (id, título y texto).
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array{id: string, title: string, body: string}>  $sources
     */
    public function __construct(
        public string $useCase,
        public string $question,
        public string $locale,
        public array $sources,
    ) {}
}
