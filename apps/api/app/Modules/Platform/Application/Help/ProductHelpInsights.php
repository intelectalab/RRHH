<?php

namespace App\Modules\Platform\Application\Help;

use App\Shared\Tenancy\CrossTenantMaintenance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AYU-5 · AYU-6 · Desde la consola: búsquedas sin resultado y valoración de la ayuda del producto en todas las
 * organizaciones (sin datos de las personas) y consultas de soporte que enviaron sus administradores.
 */
final class ProductHelpInsights
{
    private const DAYS = 90;

    public function __construct(private readonly CrossTenantMaintenance $tenants) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $since = now()->subDays(self::DAYS);

        return $this->tenants->readAcrossTenants(function () use ($since): array {
            $searches = DB::table('help_search_queries')->where('result_count', 0)->where('occurred_at', '>=', $since)
                ->groupByRaw('lower(query_text)')
                ->selectRaw('lower(query_text) AS query_text, count(*) AS times, count(DISTINCT tenant_id) AS organizations, max(occurred_at) AS last_at')
                ->orderByDesc('times')->limit(30)->get();
            $feedback = DB::table('help_article_feedback as f')->join('help_articles as a', 'a.id', '=', 'f.help_article_id')
                ->groupBy('a.id', 'a.slug', 'a.locale', 'a.title')
                ->selectRaw('a.id, a.slug, a.locale, a.title, count(*) FILTER (WHERE f.is_helpful) AS yes, count(*) FILTER (WHERE NOT f.is_helpful) AS no')
                ->orderByRaw('count(*) FILTER (WHERE NOT f.is_helpful) DESC')->limit(50)->get();

            return [
                'days' => self::DAYS,
                'searches_without_result' => array_values($searches->map(fn (object $s): array => [
                    'query_text' => (string) $s->query_text, 'times' => (int) $s->times, 'organizations' => (int) $s->organizations,
                    'last_at' => Carbon::parse((string) $s->last_at)->toIso8601String(),
                ])->all()),
                'feedback' => array_values($feedback->map(fn (object $r): array => [
                    'article_id' => (string) $r->id, 'slug' => (string) $r->slug, 'locale' => (string) $r->locale, 'title' => (string) $r->title,
                    'helpful' => (int) $r->yes, 'not_helpful' => (int) $r->no,
                ])->all()),
            ];
        });
    }

    /**
     * Consultas de soporte enviadas desde la ayuda por administradores de las organizaciones.
     *
     * @return list<array<string, mixed>>
     */
    public function escalations(): array
    {
        return $this->tenants->readAcrossTenants(fn (): array => array_values(DB::table('help_escalations as h')
            ->join('support_tickets as s', 's.id', '=', 'h.support_ticket_id')
            ->join('tenants as t', 't.id', '=', 'h.tenant_id')
            ->where('h.target', 'platform_support')
            ->orderByDesc('h.created_at')->limit(100)
            ->get(['h.id', 'h.question', 'h.screen_key', 'h.created_at', 's.id as ticket_id', 's.status', 't.id as tenant_id', 't.name as tenant_name'])
            ->map(fn (object $r): array => [
                'id' => (string) $r->id, 'question' => (string) $r->question, 'screen_key' => $r->screen_key,
                'support_ticket_id' => (string) $r->ticket_id, 'status' => (string) $r->status,
                'tenant_id' => (string) $r->tenant_id, 'tenant_name' => (string) $r->tenant_name,
                'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            ])->all()));
    }
}
