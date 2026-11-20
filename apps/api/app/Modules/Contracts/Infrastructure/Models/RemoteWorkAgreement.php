<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Modules\Contracts\Database\Factories\RemoteWorkAgreementFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CON-6 · Solicitud y acuerdo de teletrabajo (Ley 31572) de una relación laboral.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $modality
 * @property CarbonInterface $starts_on
 * @property CarbonInterface|null $ends_on
 * @property string|null $work_place
 * @property string|null $schedule_description
 * @property int|null $disconnection_hours
 * @property string|null $supervision_mechanism
 * @property string|null $equipment_provided_by
 * @property string|null $expense_compensation
 * @property string|null $currency
 * @property CarbonInterface|null $requested_on
 * @property CarbonInterface|null $responded_on
 * @property string $status
 * @property string|null $document_id
 * @property string|null $request_reason
 * @property string $requested_by
 * @property string|null $requested_by_user_id
 * @property CarbonInterface|null $response_due_on
 * @property string|null $decided_by_user_id
 * @property string|null $decision_reason
 * @property CarbonInterface|null $signed_at
 * @property string|null $signature_origin
 * @property string|null $end_reason
 * @property CarbonInterface|null $due_reminder_sent_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class RemoteWorkAgreement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RemoteWorkAgreementFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'remote_work_agreements';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'disconnection_hours' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date',
        'requested_on' => 'date', 'responded_on' => 'date', 'response_due_on' => 'date', 'signed_at' => 'datetime',
        'due_reminder_sent_at' => 'datetime',
    ];

    protected static function newFactory(): RemoteWorkAgreementFactory
    {
        return RemoteWorkAgreementFactory::new();
    }
}
