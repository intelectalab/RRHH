<?php

namespace App\Modules\Workflow\Database\Factories;

use App\Modules\Workflow\Infrastructure\Models\ApprovalFlow;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    public function definition(): array
    {
        return [
            'workflow_definition_version_id' => fn () => ApprovalFlow::factory()->create()->versions()->value('id'),
            'subject_type' => 'vacation_request',
            'subject_id' => (string) Str::uuid(),
            'status' => 'running',
            'context' => [],
        ];
    }
}
