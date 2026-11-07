<?php

namespace App\Shared\Pagination;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class OffsetPaginator
{
    /**
     * Paginate a query using offset-based pagination.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  bool  $countTotal  Whether to count total records (can be false for performance on large collections)
     * @return array{data: array<mixed>, meta: array{page: int, per_page: int, total: int|null, total_pages: int|null, next_cursor: null}}
     */
    public static function paginate(
        EloquentBuilder|QueryBuilder $query,
        int $page = 1,
        int $perPage = PaginationParams::DEFAULT_PER_PAGE,
        bool $countTotal = true,
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), PaginationParams::MAX_PER_PAGE);

        $total = null;
        $totalPages = null;

        if ($countTotal) {
            $total = (int) (clone $query)->count();
            $totalPages = (int) ceil($total / $perPage);
        }

        $rawItems = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = $rawItems->map(function (mixed $item): array {
            if ($item instanceof Arrayable) {
                /** @var array<string, mixed> $array */
                $array = $item->toArray();

                return $array;
            }

            return (array) $item;
        })->all();

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'next_cursor' => null,
            ],
        ];
    }
}
