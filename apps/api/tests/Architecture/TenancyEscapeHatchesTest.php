<?php

/**
 * Regla 1 de CLAUDE.md: ninguna consulta escapa del contexto de organización
 * fuera de app/Shared/Tenancy y de la consola de plataforma (Modules/Platform).
 *
 * Las reglas de arquitectura de Pest (`arch()->toUse()`) solo detectan clases
 * importadas, no llamadas a métodos; por eso estas reglas se verifican leyendo
 * el código fuente.
 *
 * Además (docs/02-arquitectura.md §5.2, TEN-6):
 * - La caché se usa solo a través de TenantCache (prefijo t:{tenant_id}:).
 * - El almacenamiento de archivos se usa solo a través de FileService
 *   (ruta tenants/{tenant_id}/…).
 */

/**
 * @return array<string, string> ruta relativa => contenido
 */
function appSourceFiles(): array
{
    $root = dirname(__DIR__, 2).'/app';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php') {
            $relative = 'app/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

/**
 * @param  array<int, string>  $patterns  expresiones regulares prohibidas
 * @param  array<int, string>  $allowedPrefixes  carpetas donde sí se permiten
 * @return array<int, string> infracciones "archivo:línea → coincidencia"
 */
function findForbidden(array $patterns, array $allowedPrefixes): array
{
    $violations = [];
    foreach (appSourceFiles() as $path => $content) {
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }
        foreach (preg_split('/\R/', $content) ?: [] as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue; // comentarios y docblocks
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $m) === 1) {
                    $violations[] = $path.':'.($i + 1).' → '.$m[0];
                }
            }
        }
    }

    return $violations;
}

const TENANCY_ALLOWED = ['app/Shared/Tenancy/', 'app/Modules/Platform/'];

test('ningún código fuera de Shared/Tenancy o Platform desactiva el aislamiento', function (): void {
    $violations = findForbidden([
        '/withoutGlobalScopes?\s*\(/',
        '/runWithoutTenant\s*\(/',
        '/TenancyScope::(clear|resetDatabase|setSyncWithDatabase)\s*\(/',
        "/set_config\\(\\s*'app\\.tenant_id'/",
        '/\bRESET\s+ROLE\b|\bSET\s+ROLE\b/i',
        '/BYPASSRLS/i',
        "/DB::connection\\(\\s*'(platform|gestia_platform|migrator)'/",
    ], array_merge(TENANCY_ALLOWED, ['app/Shared/Http/Middleware/ResolveTenantContext.php']));

    expect($violations)->toBe([], "Escapes del contexto de organización:\n".implode("\n", $violations));
});

test('ningún código fuera de Shared/Tenancy fija la organización con datos del cliente', function (): void {
    $violations = findForbidden([
        "/header\\(\\s*'X-Tenant-Id'/i",
        "/input\\(\\s*'tenant_id'/",
        "/query\\(\\s*'tenant_id'/",
    ], TENANCY_ALLOWED);

    expect($violations)->toBe([], "La organización solo se resuelve en Shared/Tenancy:\n".implode("\n", $violations));
});

test('la caché se usa solo a través de TenantCache', function (): void {
    $violations = findForbidden([
        '/\bCache::/',
        '/\bcache\(\)/',
        '/use\s+Illuminate\\\\Support\\\\Facades\\\\Cache\s*;/',
    ], ['app/Shared/Cache/', ...TENANCY_ALLOWED]);

    expect($violations)->toBe([], "Usa App\\Shared\\Cache\\TenantCache:\n".implode("\n", $violations));
});

test('el almacenamiento de archivos se usa solo a través de FileService', function (): void {
    $violations = findForbidden([
        '/\bStorage::/',
        '/use\s+Illuminate\\\\Support\\\\Facades\\\\Storage\s*;/',
    ], ['app/Shared/Files/', 'app/Modules/Platform/']);

    expect($violations)->toBe([], "Usa App\\Shared\\Files\\FileService:\n".implode("\n", $violations));
});

test('la cabecera X-Tenant-Id solo se acepta en el entorno de pruebas', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Shared/Tenancy/Resolution/TestingHeaderTenantResolver.php');

    expect($source)->toContain("app()->environment('testing')");
});
