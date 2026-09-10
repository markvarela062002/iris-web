<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return dashboard totals and student records from the database
     * selected during login.
     */
    public function students(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Resolve selected school database
        |--------------------------------------------------------------------------
        */

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

        if (! hash_equals($configuredCode, $schoolCode)) {
            return response()->json([
                'message' => 'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = $school['connection'] ?? null;

        if (! is_string($connection) || $connection === '') {
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

        $db = DB::connection($connection);

        /*
        |--------------------------------------------------------------------------
        | Dashboard summary totals
        |--------------------------------------------------------------------------
        |
        | These match the administrator dashboard totals from the legacy code.
        | They are only calculated when include_totals=1 is requested.
        |
        */

        $includeTotals = $request->boolean(
            'include_totals',
            false,
        );

        $totals = null;

        if ($includeTotals) {
            $totals = $db->selectOne(
                <<<'SQL'
                    SELECT
                        (
                            SELECT COUNT(*)
                            FROM person_activity
                            WHERE for_app = ?
                        ) AS activity_verification_total,

                        (
                            SELECT COUNT(*)
                            FROM file_upload
                            WHERE for_app = ?
                                AND (
                                    sto_validated != ?
                                    OR sto_validated = ?
                                    OR sto_validated IS NULL
                                )
                        ) AS documents_upload_total,

                        (
                            SELECT COUNT(*)
                            FROM person_task AS pt
                            WHERE pt.completed >= DATE_FORMAT(
                                CURDATE(),
                                '%Y-%m-01'
                            )
                            AND pt.completed < DATE_ADD(
                                LAST_DAY(CURDATE()),
                                INTERVAL 1 DAY
                            )
                            AND EXISTS (
                                SELECT 1
                                FROM person_task_file AS ptf
                                WHERE ptf.person_task_id = pt.id
                            )
                            AND EXISTS (
                                SELECT 1
                                FROM person_task_proof AS ptp
                                WHERE ptp.person_task_id = pt.id
                            )
                        ) AS otg_updates_total,

                        (
                            SELECT COUNT(*)
                            FROM person_journal AS pj
                            WHERE pj.date_journal >= DATE_FORMAT(
                                CURDATE(),
                                '%Y-%m-01'
                            )
                            AND pj.date_journal < DATE_ADD(
                                LAST_DAY(CURDATE()),
                                INTERVAL 1 DAY
                            )
                            AND pj.esig_file != ?
                        ) AS daily_journals_total,

                        (
                            SELECT COUNT(*)
                            FROM bs_person_exam
                            WHERE done = ?
                        ) AS theoretical_enrolled_total,

                        (
                            SELECT COUNT(*)
                            FROM bs_person_exam_ext
                            WHERE done = ?
                        ) AS theoretical_not_enrolled_total,

                        (
                            SELECT COUNT(*)
                            FROM person_assess_h
                            WHERE for_assess = ?
                        ) AS practical_enrolled_total,

                        (
                            SELECT COUNT(*)
                            FROM assess_h_ext
                            WHERE for_assess = ?
                        ) AS practical_not_enrolled_total
                    SQL,
                [
                    'Y', // person_activity.for_app
                    'Y', // file_upload.for_app
                    'Y', // file_upload.sto_validated != 'Y'
                    '',  // file_upload.sto_validated = ''
                    '',  // person_journal.esig_file != ''
                    'Y', // bs_person_exam.done
                    'Y', // bs_person_exam_ext.done
                    'Y', // person_assess_h.for_assess
                    'Y', // assess_h_ext.done
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Earliest TRB setup date
        |--------------------------------------------------------------------------
        |
        | Legacy equivalent:
        |
        | SELECT from_date
        | FROM person_trb_setup
        | WHERE person_id = ?
        | ORDER BY from_date ASC
        | LIMIT 1
        |
        */

        $firstSignOnDates = $db
            ->table('person_trb_setup')
            ->select([
                'person_id',
                DB::raw('MIN(from_date) AS first_sign_on'),
            ])
            ->groupBy('person_id');

        /*
        |--------------------------------------------------------------------------
        | Latest TRB setup record
        |--------------------------------------------------------------------------
        |
        | Legacy equivalent:
        |
        | SELECT *
        | FROM person_trb_setup
        | WHERE person_id = ?
        | ORDER BY from_date DESC
        | LIMIT 1
        |
        | The ID provides deterministic ordering when two records have the
        | same from_date.
        |
        */

        $latestSetupRecords = $db
            ->table('person_trb_setup')
            ->select([
                'person_id',
                DB::raw(
                    <<<'SQL'
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(
                                id
                                ORDER BY from_date DESC, id DESC
                            ),
                            ',',
                            1
                        ) AS latest_setup_id
                        SQL,
                ),
            ])
            ->groupBy('person_id');

        /*
        |--------------------------------------------------------------------------
        | Base student query
        |--------------------------------------------------------------------------
        */

        $query = $db
            ->table('person')
            ->leftJoinSub(
                $latestSetupRecords,
                'latest_setup_records',
                'person.id',
                '=',
                'latest_setup_records.person_id',
            )
            ->leftJoin(
                'person_trb_setup as latest_setup',
                function ($join): void {
                    $join->on(
                        'latest_setup.id',
                        '=',
                        'latest_setup_records.latest_setup_id',
                    );
                },
            )
            ->leftJoin(
                'vessel_type',
                'latest_setup.vessel_type_id',
                '=',
                'vessel_type.id',
            )
            ->leftJoinSub(
                $firstSignOnDates,
                'first_sign_on_dates',
                'person.id',
                '=',
                'first_sign_on_dates.person_id',
            )
            ->select([
                'person.id',
                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
                'person.batch_no',
                'latest_setup.ship_company as company',
                'vessel_type.desc_vessel_type as vessel_type',
                'first_sign_on_dates.first_sign_on',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Legacy student-list filter
        |--------------------------------------------------------------------------
        |
        | 1 = Students on board
        |     The student has at least one person_trb_setup record.
        |
        | 2 = All students
        |     No person_trb_setup condition is applied.
        |
        */

        $listType = $request->integer('list_type', 1);

        if ($listType === 1) {
            $query->whereExists(
                function ($subquery): void {
                    $subquery
                        ->selectRaw('1')
                        ->from(
                            'person_trb_setup as onboard_setup',
                        )
                        ->whereColumn(
                            'onboard_setup.person_id',
                            'person.id',
                        );
                },
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search, sort, and paginate
        |--------------------------------------------------------------------------
        */

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
                'person.batch_no',
                'latest_setup.ship_company',
                'vessel_type.desc_vessel_type',
            ],
            sortableColumns: [
                'code_person' => 'person.code_person',
                'school_id_no' => 'person.school_id_no',
                'fname' => 'person.fname',
                'mname' => 'person.mname',
                'lname' => 'person.lname',
                'gender' => 'person.gender',
                'dept' => 'person.dept',
                'batch_no' => 'person.batch_no',
                'company' => 'latest_setup.ship_company',
                'vessel_type' => 'vessel_type.desc_vessel_type',
                'first_sign_on' => 'first_sign_on_dates.first_sign_on',
            ],
            defaultSortColumn: 'lname',
            defaultSortDirection: 'asc',
        );

        /*
        |--------------------------------------------------------------------------
        | Get IDs from the current page
        |--------------------------------------------------------------------------
        */

        $personIds = Collection::make($result['data'])
            ->pluck('id')
            ->filter(
                static fn ($id): bool => $id !== null && $id !== '',
            )
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Current-page student totals
        |--------------------------------------------------------------------------
        |
        | These calculations use only the students displayed on the current
        | page instead of aggregating the complete database.
        |
        */

        if ($personIds !== []) {
            /*
             * Legacy equivalent:
             *
             * SELECT COUNT(*)
             * FROM person_activity
             * WHERE person_id = ?
             */
            $activityCounts = $db
                ->table('person_activity')
                ->select([
                    'person_id',
                    DB::raw('COUNT(*) AS total'),
                ])
                ->whereIn('person_id', $personIds)
                ->groupBy('person_id')
                ->pluck('total', 'person_id');

            /*
             * Legacy equivalent:
             *
             * SELECT COUNT(*)
             * FROM file_upload
             * WHERE owner_id = ?
             */
            $fileUploadCounts = $db
                ->table('file_upload')
                ->select([
                    'owner_id',
                    DB::raw('COUNT(*) AS total'),
                ])
                ->whereIn('owner_id', $personIds)
                ->groupBy('owner_id')
                ->pluck('total', 'owner_id');

            /*
             * Legacy equivalent:
             *
             * SELECT COUNT(*)
             * FROM person_journal
             * WHERE person_id = ?
             */
            $dailyJournalCounts = $db
                ->table('person_journal')
                ->select([
                    'person_id',
                    DB::raw('COUNT(*) AS total'),
                ])
                ->whereIn('person_id', $personIds)
                ->groupBy('person_id')
                ->pluck('total', 'person_id');

            /*
            |--------------------------------------------------------------------------
            | Total and completed OTG tasks
            |--------------------------------------------------------------------------
            |
            | Total tasks:
            | - Belong to the student.
            | - not_app is not Y.
            | - Task belongs to a TRB type assigned to the student.
            |
            | Completed tasks:
            | - All conditions above.
            | - completed is not NULL.
            | - completed is not 1970-01-01.
            | - Has at least one file.
            | - Has at least one proof.
            |
            */

            $taskCounts = $db
                ->table('person_task as counted_task')
                ->join(
                    'task as task_definition',
                    'counted_task.task_id',
                    '=',
                    'task_definition.id',
                )
                ->join(
                    'trb_competence as competence',
                    'task_definition.trb_competence_id',
                    '=',
                    'competence.id',
                )
                ->join(
                    'trb_function',
                    'competence.trb_function_id',
                    '=',
                    'trb_function.id',
                )
                ->join(
                    'person_trb_book as trb_book',
                    function ($join): void {
                        $join
                            ->on(
                                'trb_book.person_id',
                                '=',
                                'counted_task.person_id',
                            )
                            ->on(
                                'trb_book.trb_type_id',
                                '=',
                                'trb_function.trb_type_id',
                            );
                    },
                )
                ->whereIn(
                    'counted_task.person_id',
                    $personIds,
                )
                ->select([
                    'counted_task.person_id',
                    DB::raw(
                        <<<'SQL'
                            COUNT(
                                DISTINCT CASE
                                    WHEN counted_task.not_app != 'Y'
                                    THEN counted_task.id
                                    ELSE NULL
                                END
                            ) AS total_task
                            SQL,
                    ),
                    DB::raw(
                        <<<'SQL'
                            COUNT(
                                DISTINCT CASE
                                    WHEN counted_task.completed IS NOT NULL
                                        AND counted_task.completed != '1970-01-01'
                                        AND counted_task.not_app != 'Y'
                                        AND EXISTS (
                                            SELECT 1
                                            FROM person_task_file
                                            WHERE person_task_file.person_task_id =
                                                counted_task.id
                                        )
                                        AND EXISTS (
                                            SELECT 1
                                            FROM person_task_proof
                                            WHERE person_task_proof.person_task_id =
                                                counted_task.id
                                        )
                                    THEN counted_task.id
                                    ELSE NULL
                                END
                            ) AS task_completed
                            SQL,
                    ),
                ])
                ->groupBy('counted_task.person_id')
                ->get()
                ->keyBy('person_id');

            /*
            |--------------------------------------------------------------------------
            | Attach totals to each student
            |--------------------------------------------------------------------------
            */

            $result['data'] = Collection::make(
                $result['data'],
            )
                ->map(
                    function (object $row) use (
                        $activityCounts,
                        $fileUploadCounts,
                        $dailyJournalCounts,
                        $taskCounts,
                    ): object {
                        $personId = $row->id;

                        $totalTask = (int) (
                            $taskCounts[$personId]->total_task ?? 0
                        );

                        $taskCompleted = (int) (
                            $taskCounts[$personId]->task_completed ?? 0
                        );

                        $row->total_activities = (int) (
                            $activityCounts[$personId] ?? 0
                        );

                        $row->total_file_upload = (int) (
                            $fileUploadCounts[$personId] ?? 0
                        );

                        $row->total_daily_journal = (int) (
                            $dailyJournalCounts[$personId] ?? 0
                        );

                        $row->total_task = $totalTask;
                        $row->task_completed = $taskCompleted;

                        $row->task_percentage = $totalTask === 0
                            ? 0
                            : round(
                                ($taskCompleted / $totalTask) * 100,
                                1,
                            );

                        return $row;
                    },
                )
                ->all();
        }

        /*
        |--------------------------------------------------------------------------
        | Add sequential row numbers
        |--------------------------------------------------------------------------
        */

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        /*
        |--------------------------------------------------------------------------
        | Add dashboard totals when requested
        |--------------------------------------------------------------------------
        */

        if ($totals !== null) {
            $result['activity_verification_total'] = (int) (
                $totals->activity_verification_total
            );

            $result['documents_upload_total'] = (int) (
                $totals->documents_upload_total
            );

            $result['otg_updates_total'] = (int) (
                $totals->otg_updates_total
            );

            $result['daily_journals_total'] = (int) (
                $totals->daily_journals_total
            );

            $result['theoretical_enrolled_total'] = (int) (
                $totals->theoretical_enrolled_total
            );

            $result['theoretical_not_enrolled_total'] = (int) (
                $totals->theoretical_not_enrolled_total
            );

            $result['practical_enrolled_total'] = (int) (
                $totals->practical_enrolled_total
            );

            $result['practical_not_enrolled_total'] = (int) (
                $totals->practical_not_enrolled_total
            );
        }

        return response()->json($result);
    }
}