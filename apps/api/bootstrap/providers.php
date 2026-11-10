<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Organization\OrganizationServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationServiceProvider::class,
];
