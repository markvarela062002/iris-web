<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TheoreticalBatchController extends Controller
{
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
            (string) $request->input('search', ''),
        );

        $sortField = (string) $request->input(
            'sort_field',
            'last_update',
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
            'last_update' => 'bs_person_exam_batch.last_update',
            'name_course' => 'bs_course.name_course',
            'session_code' => 'bs_exam_session.session_code',
            'proctor_name' => 'bs_person_exam_batch.proctor_name',
            'duration' => 'bs_person_exam_batch.duration',
        ];

        $sortColumn = $sortableColumns[$sortField]
            ?? 'bs_person_exam_batch.last_update';

        $examineeCounts = $database
            ->table('bs_person_exam_batch_d')
            ->select([
                'bs_person_exam_batch_id',
                DB::raw('COUNT(*) AS examinee_count'),
            ])
            ->groupBy('bs_person_exam_batch_id');

        $query = $database
            ->table('bs_person_exam_batch')
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam_batch.bs_course_id',
            )
            ->leftJoin(
                'bs_exam_session',
                'bs_exam_session.id',
                '=',
                'bs_person_exam_batch.bs_exam_session_id',
            )
            ->leftJoinSub(
                $examineeCounts,
                'examinee_counts',
                'examinee_counts.bs_person_exam_batch_id',
                '=',
                'bs_person_exam_batch.id',
            )
            ->select([
                'bs_person_exam_batch.id',
                'bs_person_exam_batch.bs_course_id',
                'bs_person_exam_batch.bs_exam_session_id',
                'bs_person_exam_batch.exam_type',
                'bs_person_exam_batch.duration',
                'bs_person_exam_batch.proctor_name',
                'bs_person_exam_batch.access_exp_date_from',
                'bs_person_exam_batch.access_exp_time_from',
                'bs_person_exam_batch.access_exp_date_to',
                'bs_person_exam_batch.access_exp_time_to',
                'bs_person_exam_batch.last_update',

                'bs_course.name_course',
                'bs_exam_session.session_code',

                DB::raw(
                    'COALESCE(examinee_counts.examinee_count, 0) AS examinee_count',
                ),
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $builder) use ($search): void {
                    $value = "%{$search}%";

                    $builder
                        ->where(
                            'bs_course.name_course',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'bs_exam_session.session_code',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'bs_person_exam_batch.proctor_name',
                            'like',
                            $value,
                        )
                        ->orWhere(
                            'bs_person_exam_batch.exam_type',
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
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'bs_course_id' => (string) $row->bs_course_id,
                'bs_exam_session_id' =>
                    (string) $row->bs_exam_session_id,

                'name_course' =>
                    $row->name_course ?: 'No Exam Package',

                'session_code' =>
                    $row->session_code ?: 'No Exam Session',

                'exam_type' => $row->exam_type ?: 'New',
                'duration' => (int) ($row->duration ?? 0),

                'proctor_name' => strtoupper(
                    trim(
                        (string) (
                            $row->proctor_name
                            ?: 'No Proctor'
                        ),
                    ),
                ),

                'access_exp_date_from' =>
                    $row->access_exp_date_from,

                'access_exp_time_from' =>
                    $row->access_exp_time_from,

                'access_exp_date_to' =>
                    $row->access_exp_date_to,

                'access_exp_time_to' =>
                    $row->access_exp_time_to,

                'last_update' => $row->last_update,
                'examinee_count' => (int) $row->examinee_count,
            ])
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

    public function options(Request $request): JsonResponse
    {
        $database = $this->database($request);

        $courses = $database
            ->table('bs_course')
            ->orderBy('name_course')
            ->get([
                'id',
                'name_course',
            ])
            ->map(function (object $course) use ($database): array {
                $duration = (int) $database
                    ->table('bs_topic')
                    ->where(
                        'bs_course_id',
                        $course->id,
                    )
                    ->sum('no_quest');

                return [
                    'id' => (string) $course->id,
                    'label' => $course->name_course,
                    'duration' => $duration,
                ];
            })
            ->values();

        $sessions = $database
            ->table('bs_exam_session')
            ->orderBy('session_code')
            ->get([
                'id',
                'session_code',
            ])
            ->map(fn (object $session): array => [
                'id' => (string) $session->id,
                'label' => $session->session_code,
            ])
            ->values();

        return response()->json([
            'data' => [
                'courses' => $courses,
                'sessions' => $sessions,
                'times' => $this->timeOptions(),
            ],
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $database = $this->database($request);

        $validated = $request->validate([
            'search' => ['required', 'string', 'min:1', 'max:100'],
            'bs_course_id' => [
                'required',
                'string',
                'max:100',
            ],
            'bs_exam_session_id' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $search = trim($validated['search']);

        $students = $database
            ->table('person')
            ->where(
                function (Builder $builder) use ($search): void {
                    $value = "%{$search}%";

                    $builder
                        ->where('lname', 'like', $value)
                        ->orWhere('fname', 'like', $value)
                        ->orWhere('mname', 'like', $value)
                        ->orWhere('school_id_no', 'like', $value)
                        ->orWhere('code_person', 'like', $value);
                },
            )
            ->orderBy('lname')
            ->limit(20)
            ->get([
                'id',
                'code_person',
                'school_id_no',
                'fname',
                'mname',
                'lname',
                'email',
                'dept',
                'gender',
            ])
            ->map(function (object $student) use (
                $database,
                $validated,
            ): array {
                $hasPendingExam = $database
                    ->table('bs_person_exam')
                    ->where(
                        'bs_course_id',
                        $validated['bs_course_id'],
                    )
                    ->where(
                        'bs_exam_session_id',
                        $validated['bs_exam_session_id'],
                    )
                    ->where('person_id', $student->id)
                    ->where(
                        function (Builder $builder): void {
                            $builder
                                ->whereNull('started')
                                ->orWhere('started', '');
                        },
                    )
                    ->exists();

                return [
                    'id' => (string) $student->id,
                    'name' => $this->studentName($student),

                    'school_id_no' =>
                        $student->school_id_no
                        ?: $student->code_person,

                    'email' => trim(
                        (string) ($student->email ?? ''),
                    ),

                    'dept' => $student->dept,
                    'gender' => $student->gender,
                    'has_pending_exam' => $hasPendingExam,
                ];
            })
            ->values();

        return response()->json([
            'data' => $students,
        ]);
    }

    public function show(
        Request $request,
        string $batchId,
    ): JsonResponse {
        $database = $this->database($request);

        $batch = $database
            ->table('bs_person_exam_batch')
            ->where('id', $batchId)
            ->first();

        abort_unless(
            $batch !== null,
            Response::HTTP_NOT_FOUND,
            'The selected examination batch was not found.',
        );

        $students = $database
            ->table('bs_person_exam_batch_d')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'bs_person_exam_batch_d.person_id',
            )
            ->where(
                'bs_person_exam_batch_d.bs_person_exam_batch_id',
                $batchId,
            )
            ->orderBy('person.lname')
            ->get([
                'bs_person_exam_batch_d.id',
                'bs_person_exam_batch_d.person_id',
                'bs_person_exam_batch_d.bs_person_exam_id',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.email',
                'person.dept',
                'person.gender',
            ])
            ->map(fn (object $student): array => [
                'detail_id' => (string) $student->id,
                'id' => (string) $student->person_id,
                'exam_id' => (string) $student->bs_person_exam_id,
                'name' => $this->studentName($student),

                'school_id_no' =>
                    $student->school_id_no
                    ?: $student->code_person,

                'email' => trim(
                    (string) ($student->email ?? ''),
                ),

                'dept' => $student->dept,
                'gender' => $student->gender,
            ])
            ->values();

        return response()->json([
            'data' => [
                'id' => (string) $batch->id,
                'bs_course_id' => (string) $batch->bs_course_id,

                'bs_exam_session_id' =>
                    (string) $batch->bs_exam_session_id,

                'duration' => (int) ($batch->duration ?? 0),
                'exam_type' => $batch->exam_type ?: 'New',

                'proctor_name' =>
                    $batch->proctor_name ?? '',

                'access_exp_date_from' =>
                    $batch->access_exp_date_from,

                'access_exp_time_from' =>
                    substr(
                        (string) $batch->access_exp_time_from,
                        0,
                        5,
                    ),

                'access_exp_date_to' =>
                    $batch->access_exp_date_to,

                'access_exp_time_to' =>
                    substr(
                        (string) $batch->access_exp_time_to,
                        0,
                        5,
                    ),

                'students' => $students,

                /*
                 * A saved batch has already generated its exams.
                 * Its student membership must no longer be changed.
                 */
                'students_locked' => true,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $validated = $this->validateBatch($request);

        $studentIds = collect($validated['student_ids'])
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            throw ValidationException::withMessages([
                'student_ids' => [
                    'Add at least one examinee before saving the batch.',
                ],
            ]);
        }

        if ($studentIds->count() > 40) {
            throw ValidationException::withMessages([
                'student_ids' => [
                    'The number of examinees is limited to 40 per batch.',
                ],
            ]);
        }

        $this->validateBatchReferences(
            $database,
            $validated,
            $studentIds->all(),
        );

        $batchId = (string) Str::uuid();
        $loginId = (string) ($request->user()?->getAuthIdentifier() ?? '');
        $now = now();
        $emailRecipients = [];

        try {
            $database->transaction(
                function () use (
                    $database,
                    $validated,
                    $studentIds,
                    $batchId,
                    $loginId,
                    $now,
                    &$emailRecipients,
                ): void {
                    $topics = $database
                        ->table('bs_topic')
                        ->where(
                            'bs_course_id',
                            $validated['bs_course_id'],
                        )
                        ->orderBy('order_no')
                        ->get([
                            'id',
                            'no_quest',
                            'order_no',
                        ]);

                    $automaticDuration = (int) $topics->sum(
                        fn (object $topic): int =>
                            (int) ($topic->no_quest ?? 0),
                    );

                    $duration = (int) $validated['duration'];

                    if ($duration === 0) {
                        $duration = $automaticDuration;
                    }

                    $database
                        ->table('bs_person_exam_batch')
                        ->insert([
                            'id' => $batchId,
                            'bs_course_id' =>
                                $validated['bs_course_id'],

                            'bs_exam_session_id' =>
                                $validated['bs_exam_session_id'],

                            'exam_type' => 'New',
                            'duration' => $duration,

                            'proctor_name' =>
                                $validated['proctor_name'],

                            'access_exp_date_from' =>
                                $validated['access_exp_date_from'],

                            'access_exp_time_from' =>
                                $validated['access_exp_time_from'],

                            'access_exp_date_to' =>
                                $validated['access_exp_date_to'],

                            'access_exp_time_to' =>
                                $validated['access_exp_time_to'],

                            'login_id' => $loginId,
                            'last_update' => $now,
                        ]);

                    $courseName = (string) $database
                        ->table('bs_course')
                        ->where(
                            'id',
                            $validated['bs_course_id'],
                        )
                        ->value('name_course');

                    $students = $database
                        ->table('person')
                        ->whereIn('id', $studentIds->all())
                        ->get([
                            'id',
                            'fname',
                            'mname',
                            'lname',
                            'email',
                        ])
                        ->keyBy(
                            fn (object $student): string =>
                                (string) $student->id,
                        );

                    foreach ($studentIds as $studentId) {
                        $examId = (string) Str::uuid();
                        $student = $students->get($studentId);

                        $database
                            ->table('bs_person_exam_batch_d')
                            ->insert([
                                'id' => (string) Str::uuid(),
                                'person_id' => $studentId,

                                'bs_person_exam_batch_id' =>
                                    $batchId,

                                'bs_person_exam_id' => $examId,
                            ]);

                        $database
                            ->table('bs_person_exam')
                            ->insert([
                                'id' => $examId,

                                'bs_course_id' =>
                                    $validated['bs_course_id'],

                                'person_id' => $studentId,
                                'started' => '',
                                'ended' => '',
                                'score' => 0,
                                'passed' => '',

                                'access_exp_date' =>
                                    $validated['access_exp_date_from'],

                                'access_exp_time' =>
                                    $validated['access_exp_time_from'],

                                'access_exp_date_to' =>
                                    $validated['access_exp_date_to'],

                                'access_exp_time_to' =>
                                    $validated['access_exp_time_to'],

                                'or_no' => '',
                                'amount_paid' => 0,
                                'payment_date' => '1970-01-01',

                                'proctor_name' =>
                                    $validated['proctor_name'],

                                'login_id' => $loginId,
                                'last_update' => $now,
                                'duration' => $duration,
                                'exam_type' => 'New',
                                'exam_permit_no' => '',
                                'date_issued' => $now->toDateString(),
                                'issued_by' => '',

                                'bs_exam_session_id' =>
                                    $validated['bs_exam_session_id'],
                            ]);

                        foreach ($topics as $topic) {
                            $database
                                ->table('bs_person_exam_topic')
                                ->insert([
                                    'id' => (string) Str::uuid(),

                                    'bs_person_exam_id' =>
                                        $examId,

                                    'bs_topic_id' => $topic->id,

                                    'quest_cnt' => (int) (
                                        $topic->no_quest ?? 0
                                    ),

                                    'order_no' => $topic->order_no,
                                ]);
                        }

                        $database
                            ->table('person')
                            ->where('id', $studentId)
                            ->update([
                                'exam_id' =>
                                    $validated['bs_course_id'],

                                'access_exp' =>
                                    $validated['access_exp_date_to'].
                                    ' '.
                                    $validated['access_exp_time_to'],

                                'for_item' => 'Y',
                            ]);

                        if ($student !== null) {
                            $studentName = $this->studentName(
                                $student,
                            );

                            $content = $this->notificationContent(
                                $studentName,
                                $courseName,
                                $validated,
                            );

                            $database
                                ->table('inbox')
                                ->insert([
                                    'id' => (string) Str::uuid(),

                                    'subj_inbox' =>
                                        'Scheduled Theoretical Assessment on IRIS-SAM',

                                    'date_inbox' => $now,
                                    'date_read' => '',
                                    'recipient_type' => 'Cadet',
                                    'recipient_id' => $studentId,
                                    'sender_id' => $loginId,
                                    'content_inbox' => $content,
                                    'login_id' => $loginId,
                                    'last_update' => $now,
                                    'draft' => 'N',
                                ]);

                            $email = trim(
                                (string) (
                                    $student->email ?? ''
                                ),
                            );

                            if (
                                $email !== '' &&
                                filter_var(
                                    $email,
                                    FILTER_VALIDATE_EMAIL,
                                )
                            ) {
                                $emailRecipients[] = [
                                    'email' => $email,
                                    'name' => $studentName,
                                    'content' => $content,
                                ];
                            }
                        }
                    }
                },
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'The theoretical assessment batch could not be created.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $emailsSent = $this->sendEmails($emailRecipients);

        return response()->json([
            'message' =>
                "Batch created successfully. {$emailsSent} email(s) sent.",

            'data' => [
                'id' => $batchId,
                'emails_sent' => $emailsSent,
                'examinee_count' => $studentIds->count(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        string $batchId,
    ): JsonResponse {
        $database = $this->database($request);

        $validated = $request->validate([
            'duration' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],
            'proctor_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'access_exp_date_from' => [
                'required',
                'date_format:Y-m-d',
            ],
            'access_exp_time_from' => [
                'required',
                'date_format:H:i',
            ],
            'access_exp_date_to' => [
                'required',
                'date_format:Y-m-d',
            ],
            'access_exp_time_to' => [
                'required',
                'date_format:H:i',
            ],
        ]);

        $this->validateDateRange($validated);

        $batch = $database
            ->table('bs_person_exam_batch')
            ->where('id', $batchId)
            ->first();

        abort_unless(
            $batch !== null,
            Response::HTTP_NOT_FOUND,
            'The selected examination batch was not found.',
        );

        $database
            ->table('bs_person_exam_batch')
            ->where('id', $batchId)
            ->update([
                'duration' => $validated['duration'],
                'proctor_name' => $validated['proctor_name'] ?? '',

                'access_exp_date_from' =>
                    $validated['access_exp_date_from'],

                'access_exp_time_from' =>
                    $validated['access_exp_time_from'],

                'access_exp_date_to' =>
                    $validated['access_exp_date_to'],

                'access_exp_time_to' =>
                    $validated['access_exp_time_to'],

                'last_update' => now(),
            ]);

        return response()->json([
            'message' => 'Batch updated successfully.',
        ]);
    }

    private function validateBatch(Request $request): array
    {
        $validated = $request->validate([
            'bs_course_id' => [
                'required',
                'string',
                'max:100',
            ],
            'bs_exam_session_id' => [
                'required',
                'string',
                'max:100',
            ],
            'duration' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],
            'proctor_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'access_exp_date_from' => [
                'required',
                'date_format:Y-m-d',
            ],
            'access_exp_time_from' => [
                'required',
                'date_format:H:i',
            ],
            'access_exp_date_to' => [
                'required',
                'date_format:Y-m-d',
            ],
            'access_exp_time_to' => [
                'required',
                'date_format:H:i',
            ],
            'student_ids' => [
                'required',
                'array',
                'min:1',
                'max:40',
            ],
            'student_ids.*' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $this->validateDateRange($validated);

        return $validated;
    }

    private function validateDateRange(array $validated): void
    {
        $from = strtotime(
            $validated['access_exp_date_from'].
            ' '.
            $validated['access_exp_time_from'],
        );

        $to = strtotime(
            $validated['access_exp_date_to'].
            ' '.
            $validated['access_exp_time_to'],
        );

        if ($from === false || $to === false || $from > $to) {
            throw ValidationException::withMessages([
                'access_exp_date_to' => [
                    'The ending date and time must be after the starting date and time.',
                ],
            ]);
        }
    }

    private function validateBatchReferences(
        ConnectionInterface $database,
        array $validated,
        array $studentIds,
    ): void {
        $courseExists = $database
            ->table('bs_course')
            ->where('id', $validated['bs_course_id'])
            ->exists();

        if (! $courseExists) {
            throw ValidationException::withMessages([
                'bs_course_id' => [
                    'The selected exam package is invalid.',
                ],
            ]);
        }

        $sessionExists = $database
            ->table('bs_exam_session')
            ->where(
                'id',
                $validated['bs_exam_session_id'],
            )
            ->exists();

        if (! $sessionExists) {
            throw ValidationException::withMessages([
                'bs_exam_session_id' => [
                    'The selected exam session is invalid.',
                ],
            ]);
        }

        $studentCount = $database
            ->table('person')
            ->whereIn('id', $studentIds)
            ->count();

        if ($studentCount !== count($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => [
                    'One or more selected students are invalid.',
                ],
            ]);
        }

        $pendingStudent = $database
            ->table('bs_person_exam')
            ->where(
                'bs_course_id',
                $validated['bs_course_id'],
            )
            ->where(
                'bs_exam_session_id',
                $validated['bs_exam_session_id'],
            )
            ->whereIn('person_id', $studentIds)
            ->where(
                function (Builder $builder): void {
                    $builder
                        ->whereNull('started')
                        ->orWhere('started', '');
                },
            )
            ->value('person_id');

        if ($pendingStudent) {
            throw ValidationException::withMessages([
                'student_ids' => [
                    'A selected student already has a pending exam with the same package and session.',
                ],
            ]);
        }
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

        return DB::connection($connection);
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

    private function notificationContent(
        string $studentName,
        string $courseName,
        array $validated,
    ): string {
        $until = date(
            'M d, Y',
            strtotime(
                $validated['access_exp_date_to'],
            ),
        );

        $loginUrl = config('app.url');

        return implode('', [
            'Hi '.e($studentName).',<br><br>',
            'You have a scheduled Theoretical Assessment ',
            'with the following details:<br><br>',
            'Exam to take: <b>'.e($courseName).'</b><br>',
            'You have until: <b>',
            e($until.' '.$validated['access_exp_time_to']),
            '</b> to take the exam.<br><br>',
            'Login to your IRIS-SAM account here: <b>',
            e((string) $loginUrl),
            '</b>',
        ]);
    }

    private function sendEmails(array $recipients): int
    {
        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::html(
                    $recipient['content'],
                    function ($message) use ($recipient): void {
                        $message
                            ->to(
                                $recipient['email'],
                                $recipient['name'],
                            )
                            ->subject(
                                'You have a scheduled Theoretical Assessment on IRIS-SAM',
                            );
                    },
                );

                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    private function timeOptions(): array
    {
        $times = [];

        for ($minutes = 480; $minutes <= 1410; $minutes += 30) {
            $hours = intdiv($minutes, 60);
            $remaining = $minutes % 60;

            $times[] = sprintf(
                '%02d:%02d',
                $hours,
                $remaining,
            );
        }

        return $times;
    }
}