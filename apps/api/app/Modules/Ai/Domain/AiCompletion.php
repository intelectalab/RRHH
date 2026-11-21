<?php

namespace App\Modules\Ai\Domain;

/**
 * IAR-1 · Respuesta del proveedor de IA: texto, explicación de cómo se armó, ids de las fuentes citadas y
 * consumo (tokens) para la bitácora (IAR-2).
 */
final readonly class AiCompletion
{
    /**
     * @param  list<string>  $citations
     */
    public function __construct(
        public string $text,
        public string $explanation,
        public array $citations,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
    ) {}
}
