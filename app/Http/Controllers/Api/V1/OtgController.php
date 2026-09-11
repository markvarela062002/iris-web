<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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