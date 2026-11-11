<?php

namespace Database\Seeders;

use App\Shared\LegalParameters\LegalParameters;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Los seeders de cada módulo se registran aquí.
     */
    public function run(): void
    {
        // Parámetros legales del paquete de país (regla 4): antes de la organización demo.
        app(LegalParameters::class)->sync('PE');
        $this->call(DemoOrganizationSeeder::class);
    }
}
