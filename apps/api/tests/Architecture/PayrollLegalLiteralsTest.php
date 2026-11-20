<?php

/**
 * NOM-1 · Regla 4 de CLAUDE.md · kit/docs/11 §8: el código del motor de nómina y de los paquetes de país
 * (fuera de su carpeta data/) no tiene números con apariencia de tasa o tope ni constantes legales, y el
 * núcleo no tiene reglas de un país (RN-NOM-12): los códigos de régimen viven en el paquete.
 */
test('NOM-1 · sin tasas, topes ni reglas de país en el código del motor de nómina', function (): void {
    $base = dirname(__DIR__, 2);
    $roots = [$base.'/app/Modules/Payroll'];
    foreach (glob($base.'/packages/country-*') ?: [] as $pack) {
        $roots[] = $pack;
    }
    $problems = [];
    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php') || str_contains($path, DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $code = (string) file_get_contents($path);
            // Sin comentarios: el texto explicativo puede citar ejemplos.
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            $code = (string) preg_replace('#//[^\n]*#', '', $code);
            if (preg_match('/(?<![\w.])0\.\d+/', $code, $m) === 1) {
                $problems[] = "{$path}: decimal {$m[0]}";
            }
            if (preg_match('/\b(const|define\()\s*[\'"]?\w*(UIT|RMV|RATE|TASA|TOPE)\w*/i', $code, $m) === 1) {
                $problems[] = "{$path}: constante legal {$m[0]}";
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR) && preg_match('/[\'"]pe_[a-z]+[\'"]/', $code, $m) === 1) {
                $problems[] = "{$path}: régimen de un país en el núcleo {$m[0]}";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});
