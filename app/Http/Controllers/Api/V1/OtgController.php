<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\Models\Student;

class OtgController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return OTG task records.
     *
     * Dashboard:
     * - completed tasks for the selected month and year
     *
     * Monitoring:
     * - recent completed OTG submissions
     */
    public function index(
        Request $request,
    ): JsonResponse {
        $isMonitoring =
            $request->boolean('monitoring');

        $validated = $request->validate([
            'month' => [
                'nullable',
                'integer',
                Rule::in(range(1, 12)),
            ],
            'year' => [
                'nullable',
                'integer',
                'min:2023',
                'max:2100',
            ],
        ]);

        $month = (int) (
            $validated['month'] ??
            now()->month
        );

        $year = (int) (
            $validated['year'] ??
            now()->year
        );

        $fromDate = CarbonImmutable::create(
            year: $year,
            month: $month,
            day: 1,
        )->startOfDay();

        $nextMonth =
            $fromDate->addMonth();

        $toDate =
            $nextMonth->subDay();

        $db =
            $this->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
        * Base OTG query shared by Dashboard
        * and Monitoring.
        *
        * A completed OTG task must have:
        * - completion date
        * - month onboard
        * - Objective Evidence
        * - Proof of Assessment
        * - not marked N/A
        */
        $query = $db
            ->table('person_task')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'person_task.person_id',
            )
            ->leftJoin(
                'task',
                'task.id',
                '=',
                'person_task.task_id',
            )
            ->whereNotNull(
                'person_task.completed',
            )
            ->where(
                'person_task.month_no',
                '!=',
                '',
            )
            ->where(
                'person_task.not_app',
                '!=',
                'Y',
            )
            ->whereExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_task_file',
                        )
                        ->whereColumn(
                            'person_task_file.person_task_id',
                            'person_task.id',
                        );
                },
            )
            ->whereExists(
                function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_task_proof',
                        )
                        ->whereColumn(
                            'person_task_proof.person_task_id',
                            'person_task.id',
                        );
                },
            )
            ->select([
                'person_task.id',
                'person_task.person_id',
                'person_task.task_id',
                'person_task.completed',
                'person_task.month_no',
                'person_task.not_app',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',

                'task.ref_no',
                'task.desc_task',
            ]);

        /*
        * Dashboard only:
        * limit records to the selected month/year.
        *
        * Monitoring skips this so the STO can see
        * recent OTG submissions across all dates.
        */
        if (! $isMonitoring) {
            $query
                ->where(
                    'person_task.completed',
                    '>=',
                    $fromDate->format(
                        'Y-m-d H:i:s',
                    ),
                )
                ->where(
                    'person_task.completed',
                    '<',
                    $nextMonth->format(
                        'Y-m-d H:i:s',
                    ),
                );
        }

        $result =
            $this->datatableService->paginate(
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
                    'task.ref_no',
                    'task.desc_task',
                    'person_task.completed',
                ],

                sortableColumns: [
                    'completed' =>
                        'person_task.completed',

                    'code_person' =>
                        'person.code_person',

                    'school_id_no' =>
                        'person.school_id_no',

                    'fname' =>
                        'person.fname',

                    'lname' =>
                        'person.lname',

                    'dept' =>
                        'person.dept',

                    'ref_no' =>
                        'task.ref_no',

                    'desc_task' =>
                        'task.desc_task',

                    'month_no' =>
                        'person_task.month_no',
                ],

                defaultSortColumn:
                    'completed',

                defaultSortDirection:
                    $isMonitoring
                        ? 'desc'
                        : 'asc',
            );

        $result =
            $this->datatableService
                ->addRowNumbers(
                    response: $result,
                    key: 'index',
                );

        $result['filters'] = [
            'month' => $month,
            'year' => $year,
            'fromDate' =>
                $fromDate->format(
                    'Y-m-d',
                ),
            'toDate' =>
                $toDate->format(
                    'Y-m-d',
                ),
        ];

        return response()->json(
            $result,
        );
    }

    /**
 * Return the authenticated student's OTG summary.
 */
public function studentDashboard(
    Request $request,
): JsonResponse {
    $account = $request->user();

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
     * Count all applicable OTG tasks assigned to the
     * authenticated student.
     */
    $totalTasks = $db
        ->table('person_task')
        ->where(
            'person_id',
            $studentId,
        )
        ->whereRaw(
            "UPPER(TRIM(COALESCE(not_app, ''))) != 'Y'",
        )
        ->count();

    /*
     * Count completed OTG tasks.
     *
     * A completed OTG task must contain:
     * - a completion date
     * - a month onboard
     * - objective evidence
     * - proof of assessment
     * - not be marked N/A
     */
    $completedTasks = $db
        ->table('person_task')
        ->where(
            'person_id',
            $studentId,
        )
        ->whereNotNull(
            'completed',
        )
        ->whereRaw(
            "TRIM(COALESCE(completed, '')) != ''",
        )
        ->whereRaw(
            "TRIM(COALESCE(month_no, '')) != ''",
        )
        ->whereRaw(
            "UPPER(TRIM(COALESCE(not_app, ''))) != 'Y'",
        )
        ->whereExists(
            function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('person_task_file')
                    ->whereColumn(
                        'person_task_file.person_task_id',
                        'person_task.id',
                    );
            },
        )
        ->whereExists(
            function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('person_task_proof')
                    ->whereColumn(
                        'person_task_proof.person_task_id',
                        'person_task.id',
                    );
            },
        )
        ->count();

    /*
     * Avoid division by zero when no OTG tasks have
     * been assigned.
     */
    $completionPercentage = $totalTasks > 0
        ? round(
            ($completedTasks / $totalTasks) * 100,
            1,
        )
        : 0.0;

    /*
     * Return the student's latest 10 completed OTG tasks.
     */
    $tasks = $db
        ->table('person_task')
        ->leftJoin(
            'task',
            'task.id',
            '=',
            'person_task.task_id',
        )
        ->where(
            'person_task.person_id',
            $studentId,
        )
        ->whereNotNull(
            'person_task.completed',
        )
        ->whereRaw(
            "TRIM(COALESCE(person_task.completed, '')) != ''",
        )
        ->whereRaw(
            "TRIM(COALESCE(person_task.month_no, '')) != ''",
        )
        ->whereRaw(
            "UPPER(TRIM(COALESCE(person_task.not_app, ''))) != 'Y'",
        )
        ->whereExists(
            function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('person_task_file')
                    ->whereColumn(
                        'person_task_file.person_task_id',
                        'person_task.id',
                    );
            },
        )
        ->whereExists(
            function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('person_task_proof')
                    ->whereColumn(
                        'person_task_proof.person_task_id',
                        'person_task.id',
                    );
            },
        )
        ->select([
            'person_task.id',
            'person_task.person_id',
            'person_task.task_id',
            'person_task.completed',
            'person_task.month_no',
            'person_task.not_app',

            'task.ref_no',
            'task.desc_task',
        ])
        ->orderByDesc(
            'person_task.completed',
        )
        ->limit(10)
        ->get()
        ->map(
            static function (
                object $task,
            ): array {
                return [
                    'id' =>
                        $task->id,

                    'task_id' =>
                        $task->task_id,

                    'reference_number' =>
                        $task->ref_no,

                    'description' =>
                        $task->desc_task
                        ?? 'OTG Task',

                    'month_number' =>
                        $task->month_no,

                    'completed_at' =>
                        $task->completed,

                    'status' =>
                        'Completed',
                ];
            },
        )
        ->values();

    return response()->json([
        'percentage' =>
            $completionPercentage,

        'completed' =>
            $completedTasks,

        'total' =>
            $totalTasks,

        'data' =>
            $tasks,
    ]);
}
    

    /**
     * Return the authenticated student's complete OTG overview.
     *
     * The response follows the same data shape used by the mobile Training
     * screen, but the person ID always comes from the authenticated Student.
     */
    public function studentIndex(
        Request $request,
    ): JsonResponse {
        $account = $request->user();

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

        $studentExists = $db
            ->table('person')
            ->where('id', $studentId)
            ->exists();

        if (! $studentExists) {
            return response()->json(
                [
                    'message' =>
                        'The student record was not found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $vessels = $db
            ->table('person_trb_setup')
            ->leftJoin(
                'vessel_type',
                'vessel_type.id',
                '=',
                'person_trb_setup.vessel_type_id',
            )
            ->select([
                'person_trb_setup.id',
                'person_trb_setup.vessel_type_id',
                'person_trb_setup.vessel_name',
                'person_trb_setup.ship_company',
                'person_trb_setup.flag',
                'person_trb_setup.from_date',
                'person_trb_setup.to_date',
                'vessel_type.desc_vessel_type',
            ])
            ->where(
                'person_trb_setup.person_id',
                $studentId,
            )
            ->orderBy(
                'person_trb_setup.from_date',
            )
            ->get()
            ->map(
                function (
                    object $vessel,
                ): array {
                    return [
                        'id' =>
                            (string) $vessel->id,

                        'vessel_type_id' =>
                            $vessel->vessel_type_id,

                        'vessel_name' =>
                            $this->decodeStudentTrainingText(
                                $vessel->vessel_name,
                            ),

                        'vessel_type' =>
                            $this->decodeStudentTrainingText(
                                $vessel
                                    ->desc_vessel_type,
                            ),

                        'ship_company' =>
                            $this->decodeStudentTrainingText(
                                $vessel->ship_company,
                            ),

                        'flag' =>
                            $this->decodeStudentTrainingText(
                                $vessel->flag,
                            ),

                        'sign_on_date' =>
                            $vessel->from_date,

                        'sign_off_date' =>
                            $vessel->to_date,
                    ];
                },
            )
            ->values();

        $workbookRecords = $db
            ->table('person_trb_book')
            ->leftJoin(
                'trb_type',
                'trb_type.id',
                '=',
                'person_trb_book.trb_type_id',
            )
            ->select([
                'person_trb_book.id',
                'person_trb_book.trb_type_id',
                'trb_type.desc_trb_type',
                'trb_type.prio',
            ])
            ->where(
                'person_trb_book.person_id',
                $studentId,
            )
            ->whereNotNull(
                'person_trb_book.trb_type_id',
            )
            ->orderByRaw(
                'CASE WHEN trb_type.prio IS NULL THEN 1 ELSE 0 END',
            )
            ->orderBy('trb_type.prio')
            ->orderBy(
                'trb_type.desc_trb_type',
            )
            ->get();

        /*
         * Match the mobile Training module: assigned workbook tasks need a
         * person_task row so every blue/default task can be opened and revised.
         * Existing task progress and files are never overwritten.
         */
        $this->ensureStudentWorkbookPersonTasks(
            $db,
            $studentId,
            $workbookRecords
                ->pluck('trb_type_id')
                ->filter()
                ->unique()
                ->values(),
        );

        $workbooks = $workbookRecords
            ->map(
                function (
                    object $workbook,
                ) use (
                    $db,
                    $studentId,
                ): array {
                    $trbTypeId =
                        (string) $workbook
                            ->trb_type_id;

                    /*
                     * Start from task instead of person_task. This lets the web
                     * page show every configured task as a blue/default task
                     * even if a legacy person_task row has not been created yet.
                     * It mirrors the mobile display without mutating data on GET.
                     */
                    $tasks = $db
                        ->table('task')
                        ->join(
                            'trb_competence',
                            'trb_competence.id',
                            '=',
                            'task.trb_competence_id',
                        )
                        ->join(
                            'trb_function',
                            'trb_function.id',
                            '=',
                            'trb_competence.trb_function_id',
                        )
                        ->leftJoin(
                            'trb_sub_competence',
                            'trb_sub_competence.id',
                            '=',
                            'task.trb_sub_competence_id',
                        )
                        ->leftJoin(
                            'person_task',
                            function (
                                $join,
                            ) use (
                                $studentId,
                            ): void {
                                $join
                                    ->on(
                                        'person_task.task_id',
                                        '=',
                                        'task.id',
                                    )
                                    ->where(
                                        'person_task.person_id',
                                        '=',
                                        $studentId,
                                    );
                            },
                        )
                        ->select([
                            'task.id as task_id',
                            'task.ref_no',
                            'task.desc_task',
                            'task.prio as task_prio',

                            'trb_competence.desc_competence',
                            'trb_competence.prio as competence_prio',

                            'trb_sub_competence.desc_sub_competence',

                            'trb_function.prio as function_prio',

                            'person_task.id as person_task_id',
                            'person_task.completed',
                            'person_task.not_app',
                            'person_task.month_no',
                        ])
                        ->where(
                            'trb_function.trb_type_id',
                            $trbTypeId,
                        )
                        ->orderByRaw(
                            'CASE WHEN trb_function.prio IS NULL THEN 1 ELSE 0 END',
                        )
                        ->orderBy(
                            'trb_function.prio',
                        )
                        ->orderByRaw(
                            'CASE WHEN trb_competence.prio IS NULL THEN 1 ELSE 0 END',
                        )
                        ->orderBy(
                            'trb_competence.prio',
                        )
                        ->orderByRaw(
                            'CASE WHEN task.prio IS NULL THEN 1 ELSE 0 END',
                        )
                        ->orderBy(
                            'task.prio',
                        )
                        ->orderBy(
                            'task.ref_no',
                        )
                        ->get()
                        ->map(
                            function (
                                object $task,
                            ): array {
                                return [
                                    'person_task_id' =>
                                        $task
                                            ->person_task_id
                                            ? (string) $task
                                                ->person_task_id
                                            : null,

                                    'task_id' =>
                                        (string) $task
                                            ->task_id,

                                    'ref_no' =>
                                        $this->decodeStudentTrainingText(
                                            $task->ref_no,
                                        ),

                                    'description' =>
                                        $this->decodeStudentTrainingText(
                                            $task->desc_task,
                                        ),

                                    'competence' =>
                                        $this->decodeStudentTrainingText(
                                            $task
                                                ->desc_competence,
                                        ),

                                    'topic' =>
                                        $this->decodeStudentTrainingText(
                                            $task
                                                ->desc_sub_competence,
                                        ),

                                    'completion_date' =>
                                        $task->completed,

                                    'not_applicable' =>
                                        strtoupper(
                                            trim(
                                                (string) (
                                                    $task
                                                        ->not_app ??
                                                    ''
                                                ),
                                            ),
                                        ) === 'Y',

                                    'month_no' =>
                                        $task->month_no,
                                ];
                            },
                        )
                        ->values();

                    $taskIds = $tasks
                        ->pluck('task_id')
                        ->filter()
                        ->unique()
                        ->values();

                    $completedTaskCount =
                        $this->studentCompletedTaskCount(
                            $db,
                            $studentId,
                            $taskIds,
                        );

                    $completedNaTaskCount =
                        $this->studentCompletedNaTaskCount(
                            $db,
                            $studentId,
                            $taskIds,
                        );

                    $referenceCount =
                        $tasks->count();

                    $effectiveTaskCount =
                        max(
                            0,
                            $referenceCount -
                                $completedNaTaskCount,
                        );

                    $completionPercentage =
                        $effectiveTaskCount > 0
                            ? (
                                $completedTaskCount /
                                $effectiveTaskCount
                            ) * 100
                            : 0;

                    return [
                        'workbook_assignment_id' =>
                            (string) $workbook->id,

                        'trb_type_id' =>
                            $trbTypeId,

                        'workbook' =>
                            $this->decodeStudentTrainingText(
                                $workbook
                                    ->desc_trb_type,
                            ),

                        'reference_count' =>
                            $referenceCount,

                        'effective_task_count' =>
                            $effectiveTaskCount,

                        'completed_task_count' =>
                            $completedTaskCount,

                        'completed_na_task_count' =>
                            $completedNaTaskCount,

                        'completion_percentage' =>
                            round(
                                $completionPercentage,
                                2,
                            ),

                        'task_references' =>
                            $tasks,
                    ];
                },
            )
            ->values();

        $overallTaskCount =
            (int) $workbooks
                ->sum(
                    'effective_task_count',
                );

        $overallCompletedTaskCount =
            (int) $workbooks
                ->sum(
                    'completed_task_count',
                );

        $overallCompletedNaTaskCount =
            (int) $workbooks
                ->sum(
                    'completed_na_task_count',
                );

        $overallCompletionPercentage =
            $overallTaskCount > 0
                ? (
                    $overallCompletedTaskCount /
                    $overallTaskCount
                ) * 100
                : 0;

        return response()->json([
            'success' => true,

            'message' =>
                $vessels->isEmpty() &&
                $workbooks->isEmpty() &&
                $overallTaskCount === 0
                    ? 'No training records found.'
                    : 'Training records fetched successfully.',

            'data' => [
                'training_vessels' =>
                    $vessels,

                'training_workbooks' =>
                    $workbooks,

                'overall_task_count' =>
                    $overallTaskCount,

                'overall_completed_task_count' =>
                    $overallCompletedTaskCount,

                'overall_completed_na_task_count' =>
                    $overallCompletedNaTaskCount,

                'overall_completion_percentage' =>
                    round(
                        $overallCompletionPercentage,
                        2,
                    ),
            ],
        ]);
    }

    /**
     * Count fully completed non-N/A tasks using the same OTG completion rules
     * already used by the mobile Training module.
     */
    private function studentCompletedTaskCount(
        ConnectionInterface $db,
        string $studentId,
        $taskIds,
    ): int {
        if ($taskIds->isEmpty()) {
            return 0;
        }

        return $db
            ->table('person_task')
            ->where(
                'person_task.person_id',
                $studentId,
            )
            ->whereIn(
                'person_task.task_id',
                $taskIds,
            )
            ->whereNotNull(
                'person_task.completed',
            )
            ->whereRaw(
                "TRIM(COALESCE(person_task.completed, '')) != ''",
            )
            ->where(
                'person_task.completed',
                '!=',
                '1970-01-01',
            )
            ->whereRaw(
                "UPPER(TRIM(COALESCE(person_task.not_app, ''))) != 'Y'",
            )
            ->whereExists(
                static function (
                    $query,
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_task_file',
                        )
                        ->whereColumn(
                            'person_task_file.person_task_id',
                            'person_task.id',
                        );
                },
            )
            ->whereExists(
                static function (
                    $query,
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_task_proof',
                        )
                        ->whereColumn(
                            'person_task_proof.person_task_id',
                            'person_task.id',
                        );
                },
            )
            ->distinct()
            ->count(
                'person_task.id',
            );
    }

    /**
     * Count completed N/A tasks. Proof of Assessment is still required, which
     * matches the current mobile Training rules.
     */
    private function studentCompletedNaTaskCount(
        ConnectionInterface $db,
        string $studentId,
        $taskIds,
    ): int {
        if ($taskIds->isEmpty()) {
            return 0;
        }

        return $db
            ->table('person_task')
            ->where(
                'person_task.person_id',
                $studentId,
            )
            ->whereIn(
                'person_task.task_id',
                $taskIds,
            )
            ->whereNotNull(
                'person_task.completed',
            )
            ->whereRaw(
                "TRIM(COALESCE(person_task.completed, '')) != ''",
            )
            ->where(
                'person_task.completed',
                '!=',
                '1970-01-01',
            )
            ->whereRaw(
                "UPPER(TRIM(COALESCE(person_task.not_app, ''))) = 'Y'",
            )
            ->whereExists(
                static function (
                    $query,
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'person_task_proof',
                        )
                        ->whereColumn(
                            'person_task_proof.person_task_id',
                            'person_task.id',
                        );
                },
            )
            ->distinct()
            ->count(
                'person_task.id',
            );
    }

    /**
     * Decode legacy OTG text while preserving punctuation in task content.
     */
    private function decodeStudentTrainingText(
        ?string $value,
    ): ?string {
        if (! filled($value)) {
            return null;
        }

        $decodedValue =
            trim((string) $value);

        $decodedValue =
            str_replace(
                '+',
                ' ',
                $decodedValue,
            );

        $decodedValue =
            rawurldecode(
                $decodedValue,
            );

        $decodedValue =
            rawurldecode(
                $decodedValue,
            );

        $decodedValue =
            preg_replace(
                '/\s+/u',
                ' ',
                $decodedValue ?? '',
            );

        $decodedValue =
            trim(
                $decodedValue ?? '',
            );

        return $decodedValue !== ''
            ? $decodedValue
            : null;
    }


    /**
     * Return the authenticated Student ID.
     */
    private function authenticatedStudentId(
        Request $request,
    ): string {
        $account = $request->user();

        abort_unless(
            $account instanceof Student,
            Response::HTTP_FORBIDDEN,
            'Only student accounts may access this resource.',
        );

        $studentId = trim(
            (string) $account->getAuthIdentifier(),
        );

        abort_if(
            $studentId === '',
            Response::HTTP_FORBIDDEN,
            'Authenticated student account is unavailable.',
        );

        return $studentId;
    }

    /**
     * Confirm that the authenticated student exists in the selected school.
     */
    private function assertStudentExists(
        ConnectionInterface $db,
        string $studentId,
    ): void {
        abort_unless(
            $db
                ->table('person')
                ->where('id', $studentId)
                ->exists(),
            Response::HTTP_NOT_FOUND,
            'The student record was not found.',
        );
    }

    /**
     * Create only missing person_task rows for tasks in assigned workbooks.
     *
     * This mirrors the mobile OTG behavior. Existing completion data,
     * objective evidence, proof of assessment and uploaded files are kept.
     */
    private function ensureStudentWorkbookPersonTasks(
        ConnectionInterface $db,
        string $studentId,
        $trbTypeIds,
    ): void {
        $normalizedTrbTypeIds = collect(
            $trbTypeIds,
        )
            ->filter(
                static fn ($value): bool =>
                    filled($value),
            )
            ->unique()
            ->values();

        if ($normalizedTrbTypeIds->isEmpty()) {
            return;
        }

        $workbookTaskIds = $db
            ->table('task')
            ->join(
                'trb_competence',
                'trb_competence.id',
                '=',
                'task.trb_competence_id',
            )
            ->join(
                'trb_function',
                'trb_function.id',
                '=',
                'trb_competence.trb_function_id',
            )
            ->whereIn(
                'trb_function.trb_type_id',
                $normalizedTrbTypeIds,
            )
            ->pluck('task.id')
            ->filter()
            ->unique()
            ->values();

        if ($workbookTaskIds->isEmpty()) {
            return;
        }

        $existingTaskIds = $db
            ->table('person_task')
            ->where(
                'person_id',
                $studentId,
            )
            ->whereIn(
                'task_id',
                $workbookTaskIds,
            )
            ->pluck('task_id')
            ->filter()
            ->unique()
            ->values();

        $missingTaskIds = $workbookTaskIds
            ->diff($existingTaskIds)
            ->values();

        if ($missingTaskIds->isEmpty()) {
            return;
        }

        $lastUpdate = now()->format(
            'Y-m-d H:i:s',
        );

        $records = $missingTaskIds
            ->map(
                static function (
                    $taskId,
                ) use (
                    $studentId,
                    $lastUpdate,
                ): array {
                    return [
                        'id' =>
                            (string) Str::uuid(),

                        'person_id' =>
                            $studentId,

                        'task_id' =>
                            (string) $taskId,

                        'last_update' =>
                            $lastUpdate,
                    ];
                },
            )
            ->all();

        foreach (
            array_chunk(
                $records,
                500,
            ) as $chunk
        ) {
            $db
                ->table('person_task')
                ->insert($chunk);
        }
    }

    /**
     * Return vessel type choices for the student vessel modal.
     */
    public function studentVesselOptions(
        Request $request,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $this->assertStudentExists(
            $db,
            $studentId,
        );

        $vesselTypes = $db
            ->table('vessel_type')
            ->select([
                'id',
                'desc_vessel_type',
            ])
            ->orderBy(
                'desc_vessel_type',
            )
            ->get()
            ->map(
                function (
                    object $vesselType,
                ): array {
                    return [
                        'id' =>
                            (string) $vesselType->id,

                        'description' =>
                            $this->decodeStudentTrainingText(
                                $vesselType
                                    ->desc_vessel_type,
                            ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'vessel_types' =>
                    $vesselTypes,
            ],
        ]);
    }

    /**
     * Add one vessel for the authenticated student.
     */
    public function studentStoreVessel(
        Request $request,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $validated = $request->validate([
            'vessel_name' => [
                'required',
                'string',
                'max:255',
            ],
            'vessel_type_id' => [
                'required',
                'string',
                'max:36',
            ],
            'ship_company' => [
                'required',
                'string',
                'max:255',
            ],
            'flag' => [
                'required',
                'string',
                'max:50',
            ],
            'sign_on_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'sign_off_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:sign_on_date',
            ],
        ]);

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $this->assertStudentExists(
            $db,
            $studentId,
        );

        $vesselType = $db
            ->table('vessel_type')
            ->select([
                'id',
                'desc_vessel_type',
            ])
            ->where(
                'id',
                $validated[
                    'vessel_type_id'
                ],
            )
            ->first();

        if (! $vesselType) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The selected vessel type is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vesselId =
            (string) Str::uuid();

        $lastUpdate =
            now()->format(
                'Y-m-d H:i:s',
            );

        $db
            ->table('person_trb_setup')
            ->insert([
                'id' =>
                    $vesselId,

                'person_id' =>
                    $studentId,

                'vessel_name' =>
                    trim(
                        $validated[
                            'vessel_name'
                        ],
                    ),

                'vessel_type_id' =>
                    $validated[
                        'vessel_type_id'
                    ],

                'ship_company' =>
                    trim(
                        $validated[
                            'ship_company'
                        ],
                    ),

                'flag' =>
                    trim(
                        $validated['flag'],
                    ),

                'from_date' =>
                    $validated[
                        'sign_on_date'
                    ],

                'to_date' =>
                    $validated[
                        'sign_off_date'
                    ],

                'login_id' =>
                    $studentId,

                'last_update' =>
                    $lastUpdate,
            ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Vessel added successfully.',
            'data' => [
                'vessel' => [
                    'id' =>
                        $vesselId,

                    'vessel_type_id' =>
                        (string) $vesselType->id,

                    'vessel_name' =>
                        trim(
                            $validated[
                                'vessel_name'
                            ],
                        ),

                    'vessel_type' =>
                        $this->decodeStudentTrainingText(
                            $vesselType
                                ->desc_vessel_type,
                        ),

                    'ship_company' =>
                        trim(
                            $validated[
                                'ship_company'
                            ],
                        ),

                    'flag' =>
                        trim(
                            $validated[
                                'flag'
                            ],
                        ),

                    'sign_on_date' =>
                        $validated[
                            'sign_on_date'
                        ],

                    'sign_off_date' =>
                        $validated[
                            'sign_off_date'
                        ],
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Revise one vessel belonging to the authenticated student.
     */
    public function studentUpdateVessel(
        Request $request,
        string $vesselId,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $validated = $request->validate([
            'vessel_name' => [
                'required',
                'string',
                'max:255',
            ],
            'vessel_type_id' => [
                'required',
                'string',
                'max:36',
            ],
            'ship_company' => [
                'required',
                'string',
                'max:255',
            ],
            'flag' => [
                'required',
                'string',
                'max:50',
            ],
            'sign_on_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'sign_off_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:sign_on_date',
            ],
        ]);

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $vessel = $db
            ->table('person_trb_setup')
            ->where(
                'id',
                $vesselId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->first();

        if (! $vessel) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Vessel record not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $vesselType = $db
            ->table('vessel_type')
            ->select([
                'id',
                'desc_vessel_type',
            ])
            ->where(
                'id',
                $validated[
                    'vessel_type_id'
                ],
            )
            ->first();

        if (! $vesselType) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The selected vessel type is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $db
            ->table('person_trb_setup')
            ->where(
                'id',
                $vesselId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->update([
                'vessel_name' =>
                    trim(
                        $validated[
                            'vessel_name'
                        ],
                    ),

                'vessel_type_id' =>
                    $validated[
                        'vessel_type_id'
                    ],

                'ship_company' =>
                    trim(
                        $validated[
                            'ship_company'
                        ],
                    ),

                'flag' =>
                    trim(
                        $validated['flag'],
                    ),

                'from_date' =>
                    $validated[
                        'sign_on_date'
                    ],

                'to_date' =>
                    $validated[
                        'sign_off_date'
                    ],

                'login_id' =>
                    $studentId,

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Vessel updated successfully.',
        ]);
    }

    /**
     * Remove one vessel belonging to the authenticated student.
     *
     * This mirrors the current mobile behavior: only the vessel setup record
     * is removed. OTG task records and uploaded task files are untouched.
     */
    public function studentDestroyVessel(
        Request $request,
        string $vesselId,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $deleted = $db
            ->table('person_trb_setup')
            ->where(
                'id',
                $vesselId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Vessel record not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Vessel removed successfully.',
        ]);
    }

    /**
     * Resolve the workbook families/departments allowed for the student.
     */
    private function studentWorkbookScope(
        ConnectionInterface $db,
        string $studentId,
    ): array {
        $person = $db
            ->table('person')
            ->select([
                'id',
                'etrb_type',
                'dept',
            ])
            ->where(
                'id',
                $studentId,
            )
            ->first();

        if (! $person) {
            return [
                'families' =>
                    collect(),
                'departments' =>
                    collect(),
            ];
        }

        $etrbTypeValue =
            strtoupper(
                trim(
                    (string) (
                        $person->etrb_type ??
                        ''
                    ),
                ),
            );

        $departmentValue =
            strtoupper(
                trim(
                    (string) (
                        $person->dept ??
                        ''
                    ),
                ),
            );

        $families = collect([
            'ISF',
            'GMET',
        ])
            ->filter(
                static function (
                    string $family,
                ) use (
                    $etrbTypeValue,
                ): bool {
                    return
                        $etrbTypeValue ===
                            $family ||
                        str_contains(
                            $etrbTypeValue,
                            $family,
                        );
                },
            )
            ->values();

        $departments = collect([
            'DECK',
            'ENGINE',
        ])
            ->filter(
                static function (
                    string $department,
                ) use (
                    $departmentValue,
                ): bool {
                    return
                        $departmentValue ===
                            $department ||
                        str_contains(
                            $departmentValue,
                            $department,
                        );
                },
            )
            ->values();

        return [
            'families' =>
                $families,

            'departments' =>
                $departments,
        ];
    }

    /**
     * Return eligible, currently unassigned workbook types.
     */
    public function studentWorkbookOptions(
        Request $request,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $this->assertStudentExists(
            $db,
            $studentId,
        );

        $scope = $this
            ->studentWorkbookScope(
                $db,
                $studentId,
            );

        $families =
            $scope['families'];

        $departments =
            $scope['departments'];

        if (
            $families->isEmpty() ||
            $departments->isEmpty()
        ) {
            return response()->json([
                'success' => true,
                'message' =>
                    'The student eTRB type or department is not configured as ISF/GMET and Deck/Engine.',
                'data' => [
                    'workbook_types' =>
                        [],
                ],
            ]);
        }

        $assignedIds = $db
            ->table('person_trb_book')
            ->where(
                'person_id',
                $studentId,
            )
            ->whereNotNull(
                'trb_type_id',
            )
            ->pluck(
                'trb_type_id',
            )
            ->filter()
            ->unique()
            ->values();

        $query = $db
            ->table('trb_type')
            ->select([
                'id',
                'desc_trb_type',
                'prio',
                'trb',
                'dept',
            ])
            ->whereIn(
                DB::raw(
                    'UPPER(TRIM(trb))',
                ),
                $families->all(),
            )
            ->whereIn(
                DB::raw(
                    'UPPER(TRIM(dept))',
                ),
                $departments->all(),
            );

        if ($assignedIds->isNotEmpty()) {
            $query->whereNotIn(
                'id',
                $assignedIds,
            );
        }

        $workbookTypes = $query
            ->orderByRaw(
                'CASE WHEN prio IS NULL THEN 1 ELSE 0 END',
            )
            ->orderBy('prio')
            ->orderBy(
                'desc_trb_type',
            )
            ->get()
            ->map(
                function (
                    object $type,
                ): array {
                    return [
                        'id' =>
                            (string) $type->id,

                        'description' =>
                            $this->decodeStudentTrainingText(
                                $type
                                    ->desc_trb_type,
                            ),

                        'trb' =>
                            strtoupper(
                                trim(
                                    (string) (
                                        $type->trb ??
                                        ''
                                    ),
                                ),
                            ),

                        'department' =>
                            strtoupper(
                                trim(
                                    (string) (
                                        $type->dept ??
                                        ''
                                    ),
                                ),
                            ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'workbook_types' =>
                    $workbookTypes,
            ],
        ]);
    }

    /**
     * Add one eligible workbook assignment.
     */
    public function studentStoreWorkbook(
        Request $request,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $validated = $request->validate([
            'trb_type_id' => [
                'required',
                'string',
                'max:36',
            ],
        ]);

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $this->assertStudentExists(
            $db,
            $studentId,
        );

        $scope = $this
            ->studentWorkbookScope(
                $db,
                $studentId,
            );

        $families =
            $scope['families'];

        $departments =
            $scope['departments'];

        if (
            $families->isEmpty() ||
            $departments->isEmpty()
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The student eTRB type or department is not configured for this workbook.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = $db
            ->table('trb_type')
            ->select([
                'id',
                'desc_trb_type',
                'trb',
                'dept',
            ])
            ->where(
                'id',
                $validated[
                    'trb_type_id'
                ],
            )
            ->first();

        if (! $type) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The selected workbook type is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $family = strtoupper(
            trim(
                (string) (
                    $type->trb ??
                    ''
                ),
            ),
        );

        $department = strtoupper(
            trim(
                (string) (
                    $type->dept ??
                    ''
                ),
            ),
        );

        if (
            ! $families->contains(
                $family,
            ) ||
            ! $departments->contains(
                $department,
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The selected workbook does not match the student eTRB type or department.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $exists = $db
            ->table('person_trb_book')
            ->where(
                'person_id',
                $studentId,
            )
            ->where(
                'trb_type_id',
                $validated[
                    'trb_type_id'
                ],
            )
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This workbook is already assigned to the student.',
            ], Response::HTTP_CONFLICT);
        }

        $assignmentId =
            (string) Str::uuid();

        $db
            ->table('person_trb_book')
            ->insert([
                'id' =>
                    $assignmentId,

                'person_id' =>
                    $studentId,

                'trb_type_id' =>
                    $validated[
                        'trb_type_id'
                    ],

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        $this->ensureStudentWorkbookPersonTasks(
            $db,
            $studentId,
            collect([
                $validated[
                    'trb_type_id'
                ],
            ]),
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Workbook added successfully.',
            'data' => [
                'workbook_assignment_id' =>
                    $assignmentId,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Remove an assigned workbook.
     *
     * Exactly like the mobile workflow, person_task rows, Objective Evidence,
     * Proof of Assessment, and uploaded files are preserved.
     */
    public function studentDestroyWorkbook(
        Request $request,
        string $workbookAssignmentId,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $assignment = $db
            ->table('person_trb_book')
            ->select([
                'id',
                'trb_type_id',
            ])
            ->where(
                'id',
                $workbookAssignmentId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->first();

        if (! $assignment) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Workbook assignment not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $db
            ->table('person_trb_book')
            ->where(
                'id',
                $workbookAssignmentId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->delete();

        return response()->json([
            'success' => true,
            'message' =>
                'Workbook removed. Existing task records and uploaded files were preserved.',
        ]);
    }

    /**
     * Build the public URL of an OTG task attachment.
     */
    private function studentTaskFileUrl(
        ?string $filename,
    ): ?string {
        if (! filled($filename)) {
            return null;
        }

        $baseUrl = trim(
            (string) config(
                'admapro.activity.file_url',
                '',
            ),
        );

        if ($baseUrl === '') {
            $baseUrl = trim(
                (string) config(
                    'admapro.person_task.file_url',
                    '',
                ),
            );
        }

        if ($baseUrl === '') {
            return null;
        }

        return rtrim(
            $baseUrl,
            '/',
        ) . '/' . rawurlencode(
            basename(
                (string) $filename,
            ),
        );
    }

    /**
     * Store one OTG Objective Evidence / Proof of Assessment attachment.
     */
    private function storeStudentTaskFile(
        ConnectionInterface $db,
        $file,
        string $type,
        string $personTaskId,
        string $taskId,
        string $studentId,
    ): array {
        $extension = strtolower(
            $file
                ->getClientOriginalExtension(),
        );

        $filename =
            now()->format(
                'Ymd_His',
            ) .
            '_' .
            Str::lower(
                Str::random(8),
            ) .
            (
                $extension !== ''
                    ? '.' . $extension
                    : ''
            );

        Storage::disk(
            'admapro_person_task',
        )->putFileAs(
            '',
            $file,
            $filename,
        );

        $record = [
            'id' =>
                (string) Str::uuid(),

            'filename' =>
                $filename,

            'file_desc' =>
                $file
                    ->getClientOriginalName(),

            'uploaded' =>
                now()->format(
                    'Y-m-d',
                ),

            'person_id' =>
                $studentId,

            'task_id' =>
                $taskId,

            'person_task_id' =>
                $personTaskId,
        ];

        $table =
            $type === 'objective'
                ? 'person_task_file'
                : 'person_task_proof';

        if ($type === 'objective') {
            $record += [
                'checked_by_id' =>
                    null,

                'app_by_id' =>
                    null,

                'date_checked' =>
                    null,

                'date_app' =>
                    null,

                'checked_remarks' =>
                    null,

                'app_remarks' =>
                    null,
            ];
        }

        $db
            ->table($table)
            ->insert($record);

        return [
            'type' =>
                $type,

            'filename' =>
                $filename,
        ];
    }

    private function removeStudentStoredTaskFile(
        ?string $filename,
    ): void {
        if (! filled($filename)) {
            return;
        }

        $path = basename(
            (string) $filename,
        );

        $disk = Storage::disk(
            'admapro_person_task',
        );

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * Return the full editable task payload for one authenticated student task.
     */
    public function studentTaskDetails(
        Request $request,
        string $personTaskId,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $task = $db
            ->table('person_task')
            ->join(
                'task',
                'task.id',
                '=',
                'person_task.task_id',
            )
            ->leftJoin(
                'trb_competence',
                'trb_competence.id',
                '=',
                'task.trb_competence_id',
            )
            ->leftJoin(
                'trb_sub_competence',
                'trb_sub_competence.id',
                '=',
                'task.trb_sub_competence_id',
            )
            ->leftJoin(
                'trb_function',
                'trb_function.id',
                '=',
                'trb_competence.trb_function_id',
            )
            ->select([
                'person_task.id as person_task_id',
                'person_task.task_id',
                'person_task.completed',
                'person_task.month_no',
                'person_task.not_app',

                'task.ref_no',
                'task.desc_task',

                'trb_competence.ref_no as competence_ref_no',
                'trb_competence.desc_competence',

                'trb_sub_competence.ref_no as topic_ref_no',
                'trb_sub_competence.desc_sub_competence',

                'trb_function.trb_type_id',
            ])
            ->where(
                'person_task.id',
                $personTaskId,
            )
            ->where(
                'person_task.person_id',
                $studentId,
            )
            ->first();

        if (! $task) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Training task not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $objectiveEvidence = $db
            ->table('person_task_file')
            ->select([
                'id',
                'filename',
                'file_desc',
                'uploaded',
            ])
            ->where(
                'person_task_id',
                $personTaskId,
            )
            ->orderByDesc(
                'uploaded',
            )
            ->get()
            ->map(
                function (
                    object $file,
                ): array {
                    return [
                        'id' =>
                            (string) $file->id,

                        'filename' =>
                            $file->filename,

                        'file_description' =>
                            $this->decodeStudentTrainingText(
                                $file->file_desc,
                            ),

                        'uploaded_date' =>
                            $file->uploaded,

                        'file_url' =>
                            $this->studentTaskFileUrl(
                                $file->filename,
                            ),
                    ];
                },
            )
            ->values();

        $proofOfAssessment = $db
            ->table('person_task_proof')
            ->select([
                'id',
                'filename',
                'file_desc',
                'uploaded',
            ])
            ->where(
                'person_task_id',
                $personTaskId,
            )
            ->orderByDesc(
                'uploaded',
            )
            ->get()
            ->map(
                function (
                    object $file,
                ): array {
                    return [
                        'id' =>
                            (string) $file->id,

                        'filename' =>
                            $file->filename,

                        'file_description' =>
                            $this->decodeStudentTrainingText(
                                $file->file_desc,
                            ),

                        'uploaded_date' =>
                            $file->uploaded,

                        'file_url' =>
                            $this->studentTaskFileUrl(
                                $file->filename,
                            ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'task' => [
                    'person_task_id' =>
                        (string) $task
                            ->person_task_id,

                    'task_id' =>
                        (string) $task
                            ->task_id,

                    'ref_no' =>
                        $this->decodeStudentTrainingText(
                            $task->ref_no,
                        ),

                    'description' =>
                        $this->decodeStudentTrainingText(
                            $task->desc_task,
                        ),

                    'completion_date' =>
                        $task->completed,

                    'month_no' =>
                        $task->month_no,

                    'not_applicable' =>
                        strtoupper(
                            trim(
                                (string) (
                                    $task->not_app ??
                                    ''
                                ),
                            ),
                        ) === 'Y',
                ],

                'competence' => [
                    'ref_no' =>
                        $task
                            ->competence_ref_no,

                    'description' =>
                        $this->decodeStudentTrainingText(
                            $task
                                ->desc_competence,
                        ),
                ],

                'sub_competence' => [
                    'ref_no' =>
                        $task
                            ->topic_ref_no,

                    'description' =>
                        $this->decodeStudentTrainingText(
                            $task
                                ->desc_sub_competence,
                        ),
                ],

                'objective_evidence' =>
                    $objectiveEvidence,

                'proof_of_assessment' =>
                    $proofOfAssessment,
            ],
        ]);
    }

    /**
     * Revise a task exactly through the fields the mobile task editor owns:
     * completion date, month onboard, N/A status, Objective Evidence and Proof.
     */
    public function studentUpdateTask(
        Request $request,
        string $personTaskId,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $validated = $request->validate([
            'completion_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'month_no' => [
                'required',
                'string',
                'regex:/^(0[1-9]|1[0-2])$/',
            ],
            'not_app' => [
                'required',
                'in:Y,N',
            ],
            'objective_evidence_files' => [
                'nullable',
                'array',
            ],
            'objective_evidence_files.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,pdf,doc,docx',
                'max:20480',
            ],
            'proof_assessment_files' => [
                'nullable',
                'array',
            ],
            'proof_assessment_files.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,pdf,doc,docx',
                'max:20480',
            ],
        ]);

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $personTask = $db
            ->table('person_task')
            ->select([
                'id',
                'task_id',
                'person_id',
            ])
            ->where(
                'id',
                $personTaskId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->first();

        if (! $personTask) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Training task not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $objectiveFiles = $request->file(
            'objective_evidence_files',
            [],
        );

        $proofFiles = $request->file(
            'proof_assessment_files',
            [],
        );

        $storedFiles = [];

        $db->beginTransaction();

        try {
            $db
                ->table('person_task')
                ->where(
                    'id',
                    $personTaskId,
                )
                ->where(
                    'person_id',
                    $studentId,
                )
                ->update([
                    'completed' =>
                        $validated[
                            'completion_date'
                        ],

                    'month_no' =>
                        $validated[
                            'month_no'
                        ],

                    'not_app' =>
                        $validated[
                            'not_app'
                        ],

                    'last_update' =>
                        now()->format(
                            'Y-m-d H:i:s',
                        ),
                ]);

            foreach (
                $objectiveFiles as $file
            ) {
                $storedFiles[] =
                    $this->storeStudentTaskFile(
                        $db,
                        $file,
                        'objective',
                        $personTaskId,
                        (string) $personTask
                            ->task_id,
                        $studentId,
                    );
            }

            foreach (
                $proofFiles as $file
            ) {
                $storedFiles[] =
                    $this->storeStudentTaskFile(
                        $db,
                        $file,
                        'proof',
                        $personTaskId,
                        (string) $personTask
                            ->task_id,
                        $studentId,
                    );
            }

            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();

            foreach (
                $storedFiles as $storedFile
            ) {
                $this
                    ->removeStudentStoredTaskFile(
                        $storedFile[
                            'filename'
                        ] ?? null,
                    );
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Training task updated successfully.',
        ]);
    }

    public function studentRemoveObjectiveEvidence(
        Request $request,
        string $fileId,
    ): JsonResponse {
        return $this
            ->studentRemoveTaskFile(
                $request,
                $fileId,
                'objective',
            );
    }

    public function studentRemoveProofOfAssessment(
        Request $request,
        string $fileId,
    ): JsonResponse {
        return $this
            ->studentRemoveTaskFile(
                $request,
                $fileId,
                'proof',
            );
    }

    /**
     * Remove one task attachment only after verifying the task belongs to the
     * authenticated student.
     */
    private function studentRemoveTaskFile(
        Request $request,
        string $fileId,
        string $type,
    ): JsonResponse {
        $studentId = $this
            ->authenticatedStudentId(
                $request,
            );

        $db = $this
            ->resolveSchoolConnection(
                $request,
            );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $table =
            $type === 'objective'
                ? 'person_task_file'
                : 'person_task_proof';

        $file = $db
            ->table($table)
            ->join(
                'person_task',
                'person_task.id',
                '=',
                $table .
                    '.person_task_id',
            )
            ->select([
                $table . '.id',
                $table . '.filename',
            ])
            ->where(
                $table . '.id',
                $fileId,
            )
            ->where(
                'person_task.person_id',
                $studentId,
            )
            ->first();

        if (! $file) {
            return response()->json([
                'success' => false,
                'message' =>
                    $type === 'objective'
                        ? 'Objective evidence not found.'
                        : 'Proof of assessment not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $db
            ->table($table)
            ->where(
                'id',
                $fileId,
            )
            ->delete();

        $this
            ->removeStudentStoredTaskFile(
                $file->filename,
            );

        return response()->json([
            'success' => true,
            'message' =>
                $type === 'objective'
                    ? 'Objective evidence removed successfully.'
                    : 'Proof of assessment removed successfully.',
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

        return DB::connection($connection);
    }
}