<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementsController extends Controller
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
            ->table('announcement')
            ->select([
                'announcement.id',
                'announcement.ref_no',
                'announcement.subject',
                'announcement.details',
                'announcement.post_by',
                'announcement.post_until',
                'announcement.to_all',
                'announcement.posted_by_id',
                'announcement.login_id',
                'announcement.last_update',
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'announcement.ref_no',
                'announcement.subject',
                'announcement.details',
                'announcement.post_by',
                'announcement.post_until',
            ],
            sortableColumns: [
                'subject' => 'announcement.subject',
                'post_by' => 'announcement.post_by',
                'post_until' => 'announcement.post_until',
                'last_update' => 'announcement.last_update',
            ],
            defaultSortColumn: 'post_by',
            defaultSortDirection: 'desc',
        );

        $today = now()->toDateString();

        $result['data'] = collect($result['data'])
            ->map(
                static function (
                    object|array $row,
                ) use ($today): array {
                    $data = is_object($row)
                        ? (array) $row
                        : $row;

                    $postFrom = trim(
                        (string) (
                            $data['post_by']
                            ?? ''
                        ),
                    );

                    $postUntil = trim(
                        (string) (
                            $data['post_until']
                            ?? ''
                        ),
                    );

                    $status = 'Active';

                    if (
                        $postFrom !== ''
                        && $postFrom > $today
                    ) {
                        $status = 'Upcoming';
                    } elseif (
                        $postUntil !== ''
                        && $postUntil < $today
                    ) {
                        $status = 'Expired';
                    }

                    return [
                        ...$data,
                        'status' => $status,
                    ];
                },
            )
            ->values()
            ->all();

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $validated = $this->validatedAnnouncement($request);
        $announcementId = (string) Str::uuid();
        $loginId = $this->loginId($request);

        $db->table('announcement')->insert([
            'id' => $announcementId,
            'ref_no' => now()->format('ymdHis'),
            'subject' => $validated['subject'],
            'details' => $validated['details'],
            'post_by' => $validated['post_by'],
            'post_until' => $validated['post_until'],
            'to_all' => 'Y',
            'posted_by_id' => null,
            'login_id' => $loginId !== ''
                ? $loginId
                : null,
            'last_update' => now()->format(
                'Y-m-d H:i:s',
            ),
        ]);

        return response()->json([
            'id' => $announcementId,
            'message' => 'Announcement created successfully.',
        ], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        string $announcementId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $validated = $this->validatedAnnouncement($request);

        $exists = $db
            ->table('announcement')
            ->where('id', $announcementId)
            ->exists();

        if (! $exists) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $loginId = $this->loginId($request);

        $db
            ->table('announcement')
            ->where('id', $announcementId)
            ->update([
                'subject' => $validated['subject'],
                'details' => $validated['details'],
                'post_by' => $validated['post_by'],
                'post_until' => $validated['post_until'],
                'to_all' => 'Y',
                'login_id' => $loginId !== ''
                    ? $loginId
                    : null,
                'last_update' => now()->format(
                    'Y-m-d H:i:s',
                ),
            ]);

        return response()->json([
            'id' => $announcementId,
            'message' => 'Announcement updated successfully.',
        ]);
    }

    public function destroy(
        Request $request,
        string $announcementId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $exists = $db
            ->table('announcement')
            ->where('id', $announcementId)
            ->exists();

        if (! $exists) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $db->transaction(
            function () use (
                $db,
                $announcementId,
            ): void {
                if (
                    $db
                        ->getSchemaBuilder()
                        ->hasTable('announcement_file')
                ) {
                    $db
                        ->table('announcement_file')
                        ->where(
                            'announcement_id',
                            $announcementId,
                        )
                        ->delete();
                }

                $db
                    ->table('announcement')
                    ->where('id', $announcementId)
                    ->delete();
            },
        );

        return response()->json([
            'message' => 'Announcement deleted successfully.',
        ]);
    }

    private function validatedAnnouncement(
        Request $request,
    ): array {
        return $request->validate([
            'post_by' => [
                'required',
                'date_format:Y-m-d',
            ],
            'post_until' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:post_by',
            ],
            'subject' => [
                'required',
                'string',
                'max:200',
            ],
            'details' => [
                'required',
                'string',
            ],
        ]);
    }

    private function loginId(
        Request $request,
    ): string {
        return trim(
            (string) (
                $request
                    ->session()
                    ->get('login_id')
                ??
                $request
                    ->user()
                    ?->getAuthIdentifier()
                ??
                ''
            ),
        );
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
                (string) (
                    $school['code']
                    ?? $schoolCode
                ),
            ),
        );

        if (! hash_equals(
            $configuredCode,
            $schoolCode,
        )) {
            return response()->json([
                'message' => 'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = $school['connection'] ?? null;

        if (
            ! is_string($connection)
            || $connection === ''
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
