<?php

namespace App\Modules\HelpCenter\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AYU-5 · Uso de la ayuda en la organización para quien administra el contenido: búsquedas sin resultado más
 * frecuentes (sin datos de la persona, RN-AYU-05), valoración de cada artículo y consultas derivadas.
 */
final class HelpInsights
{
    private const DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $since = now()->subDays(self::DAYS);
        $searches = DB::table('help_search_queries')->where('result_count', 0)->where('occurred_at', '>=', $since)
            ->groupByRaw('lower(query_text)')
            ->selectRaw('lower(query_text) AS query_text, count(*) AS times, max(occurred_at) AS last_at')
            ->orderByDesc('times')->orderByDesc('last_at')->limit(20)->get();

        $product = DB::table('help_article_feedback as f')->join('help_articles as a', 'a.id', '=', 'f.help_article_id')
            ->groupBy('f.help_article_id', 'a.title')
            ->selectRaw('f.help_article_id AS id, a.title, count(*) FILTER (WHERE f.is_helpful) AS yes, count(*) FILTER (WHERE NOT f.is_helpful) AS no')
            ->get();
        $org = DB::table('kb_article_feedback as f')->join('kb_articles as a', 'a.id', '=', 'f.kb_article_id')
            ->groupBy('f.kb_article_id', 'a.title')
            ->selectRaw('f.kb_article_id AS id, a.title, count(*) FILTER (WHERE f.is_helpful) AS yes, count(*) FILTER (WHERE NOT f.is_helpful) AS no')
            ->get();
        $feedback = [];
        foreach ([['product', $product], ['organization', $org]] as [$source, $rows]) {
            foreach ($rows as $r) {
                $feedback[] = ['article_id' => (string) $r->id, 'source' => $source, 'title' => (string) $r->title, 'helpful' => (int) $r->yes, 'not_helpful' => (int) $r->no];
            }
        }
        usort($feedback, fn (array $a, array $b): int => [$b['not_helpful'], $b['helpful']] <=> [$a['not_helpful'], $a['helpful']]);

        $escalations = DB::table('help_escalations')->where('created_at', '>=', $since)
            ->selectRaw("count(*) AS total, count(*) FILTER (WHERE target = 'hr') AS hr, count(*) FILTER (WHERE target = 'platform_support') AS support")
            ->first();

        return [
            'days' => self::DAYS,
            'searches_without_result' => array_values($searches->map(fn (object $s): array => [
                'query_text' => (string) $s->query_text,
                'times' => (int) $s->times,
                'last_at' => Carbon::parse((string) $s->last_at)->toIso8601String(),
            ])->all()),
            'feedback' => $feedback,
            'escalations' => ['total' => (int) ($escalations->total ?? 0), 'hr' => (int) ($escalations->hr ?? 0), 'platform_support' => (int) ($escalations->support ?? 0)],
        ];
    }
}
