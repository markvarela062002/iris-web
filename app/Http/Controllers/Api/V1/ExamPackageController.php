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

class ExamPackageController extends Controller
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
            ->table('bs_course')
            ->select([
                'bs_course.id',
                'bs_course.code_course',
                'bs_course.name_course',
                'bs_course.randomize',
                'bs_course.last_update',
            ])
            ->selectSub(
                $db
                    ->table('bs_topic')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('bs_topic.bs_course_id', 'bs_course.id'),
                'total_subjects',
            );

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'bs_course.code_course',
                'bs_course.name_course',
            ],
            sortableColumns: [
                'code_course' => 'bs_course.code_course',
                'name_course' => 'bs_course.name_course',
                'randomize' => 'bs_course.randomize',
                'last_update' => 'bs_course.last_update',
            ],
            defaultSortColumn: 'name_course',
            defaultSortDirection: 'asc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        return response()->json($result);
    }

    public function show(Request $request, string $packageId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $package = $db
            ->table('bs_course')
            ->where('id', $packageId)
            ->first([
                'id',
                'code_course',
                'name_course',
                'randomize',
                'pct_easy',
                'pct_medium',
                'pct_hard',
                'company_id',
                'login_id',
                'last_update',
            ]);

        if (! $package) {
            return $this->notFound('The exam package could not be found.');
        }

        return response()->json(['data' => $package]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePackage($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $id = (string) Str::uuid();

        $db->table('bs_course')->insert([
            'id' => $id,
            'code_course' => trim($validated['code_course']),
            'name_course' => trim($validated['name_course']),
            'randomize' => $validated['randomize'],
            'pct_easy' => 0,
            'pct_medium' => 0,
            'pct_hard' => 0,
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d'),
        ]);

        return response()->json(
            [
                'id' => $id,
                'message' => 'The exam package has been created.',
            ],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        Request $request,
        string $packageId,
    ): JsonResponse {
        $validated = $this->validatePackage($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->packageExists($db, $packageId)) {
            return $this->notFound('The exam package could not be found.');
        }

        $db
            ->table('bs_course')
            ->where('id', $packageId)
            ->update([
                'code_course' => trim($validated['code_course']),
                'name_course' => trim($validated['name_course']),
                'randomize' => $validated['randomize'],
                'login_id' => $this->resolveLoginId($request),
                'last_update' => now()->format('Y-m-d'),
            ]);

        return response()->json([
            'id' => $packageId,
            'message' => 'The exam package has been updated.',
        ]);
    }

    public function destroy(
        Request $request,
        string $packageId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->packageExists($db, $packageId)) {
            return $this->notFound('The exam package could not be found.');
        }

        if ($db->table('bs_topic')->where('bs_course_id', $packageId)->exists()) {
            return response()->json(
                [
                    'message' =>
                        'Delete all subjects before deleting this exam package.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $db->table('bs_course')->where('id', $packageId)->delete();
        } catch (QueryException) {
            return response()->json(
                ['message' => 'The exam package cannot be deleted because it is in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json([
            'message' => 'The exam package has been deleted.',
        ]);
    }

    public function subjects(
        Request $request,
        string $packageId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->packageExists($db, $packageId)) {
            return $this->notFound('The exam package could not be found.');
        }

        $subjects = $db
            ->table('bs_topic')
            ->where('bs_course_id', $packageId)
            ->orderBy('order_no')
            ->get([
                'id',
                'bs_course_id',
                'desc_topic',
                'no_quest',
                'passing_mark',
                'order_no',
                'func',
                'last_update',
            ])
            ->map(function ($subject): object {
                $subject->desc_topic = $this->decodeDescription(
                    (string) $subject->desc_topic,
                );

                return $subject;
            })
            ->values();

        return response()->json(['data' => $subjects]);
    }

    public function storeSubject(
        Request $request,
        string $packageId,
    ): JsonResponse {
        $validated = $this->validateSubject($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->packageExists($db, $packageId)) {
            return $this->notFound('The exam package could not be found.');
        }

        $encodedDescription = $this->encodeDescription(
            $validated['desc_topic'],
        );

        $this->ensureSubjectIsUnique(
            $db,
            $packageId,
            $encodedDescription,
            (int) $validated['order_no'],
        );

        $id = (string) Str::uuid();

        $db->table('bs_topic')->insert([
            'id' => $id,
            'bs_course_id' => $packageId,
            'desc_topic' => $encodedDescription,
            'no_quest' => $validated['no_quest'],
            'passing_mark' => $validated['passing_mark'],
            'order_no' => $validated['order_no'],
            'func' => 0,
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(
            [
                'id' => $id,
                'message' => 'The subject has been added.',
            ],
            Response::HTTP_CREATED,
        );
    }

    public function updateSubject(
        Request $request,
        string $packageId,
        string $subjectId,
    ): JsonResponse {
        $validated = $this->validateSubject($request);
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->subjectExists($db, $packageId, $subjectId)) {
            return $this->notFound('The subject could not be found.');
        }

        $encodedDescription = $this->encodeDescription(
            $validated['desc_topic'],
        );

        $this->ensureSubjectIsUnique(
            $db,
            $packageId,
            $encodedDescription,
            (int) $validated['order_no'],
            $subjectId,
        );

        $db
            ->table('bs_topic')
            ->where('id', $subjectId)
            ->where('bs_course_id', $packageId)
            ->update([
                'desc_topic' => $encodedDescription,
                'no_quest' => $validated['no_quest'],
                'passing_mark' => $validated['passing_mark'],
                'order_no' => $validated['order_no'],
                'login_id' => $this->resolveLoginId($request),
                'last_update' => now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'id' => $subjectId,
            'message' => 'The subject has been updated.',
        ]);
    }

    public function destroySubject(
        Request $request,
        string $packageId,
        string $subjectId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        if (! $this->subjectExists($db, $packageId, $subjectId)) {
            return $this->notFound('The subject could not be found.');
        }

        try {
            $db
                ->table('bs_topic')
                ->where('id', $subjectId)
                ->where('bs_course_id', $packageId)
                ->delete();
        } catch (QueryException) {
            return response()->json(
                ['message' => 'The subject cannot be deleted because it is in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json([
            'message' => 'The subject has been deleted.',
        ]);
    }

    private function validatePackage(Request $request): array
    {
        return $request->validate([
            'code_course' => ['required', 'string', 'max:255'],
            'name_course' => ['required', 'string', 'max:255'],
            'randomize' => ['required', 'in:Y,N'],
        ]);
    }

    private function validateSubject(Request $request): array
    {
        return $request->validate([
            'desc_topic' => ['required', 'string', 'max:255'],
            'no_quest' => ['required', 'integer', 'min:1'],
            'passing_mark' => ['required', 'numeric', 'min:0'],
            'order_no' => ['required', 'integer', 'min:1'],
        ]);
    }

    private function ensureSubjectIsUnique(
        ConnectionInterface $db,
        string $packageId,
        string $description,
        int $orderNumber,
        ?string $ignoreId = null,
    ): void {
        $descriptionQuery = $db
            ->table('bs_topic')
            ->where('bs_course_id', $packageId)
            ->where('desc_topic', $description);

        $orderQuery = $db
            ->table('bs_topic')
            ->where('bs_course_id', $packageId)
            ->where('order_no', $orderNumber);

        if ($ignoreId !== null) {
            $descriptionQuery->where('id', '!=', $ignoreId);
            $orderQuery->where('id', '!=', $ignoreId);
        }

        $errors = [];

        if ($descriptionQuery->exists()) {
            $errors['desc_topic'] = ['Description already exists.'];
        }

        if ($orderQuery->exists()) {
            $errors['order_no'] = ['Order number already exists.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function encodeDescription(string $description): string
    {
        return urlencode(str_replace('&', 'andxx', trim($description)));
    }

    private function decodeDescription(string $description): string
    {
        return str_replace('andxx', '&', urldecode($description));
    }

    private function packageExists(ConnectionInterface $db, string $id): bool
    {
        return $db->table('bs_course')->where('id', $id)->exists();
    }

    private function subjectExists(
        ConnectionInterface $db,
        string $packageId,
        string $subjectId,
    ): bool {
        return $db
            ->table('bs_topic')
            ->where('id', $subjectId)
            ->where('bs_course_id', $packageId)
            ->exists();
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(
            ['message' => $message],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function resolveLoginId(Request $request): ?string
    {
        $id = trim((string) $request->session()->get('login_id', ''));

        if ($id !== '') {
            return $id;
        }

        $authId = $request->user()?->getAuthIdentifier();

        return $authId === null ? null : (string) $authId;
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