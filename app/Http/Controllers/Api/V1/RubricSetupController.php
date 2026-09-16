<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RubricSetupController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $query = $db
            ->table('rubrics')
            ->select([
                'rubrics.id',
                'rubrics.rubrics_name',
                'rubrics.rubrics_desc',
                'rubrics.last_update',
            ])
            ->selectSub(
                $db->table('rubrics_criterion')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('rubrics_criterion.rubrics_id', 'rubrics.id'),
                'total_criteria',
            );

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'rubrics.rubrics_name',
                'rubrics.rubrics_desc',
            ],
            sortableColumns: [
                'rubrics_name' => 'rubrics.rubrics_name',
                'rubrics_desc' => 'rubrics.rubrics_desc',
                'last_update' => 'rubrics.last_update',
            ],
            defaultSortColumn: 'rubrics_name',
            defaultSortDirection: 'asc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        $result['data'] = collect($result['data'] ?? [])
            ->map(function ($row) use ($db): array {
                $record = is_object($row)
                    ? get_object_vars($row)
                    : (array) $row;

                $record['total_points'] = (float) $db
                    ->table('rubrics_criterion_item')
                    ->where('rubrics_id', $record['id'])
                    ->selectRaw(
                        'rubrics_criterion_id, MAX(item_point) AS maximum_points',
                    )
                    ->groupBy('rubrics_criterion_id')
                    ->get()
                    ->sum('maximum_points');

                return $record;
            })
            ->values()
            ->all();

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRubric($request);
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $id = (string) Str::uuid();
        $db->table('rubrics')->insert([
            'id' => $id,
            'rubrics_name' => trim($validated['rubrics_name']),
            'rubrics_desc' => trim((string) ($validated['rubrics_desc'] ?? '')),
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(
            ['id' => $id, 'message' => 'The rubric has been created.'],
            Response::HTTP_CREATED,
        );
    }

    public function update(Request $request, string $rubricId): JsonResponse
    {
        $validated = $this->validateRubric($request);
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->rubricExists($db, $rubricId)) {
            return $this->notFound('The rubric could not be found.');
        }

        $db->table('rubrics')->where('id', $rubricId)->update([
            'rubrics_name' => trim($validated['rubrics_name']),
            'rubrics_desc' => trim((string) ($validated['rubrics_desc'] ?? '')),
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'id' => $rubricId,
            'message' => 'The rubric has been updated.',
        ]);
    }

    public function destroy(Request $request, string $rubricId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->rubricExists($db, $rubricId)) {
            return $this->notFound('The rubric could not be found.');
        }

        if ($db->table('p_assess_h')->where('rubrics_id', $rubricId)->exists()) {
            return response()->json(
                ['message' => 'This rubric cannot be deleted because it is used by a practical assessment.'],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $db->transaction(function () use ($db, $rubricId): void {
                $db->table('rubrics_criterion_item')->where('rubrics_id', $rubricId)->delete();
                $db->table('rubrics_criterion')->where('rubrics_id', $rubricId)->delete();
                $db->table('rubrics')->where('id', $rubricId)->delete();
            });
        } catch (QueryException) {
            return response()->json(
                ['message' => 'The rubric cannot be deleted because it is in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['message' => 'The rubric has been deleted.']);
    }

    public function criteria(Request $request, string $rubricId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->rubricExists($db, $rubricId)) {
            return $this->notFound('The rubric could not be found.');
        }

        $criteria = $db->table('rubrics_criterion')
            ->where('rubrics_id', $rubricId)
            ->orderBy('order_no')
            ->get(['id', 'rubrics_id', 'criterion_title', 'criterion_desc', 'order_no'])
            ->map(function ($criterion) use ($db): object {
                $criterion->levels = $db->table('rubrics_criterion_item')
                    ->where('rubrics_criterion_id', $criterion->id)
                    ->orderByDesc('item_point')
                    ->get([
                        'id',
                        'rubrics_id',
                        'rubrics_criterion_id',
                        'item_point',
                        'item_title',
                        'item_desc',
                        'item_order_no',
                    ]);
                $criterion->maximum_points = $criterion->levels->max('item_point') ?? 0;
                return $criterion;
            });

        return response()->json(['data' => $criteria]);
    }

    public function storeCriterion(Request $request, string $rubricId): JsonResponse
    {
        return $this->saveCriterion($request, $rubricId);
    }

    public function updateCriterion(
        Request $request,
        string $rubricId,
        string $criterionId,
    ): JsonResponse {
        return $this->saveCriterion($request, $rubricId, $criterionId);
    }

    private function saveCriterion(
        Request $request,
        string $rubricId,
        ?string $criterionId = null,
    ): JsonResponse {
        $validated = $request->validate([
            'criterion_title' => ['required', 'string', 'max:255'],
            'criterion_desc' => ['nullable', 'string'],
        ]);
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->rubricExists($db, $rubricId)) {
            return $this->notFound('The rubric could not be found.');
        }

        if ($criterionId === null) {
            $criterionId = (string) Str::uuid();
            $db->table('rubrics_criterion')->insert([
                'id' => $criterionId,
                'rubrics_id' => $rubricId,
                'criterion_title' => trim($validated['criterion_title']),
                'criterion_desc' => trim((string) ($validated['criterion_desc'] ?? '')),
                'order_no' => now()->format('Y-m-d H:i:s'),
            ]);
            $message = 'The criterion has been added.';
            $status = Response::HTTP_CREATED;
        } else {
            if (! $this->criterionExists($db, $rubricId, $criterionId)) {
                return $this->notFound('The criterion could not be found.');
            }
            $db->table('rubrics_criterion')
                ->where('id', $criterionId)
                ->where('rubrics_id', $rubricId)
                ->update([
                    'criterion_title' => trim($validated['criterion_title']),
                    'criterion_desc' => trim((string) ($validated['criterion_desc'] ?? '')),
                ]);
            $message = 'The criterion has been updated.';
            $status = Response::HTTP_OK;
        }

        return response()->json(['id' => $criterionId, 'message' => $message], $status);
    }

    public function destroyCriterion(
        Request $request,
        string $rubricId,
        string $criterionId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->criterionExists($db, $rubricId, $criterionId)) {
            return $this->notFound('The criterion could not be found.');
        }

        $db->transaction(function () use ($db, $criterionId): void {
            $db->table('rubrics_criterion_item')
                ->where('rubrics_criterion_id', $criterionId)
                ->delete();
            $db->table('rubrics_criterion')->where('id', $criterionId)->delete();
        });

        return response()->json(['message' => 'The criterion and its levels have been deleted.']);
    }

    public function storeLevel(
        Request $request,
        string $rubricId,
        string $criterionId,
    ): JsonResponse {
        return $this->saveLevel($request, $rubricId, $criterionId);
    }

    public function updateLevel(
        Request $request,
        string $rubricId,
        string $criterionId,
        string $levelId,
    ): JsonResponse {
        return $this->saveLevel($request, $rubricId, $criterionId, $levelId);
    }

    private function saveLevel(
        Request $request,
        string $rubricId,
        string $criterionId,
        ?string $levelId = null,
    ): JsonResponse {
        $validated = $request->validate([
            'item_point' => ['required', 'numeric', 'min:0'],
            'item_title' => ['required', 'string', 'max:255'],
            'item_desc' => ['nullable', 'string'],
        ]);
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->criterionExists($db, $rubricId, $criterionId)) {
            return $this->notFound('The criterion could not be found.');
        }

        $duplicate = $db->table('rubrics_criterion_item')
            ->where('rubrics_criterion_id', $criterionId)
            ->where('item_point', $validated['item_point']);
        if ($levelId !== null) {
            $duplicate->where('id', '!=', $levelId);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages([
                'item_point' => ['This point value already exists under the criterion.'],
            ]);
        }

        $data = [
            'rubrics_id' => $rubricId,
            'rubrics_criterion_id' => $criterionId,
            'item_point' => $validated['item_point'],
            'item_title' => trim($validated['item_title']),
            'item_desc' => trim((string) ($validated['item_desc'] ?? '')),
        ];

        if ($levelId === null) {
            $levelId = (string) Str::uuid();
            $db->table('rubrics_criterion_item')->insert([
                'id' => $levelId,
                ...$data,
                'item_order_no' => now()->format('Y-m-d'),
            ]);
            $message = 'The performance level has been added.';
            $status = Response::HTTP_CREATED;
        } else {
            if (! $this->levelExists($db, $criterionId, $levelId)) {
                return $this->notFound('The performance level could not be found.');
            }
            $db->table('rubrics_criterion_item')
                ->where('id', $levelId)
                ->where('rubrics_criterion_id', $criterionId)
                ->update($data);
            $message = 'The performance level has been updated.';
            $status = Response::HTTP_OK;
        }

        return response()->json(['id' => $levelId, 'message' => $message], $status);
    }

    public function destroyLevel(
        Request $request,
        string $rubricId,
        string $criterionId,
        string $levelId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (
            ! $this->criterionExists($db, $rubricId, $criterionId)
            || ! $this->levelExists($db, $criterionId, $levelId)
        ) {
            return $this->notFound('The performance level could not be found.');
        }

        $db->table('rubrics_criterion_item')
            ->where('id', $levelId)
            ->where('rubrics_criterion_id', $criterionId)
            ->delete();

        return response()->json(['message' => 'The performance level has been deleted.']);
    }

    private function validateRubric(Request $request): array
    {
        return $request->validate([
            'rubrics_name' => ['required', 'string', 'max:255'],
            'rubrics_desc' => ['nullable', 'string'],
        ]);
    }

    private function rubricExists(ConnectionInterface $db, string $id): bool
    {
        return $db->table('rubrics')->where('id', $id)->exists();
    }

    private function criterionExists(
        ConnectionInterface $db,
        string $rubricId,
        string $criterionId,
    ): bool {
        return $db->table('rubrics_criterion')
            ->where('id', $criterionId)
            ->where('rubrics_id', $rubricId)
            ->exists();
    }

    private function levelExists(
        ConnectionInterface $db,
        string $criterionId,
        string $levelId,
    ): bool {
        return $db->table('rubrics_criterion_item')
            ->where('id', $levelId)
            ->where('rubrics_criterion_id', $criterionId)
            ->exists();
    }

    private function resolveLoginId(Request $request): ?string
    {
        $id = trim((string) $request->session()->get('login_id', ''));
        $authId = $request->user()?->getAuthIdentifier();
        return $id !== '' ? $id : ($authId === null ? null : (string) $authId);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = strtoupper(
            trim((string) $request->session()->get('school_code', '')),
        );
        if ($schoolCode === '') {
            return response()->json(
                ['message' => 'No school has been selected.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$schoolCode] ?? null) : null;
        if (! is_array($school)) {
            return response()->json(
                ['message' => 'The selected school is not configured.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $configuredCode = strtoupper(trim((string) ($school['code'] ?? $schoolCode)));
        if ($configuredCode === '' || ! hash_equals($configuredCode, $schoolCode)) {
            return response()->json(
                ['message' => 'The selected school code is invalid.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $connection = $school['connection'] ?? null;
        if (
            ! is_string($connection)
            || $connection === ''
            || ! is_array(config("database.connections.{$connection}"))
        ) {
            return response()->json(
                ['message' => 'The school database connection is unavailable.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);
        return DB::connection($connection);
    }
}
