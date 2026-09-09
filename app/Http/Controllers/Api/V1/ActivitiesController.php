<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

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
            ])
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
         * This matches the legacy application behavior.
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
                     * Remove any accidental directory
                     * components stored in the database.
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

    /**
     * Resolve the school database selected during login.
     */
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
                        'No school database has been selected.',
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