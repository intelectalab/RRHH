<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\AssertsQueryCount;
use Tests\Support\OpenApiAssert;

abstract class TestCase extends BaseTestCase
{
    use AssertsQueryCount;
    use OpenApiAssert;
}
