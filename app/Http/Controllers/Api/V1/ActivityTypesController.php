<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivityTypesController extends Controller
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
            ->table('activity')
            ->select([
                'activity.id',
                'activity.code_activity',
                'activity.desc_activity',
                'activity.activity_remarks',
                'activity.for_admin',
                'activity.seq_no',
                'activity.deployment_id',
                'activity.payment_required',
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'activity.code_activity',
                'activity.desc_activity',
                'activity.activity_remarks',
            ],
            sortableColumns: [
                'code_activity' => 'activity.code_activity',
                'desc_activity' => 'activity.desc_activity',
                'activity_remarks' => 'activity.activity_remarks',
                'for_admin' => 'activity.for_admin',
            ],
            defaultSortColumn: 'code_activity',
            defaultSortDirection: 'asc',
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $input = $this->validatePayload($request);

        $this->ensureUniqueValues(
            db: $db,
            code: $input['code_activity'],
            description: $input['desc_activity'],
        );

        $id = (string) Str::uuid();

        $db->table('activity')->insert([
            'id' => $id,
            'code_activity' => $input['code_activity'],
            'desc_activity' => $input['desc_activity'],
            'seq_no' => 1,
            'deployment_id' => null,
            'payment_required' => 'N',
            'activity_remarks' => $input['activity_remarks'],
            'for_admin' => $input['for_admin'],
        ]);

        return response()->json([
            'id' => $id,
            'message' => 'Activity type created successfully.',
        ], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        string $activityId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activity = $db
            ->table('activity')
            ->where('id', $activityId)
            ->first();

        if (! $activity) {
            return response()->json([
                'message' => 'Activity type was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $input = $this->validatePayload($request);

        $this->ensureUniqueValues(
            db: $db,
            code: $input['code_activity'],
            description: $input['desc_activity'],
            ignoreId: $activityId,
        );

        $db
            ->table('activity')
            ->where('id', $activityId)
            ->update([
                'code_activity' => $input['code_activity'],
                'desc_activity' => $input['desc_activity'],
                'activity_remarks' => $input['activity_remarks'],
                'for_admin' => $input['for_admin'],
            ]);

        return response()->json([
            'id' => $activityId,
            'message' => 'Activity type updated successfully.',
        ]);
    }

    public function destroy(
        Request $request,
        string $activityId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activity = $db
            ->table('activity')
            ->where('id', $activityId)
            ->first();

        if (! $activity) {
            return response()->json([
                'message' => 'Activity type was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $db
                ->table('activity')
                ->where('id', $activityId)
                ->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'Unable to delete this activity type because it is still being used by existing records.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => 'Activity type deleted successfully.',
        ]);
    }

    /**
     * @return array{
     *     code_activity: string,
     *     desc_activity: string,
     *     activity_remarks: string|null,
     *     for_admin: string
     * }
     */
    private function validatePayload(Request $request): array
    {
        $input = $request->validate([
            'code_activity' => [
                'required',
                'string',
                'max:10',
            ],
            'desc_activity' => [
                'required',
                'string',
                'max:100',
            ],
            'activity_remarks' => [
                'nullable',
                'string',
            ],
            'for_admin' => [
                'required',
                'in:Y,N',
            ],
        ]);

        $remarks = isset($input['activity_remarks'])
            ? trim((string) $input['activity_remarks'])
            : '';

        return [
            'code_activity' => trim((string) $input['code_activity']),
            'desc_activity' => trim((string) $input['desc_activity']),
            'activity_remarks' => $remarks !== '' ? $remarks : null,
            'for_admin' => (string) $input['for_admin'],
        ];
    }

    private function ensureUniqueValues(
        ConnectionInterface $db,
        string $code,
        string $description,
        ?string $ignoreId = null,
    ): void {
        $codeQuery = $db
            ->table('activity')
            ->where('code_activity', $code);

        $descriptionQuery = $db
            ->table('activity')
            ->where('desc_activity', $description);

        if ($ignoreId !== null) {
            $codeQuery->where('id', '!=', $ignoreId);
            $descriptionQuery->where('id', '!=', $ignoreId);
        }

        $errors = [];

        if ($codeQuery->exists()) {
            $errors['code_activity'] = [
                'This activity code is already in use.',
            ];
        }

        if ($descriptionQuery->exists()) {
            $errors['desc_activity'] = [
                'This activity description is already in use.',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        if ($schoolCode === '') {
            return response()->json([
                'message' => 'No school database has been selected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $schools = config('schools.schools', []);

        if (! is_array($schools)) {
            return response()->json([
                'message' => 'School configuration is unavailable.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $school = $schools[$schoolCode] ?? null;

        if (! is_array($school)) {
            return response()->json([
                'message' => 'The selected school is not configured.',
                'schoolCode' => $schoolCode,
            ], Response::HTTP_FORBIDDEN);
        }

        $configuredCode = strtoupper(
            trim(
                (string) ($school['code'] ?? $schoolCode),
            ),
        );

        if (
            $configuredCode === ''
            ||
            ! hash_equals($configuredCode, $schoolCode)
        ) {
            return response()->json([
                'message' => 'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = $school['connection'] ?? null;

        if (
            ! is_string($connection)
            ||
            $connection === ''
        ) {
            return response()->json([
                'message' => 'The school database connection is missing.',
                'schoolCode' => $schoolCode,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (! is_array($connectionConfig)) {
            return response()->json([
                'message' => 'The school database connection is not configured.',
                'schoolCode' => $schoolCode,
                'connection' => $connection,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        return DB::connection($connection);
    }
}
