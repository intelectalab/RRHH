<?php

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Domain\AiCompletion;
use App\Modules\Ai\Domain\AiPrompt;
use App\Modules\Ai\Domain\Ports\AiProvider;

/**
 * IAR-1 · Proveedor de IA falso y determinista, para desarrollo, pruebas y la evaluación de calidad en CI
 * (IAR-11). Arma la respuesta con el primer párrafo de las fuentes recibidas, en el orden en que llegan, y cita
 * exactamente esas fuentes: la misma entrada da siempre la misma salida. Los tokens se estiman por palabras.
 */
final class FakeAiProvider implements AiProvider
{
    private const MAX_SOURCES = 3;

    /** @var (callable(AiPrompt): AiCompletion)|null */
    private $override = null;

    /**
     * Solo pruebas: sustituye la respuesta (por ejemplo, para simular una cita fuera de las fuentes).
     *
     * @param  (callable(AiPrompt): AiCompletion)|null  $fn
     */
    public function respondWith(?callable $fn): void
    {
        $this->override = $fn;
    }

    public function name(): string
    {
        return 'fake_ai';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function answer(AiPrompt $prompt): AiCompletion
    {
        if ($this->override !== null) {
            return ($this->override)($prompt);
        }
        $sources = array_slice($prompt->sources, 0, self::MAX_SOURCES);
        $parts = [];
        foreach ($sources as $s) {
            $blocks = preg_split('/\n\s*\n/', trim($s['body'])) ?: [''];
            $parts[] = $s['title'].': '.mb_substr(trim($blocks[0]), 0, 400);
        }
        $text = implode("\n\n", $parts);
        $in = $this->words($prompt->question);
        foreach ($prompt->sources as $s) {
            $in += $this->words($s['title'].' '.$s['body']);
        }

        return new AiCompletion(
            text: $text,
            explanation: (string) trans('ai.help.explanation', ['count' => count($sources)], $prompt->locale),
            citations: array_map(fn (array $s): string => $s['id'], $sources),
            inputTokens: $in,
            outputTokens: $this->words($text),
            model: 'fake-deterministic-1',
        );
    }

    private function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
