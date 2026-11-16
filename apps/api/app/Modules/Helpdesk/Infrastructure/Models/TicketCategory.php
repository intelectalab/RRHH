<?php

namespace App\Modules\Helpdesk\Infrastructure\Models;

use App\Modules\Helpdesk\Database\Factories\TicketCategoryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESS-5 · Tipo de solicitud a RRHH (ticket_categories), configurable por la organización.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $default_assignee_role_id
 * @property bool $is_confidential
 * @property bool $requires_attachment
 * @property int $sort_order
 * @property string $status
 */
class TicketCategory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TicketCategoryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'ticket_categories';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'active', 'is_confidential' => false, 'requires_attachment' => false, 'sort_order' => 100];

    protected $casts = ['is_confidential' => 'boolean', 'requires_attachment' => 'boolean', 'sort_order' => 'integer', 'form' => 'array'];

    protected static function newFactory(): TicketCategoryFactory
    {
        return TicketCategoryFactory::new();
    }
}
