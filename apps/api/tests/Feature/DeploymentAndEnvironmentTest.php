<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DeploymentAndEnvironmentTest extends TestCase
{
    public function test_env_verify_command_succeeds_for_testing_environment(): void
    {
        $exitCode = Artisan::call('env:verify', ['--environment' => 'testing']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('válidas', $output);
    }

    public function test_env_verify_fails_if_insecure_defaults_are_used_in_production(): void
    {
        putenv('APP_DEBUG=true');
        putenv('DB_PASSWORD=secret');

        $exitCode = Artisan::call('env:verify', ['--environment' => 'production']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error de seguridad', $output);

        // Restaurar estado
        putenv('APP_DEBUG=false');
        putenv('DB_PASSWORD');
    }

    private function resolvePath(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $candidates = [
            '/var/www/'.$relativePath,
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

    public function test_deploy_workflow_and_rollback_scripts_exist_and_define_steps(): void
    {
        $deployYmlPath = $this->resolvePath('.github/workflows/deploy.yml');
        $deployShPath = $this->resolvePath('scripts/deploy.sh');
        $rollbackShPath = $this->resolvePath('scripts/rollback.sh');

        $this->assertFileExists($deployYmlPath, 'El workflow .github/workflows/deploy.yml debe existir');
        $this->assertFileExists($deployShPath, 'El script scripts/deploy.sh debe existir');
        $this->assertFileExists($rollbackShPath, 'El script scripts/rollback.sh debe existir');

        $deployYml = (string) file_get_contents($deployYmlPath);
        $this->assertStringContainsString('Automated Deployment', $deployYml);
        $this->assertStringContainsString('Version Rollback', $deployYml);
        $this->assertStringContainsString('migrate --force', $deployYml);

        $rollbackSh = (string) file_get_contents($rollbackShPath);
        $this->assertStringContainsString('TARGET_VERSION', $rollbackSh);
        $this->assertStringContainsString('git checkout', $rollbackSh);
        $this->assertStringContainsString('queue:restart', $rollbackSh);
    }
}
