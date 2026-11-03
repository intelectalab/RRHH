<?php

namespace App\Shared\Exceptions;

use App\Shared\Http\Responses\ProblemDetailsResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * AyniException — Excepción base de dominio y aplicación para errores controlados.
 */
class AyniException extends Exception
{
    /**
     * @param  array<int, array{field: string, rule: string, message: string}>  $details
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public function __construct(
        protected string $errorCode,
        int $statusCode = 400,
        ?string $message = null,
        protected array $details = [],
        protected array $meta = [],
        protected array $headers = [],
        ?Exception $previous = null,
    ) {
        $resolvedMessage = $message ?: (string) trans("errors.{$errorCode}");
        parent::__construct($resolvedMessage, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->code > 0 ? $this->code : 400;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<int, array{field: string, rule: string, message: string}>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function render(): JsonResponse
    {
        return ProblemDetailsResponse::make(
            status: $this->getStatusCode(),
            code: $this->getErrorCode(),
            message: $this->getMessage(),
            details: $this->getDetails(),
            meta: $this->getMeta(),
            headers: $this->getHeaders(),
        );
    }
}
