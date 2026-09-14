<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends Controller
{
    /**
     * Return Monitoring Report filter options.
     */
    public function options(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
         * Legacy CCI years came from BATCHES.
         *
         * In the new application we use the actual
         * batch_no values available in the selected
         * school database.
         * Legacy IRIS-SAM BATCHES:
         * 2020 through 2032.
        */
        $years = range(
            2020,
            2032,
        );
        return response()->json([
            'reports' => [
                [
                    'value' => 1,
                    'label' =>
                        'Percent Deployment',
                ],
                [
                    'value' => 2,
                    'label' =>
                        'Percent Deployment within 18 Months',
                ],
                [
                    'value' => 3,
                    'label' =>
                        'Activities Summary',
                ],
                [
                    'value' => 4,
                    'label' =>
                        'Wastage Distribution Summary',
                ],
                [
                    'value' => 5,
                    'label' =>
                        'Wastages and CCI',
                ],
            ],

            'years' => $years,

            'departments' => [
                'DECK',
                'ENGINE',
            ],

            'genders' => [
                'MALE',
                'FEMALE',
            ],
        ]);
    }

    /**
     * Return the selected Monitoring Report.
     */
    public function index(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'report_type' => [
                'required',
                'integer',
                Rule::in(
                    range(1, 5),
                ),
            ],

            'batch_no' => [
                'nullable',
                'string',
                'max:50',
            ],

            'dept' => [
                'nullable',
                'string',
                Rule::in([
                    'DECK',
                    'ENGINE',
                ]),
            ],

            'gender' => [
                'nullable',
                'string',
                Rule::in([
                    'MALE',
                    'FEMALE',
                ]),
            ],
        ]);

        $db =
            $this->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $reportType = (int)
            $validated['report_type'];

        $batchNo = trim(
            (string) (
                $validated['batch_no'] ??
                ''
            ),
        );

        $department = trim(
            (string) (
                $validated['dept'] ??
                ''
            ),
        );

        $gender = trim(
            (string) (
                $validated['gender'] ??
                ''
            ),
        );

        return match ($reportType) {
            1 => $this->percentDeployment(
                db: $db,
                batchNo: $batchNo,
                department: $department,
                gender: $gender,
                within18Months: false,
            ),

            2 => $this->percentDeployment(
                db: $db,
                batchNo: $batchNo,
                department: $department,
                gender: $gender,
                within18Months: true,
            ),

            3 => $this->activitiesSummary(
                $db,
            ),

            4 =>
                $this->wastageDistribution(
                    $db,
                ),

            5 => $this->wastageAndCci(
                $db,
            ),
        };
    }

    /**
     * Percent Deployment reports.
     */
    private function percentDeployment(
        ConnectionInterface $db,
        string $batchNo,
        string $department,
        string $gender,
        bool $within18Months,
    ): JsonResponse {
        /*
         * Total registered cadets.
         */
        $registeredQuery =
            $db->table('person');

        $this->applyPersonFilters(
            query: $registeredQuery,
            batchNo: $batchNo,
            department: $department,
            gender: $gender,
        );

        $registered =
            $registeredQuery->count(
                'person.id',
            );

        /*
         * Completed Classroom Instruction.
         */
        $cciQuery = $db
            ->table('person_activity')
            ->join(
                'person',
                'person.id',
                '=',
                'person_activity.person_id',
            )
            ->join(
                'activity',
                'activity.id',
                '=',
                'person_activity.activity_id',
            )
            ->where(
                'activity.code_activity',
                '=',
                'CCI',
            );

        $this->applyPersonFilters(
            query: $cciQuery,
            batchNo: $batchNo,
            department: $department,
            gender: $gender,
        );

        $cci = $cciQuery->count(
            'person_activity.person_id',
        );

        /*
         * Wastage.
         *
         * Legacy WASTAGE activity:
         * seq_no = 99.
         */
        $wastageQuery = $db
            ->table('person_activity')
            ->join(
                'person',
                'person.id',
                '=',
                'person_activity.person_id',
            )
            ->join(
                'activity',
                'activity.id',
                '=',
                'person_activity.activity_id',
            )
            ->where(
                'activity.seq_no',
                '=',
                99,
            );

        $this->applyPersonFilters(
            query: $wastageQuery,
            batchNo: $batchNo,
            department: $department,
            gender: $gender,
        );

        $wastage =
            $wastageQuery->count(
                'person_activity.person_id',
            );

        /*
         * Deployed cadets.
         */
        $deployedQuery = $db
            ->table('person_activity')
            ->join(
                'person',
                'person.id',
                '=',
                'person_activity.person_id',
            )
            ->join(
                'activity',
                'activity.id',
                '=',
                'person_activity.activity_id',
            )
            ->where(
                'activity.code_activity',
                '=',
                'SignOn',
            );

        $this->applyPersonFilters(
            query: $deployedQuery,
            batchNo: $batchNo,
            department: $department,
            gender: $gender,
        );

        /*
         * Preserve the legacy
         * rep_pct_deployment_18.php rule:
         *
         * DATEDIFF(CURDATE(), start_date)
         * <= 540 days.
         */
        if ($within18Months) {
            $deployedQuery->whereRaw(
                'DATEDIFF(CURDATE(), person_activity.start_date) <= 540',
            );
        }

        $deployed =
            $deployedQuery->count(
                'person_activity.person_id',
            );

        $eligible =
            $cci - $wastage;

        $percentage =
            $eligible > 0
                ? (
                    $deployed /
                    $eligible
                ) * 100
                : 0;

        return response()->json([
            'report' => [
                'type' =>
                    $within18Months
                        ? 2
                        : 1,

                'title' =>
                    $within18Months
                        ? 'Percent Deployment within 18 Months'
                        : 'Percent Deployment',
            ],

            'columns' => [
                [
                    'field' =>
                        'registered',
                    'header' =>
                        'Total Registered for CCI Year',
                ],
                [
                    'field' => 'cci',
                    'header' =>
                        'Completed Classroom Instruction',
                ],
                [
                    'field' =>
                        'wastage',
                    'header' =>
                        'Wastage (Deceased/Disabled/Sick/Land Based)',
                ],
                [
                    'field' =>
                        'deployed',
                    'header' =>
                        'Deployed',
                ],
                [
                    'field' =>
                        'percentage',
                    'header' =>
                        'Percent Deployment',
                ],
            ],

            'data' => [
                [
                    'registered' =>
                        $registered,

                    'cci' => $cci,

                    'wastage' =>
                        $wastage,

                    'deployed' =>
                        $deployed,

                    'percentage' =>
                        number_format(
                            $percentage,
                            2,
                        ).'%',
                ],
            ],
        ]);
    }

    /**
     * Activities Summary.
     */
    private function activitiesSummary(
        ConnectionInterface $db,
    ): JsonResponse {
        $activities = $db
            ->table('activity')
            ->orderBy('seq_no')
            ->get([
                'id',
                'desc_activity',
            ]);

        $columns = [];
        $row = [];

        foreach (
            $activities as $index =>
                $activity
        ) {
            $field =
                'activity_'.$index;

            $columns[] = [
                'field' => $field,
                'header' =>
                    $activity
                        ->desc_activity,
            ];

            $row[$field] = $db
                ->table(
                    'person_activity',
                )
                ->where(
                    'activity_id',
                    '=',
                    $activity->id,
                )
                ->count('id');
        }

        return response()->json([
            'report' => [
                'type' => 3,
                'title' =>
                    'Activities Summary',
            ],

            'columns' => $columns,

            'data' => [$row],
        ]);
    }

    /**
     * Wastage Distribution Summary.
     */
    private function wastageDistribution(
        ConnectionInterface $db,
    ): JsonResponse {
        $activities = $db
            ->table('activity')
            ->where(
                'seq_no',
                '=',
                99,
            )
            ->orderBy(
                'desc_activity',
            )
            ->get([
                'id',
                'desc_activity',
            ]);

        $columns = [];
        $row = [];

        foreach (
            $activities as $index =>
                $activity
        ) {
            $field =
                'wastage_'.$index;

            $columns[] = [
                'field' => $field,
                'header' =>
                    $activity
                        ->desc_activity,
            ];

            $row[$field] = $db
                ->table(
                    'person_activity',
                )
                ->where(
                    'activity_id',
                    '=',
                    $activity->id,
                )
                ->count('id');
        }

        return response()->json([
            'report' => [
                'type' => 4,
                'title' =>
                    'Wastage Distribution Summary',
            ],

            'columns' => $columns,

            'data' => [$row],
        ]);
    }

    /**
     * Wastages and CCI.
     */
    private function wastageAndCci(
        ConnectionInterface $db,
    ): JsonResponse {
        /*
         * Legacy "Wastage Before CCI":
         *
         * Has wastage record but no CCI record.
         */
        $beforeCci = $db
            ->table('person')
            ->whereExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_activity',
                        )
                        ->join(
                            'activity',
                            'activity.id',
                            '=',
                            'person_activity.activity_id',
                        )
                        ->whereColumn(
                            'person_activity.person_id',
                            'person.id',
                        )
                        ->where(
                            'activity.seq_no',
                            '=',
                            99,
                        );
                },
            )
            ->whereNotExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_activity',
                        )
                        ->join(
                            'activity',
                            'activity.id',
                            '=',
                            'person_activity.activity_id',
                        )
                        ->whereColumn(
                            'person_activity.person_id',
                            'person.id',
                        )
                        ->where(
                            'activity.code_activity',
                            '=',
                            'CCI',
                        );
                },
            )
            ->count();

        /*
         * Legacy "Wastage After CCI":
         *
         * Has both a wastage and a CCI record.
         */
        $afterCci = $db
            ->table('person')
            ->whereExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_activity',
                        )
                        ->join(
                            'activity',
                            'activity.id',
                            '=',
                            'person_activity.activity_id',
                        )
                        ->whereColumn(
                            'person_activity.person_id',
                            'person.id',
                        )
                        ->where(
                            'activity.seq_no',
                            '=',
                            99,
                        );
                },
            )
            ->whereExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_activity',
                        )
                        ->join(
                            'activity',
                            'activity.id',
                            '=',
                            'person_activity.activity_id',
                        )
                        ->whereColumn(
                            'person_activity.person_id',
                            'person.id',
                        )
                        ->where(
                            'activity.code_activity',
                            '=',
                            'CCI',
                        );
                },
            )
            ->count();

        return response()->json([
            'report' => [
                'type' => 5,
                'title' =>
                    'Wastages and CCI',
            ],

            'columns' => [
                [
                    'field' =>
                        'before_cci',
                    'header' =>
                        'Wastage Before CCI',
                ],
                [
                    'field' =>
                        'after_cci',
                    'header' =>
                        'Wastage After CCI',
                ],
            ],

            'data' => [
                [
                    'before_cci' =>
                        $beforeCci,

                    'after_cci' =>
                        $afterCci,
                ],
            ],
        ]);
    }

    /**
     * Apply common person filters.
     */
    private function applyPersonFilters(
        $query,
        string $batchNo,
        string $department,
        string $gender,
    ): void {
        if ($batchNo !== '') {
            $query->where(
                'person.batch_no',
                '=',
                $batchNo,
            );
        }

        if ($department !== '') {
            $query->where(
                'person.dept',
                '=',
                $department,
            );
        }

        if ($gender !== '') {
            $query->where(
                'person.gender',
                '=',
                $gender,
            );
        }
    }

    /**
     * Resolve the database selected during login.
     */
    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get(
                        'school_code',
                        '',
                    ),
            ),
        );

        if ($schoolCode === '') {
            return response()->json([
                'message' =>
                    'No school database has been selected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $schools = config(
            'schools.schools',
            [],
        );

        if (! is_array($schools)) {
            return response()->json([
                'message' =>
                    'School configuration is unavailable.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $school =
            $schools[$schoolCode] ??
            null;

        if (! is_array($school)) {
            return response()->json([
                'message' =>
                    'The selected school is not configured.',
                'schoolCode' =>
                    $schoolCode,
            ], Response::HTTP_FORBIDDEN);
        }

        $connection =
            $school['connection'] ??
            null;

        if (
            ! is_string($connection) ||
            $connection === ''
        ) {
            return response()->json([
                'message' =>
                    'The school database connection is missing.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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