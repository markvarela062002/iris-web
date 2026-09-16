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
use Symfony\Component\HttpFoundation\Response;

class ExamSessionController extends Controller
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
            ->table('bs_exam_session')
            ->select([
                'id',
                'session_code',
                'date_from',
                'date_to',
                'login_id',
                'last_update',
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'bs_exam_session.session_code',
            ],
            sortableColumns: [
                'session_code' => 'bs_exam_session.session_code',
                'date_from' => 'bs_exam_session.date_from',
                'date_to' => 'bs_exam_session.date_to',
                'last_update' => 'bs_exam_session.last_update',
            ],
            defaultSortColumn: 'date_from',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        $today = now('Asia/Manila')->toDateString();

        $result['data'] = collect($result['data'] ?? [])
            ->map(function ($row) use ($today): array {
                $record = is_object($row)
                    ? get_object_vars($row)
                    : (array) $row;

                $dateFrom = (string) ($record['date_from'] ?? '');
                $dateTo = (string) ($record['date_to'] ?? '');

                $record['status'] = match (true) {
                    $dateFrom !== '' && $today < $dateFrom => 'Upcoming',
                    $dateTo !== '' && $today > $dateTo => 'Completed',
                    default => 'Active',
                };

                return $record;
            })
            ->values()
            ->all();

        return response()->json($result);
    }

    public function show(
        Request $request,
        string $examSessionId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $examSession = $db
            ->table('bs_exam_session')
            ->where('id', $examSessionId)
            ->first([
                'id',
                'session_code',
                'date_from',
                'date_to',
                'login_id',
                'last_update',
            ]);

        if (! $examSession) {
            return response()->json(
                ['message' => 'The exam session could not be found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return response()->json([
            'data' => $examSession,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateExamSession($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $id = (string) Str::uuid();

        $db->table('bs_exam_session')->insert([
            'id' => $id,
            'session_code' => trim($validated['session_code']),
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(
            [
                'id' => $id,
                'message' => 'The exam session has been created.',
            ],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        Request $request,
        string $examSessionId,
    ): JsonResponse {
        $validated = $this->validateExamSession($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->examSessionExists($db, $examSessionId)) {
            return response()->json(
                ['message' => 'The exam session could not be found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('bs_exam_session')
            ->where('id', $examSessionId)
            ->update([
                'session_code' => trim($validated['session_code']),
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'login_id' => $this->resolveLoginId($request),
                'last_update' => now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'id' => $examSessionId,
            'message' => 'The exam session has been updated.',
        ]);
    }

    public function destroy(
        Request $request,
        string $examSessionId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->examSessionExists($db, $examSessionId)) {
            return response()->json(
                ['message' => 'The exam session could not be found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            $db
                ->table('bs_exam_session')
                ->where('id', $examSessionId)
                ->delete();
        } catch (QueryException) {
            return response()->json(
                [
                    'message' =>
                        'The exam session cannot be deleted because it is already in use.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json([
            'message' => 'The exam session has been deleted.',
        ]);
    }

    /**
     * @return array{session_code: string, date_from: string, date_to: string}
     */
    private function validateExamSession(Request $request): array
    {
        return $request->validate(
            [
                'session_code' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'date_from' => [
                    'required',
                    'date_format:Y-m-d',
                ],
                'date_to' => [
                    'required',
                    'date_format:Y-m-d',
                    'after_or_equal:date_from',
                ],
            ],
            [
                'session_code.required' => 'Session code is required.',
                'date_from.required' => 'Date from is required.',
                'date_to.required' => 'Date to is required.',
                'date_to.after_or_equal' =>
                    'Date to must be the same as or later than date from.',
            ],
        );
    }

    private function examSessionExists(
        ConnectionInterface $db,
        string $examSessionId,
    ): bool {
        return $db
            ->table('bs_exam_session')
            ->where('id', $examSessionId)
            ->exists();
    }

    private function resolveLoginId(Request $request): ?string
    {
        $loginId = trim((string) $request->session()->get('login_id', ''));

        if ($loginId !== '') {
            return $loginId;
        }

        $authenticatedId = $request->user()?->getAuthIdentifier();

        return $authenticatedId === null
            ? null
            : (string) $authenticatedId;
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
        $school = is_array($schools)
            ? ($schools[$schoolCode] ?? null)
            : null;

        if (! is_array($school)) {
            return response()->json(
                ['message' => 'The selected school is not configured.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $configuredCode = strtoupper(
            trim((string) ($school['code'] ?? $schoolCode)),
        );

        if (
            $configuredCode === '' ||
            ! hash_equals($configuredCode, $schoolCode)
        ) {
            return response()->json(
                ['message' => 'The selected school code is invalid.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $connection = $school['connection'] ?? null;

        if (
            ! is_string($connection) ||
            $connection === '' ||
            ! is_array(config("database.connections.{$connection}"))
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
