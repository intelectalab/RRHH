<?php

namespace App\Modules\HelpCenter\Console;

use App\Modules\HelpCenter\Application\OrgArticles;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;

/**
 * AYU-4 · RN-AYU-04 · Marca «por revisar» los artículos de la organización cuya revisión vence y avisa a su
 * responsable, organización por organización. Se puede correr varias veces el mismo día: no repite avisos.
 */
final class MarkArticlesForReview extends Command
{
    protected $signature = 'help:review-articles {--date= : Fecha de corte (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Marca por revisar los artículos de la organización que vencen y avisa al responsable (AYU-4)';

    public function handle(TenantDirectory $tenants, OrgArticles $articles): int
    {
        $date = $this->option('date');
        $day = is_string($date) && $date !== '' ? $date : now()->toDateString();
        $total = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $total += TenancyScope::runAs($tenantId, fn (): int => $articles->markReviewDue($day));
        }
        $this->info((string) trans('help.review_marked', ['count' => $total]));

        return self::SUCCESS;
    }
}
