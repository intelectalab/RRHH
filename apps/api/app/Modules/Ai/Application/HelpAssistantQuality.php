<?php

namespace App\Modules\Ai\Application;

use Illuminate\Support\Facades\DB;

/**
 * IAR-11 · Calidad del asistente de ayuda en la organización: cuántas preguntas tuvo, cuántas quedaron sin
 * fuente, cuáles fueron (para escribir el artículo que falta) y qué respuestas se valoraron mal. No muestra el
 * texto de las respuestas ni quién preguntó: solo la pregunta sin respuesta, como en las búsquedas (AYU-5).
 */
final class HelpAssistantQuality
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        $since = now()->subDays($days);
        $base = DB::table('ai_interactions')->where('use_case', HelpAssistant::LOG_USE_CASE)->where('occurred_at', '>=', $since);
        $total = (clone $base)->count();
        $answered = (clone $base)->whereRaw("jsonb_array_length(COALESCE(referenced_entities->'help_articles', '[]'::jsonb)) > 0")->count();
        $ratings = DB::table('ai_feedback as f')->join('ai_interactions as i', 'i.id', '=', 'f.ai_interaction_id')
            ->where('i.use_case', HelpAssistant::LOG_USE_CASE)->where('i.occurred_at', '>=', $since)
            ->selectRaw("count(*) FILTER (WHERE f.rating = 'up') AS up, count(*) FILTER (WHERE f.rating = 'down') AS down")->first();

        $unanswered = DB::table('help_search_queries')->where('source', 'assistant')->where('occurred_at', '>=', $since)
            ->selectRaw('query_text, count(*) AS times, max(occurred_at) AS last_at')->groupBy('query_text')
            ->orderByDesc('times')->orderByDesc('last_at')->limit(50)->get()
            ->map(fn (object $r): array => ['query_text' => (string) $r->query_text, 'times' => (int) $r->times, 'last_at' => now()->parse((string) $r->last_at)->toIso8601String()])->all();

        $badly = DB::table('ai_feedback as f')->join('ai_interactions as i', 'i.id', '=', 'f.ai_interaction_id')
            ->where('i.use_case', HelpAssistant::LOG_USE_CASE)->where('i.occurred_at', '>=', $since)->where('f.rating', 'down')
            ->orderByDesc('f.created_at')->limit(50)->get(['i.id', 'i.referenced_entities', 'f.comment', 'f.created_at']);
        $titles = $this->titles(array_values($badly->flatMap(fn (\stdClass $r): array => $this->cited($r->referenced_entities))->unique()->all()));

        return [
            'days' => $days,
            'questions' => $total,
            'answered' => $answered,
            'unanswered' => $total - $answered,
            'rated_up' => (int) ($ratings->up ?? 0),
            'rated_down' => (int) ($ratings->down ?? 0),
            'unanswered_questions' => array_values($unanswered),
            'badly_rated' => $badly->map(fn (object $r): array => [
                'interaction_id' => (string) $r->id,
                'cited_articles' => array_map(fn (string $id): array => ['id' => $id, 'title' => $titles[$id] ?? null], $this->cited($r->referenced_entities)),
                'comment' => $r->comment,
                'rated_at' => now()->parse((string) $r->created_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function cited(mixed $refs): array
    {
        $data = is_string($refs) ? json_decode($refs, true) : null;

        return is_array($data) && is_array($data['help_articles'] ?? null) ? array_values(array_map('strval', $data['help_articles'])) : [];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function titles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $product = DB::table('help_articles as a')->join('help_article_versions as v', 'v.help_article_id', '=', 'a.id')->whereIn('a.id', $ids)
            ->orderBy('v.version_number')->pluck('v.title', 'a.id')->all();
        $org = DB::table('kb_articles as a')->join('kb_article_versions as v', 'v.kb_article_id', '=', 'a.id')->whereIn('a.id', $ids)
            ->orderBy('v.version_number')->pluck('v.title', 'a.id')->all();

        return array_map('strval', [...$product, ...$org]);
    }
}
