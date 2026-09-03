<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /** Return students from the database selected during login. */
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

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (! is_array($connectionConfig)) {
            return response()->json([
                'message' =>
                    'The school database connection is not configured.',
                'schoolCode' => $schoolCode,
                'connection' => $connection,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        /*
         * Count each student's activities.
         */
        $activityCounts = DB::connection($connection)
            ->table('person_activity')
            ->select([
                'person_id',
                DB::raw('COUNT(*) as total_activities'),
            ])
            ->groupBy('person_id');

        /*
         * Count each student's uploaded files.
         */
        $fileUploadCounts = DB::connection($connection)
            ->table('file_upload')
            ->select([
                'owner_id',
                DB::raw('COUNT(*) as total_file_upload'),
            ])
            ->groupBy('owner_id');

        /*
         * Count each student's daily journal entries.
         */
        $dailyJournalCounts = DB::connection($connection)
            ->table('person_journal')
            ->select([
                'person_id',
                DB::raw('COUNT(*) as total_daily_journal'),
            ])
            ->groupBy('person_id');

        /*
         * Count each student's total and completed tasks.
         *
         * A task is completed when:
         * - completed is not null
         * - not_app is N
         */
        $taskCounts = DB::connection($connection)
            ->table('person_task')
            ->select([
                'person_id',
                DB::raw('COUNT(*) as total_task'),
                DB::raw(
                    "SUM(
                        CASE
                            WHEN completed IS NOT NULL
                                AND not_app = 'N'
                            THEN 1
                            ELSE 0
                        END
                    ) as task_completed",
                ),
            ])
            ->groupBy('person_id');

        /*
         * Get students together with setup information and totals.
         */
        $query = DB::connection($connection)
            ->table('person')
            ->leftJoin(
                'person_trb_setup',
                'person.id',
                '=',
                'person_trb_setup.person_id',
            )
            ->leftJoin(
                'vessel_type',
                'person_trb_setup.vessel_type_id',
                '=',
                'vessel_type.id',
            )
            ->leftJoinSub(
                $activityCounts,
                'activity_counts',
                'person.id',
                '=',
                'activity_counts.person_id',
            )
            ->leftJoinSub(
                $fileUploadCounts,
                'file_upload_counts',
                'person.id',
                '=',
                'file_upload_counts.owner_id',
            )
            ->leftJoinSub(
                $dailyJournalCounts,
                'daily_journal_counts',
                'person.id',
                '=',
                'daily_journal_counts.person_id',
            )
            ->leftJoinSub(
                $taskCounts,
                'task_counts',
                'person.id',
                '=',
                'task_counts.person_id',
            )
            ->select([
                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
                'person.batch_no',
                'person.last_update',
                'person_trb_setup.ship_company as company',
                'vessel_type.desc_vessel_type as vessel_type',

                DB::raw(
                    'COALESCE(
                        activity_counts.total_activities,
                        0
                    ) as total_activities',
                ),

                DB::raw(
                    'COALESCE(
                        file_upload_counts.total_file_upload,
                        0
                    ) as total_file_upload',
                ),

                DB::raw(
                    'COALESCE(
                        daily_journal_counts.total_daily_journal,
                        0
                    ) as total_daily_journal',
                ),

                DB::raw(
                    'COALESCE(
                        task_counts.total_task,
                        0
                    ) as total_task',
                ),

                DB::raw(
                    'COALESCE(
                        task_counts.task_completed,
                        0
                    ) as task_completed',
                ),

                DB::raw(
                    'CASE
                        WHEN COALESCE(task_counts.total_task, 0) = 0
                        THEN 0
                        ELSE ROUND(
                            (
                                COALESCE(
                                    task_counts.task_completed,
                                    0
                                ) / task_counts.total_task
                            ) * 100,
                            2
                        )
                    END as task_percentage',
                ),
            ])
            ->where('person.active', 'Y')
            ->whereNotNull('person.last_update');

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
                'person_trb_setup.ship_company',
                'vessel_type.desc_vessel_type',
            ],
            sortableColumns: [
                'last_update' => 'person.last_update',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        return response()->json($result);
    }
}