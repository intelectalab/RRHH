<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\HeadcountScenario;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Escenario en borrador de un plan de dotación nuevo de la organización actual.
 *
 * @extends Factory<HeadcountScenario>
 */
class HeadcountScenarioFactory extends Factory
{
    protected $model = HeadcountScenario::class;

    public function definition(): array
    {
        return [
            'headcount_plan_id' => function (): string {
                $id = (string) Str::uuid();
                DB::table('headcount_plans')->insert(['id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'name' => 'Plan '.Str::random(5), 'fiscal_year' => 2026]);

                return $id;
            },
            'name' => 'Escenario '.Str::random(5),
            'kind' => 'custom',
            'base_as_of' => '2026-01-01',
            'status' => 'draft',
        ];
    }
}
