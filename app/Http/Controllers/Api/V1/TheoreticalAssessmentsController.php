<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TheoreticalAssessmentsController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return enrolled theoretical assessments.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $totalItems = $db
            ->table('bs_person_exam_topic')
            ->select([
                'bs_person_exam_id',
                DB::raw('SUM(quest_cnt) AS total_items'),
            ])
            ->groupBy('bs_person_exam_id');

        $query = $db
            ->table('bs_person_exam')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'bs_person_exam.person_id',
            )
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam.bs_course_id',
            )
            ->leftJoin(
                'bs_exam_session',
                'bs_exam_session.id',
                '=',
                'bs_person_exam.bs_exam_session_id',
            )
            ->leftJoinSub(
                $totalItems,
                'exam_totals',
                'exam_totals.bs_person_exam_id',
                '=',
                'bs_person_exam.id',
            )
            ->when(
                $request->filled('course_id'),
                fn ($query) => $query->where(
                    'bs_person_exam.bs_course_id',
                    $request->string('course_id')->trim()->toString(),
                ),
            )
            ->when(
                $request->filled('session_id'),
                fn ($query) => $query->where(
                    'bs_person_exam.bs_exam_session_id',
                    $request->string('session_id')->trim()->toString(),
                ),
            )
            ->when(
                $request->filled('person_id'),
                fn ($query) => $query->where(
                    'bs_person_exam.person_id',
                    $request->string('person_id')->trim()->toString(),
                ),
            )
            ->when(
                $request->filled('school_id_no'),
                fn ($query) => $query->where(
                    'person.school_id_no',
                    'like',
                    '%'.$request->string('school_id_no')->trim().'%',
                ),
            )
            ->when(
                $request->filled('date_from'),
                fn ($query) => $query->whereDate(
                    'bs_person_exam.access_exp_date',
                    '>=',
                    $request->string('date_from')->toString(),
                ),
            )
            ->when(
                $request->filled('date_to'),
                fn ($query) => $query->whereDate(
                    'bs_person_exam.access_exp_date',
                    '<=',
                    $request->string('date_to')->toString(),
                ),
            )
            ->select([
                'bs_person_exam.id',
                'bs_person_exam.person_id',
                'bs_person_exam.bs_course_id',
                'bs_person_exam.bs_exam_session_id',
                'bs_person_exam.exam_type',
                'bs_person_exam.proctor_name',
                'bs_person_exam.score',
                'bs_person_exam.done',
                'bs_person_exam.started',
                'bs_person_exam.ended',
                'bs_person_exam.access_exp_date',

                'person.school_id_no',
                'person.code_person',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',

                'bs_course.name_course',
                'bs_exam_session.session_code',

                DB::raw('COALESCE(exam_totals.total_items, 0) AS total_items'),
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'person.school_id_no',
                'person.code_person',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.dept',
                'bs_course.name_course',
                'bs_exam_session.session_code',
                'bs_person_exam.exam_type',
                'bs_person_exam.proctor_name',
            ],
            sortableColumns: [
                'started' => 'bs_person_exam.started',
                'access_exp_date' => 'bs_person_exam.access_exp_date',
                'school_id_no' => 'person.school_id_no',
                'student_name' => 'person.lname',
                'name_course' => 'bs_course.name_course',
                'session_code' => 'bs_exam_session.session_code',
                'exam_type' => 'bs_person_exam.exam_type',
                'proctor_name' => 'bs_person_exam.proctor_name',
                'score' => 'bs_person_exam.score',
                'done' => 'bs_person_exam.done',
            ],
            defaultSortColumn: 'started',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        $result['data'] = collect($result['data'] ?? [])
            ->map(function ($row): array {
                $record = is_object($row)
                    ? get_object_vars($row)
                    : (array) $row;

                $score = (float) ($record['score'] ?? 0);
                $totalItems = (int) ($record['total_items'] ?? 0);

                $record['score_percentage'] = $totalItems > 0
                    ? round(($score / $totalItems) * 100, 1)
                    : 0;

                $record['student_name'] = $this->formatStudentName(
                    $record,
                );

                $record['is_completed'] =
                    ($record['done'] ?? '') === 'Y';

                return $record;
            })
            ->values()
            ->all();

        return response()->json($result);
    }

    /**
     * Return course and session filter options.
     */
    public function options(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        return response()->json([
            'courses' => $db
                ->table('bs_course')
                ->select([
                    'id',
                    'name_course as label',
                ])
                ->orderBy('name_course')
                ->get(),

            'sessions' => $db
                ->table('bs_exam_session')
                ->select([
                    'id',
                    'session_code as label',
                ])
                ->orderByDesc('session_code')
                ->get(),
        ]);
    }

    /**
     * Search students for the PrimeVue AutoComplete.
     */
    public function students(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $search = trim(
            (string) $request->query('search', ''),
        );

        if (mb_strlen($search) < 2) {
            return response()->json([
                'data' => [],
            ]);
        }

        $students = $db
            ->table('person')
            ->where(function ($query) use ($search): void {
                $query
                    ->where('school_id_no', 'like', "%{$search}%")
                    ->orWhere('code_person', 'like', "%{$search}%")
                    ->orWhere('fname', 'like', "%{$search}%")
                    ->orWhere('mname', 'like', "%{$search}%")
                    ->orWhere('lname', 'like', "%{$search}%");
            })
            ->select([
                'id',
                'school_id_no',
                'code_person',
                'fname',
                'mname',
                'lname',
                'gender',
                'dept',
            ])
            ->orderBy('lname')
            ->limit(20)
            ->get()
            ->map(function ($student): array {
                $record = (array) $student;

                $record['student_name'] =
                    $this->formatStudentName($record);

                return $record;
            });

        return response()->json([
            'data' => $students,
        ]);
    }

    /**
     * Return the answers for one assessment.
     */
    public function show(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $assessment = $db
            ->table('bs_person_exam')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'bs_person_exam.person_id',
            )
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam.bs_course_id',
            )
            ->where('bs_person_exam.id', $assessmentId)
            ->select([
                'bs_person_exam.id',
                'bs_person_exam.exam_type',
                'bs_person_exam.started',
                'bs_person_exam.score',
                'bs_person_exam.done',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'bs_course.name_course',
            ])
            ->first();

        if (! $assessment) {
            return response()->json([
                'message' => 'The assessment could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $answers = $db
            ->table('bs_person_exam_topic_quest')
            ->join(
                'bs_person_exam_topic',
                'bs_person_exam_topic.id',
                '=',
                'bs_person_exam_topic_quest.bs_person_exam_topic_id',
            )
            ->leftJoin(
                'bs_quest',
                'bs_quest.id',
                '=',
                'bs_person_exam_topic_quest.bs_quest_id',
            )
            ->where(
                'bs_person_exam_topic.bs_person_exam_id',
                $assessmentId,
            )
            ->orderBy('bs_person_exam_topic.order_no')
            ->orderBy('bs_person_exam_topic_quest.id')
            ->select([
                'bs_person_exam_topic_quest.id',
                'bs_person_exam_topic_quest.answer',
                'bs_person_exam_topic_quest.correct_ans',
                'bs_quest.quest_text',
            ])
            ->get()
            ->map(function ($answer, int $index): array {
                return [
                    'index' => $index + 1,
                    'question' => $this->decodeText(
                        $answer->quest_text,
                    ),
                    'answer' => $answer->answer,
                    'correct_answer' => $answer->correct_ans,
                    'is_correct' =>
                        (string) $answer->answer ===
                        (string) $answer->correct_ans,
                ];
            });

        $assessment = (array) $assessment;
        $assessment['student_name'] =
            $this->formatStudentName($assessment);
        $assessment['total_items'] = $answers->count();

        return response()->json([
            'assessment' => $assessment,
            'answers' => $answers,
        ]);
    }

    /**
     * Download the completed assessment certificate using mPDF.
     */
    public function certificate(
        Request $request,
        string $assessmentId,
    ): StreamedResponse|JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $assessment = $db
            ->table('bs_person_exam')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'bs_person_exam.person_id',
            )
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam.bs_course_id',
            )
            ->where('bs_person_exam.id', $assessmentId)
            ->select([
                'bs_person_exam.*',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.dept',
                'person.school_id',
                'bs_course.name_course',
            ])
            ->first();

        if (! $assessment) {
            return response()->json([
                'message' => 'The assessment could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($assessment->done !== 'Y') {
            return response()->json([
                'message' =>
                    'A certificate is only available for a completed assessment.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $topics = $db
            ->table('bs_person_exam_topic')
            ->leftJoin(
                'bs_topic',
                'bs_topic.id',
                '=',
                'bs_person_exam_topic.bs_topic_id',
            )
            ->where(
                'bs_person_exam_topic.bs_person_exam_id',
                $assessmentId,
            )
            ->orderBy('bs_person_exam_topic.order_no')
            ->select([
                'bs_person_exam_topic.id',
                'bs_person_exam_topic.score',
                'bs_person_exam_topic.quest_cnt',
                'bs_person_exam_topic.started',
                'bs_person_exam_topic.passed',
                'bs_topic.desc_topic',
                'bs_topic.passing_mark',
                'bs_topic.no_quest',
            ])
            ->get()
            ->map(function ($topic) use ($db): array {
                $correctAnswers = $db
                    ->table('bs_person_exam_topic_quest')
                    ->where(
                        'bs_person_exam_topic_id',
                        $topic->id,
                    )
                    ->whereColumn('correct_ans', 'answer')
                    ->count();

                $totalQuestions = max(
                    (int) ($topic->no_quest ?: $topic->quest_cnt),
                    0,
                );

                return [
                    'description' =>
                        $this->decodeText($topic->desc_topic),
                    'rating' => $totalQuestions > 0
                        ? round(
                            ($correctAnswers / $totalQuestions) * 100,
                            1,
                        )
                        : 0,
                    'remarks' =>
                        (float) $topic->score >=
                        (float) $topic->passing_mark
                            ? 'PASS'
                            : 'FAIL',
                    'date_taken' => $topic->started,
                ];
            });

        $schoolCode = $this->resolveSchoolCode($request);
        $school = config(
            "schools.schools.{$schoolCode}",
            [],
        );

        $html = view(
            'theoretical-assessments.certificate',
            [
                'assessment' => $assessment,
                'topics' => $topics,
                'school' => $school,
            ],
        )->render();

        $filename = sprintf(
            'theoretical-assessment-%s.pdf',
            preg_replace(
                '/[^A-Za-z0-9_-]/',
                '-',
                $assessmentId,
            ),
        );

        return response()->streamDownload(
            function () use ($html): void {
                $pdf = new Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'orientation' => 'P',
                    'margin_left' => 12,
                    'margin_right' => 12,
                    'margin_top' => 12,
                    'margin_bottom' => 12,
                ]);

                $pdf->WriteHTML($html);

                echo $pdf->Output(
                    '',
                    Destination::STRING_RETURN,
                );
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ],
        );
    }

    private function formatStudentName(array $record): string
    {
        $lastName = trim((string) ($record['lname'] ?? ''));
        $firstName = trim((string) ($record['fname'] ?? ''));
        $middleName = trim((string) ($record['mname'] ?? ''));

        return trim(
            $lastName.', '.$firstName.' '.$middleName,
            " ,",
        );
    }

    private function decodeText(mixed $value): string
    {
        return str_replace(
            'andxx',
            '&',
            urldecode((string) $value),
        );
    }

/**
 * Schedule one enrolled student's theoretical assessment.
 */
public function store(Request $request): JsonResponse
{
    $db = $this->resolveSchoolConnection($request);

    if ($db instanceof JsonResponse) {
        return $db;
    }

    $data = $request->validate([
        'person_id' => ['required', 'string', 'max:100'],
        'bs_course_id' => ['required', 'string', 'max:100'],
        'bs_exam_session_id' => ['required', 'string', 'max:100'],
        'exam_type' => ['required', 'in:New,Resit'],
        'duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
        'proctor_name' => ['nullable', 'string', 'max:255'],
        'access_exp_date' => ['required', 'date_format:Y-m-d'],
        'access_exp_time' => ['required', 'date_format:H:i'],
        'access_exp_date_to' => ['required', 'date_format:Y-m-d'],
        'access_exp_time_to' => ['required', 'date_format:H:i'],
    ]);

    $start = strtotime(
        $data['access_exp_date'].' '.$data['access_exp_time']
    );
    $end = strtotime(
        $data['access_exp_date_to'].' '.$data['access_exp_time_to']
    );

    if ($start === false || $end === false || $end <= $start) {
        throw ValidationException::withMessages([
            'access_exp_date_to' => [
                'The access end date and time must be after the start date and time.',
            ],
        ]);
    }

    $student = $db->table('person')
        ->where('id', $data['person_id'])
        ->first(['id', 'fname', 'mname', 'lname', 'email']);

    if (! $student) {
        throw ValidationException::withMessages([
            'person_id' => ['The selected student is invalid.'],
        ]);
    }

    $course = $db->table('bs_course')
        ->where('id', $data['bs_course_id'])
        ->first(['id', 'name_course']);

    if (! $course) {
        throw ValidationException::withMessages([
            'bs_course_id' => ['The selected exam package is invalid.'],
        ]);
    }

    if (! $db->table('bs_exam_session')
        ->where('id', $data['bs_exam_session_id'])
        ->exists()) {
        throw ValidationException::withMessages([
            'bs_exam_session_id' => ['The selected exam session is invalid.'],
        ]);
    }

    // Check the selected access date rather than today's date.
    $hasPendingExam = $db->table('bs_person_exam')
        ->where('person_id', $data['person_id'])
        ->where('access_exp_date', $data['access_exp_date'])
        ->where(function (Builder $query): void {
            $query->whereNull('started')
                ->orWhere('started', '');
        })
        ->exists();

    if ($hasPendingExam) {
        throw ValidationException::withMessages([
            'person_id' => [
                'The student already has a pending exam on the selected access date.',
            ],
        ]);
    }

    if ($data['exam_type'] === 'New') {
        $topics = $db->table('bs_topic')
            ->where('bs_course_id', $data['bs_course_id'])
            ->orderBy('order_no')
            ->get(['id as bs_topic_id', 'no_quest as quest_cnt', 'order_no']);
    } else {
        $previousExam = $db->table('bs_person_exam')
            ->where('person_id', $data['person_id'])
            ->where('bs_course_id', $data['bs_course_id'])
            ->whereNotNull('ended')
            ->where('ended', '<>', '')
            ->orderByDesc('ended')
            ->first(['id']);

        if (! $previousExam) {
            throw ValidationException::withMessages([
                'exam_type' => [
                    'No completed exam was found for this student and package.',
                ],
            ]);
        }

        $topics = $db->table('bs_person_exam_topic')
            ->where('bs_person_exam_id', $previousExam->id)
            ->where('passed', 'N')
            ->orderBy('order_no')
            ->get(['bs_topic_id', 'quest_cnt', 'order_no'])
            ->values()
            ->map(function ($topic, int $index) {
                $topic->order_no = $index + 1;

                return $topic;
            });
    }

    if ($topics->isEmpty()) {
        throw ValidationException::withMessages([
            'exam_type' => [
                $data['exam_type'] === 'Resit'
                    ? 'No failed subjects were found for the latest completed exam.'
                    : 'The selected exam package has no subjects.',
            ],
        ]);
    }

    $duration = (int) ($data['duration'] ?? 0);

    if ($duration === 0) {
        $duration = (int) $topics->sum(
            fn ($topic) => (int) $topic->quest_cnt
        );
    }

    $examId = (string) Str::uuid();
    $loginId = (string) ($request->user()?->getAuthIdentifier() ?? '');

    if ($loginId === '') {
        return response()->json([
            'message' => 'Your login session could not be identified.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    $now = now();
    $studentName = trim(
        $student->lname.', '.$student->fname.' '.$student->mname
    );
    $until = date('M d, Y', strtotime($data['access_exp_date_to']));
    $loginUrl = (string) config('app.url');

    $content = 'Hi '.e($studentName).',<br><br>'
        .'You have a scheduled Theoretical Assessment with the following details:<br><br>'
        .'Exam to take: <b>'.e($course->name_course).'</b><br>'
        .'You have until: <b>'.e($until.' '.$data['access_exp_time_to'])
        .'</b> to take the exam.<br><br>'
        .'Login to your IRIS-SAM account here: <b>'.e($loginUrl).'</b>';

    try {
        $db->transaction(function () use (
            $db,
            $data,
            $topics,
            $duration,
            $examId,
            $loginId,
            $now,
            $content
        ): void {
            $db->table('bs_person_exam')->insert([
                'id' => $examId,
                'bs_course_id' => $data['bs_course_id'],
                'person_id' => $data['person_id'],
                'started' => '',
                'ended' => '',
                'score' => 0,
                'passed' => '',
                'access_exp_date' => $data['access_exp_date'],
                'access_exp_time' => $data['access_exp_time'],
                'access_exp_date_to' => $data['access_exp_date_to'],
                'access_exp_time_to' => $data['access_exp_time_to'],
                'or_no' => '',
                'amount_paid' => 0,
                'payment_date' => '1970-01-01',
                'proctor_name' => $data['proctor_name'] ?? '',
                'login_id' => $loginId,
                'last_update' => $now,
                'duration' => $duration,
                'exam_type' => $data['exam_type'],
                'exam_permit_no' => '',
                'date_issued' => $now->toDateString(),
                'issued_by' => '',
                'bs_exam_session_id' => $data['bs_exam_session_id'],
            ]);

            foreach ($topics as $topic) {
                $db->table('bs_person_exam_topic')->insert([
                    'id' => (string) Str::uuid(),
                    'bs_person_exam_id' => $examId,
                    'bs_topic_id' => $topic->bs_topic_id,
                    'quest_cnt' => (int) $topic->quest_cnt,
                    'order_no' => $topic->order_no,
                ]);
            }

            $db->table('person')
                ->where('id', $data['person_id'])
                ->update([
                    'exam_id' => $data['bs_course_id'],
                    // Matches the legacy single-student insert.
                    'access_exp' => $data['access_exp_date']
                        .' '.$data['access_exp_time'],
                    'for_item' => 'Y',
                ]);

            $db->table('inbox')->insert([
                'id' => (string) Str::uuid(),
                'subj_inbox' => 'Scheduled Theoretical Assessment on IRIS-SAM',
                'date_inbox' => $now,
                'date_read' => '',
                'recipient_type' => 'Cadet',
                'recipient_id' => $data['person_id'],
                'sender_id' => $loginId,
                'content_inbox' => $content,
                'login_id' => $loginId,
                'last_update' => $now,
                'draft' => 'N',
            ]);
        });
    } catch (Throwable $exception) {
        report($exception);

        return response()->json([
            'message' => 'The theoretical assessment could not be saved.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $emailSent = false;
    $email = trim((string) ($student->email ?? ''));

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            Mail::html(
                $content,
                function ($message) use ($email, $studentName): void {
                    $message->to($email, $studentName)
                        ->subject(
                            'You have a scheduled Theoretical Assessment on IRIS-SAM'
                        );
                }
            );

            $emailSent = true;
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    return response()->json([
        'message' => 'The theoretical assessment has been saved.',
        'data' => [
            'id' => $examId,
            'email_sent' => $emailSent,
        ],
    ], Response::HTTP_CREATED);
}

    private function resolveSchoolCode(Request $request): string
    {
        return strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = $this->resolveSchoolCode($request);

        if ($schoolCode === '') {
            return response()->json([
                'message' => 'No school database has been selected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $school = config(
            "schools.schools.{$schoolCode}",
        );

        if (! is_array($school)) {
            return response()->json([
                'message' => 'The selected school is not configured.',
            ], Response::HTTP_FORBIDDEN);
        }

        $configuredCode = strtoupper(
            trim(
                (string) ($school['code'] ?? ''),
            ),
        );

        if (
            $configuredCode === '' ||
            ! hash_equals($configuredCode, $schoolCode)
        ) {
            return response()->json([
                'message' => 'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = $school['connection'] ?? null;

        if (
            ! is_string($connection) ||
            ! is_array(
                config("database.connections.{$connection}"),
            )
        ) {
            return response()->json([
                'message' =>
                    'The school database connection is not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        return DB::connection($connection);
    }
}