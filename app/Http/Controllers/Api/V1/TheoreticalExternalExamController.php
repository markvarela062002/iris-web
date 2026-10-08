<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExternalAssessmentAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TheoreticalExternalExamController extends Controller
{
    public function __construct(
        private readonly ExternalAssessmentAccessService $accessService,
    ) {
    }

    public function page(
        string $accessToken,
    ): InertiaResponse|JsonResponse {
        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [, $assessment] = $context;

        return Inertia::render(
            'assessments/theoretical-external/exam/Index',
            [
                'accessToken' => $accessToken,
                'assessmentId' => (string) $assessment->id,
                'examineeName' => $this->formatExternalName(
                    $assessment,
                ),
            ],
        );
    }

    public function state(
        string $accessToken,
    ): JsonResponse {
        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$db, $assessment] = $context;

        return response()->json([
            'exam' => $this->metadata(
                $db,
                $assessment,
            ),
        ]);
    }

    public function start(
        Request $request,
        string $accessToken,
    ): JsonResponse {
        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$db, $assessment] = $context;

        if (
            $this->isCompletedAssessment(
                $assessment,
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'This examination has already been completed.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $timing = $this->actionState(
            $assessment,
        );

        if (
            $timing[
                'exam_action_state'
            ] === 'not_available'
        ) {
            return response()->json(
                [
                    'message' =>
                        'This examination is not available yet.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (
            $timing[
                'exam_action_state'
            ] === 'expired'
        ) {
            return response()->json(
                [
                    'message' =>
                        'The examination access window has expired.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (
            $timing[
                'exam_action_state'
            ] === 'time_expired'
        ) {
            return response()->json(
                [
                    'message' =>
                        'The examination time has expired.',
                    'time_expired' => true,
                ],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $db->beginTransaction();

            $lockedAssessment = $db
                ->table(
                    'bs_person_exam_ext',
                )
                ->where(
                    'id',
                    $assessment->id,
                )
                ->lockForUpdate()
                ->first();

            if (! $lockedAssessment) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'The assessment could not be found.',
                    ],
                    Response::HTTP_NOT_FOUND,
                );
            }

            if (
                $this->isCompletedAssessment(
                    $lockedAssessment,
                )
            ) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'This examination has already been completed.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $startedAt =
                $this->parseStoredDateTime(
                    $lockedAssessment
                        ->started
                        ?? null,
                );

            if (! $startedAt) {
                $accessStart =
                    $this->combineStoredDateTime(
                        $lockedAssessment
                            ->access_exp_date
                            ?? null,
                        $lockedAssessment
                            ->access_exp_time
                            ?? null,
                    );

                $accessEnd =
                    $this->combineStoredDateTime(
                        $lockedAssessment
                            ->access_exp_date_to
                            ?? null,
                        $lockedAssessment
                            ->access_exp_time_to
                            ?? null,
                    );

                $now =
                    CarbonImmutable::now();

                if (
                    $accessStart
                    && $now->lt(
                        $accessStart,
                    )
                ) {
                    $db->rollBack();

                    return response()->json(
                        [
                            'message' =>
                                'This examination is not available yet.',
                        ],
                        Response::HTTP_FORBIDDEN,
                    );
                }

                if (
                    $accessEnd
                    && $now->gt(
                        $accessEnd,
                    )
                ) {
                    $db->rollBack();

                    return response()->json(
                        [
                            'message' =>
                                'The examination access window has expired.',
                        ],
                        Response::HTTP_FORBIDDEN,
                    );
                }

                $startedAt = $now;

                $db->table(
                    'bs_person_exam_ext',
                )
                    ->where(
                        'id',
                        $assessment->id,
                    )
                    ->update([
                        'started' =>
                            $startedAt
                                ->format(
                                    'Y-m-d H:i:s',
                                ),
                    ]);
            }

            $expiresAt =
                $this->examExpiresAt(
                    $startedAt,
                    (int) (
                        $lockedAssessment
                            ->duration
                        ?? 0
                    ),
                );

            if (
                $expiresAt
                && CarbonImmutable::now()
                    ->gte(
                        $expiresAt,
                    )
            ) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'The examination time has expired.',
                        'time_expired' =>
                            true,
                    ],
                    Response::HTTP_CONFLICT,
                );
            }

            $topic = $db
                ->table(
                    'bs_person_exam_topic_ext as pet',
                )
                ->leftJoin(
                    'bs_topic as topic',
                    'topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->where(
                    'pet.bs_person_exam_id',
                    $assessment->id,
                )
                ->where(
                    function (
                        $query,
                    ): void {
                        $query
                            ->whereNull(
                                'pet.ended',
                            )
                            ->orWhere(
                                'pet.ended',
                                '',
                            );
                    },
                )
                ->orderBy(
                    'pet.order_no',
                )
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
                    ->table(
                        'bs_person_exam_topic_ext',
                    )
                    ->where(
                        'bs_person_exam_id',
                        $assessment->id,
                    )
                    ->count();

                $db->rollBack();

                if (
                    $totalCompetences === 0
                ) {
                    return response()->json(
                        [
                            'message' =>
                                'This assessment has no competences assigned. Please contact the administrator.',
                            'exam_setup_error' =>
                                true,
                        ],
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }

                return response()->json(
                    [
                        'message' =>
                            'No unfinished competence is available for this assessment.',
                        'exam_state_error' =>
                            true,
                    ],
                    Response::HTTP_CONFLICT,
                );
            }

            if (
                ! $this
                    ->parseStoredDateTime(
                        $topic->started
                            ?? null,
                    )
            ) {
                $topicStarted =
                    CarbonImmutable::now()
                        ->format(
                            'Y-m-d H:i:s',
                        );

                $db->table(
                    'bs_person_exam_topic_ext',
                )
                    ->where(
                        'id',
                        $topic->id,
                    )
                    ->update([
                        'started' =>
                            $topicStarted,
                    ]);

                $topic->started =
                    $topicStarted;
            }

            $this->ensureTopicQuestions(
                $db,
                $assessment,
                $topic,
            );

            $db->commit();

            $freshAssessment =
                $this->findAssessment(
                    $db,
                    (string) $assessment->id,
                );

            return response()->json(
                $this->activePayload(
                    $request,
                    $db,
                    $freshAssessment
                        ?? $assessment,
                    (string) $topic->id,
                ),
            );
        } catch (Throwable $throwable) {
            if (
                $db->transactionLevel()
                > 0
            ) {
                $db->rollBack();
            }

            report($throwable);

            return response()->json(
                [
                    'message' =>
                        'Unable to start or resume the examination.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    public function saveAnswer(
        Request $request,
        string $accessToken,
        string $questionAttemptId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'answer' => [
                    'required',
                    Rule::in([
                        'A',
                        'B',
                        'C',
                        'D',
                        'E',
                    ]),
                ],
            ]);

        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$db, $assessment] = $context;

        $guard =
            $this->interactionGuard(
                $assessment,
            );

        if (
            $guard
            instanceof JsonResponse
        ) {
            return $guard;
        }

        $question = $db
            ->table(
                'bs_person_exam_topic_quest_ext as question',
            )
            ->join(
                'bs_person_exam_topic_ext as topic',
                'topic.id',
                '=',
                'question.bs_person_exam_topic_id',
            )
            ->join(
                'bs_person_exam_ext as exam',
                'exam.id',
                '=',
                'topic.bs_person_exam_id',
            )
            ->where(
                'question.id',
                $questionAttemptId,
            )
            ->where(
                'exam.id',
                $assessment->id,
            )
            ->where(
                function (
                    $query,
                ): void {
                    $query
                        ->whereNull(
                            'topic.ended',
                        )
                        ->orWhere(
                            'topic.ended',
                            '',
                        );
                },
            )
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
            return response()->json(
                [
                    'message' =>
                        'The examination question could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $currentTopicId = $db
            ->table(
                'bs_person_exam_topic_ext',
            )
            ->where(
                'bs_person_exam_id',
                $assessment->id,
            )
            ->where(
                function (
                    $query,
                ): void {
                    $query
                        ->whereNull(
                            'ended',
                        )
                        ->orWhere(
                            'ended',
                            '',
                        );
                },
            )
            ->orderBy(
                'order_no',
            )
            ->value(
                'id',
            );

        if (
            ! $currentTopicId
            || ! hash_equals(
                (string) $currentTopicId,
                (string) $question
                    ->topic_attempt_id,
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'This question is not part of the current examination topic.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $answer = (string) $validated['answer'];

        $choiceNumber =
            ord($answer) - 64;

        $choiceField =
            'choice_'.$choiceNumber;

        $imageField =
            'choice_'
            .$choiceNumber
            .'_img';

        if (
            trim(
                (string) (
                    $question
                        ->{$choiceField}
                    ?? ''
                ),
            ) === ''
            && trim(
                (string) (
                    $question
                        ->{$imageField}
                    ?? ''
                ),
            ) === ''
        ) {
            return response()->json(
                [
                    'message' =>
                        'The selected answer choice is not available.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $db->table(
            'bs_person_exam_topic_quest_ext',
        )
            ->where(
                'id',
                $questionAttemptId,
            )
            ->update([
                'answer' => $answer,
            ]);

        return response()->json([
            'success' => true,
            'answer' => $answer,
        ]);
    }

    public function submitTopic(
        string $accessToken,
        string $topicAttemptId,
    ): JsonResponse {
        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$db, $assessment] = $context;

        $guard =
            $this->interactionGuard(
                $assessment,
            );

        if (
            $guard
            instanceof JsonResponse
        ) {
            return $guard;
        }

        try {
            $db->beginTransaction();

            $topic = $db
                ->table(
                    'bs_person_exam_topic_ext as pet',
                )
                ->join(
                    'bs_topic as source_topic',
                    'source_topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->join(
                    'bs_person_exam_ext as exam',
                    'exam.id',
                    '=',
                    'pet.bs_person_exam_id',
                )
                ->where(
                    'pet.id',
                    $topicAttemptId,
                )
                ->where(
                    'exam.id',
                    $assessment->id,
                )
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

                return response()->json(
                    [
                        'message' =>
                            'The examination topic could not be found.',
                    ],
                    Response::HTTP_NOT_FOUND,
                );
            }

            $currentTopicId = $db
                ->table(
                    'bs_person_exam_topic_ext',
                )
                ->where(
                    'bs_person_exam_id',
                    $assessment->id,
                )
                ->where(
                    function (
                        $query,
                    ): void {
                        $query
                            ->whereNull(
                                'ended',
                            )
                            ->orWhere(
                                'ended',
                                '',
                            );
                    },
                )
                ->orderBy(
                    'order_no',
                )
                ->value(
                    'id',
                );

            if (
                ! $currentTopicId
                || ! hash_equals(
                    (string) $currentTopicId,
                    (string) $topic->id,
                )
            ) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'Only the current examination topic may be submitted.',
                    ],
                    Response::HTTP_CONFLICT,
                );
            }

            if (
                $this
                    ->parseStoredDateTime(
                        $topic->ended
                            ?? null,
                    )
            ) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'This topic has already been submitted.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $questions = $db
                ->table(
                    'bs_person_exam_topic_quest_ext',
                )
                ->where(
                    'bs_person_exam_topic_id',
                    $topicAttemptId,
                )
                ->orderBy(
                    'last_update',
                )
                ->orderBy(
                    'id',
                )
                ->get([
                    'id',
                    'answer',
                    'correct_ans',
                ]);

            $unanswered = [];
            $score = 0;

            foreach (
                $questions
                as $index => $question
            ) {
                $answer = trim(
                    (string) (
                        $question->answer
                        ?? ''
                    ),
                );

                if ($answer === '') {
                    $unanswered[] =
                        $index + 1;

                    continue;
                }

                if (
                    $answer
                    === trim(
                        (string) (
                            $question
                                ->correct_ans
                            ?? ''
                        ),
                    )
                ) {
                    $score++;
                }
            }

            if ($unanswered !== []) {
                $db->rollBack();

                return response()->json(
                    [
                        'message' =>
                            'Please answer all questions before submitting.',
                        'unanswered_numbers' =>
                            $unanswered,
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $now =
                CarbonImmutable::now()
                    ->format(
                        'Y-m-d H:i:s',
                    );

            $db->table(
                'bs_person_exam_topic_quest_ext',
            )
                ->where(
                    'bs_person_exam_topic_id',
                    $topicAttemptId,
                )
                ->whereNotNull(
                    'answer',
                )
                ->where(
                    'answer',
                    '<>',
                    '',
                )
                ->update([
                    'is_saved' => 'Y',
                    'date_taken' =>
                        $now,
                ]);

            $passingMark = (float) (
                $topic
                    ->passing_mark
                ?? 0
            );

            $passed =
                $score >= $passingMark
                    ? 'Y'
                    : 'N';

            $db->table(
                'bs_person_exam_topic_ext',
            )
                ->where(
                    'id',
                    $topicAttemptId,
                )
                ->update([
                    'ended' => $now,
                    'score' => $score,
                    'passed' => $passed,
                ]);

            $overall =
                $this->calculateOverall(
                    $db,
                    (string) $assessment->id,
                    (string) $assessment
                        ->bs_course_id,
                );

            $db->table(
                'bs_person_exam_ext',
            )
                ->where(
                    'id',
                    $assessment->id,
                )
                ->update([
                    'ended' => $now,
                    'score' =>
                        $overall[
                            'score'
                        ],
                    'passed' =>
                        $overall[
                            'passed'
                        ],
                ]);

            $nextTopic = $db
                ->table(
                    'bs_person_exam_topic_ext as pet',
                )
                ->leftJoin(
                    'bs_topic as source_topic',
                    'source_topic.id',
                    '=',
                    'pet.bs_topic_id',
                )
                ->where(
                    'pet.bs_person_exam_id',
                    $assessment->id,
                )
                ->where(
                    'pet.order_no',
                    '>',
                    (int) $topic->order_no,
                )
                ->orderBy(
                    'pet.order_no',
                )
                ->select([
                    'pet.id',
                    'pet.bs_topic_id',
                    'pet.order_no',
                    'source_topic.desc_topic',
                ])
                ->first();

            $done =
                $nextTopic
                    ? 'N'
                    : 'Y';

            if (! $nextTopic) {
                $db->table(
                    'bs_person_exam_ext',
                )
                    ->where(
                        'id',
                        $assessment->id,
                    )
                    ->update([
                        'done' => 'Y',
                        'ended' => $now,
                    ]);
            }

            $db->commit();

            return response()->json([
                'success' => true,

                'done' => $done,

                'topic_result' => [
                    'topic' =>
                        $this->decodeText(
                            $topic
                                ->desc_topic
                            ?? '',
                        ),

                    'order_no' => (int) (
                        $topic
                            ->order_no
                        ?? 0
                    ),

                    'completed' =>
                        true,
                ],

                'next_topic' =>
                    $nextTopic
                        ? [
                            'id' => (string) $nextTopic
                                ->id,

                            'bs_topic_id' => (string) $nextTopic
                                ->bs_topic_id,

                            'order_no' => (int) $nextTopic
                                ->order_no,

                            'description' =>
                                $this->decodeText(
                                    $nextTopic
                                        ->desc_topic
                                    ?? '',
                                ),
                        ]
                        : null,

                'final_result' =>
                    $done === 'Y'
                        ? $this
                            ->finalResult(
                                $db,
                                $assessment,
                                (float) $overall[
                                    'score'
                                ],
                                (string) $overall[
                                    'passed'
                                ],
                            )
                        : null,

                'message' =>
                    $done === 'Y'
                        ? 'The final competence has been completed. The examination is complete.'
                        : 'Competence completed. You may proceed to the next competence.',
            ]);
        } catch (Throwable $throwable) {
            if (
                $db->transactionLevel()
                > 0
            ) {
                $db->rollBack();
            }

            report($throwable);

            return response()->json(
                [
                    'message' =>
                        'Unable to submit the examination topic.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    public function timeout(
        string $accessToken,
    ): JsonResponse {
        $context = $this->context(
            $accessToken,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$db, $assessment] = $context;

        if (
            $this->isCompletedAssessment(
                $assessment,
            )
        ) {
            return response()->json([
                'success' => true,
                'completed' => true,
                'final_result' =>
                    $this->finalResult(
                        $db,
                        $assessment,
                    ),
                'message' =>
                    'The examination has already been completed.',
            ]);
        }

        $startedAt =
            $this->parseStoredDateTime(
                $assessment->started
                    ?? null,
            );

        $expiresAt =
            $this->examExpiresAt(
                $startedAt,
                (int) (
                    $assessment
                        ->duration
                    ?? 0
                ),
            );

        if (
            ! $startedAt
            || ! $expiresAt
        ) {
            return response()->json(
                [
                    'message' =>
                        'The examination timer has not started.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            CarbonImmutable::now()
                ->lt(
                    $expiresAt,
                )
        ) {
            return response()->json(
                [
                    'message' =>
                        'The examination still has time remaining.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $this->finalizeTimedOutExam(
                $db,
                $assessment,
            );

            $finalAssessment =
                $this->findAssessment(
                    $db,
                    (string) $assessment->id,
                );

            return response()->json([
                'success' => true,
                'completed' => true,

                'final_result' =>
                    $finalAssessment
                        ? $this
                            ->finalResult(
                                $db,
                                $finalAssessment,
                            )
                        : null,

                'message' =>
                    'Time is up. Your saved answers have been submitted.',
            ]);
} catch (Throwable $throwable) {
    report($throwable);

    return response()->json(
        [
            'message' => 'Unable to validate this external assessment link.',
            'error' => $throwable->getMessage(),
            'exception' => get_class($throwable),
        ],
        Response::HTTP_INTERNAL_SERVER_ERROR,
    );
}
    }

    private function context(
        string $accessToken,
    ): array|JsonResponse {
        try {
            $access =
                $this->accessService
                    ->resolveAccess(
                        $accessToken,
                        ExternalAssessmentAccessService::TYPE_THEORETICAL,
                    );

            $db = $access['db'];

            $assessment =
                $this->findAssessment(
                    $db,
                    $access[
                        'token'
                    ][
                        'assessment_id'
                    ],
                );

            if (! $assessment) {
                return response()->json(
                    [
                        'message' =>
                            'The external theoretical assessment could not be found.',
                    ],
                    Response::HTTP_NOT_FOUND,
                );
            }

            return [
                $db,
                $assessment,
                $access['token'],
            ];
        } catch (RuntimeException $exception) {
            return response()->json(
                [
                    'message' =>
                        $exception
                            ->getMessage(),
                ],
                Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json(
                [
                    'message' =>
                        'Unable to validate this external assessment link.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function findAssessment(
        ConnectionInterface $db,
        string $assessmentId,
    ): ?object {
        return $db
            ->table(
                'bs_person_exam_ext',
            )
            ->leftJoin(
                'bs_course',
                'bs_course.id',
                '=',
                'bs_person_exam_ext.bs_course_id',
            )
            ->leftJoin(
                'bs_exam_session',
                'bs_exam_session.id',
                '=',
                'bs_person_exam_ext.bs_exam_session_id',
            )
            ->where(
                'bs_person_exam_ext.id',
                $assessmentId,
            )
            ->select([
                'bs_person_exam_ext.id',
                'bs_person_exam_ext.email',
                'bs_person_exam_ext.fname',
                'bs_person_exam_ext.mname',
                'bs_person_exam_ext.lname',
                'bs_person_exam_ext.bs_course_id',
                'bs_person_exam_ext.bs_exam_session_id',
                'bs_person_exam_ext.exam_type',
                'bs_person_exam_ext.proctor_name',
                'bs_person_exam_ext.started',
                'bs_person_exam_ext.ended',
                'bs_person_exam_ext.score',
                'bs_person_exam_ext.passed',
                'bs_person_exam_ext.done',
                'bs_person_exam_ext.duration',
                'bs_person_exam_ext.access_exp_date',
                'bs_person_exam_ext.access_exp_time',
                'bs_person_exam_ext.access_exp_date_to',
                'bs_person_exam_ext.access_exp_time_to',
                'bs_person_exam_ext.login_id',
                'bs_course.name_course',
                'bs_course.randomize',
                'bs_exam_session.session_code',
            ])
            ->first();
    }

    private function actionState(
        array|object $assessment,
    ): array {
        $record =
            is_object($assessment)
                ? get_object_vars(
                    $assessment,
                )
                : $assessment;

        if (
            strtoupper(
                trim(
                    (string) (
                        $record['done']
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return [
                'exam_action_state' =>
                    'completed',
                'exam_action_label' =>
                    'Exam Completed',
                'can_open_exam' =>
                    false,
                'expires_at' =>
                    null,
            ];
        }

        $startedAt =
            $this->parseStoredDateTime(
                $record['started']
                    ?? null,
            );

        $duration = (int) (
            $record['duration']
            ?? 0
        );

        $expiresAt =
            $this->examExpiresAt(
                $startedAt,
                $duration,
            );

        $now =
            CarbonImmutable::now();

        if ($startedAt) {
            if (
                $expiresAt
                && $now->gte(
                    $expiresAt,
                )
            ) {
                return [
                    'exam_action_state' =>
                        'time_expired',
                    'exam_action_label' =>
                        'Finalize Timed Out Exam',
                    'can_open_exam' =>
                        true,
                    'expires_at' =>
                        $expiresAt
                            ->toIso8601String(),
                ];
            }

            return [
                'exam_action_state' =>
                    'resume',
                'exam_action_label' =>
                    'Resume Exam',
                'can_open_exam' =>
                    true,
                'expires_at' =>
                    $expiresAt
                        ?->toIso8601String(),
            ];
        }

        $accessStart =
            $this->combineStoredDateTime(
                $record[
                    'access_exp_date'
                ] ?? null,
                $record[
                    'access_exp_time'
                ] ?? null,
            );

        $accessEnd =
            $this->combineStoredDateTime(
                $record[
                    'access_exp_date_to'
                ] ?? null,
                $record[
                    'access_exp_time_to'
                ] ?? null,
            );

        if (
            $accessStart
            && $now->lt(
                $accessStart,
            )
        ) {
            return [
                'exam_action_state' =>
                    'not_available',
                'exam_action_label' =>
                    'Not Available Yet',
                'can_open_exam' =>
                    false,
                'expires_at' =>
                    null,
            ];
        }

        if (
            $accessEnd
            && $now->gt(
                $accessEnd,
            )
        ) {
            return [
                'exam_action_state' =>
                    'expired',
                'exam_action_label' =>
                    'Access Expired',
                'can_open_exam' =>
                    false,
                'expires_at' =>
                    null,
            ];
        }

        return [
            'exam_action_state' =>
                'start',
            'exam_action_label' =>
                'Start Exam',
            'can_open_exam' =>
                true,
            'expires_at' =>
                null,
        ];
    }

    private function metadata(
        ConnectionInterface $db,
        object $assessment,
    ): array {
        $state =
            $this->actionState(
                $assessment,
            );

        $startedAt =
            $this->parseStoredDateTime(
                $assessment->started
                    ?? null,
            );

        $accessStart =
            $this->combineStoredDateTime(
                $assessment
                    ->access_exp_date
                    ?? null,
                $assessment
                    ->access_exp_time
                    ?? null,
            );

        $accessEnd =
            $this->combineStoredDateTime(
                $assessment
                    ->access_exp_date_to
                    ?? null,
                $assessment
                    ->access_exp_time_to
                    ?? null,
            );

        return array_merge(
            $state,
            [
                'id' => (string) $assessment->id,

                'examinee_name' =>
                    $this
                        ->formatExternalName(
                            $assessment,
                        ),

                'email' => (string) (
                    $assessment->email
                    ?? ''
                ),

                'name_course' => (string) (
                    $assessment
                        ->name_course
                    ?? ''
                ),

                'session_code' => (string) (
                    $assessment
                        ->session_code
                    ?? ''
                ),

                'exam_type' => (string) (
                    $assessment
                        ->exam_type
                    ?? ''
                ),

                'proctor_name' => (string) (
                    $assessment
                        ->proctor_name
                    ?? ''
                ),

                'duration' => (int) (
                    $assessment
                        ->duration
                    ?? 0
                ),

                'started_at' =>
                    $startedAt
                        ?->toIso8601String(),

                'access_start' =>
                    $accessStart
                        ?->toIso8601String(),

                'access_end' =>
                    $accessEnd
                        ?->toIso8601String(),

                'server_time' =>
                    CarbonImmutable::now()
                        ->toIso8601String(),

                'done' =>
                    $this
                        ->isCompletedAssessment(
                            $assessment,
                        ),

                'remarks' =>
                    $this
                        ->isCompletedAssessment(
                            $assessment,
                        )
                        ? (
                            strtoupper(
                                trim(
                                    (string) (
                                        $assessment
                                            ->passed
                                        ?? ''
                                    ),
                                ),
                            ) === 'Y'
                                ? 'PASSED'
                                : 'FAILED'
                        )
                        : null,

                'final_result' =>
                    $this
                        ->isCompletedAssessment(
                            $assessment,
                        )
                        ? $this
                            ->finalResult(
                                $db,
                                $assessment,
                            )
                        : null,
            ],
        );
    }

    private function activePayload(
        Request $request,
        ConnectionInterface $db,
        object $assessment,
        string $topicAttemptId,
    ): array {
        $topic = $db
            ->table(
                'bs_person_exam_topic_ext as pet',
            )
            ->leftJoin(
                'bs_topic as source_topic',
                'source_topic.id',
                '=',
                'pet.bs_topic_id',
            )
            ->where(
                'pet.id',
                $topicAttemptId,
            )
            ->where(
                'pet.bs_person_exam_id',
                $assessment->id,
            )
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
                'exam' =>
                    $this->metadata(
                        $db,
                        $assessment,
                    ),
                'topic' => null,
                'questions' => [],
            ];
        }

        $totalCompetences = (int) $db
            ->table(
                'bs_person_exam_topic_ext',
            )
            ->where(
                'bs_person_exam_id',
                $assessment->id,
            )
            ->count();

        $questions = $db
            ->table(
                'bs_person_exam_topic_quest_ext as attempt',
            )
            ->leftJoin(
                'bs_quest as source_question',
                'source_question.id',
                '=',
                'attempt.bs_quest_id',
            )
            ->where(
                'attempt.bs_person_exam_topic_id',
                $topicAttemptId,
            )
            ->orderBy(
                'attempt.last_update',
            )
            ->orderBy(
                'attempt.id',
            )
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
            ->map(
                function (
                    $question,
                    int $index,
                ) use (
                    $request,
                ): array {
                    $choices = [];

                    foreach (
                        range(
                            1,
                            5,
                        )
                        as $choiceNumber
                    ) {
                        $letter =
                            chr(
                                64
                                + $choiceNumber,
                            );

                        $textField =
                            'choice_'
                            .$choiceNumber;

                        $imageField =
                            'choice_'
                            .$choiceNumber
                            .'_img';

                        $choiceText =
                            $this
                                ->decodeText(
                                    $question
                                        ->{$textField}
                                    ?? '',
                                );

                        $imageUrl =
                            $this
                                ->buildQuestionImageUrl(
                                    $request,
                                    $question
                                        ->{$imageField}
                                    ?? null,
                                );

                        if (
                            $choiceText
                                === ''
                            && ! $imageUrl
                        ) {
                            continue;
                        }

                        $choices[] = [
                            'key' =>
                                $letter,

                            'text' =>
                                $choiceText,

                            'image_url' =>
                                $imageUrl,
                        ];
                    }

                    return [
                        'id' => (string) $question->id,

                        'index' =>
                            $index + 1,

                        'question' =>
                            $this
                                ->decodeText(
                                    $question
                                        ->quest_text
                                    ?? '',
                                ),

                        'answer' =>
                            trim(
                                (string) (
                                    $question
                                        ->answer
                                    ?? ''
                                ),
                            ),

                        'choices' =>
                            $choices,
                    ];
                },
            )
            ->all();

        return [
            'exam' =>
                $this->metadata(
                    $db,
                    $assessment,
                ),

            'topic' => [
                'id' => (string) $topic->id,

                'bs_topic_id' => (string) $topic
                    ->bs_topic_id,

                'description' =>
                    $this->decodeText(
                        $topic
                            ->desc_topic
                        ?? '',
                    ),

                'order_no' => (int) (
                    $topic
                        ->order_no
                    ?? 0
                ),

                'total_competences' =>
                    $totalCompetences,

                'quest_cnt' => (int) (
                    $topic
                        ->quest_cnt
                    ?? 0
                ),

                'started_at' =>
                    $this
                        ->parseStoredDateTime(
                            $topic
                                ->started
                            ?? null,
                        )
                        ?->toIso8601String(),
            ],

            'questions' =>
                $questions,

            'answered_count' =>
                collect(
                    $questions,
                )
                    ->where(
                        'answer',
                        '<>',
                        '',
                    )
                    ->count(),
        ];
    }

    private function interactionGuard(
        object $assessment,
    ): ?JsonResponse {
        if (
            $this->isCompletedAssessment(
                $assessment,
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'This examination has already been completed.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $startedAt =
            $this->parseStoredDateTime(
                $assessment->started
                    ?? null,
            );

        if (! $startedAt) {
            return response()->json(
                [
                    'message' =>
                        'The examination has not started yet.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $expiresAt =
            $this->examExpiresAt(
                $startedAt,
                (int) (
                    $assessment
                        ->duration
                    ?? 0
                ),
            );

        if (
            $expiresAt
            && CarbonImmutable::now()
                ->gte(
                    $expiresAt,
                )
        ) {
            return response()->json(
                [
                    'message' =>
                        'The examination time has expired.',
                    'time_expired' =>
                        true,
                ],
                Response::HTTP_CONFLICT,
            );
        }

        return null;
    }

    private function ensureTopicQuestions(
        ConnectionInterface $db,
        object $assessment,
        object $topic,
    ): void {
        $assignedCount = (int) (
            $topic->quest_cnt
            ?? 0
        );

        if ($assignedCount <= 0) {
            $assignedCount = (int) (
                $topic->no_quest
                ?? 0
            );
        }

        if ($assignedCount <= 0) {
            throw new RuntimeException(
                'The selected topic has no required question count.',
            );
        }

        $existingQuestionIds =
            $db->table(
                'bs_person_exam_topic_quest_ext',
            )
                ->where(
                    'bs_person_exam_topic_id',
                    $topic->id,
                )
                ->pluck(
                    'bs_quest_id',
                )
                ->filter()
                ->values()
                ->all();

        $remaining =
            $assignedCount
            - count(
                $existingQuestionIds,
            );

        if ($remaining <= 0) {
            return;
        }

        $questionQuery =
            $db->table(
                'bs_quest',
            )
                ->where(
                    'bs_topic_id',
                    $topic
                        ->bs_topic_id,
                )
                ->where(
                    'level',
                    1,
                )
                ->where(
                    'active',
                    'Y',
                );

        if (
            $existingQuestionIds
            !== []
        ) {
            $questionQuery
                ->whereNotIn(
                    'id',
                    $existingQuestionIds,
                );
        }

        $randomize =
            strtoupper(
                trim(
                    (string) (
                        $assessment
                            ->randomize
                        ?? ''
                    ),
                ),
            ) === 'Y';

        if ($randomize) {
            $questionQuery
                ->inRandomOrder();
        } else {
            $questionQuery
                ->orderBy(
                    'quest_text',
                );
        }

        $sourceQuestions =
            $questionQuery
                ->limit(
                    $remaining,
                )
                ->get([
                    'id',
                ]);

        if (
            $sourceQuestions
                ->count()
            < $remaining
        ) {
            throw new RuntimeException(
                'There are not enough active questions for this topic.',
            );
        }

        foreach (
            $sourceQuestions
            as $sourceQuestion
        ) {
            $answerQuery =
                $db->table(
                    'bs_quest_ans',
                )
                    ->where(
                        'bs_quest_id',
                        $sourceQuestion
                            ->id,
                    );

            if ($randomize) {
                $answerQuery
                    ->inRandomOrder();
            } else {
                $answerQuery
                    ->orderBy(
                        'answer_text',
                    );
            }

            $sourceAnswers =
                $answerQuery
                    ->limit(5)
                    ->get([
                        'answer_text',
                        'filename',
                        'answer',
                    ]);

            $choices =
                array_fill(
                    0,
                    5,
                    '',
                );

            $choiceImages =
                array_fill(
                    0,
                    5,
                    '',
                );

            $correctAnswer = '';

            foreach (
                $sourceAnswers
                as $index =>
                    $sourceAnswer
            ) {
                if ($index > 4) {
                    break;
                }

                $choices[$index] = (string) (
                    $sourceAnswer
                        ->answer_text
                    ?? ''
                );

                $choiceImages[$index] = (string) (
                    $sourceAnswer
                        ->filename
                    ?? ''
                );

                if (
                    (
                        $sourceAnswer
                            ->answer
                        ?? ''
                    ) === 'Y'
                ) {
                    $correctAnswer =
                        chr(
                            65 + $index,
                        );
                }
            }

            if (
                $correctAnswer === ''
            ) {
                throw new RuntimeException(
                    'A selected question has no correct answer configured.',
                );
            }

            $db->table(
                'bs_person_exam_topic_quest_ext',
            )->insert([
                'id' => (string) Str::uuid(),

                'bs_person_exam_topic_id' => (string) $topic->id,

                'bs_quest_id' => (string) $sourceQuestion
                    ->id,

                'correct_ans' =>
                    $correctAnswer,

                'answer' => '',

                'is_saved' => 'N',

                'login_id' => (string) (
                    $assessment
                        ->login_id
                    ?? ''
                ),

                'last_update' =>
                    CarbonImmutable::now()
                        ->format(
                            'Y-m-d H:i:s',
                        ),

                'choice_1' =>
                    $choices[0],

                'choice_2' =>
                    $choices[1],

                'choice_3' =>
                    $choices[2],

                'choice_4' =>
                    $choices[3],

                'choice_5' =>
                    $choices[4],

                'choice_1_img' =>
                    $choiceImages[0],

                'choice_2_img' =>
                    $choiceImages[1],

                'choice_3_img' =>
                    $choiceImages[2],

                'choice_4_img' =>
                    $choiceImages[3],

                'choice_5_img' =>
                    $choiceImages[4],

                'stud_type' => '',

                'bs_topic_id' => (string) $topic
                    ->bs_topic_id,

                'school_class' =>
                    CarbonImmutable::now()
                        ->format('Y'),

                'company_id' => '',

                'person_id' => '',

                'bs_exam_session_id' => (string) (
                    $assessment
                        ->bs_exam_session_id
                    ?? ''
                ),

                'date_taken' =>
                    CarbonImmutable::now()
                        ->format(
                            'Y-m-d',
                        ),
            ]);
        }
    }

    private function calculateOverall(
        ConnectionInterface $db,
        string $assessmentId,
        string $courseId,
    ): array {
        $courseTopics = $db
            ->table(
                'bs_topic',
            )
            ->where(
                'bs_course_id',
                $courseId,
            )
            ->get([
                'id',
                'passing_mark',
            ]);

        $passingScore = 0.0;
        $overallScore = 0.0;

        foreach (
            $courseTopics
            as $courseTopic
        ) {
            $passingScore += (float) (
                $courseTopic
                    ->passing_mark
                ?? 0
            );

            $latestScore = $db
                ->table(
                    'bs_person_exam_topic_ext',
                )
                ->where(
                    'bs_person_exam_id',
                    $assessmentId,
                )
                ->where(
                    'bs_topic_id',
                    $courseTopic->id,
                )
                ->orderByDesc(
                    'started',
                )
                ->value(
                    'score',
                );

            if (
                $latestScore
                !== null
            ) {
                $overallScore += (float) $latestScore;
            }
        }

        return [
            'score' =>
                $overallScore,

            'passing_score' =>
                $passingScore,

            'passed' =>
                $overallScore
                    >= $passingScore
                    ? 'Y'
                    : 'N',
        ];
    }

    private function finalResult(
        ConnectionInterface $db,
        object $assessment,
        ?float $score = null,
        ?string $passed = null,
    ): array {
        $resolvedScore =
            $score
            ?? (float) (
                $assessment->score
                ?? 0
            );

        $resolvedPassed =
            strtoupper(
                trim(
                    (string) (
                        $passed
                        ?? $assessment
                            ->passed
                        ?? ''
                    ),
                ),
            );

        $totalItems = (int) $db
            ->table(
                'bs_person_exam_topic_ext',
            )
            ->where(
                'bs_person_exam_id',
                $assessment->id,
            )
            ->sum(
                'quest_cnt',
            );

        if ($totalItems <= 0) {
            $totalItems = (int) $db
                ->table(
                    'bs_person_exam_topic_quest_ext as attempt',
                )
                ->join(
                    'bs_person_exam_topic_ext as topic',
                    'topic.id',
                    '=',
                    'attempt.bs_person_exam_topic_id',
                )
                ->where(
                    'topic.bs_person_exam_id',
                    $assessment->id,
                )
                ->count();
        }

        return [
            'remarks' =>
                $resolvedPassed === 'Y'
                    ? 'PASSED'
                    : 'FAILED',

            'score' =>
                $resolvedScore,

            'total_items' =>
                $totalItems,

            'percentage' =>
                $totalItems > 0
                    ? round(
                        (
                            $resolvedScore
                            / $totalItems
                        ) * 100,
                        1,
                    )
                    : 0.0,

            'exam_package' => (string) (
                $assessment
                    ->name_course
                ?? ''
            ),
        ];
    }

    private function finalizeTimedOutExam(
        ConnectionInterface $db,
        object $assessment,
    ): void {
        $db->transaction(
            function () use (
                $db,
                $assessment,
            ): void {
                $now =
                    CarbonImmutable::now()
                        ->format(
                            'Y-m-d H:i:s',
                        );

                $topics = $db
                    ->table(
                        'bs_person_exam_topic_ext as pet',
                    )
                    ->leftJoin(
                        'bs_topic as source_topic',
                        'source_topic.id',
                        '=',
                        'pet.bs_topic_id',
                    )
                    ->where(
                        'pet.bs_person_exam_id',
                        $assessment->id,
                    )
                    ->orderBy(
                        'pet.order_no',
                    )
                    ->select([
                        'pet.id',
                        'pet.started',
                        'pet.ended',
                        'pet.order_no',
                        'source_topic.passing_mark',
                    ])
                    ->lockForUpdate()
                    ->get();

                $currentUnfinishedFound =
                    false;

                foreach (
                    $topics
                    as $topic
                ) {
                    if (
                        $this
                            ->parseStoredDateTime(
                                $topic->ended
                                    ?? null,
                            )
                    ) {
                        continue;
                    }

                    $isCurrent =
                        ! $currentUnfinishedFound;

                    $currentUnfinishedFound =
                        true;

                    $score = 0;

                    if ($isCurrent) {
                        $questions = $db
                            ->table(
                                'bs_person_exam_topic_quest_ext',
                            )
                            ->where(
                                'bs_person_exam_topic_id',
                                $topic->id,
                            )
                            ->get([
                                'id',
                                'answer',
                                'correct_ans',
                            ]);

                        foreach (
                            $questions
                            as $question
                        ) {
                            $answer =
                                trim(
                                    (string) (
                                        $question
                                            ->answer
                                        ?? ''
                                    ),
                                );

                            if (
                                $answer
                                    !== ''
                                && $answer
                                    === trim(
                                        (string) (
                                            $question
                                                ->correct_ans
                                            ?? ''
                                        ),
                                    )
                            ) {
                                $score++;
                            }
                        }
                    }

                    $db->table(
                        'bs_person_exam_topic_quest_ext',
                    )
                        ->where(
                            'bs_person_exam_topic_id',
                            $topic->id,
                        )
                        ->update([
                            'is_saved' =>
                                'Y',
                        ]);

                    $passingMark = (float) (
                        $topic
                            ->passing_mark
                        ?? 0
                    );

                    $started =
                        $this
                            ->parseStoredDateTime(
                                $topic->started
                                    ?? null,
                            )
                            ? (string) $topic
                                ->started
                            : $now;

                    $db->table(
                        'bs_person_exam_topic_ext',
                    )
                        ->where(
                            'id',
                            $topic->id,
                        )
                        ->update([
                            'started' =>
                                $started,

                            'ended' =>
                                $now,

                            'score' =>
                                $score,

                            'passed' =>
                                $score
                                    >= $passingMark
                                    ? 'Y'
                                    : 'N',
                        ]);
                }

                $overall =
                    $this->calculateOverall(
                        $db,
                        (string) $assessment->id,
                        (string) $assessment
                            ->bs_course_id,
                    );

                $db->table(
                    'bs_person_exam_ext',
                )
                    ->where(
                        'id',
                        $assessment->id,
                    )
                    ->update([
                        'ended' => $now,

                        'score' =>
                            $overall[
                                'score'
                            ],

                        'passed' =>
                            $overall[
                                'passed'
                            ],

                        'done' => 'Y',
                    ]);
            },
        );
    }

    private function buildQuestionImageUrl(
        Request $request,
        mixed $filename,
    ): ?string {
        $cleanFilename = basename(
            str_replace(
                '\\',
                '/',
                trim(
                    (string) $filename,
                ),
            ),
        );

        if (
            $cleanFilename === ''
        ) {
            return null;
        }

        /*
         * The token is the source of school authority.
         * Recover it from the route and never trust a query parameter.
         */
        $token = (string) $request
            ->route(
                'accessToken',
                '',
            );

        try {
            $payload =
                $this->accessService
                    ->resolveToken(
                        $token,
                        ExternalAssessmentAccessService::TYPE_THEORETICAL,
                    );

            $schoolCode =
                $payload['school'];
        } catch (Throwable) {
            return null;
        }

        $configured = trim(
            (string) config(
                "schools.schools.{$schoolCode}.files.question_images_url",
                '',
            ),
        );

        if ($configured !== '') {
            return rtrim(
                $configured,
                '/',
            )
                .'/'
                .rawurlencode(
                    $cleanFilename,
                );
        }

        $photosUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.photos_url",
                    '',
                ),
            ),
            '/',
        );

        if ($photosUrl === '') {
            return null;
        }

        $baseUrl =
            preg_replace(
                '#/photos$#i',
                '',
                $photosUrl,
            )
            ?: $photosUrl;

        return rtrim(
            $baseUrl,
            '/',
        )
            .'/question_images/'
            .rawurlencode(
                $cleanFilename,
            );
    }

    private function isCompletedAssessment(
        object|array $assessment,
    ): bool {
        $done =
            is_object($assessment)
                ? (
                    $assessment->done
                    ?? ''
                )
                : (
                    $assessment['done']
                    ?? ''
                );

        return strtoupper(
            trim(
                (string) $done,
            ),
        ) === 'Y';
    }

    private function parseStoredDateTime(
        mixed $value,
    ): ?CarbonImmutable {
        $raw = trim(
            (string) $value,
        );

        if (
            $raw === ''
            || str_starts_with(
                $raw,
                '0000-00-00',
            )
            || str_starts_with(
                $raw,
                '1970-01-01',
            )
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $raw,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function combineStoredDateTime(
        mixed $date,
        mixed $time,
    ): ?CarbonImmutable {
        $dateValue = trim(
            (string) $date,
        );

        $timeValue = trim(
            (string) $time,
        );

        if (
            $dateValue === ''
            || str_starts_with(
                $dateValue,
                '0000-00-00',
            )
        ) {
            return null;
        }

        if ($timeValue === '') {
            $timeValue =
                '00:00:00';
        }

        try {
            return CarbonImmutable::parse(
                $dateValue
                .' '
                .$timeValue,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function examExpiresAt(
        ?CarbonImmutable $startedAt,
        int $durationMinutes,
    ): ?CarbonImmutable {
        if (
            ! $startedAt
            || $durationMinutes <= 0
        ) {
            return null;
        }

        return $startedAt
            ->addMinutes(
                $durationMinutes,
            );
    }

    private function formatExternalName(
        object $assessment,
    ): string {
        $lastName = trim(
            (string) (
                $assessment->lname
                ?? ''
            ),
        );

        $firstName = trim(
            (string) (
                $assessment->fname
                ?? ''
            ),
        );

        $middleName = trim(
            (string) (
                $assessment->mname
                ?? ''
            ),
        );

        return trim(
            $lastName
            .', '
            .$firstName
            .' '
            .$middleName,
            ' ,',
        );
    }

    private function decodeText(
        mixed $value,
    ): string {
        return str_replace(
            'andxx',
            '&',
            urldecode(
                (string) $value,
            ),
        );
    }
}