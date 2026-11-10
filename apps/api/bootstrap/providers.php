<?php

use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Organization\OrganizationServiceProvider;
use App\Modules\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationServiceProvider::class,
    AuditServiceProvider::class,
    WorkflowServiceProvider::class,
];
