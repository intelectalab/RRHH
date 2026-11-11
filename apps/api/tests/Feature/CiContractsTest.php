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
        $relativePath = ltrim($relativePath, '/');

        // En entorno dockerizado bajo /var/www
        $containerPath = '/var/www/'.$relativePath;
        if (file_exists($containerPath)) {
            return $containerPath;
        }

        $candidates = [
            base_path('../../'.$relativePath),
            base_path('../'.$relativePath),
            dirname(base_path()).'/'.$relativePath,
            dirname(dirname(base_path())).'/'.$relativePath,
        ];

        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                return $cand;
            }
        }

        return base_path('../../'.$relativePath);
    }

    public function test_openapi_contract_file_exists_and_is_valid_yaml(): void
    {
        $openapiPath = $this->resolvePath('kit/api/openapi.yaml');
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

    public function test_repository_work_guide_exists_and_contains_rules(): void
    {
        $guidePath = $this->resolvePath('docs/guia-trabajo.md');
        $this->assertFileExists($guidePath, 'El archivo docs/guia-trabajo.md debe existir.');

        $content = (string) file_get_contents($guidePath);
        $this->assertStringContainsString('Guía de trabajo del repositorio', $content);
        $this->assertStringContainsString('Regla fundamental de no regresión', $content);
        $this->assertStringContainsString('justificación técnica', $content);
        $this->assertStringContainsString('Definición de «Hecho»', $content);
        $this->assertStringContainsString('Orden de construcción por fases', $content);
    }

    public function test_pull_request_template_exists_and_links_to_work_guide(): void
    {
        $templatePath = $this->resolvePath('.github/pull_request_template.md');
        $this->assertFileExists($templatePath, 'El archivo .github/pull_request_template.md debe existir.');

        $content = (string) file_get_contents($templatePath);
        $this->assertStringContainsString('docs/guia-trabajo.md', $content);
        $this->assertStringContainsString('Justificación técnica', $content);
        $this->assertStringContainsString('Lista de verificación de «Hecho»', $content);
    }
}
