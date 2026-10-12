<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use stdClass;

/**
 * @phpstan-type TableResponse array{
 *     data: array<array-key, object|array<string, mixed>>,
 *     meta: array{
 *         currentPage: int,
 *         perPage: int,
 *         lastPage?: int,
 *         total?: int,
 *         from?: int|null,
 *         to?: int|null
 *     },
 *     links: array<string, string|null>
 * }
 */
class DatatableService
{
    /**
     * Apply searching, sorting, and pagination to a query.
     *
     * @param  array<int, string>  $searchableColumns
     * @param  array<string, string>  $sortableColumns
     * @param  array<int, string>  $selectColumns
     * @return TableResponse
     */
    public function paginate(
        Builder $query,
        Request $request,
        array $searchableColumns = [],
        array $sortableColumns = [],
        string $defaultSortColumn = 'id',
        string $defaultSortDirection = 'asc',
        array $selectColumns = [],
        bool $simplePaginate = false,
        string $searchMode = 'contains',
    ): array {
        if ($selectColumns !== []) {
            $query->select($selectColumns);
        }

        $search = trim((string) $request->input('search', ''));

        $allowedPerPage = [10, 20, 50, 100];
        $requestedPerPage = $request->integer('per_page', 10);
        $perPage = in_array($requestedPerPage, $allowedPerPage, true)
            ? $requestedPerPage
            : 10;

        $requestedSortColumn = (string) $request->input(
            'sort_field',
            $defaultSortColumn,
        );

        $requestedSortDirection = strtolower(
            (string) $request->input(
                'sort_direction',
                $defaultSortDirection,
            ),
        );

        $fallbackSortDirection = strtolower($defaultSortDirection) === 'desc'
            ? 'desc'
            : 'asc';

        $sortDirection = match ($requestedSortDirection) {
            'asc' => 'asc',
            'desc' => 'desc',
            default => $fallbackSortDirection,
        };

        $sortColumn = $sortableColumns[$requestedSortColumn]
            ?? $sortableColumns[$defaultSortColumn]
            ?? $defaultSortColumn;

        $this->applySearch($query, $search, $searchableColumns, $searchMode);

        $query->orderBy($sortColumn, $sortDirection);

        $page = max(1, $request->integer('page', 1));

        $paginator = $simplePaginate
            ? $query->simplePaginate(perPage: $perPage, page: $page)
            : $query->paginate(perPage: $perPage, page: $page);

        return $this->formatResponse($paginator);
    }

    /**
     * @param  array<int, string>  $searchableColumns
     */
    private function applySearch(
        Builder $query,
        string $search,
        array $searchableColumns,
        string $searchMode,
    ): void {
        if ($search === '' || $searchableColumns === []) {
            return;
        }

        $pattern = $searchMode === 'starts_with'
            ? "{$search}%"
            : "%{$search}%";

        $query->where(function (Builder $searchQuery) use (
            $pattern,
            $searchableColumns,
        ): void {
            foreach ($searchableColumns as $index => $column) {
                if ($index === 0) {
                    $searchQuery->where($column, 'like', $pattern);

                    continue;
                }

                $searchQuery->orWhere($column, 'like', $pattern);
            }
        });
    }

    /**
     * @param  LengthAwarePaginator<int, stdClass>|Paginator<int, stdClass>  $paginator
     * @return TableResponse
     */
    private function formatResponse(
        LengthAwarePaginator|Paginator $paginator,
    ): array {
        $meta = [
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
        ];

        $links = [
            'previous' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $meta['lastPage'] = $paginator->lastPage();
            $meta['total'] = $paginator->total();
            $meta['from'] = $paginator->firstItem();
            $meta['to'] = $paginator->lastItem();

            $links['first'] = $paginator->url(1);
            $links['last'] = $paginator->url($paginator->lastPage());
        }

        return [
            'data' => $paginator->items(),
            'meta' => $meta,
            'links' => $links,
        ];
    }

    /**
     * Add a continuous row number to the current page.
     *
     * @param  TableResponse  $response
     * @return TableResponse
     */
    public function addRowNumbers(
        array $response,
        string $key = 'index',
    ): array {
        $currentPage = $response['meta']['currentPage'];
        $perPage = $response['meta']['perPage'];
        $startingIndex = (($currentPage - 1) * $perPage) + 1;

        $numberedRows = [];
        $position = 0;

        foreach ($response['data'] as $row) {
            $rowData = is_object($row) ? (array) $row : $row;

            $numberedRows[] = [
                $key => $startingIndex + $position,
                ...$rowData,
            ];

            $position++;
        }

        $response['data'] = $numberedRows;

        return $response;
    }
}
