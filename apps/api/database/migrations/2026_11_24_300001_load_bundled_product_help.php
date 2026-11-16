<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * AYU-1 · Carga la ayuda del producto incluida en esta versión (resources/help) como artículos publicados.
 * Idempotente: solo crea los que faltan (slug e idioma) y no pisa lo editado en la consola. Las versiones
 * siguientes del producto la amplían con «php artisan help:sync-bundled».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('help_articles') || ! Schema::hasTable('help_article_versions')) {
            return;
        }
        app(ProductHelpArticles::class)->syncBundled();
    }

    public function down(): void
    {
        // Las versiones publicadas son inmutables: no se borran al revertir.
    }
};
