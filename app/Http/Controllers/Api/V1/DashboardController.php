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
     * Return dashboard totals and student records from the
     * database selected during login.
     */
    public function students(Request $request): JsonResponse
    {
        $schoolCode = strtoupper(trim(
            (string) $request->session()->get('school_code', ''),
        ));

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

        $configuredCode = strtoupper(trim(
            (string) ($school['code'] ?? $schoolCode),
        ));

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

        $connectionConfig = config("database.connections.{$connection}");

        if (! is_array($connectionConfig)) {
            return response()->json([
                'message' => 'The school database connection is not configured.',
                'schoolCode' => $schoolCode,
                'connection' => $connection,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);

        $db = DB::connection($connection);

        /*
        |--------------------------------------------------------------------------
        | Dashboard totals — one round trip instead of eight, and only run
        | when explicitly requested.
        |--------------------------------------------------------------------------
        |
        | The frontend datatable re-requests this endpoint on every search
        | keystroke (debounced), sort change, page change, and list-type
        | filter change. None of those actions change these totals, so
        | recomputing all 8 aggregate counts on every one of those requests
        | is wasted DB work. The frontend now only passes include_totals=1
        | on the initial page load; every other interaction omits it and
        | this whole block is skipped.
        */
        $includeTotals = $request->boolean('include_totals', false);

        $totals = null;

        if ($includeTotals) {
            $totals = $db->selectOne('
                SELECT
                    (SELECT COUNT(*) FROM person_activity WHERE for_app = ?) AS activity_verification_total,
                    (SELECT COUNT(*) FROM file_upload WHERE for_app = ?) AS documents_upload_total,
                    (
                        SELECT COUNT(*) FROM person_task pt
                        WHERE pt.completed >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')
                            AND pt.completed < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
                            AND pt.month_no != \'\'
                            AND pt.not_app != \'Y\'
                            AND EXISTS (
                                SELECT 1 FROM person_task_file ptf
                                WHERE ptf.person_task_id = pt.id
                            )
                            AND EXISTS (
                                SELECT 1 FROM person_task_proof ptp
                                WHERE ptp.person_task_id = pt.id
                            )
                    ) AS otg_updates_total,
                    (
                        SELECT COUNT(*) FROM person_journal pj
                        WHERE pj.date_journal >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')
                            AND pj.date_journal < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
                            AND pj.esig_file != ?
                    ) AS daily_journals_total,
                    (SELECT COUNT(*) FROM bs_person_exam WHERE done = ?) AS theoretical_enrolled_total,
                    (SELECT COUNT(*) FROM bs_person_exam_ext WHERE done = ?) AS theoretical_not_enrolled_total,
                    (SELECT COUNT(*) FROM person_assess_h WHERE done = ?) AS practical_enrolled_total,
                    (SELECT COUNT(*) FROM assess_h_ext WHERE done = ?) AS practical_not_enrolled_total
            ', ['Y', 'Y', '', 'Y', 'Y', 'Y', 'Y']);
        }

        /*
        |--------------------------------------------------------------------------
        | Step 1 — paginate the base student query.
        |--------------------------------------------------------------------------
        |
        | Only joins needed for filtering, searching, and sorting the page are
        | included here. first_sign_on and latest_setup still need to be
        | joined pre-pagination because first_sign_on is a sortable column
        | and latest_setup.ship_company / vessel_type are searchable/displayed.
        | The per-student activity/file/journal/task aggregate counts are
        | deliberately NOT joined here — see Step 2.
        */
        $firstSignOnDates = $db->table('person_trb_setup')
            ->select([
                'person_id',
                DB::raw('MIN(from_date) as first_sign_on'),
            ])
            ->groupBy('person_id');

        /*
         * Select exactly one latest setup row per student. The ID is used as
         * a deterministic tie-breaker when two assignments have the same
         * from_date, preventing duplicate students in the datatable.
         */
        $latestSetupRecords = $db->table('person_trb_setup')
            ->select([
                'person_id',
                DB::raw(
                    "SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            id
                            ORDER BY from_date DESC, id DESC
                        ),
                        ',',
                        1
                    ) as latest_setup_id",
                ),
            ])
            ->groupBy('person_id');

        $query = $db->table('person')
            ->leftJoinSub(
                $latestSetupRecords,
                'latest_setup_records',
                'person.id',
                '=',
                'latest_setup_records.person_id',
            )
            ->leftJoin(
                'person_trb_setup as latest_setup',
                function ($join) {
                    $join
                        ->on(
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
         * Legacy list filter:
         *
         * 1 = Students on board
         *     Student has at least one person_trb_setup record.
         *
         * 2 = All students
         */
        $listType = $request->integer('list_type', 1);

        if ($listType === 1) {
            $query->whereExists(function ($subquery) {
                $subquery
                    ->selectRaw('1')
                    ->from('person_trb_setup as onboard_setup')
                    ->whereColumn('onboard_setup.person_id', 'person.id');
            });
        }

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
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
                'lname' => 'person.lname',
                'first_sign_on' => 'first_sign_on_dates.first_sign_on',
            ],
            defaultSortColumn: 'lname',
            defaultSortDirection: 'asc',
        );

        /*
        |--------------------------------------------------------------------------
        | Step 2 — aggregate counts, scoped to just this page's person ids.
        |--------------------------------------------------------------------------
        |
        | Instead of GROUP BY-ing entire tables on every request, these run
        | WHERE person_id IN (...the ~10-100 ids on this page...), which can
        | use an index on person_id and returns almost instantly regardless
        | of how large the underlying tables grow.
        */
        $personIds = Collection::make($result['data'])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($personIds !== []) {
            $activityCounts = $db->table('person_activity')
                ->select(['person_id', DB::raw('COUNT(*) as total')])
                ->whereIn('person_id', $personIds)
                ->groupBy('person_id')
                ->pluck('total', 'person_id');

            $fileUploadCounts = $db->table('file_upload')
                ->select(['owner_id', DB::raw('COUNT(*) as total')])
                ->whereIn('owner_id', $personIds)
                ->groupBy('owner_id')
                ->pluck('total', 'owner_id');

            $dailyJournalCounts = $db->table('person_journal')
                ->select(['person_id', DB::raw('COUNT(*) as total')])
                ->whereIn('person_id', $personIds)
                ->groupBy('person_id')
                ->pluck('total', 'person_id');

            $taskCounts = $db->table('person_task as counted_task')
                ->join('task as task_definition', 'counted_task.task_id', '=', 'task_definition.id')
                ->join('trb_competence as competence', 'task_definition.trb_competence_id', '=', 'competence.id')
                ->join('trb_function as trb_function', 'competence.trb_function_id', '=', 'trb_function.id')
                ->join('person_trb_book as trb_book', function ($join) {
                    $join
                        ->on('trb_book.person_id', '=', 'counted_task.person_id')
                        ->on('trb_book.trb_type_id', '=', 'trb_function.trb_type_id');
                })
                ->whereIn('counted_task.person_id', $personIds)
                ->select([
                    'counted_task.person_id',
                    DB::raw("
                        COUNT(DISTINCT CASE
                            WHEN counted_task.not_app != 'Y' THEN counted_task.id
                            ELSE NULL
                        END) as total_task
                    "),
                    DB::raw("
                        COUNT(DISTINCT CASE
                            WHEN counted_task.completed IS NOT NULL
                                AND counted_task.completed != '1970-01-01'
                                AND counted_task.not_app != 'Y'
                                AND EXISTS (
                                    SELECT 1 FROM person_task_file
                                    WHERE person_task_file.person_task_id = counted_task.id
                                )
                                AND EXISTS (
                                    SELECT 1 FROM person_task_proof
                                    WHERE person_task_proof.person_task_id = counted_task.id
                                )
                            THEN counted_task.id
                            ELSE NULL
                        END) as task_completed
                    "),
                ])
                ->groupBy('counted_task.person_id')
                ->get()
                ->keyBy('person_id');

            $result['data'] = Collection::make($result['data'])
                ->map(function (object $row) use (
                    $activityCounts,
                    $fileUploadCounts,
                    $dailyJournalCounts,
                    $taskCounts,
                ): object {
                    $personId = $row->id;
                    $totalTask = (int) ($taskCounts[$personId]->total_task ?? 0);
                    $taskCompleted = (int) ($taskCounts[$personId]->task_completed ?? 0);

                    $row->total_activities = (int) ($activityCounts[$personId] ?? 0);
                    $row->total_file_upload = (int) ($fileUploadCounts[$personId] ?? 0);
                    $row->total_daily_journal = (int) ($dailyJournalCounts[$personId] ?? 0);
                    $row->total_task = $totalTask;
                    $row->task_completed = $taskCompleted;
                    $row->task_percentage = $totalTask === 0
                        ? 0
                        : round(($taskCompleted / $totalTask) * 100, 1);

                    return $row;
                })
                ->all();
        }

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        /*
         * Only include totals in the response when they were actually
         * computed. Callers that omit include_totals (every request after
         * the initial page load) get a response with no totals keys —
         * the frontend preserves its previously-fetched totals in that case.
         */
        if ($totals !== null) {
            $result['activity_verification_total'] = (int) $totals->activity_verification_total;
            $result['documents_upload_total'] = (int) $totals->documents_upload_total;
            $result['otg_updates_total'] = (int) $totals->otg_updates_total;
            $result['daily_journals_total'] = (int) $totals->daily_journals_total;
            $result['theoretical_enrolled_total'] = (int) $totals->theoretical_enrolled_total;
            $result['theoretical_not_enrolled_total'] = (int) $totals->theoretical_not_enrolled_total;
            $result['practical_enrolled_total'] = (int) $totals->practical_enrolled_total;
            $result['practical_not_enrolled_total'] = (int) $totals->practical_not_enrolled_total;
        }

        return response()->json($result);
    }
}
