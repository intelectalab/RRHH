<?php

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks sensitive fields in log context and extra data.
 *
 * Fields listed in SENSITIVE_FIELDS are replaced with '[REDACTED]'
 * to prevent leaking personal data, salaries, or credentials in logs.
 */
final class SensitiveDataScrubber implements ProcessorInterface
{
    /**
     * Field names (case-insensitive) that must never appear in logs.
     *
     * @var array<string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'api_key',
        'api_secret',
        'access_token',
        'refresh_token',
        'credit_card',
        'card_number',
        'cvv',
        'base_salary',
        'net_salary',
        'gross_salary',
        'salary',
        'bank_account',
        'iban',
        'routing_number',
        'ssn',
        'national_id',
        'passport_number',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->scrub($record->context);
        $extra = $this->scrub($record->extra);

        return $record->with(context: $context, extra: $extra);
    }

    /**
     * Recursively redact sensitive keys from an array.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_FIELDS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<mixed> $value */
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
