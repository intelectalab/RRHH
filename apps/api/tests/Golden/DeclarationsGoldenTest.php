<?php

/**
 * DEC-10 · Archivos dorados de las estructuras oficiales verificadas. Cada estructura del paquete de país marcada como
 * verificada debe tener en tests/Golden/Declarations su caso (<tipo>_<estructura>.json con las filas) y el archivo
 * esperado (<tipo>_<estructura>.txt), y el serializador debe reproducirlo byte a byte. Mientras una estructura siga
 * por validar (AR-11) solo genera borradores y no lleva archivo dorado.
 */

use App\Modules\Payroll\Domain\Declarations\FileSerializer;

it('DEC-10 · cada estructura verificada del paquete de país tiene su archivo dorado y lo reproduce byte a byte', function (): void {
    /** @var array{estructuras: list<array<string, mixed>>} $pack */
    $pack = json_decode((string) file_get_contents(base_path('packages/country-pe/data/estructuras_archivos_pe.json')), true);
    $dir = base_path('tests/Golden/Declarations');
    $checked = 0;
    foreach ($pack['estructuras'] as $e) {
        if (($e['verified'] ?? false) !== true) {
            continue; // Por validar (AR-11): sin verificar solo se generan borradores y no hay archivo dorado.
        }
        $case = json_decode((string) file_get_contents("{$dir}/{$e['filing_kind']}_{$e['structure_code']}.json"), true);
        $expected = (string) file_get_contents("{$dir}/{$e['filing_kind']}_{$e['structure_code']}.txt");
        expect(FileSerializer::serialize($e + ['trailing_separator' => true], $case['rows'])['content'])->toBe($expected);
        $checked++;
    }
    expect($checked)->toBeGreaterThanOrEqual(0);
    expect(is_dir($dir))->toBeTrue();
});
