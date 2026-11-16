<?php

namespace App\Modules\Helpdesk\Database\Factories;

use App\Modules\Helpdesk\Infrastructure\Models\Ticket;
use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'number' => 'SOL-'.Str::upper(Str::random(8)),
            'ticket_category_id' => fn (): string => TicketCategory::factory()->create()->id,
            'requester_user_id' => fn (): string => (string) DB::table('users')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'display_name' => 'Usuario '.Str::random(5), 'status' => 'active',
            ]),
            'subject' => 'Consulta '.Str::random(6),
            'status' => 'new',
        ];
    }
}
