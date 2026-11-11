<?php

declare(strict_types=1);
use App\Payroll\Steps\AdditionalEarningsStep;
use App\Payroll\Steps\AgrarianBenefitsStep;
use App\Payroll\Steps\AgrarianBonusStep;
use App\Payroll\Steps\AgrarianHealthContributionStep;
use App\Payroll\Steps\ConstructionBenefitsStep;
use App\Payroll\Steps\ConstructionWageStep;
use App\Payroll\Steps\BaseSalaryStep;
use App\Payroll\Steps\CtsProvisionStep;
use App\Payroll\Steps\FamilyAllowanceStep;
use App\Payroll\Steps\FifthCategoryTaxStep;
use App\Payroll\Steps\GratificationProvisionStep;
use App\Payroll\Steps\HealthContributionStep;
use App\Payroll\Steps\OtherDeductionsStep;
use App\Payroll\Steps\PensionStep;

return [
    'country' => 'PE',

    // Catálogo del kit con los parámetros legales del Perú. Lo carga LegalParametersSeeder.
    'catalog_path' => env('PAYROLL_CATALOG', base_path('kit/database/catalogos/parametros_legales_pe.json')),

    // Decimales y redondeo de cada línea. En el producto es un parámetro del paquete de país (RN-NOM-08).
    'scale' => 2,

    // Pasos de la calculadora, en orden. Para añadir un bono o un descuento se crea una clase que
    // implemente PayrollStep y se agrega aquí. La firma de PayrollService::calculate() no cambia.
    'steps' => [
        BaseSalaryStep::class,
        FamilyAllowanceStep::class,
        AdditionalEarningsStep::class,
        PensionStep::class,
        FifthCategoryTaxStep::class,
        OtherDeductionsStep::class,
        HealthContributionStep::class,
        GratificationProvisionStep::class,
        CtsProvisionStep::class,
    ],

    // Regímenes con pasos propios. El resto usa «steps». Agregar un régimen no cambia el servicio.
    'steps_by_regime' => [
        'pe_agrario' => [
            BaseSalaryStep::class, FamilyAllowanceStep::class, AgrarianBonusStep::class, AdditionalEarningsStep::class,
            AgrarianBenefitsStep::class, PensionStep::class, FifthCategoryTaxStep::class, OtherDeductionsStep::class,
            AgrarianHealthContributionStep::class,
        ],
        'pe_construccion' => [
            ConstructionWageStep::class, AdditionalEarningsStep::class, PensionStep::class, FifthCategoryTaxStep::class,
            OtherDeductionsStep::class, HealthContributionStep::class, ConstructionBenefitsStep::class,
        ],
    ],
];
