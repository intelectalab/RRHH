<?php

use App\Shared\Logging\RequestContextProcessor;
use App\Shared\Logging\SensitiveDataScrubber;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        /*
         * Structured JSON channel — use in production via LOG_CHANNEL=structured.
         *
         * Every entry contains:
         *   - request_id  (from EnsureRequestId middleware)
         *   - tenant_id   (from TenancyScope, when active)
         * Sensitive fields (password, salary, token, …) are automatically
         * replaced with [REDACTED] by SensitiveDataScrubber.
         */
        'structured' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => env('LOG_STRUCTURED_STREAM', 'php://stderr')],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'batchMode' => JsonFormatter::BATCH_MODE_NEWLINES,
                'appendNewline' => true,
                'ignoreEmptyContextAndExtra' => false,
            ],
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                SensitiveDataScrubber::class,
            ],
        ],
    ],
];
