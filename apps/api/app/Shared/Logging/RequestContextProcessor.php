<?php

namespace App\Shared\Logging;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds request_id and tenant_id to every log entry.
 *
 * The request_id is read from the container binding 'request.id' that
 * EnsureRequestId middleware sets. The tenant_id is read from TenancyScope.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $requestId = app()->bound('request.id') ? (string) app('request.id') : null;

        if ($requestId === null) {
            /** @var Request $request */
            $request = app('request');
            $requestId = $request->header('X-Request-Id');
        }

        if ($requestId !== null && $requestId !== '') {
            $extra['request_id'] = $requestId;
        }

        $tenantId = TenancyScope::current();
        if ($tenantId !== null) {
            $extra['tenant_id'] = $tenantId;
        }

        return $record->with(extra: $extra);
    }
}
