<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\WithholdingCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Datos de prueba de WithholdingCertificate (pruebas de aislamiento y de la API).
 *
 * @extends Factory<WithholdingCertificate>
 */
class WithholdingCertificateFactory extends Factory
{
    protected $model = WithholdingCertificate::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'fiscal_year' => 2026,
            'currency' => 'PEN',
            'reason' => 'on_request',
            'issued_on' => '2026-12-31',
            'content_hash' => hash('sha256', Str::random(16)),
        ];
    }
}
