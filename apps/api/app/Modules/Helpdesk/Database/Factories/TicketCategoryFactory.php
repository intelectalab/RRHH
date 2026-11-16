<?php

namespace App\Modules\Helpdesk\Database\Factories;

use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketCategory>
 */
class TicketCategoryFactory extends Factory
{
    protected $model = TicketCategory::class;

    public function definition(): array
    {
        return ['code' => 't_'.Str::lower(Str::random(8)), 'name' => 'Tipo '.Str::random(5), 'status' => 'active', 'sort_order' => 500];
    }
}
