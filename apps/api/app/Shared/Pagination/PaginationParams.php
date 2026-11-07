<?php

namespace App\Shared\Pagination;

use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\Request;

final class PaginationParams
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $cursor,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $rawPerPage = $request->query('per_page');
        $perPage = self::DEFAULT_PER_PAGE;

        if ($rawPerPage !== null) {
            if (! is_numeric($rawPerPage) || (int) $rawPerPage <= 0) {
                throw new InvalidFilterException(
                    message: 'El parámetro per_page debe ser un número entero positivo.',
                    allowed: ['per_page <= 100'],
                );
            }

            $perPage = (int) $rawPerPage;
            if ($perPage > self::MAX_PER_PAGE) {
                throw new InvalidFilterException(
                    message: sprintf('El parámetro per_page no puede exceder el límite máximo de %d.', self::MAX_PER_PAGE),
                    allowed: ['per_page <= 100'],
                );
            }
        }

        $rawPage = $request->query('page');
        $page = 1;
        if ($rawPage !== null) {
            if (! is_numeric($rawPage) || (int) $rawPage <= 0) {
                throw new InvalidFilterException(
                    message: 'El parámetro page debe ser un número entero positivo.',
                );
            }
            $page = (int) $rawPage;
        }

        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null;

        return new self($page, $perPage, $cursor);
    }
}
