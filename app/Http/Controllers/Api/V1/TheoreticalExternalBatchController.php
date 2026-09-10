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

class TheoreticalExternalBatchController extends Controller
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
            'last_update' =>
                'bs_person_exam_ext_batch.last_update',

            'name_course' =>
                'bs_course.name_course',

            'session_code' =>
                'bs_exam_session.session_code',

            'proctor_name' =>
                'bs_person_exam_ext_batch.proctor_name',

            'duration' =>
                'bs_person_exam_ext_batch.duration',
        ];

        $sortColumn = $sortableColumns[$sortField]
            ?? 'bs_person_exam_ext_batch.last_update';

        $examineeCounts = $database
            ->table('bs_person_exam_ext_batch_d')
            ->select([
                'bs_person_exam_ext_batch_id',

                DB::raw(
                    'COUNT(*) AS examinee_count',
                ),
            ])
            ->groupBy(
                'bs_person_exam_ext_batch_id',
            );

        $query = $database
            ->table('bs_person_exam_ext_batch')
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam_ext_batch.bs_course_id',
            )
            ->leftJoin(
                'bs_exam_session',
                'bs_exam_session.id',
                '=',
                'bs_person_exam_ext_batch.bs_exam_session_id',
            )
            ->leftJoinSub(
                $examineeCounts,
                'examinee_counts',
                'examinee_counts.bs_person_exam_ext_batch_id',
                '=',
                'bs_person_exam_ext_batch.id',
            )
            ->select([
                'bs_person_exam_ext_batch.id',
                'bs_person_exam_ext_batch.bs_course_id',
                'bs_person_exam_ext_batch.bs_exam_session_id',
                'bs_person_exam_ext_batch.exam_type',
                'bs_person_exam_ext_batch.duration',
                'bs_person_exam_ext_batch.proctor_name',
                'bs_person_exam_ext_batch.access_exp_date_from',
                'bs_person_exam_ext_batch.access_exp_time_from',
                'bs_person_exam_ext_batch.access_exp_date_to',
                'bs_person_exam_ext_batch.access_exp_time_to',
                'bs_person_exam_ext_batch.last_update',

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
                            'bs_person_exam_ext_batch.proctor_name',
                            'like',
                            $value,
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
            fn (object $row): array => [
                'id' => (string) $row->id,

                'name_course' =>
                    $row->name_course
                    ?: 'No Exam Package',

                'session_code' =>
                    $row->session_code
                    ?: 'No Exam Session',

                'exam_type' =>
                    $row->exam_type ?: 'New',

                'duration' => (int) (
                    $row->duration ?? 0
                ),

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

                'last_update' =>
                    $row->last_update,

                'examinee_count' => (int) (
                    $row->examinee_count ?? 0
                ),
            ],
        )->values();

        return response()->json([
            'data' => $records,

            'meta' => [
                'currentPage' =>
                    $paginator->currentPage(),

                'lastPage' =>
                    $paginator->lastPage(),

                'perPage' =>
                    $paginator->perPage(),

                'total' =>
                    $paginator->total(),

                'from' =>
                    $paginator->firstItem(),

                'to' =>
                    $paginator->lastItem(),
            ],

            'links' => [
                'first' => $paginator->url(1),

                'last' => $paginator->url(
                    $paginator->lastPage(),
                ),

                'previous' =>
                    $paginator->previousPageUrl(),

                'next' =>
                    $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function options(
        Request $request,
    ): JsonResponse {
        $database = $this->database($request);

        $courses = $database
            ->table('bs_course')
            ->orderBy('name_course')
            ->get([
                'id',
                'name_course',
            ])
            ->map(
                function (object $course) use (
                    $database,
                ): array {
                    return [
                        'id' => (string) $course->id,
                        'label' => $course->name_course,

                        'duration' => (int) $database
                            ->table('bs_topic')
                            ->where(
                                'bs_course_id',
                                $course->id,
                            )
                            ->sum('no_quest'),
                    ];
                },
            )
            ->values();

        $sessions = $database
            ->table('bs_exam_session')
            ->orderBy('session_code')
            ->get([
                'id',
                'session_code',
            ])
            ->map(
                fn (object $session): array => [
                    'id' => (string) $session->id,
                    'label' => $session->session_code,
                ],
            )
            ->values();

        return response()->json([
            'data' => [
                'courses' => $courses,
                'sessions' => $sessions,
                'times' => $this->timeOptions(),
            ],
        ]);
    }

    public function show(
        Request $request,
        string $batchId,
    ): JsonResponse {
        $database = $this->database($request);

        $batch = $database
            ->table('bs_person_exam_ext_batch')
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam_ext_batch.bs_course_id',
            )
            ->leftJoin(
                'bs_exam_session',
                'bs_exam_session.id',
                '=',
                'bs_person_exam_ext_batch.bs_exam_session_id',
            )
            ->where(
                'bs_person_exam_ext_batch.id',
                $batchId,
            )
            ->select([
                'bs_person_exam_ext_batch.*',
                'bs_course.name_course',
                'bs_exam_session.session_code',
            ])
            ->first();

        abort_unless(
            $batch !== null,
            Response::HTTP_NOT_FOUND,
            'The selected External batch was not found.',
        );

        $examinees = $database
            ->table('bs_person_exam_ext_batch_d')
            ->where(
                'bs_person_exam_ext_batch_id',
                $batchId,
            )
            ->orderBy('email')
            ->orderBy('lname')
            ->get([
                'id',
                'bs_person_exam_ext_id',
                'email',
                'fname',
                'mname',
                'lname',
                'email_sent',
            ])
            ->map(
                fn (object $examinee): array => [
                    'id' => (string) $examinee->id,

                    'exam_id' =>
                        (string) $examinee
                            ->bs_person_exam_ext_id,

                    'email' => strtolower(
                        trim(
                            (string) $examinee->email,
                        ),
                    ),

                    'fname' =>
                        trim(
                            (string) $examinee->fname,
                        ),

                    'mname' =>
                        trim(
                            (string) $examinee->mname,
                        ),

                    'lname' =>
                        trim(
                            (string) $examinee->lname,
                        ),

                    'name' => $this->examineeName(
                        $examinee,
                    ),

                    'email_sent' =>
                        strtoupper(
                            (string) $examinee
                                ->email_sent,
                        ) === 'Y',
                ],
            )
            ->values();

        return response()->json([
            'data' => [
                'id' => (string) $batch->id,

                'bs_course_id' =>
                    (string) $batch->bs_course_id,

                'bs_exam_session_id' =>
                    (string) $batch
                        ->bs_exam_session_id,

                'name_course' =>
                    $batch->name_course,

                'session_code' =>
                    $batch->session_code,

                'exam_type' =>
                    $batch->exam_type ?: 'New',

                'duration' => (int) (
                    $batch->duration ?? 0
                ),

                'proctor_name' =>
                    $batch->proctor_name ?? '',

                'access_exp_date_from' =>
                    $batch->access_exp_date_from,

                'access_exp_time_from' =>
                    substr(
                        (string) $batch
                            ->access_exp_time_from,
                        0,
                        5,
                    ),

                'access_exp_date_to' =>
                    $batch->access_exp_date_to,

                'access_exp_time_to' =>
                    substr(
                        (string) $batch
                            ->access_exp_time_to,
                        0,
                        5,
                    ),

                'examinees' => $examinees,
                'examinees_locked' => true,
            ],
        ]);
    }

    public function store(
        Request $request,
    ): JsonResponse {
        $database = $this->database($request);

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
                'max:100',
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

            'examinees' => [
                'required',
                'array',
                'min:1',
                'max:40',
            ],

            'examinees.*.email' => [
                'required',
                'email:rfc',
                'max:255',
            ],

            'examinees.*.fname' => [
                'nullable',
                'string',
                'max:100',
            ],

            'examinees.*.mname' => [
                'nullable',
                'string',
                'max:100',
            ],

            'examinees.*.lname' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $this->validateDateRange($validated);

        $examinees = collect(
            $validated['examinees'],
        )->map(
            fn (array $examinee): array => [
                'email' => strtolower(
                    trim($examinee['email']),
                ),

                'fname' => trim(
                    (string) (
                        $examinee['fname'] ?? ''
                    ),
                ),

                'mname' => trim(
                    (string) (
                        $examinee['mname'] ?? ''
                    ),
                ),

                'lname' => trim(
                    (string) (
                        $examinee['lname'] ?? ''
                    ),
                ),
            ],
        );

        if (
            $examinees->pluck('email')->unique()->count()
            !== $examinees->count()
        ) {
            throw ValidationException::withMessages([
                'examinees' => [
                    'The same email address cannot be added more than once.',
                ],
            ]);
        }

        $this->validateReferences(
            $database,
            $validated,
            $examinees->pluck('email')->all(),
        );

        $batchId = (string) Str::uuid();

        $loginId = (string) (
            $request->user()?->getAuthIdentifier()
            ?? ''
        );

        $now = now();
        $emailRecipients = [];

        try {
            $database->transaction(
                function () use (
                    $database,
                    $validated,
                    $examinees,
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

                    $automaticDuration = (int) $topics
                        ->sum(
                            fn (object $topic): int =>
                                (int) (
                                    $topic->no_quest ?? 0
                                ),
                        );

                    $duration = (int) $validated[
                        'duration'
                    ];

                    if ($duration === 0) {
                        $duration = $automaticDuration;
                    }

                    $database
                        ->table(
                            'bs_person_exam_ext_batch',
                        )
                        ->insert([
                            'id' => $batchId,

                            'bs_course_id' =>
                                $validated['bs_course_id'],

                            'bs_exam_session_id' =>
                                $validated[
                                    'bs_exam_session_id'
                                ],

                            'exam_type' => 'New',
                            'duration' => $duration,

                            'access_exp_date_from' =>
                                $validated[
                                    'access_exp_date_from'
                                ],

                            'access_exp_date_to' =>
                                $validated[
                                    'access_exp_date_to'
                                ],

                            'access_exp_time_from' =>
                                $validated[
                                    'access_exp_time_from'
                                ],

                            'access_exp_time_to' =>
                                $validated[
                                    'access_exp_time_to'
                                ],

                            'proctor_name' =>
                                $validated[
                                    'proctor_name'
                                ] ?? '',

                            'login_id' => $loginId,
                            'last_update' =>
                                $now->format(
                                    'Y-m-d H:i:s',
                                ),
                        ]);

                    $courseName = (string) $database
                        ->table('bs_course')
                        ->where(
                            'id',
                            $validated['bs_course_id'],
                        )
                        ->value('name_course');

                    foreach ($examinees as $examinee) {
                        $examId = (string) Str::uuid();
                        $detailId = (string) Str::uuid();

                        $database
                            ->table(
                                'bs_person_exam_ext_batch_d',
                            )
                            ->insert([
                                'id' => $detailId,

                                'bs_person_exam_ext_batch_id' =>
                                    $batchId,

                                'bs_person_exam_ext_id' =>
                                    $examId,

                                'email' =>
                                    $examinee['email'],

                                'fname' =>
                                    $examinee['fname'],

                                'mname' =>
                                    $examinee['mname'],

                                'lname' =>
                                    $examinee['lname'],

                                'email_sent' => 'N',
                            ]);

                        $database
                            ->table('bs_person_exam_ext')
                            ->insert([
                                'id' => $examId,

                                'bs_course_id' =>
                                    $validated[
                                        'bs_course_id'
                                    ],

                                'email' =>
                                    $examinee['email'],

                                'fname' =>
                                    $examinee['fname'],

                                'mname' =>
                                    $examinee['mname'],

                                'lname' =>
                                    $examinee['lname'],

                                'started' => '',
                                'ended' => '',
                                'score' => 0,
                                'passed' => '',

                                'access_exp_date' =>
                                    $validated[
                                        'access_exp_date_from'
                                    ],

                                'access_exp_time' =>
                                    $validated[
                                        'access_exp_time_from'
                                    ],

                                'access_exp_date_to' =>
                                    $validated[
                                        'access_exp_date_to'
                                    ],

                                'access_exp_time_to' =>
                                    $validated[
                                        'access_exp_time_to'
                                    ],

                                'or_no' => '',
                                'amount_paid' => 0,
                                'payment_date' =>
                                    '1970-01-01',

                                'proctor_name' =>
                                    $validated[
                                        'proctor_name'
                                    ] ?? '',

                                'login_id' => $loginId,

                                'last_update' =>
                                    $now->format(
                                        'Y-m-d H:i:s',
                                    ),

                                'duration' => $duration,
                                'exam_type' => 'New',
                                'exam_permit_no' => '',

                                'date_issued' =>
                                    $now->toDateString(),

                                'issued_by' => '',

                                'bs_exam_session_id' =>
                                    $validated[
                                        'bs_exam_session_id'
                                    ],
                            ]);

                        foreach ($topics as $topic) {
                            $database
                                ->table(
                                    'bs_person_exam_topic_ext',
                                )
                                ->insert([
                                    'id' =>
                                        (string) Str::uuid(),

                                    'bs_person_exam_id' =>
                                        $examId,

                                    'bs_topic_id' =>
                                        $this
                                            ->externalSourceTopicId(
                                                (string) $topic->id,
                                            ),

                                    'quest_cnt' => (int) (
                                        $topic->no_quest ?? 0
                                    ),

                                    'order_no' =>
                                        $topic->order_no,
                                ]);
                        }

                        $content = $this->emailContent(
                            $request,
                            $examinee,
                            $courseName,
                            $examId,
                            $validated,
                        );

                        $emailRecipients[] = [
                            'detail_id' => $detailId,
                            'email' => $examinee['email'],
                            'name' => $this->examineeName(
                                (object) $examinee,
                            ),
                            'content' => $content,
                        ];
                    }
                },
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'The External examination batch could not be created.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $emailResult = $this->sendEmails(
            $database,
            $emailRecipients,
        );

        return response()->json([
            'message' =>
                "Batch created successfully. {$emailResult['sent']} email(s) sent.",

            'data' => [
                'id' => $batchId,
                'examinee_count' =>
                    $examinees->count(),

                'emails_sent' =>
                    $emailResult['sent'],

                'emails_failed' =>
                    $emailResult['failed'],
            ],
        ], Response::HTTP_CREATED);
    }

    private function validateReferences(
        ConnectionInterface $database,
        array $validated,
        array $emails,
    ): void {
        $courseExists = $database
            ->table('bs_course')
            ->where(
                'id',
                $validated['bs_course_id'],
            )
            ->exists();

        if (! $courseExists) {
            throw ValidationException::withMessages([
                'bs_course_id' => [
                    'The selected Exam Package is invalid.',
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
                    'The selected Exam Session is invalid.',
                ],
            ]);
        }

        $pendingEmail = $database
            ->table('bs_person_exam_ext')
            ->where(
                'bs_course_id',
                $validated['bs_course_id'],
            )
            ->where(
                'bs_exam_session_id',
                $validated[
                    'bs_exam_session_id'
                ],
            )
            ->whereIn('email', $emails)
            ->where(
                function (Builder $builder): void {
                    $builder
                        ->whereNull('started')
                        ->orWhere('started', '');
                },
            )
            ->value('email');

        if ($pendingEmail) {
            throw ValidationException::withMessages([
                'examinees' => [
                    "{$pendingEmail} already has a pending exam with the same package and session.",
                ],
            ]);
        }
    }

    private function validateDateRange(
        array $validated,
    ): void {
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

        if (
            $from === false ||
            $to === false ||
            $from > $to
        ) {
            throw ValidationException::withMessages([
                'access_exp_date_to' => [
                    'The ending date and time must be after the starting date and time.',
                ],
            ]);
        }
    }

    private function externalSourceTopicId(
        string $topicId,
    ): string {
        return match ($topicId) {
            'd30a104a-6503-11eb-9cb9-42010a920007' =>
                '8e00dbbc-1f32-11ea-8c93-5d67f07b9a91',

            '6cf0d3f0-6504-11eb-9cb9-42010a920007' =>
                '6637a32c-1f32-11ea-8c93-5d67f07b9a91',

            default => $topicId,
        };
    }

    private function emailContent(
    Request $request,
    array $examinee,
    string $courseName,
    string $examId,
    array $validated,
): string {
    $schoolCode = strtoupper(
        trim(
            (string) $request
                ->session()
                ->get('school_code', ''),
        ),
    );

    $configuredUrl = trim(
        (string) config(
            "schools.schools.{$schoolCode}.theoretical_external_exam_url",
            '',
        ),
    );

    $baseUrl = rtrim(
        $configuredUrl !== ''
            ? $configuredUrl
            : (string) config('app.url'),
        '/',
    );

    $examUrl =
        "{$baseUrl}/theoretical-exam.php?id=".
        rawurlencode($examId);

    $name = $this->examineeName(
        (object) $examinee,
    );

    if ($name === '') {
        $name = 'Examinee';
    }

    $until = date(
        'M d, Y',
        strtotime(
            $validated[
                'access_exp_date_to'
            ],
        ),
    );

    return implode('', [
        '<html><body>',
        'Hi '.e($name).',<br><br>',
        'You have a scheduled Theoretical Assessment ',
        'with the following details:<br><br>',
        'Exam to take: <b>',
        e($courseName),
        '</b><br>',
        'You have until: <b>',
        e(
            $until.
            ' '.
            $validated[
                'access_exp_time_to'
            ],
        ),
        '</b> to take the exam.<br><br>',
        'Click this <a href="',
        e($examUrl),
        '" target="_blank" rel="noopener noreferrer">',
        'link</a> to start your exam.',
        '<br><br>',
        'If the link does not work, copy this URL:<br>',
        e($examUrl),
        '</body></html>',
    ]);
}

    private function sendEmails(
        ConnectionInterface $database,
        array $recipients,
    ): array {
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::html(
                    $recipient['content'],
                    function ($message) use (
                        $recipient,
                    ): void {
                        $message
                            ->to(
                                $recipient['email'],
                                $recipient['name'],
                            )
                            ->subject(
                                'You have a scheduled Theoretical Assessment',
                            );
                    },
                );

                $database
                    ->table(
                        'bs_person_exam_ext_batch_d',
                    )
                    ->where(
                        'id',
                        $recipient['detail_id'],
                    )
                    ->update([
                        'email_sent' => 'Y',
                    ]);

                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
        ];
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

        $connection = $school['connection']
            ?? null;

        abort_unless(
            is_string($connection) &&
            $connection !== '',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        return DB::connection($connection);
    }

    private function examineeName(
        object $examinee,
    ): string {
        $lastName = trim(
            (string) ($examinee->lname ?? ''),
        );

        $otherNames = collect([
            $examinee->fname ?? '',
            $examinee->mname ?? '',
        ])
            ->map(
                fn ($name): string =>
                    trim((string) $name),
            )
            ->filter()
            ->implode(' ');

        if (
            $lastName !== '' &&
            $otherNames !== ''
        ) {
            return strtoupper(
                "{$lastName}, {$otherNames}",
            );
        }

        return strtoupper(
            $lastName ?: $otherNames,
        );
    }

    private function timeOptions(): array
    {
        $times = [];

        for (
            $minutes = 480;
            $minutes <= 1410;
            $minutes += 30
        ) {
            $times[] = sprintf(
                '%02d:%02d',
                intdiv($minutes, 60),
                $minutes % 60,
            );
        }

        return $times;
    }
}