<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Carbon\CarbonImmutable;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Illuminate\View\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * Return only the authenticated student's enrolled theoretical assessments.
     */

    public function studentIndex(Request $request): JsonResponse
    {
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
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $studentId = (string) $account
            ->getAuthIdentifier();
        /*
         * Ownership is enforced on the server.
         * The browser never supplies person_id as authority.
         */
        $query = $db
            ->table('bs_person_exam')
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
            ->where(
                'bs_person_exam.person_id',
                $studentId,
            )
            ->select([
                'bs_person_exam.id',
                'bs_person_exam.bs_course_id',
                'bs_person_exam.bs_exam_session_id',
                'bs_person_exam.exam_type',
                'bs_person_exam.proctor_name',
                'bs_person_exam.done',
                'bs_person_exam.started',
                'bs_person_exam.ended',
                'bs_person_exam.access_exp_date',
                'bs_person_exam.access_exp_time',
                'bs_person_exam.access_exp_date_to',
                'bs_person_exam.access_exp_time_to',
                'bs_person_exam.duration',
                'bs_course.name_course',
                'bs_exam_session.session_code',
            ]);
        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'bs_course.name_course',
                'bs_exam_session.session_code',
                'bs_person_exam.exam_type',
                'bs_person_exam.proctor_name',
            ],
            sortableColumns: [
                'access_exp_date' =>
                    'bs_person_exam.access_exp_date',
                'name_course' =>
                    'bs_course.name_course',
                'session_code' =>
                    'bs_exam_session.session_code',
                'exam_type' =>
                    'bs_person_exam.exam_type',
                'proctor_name' =>
                    'bs_person_exam.proctor_name',
                'done' =>
                    'bs_person_exam.done',
            ],
            defaultSortColumn: 'access_exp_date',
            defaultSortDirection: 'desc',
        );
        $result = $this->datatableService
            ->addRowNumbers(
                response: $result,
                key: 'index',
            );
        $result['data'] = collect(
            $result['data'] ?? [],
        )
            ->map(
                function ($row): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;
                    $record['is_completed'] =
                        strtoupper(
                            trim(
                                (string) (
                                    $record['done']
                                    ?? ''
                                ),
                            ),
                        ) === 'Y';
                    $record = array_merge(
                        $record,
                        $this->studentExamActionState(
                            $record,
                        ),
                    );
                    return $record;
                },
            )
            ->values()
            ->all();
        return response()->json($result);
    }

    /**
     * Render the student's secure theoretical examination page.
     */

    public function studentExamPage(
        Request $request,
        string $assessmentId,
    ): InertiaResponse|JsonResponse {
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        return Inertia::render(
            'assessments/theoretical-internal/student/exam/Index',
            [
                'assessmentId' => $assessmentId,
            ],
        );
    }

    /**
     * Return timing/status metadata without starting the examination.
     */

    public function studentExamState(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$db, $assessment] = $context;
        return response()->json([
            'exam' => $this->studentExamMetadata(
                $db,
                $assessment,
            ),
        ]);
    }

    /**
     * Start the examination once, or resume it without resetting its timer.
     */

    public function studentStartExam(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$db, $assessment, $studentId] = $context;
        if ($this->isCompletedAssessment($assessment)) {
            return response()->json([
                'message' => 'This examination has already been completed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $timing = $this->studentExamActionState(
            (array) $assessment,
        );
        if ($timing['exam_action_state'] === 'not_available') {
            return response()->json([
                'message' => 'This examination is not available yet.',
            ], Response::HTTP_FORBIDDEN);
        }
        if ($timing['exam_action_state'] === 'expired') {
            return response()->json([
                'message' => 'The examination access window has expired.',
            ], Response::HTTP_FORBIDDEN);
        }
        if ($timing['exam_action_state'] === 'time_expired') {
            return response()->json([
                'message' => 'The examination time has expired.',
                'time_expired' => true,
            ], Response::HTTP_CONFLICT);
        }
        try {
            $db->beginTransaction();
            $lockedAssessment = $db
                ->table('bs_person_exam')
                ->where('id', $assessmentId)
                ->where('person_id', $studentId)
                ->lockForUpdate()
                ->first();
            if (! $lockedAssessment) {
                $db->rollBack();
                return response()->json([
                    'message' => 'The assessment could not be found.',
                ], Response::HTTP_NOT_FOUND);
            }
            if ($this->isCompletedAssessment($lockedAssessment)) {
                $db->rollBack();
                return response()->json([
                    'message' => 'This examination has already been completed.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $startedAt = $this->parseStoredDateTime(
                $lockedAssessment->started ?? null,
            );
            if (! $startedAt) {
                $accessStart = $this->combineStoredDateTime(
                    $lockedAssessment->access_exp_date ?? null,
                    $lockedAssessment->access_exp_time ?? null,
                );
                $accessEnd = $this->combineStoredDateTime(
                    $lockedAssessment->access_exp_date_to ?? null,
                    $lockedAssessment->access_exp_time_to ?? null,
                );
                $now = CarbonImmutable::now();
                if ($accessStart && $now->lt($accessStart)) {
                    $db->rollBack();
                    return response()->json([
                        'message' => 'This examination is not available yet.',
                    ], Response::HTTP_FORBIDDEN);
                }
                if ($accessEnd && $now->gt($accessEnd)) {
                    $db->rollBack();
                    return response()->json([
                        'message' => 'The examination access window has expired.',
                    ], Response::HTTP_FORBIDDEN);
                }
                $startedAt = $now;
                $db->table('bs_person_exam')
                    ->where('id', $assessmentId)
                    ->where('person_id', $studentId)
                    ->update([
                        'started' => $startedAt->format('Y-m-d H:i:s'),
                    ]);
            }
            $expiresAt = $this->examExpiresAt(
                $startedAt,
                (int) ($lockedAssessment->duration ?? 0),
            );
            if ($expiresAt && CarbonImmutable::now()->gte($expiresAt)) {
                $db->rollBack();
                return response()->json([
                    'message' => 'The examination time has expired.',
                    'time_expired' => true,
                ], Response::HTTP_CONFLICT);
            }
            $topic = $db
                ->table('bs_person_exam_topic as pet')
                ->leftJoin(
                    'bs_topic as topic',
                    'topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->where('pet.bs_person_exam_id', $assessmentId)
                ->where(function ($query): void {
                    $query->whereNull('pet.ended')
                        ->orWhere('pet.ended', '');
                })
                ->orderBy('pet.order_no')
                ->select([
                    'pet.id',
                    'pet.bs_person_exam_id',
                    'pet.bs_topic_id',
                    'pet.quest_cnt',
                    'pet.order_no',
                    'pet.started',
                    'pet.ended',
                    'topic.desc_topic',
                    'topic.no_quest',
                    'topic.passing_mark',
                ])
                ->lockForUpdate()
                ->first();
            if (! $topic) {
                $totalCompetences = (int) $db
                    ->table('bs_person_exam_topic')
                    ->where('bs_person_exam_id', $assessmentId)
                    ->count();
                $db->rollBack();
                /*
                 * Never mark an exam complete just because no unfinished
                 * competence was found. A missing setup and a real completed
                 * exam are different states.
                 */
                if ($totalCompetences === 0) {
                    return response()->json([
                        'message' =>
                            'This assessment has no competences assigned. Please contact the administrator.',
                        'exam_setup_error' => true,
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                return response()->json([
                    'message' =>
                        'No unfinished competence is available for this assessment. Please refresh the assessment list.',
                    'exam_state_error' => true,
                ], Response::HTTP_CONFLICT);
            }
            if (! $this->parseStoredDateTime($topic->started ?? null)) {
                $topicStarted = CarbonImmutable::now()
                    ->format('Y-m-d H:i:s');
                $db->table('bs_person_exam_topic')
                    ->where('id', $topic->id)
                    ->update([
                        'started' => $topicStarted,
                    ]);
                $topic->started = $topicStarted;
            }
            $this->ensureStudentTopicQuestions(
                $db,
                $assessment,
                $topic,
                $studentId,
            );
            $db->commit();
            $freshAssessment = $this->findStudentAssessment(
                $db,
                $studentId,
                $assessmentId,
            );
            return response()->json(
                $this->studentActiveExamPayload(
                    $request,
                    $db,
                    $freshAssessment ?? $assessment,
                    (string) $topic->id,
                ),
            );
        } catch (Throwable $throwable) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            report($throwable);
            return response()->json([
                'message' => 'Unable to start or resume the examination.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Persist one A-E answer immediately without exposing the correct answer.
     */

    public function studentSaveExamAnswer(
        Request $request,
        string $assessmentId,
        string $questionAttemptId,
    ): JsonResponse {
        $validated = $request->validate([
            'answer' => [
                'required',
                Rule::in(['A', 'B', 'C', 'D', 'E']),
            ],
        ]);
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$db, $assessment, $studentId] = $context;
        $guard = $this->studentExamInteractionGuard(
            $assessment,
        );
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        $question = $db
            ->table('bs_person_exam_topic_quest as question')
            ->join(
                'bs_person_exam_topic as topic',
                'topic.id',
                '=',
                'question.bs_person_exam_topic_id',
            )
            ->join(
                'bs_person_exam as exam',
                'exam.id',
                '=',
                'topic.bs_person_exam_id',
            )
            ->where('question.id', $questionAttemptId)
            ->where('exam.id', $assessmentId)
            ->where('exam.person_id', $studentId)
            ->where(function ($query): void {
                $query->whereNull('topic.ended')
                    ->orWhere('topic.ended', '');
            })
            ->select([
                'question.id',
                'topic.id as topic_attempt_id',
                'question.choice_1',
                'question.choice_2',
                'question.choice_3',
                'question.choice_4',
                'question.choice_5',
                'question.choice_1_img',
                'question.choice_2_img',
                'question.choice_3_img',
                'question.choice_4_img',
                'question.choice_5_img',
            ])
            ->first();
        if (! $question) {
            return response()->json([
                'message' => 'The examination question could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }
        $currentTopicId = $db
            ->table('bs_person_exam_topic')
            ->where('bs_person_exam_id', $assessmentId)
            ->where(function ($query): void {
                $query->whereNull('ended')
                    ->orWhere('ended', '');
            })
            ->orderBy('order_no')
            ->value('id');
        if (
            ! $currentTopicId
            || ! hash_equals(
                (string) $currentTopicId,
                (string) $question->topic_attempt_id,
            )
        ) {
            return response()->json([
                'message' => 'This question is not part of the current examination topic.',
            ], Response::HTTP_CONFLICT);
        }
        $answer = (string) $validated['answer'];
        $choiceNumber = ord($answer) - 64;
        $choiceField = 'choice_'.$choiceNumber;
        $imageField = 'choice_'.$choiceNumber.'_img';
        if (
            trim((string) ($question->{$choiceField} ?? '')) === ''
            && trim((string) ($question->{$imageField} ?? '')) === ''
        ) {
            return response()->json([
                'message' => 'The selected answer choice is not available.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $db->table('bs_person_exam_topic_quest')
            ->where('id', $questionAttemptId)
            ->update([
                // Deliberately do not change last_update: legacy display order
                // is based on the generated-question timestamp.
                'answer' => $answer,
            ]);
        return response()->json([
            'success' => true,
            'answer' => $answer,
        ]);
    }

    /**
     * Grade one topic and either expose the next topic or finish the exam.
     */

    public function studentSubmitExamTopic(
        Request $request,
        string $assessmentId,
        string $topicAttemptId,
    ): JsonResponse {
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$db, $assessment, $studentId] = $context;
        $guard = $this->studentExamInteractionGuard(
            $assessment,
        );
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        try {
            $db->beginTransaction();
            $topic = $db
                ->table('bs_person_exam_topic as pet')
                ->join(
                    'bs_topic as source_topic',
                    'source_topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->join(
                    'bs_person_exam as exam',
                    'exam.id',
                    '=',
                    'pet.bs_person_exam_id',
                )
                ->where('pet.id', $topicAttemptId)
                ->where('exam.id', $assessmentId)
                ->where('exam.person_id', $studentId)
                ->select([
                    'pet.id',
                    'pet.bs_topic_id',
                    'pet.bs_person_exam_id',
                    'pet.quest_cnt',
                    'pet.order_no',
                    'pet.started',
                    'pet.ended',
                    'source_topic.desc_topic',
                    'source_topic.passing_mark',
                ])
                ->lockForUpdate()
                ->first();
            if (! $topic) {
                $db->rollBack();
                return response()->json([
                    'message' => 'The examination topic could not be found.',
                ], Response::HTTP_NOT_FOUND);
            }
            $currentTopicId = $db
                ->table('bs_person_exam_topic')
                ->where('bs_person_exam_id', $assessmentId)
                ->where(function ($query): void {
                    $query->whereNull('ended')
                        ->orWhere('ended', '');
                })
                ->orderBy('order_no')
                ->value('id');
            if (
                ! $currentTopicId
                || ! hash_equals(
                    (string) $currentTopicId,
                    (string) $topic->id,
                )
            ) {
                $db->rollBack();
                return response()->json([
                    'message' => 'Only the current examination topic may be submitted.',
                ], Response::HTTP_CONFLICT);
            }
            if ($this->parseStoredDateTime($topic->ended ?? null)) {
                $db->rollBack();
                return response()->json([
                    'message' => 'This topic has already been submitted.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $questions = $db
                ->table('bs_person_exam_topic_quest')
                ->where('bs_person_exam_topic_id', $topicAttemptId)
                ->orderBy('last_update')
                ->orderBy('id')
                ->get([
                    'id',
                    'answer',
                    'correct_ans',
                ]);
            $unanswered = [];
            $score = 0;
            foreach ($questions as $index => $question) {
                $answer = trim((string) ($question->answer ?? ''));
                if ($answer === '') {
                    $unanswered[] = $index + 1;
                    continue;
                }
                if (
                    $answer === trim(
                        (string) ($question->correct_ans ?? ''),
                    )
                ) {
                    $score++;
                }
            }
            if ($unanswered !== []) {
                $db->rollBack();
                return response()->json([
                    'message' => 'Please answer all questions before submitting.',
                    'unanswered_numbers' => $unanswered,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $now = CarbonImmutable::now()
                ->format('Y-m-d H:i:s');
            $db->table('bs_person_exam_topic_quest')
                ->where('bs_person_exam_topic_id', $topicAttemptId)
                ->whereNotNull('answer')
                ->where('answer', '<>', '')
                ->update([
                    'is_saved' => 'Y',
                    'date_taken' => $now,
                ]);
            $passingMark = (float) ($topic->passing_mark ?? 0);
            $passed = $score >= $passingMark ? 'Y' : 'N';
            $questionCount = max(
                (int) ($topic->quest_cnt ?? 0),
                $questions->count(),
            );
            $percentage = $questionCount > 0
                ? round(($score / $questionCount) * 100, 1)
                : 0.0;
            $db->table('bs_person_exam_topic')
                ->where('id', $topicAttemptId)
                ->update([
                    'ended' => $now,
                    'score' => $score,
                    'passed' => $passed,
                ]);
            $overall = $this->calculateStudentExamOverall(
                $db,
                $assessmentId,
                (string) $assessment->bs_course_id,
            );
            $db->table('bs_person_exam')
                ->where('id', $assessmentId)
                ->where('person_id', $studentId)
                ->update([
                    'ended' => $now,
                    'score' => $overall['score'],
                    'passed' => $overall['passed'],
                ]);
            $nextTopic = $db
                ->table('bs_person_exam_topic as pet')
                ->leftJoin(
                    'bs_topic as source_topic',
                    'source_topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->where('pet.bs_person_exam_id', $assessmentId)
                ->where('pet.order_no', '>', (int) $topic->order_no)
                ->orderBy('pet.order_no')
                ->select([
                    'pet.id',
                    'pet.bs_topic_id',
                    'pet.order_no',
                    'source_topic.desc_topic',
                ])
                ->first();
            $done = $nextTopic ? 'N' : 'Y';
            if (! $nextTopic) {
                $db->table('bs_person_exam')
                    ->where('id', $assessmentId)
                    ->where('person_id', $studentId)
                    ->update([
                        'done' => 'Y',
                        'ended' => $now,
                    ]);
            }
            $db->commit();
            return response()->json([
                'success' => true,
                'done' => $done,
                /*
                 * Do not expose per-competence scores to the student between
                 * competences. The student only needs confirmation that the
                 * competence is complete before proceeding, matching the
                 * previous assessment flow.
                 */
                'topic_result' => [
                    'topic' => $this->decodeText(
                        $topic->desc_topic ?? '',
                    ),
                    'order_no' => (int) ($topic->order_no ?? 0),
                    'completed' => true,
                ],
                'next_topic' => $nextTopic
                    ? [
                        'id' => (string) $nextTopic->id,
                        'bs_topic_id' => (string) $nextTopic->bs_topic_id,
                        'order_no' => (int) $nextTopic->order_no,
                        'description' => $this->decodeText(
                            $nextTopic->desc_topic ?? '',
                        ),
                    ]
                    : null,
                /*
                 * Final remarks are shown only after the final competence.
                 */
                'final_result' => $done === 'Y'
                    ? $this->studentFinalExamResult(
                        $db,
                        $assessment,
                        (float) $overall['score'],
                        (string) $overall['passed'],
                    )
                    : null,
                'message' => $done === 'Y'
                    ? 'The final competence has been completed. The examination is complete.'
                    : 'Competence completed. You may proceed to the next competence.',
            ]);
        } catch (Throwable $throwable) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            report($throwable);
            return response()->json([
                'message' => 'Unable to submit the examination topic.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Finalize a timed-out examination using the legacy auto-submit behavior:
     * grade the current unfinished competence from persisted answers, close
     * every later unfinished competence with zero score, and finish the exam.
     */

    public function studentTimeoutExam(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $context = $this->studentExamContext(
            $request,
            $assessmentId,
        );
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$db, $assessment, $studentId] = $context;
        if ($this->isCompletedAssessment($assessment)) {
            return response()->json([
                'success' => true,
                'completed' => true,
                'message' => 'The examination has already been completed.',
            ]);
        }
        $startedAt = $this->parseStoredDateTime(
            $assessment->started ?? null,
        );
        $expiresAt = $this->examExpiresAt(
            $startedAt,
            (int) ($assessment->duration ?? 0),
        );
        if (! $startedAt || ! $expiresAt) {
            return response()->json([
                'message' => 'The examination timer has not started.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (CarbonImmutable::now()->lt($expiresAt)) {
            return response()->json([
                'message' => 'The examination still has time remaining.',
            ], Response::HTTP_CONFLICT);
        }
        try {
            $this->finalizeTimedOutStudentExam(
                $db,
                $assessment,
                $studentId,
            );
            $finalAssessment = $this->findStudentAssessment(
                $db,
                $studentId,
                $assessmentId,
            );
            return response()->json([
                'success' => true,
                'completed' => true,
                'final_result' => $finalAssessment
                    ? $this->studentFinalExamResult(
                        $db,
                        $finalAssessment,
                    )
                    : null,
                'message' => 'Time is up. Your saved answers have been submitted.',
            ]);
        } catch (Throwable $throwable) {
            report($throwable);
            return response()->json([
                'message' => 'Unable to finalize the timed-out examination.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a certificate only when the assessment belongs
     * to the authenticated student.
     */

    public function studentCertificate(
        Request $request,
        string $assessmentId,
    ): StreamedResponse|JsonResponse {
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
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $studentId = (string) $account
            ->getAuthIdentifier();
        $assessment = $db
            ->table('bs_person_exam')
            ->where(
                'id',
                $assessmentId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->select([
                'id',
                'done',
            ])
            ->first();
        if (! $assessment) {
            return response()->json(
                [
                    'message' =>
                        'The assessment could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }
        if (
            strtoupper(
                trim(
                    (string) (
                        $assessment->done
                        ?? ''
                    ),
                ),
            ) !== 'Y'
        ) {
            return response()->json(
                [
                    'message' =>
                        'A certificate is only available for a completed assessment.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        /*
         * Reuse the senior's existing certificate generator only
         * after student ownership has been verified.
         */
        return $this->certificate(
            $request,
            $assessmentId,
        );
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

    /**
     * @return array{0: ConnectionInterface, 1: object, 2: string}|JsonResponse
     */

    private function studentExamContext(
        Request $request,
        string $assessmentId,
    ): array|JsonResponse {
        $account = $request->user();
        if (! $account instanceof Student) {
            return response()->json([
                'message' => 'Only student accounts may access this resource.',
            ], Response::HTTP_FORBIDDEN);
        }
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $studentId = (string) $account->getAuthIdentifier();
        $assessment = $this->findStudentAssessment(
            $db,
            $studentId,
            $assessmentId,
        );
        if (! $assessment) {
            return response()->json([
                'message' => 'The assessment could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }
        return [$db, $assessment, $studentId];
    }

    private function findStudentAssessment(
        ConnectionInterface $db,
        string $studentId,
        string $assessmentId,
    ): ?object {
        return $db
            ->table('bs_person_exam')
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
            ->where('bs_person_exam.id', $assessmentId)
            ->where('bs_person_exam.person_id', $studentId)
            ->select([
                'bs_person_exam.id',
                'bs_person_exam.person_id',
                'bs_person_exam.bs_course_id',
                'bs_person_exam.bs_exam_session_id',
                'bs_person_exam.exam_type',
                'bs_person_exam.proctor_name',
                'bs_person_exam.started',
                'bs_person_exam.ended',
                'bs_person_exam.score',
                'bs_person_exam.passed',
                'bs_person_exam.done',
                'bs_person_exam.duration',
                'bs_person_exam.access_exp_date',
                'bs_person_exam.access_exp_time',
                'bs_person_exam.access_exp_date_to',
                'bs_person_exam.access_exp_time_to',
                'bs_course.name_course',
                'bs_course.randomize',
                'bs_exam_session.session_code',
            ])
            ->first();
    }

    /**
     * @param array<string, mixed>|object $assessment
     * @return array{exam_action_state: string, exam_action_label: string, can_open_exam: bool, expires_at: ?string}
     */

    private function studentExamActionState(
        array|object $assessment,
    ): array {
        $record = is_object($assessment)
            ? get_object_vars($assessment)
            : $assessment;
        if (
            strtoupper(trim((string) ($record['done'] ?? ''))) === 'Y'
        ) {
            return [
                'exam_action_state' => 'completed',
                'exam_action_label' => 'Exam Completed',
                'can_open_exam' => false,
                'expires_at' => null,
            ];
        }
        $startedAt = $this->parseStoredDateTime(
            $record['started'] ?? null,
        );
        $duration = (int) ($record['duration'] ?? 0);
        $expiresAt = $this->examExpiresAt($startedAt, $duration);
        $now = CarbonImmutable::now();
        if ($startedAt) {
            if ($expiresAt && $now->gte($expiresAt)) {
                return [
                    'exam_action_state' => 'time_expired',
                    'exam_action_label' => 'Finalize Timed Out Exam',
                    'can_open_exam' => true,
                    'expires_at' => $expiresAt->toIso8601String(),
                ];
            }
            return [
                'exam_action_state' => 'resume',
                'exam_action_label' => 'Resume Exam',
                'can_open_exam' => true,
                'expires_at' => $expiresAt?->toIso8601String(),
            ];
        }
        $accessStart = $this->combineStoredDateTime(
            $record['access_exp_date'] ?? null,
            $record['access_exp_time'] ?? null,
        );
        $accessEnd = $this->combineStoredDateTime(
            $record['access_exp_date_to'] ?? null,
            $record['access_exp_time_to'] ?? null,
        );
        if ($accessStart && $now->lt($accessStart)) {
            return [
                'exam_action_state' => 'not_available',
                'exam_action_label' => 'Not Available Yet',
                'can_open_exam' => false,
                'expires_at' => null,
            ];
        }
        if ($accessEnd && $now->gt($accessEnd)) {
            return [
                'exam_action_state' => 'expired',
                'exam_action_label' => 'Access Expired',
                'can_open_exam' => false,
                'expires_at' => null,
            ];
        }
        return [
            'exam_action_state' => 'start',
            'exam_action_label' => 'Start Exam',
            'can_open_exam' => true,
            'expires_at' => null,
        ];
    }

    private function studentExamMetadata(
        ConnectionInterface $db,
        object $assessment,
    ): array {
        $state = $this->studentExamActionState($assessment);
        $startedAt = $this->parseStoredDateTime(
            $assessment->started ?? null,
        );
        $accessStart = $this->combineStoredDateTime(
            $assessment->access_exp_date ?? null,
            $assessment->access_exp_time ?? null,
        );
        $accessEnd = $this->combineStoredDateTime(
            $assessment->access_exp_date_to ?? null,
            $assessment->access_exp_time_to ?? null,
        );
        return array_merge($state, [
            'id' => (string) $assessment->id,
            'name_course' => (string) ($assessment->name_course ?? ''),
            'session_code' => (string) ($assessment->session_code ?? ''),
            'exam_type' => (string) ($assessment->exam_type ?? ''),
            'proctor_name' => (string) ($assessment->proctor_name ?? ''),
            'duration' => (int) ($assessment->duration ?? 0),
            'started_at' => $startedAt?->toIso8601String(),
            'access_start' => $accessStart?->toIso8601String(),
            'access_end' => $accessEnd?->toIso8601String(),
            'server_time' => CarbonImmutable::now()->toIso8601String(),
            'done' => $this->isCompletedAssessment($assessment),
            'remarks' => $this->isCompletedAssessment($assessment)
                ? (
                    strtoupper(trim((string) ($assessment->passed ?? ''))) === 'Y'
                        ? 'PASSED'
                        : 'FAILED'
                )
                : null,
            'final_result' => $this->isCompletedAssessment($assessment)
                ? $this->studentFinalExamResult(
                    $db,
                    $assessment,
                )
                : null,
        ]);
    }

    private function studentActiveExamPayload(
        Request $request,
        ConnectionInterface $db,
        object $assessment,
        string $topicAttemptId,
    ): array {
        $topic = $db
            ->table('bs_person_exam_topic as pet')
            ->leftJoin(
                'bs_topic as source_topic',
                'source_topic.id',
                '=',
                'pet.bs_topic_id',
            )
            ->where('pet.id', $topicAttemptId)
            ->where('pet.bs_person_exam_id', $assessment->id)
            ->select([
                'pet.id',
                'pet.bs_topic_id',
                'pet.quest_cnt',
                'pet.order_no',
                'pet.started',
                'source_topic.desc_topic',
            ])
            ->first();
        if (! $topic) {
            return [
                'exam' => $this->studentExamMetadata($db, $assessment),
                'topic' => null,
                'questions' => [],
            ];
        }
        $totalCompetences = (int) $db
            ->table('bs_person_exam_topic')
            ->where('bs_person_exam_id', $assessment->id)
            ->count();
        $questions = $db
            ->table('bs_person_exam_topic_quest as attempt')
            ->leftJoin(
                'bs_quest as source_question',
                'source_question.id',
                '=',
                'attempt.bs_quest_id',
            )
            ->where('attempt.bs_person_exam_topic_id', $topicAttemptId)
            ->orderBy('attempt.last_update')
            ->orderBy('attempt.id')
            ->select([
                'attempt.id',
                'attempt.answer',
                'attempt.choice_1',
                'attempt.choice_2',
                'attempt.choice_3',
                'attempt.choice_4',
                'attempt.choice_5',
                'attempt.choice_1_img',
                'attempt.choice_2_img',
                'attempt.choice_3_img',
                'attempt.choice_4_img',
                'attempt.choice_5_img',
                'source_question.quest_text',
            ])
            ->get()
            ->values()
            ->map(function ($question, int $index) use ($request): array {
                $choices = [];
                foreach (range(1, 5) as $choiceNumber) {
                    $letter = chr(64 + $choiceNumber);
                    $textField = 'choice_'.$choiceNumber;
                    $imageField = 'choice_'.$choiceNumber.'_img';
                    $choiceText = $this->decodeText(
                        $question->{$textField} ?? '',
                    );
                    $imageUrl = $this->buildQuestionImageUrl(
                        $request,
                        $question->{$imageField} ?? null,
                    );
                    if ($choiceText === '' && ! $imageUrl) {
                        continue;
                    }
                    $choices[] = [
                        'key' => $letter,
                        'text' => $choiceText,
                        'image_url' => $imageUrl,
                    ];
                }
                return [
                    'id' => (string) $question->id,
                    'index' => $index + 1,
                    'question' => $this->decodeText(
                        $question->quest_text ?? '',
                    ),
                    'answer' => trim((string) ($question->answer ?? '')),
                    'choices' => $choices,
                ];
            })
            ->all();
        return [
            'exam' => $this->studentExamMetadata($db, $assessment),
            'topic' => [
                'id' => (string) $topic->id,
                'bs_topic_id' => (string) $topic->bs_topic_id,
                'description' => $this->decodeText(
                    $topic->desc_topic ?? '',
                ),
                'order_no' => (int) ($topic->order_no ?? 0),
                'total_competences' => $totalCompetences,
                'quest_cnt' => (int) ($topic->quest_cnt ?? 0),
                'started_at' => $this->parseStoredDateTime(
                    $topic->started ?? null,
                )?->toIso8601String(),
            ],
            'questions' => $questions,
            'answered_count' => collect($questions)
                ->where('answer', '<>', '')
                ->count(),
        ];
    }

    private function studentExamInteractionGuard(
        object $assessment,
    ): ?JsonResponse {
        if ($this->isCompletedAssessment($assessment)) {
            return response()->json([
                'message' => 'This examination has already been completed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $startedAt = $this->parseStoredDateTime(
            $assessment->started ?? null,
        );
        if (! $startedAt) {
            return response()->json([
                'message' => 'The examination has not started yet.',
            ], Response::HTTP_CONFLICT);
        }
        $expiresAt = $this->examExpiresAt(
            $startedAt,
            (int) ($assessment->duration ?? 0),
        );
        if ($expiresAt && CarbonImmutable::now()->gte($expiresAt)) {
            return response()->json([
                'message' => 'The examination time has expired.',
                'time_expired' => true,
            ], Response::HTTP_CONFLICT);
        }
        return null;
    }

    private function ensureStudentTopicQuestions(
        ConnectionInterface $db,
        object $assessment,
        object $topic,
        string $studentId,
    ): void {
        $assignedCount = (int) ($topic->quest_cnt ?? 0);
        if ($assignedCount <= 0) {
            $assignedCount = (int) ($topic->no_quest ?? 0);
        }
        if ($assignedCount <= 0) {
            throw new \RuntimeException(
                'The selected topic has no required question count.',
            );
        }
        $existingQuestionIds = $db
            ->table('bs_person_exam_topic_quest')
            ->where('bs_person_exam_topic_id', $topic->id)
            ->pluck('bs_quest_id')
            ->filter()
            ->values()
            ->all();
        $remaining = $assignedCount - count($existingQuestionIds);
        if ($remaining <= 0) {
            return;
        }
        $questionQuery = $db
            ->table('bs_quest')
            ->where('bs_topic_id', $topic->bs_topic_id)
            ->where('level', 1)
            ->where('active', 'Y');
        if ($existingQuestionIds !== []) {
            $questionQuery->whereNotIn('id', $existingQuestionIds);
        }
        $randomize = strtoupper(
            trim((string) ($assessment->randomize ?? '')),
        ) === 'Y';
        if ($randomize) {
            $questionQuery->inRandomOrder();
        } else {
            $questionQuery->orderBy('quest_text');
        }
        $sourceQuestions = $questionQuery
            ->limit($remaining)
            ->get([
                'id',
            ]);
        if ($sourceQuestions->count() < $remaining) {
            throw new \RuntimeException(
                'There are not enough active questions for this topic.',
            );
        }
        foreach ($sourceQuestions as $sourceQuestion) {
            $answerQuery = $db
                ->table('bs_quest_ans')
                ->where('bs_quest_id', $sourceQuestion->id);
            if ($randomize) {
                $answerQuery->inRandomOrder();
            } else {
                $answerQuery->orderBy('answer_text');
            }
            $sourceAnswers = $answerQuery
                ->limit(5)
                ->get([
                    'answer_text',
                    'filename',
                    'answer',
                ]);
            $choices = array_fill(0, 5, '');
            $choiceImages = array_fill(0, 5, '');
            $correctAnswer = '';
            foreach ($sourceAnswers as $index => $sourceAnswer) {
                if ($index > 4) {
                    break;
                }
                $choices[$index] = (string) ($sourceAnswer->answer_text ?? '');
                $choiceImages[$index] = (string) ($sourceAnswer->filename ?? '');
                if (($sourceAnswer->answer ?? '') === 'Y') {
                    $correctAnswer = chr(65 + $index);
                }
            }
            if ($correctAnswer === '') {
                throw new \RuntimeException(
                    'A selected question has no correct answer configured.',
                );
            }
            $db->table('bs_person_exam_topic_quest')->insert([
                'id' => (string) Str::uuid(),
                'bs_person_exam_topic_id' => (string) $topic->id,
                'bs_quest_id' => (string) $sourceQuestion->id,
                'correct_ans' => $correctAnswer,
                'answer' => '',
                'is_saved' => 'N',
                'login_id' => $studentId,
                'last_update' => CarbonImmutable::now()
                    ->format('Y-m-d H:i:s'),
                'choice_1' => $choices[0],
                'choice_2' => $choices[1],
                'choice_3' => $choices[2],
                'choice_4' => $choices[3],
                'choice_5' => $choices[4],
                'choice_1_img' => $choiceImages[0],
                'choice_2_img' => $choiceImages[1],
                'choice_3_img' => $choiceImages[2],
                'choice_4_img' => $choiceImages[3],
                'choice_5_img' => $choiceImages[4],
                'stud_type' => '',
                'bs_topic_id' => (string) $topic->bs_topic_id,
                'school_class' => CarbonImmutable::now()->format('Y'),
                'company_id' => '',
                'person_id' => '',
                'bs_exam_session_id' => (string) (
                    $assessment->bs_exam_session_id ?? ''
                ),
                'date_taken' => CarbonImmutable::now()->format('Y-m-d'),
            ]);
        }
    }

    /**
     * @return array{
     *     remarks: string,
     *     score: float,
     *     total_items: int,
     *     percentage: float,
     *     exam_package: string
     * }
     */

    private function studentFinalExamResult(
        ConnectionInterface $db,
        object $assessment,
        ?float $score = null,
        ?string $passed = null,
    ): array {
        $resolvedScore = $score
            ?? (float) ($assessment->score ?? 0);
        $resolvedPassed = strtoupper(trim(
            (string) (
                $passed
                ?? $assessment->passed
                ?? ''
            ),
        ));
        $totalItems = (int) $db
            ->table('bs_person_exam_topic')
            ->where(
                'bs_person_exam_id',
                (string) $assessment->id,
            )
            ->sum('quest_cnt');
        if ($totalItems <= 0) {
            $totalItems = (int) $db
                ->table('bs_person_exam_topic_quest as attempt')
                ->join(
                    'bs_person_exam_topic as topic',
                    'topic.id',
                    '=',
                    'attempt.bs_person_exam_topic_id',
                )
                ->where(
                    'topic.bs_person_exam_id',
                    (string) $assessment->id,
                )
                ->count();
        }
        return [
            'remarks' => $resolvedPassed === 'Y'
                ? 'PASSED'
                : 'FAILED',
            'score' => $resolvedScore,
            'total_items' => $totalItems,
            'percentage' => $totalItems > 0
                ? round(
                    ($resolvedScore / $totalItems) * 100,
                    1,
                )
                : 0.0,
            'exam_package' => (string) (
                $assessment->name_course
                ?? ''
            ),
        ];
    }

    /**
     * @return array{score: float, passing_score: float, passed: string}
     */

    private function calculateStudentExamOverall(
        ConnectionInterface $db,
        string $assessmentId,
        string $courseId,
    ): array {
        $courseTopics = $db
            ->table('bs_topic')
            ->where('bs_course_id', $courseId)
            ->get([
                'id',
                'passing_mark',
            ]);
        $passingScore = 0.0;
        $overallScore = 0.0;
        foreach ($courseTopics as $courseTopic) {
            $passingScore += (float) ($courseTopic->passing_mark ?? 0);
            $latestScore = $db
                ->table('bs_person_exam_topic')
                ->where('bs_person_exam_id', $assessmentId)
                ->where('bs_topic_id', $courseTopic->id)
                ->orderByDesc('started')
                ->value('score');
            if ($latestScore !== null) {
                $overallScore += (float) $latestScore;
            }
        }
        return [
            'score' => $overallScore,
            'passing_score' => $passingScore,
            'passed' => $overallScore >= $passingScore ? 'Y' : 'N',
        ];
    }

    private function finalizeTimedOutStudentExam(
        ConnectionInterface $db,
        object $assessment,
        string $studentId,
    ): void {
        $db->transaction(function () use (
            $db,
            $assessment,
            $studentId,
        ): void {
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s');
            $topics = $db
                ->table('bs_person_exam_topic as pet')
                ->leftJoin(
                    'bs_topic as source_topic',
                    'source_topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->where('pet.bs_person_exam_id', $assessment->id)
                ->orderBy('pet.order_no')
                ->select([
                    'pet.id',
                    'pet.started',
                    'pet.ended',
                    'pet.order_no',
                    'source_topic.passing_mark',
                ])
                ->lockForUpdate()
                ->get();
            /*
             * Legacy auto_submit.php grades the current competence, then closes
             * all later unfinished competences with zero score / failed status.
             */
            $currentUnfinishedFound = false;
            foreach ($topics as $topic) {
                if ($this->parseStoredDateTime($topic->ended ?? null)) {
                    continue;
                }
                $isCurrentCompetence = ! $currentUnfinishedFound;
                $currentUnfinishedFound = true;
                $score = 0;
                if ($isCurrentCompetence) {
                    $questions = $db
                        ->table('bs_person_exam_topic_quest')
                        ->where('bs_person_exam_topic_id', $topic->id)
                        ->get([
                            'id',
                            'answer',
                            'correct_ans',
                        ]);
                    foreach ($questions as $question) {
                        $answer = trim((string) ($question->answer ?? ''));
                        if (
                            $answer !== ''
                            && $answer === trim(
                                (string) ($question->correct_ans ?? ''),
                            )
                        ) {
                            $score++;
                        }
                    }
                }
                /*
                 * Legacy timeout marks all question rows in the timed-out
                 * competence(s) as saved, including unanswered rows.
                 */
                $db->table('bs_person_exam_topic_quest')
                    ->where('bs_person_exam_topic_id', $topic->id)
                    ->update([
                        'is_saved' => 'Y',
                    ]);
                $passingMark = (float) ($topic->passing_mark ?? 0);
                $started = $this->parseStoredDateTime(
                    $topic->started ?? null,
                )
                    ? (string) $topic->started
                    : $now;
                $db->table('bs_person_exam_topic')
                    ->where('id', $topic->id)
                    ->update([
                        'started' => $started,
                        'ended' => $now,
                        'score' => $score,
                        'passed' => $score >= $passingMark ? 'Y' : 'N',
                    ]);
            }
            $overall = $this->calculateStudentExamOverall(
                $db,
                (string) $assessment->id,
                (string) $assessment->bs_course_id,
            );
            $db->table('bs_person_exam')
                ->where('id', $assessment->id)
                ->where('person_id', $studentId)
                ->update([
                    'ended' => $now,
                    'score' => $overall['score'],
                    'passed' => $overall['passed'],
                    'done' => 'Y',
                ]);
        });
    }

    private function buildQuestionImageUrl(
        Request $request,
        mixed $filename,
    ): ?string {
        $cleanFilename = basename(
            str_replace('\\', '/', trim((string) $filename)),
        );
        if ($cleanFilename === '') {
            return null;
        }
        $schoolCode = $this->resolveSchoolCode($request);
        $configured = trim((string) config(
            "schools.schools.{$schoolCode}.files.question_images_url",
            '',
        ));
        if ($configured !== '') {
            return rtrim($configured, '/')
                .'/'.rawurlencode($cleanFilename);
        }
        /*
         * Existing school config exposes the legacy /photos public URL but
         * not /question_images. Both directories are siblings in legacy SAM,
         * so use that parent only as a compatibility fallback.
         */
        $photosUrl = rtrim(trim((string) config(
            "schools.schools.{$schoolCode}.files.photos_url",
            '',
        )), '/');
        if ($photosUrl === '') {
            return null;
        }
        $baseUrl = preg_replace('#/photos$#i', '', $photosUrl)
            ?: $photosUrl;
        return rtrim($baseUrl, '/')
            .'/question_images/'
            .rawurlencode($cleanFilename);
    }

    private function isCompletedAssessment(object|array $assessment): bool
    {
        $done = is_object($assessment)
            ? ($assessment->done ?? '')
            : ($assessment['done'] ?? '');
        return strtoupper(trim((string) $done)) === 'Y';
    }

    private function parseStoredDateTime(mixed $value): ?CarbonImmutable
    {
        $raw = trim((string) $value);
        if (
            $raw === ''
            || str_starts_with($raw, '0000-00-00')
            || str_starts_with($raw, '1970-01-01')
        ) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function combineStoredDateTime(
        mixed $date,
        mixed $time,
    ): ?CarbonImmutable {
        $dateValue = trim((string) $date);
        $timeValue = trim((string) $time);
        if (
            $dateValue === ''
            || str_starts_with($dateValue, '0000-00-00')
        ) {
            return null;
        }
        if ($timeValue === '') {
            $timeValue = '00:00:00';
        }
        try {
            return CarbonImmutable::parse(
                $dateValue.' '.$timeValue,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function examExpiresAt(
        ?CarbonImmutable $startedAt,
        int $durationMinutes,
    ): ?CarbonImmutable {
        if (! $startedAt || $durationMinutes <= 0) {
            return null;
        }
        return $startedAt->addMinutes($durationMinutes);
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
