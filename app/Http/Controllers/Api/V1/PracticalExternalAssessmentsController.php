<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PracticalExternalAssessmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $database = $this->database($request);

        $perPage = (int) $request->integer('per_page', 10);

        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $search = trim(
            (string) $request->input('search', ''),
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
            'examinee_name' => 'assess_h_ext.lname',
            'email' => 'assess_h_ext.email',
            'title_assess' => 'p_assess_h.title_assess',
            'date_taken' => 'assess_h_ext.date_taken',
            'due_date' => 'assess_h_ext.due_date',
        ];

        $sortColumn = $sortableColumns[$sortField]
            ?? 'assess_h_ext.date_taken';

        $query = $database
            ->table('assess_h_ext')
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'assess_h_ext.p_assess_h_id',
            )
            ->where('assess_h_ext.for_assess', 'Y')
            ->select([
                'assess_h_ext.id',
                'assess_h_ext.p_assess_h_id',
                'assess_h_ext.email',
                'assess_h_ext.fname',
                'assess_h_ext.mname',
                'assess_h_ext.lname',
                'assess_h_ext.date_taken',
                'assess_h_ext.due_date',
                'assess_h_ext.date_assessed',
                'assess_h_ext.total_pts',
                'assess_h_ext.for_assess',
                'assess_h_ext.done',

                'p_assess_h.title_assess',
                'p_assess_h.grade_system',
                'p_assess_h.passing_mark',
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $builder) use ($search): void {
                    $value = "%{$search}%";

                    $builder
                        ->where(
                            'assess_h_ext.email',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'assess_h_ext.fname',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'assess_h_ext.mname',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'assess_h_ext.lname',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'p_assess_h.title_assess',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'p_assess_h.grade_system',
                            'like',
                            $value,
                        );
                },
            );
        }

        $paginator = $query
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();

        $records = collect($paginator->items())
            ->map(function (object $row): array {
                return [
                    'id' => (string) $row->id,

                    'examinee_name' => $this->examineeName(
                        $row,
                    ),

                    'email' => trim(
                        (string) ($row->email ?? ''),
                    ),

                    'title_assess' =>
                        $row->title_assess
                        ?: 'Untitled Practical Assessment',

                    'grade_system' =>
                        $row->grade_system
                        ?: 'Checklist',

                    'date_taken' => $row->date_taken,
                    'due_date' => $row->due_date,
                    'date_assessed' => $row->date_assessed,

                    'total_points' => (float) (
                        $row->total_pts ?? 0
                    ),

                    'is_completed' =>
                        strtoupper(
                            (string) $row->done,
                        ) === 'Y',

                    'is_pending' =>
                        strtoupper(
                            (string) $row->for_assess,
                        ) === 'Y',
                ];
            })
            ->values();

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

    public function show(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $database = $this->database($request);

        $assessment = $database
            ->table('assess_h_ext')
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'assess_h_ext.p_assess_h_id',
            )
            ->where('assess_h_ext.id', $assessmentId)
            ->select([
                'assess_h_ext.*',
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
            'The selected external practical assessment was not found.',
        );

        $items = $database
            ->table('assess_d_ext')
            ->leftJoin(
                'p_assess_d',
                'p_assess_d.id',
                '=',
                'assess_d_ext.assess_d_id',
            )
            ->where(
                'assess_d_ext.assess_h_ext_id',
                $assessmentId,
            )
            ->orderBy('p_assess_d.prio')
            ->select([
                'assess_d_ext.id',
                'assess_d_ext.assess_d_id',
                'assess_d_ext.remarks',
                'assess_d_ext.filename_d',
                'assess_d_ext.points',

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
                        trim(
                            (string) $file->assess_h_file,
                        ),
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

        $normalizedItems = $items
            ->map(function (object $item): array {
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
            })
            ->values();

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

                'examinee' => [
                    'name' => $this->examineeName(
                        $assessment,
                    ),

                    'email' => trim(
                        (string) (
                            $assessment->email ?? ''
                        ),
                    ),
                ],

                'title' =>
                    $assessment->title_assess
                    ?: 'Untitled Practical Assessment',

                'instructions' => $this->decodeLegacyText(
                    $assessment->instruction_assess,
                ),

                'grade_system' =>
                    $assessment->grade_system
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

                    'remarks' =>
                        $percentage >= $passingMark
                            ? 'PASS'
                            : 'FAIL',
                ],
            ],
        ]);
    }

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
                        ->table('assess_h_ext')
                        ->join(
                            'p_assess_h',
                            'p_assess_h.id',
                            '=',
                            'assess_h_ext.p_assess_h_id',
                        )
                        ->where(
                            'assess_h_ext.id',
                            $assessmentId,
                        )
                        ->lockForUpdate()
                        ->select([
                            'assess_h_ext.id',
                            'assess_h_ext.done',
                            'assess_h_ext.for_assess',
                            'p_assess_h.grade_system',
                            'p_assess_h.rubrics_id',
                        ])
                        ->first();

                    abort_unless(
                        $assessment !== null,
                        Response::HTTP_NOT_FOUND,
                        'The selected external practical assessment was not found.',
                    );

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
                        ->table('assess_d_ext')
                        ->leftJoin(
                            'p_assess_d',
                            'p_assess_d.id',
                            '=',
                            'assess_d_ext.assess_d_id',
                        )
                        ->where(
                            'assess_d_ext.assess_h_ext_id',
                            $assessmentId,
                        )
                        ->lockForUpdate()
                        ->select([
                            'assess_d_ext.id',
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

                    $submittedItemIds = $submittedGrades
                        ->keys()
                        ->sort()
                        ->values();

                    $actualItemIds = $items
                        ->keys()
                        ->sort()
                        ->values();

                    if (
                        $submittedItemIds->all() !==
                        $actualItemIds->all()
                    ) {
                        throw ValidationException::withMessages([
                            'grades' => [
                                'The submitted grading items do not match this assessment.',
                            ],
                        ]);
                    }

                    $gradeSystem = strtolower(
                        trim(
                            (string) $assessment->grade_system,
                        ),
                    );

                    $rubricOptions = collect();

                    if ($gradeSystem === 'rubrics') {
                        $rubricOptions = $database
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
                                fn (object $option): string =>
                                    (string) $option->id,
                            );
                    }

                    $totalPoints = 0.0;

                    foreach ($items as $itemId => $item) {
                        $submitted = $submittedGrades->get(
                            $itemId,
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
                                        "An item grade cannot exceed {$maximum} points.",
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
                                $submitted[
                                    'rubric_selections'
                                ] ?? [],
                            );

                            $criterionIds = [];

                            foreach (
                                $selections
                                as $criterionId => $optionId
                            ) {
                                if (! $optionId) {
                                    continue;
                                }

                                $rubricOption = $rubricOptions->get(
                                    (string) $optionId,
                                );

                                if (
                                    $rubricOption === null ||
                                    (string) $rubricOption
                                        ->rubrics_criterion_id !==
                                        (string) $criterionId
                                ) {
                                    throw ValidationException::withMessages([
                                        'grades' => [
                                            'An invalid rubric option was submitted.',
                                        ],
                                    ]);
                                }

                                if (
                                    in_array(
                                        (string) $criterionId,
                                        $criterionIds,
                                        true,
                                    )
                                ) {
                                    throw ValidationException::withMessages([
                                        'grades' => [
                                            'Only one rating can be selected for each rubric criterion.',
                                        ],
                                    ]);
                                }

                                $criterionIds[] =
                                    (string) $criterionId;

                                $points += (float) (
                                    $rubricOption->item_point ?? 0
                                );
                            }
                        }

                        $database
                            ->table('assess_d_ext')
                            ->where('id', $itemId)
                            ->where(
                                'assess_h_ext_id',
                                $assessmentId,
                            )
                            ->update([
                                'points' => $points,
                            ]);

                        $totalPoints += $points;
                    }

                    $database
                        ->table('assess_h_ext')
                        ->where('id', $assessmentId)
                        ->update([
                            'date_assessed' =>
                                now()->toDateString(),

                            'total_pts' => $totalPoints,
                            'for_assess' => 'N',
                            'done' => 'Y',
                        ]);

                    $nextAssessmentId = $database
                        ->table('assess_h_ext')
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
                    'The external practical assessment could not be saved.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'message' =>
                'The external practical assessment was graded successfully.',

            'data' => $result,
        ]);
    }

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

    private function rubricCriteria(
        ConnectionInterface $database,
        string $rubricsId,
    ): Collection {
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
                    fn (object $option): string =>
                        (string) $option
                            ->rubrics_criterion_id,
                );

        return $criteria
            ->map(
                function (
                    object $criterion,
                ) use ($options): array {
                    return [
                        'id' => (string) $criterion->id,
                        'title' =>
                            $criterion->criterion_title,
                        'description' =>
                            $criterion->criterion_desc,

                        'options' => collect(
                            $options->get(
                                (string) $criterion->id,
                                collect(),
                            ),
                        )
                            ->map(
                                fn (object $option): array => [
                                    'id' =>
                                        (string) $option->id,

                                    'title' =>
                                        $option->item_title,

                                    'points' =>
                                        (float) $option
                                            ->item_point,
                                ],
                            )
                            ->values(),
                    ];
                },
            )
            ->values();
    }

    private function maximumPoints(
        string $gradeSystem,
        Collection $items,
        Collection $criteria,
    ): float {
        $system = strtolower(
            trim($gradeSystem),
        );

        if ($system === 'points') {
            return (float) $items->sum(
                fn (array $item): float =>
                    (float) $item[
                        'maximum_points'
                    ],
            );
        }

        if ($system === 'checklist') {
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

    private function examineeName(
        object $record,
    ): string {
        $lastName = trim(
            (string) ($record->lname ?? ''),
        );

        $otherNames = collect([
            $record->fname ?? '',
            $record->mname ?? '',
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