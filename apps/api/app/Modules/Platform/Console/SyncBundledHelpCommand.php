<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Console\Command;

/**
 * AYU-1 · Carga la ayuda del producto que acompaña a esta versión (resources/help) como artículos
 * publicados. Es idempotente y no pisa lo que GestiaHR ya editó desde la consola.
 */
final class SyncBundledHelpCommand extends Command
{
    protected $signature = 'help:sync-bundled';

    protected $description = 'Carga la ayuda del producto incluida en esta versión (solo los artículos que falten)';

    public function handle(ProductHelpArticles $articles): int
    {
        $this->info((string) trans('platform.help.synced', ['count' => $articles->syncBundled()]));

        return self::SUCCESS;
    }
}
