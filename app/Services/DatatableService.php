<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * @return array<string, mixed>
     */
    public function paginate(
        Builder $query,
        Request $request,
        array $searchableColumns = [],
        array $sortableColumns = [],
        string $defaultSortColumn = 'id',
        string $defaultSortDirection = 'asc',
    ): array {
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

        $this->applySearch($query, $search, $searchableColumns);

        $query->orderBy($sortColumn, $sortDirection);

        $paginator = $query->paginate(
            perPage: $perPage,
            page: max(1, $request->integer('page', 1)),
        );

        return $this->formatResponse($paginator);
    }

    /** @param array<string> $searchableColumns */
    private function applySearch(
        Builder $query,
        string $search,
        array $searchableColumns,
    ): void {
        if ($search === '' || $searchableColumns === []) {
            return;
        }

        $query->where(function (Builder $searchQuery) use (
            $search,
            $searchableColumns,
        ): void {
            foreach ($searchableColumns as $index => $column) {
                if ($index === 0) {
                    $searchQuery->where($column, 'like', "%{$search}%");
                    continue;
                }

                $searchQuery->orWhere($column, 'like', "%{$search}%");
            }
        });
    }

    /** @return array<string, mixed> */
    private function formatResponse(
        LengthAwarePaginator $paginator,
    ): array {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'previous' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
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
