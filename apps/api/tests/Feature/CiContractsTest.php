<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CiContractsTest — Verifica que el contrato OpenAPI, los artefactos de contratos
 * y el flujo de CI existen y son válidos.
 */
class CiContractsTest extends TestCase
{
    private function resolvePath(string $relativePath): string
    {
        // En entorno dockerizado bajo /var/www
        $containerPath = '/var/www/'.ltrim($relativePath, '/');
        if (file_exists($containerPath)) {
            return $containerPath;
        }

        // En ejecución local fuera de docker
        return base_path('../../'.ltrim($relativePath, '/'));
    }

    public function test_openapi_contract_file_exists_and_is_valid_yaml(): void
    {
        $openapiPath = $this->resolvePath('api/openapi.yaml');
        $this->assertFileExists($openapiPath, 'El archivo api/openapi.yaml debe existir.');

        $content = (string) file_get_contents($openapiPath);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('openapi: 3.', $content);
        $this->assertStringContainsString('/api/v1', $content);
    }

    public function test_typescript_contracts_package_exists(): void
    {
        $contractsPath = $this->resolvePath('packages/contracts/src/index.ts');
        $this->assertFileExists($contractsPath, 'El archivo packages/contracts/src/index.ts debe existir.');

        $content = (string) file_get_contents($contractsPath);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('paths', $content);
    }

    public function test_ci_workflow_definition_exists(): void
    {
        $ciPath = $this->resolvePath('.github/workflows/ci.yml');
        $this->assertFileExists($ciPath, 'El archivo .github/workflows/ci.yml debe existir.');

        $content = (string) file_get_contents($ciPath);
        $this->assertStringContainsString('Lint & Static Analysis', $content);
        $this->assertStringContainsString('OpenAPI Contracts & Types Sync', $content);
        $this->assertStringContainsString('Automated Tests & Coverage', $content);
        $this->assertStringContainsString('upload-artifact', $content);
    }
}
