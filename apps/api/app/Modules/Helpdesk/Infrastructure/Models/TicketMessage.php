<?php

namespace App\Modules\Helpdesk\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ESS-5 · Mensaje o hito del historial de una solicitud (ticket_messages).
 *
 * @property string $id
 * @property string $ticket_id
 * @property string|null $author_user_id
 * @property string $body
 * @property bool $is_internal_note
 * @property string $via
 * @property string $event
 * @property string|null $stored_file_id
 * @property string|null $file_name
 * @property Carbon|null $created_at
 */
class TicketMessage extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ticket_messages';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_internal_note' => false, 'via' => 'portal', 'event' => 'comment'];

    protected $casts = ['is_internal_note' => 'boolean'];
}
