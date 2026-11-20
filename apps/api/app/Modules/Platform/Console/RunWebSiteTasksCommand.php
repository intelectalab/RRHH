<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Web\DemoScheduling;
use App\Modules\Platform\Application\Web\SiteSignups;
use App\Modules\Platform\Application\Web\WebMessages;
use Illuminate\Console\Command;

/**
 * WEB-5 a WEB-7 · Tareas del sitio: entrega (y reintento) de los correos encolados y recordatorio de demos; con
 * --purge borra los registros a medias que superaron la retención (RN-WEB-07).
 */
final class RunWebSiteTasksCommand extends Command
{
    protected $signature = 'web:run {--purge : Borrar registros a medias vencidos}';

    protected $description = 'Entrega los correos del sitio, recuerda las demos y borra registros a medias vencidos';

    public function handle(WebMessages $messages, DemoScheduling $demos, SiteSignups $signups): int
    {
        $reminded = $demos->remind();
        $sent = $messages->deliver(200);
        $purged = $this->option('purge') ? $signups->purgeAbandoned() : 0;
        $this->info("reminded={$reminded} sent={$sent} purged={$purged}");

        return self::SUCCESS;
    }
}
