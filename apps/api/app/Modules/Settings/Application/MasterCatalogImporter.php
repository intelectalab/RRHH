<?php

namespace App\Modules\Settings\Application;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CFG-10 · Carga y actualización de los catálogos maestros sin desplegar.
 *
 * Inserta los catálogos y códigos nuevos y actualiza etiqueta, código oficial, padre, orden,
 * verificación y vigencia de los existentes. Nunca borra: un código que deja de existir se cierra
 * con valid_to (RN-CFG-09). Es idempotente: cargar dos veces el mismo archivo no cambia nada.
 */
final class MasterCatalogImporter
{
    /**
     * @param  array<string, mixed>  $payload  formato de kit/database/catalogos/catalogos_maestros.json
     * @return array{catalogs: int, inserted: int, updated: int}
     */
    public function importJson(array $payload): array
    {
        $catalogs = $payload['catalogos'] ?? null;
        if (! is_array($catalogs)) {
            throw new InvalidArgumentException('El archivo no tiene la lista «catalogos».');
        }
        $totals = ['catalogs' => 0, 'inserted' => 0, 'updated' => 0];
        DB::transaction(function () use ($catalogs, &$totals): void {
            foreach ($catalogs as $c) {
                if (! is_array($c) || ! isset($c['code'], $c['name']) || ! is_string($c['code'])) {
                    throw new InvalidArgumentException('Cada catálogo necesita code y name.');
                }
                $id = $this->upsertCatalog($c);
                $totals['catalogs']++;
                /** @var array<int, array<string, mixed>> $items */
                $items = is_array($c['items'] ?? null) ? $c['items'] : [];
                foreach ($items as $item) {
                    $this->upsertItem($id, $item) ? $totals['inserted']++ : $totals['updated']++;
                }
            }
        });

        return $totals;
    }

    /**
     * CSV de un catálogo ya existente. Columnas: code, label_es, label_en, label_pt, statutory_code,
     * parent_code, sort_order, valid_from, valid_to, is_verified (solo code y label_es son obligatorias).
     *
     * @return array{catalogs: int, inserted: int, updated: int}
     */
    public function importCsv(string $catalogCode, string $path): array
    {
        $id = DB::table('master_catalogs')->where('code', $catalogCode)->value('id');
        if (! is_string($id)) {
            throw new InvalidArgumentException("No existe el catálogo maestro {$catalogCode}.");
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new InvalidArgumentException("No se puede leer {$path}.");
        }
        $header = fgetcsv($fh, null, ',', '"', '');
        if (! is_array($header) || ! in_array('code', $header, true) || ! in_array('label_es', $header, true)) {
            throw new InvalidArgumentException('La primera fila debe tener al menos las columnas code y label_es.');
        }
        $header = array_map(fn (?string $h): string => trim($h ?? ''), $header);
        $totals = ['catalogs' => 1, 'inserted' => 0, 'updated' => 0];
        DB::transaction(function () use ($fh, $header, $id, &$totals): void {
            $n = 0;
            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $r = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
                $labels = array_filter(['es' => $r['label_es'] ?? null, 'en' => $r['label_en'] ?? null, 'pt' => $r['label_pt'] ?? null], fn ($v) => is_string($v) && $v !== '');
                $this->upsertItem($id, [
                    'code' => $r['code'], 'labels' => $labels, 'statutory_code' => $r['statutory_code'] ?? null, 'parent_code' => $r['parent_code'] ?? null,
                    'sort_order' => isset($r['sort_order']) && $r['sort_order'] !== '' ? (int) $r['sort_order'] : ++$n,
                    'valid_from' => ($r['valid_from'] ?? '') !== '' ? $r['valid_from'] : '2000-01-01', 'valid_to' => ($r['valid_to'] ?? '') !== '' ? $r['valid_to'] : null,
                    'is_verified' => in_array(strtolower((string) ($r['is_verified'] ?? '')), ['1', 'true', 'si', 'sí'], true),
                ]) ? $totals['inserted']++ : $totals['updated']++;
            }
        });
        fclose($fh);

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function upsertCatalog(array $c): string
    {
        $values = [
            'name' => (string) $c['name'],
            'description' => isset($c['description']) ? (string) $c['description'] : null,
            'is_extensible' => (bool) ($c['is_extensible'] ?? false),
            'country_code' => isset($c['country_code']) ? (string) $c['country_code'] : null,
            'source' => isset($c['source']) ? (string) $c['source'] : null,
        ];
        $id = DB::table('master_catalogs')->where('code', $c['code'])->value('id');
        if (is_string($id)) {
            DB::table('master_catalogs')->where('id', $id)->update($values);

            return $id;
        }
        DB::table('master_catalogs')->insert($values + ['code' => $c['code']]);

        return (string) DB::table('master_catalogs')->where('code', $c['code'])->value('id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return bool verdadero si el código es nuevo
     */
    private function upsertItem(string $catalogId, array $item): bool
    {
        $code = $item['code'] ?? null;
        /** @var array<string, string> $labels */
        $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
        if (! is_string($code) || $code === '' || ! isset($labels['es']) || $labels['es'] === '') {
            throw new InvalidArgumentException('Cada elemento necesita code y la etiqueta en español.');
        }
        $values = [
            'label' => $labels['es'],
            'labels' => json_encode($labels, JSON_UNESCAPED_UNICODE),
            'statutory_code' => isset($item['statutory_code']) && $item['statutory_code'] !== '' ? (string) $item['statutory_code'] : null,
            'parent_code' => isset($item['parent_code']) && $item['parent_code'] !== '' ? (string) $item['parent_code'] : null,
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'is_verified' => (bool) ($item['is_verified'] ?? false),
            'valid_from' => (string) ($item['valid_from'] ?? '2000-01-01'),
            'valid_to' => isset($item['valid_to']) && $item['valid_to'] !== '' ? (string) $item['valid_to'] : null,
        ];
        $exists = DB::table('master_catalog_items')->where('master_catalog_id', $catalogId)->where('code', $code)->exists();
        if ($exists) {
            DB::table('master_catalog_items')->where('master_catalog_id', $catalogId)->where('code', $code)->update($values);

            return false;
        }
        DB::table('master_catalog_items')->insert($values + ['master_catalog_id' => $catalogId, 'code' => $code]);

        return true;
    }

    /** Ruta del archivo generado por el kit, si está disponible en este despliegue. */
    public static function kitFile(): ?string
    {
        foreach ([base_path('../../kit/database/catalogos/catalogos_maestros.json'), base_path('../kit/database/catalogos/catalogos_maestros.json'), '/var/www/kit/database/catalogos/catalogos_maestros.json'] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }
}
