<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PracticalInternalAssessmentsController extends Controller
{
    /**
     * Display pending Practical Internal assessments.
     */
    public function index(Request $request): JsonResponse
    {
        $database = $this->database($request);

        $perPage = (int) $request->integer(
            'per_page',
            10,
        );

        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $search = trim(
            (string) $request->input(
                'search',
                '',
            ),
        );

        $sortField = (string) $request->input(
            'sort_field',
            'date_taken',
        );

        $sortDirection = strtolower(
            (string) $request->input(
                'sort_direction',
                'desc',
            ),
        );

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $sortableColumns = [
            'date_taken' => 'person_assess_h.date_taken',
            'due_date' => 'person_assess_h.due_date',
            'student_name' => 'person.lname',
            'title_assess' => 'p_assess_h.title_assess',
        ];

        $sortColumn = $sortableColumns[$sortField]
            ?? 'person_assess_h.date_taken';

        $query = $database
            ->table('person_assess_h')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'person_assess_h.person_id',
            )
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'person_assess_h.p_assess_h_id',
            )
            ->where(
                'person_assess_h.for_assess',
                'Y',
            )
            ->select([
                'person_assess_h.id',
                'person_assess_h.person_id',
                'person_assess_h.p_assess_h_id',
                'person_assess_h.date_taken',
                'person_assess_h.due_date',
                'person_assess_h.date_assessed',
                'person_assess_h.done',
                'person_assess_h.for_assess',
                'person_assess_h.total_pts',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',

                'p_assess_h.title_assess',
                'p_assess_h.grade_system',
                'p_assess_h.passing_mark',
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $builder) use ($search): void {
                    $searchValue = "%{$search}%";

                    $builder
                        ->where(
                            'person.code_person',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'person.school_id_no',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'person.fname',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'person.mname',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'person.lname',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'person.dept',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'p_assess_h.title_assess',
                            'like',
                            $searchValue,
                        )
                        ->orWhere(
                            'p_assess_h.grade_system',
                            'like',
                            $searchValue,
                        );
                },
            );
        }

        $paginator = $query
            ->orderBy(
                $sortColumn,
                $sortDirection,
            )
            ->paginate($perPage)
            ->withQueryString();

        $records = collect(
            $paginator->items(),
        )->map(
            function (object $row): array {
                return [
                    'id' => (string) $row->id,
                    'person_id' => (string) $row->person_id,

                    'student_name' => $this->studentName(
                        $row,
                    ),

                    'school_id_no' => $row->school_id_no
                        ?: $row->code_person,

                    'code_person' => $row->code_person,
                    'gender' => $row->gender,
                    'dept' => $row->dept,

                    'title_assess' => $row->title_assess
                        ?: 'Untitled Practical Assessment',

                    'grade_system' => $row->grade_system
                        ?: 'Checklist',

                    'date_taken' => $row->date_taken,
                    'due_date' => $row->due_date,
                    'date_assessed' => $row->date_assessed,

                    'total_points' => (float) (
                        $row->total_pts ?? 0
                    ),

                    'is_completed' =>
                        strtoupper((string) $row->done) === 'Y',

                    'is_pending' =>
                        strtoupper((string) $row->for_assess) === 'Y',
                ];
            },
        )->values();

        return response()->json([
            'data' => $records,

            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],

            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url(
                    $paginator->lastPage(),
                ),
                'previous' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Return an assessment and its grading items.
     */
    public function show(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $database = $this->database($request);

        $assessment = $database
            ->table('person_assess_h')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'person_assess_h.person_id',
            )
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'person_assess_h.p_assess_h_id',
            )
            ->where(
                'person_assess_h.id',
                $assessmentId,
            )
            ->select([
                'person_assess_h.*',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',

                'p_assess_h.title_assess',
                'p_assess_h.instruction_assess',
                'p_assess_h.grade_system',
                'p_assess_h.passing_mark',
                'p_assess_h.rubrics_id',
            ])
            ->first();

        abort_unless(
            $assessment !== null,
            Response::HTTP_NOT_FOUND,
            'The selected practical assessment was not found.',
        );

        $items = $database
            ->table('person_assess_d')
            ->leftJoin(
                'p_assess_d',
                'p_assess_d.id',
                '=',
                'person_assess_d.assess_d_id',
            )
            ->where(
                'person_assess_d.person_assess_h_id',
                $assessmentId,
            )
            ->orderBy('p_assess_d.prio')
            ->select([
                'person_assess_d.id',
                'person_assess_d.assess_d_id',
                'person_assess_d.remarks',
                'person_assess_d.filename_d',
                'person_assess_d.points',

                'p_assess_d.item_d',
                'p_assess_d.filename_d as item_file',
                'p_assess_d.prio',
                'p_assess_d.point_d as maximum_points',
            ])
            ->get();

        $rubricCriteria = collect();

        if (
            strcasecmp(
                (string) $assessment->grade_system,
                'Rubrics',
            ) === 0 &&
            ! empty($assessment->rubrics_id)
        ) {
            $rubricCriteria = $this->rubricCriteria(
                $database,
                (string) $assessment->rubrics_id,
            );
        }

        $referenceFiles = $database
            ->table('p_assess_h_file')
            ->where(
                'p_assess_h_id',
                $assessment->p_assess_h_id,
            )
            ->orderBy('prio_file')
            ->get([
                'id',
                'assess_h_file',
                'prio_file',
            ])
            ->map(
                fn (object $file): array => [
                    'id' => (string) $file->id,
                    'name' => basename(
                        (string) $file->assess_h_file,
                    ),
                    'url' => $this->remoteFileUrl(
                        'dashboard.files.upload',
                        $file->assess_h_file,
                    ),
                ],
            )
            ->filter(
                fn (array $file): bool =>
                    $file['name'] !== '',
            )
            ->values();

        $normalizedItems = $items->map(
            function (object $item): array {
                return [
                    'id' => (string) $item->id,
                    'assessment_item_id' =>
                        (string) $item->assess_d_id,

                    'description' => $this->decodeLegacyText(
                        $item->item_d,
                    ),

                    'answer' => $this->decodeLegacyText(
                        $item->remarks,
                    ),

                    'points' => is_numeric($item->points)
                        ? (float) $item->points
                        : 0,

                    'maximum_points' =>
                        is_numeric($item->maximum_points)
                            ? (float) $item->maximum_points
                            : 0,

                    'reference_file' => $this->fileData(
                        $item->item_file,
                        'dashboard.files.upload',
                    ),

                    'evidence_file' => $this->fileData(
                        $item->filename_d,
                        'dashboard.files.person-task',
                    ),
                ];
            },
        )->values();

        $maximumPoints = $this->maximumPoints(
            (string) $assessment->grade_system,
            $normalizedItems,
            $rubricCriteria,
        );

        $earnedPoints = (float) $normalizedItems->sum(
            fn (array $item): float =>
                (float) $item['points'],
        );

        $percentage = $maximumPoints > 0
            ? round(
                ($earnedPoints / $maximumPoints) * 100,
                1,
            )
            : 0;

        $passingMark = (float) (
            $assessment->passing_mark ?? 0
        );

        return response()->json([
            'data' => [
                'id' => (string) $assessment->id,

                'student' => [
                    'id' => (string) $assessment->person_id,

                    'name' => $this->studentName(
                        $assessment,
                    ),

                    'school_id_no' =>
                        $assessment->school_id_no
                        ?: $assessment->code_person,

                    'dept' => $assessment->dept,
                    'gender' => $assessment->gender,
                ],

                'title' => $assessment->title_assess
                    ?: 'Untitled Practical Assessment',

                'instructions' => $this->decodeLegacyText(
                    $assessment->instruction_assess,
                ),

                'grade_system' => $assessment->grade_system
                    ?: 'Checklist',

                'passing_mark' => $passingMark,
                'date_taken' => $assessment->date_taken,
                'due_date' => $assessment->due_date,

                'is_completed' =>
                    strtoupper(
                        (string) $assessment->done,
                    ) === 'Y',

                'is_pending' =>
                    strtoupper(
                        (string) $assessment->for_assess,
                    ) === 'Y',

                'items' => $normalizedItems,
                'rubric_criteria' => $rubricCriteria,
                'reference_files' => $referenceFiles,

                'result' => [
                    'earned_points' => $earnedPoints,
                    'maximum_points' => $maximumPoints,
                    'percentage' => $percentage,
                    'passing_mark' => $passingMark,
                    'remarks' => $percentage >= $passingMark
                        ? 'PASS'
                        : 'FAIL',
                ],
            ],
        ]);
    }

    /**
     * Save grades and complete an assessment.
     */
    public function grade(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $database = $this->database($request);

        $validated = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.item_id' => [
                'required',
                'string',
            ],
            'grades.*.points' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'grades.*.rubric_selections' => [
                'nullable',
                'array',
            ],
            'grades.*.rubric_selections.*' => [
                'nullable',
                'string',
            ],
        ]);

        try {
            $result = $database->transaction(
                function () use (
                    $database,
                    $assessmentId,
                    $validated,
                ): array {
                    $assessment = $database
                        ->table('person_assess_h')
                        ->join(
                            'p_assess_h',
                            'p_assess_h.id',
                            '=',
                            'person_assess_h.p_assess_h_id',
                        )
                        ->where(
                            'person_assess_h.id',
                            $assessmentId,
                        )
                        ->lockForUpdate()
                        ->select([
                            'person_assess_h.id',
                            'person_assess_h.done',
                            'person_assess_h.for_assess',
                            'p_assess_h.grade_system',
                            'p_assess_h.rubrics_id',
                        ])
                        ->first();

                    if ($assessment === null) {
                        abort(
                            Response::HTTP_NOT_FOUND,
                            'The selected practical assessment was not found.',
                        );
                    }

                    if (
                        strtoupper(
                            (string) $assessment->done,
                        ) === 'Y'
                    ) {
                        throw ValidationException::withMessages([
                            'assessment' => [
                                'This practical assessment has already been graded.',
                            ],
                        ]);
                    }

                    $items = $database
                        ->table('person_assess_d')
                        ->leftJoin(
                            'p_assess_d',
                            'p_assess_d.id',
                            '=',
                            'person_assess_d.assess_d_id',
                        )
                        ->where(
                            'person_assess_d.person_assess_h_id',
                            $assessmentId,
                        )
                        ->lockForUpdate()
                        ->select([
                            'person_assess_d.id',
                            'p_assess_d.point_d as maximum_points',
                        ])
                        ->get()
                        ->keyBy(
                            fn (object $item): string =>
                                (string) $item->id,
                        );

                    if ($items->isEmpty()) {
                        throw ValidationException::withMessages([
                            'grades' => [
                                'This assessment does not contain any grading items.',
                            ],
                        ]);
                    }

                    $submittedGrades = collect(
                        $validated['grades'],
                    )->keyBy(
                        fn (array $grade): string =>
                            (string) $grade['item_id'],
                    );

                    $gradeSystem = strtolower(
                        trim(
                            (string) $assessment->grade_system,
                        ),
                    );

                    $rubricItems = collect();

                    if ($gradeSystem === 'rubrics') {
                        $rubricItems = $database
                            ->table('rubrics_criterion_item')
                            ->join(
                                'rubrics_criterion',
                                'rubrics_criterion.id',
                                '=',
                                'rubrics_criterion_item.rubrics_criterion_id',
                            )
                            ->where(
                                'rubrics_criterion.rubrics_id',
                                $assessment->rubrics_id,
                            )
                            ->select([
                                'rubrics_criterion_item.id',
                                'rubrics_criterion_item.rubrics_criterion_id',
                                'rubrics_criterion_item.item_point',
                            ])
                            ->get()
                            ->keyBy(
                                fn (object $item): string =>
                                    (string) $item->id,
                            );
                    }

                    $totalPoints = 0.0;

                    foreach ($items as $itemId => $item) {
                        $submitted = $submittedGrades->get(
                            $itemId,
                            [],
                        );

                        $points = 0.0;

                        if ($gradeSystem === 'points') {
                            $points = (float) (
                                $submitted['points'] ?? 0
                            );

                            $maximum = max(
                                0,
                                (float) (
                                    $item->maximum_points ?? 0
                                ),
                            );

                            if ($points > $maximum) {
                                throw ValidationException::withMessages([
                                    'grades' => [
                                        "The grade for an item cannot exceed {$maximum} points.",
                                    ],
                                ]);
                            }
                        } elseif ($gradeSystem === 'checklist') {
                            $points = (
                                (float) (
                                    $submitted['points'] ?? 0
                                ) > 0
                            ) ? 1.0 : 0.0;
                        } else {
                            $selections = collect(
                                $submitted['rubric_selections']
                                    ?? [],
                            )
                                ->filter()
                                ->values();

                            foreach ($selections as $selectionId) {
                                $rubricItem = $rubricItems->get(
                                    (string) $selectionId,
                                );

                                if ($rubricItem === null) {
                                    throw ValidationException::withMessages([
                                        'grades' => [
                                            'An invalid rubric option was submitted.',
                                        ],
                                    ]);
                                }

                                $points += (float) (
                                    $rubricItem->item_point ?? 0
                                );
                            }
                        }

                        $database
                            ->table('person_assess_d')
                            ->where('id', $itemId)
                            ->where(
                                'person_assess_h_id',
                                $assessmentId,
                            )
                            ->update([
                                'points' => $points,
                            ]);

                        $totalPoints += $points;
                    }

                    $database
                        ->table('person_assess_h')
                        ->where('id', $assessmentId)
                        ->update([
                            'date_assessed' => now()->toDateString(),
                            'total_pts' => $totalPoints,
                            'for_assess' => 'N',
                            'done' => 'Y',
                        ]);

                    $nextAssessmentId = $database
                        ->table('person_assess_h')
                        ->where('for_assess', 'Y')
                        ->where('id', '!=', $assessmentId)
                        ->orderByDesc('date_taken')
                        ->value('id');

                    return [
                        'total_points' => $totalPoints,
                        'next_assessment_id' =>
                            $nextAssessmentId
                                ? (string) $nextAssessmentId
                                : null,
                    ];
                },
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'The practical assessment could not be saved.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'message' =>
                'The practical assessment was graded successfully.',

            'data' => $result,
        ]);
    }

    /**
     * Resolve the database selected during login.
     */
    private function database(
        Request $request,
    ): ConnectionInterface {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        abort_if(
            $schoolCode === '',
            Response::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $school = config(
            "schools.schools.{$schoolCode}",
        );

        abort_unless(
            is_array($school),
            Response::HTTP_FORBIDDEN,
            'The selected school is not configured.',
        );

        $connection = $school['connection'] ?? null;

        abort_unless(
            is_string($connection) &&
            $connection !== '',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        abort_unless(
            is_array(
                config(
                    "database.connections.{$connection}",
                ),
            ),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is not configured.',
        );

        return DB::connection($connection);
    }

    /**
     * Retrieve rubric criteria and their available ratings.
     */
    private function rubricCriteria(
        ConnectionInterface $database,
        string $rubricsId,
    ) {
        $criteria = $database
            ->table('rubrics_criterion')
            ->where('rubrics_id', $rubricsId)
            ->orderBy('order_no')
            ->get([
                'id',
                'criterion_title',
                'criterion_desc',
                'order_no',
            ]);

        $criterionIds = $criteria
            ->pluck('id')
            ->filter()
            ->all();

        $options = empty($criterionIds)
            ? collect()
            : $database
                ->table('rubrics_criterion_item')
                ->whereIn(
                    'rubrics_criterion_id',
                    $criterionIds,
                )
                ->orderBy('item_order_no')
                ->get([
                    'id',
                    'rubrics_criterion_id',
                    'item_title',
                    'item_point',
                    'item_order_no',
                ])
                ->groupBy(
                    fn (object $item): string =>
                        (string) $item->rubrics_criterion_id,
                );

        return $criteria->map(
            function (object $criterion) use ($options): array {
                return [
                    'id' => (string) $criterion->id,
                    'title' => $criterion->criterion_title,
                    'description' => $criterion->criterion_desc,

                    'options' => collect(
                        $options->get(
                            (string) $criterion->id,
                            collect(),
                        ),
                    )->map(
                        fn (object $option): array => [
                            'id' => (string) $option->id,
                            'title' => $option->item_title,
                            'points' => (float) $option->item_point,
                        ],
                    )->values(),
                ];
            },
        )->values();
    }

    private function maximumPoints(
        string $gradeSystem,
        $items,
        $criteria,
    ): float {
        $normalized = strtolower(
            trim($gradeSystem),
        );

        if ($normalized === 'points') {
            return (float) $items->sum(
                fn (array $item): float =>
                    (float) $item['maximum_points'],
            );
        }

        if ($normalized === 'checklist') {
            return (float) $items->count();
        }

        $rubricMaximum = (float) $criteria->sum(
            function (array $criterion): float {
                return (float) collect(
                    $criterion['options'],
                )->max('points');
            },
        );

        return $rubricMaximum * $items->count();
    }

    private function studentName(object $student): string
    {
        $lastName = trim(
            (string) ($student->lname ?? ''),
        );

        $otherNames = collect([
            $student->fname ?? '',
            $student->mname ?? '',
        ])
            ->map(
                fn ($name): string =>
                    trim((string) $name),
            )
            ->filter()
            ->implode(' ');

        if ($lastName !== '' && $otherNames !== '') {
            return strtoupper(
                "{$lastName}, {$otherNames}",
            );
        }

        return strtoupper(
            $lastName ?: $otherNames,
        );
    }

    private function decodeLegacyText(
        mixed $value,
    ): string {
        $text = urldecode(
            (string) ($value ?? ''),
        );

        return str_replace(
            ['andxx', 'apostrophexx', '%0A'],
            ['&', "'", "\n"],
            $text,
        );
    }

    private function fileData(
        mixed $filename,
        string $routeName,
    ): ?array {
        $name = basename(
            trim((string) ($filename ?? '')),
        );

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'url' => $this->remoteFileUrl(
                $routeName,
                $name,
            ),
        ];
    }

    private function remoteFileUrl(
        string $routeName,
        mixed $filename,
    ): string {
        $name = basename(
            trim((string) ($filename ?? '')),
        );

        if ($name === '') {
            return '';
        }

        return route(
            $routeName,
            ['filename' => $name],
        );
    }
}