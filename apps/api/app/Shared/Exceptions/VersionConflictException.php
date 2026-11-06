<?php

namespace App\Shared\Exceptions;

final class VersionConflictException extends AyniException
{
    public function __construct(int $expectedVersion, int $actualVersion, ?string $message = null)
    {
        parent::__construct(
            errorCode: 'version_conflict',
            statusCode: 409,
            message: $message ?? 'Conflicto de concurrencia: el registro fue modificado por otra transacción.',
            meta: [
                'expected_version' => $expectedVersion,
                'actual_version' => $actualVersion,
            ],
        );
    }
}
