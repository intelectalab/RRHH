<?php

namespace App\Shared\Commands;

use App\Shared\Database\DataDictionaryGenerator;
use Illuminate\Console\Command;

final class GenerateDataDictionaryCommand extends Command
{
    protected $signature = 'db:generate-data-dictionary {--output= : Ruta opcional donde guardar el diccionario de datos}';

    protected $description = 'Genera el diccionario de datos en formato Markdown a partir del catálogo de PostgreSQL';

    public function handle(DataDictionaryGenerator $generator): int
    {
        $this->info('Generando diccionario de datos desde el catálogo de la base de datos...');

        $customPath = $this->option('output');
        $savedPath = $generator->saveToFile(is_string($customPath) ? $customPath : null);

        $this->info("✓ Diccionario de datos generado con éxito en: {$savedPath}");

        return 0;
    }
}
