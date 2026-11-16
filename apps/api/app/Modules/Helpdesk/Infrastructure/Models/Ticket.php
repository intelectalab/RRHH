<?php

namespace App\Modules\Helpdesk\Infrastructure\Models;

use App\Modules\Helpdesk\Database\Factories\TicketFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * ESS-5 · Solicitud del colaborador a RRHH (tickets, ME-31).
 *
 * @property string $id
 * @property string $number
 * @property string $ticket_category_id
 * @property string $requester_user_id
 * @property string|null $employment_id
 * @property string $subject
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property string|null $assignee_user_id
 * @property Carbon|null $first_response_due_at
 * @property Carbon|null $resolution_due_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string $channel
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ticket extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'tickets';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'new', 'priority' => 'normal', 'channel' => 'portal'];

    protected $casts = [
        'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }
}
