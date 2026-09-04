<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DatatableService
{
    /**
     * Apply searching, sorting, and pagination to a query.
     *
     * @param  array<string>  $searchableColumns
     * @param  array<string, string>  $sortableColumns
     * @param  array<string>  $selectColumns  Explicit columns to select.
     *         Leave empty to select all columns (previous default behavior).
     * @param  bool  $simplePaginate  When true, skips the COUNT(*) query
     *         (no total/lastPage in the response). Use for tables where the
     *         UI only needs prev/next, not a page count — this is usually
     *         the single biggest speed win on large tables.
     * @param  string  $searchMode  'contains' (default, original behavior,
     *         "%term%") or 'starts_with' ("term%"), which can use a normal
     *         B-tree index and is much faster on large tables when the UX
     *         allows prefix search instead of substring search.
     * @return array<string, mixed>
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

        $sortDirection = strtolower(
            (string) $request->input(
                'sort_direction',
                $defaultSortDirection,
            ),
        );

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = $defaultSortDirection;
        }

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

    /** @param array<string> $searchableColumns */
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

    /** @return array<string, mixed> */
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

        // Only LengthAwarePaginator (paginate()) knows the total row count
        // and last page — simplePaginate() intentionally skips the COUNT(*)
        // query needed to compute these, which is where the speed gain
        // comes from.
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

    /** Add a continuous row number to the current page. */
    public function addRowNumbers(
        array $response,
        string $key = 'index',
    ): array {
        $currentPage = (int) $response['meta']['currentPage'];
        $perPage = (int) $response['meta']['perPage'];
        $startingIndex = (($currentPage - 1) * $perPage) + 1;

        $response['data'] = Collection::make($response['data'])
            ->values()
            ->map(function (
                object|array $row,
                int $position,
            ) use ($key, $startingIndex): array {
                $rowData = is_object($row) ? (array) $row : $row;

                return [
                    $key => $startingIndex + $position,
                    ...$rowData,
                ];
            })
            ->all();

        return $response;
    }
}