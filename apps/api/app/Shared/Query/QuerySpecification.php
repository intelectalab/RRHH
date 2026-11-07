<?php

namespace App\Shared\Query;

use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\Request;

final class QuerySpecification
{
    /**
     * @param  array<string>  $allowedFilters
     * @param  array<string>  $allowedSorts
     */
    public function __construct(
        public readonly array $allowedFilters = [],
        public readonly array $allowedSorts = [],
    ) {}

    /**
     * Validate the given request query parameters against declared filters and sorts.
     *
     * @throws InvalidFilterException
     */
    public function validate(Request $request): void
    {
        // 1. Validate requested filters: ?filter[field]=val
        $filters = $request->query('filter');
        if (is_array($filters)) {
            foreach (array_keys($filters) as $field) {
                $fieldName = (string) $field;
                if (! in_array($fieldName, $this->allowedFilters, true)) {
                    throw new InvalidFilterException(
                        message: sprintf("El filtro '%s' no está permitido para este recurso.", $fieldName),
                        allowed: $this->allowedFilters,
                    );
                }
            }
        }

        // 2. Validate requested sorts: ?sort=-field,other_field
        $sortParam = $request->query('sort');
        if (is_string($sortParam) && trim($sortParam) !== '') {
            $sortFields = explode(',', trim($sortParam));
            foreach ($sortFields as $sortField) {
                $cleanField = ltrim(trim($sortField), '-+');
                if ($cleanField !== '' && ! in_array($cleanField, $this->allowedSorts, true)) {
                    throw new InvalidFilterException(
                        message: sprintf("El ordenamiento por '%s' no está permitido para este recurso.", $cleanField),
                        allowed: $this->allowedSorts,
                    );
                }
            }
        }
    }
}
