<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Student;

class ActivitiesController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return activities waiting for verification.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
         * Resolve the school selected during login.
         */
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        /*
         * Get the school's public activity-file URL.
         *
         * Example:
         * https://igcfi-iris.com/person_task
         */
        $activityFileBaseUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.activity_url",
                    '',
                ),
            ),
            '/',
        );

        /*
         * Legacy administrator filter:
         *
         * sto_validated != 'Y'
         * for_app = 'Y'
         * ORDER BY last_update DESC
         */
        $query = $db
            ->table('person_activity')
            ->leftJoin(
                'person',
                'person_activity.person_id',
                '=',
                'person.id',
            )
            ->leftJoin(
                'activity',
                'person_activity.activity_id',
                '=',
                'activity.id',
            )
            ->select([
                'person_activity.id',
                'person_activity.person_id',
                'person_activity.activity_id',
                'person_activity.filename',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.last_update',
                'person_activity.sto_validated',
                'person_activity.for_app',
                'person_activity.revise_remarks',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',

                'activity.desc_activity',
            ]);

            if (! $request->boolean('monitoring')) {
                $query
                    ->where(
                        'person_activity.sto_validated',
                        '!=',
                        'Y',
                    )
                    ->where(
                        'person_activity.for_app',
                        '=',
                        'Y',
                    );
            }
        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'activity.desc_activity',
                'person_activity.filename',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.last_update',
            ],
            sortableColumns: [
                'last_update' =>
                    'person_activity.last_update',

                'code_person' =>
                    'person.code_person',

                'school_id_no' =>
                    'person.school_id_no',

                'fname' =>
                    'person.fname',

                'lname' =>
                    'person.lname',

                'desc_activity' =>
                    'activity.desc_activity',

                'filename' =>
                    'person_activity.filename',

                'start_date' =>
                    'person_activity.start_date',

                'end_date' =>
                    'person_activity.end_date',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        /*
         * Generate a direct public HTTPS URL for every
         * activity attachment.
         *
         * The browser requests the public file directly,
         * so Laravel does not need to proxy it through FTP.
         */
        $result['data'] = collect(
            $result['data'] ?? [],
        )
            ->map(
                function ($row) use (
                    $activityFileBaseUrl,
                ): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    /*
                     * Remove accidental directory components
                     * stored in the database.
                     */
                    $filename = basename(
                        str_replace(
                            '\\',
                            '/',
                            trim(
                                (string) (
                                    $record['filename']
                                    ?? ''
                                ),
                            ),
                        ),
                    );

                    $record['filename'] =
                        $filename;

                    $record['file_url'] =
                        $filename !== '' &&
                        $activityFileBaseUrl !== ''
                            ? $activityFileBaseUrl.
                                '/'.
                                rawurlencode(
                                    $filename,
                                )
                            : null;

                    return $record;
                },
            )
            ->values()
            ->all();

        return response()->json(
            $result,
        );
    }

    /**
 * Return the authenticated student's activity summary.
 */
public function studentDashboard(
    Request $request,
): JsonResponse {
    $account = $request->user();

    /*
     * Only authenticated student accounts may use this
     * endpoint.
     */
    if (! $account instanceof Student) {
        return response()->json(
            [
                'message' =>
                    'Only student accounts may access this resource.',
            ],
            Response::HTTP_FORBIDDEN,
        );
    }

    $db = $this->resolveSchoolConnection(
        $request,
    );

    if ($db instanceof JsonResponse) {
        return $db;
    }

    $studentId = (string) $account
        ->getAuthIdentifier();

    /*
     * Count every activity belonging to the currently
     * authenticated student.
     */
    $total = $db
        ->table('person_activity')
        ->where(
            'person_id',
            $studentId,
        )
        ->count();

    /*
     * Return only the student's 10 most recent activities.
     */
    $activities = $db
        ->table('person_activity')
        ->leftJoin(
            'activity',
            'person_activity.activity_id',
            '=',
            'activity.id',
        )
        ->where(
            'person_activity.person_id',
            $studentId,
        )
        ->select([
            'person_activity.id',
            'person_activity.activity_id',
            'person_activity.filename',
            'person_activity.start_date',
            'person_activity.end_date',
            'person_activity.last_update',
            'person_activity.sto_validated',
            'person_activity.for_app',
            'person_activity.revise_remarks',
            'activity.desc_activity',
        ])
        ->orderByDesc(
            'person_activity.last_update',
        )
        ->limit(10)
        ->get()
        ->map(
            function (object $activity): array {
                $validated = strtoupper(
                    trim(
                        (string) (
                            $activity->sto_validated
                            ?? ''
                        ),
                    ),
                );

                $forApproval = strtoupper(
                    trim(
                        (string) (
                            $activity->for_app
                            ?? ''
                        ),
                    ),
                );

                $remarks = trim(
                    (string) (
                        $activity->revise_remarks
                        ?? ''
                    ),
                );

                if ($validated === 'Y') {
                    $status = 'Verified';
                } elseif ($forApproval === 'Y') {
                    $status = 'For Verification';
                } elseif ($remarks !== '') {
                    $status = 'For Revision';
                } else {
                    $status = 'Draft';
                }

                return [
                    'id' => $activity->id,

                    'activity_id' =>
                        $activity->activity_id,

                    'description' =>
                        $activity->desc_activity
                        ?? 'Activity',

                    'filename' =>
                        $activity->filename,

                    'start_date' =>
                        $activity->start_date,

                    'end_date' =>
                        $activity->end_date,

                    'last_update' =>
                        $activity->last_update,

                    'sto_validated' =>
                        $activity->sto_validated,

                    'for_app' =>
                        $activity->for_app,

                    'revise_remarks' =>
                        $remarks,

                    'status' => $status,
                ];
            },
        )
        ->values();

    return response()->json([
        'total' => $total,
        'data' => $activities,
    ]);
}

    /**
     * Return activity options for the Monitoring filter.
     */
    public function activityOptions(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activities = $db
            ->table('activity')
            ->select([
                'id',
                'desc_activity',
            ])
            ->orderBy(
                'desc_activity',
            )
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * Verify a submitted activity.
     *
     * Legacy update:
     *
     * sto_validated = 'Y'
     * for_app = 'N'
     * revise_remarks = ''
     * last_update = current date and time
     */
    public function verify(
        Request $request,
        string $activityId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activityExists = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->exists();

        if (!$activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->update([
                'sto_validated' => 'Y',
                'for_app' => 'N',
                'revise_remarks' => '',

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'The activity has been validated.',
        ]);
    }

    /**
     * Return an activity to the student for revision.
     *
     * Legacy update:
     *
     * for_app = 'N'
     * revise_remarks = submitted remarks
     * last_update = current date and time
     */
    public function revise(
        Request $request,
        string $activityId,
    ): JsonResponse {
        $validated = $request->validate(
            [
                'revise_remarks' => [
                    'required',
                    'string',
                ],
            ],
            [
                'revise_remarks.required' =>
                    'Reason for revision is required.',
            ],
        );

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activityExists = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->exists();

        if (!$activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->update([
                'for_app' => 'N',

                'revise_remarks' => trim(
                    $validated['revise_remarks'],
                ),

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'The activity has been saved.',
        ]);
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
            return response()->json(
                [
                    'message' =>
                        'No school has been selected.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $schools = config(
            'schools.schools',
            [],
        );

        if (!is_array($schools)) {
            return response()->json(
                [
                    'message' =>
                        'School configuration is unavailable.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $school =
            $schools[$schoolCode] ?? null;

        if (!is_array($school)) {
            return response()->json(
                [
                    'message' =>
                        'The selected school is not configured.',

                    'schoolCode' =>
                        $schoolCode,
                ],
                Response::HTTP_FORBIDDEN,
            );
        }
        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code']
                    ?? $schoolCode
                ),
            ),
        );
        if (
            $configuredCode === '' ||
            !hash_equals(
                $configuredCode,
                $schoolCode,
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'The selected school code is invalid.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $connection =
            $school['connection'] ?? null;

        if (
            !is_string($connection) ||
            $connection === ''
        ) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is missing.',

                    'schoolCode' =>
                        $schoolCode,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (!is_array($connectionConfig)) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is not configured.',

                    'schoolCode' =>
                        $schoolCode,

                    'connection' =>
                        $connection,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        config([
            'database.default' =>
                $connection,
        ]);

        DB::setDefaultConnection(
            $connection,
        );

        return DB::connection(
            $connection,
        );
    }
}   