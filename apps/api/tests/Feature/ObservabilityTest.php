<?php

use App\Shared\Logging\RequestContextProcessor;
use App\Shared\Logging\SensitiveDataScrubber;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

// ─── RequestContextProcessor ─────────────────────────────────────────────────

test('request_id from container binding appears in log entry extra', function (): void {
    app()->instance('request.id', 'test-req-123');

    $processor = new RequestContextProcessor;
    $record = makeRecord();
    $result = $processor($record);

    expect($result->extra)->toHaveKey('request_id', 'test-req-123');
});

test('request_id from X-Request-Id header appears in log entry extra when container binding absent', function (): void {
    app()->forgetInstance('request.id');

    // Simulate a request with the header set
    $request = new Request;
    $request->headers->set('X-Request-Id', 'header-req-456');
    app()->instance('request', $request);

    $processor = new RequestContextProcessor;
    $result = $processor(makeRecord());

    expect($result->extra)->toHaveKey('request_id', 'header-req-456');
});

test('tenant_id appears in log entry extra when TenancyScope is active', function (): void {
    TenancyScope::set('org-abc');
    app()->instance('request.id', 'req-xyz');

    $processor = new RequestContextProcessor;
    $result = $processor(makeRecord());

    expect($result->extra)
        ->toHaveKey('tenant_id', 'org-abc')
        ->toHaveKey('request_id', 'req-xyz');

    TenancyScope::clear();
});

test('tenant_id is absent from log entry when no active tenant', function (): void {
    TenancyScope::clear();
    app()->instance('request.id', 'req-no-tenant');

    $processor = new RequestContextProcessor;
    $result = $processor(makeRecord());

    expect($result->extra)->not->toHaveKey('tenant_id');
});

// ─── SensitiveDataScrubber ───────────────────────────────────────────────────

test('sensitive fields in context are replaced with [REDACTED]', function (): void {
    $scrubber = new SensitiveDataScrubber;

    $record = makeRecord(context: [
        'username' => 'john',
        'password' => 'supersecret',
        'base_salary' => 5000.00,
        'net_salary' => 4200.00,
        'token' => 'abc123',
    ]);

    $result = $scrubber($record);

    expect($result->context)
        ->toHaveKey('username', 'john')               // not sensitive — unchanged
        ->toHaveKey('password', '[REDACTED]')
        ->toHaveKey('base_salary', '[REDACTED]')
        ->toHaveKey('net_salary', '[REDACTED]')
        ->toHaveKey('token', '[REDACTED]');
});

test('nested sensitive fields are also redacted', function (): void {
    $scrubber = new SensitiveDataScrubber;

    $record = makeRecord(context: [
        'user' => [
            'name' => 'jane',
            'password' => 'hunter2',
            'salary' => 6000,
        ],
    ]);

    $result = $scrubber($record);

    expect($result->context['user'])
        ->toHaveKey('name', 'jane')
        ->toHaveKey('password', '[REDACTED]')
        ->toHaveKey('salary', '[REDACTED]');
});

test('scrubber does not alter non-sensitive fields', function (): void {
    $scrubber = new SensitiveDataScrubber;

    $record = makeRecord(context: [
        'action' => 'user.login',
        'ip_address' => '127.0.0.1',
        'browser' => 'Chrome',
    ]);

    $result = $scrubber($record);

    expect($result->context)->toBe([
        'action' => 'user.login',
        'ip_address' => '127.0.0.1',
        'browser' => 'Chrome',
    ]);
});

// ─── Structured channel integration ──────────────────────────────────────────

test('structured log channel is configured and uses JsonFormatter', function (): void {
    $channels = config('logging.channels');

    expect($channels)->toHaveKey('structured');

    $channel = $channels['structured'];

    expect($channel['driver'])->toBe('monolog');
    expect($channel['formatter'])->toBe(JsonFormatter::class);
    expect($channel['processors'])->toContain(RequestContextProcessor::class);
    expect($channel['processors'])->toContain(SensitiveDataScrubber::class);
});

test('logging a message with structured channel produces JSON with request_id', function (): void {
    // Switch to a temp file so we can inspect the output
    $tmpFile = tempnam(sys_get_temp_dir(), 'ayni_log_');
    assert(is_string($tmpFile));

    config(['logging.channels.structured.with' => ['stream' => $tmpFile]]);
    config(['logging.default' => 'structured']);

    app()->instance('request.id', 'struct-req-999');
    TenancyScope::set('org-struct');

    Log::info('observability test', ['action' => 'plt10']);

    $contents = file_get_contents($tmpFile);
    @unlink($tmpFile);

    assert(is_string($contents));

    $decoded = json_decode(trim($contents), true);
    assert(is_array($decoded));

    expect($decoded)->toHaveKey('message', 'observability test');
    expect($decoded['extra'] ?? [])->toHaveKey('request_id', 'struct-req-999');
    expect($decoded['extra'] ?? [])->toHaveKey('tenant_id', 'org-struct');
    expect($decoded['context'] ?? [])->toHaveKey('action', 'plt10');

    TenancyScope::clear();
});

// ─── Helper ──────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $extra
 */
function makeRecord(array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: 'test message',
        context: $context,
        extra: $extra,
    );
}
