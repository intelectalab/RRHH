<?php

use App\Modules\Absences\AbsencesServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Contracts\ContractsServiceProvider;
use App\Modules\CoreHR\CoreHRServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Organization\OrganizationServiceProvider;
use App\Modules\Platform\PlatformServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\SelfService\SelfServiceServiceProvider;
use App\Modules\Settings\SettingsServiceProvider;
use App\Modules\Vacations\VacationsServiceProvider;
use App\Modules\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationServiceProvider::class,
    AuditServiceProvider::class,
    WorkflowServiceProvider::class,
    NotificationsServiceProvider::class,
    SettingsServiceProvider::class,
    ContractsServiceProvider::class,
    ReportsServiceProvider::class,
    PlatformServiceProvider::class,
    AbsencesServiceProvider::class,
    ImportsServiceProvider::class,
    CoreHRServiceProvider::class,
    VacationsServiceProvider::class,
    SelfServiceServiceProvider::class,
];
