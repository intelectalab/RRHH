<?php

namespace App\Modules\Settings\Infrastructure\Console;

use App\Modules\Settings\Application\MasterCatalogImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * CFG-10 · Actualiza los catálogos maestros sin desplegar. Lo ejecuta la plataforma con el rol de
 * base de la consola (gestia_platform): la aplicación de las organizaciones solo los lee.
 *
 *   php artisan catalogs:import                         # archivo del kit (catalogos_maestros.json)
 *   php artisan catalogs:import ruta/catalogos.json     # otro archivo con el mismo formato
 *   php artisan catalogs:import ruta/ubigeo.csv --catalog=ubigeo_pe
 */
final class ImportCatalogsCommand extends Command
{
    protected $signature = 'catalogs:import {file? : JSON con la lista «catalogos» o CSV de un catálogo} {--catalog= : código del catálogo maestro (solo CSV)}';

    protected $description = 'Carga o actualiza los catálogos maestros (CFG-10); nunca borra elementos';

    public function handle(MasterCatalogImporter $importer): int
    {
        $file = $this->argument('file');
        $file = is_string($file) && $file !== '' ? $file : MasterCatalogImporter::kitFile();
        if ($file === null || ! is_file($file)) {
            $this->error('No se encontró el archivo de catálogos.');

            return self::FAILURE;
        }
        try {
            $catalog = $this->option('catalog');
            if (str_ends_with(strtolower($file), '.csv')) {
                if (! is_string($catalog) || $catalog === '') {
                    $this->error('Un CSV necesita --catalog=<código>.');

                    return self::FAILURE;
                }
                $totals = $importer->importCsv($catalog, $file);
            } else {
                $payload = json_decode((string) file_get_contents($file), true);
                if (! is_array($payload)) {
                    $this->error('El archivo no es un JSON válido.');

                    return self::FAILURE;
                }
                /** @var array<string, mixed> $payload */
                $totals = $importer->importJson($payload);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Catálogos: {$totals['catalogs']} · nuevos: {$totals['inserted']} · actualizados: {$totals['updated']}");

        return self::SUCCESS;
    }
}
