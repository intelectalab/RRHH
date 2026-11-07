<?php

namespace App\Shared\Pagination;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class CursorPaginator
{
    /**
     * Encode an identifier/value tuple into an opaque cursor string.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Decode an opaque cursor string into its payload array.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Paginate a query using cursor-based pagination.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  string  $cursorColumn  Column to order by (defaults to 'id')
     * @param  string  $direction  'asc' or 'desc'
     * @return array{data: array<mixed>, meta: array{per_page: int, next_cursor: string|null, prev_cursor: string|null}}
     */
    public static function paginate(
        EloquentBuilder|QueryBuilder $query,
        int $perPage = PaginationParams::DEFAULT_PER_PAGE,
        ?string $cursor = null,
        string $cursorColumn = 'id',
        string $direction = 'asc',
    ): array {
        $perPage = min(max(1, $perPage), PaginationParams::MAX_PER_PAGE);
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $operator = $direction === 'asc' ? '>' : '<';

        $decoded = self::decodeCursor($cursor);
        if ($decoded !== null && isset($decoded[$cursorColumn])) {
            $query->where($cursorColumn, $operator, $decoded[$cursorColumn]);
        }

        $query->orderBy($cursorColumn, $direction);

        // Fetch one extra to know if there is a next page
        $results = $query->limit($perPage + 1)->get();
        $hasMore = $results->count() > $perPage;

        $items = $hasMore ? $results->slice(0, $perPage)->values() : $results->values();

        $nextCursor = null;
        if ($hasMore) {
            $lastItem = $items->last();
            if ($lastItem !== null) {
                $lastValue = $lastItem->{$cursorColumn} ?? null;
                if ($lastValue !== null) {
                    $nextCursor = self::encodeCursor([$cursorColumn => $lastValue]);
                }
            }
        }

        $prevCursor = null;
        if ($decoded !== null) {
            $firstItem = $items->first();
            if ($firstItem !== null) {
                $firstValue = $firstItem->{$cursorColumn} ?? null;
                if ($firstValue !== null) {
                    $prevCursor = self::encodeCursor([$cursorColumn => $firstValue]);
                }
            }
        }

        $data = $items->map(function (mixed $item): array {
            if ($item instanceof Arrayable) {
                /** @var array<string, mixed> $array */
                $array = $item->toArray();

                return $array;
            }

            return (array) $item;
        })->all();

        return [
            'data' => $data,
            'meta' => [
                'per_page' => $perPage,
                'next_cursor' => $nextCursor,
                'prev_cursor' => $prevCursor,
            ],
        ];
    }
}
