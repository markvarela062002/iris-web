<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use App\Services\ExternalAssessmentAccessService;
use App\Services\IrisEmailService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TheoreticalExternalAssessmentsController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
        private readonly ExternalAssessmentAccessService $externalAssessmentAccessService,
        private readonly IrisEmailService $irisEmailService,
    ) {
    }

    /**
     * Return External theoretical assessments.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        /*
         * Count the actual questions assigned to every exam.
         */
        $examTotals = $db
            ->table('bs_person_exam_topic_ext')
            ->leftJoin(
                'bs_person_exam_topic_quest_ext',
                'bs_person_exam_topic_quest_ext.bs_person_exam_topic_id',
                '=',
                'bs_person_exam_topic_ext.id',
            )
            ->select([
                'bs_person_exam_topic_ext.bs_person_exam_id',
                DB::raw(
                    'COUNT(bs_person_exam_topic_quest_ext.id) AS total_items',
                ),
            ])
            ->groupBy(
                'bs_person_exam_topic_ext.bs_person_exam_id',
            );
        $query = $db
            ->table('bs_person_exam_ext')
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
            ->leftJoinSub(
                $examTotals,
                'exam_totals',
                'exam_totals.bs_person_exam_id',
                '=',
                'bs_person_exam_ext.id',
            )
            ->when(
                $request->filled('course_id'),
                fn ($query) => $query->where(
                    'bs_person_exam_ext.bs_course_id',
                    $request->string('course_id')->trim()->toString(),
                ),
            )
            ->when(
                $request->filled('session_id'),
                fn ($query) => $query->where(
                    'bs_person_exam_ext.bs_exam_session_id',
                    $request->string('session_id')->trim()->toString(),
                ),
            )
            ->when(
                $request->filled('email'),
                fn ($query) => $query->where(
                    'bs_person_exam_ext.email',
                    'like',
                    '%'.$request->string('email')->trim().'%',
                ),
            )
            ->when(
                $request->filled('examinee_name'),
                function ($query) use ($request): void {
                    $name = $request
                        ->string('examinee_name')
                        ->trim()
                        ->toString();
                    $query->where(function ($query) use ($name): void {
                        $query
                            ->where(
                                'bs_person_exam_ext.lname',
                                'like',
                                "%{$name}%",
                            )
                            ->orWhere(
                                'bs_person_exam_ext.fname',
                                'like',
                                "%{$name}%",
                            )
                            ->orWhere(
                                'bs_person_exam_ext.mname',
                                'like',
                                "%{$name}%",
                            );
                    });
                },
            )
            ->when(
                $request->filled('date_from'),
                fn ($query) => $query->whereDate(
                    'bs_person_exam_ext.access_exp_date',
                    '>=',
                    $request->string('date_from')->toString(),
                ),
            )
            ->when(
                $request->filled('date_to'),
                fn ($query) => $query->whereDate(
                    'bs_person_exam_ext.access_exp_date_to',
                    '<=',
                    $request->string('date_to')->toString(),
                ),
            )
            ->select([
                'bs_person_exam_ext.id',
                'bs_person_exam_ext.bs_course_id',
                'bs_person_exam_ext.bs_exam_session_id',
                'bs_person_exam_ext.email',
                'bs_person_exam_ext.fname',
                'bs_person_exam_ext.mname',
                'bs_person_exam_ext.lname',
                'bs_person_exam_ext.exam_type',
                'bs_person_exam_ext.proctor_name',
                'bs_person_exam_ext.score',
                'bs_person_exam_ext.done',
                'bs_person_exam_ext.started',
                'bs_person_exam_ext.ended',
                'bs_person_exam_ext.access_exp_date',
                'bs_person_exam_ext.access_exp_date_to',
                'bs_course.name_course',
                'bs_exam_session.session_code',
                DB::raw(
                    'COALESCE(exam_totals.total_items, 0) AS total_items',
                ),
            ]);
        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'bs_person_exam_ext.email',
                'bs_person_exam_ext.fname',
                'bs_person_exam_ext.mname',
                'bs_person_exam_ext.lname',
                'bs_person_exam_ext.exam_type',
                'bs_person_exam_ext.proctor_name',
                'bs_course.name_course',
                'bs_exam_session.session_code',
            ],
            sortableColumns: [
                'started' => 'bs_person_exam_ext.started',
                'access_exp_date' =>
                    'bs_person_exam_ext.access_exp_date',
                'email' => 'bs_person_exam_ext.email',
                'examinee_name' => 'bs_person_exam_ext.lname',
                'name_course' => 'bs_course.name_course',
                'session_code' => 'bs_exam_session.session_code',
                'exam_type' => 'bs_person_exam_ext.exam_type',
                'proctor_name' => 'bs_person_exam_ext.proctor_name',
                'score' => 'bs_person_exam_ext.score',
                'done' => 'bs_person_exam_ext.done',
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
                $record['examinee_name'] =
                    $this->formatExamineeName($record);
                $record['score_percentage'] = $totalItems > 0
                    ? round(($score / $totalItems) * 100, 1)
                    : 0;
                $record['is_completed'] =
                    ($record['done'] ?? '') === 'Y';
                return $record;
            })
            ->values()
            ->all();
        return response()->json($result);
    }

    /**
     * Return Exam Package and Exam Session options.
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

    /** Schedule one external theoretical assessment. */
    public function store(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'fname' => ['nullable', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'lname' => ['nullable', 'string', 'max:100'],
            'bs_course_id' => ['required', 'string', 'max:100'],
            'bs_exam_session_id' => ['required', 'string', 'max:100'],
            'exam_type' => ['required', 'in:New,Resit'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'proctor_name' => ['nullable', 'string', 'max:100'],
            'access_exp_date' => ['required', 'date_format:Y-m-d'],
            'access_exp_time' => ['required', 'date_format:H:i'],
            'access_exp_date_to' => ['required', 'date_format:Y-m-d'],
            'access_exp_time_to' => ['required', 'date_format:H:i'],
        ]);
        $data['email'] = strtolower(trim($data['email']));
        $start = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d H:i', $data['access_exp_date'].' '.$data['access_exp_time']);
        $end = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d H:i', $data['access_exp_date_to'].' '.$data['access_exp_time_to']);
        if ($end->lessThanOrEqualTo($start)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'access_exp_date_to' => 'The access end must be after the access start.',
            ]);
        }
        $courseName = $db->table('bs_course')->where('id', $data['bs_course_id'])->value('name_course');
        if ($courseName === null || ! $db->table('bs_exam_session')->where('id', $data['bs_exam_session_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bs_course_id' => 'Select an existing exam package and session.',
            ]);
        }
        $id = (string) \Illuminate\Support\Str::uuid();
        $loginId = (string) ($request->user()?->getAuthIdentifier() ?? '');
        $db->transaction(function () use ($db, $data, $id, $loginId): void {
            $pending = $db->table('bs_person_exam_ext')
                ->where('email', $data['email'])
                ->where('access_exp_date', $data['access_exp_date'])
                ->where(function ($query): void {
                    $query->whereNull('started')->orWhere('started', '');
                })->exists();
            if ($pending) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => 'The examinee already has a pending exam on the selected date.',
                ]);
            }
            if ($data['exam_type'] === 'New') {
                $topics = $db->table('bs_topic')
                    ->where('bs_course_id', $data['bs_course_id'])
                    ->orderBy('order_no')->get(['id', 'no_quest', 'order_no'])
                    ->map(fn ($topic) => [
                        'bs_topic_id' => $topic->id,
                        'quest_cnt' => (int) $topic->no_quest,
                        'order_no' => $topic->order_no,
                    ]);
            } else {
                $latestId = $db->table('bs_person_exam_ext')
                    ->where('email', $data['email'])
                    ->where('bs_course_id', $data['bs_course_id'])
                    ->whereNotNull('ended')->where('ended', '<>', '')
                    ->orderByDesc('ended')->value('id');
                $topics = $latestId
                    ? $db->table('bs_person_exam_topic_ext')
                        ->where('bs_person_exam_id', $latestId)->where('passed', 'N')
                        ->orderBy('order_no')->get(['bs_topic_id', 'quest_cnt'])
                        ->values()->map(fn ($topic, $index) => [
                            'bs_topic_id' => $topic->bs_topic_id,
                            'quest_cnt' => (int) $topic->quest_cnt,
                            'order_no' => $index + 1,
                        ])
                    : collect();
            }
            if ($topics->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'exam_type' => $data['exam_type'] === 'Resit'
                        ? 'No failed topics were found in the latest completed exam.'
                        : 'This exam package has no topics.',
                ]);
            }
            $db->table('bs_person_exam_ext')->insert([
                'id' => $id,
                'bs_course_id' => $data['bs_course_id'],
                'bs_exam_session_id' => $data['bs_exam_session_id'],
                'email' => $data['email'],
                'fname' => trim($data['fname'] ?? ''),
                'mname' => trim($data['mname'] ?? ''),
                'lname' => trim($data['lname'] ?? ''),
                'started' => '', 'ended' => '', 'score' => 0, 'passed' => '',
                'access_exp_date' => $data['access_exp_date'],
                'access_exp_time' => $data['access_exp_time'],
                'access_exp_date_to' => $data['access_exp_date_to'],
                'access_exp_time_to' => $data['access_exp_time_to'],
                'or_no' => '', 'amount_paid' => 0, 'payment_date' => '1970-01-01',
                'proctor_name' => trim($data['proctor_name'] ?? ''),
                'login_id' => $loginId, 'last_update' => now()->format('Y-m-d H:i:s'),
                'duration' => (int) (($data['duration'] ?? 0) ?: $topics->sum('quest_cnt')),
                'exam_type' => $data['exam_type'],
                'exam_permit_no' => '', 'date_issued' => now()->toDateString(), 'issued_by' => '',
            ]);
            foreach ($topics as $topic) {
                $db->table('bs_person_exam_topic_ext')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'bs_person_exam_id' => $id,
                    'bs_topic_id' => $topic['bs_topic_id'],
                    'quest_cnt' => $topic['quest_cnt'],
                    'order_no' => $topic['order_no'],
                ]);
            }
        });
        $schoolCode = $this->resolveSchoolCode($request);
        $duration = (int) $db
            ->table('bs_person_exam_ext')
            ->where('id', $id)
            ->value('duration');
        $tokenExpiry = $this->externalAssessmentAccessService
            ->assessmentTokenExpiry(
                $data['access_exp_date_to'],
                $data['access_exp_time_to'],
                $duration,
            );
        $examUrl = $this->externalAssessmentAccessService
            ->theoreticalUrl(
                $schoolCode,
                $id,
                $tokenExpiry,
            );
        $name = trim(implode(' ', array_filter([$data['fname'] ?? '', $data['mname'] ?? '', $data['lname'] ?? '']))) ?: 'Examinee';
        $body = 'Hi '.e($name).',<br><br>You have a scheduled Theoretical Assessment.<br><br>'
            .'Exam to take: <b>'.e($courseName).'</b><br>'
            .'You have until: <b>'.e($end->format('M d, Y H:i')).'</b> to take the exam.<br><br>'
            .'<a href="'.e($examUrl).'">Start your exam</a><br>'.e($examUrl);
        $emailSent = true;
        try {
            $this->irisEmailService->send(
                view: 'emails.theoretical-external',
                email: strtolower(trim((string) $data['email'])),
                recipientName: $name,
                schoolCode: $schoolCode,
                subject: 'You have a scheduled Theoretical Assessment',
                data: [
                    'recipientName' => $name,
                    'examName' => $courseName,
                    'accessUntil' => $end->format('M d, Y H:i'),
                    'assessmentUrl' => $examUrl,
                ],
            );
        } catch (\Throwable $exception) {
            report($exception);
            $emailSent = false;
        }
        return response()->json([
            'message' => $emailSent ? 'The record has been saved.' : 'The record was saved, but its email could not be sent.',
            'id' => $id,
            'email_sent' => $emailSent,
        ], 201);
    }

    /**
     * Return answers for one External assessment.
     */
    /** Load editable values without querying exam answers. */
    public function edit(Request $request, string $assessmentId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) return $db;
        $assessment = $db->table('bs_person_exam_ext')->where('id', $assessmentId)
            ->first(['id', 'email', 'fname', 'mname', 'lname', 'bs_course_id',
                'bs_exam_session_id', 'exam_type', 'duration', 'proctor_name',
                'access_exp_date', 'access_exp_time', 'access_exp_date_to', 'access_exp_time_to']);
        if (! $assessment) return response()->json(['message' => 'Assessment not found.'], 404);
        return response()->json(['assessment' => $assessment]);
    }

    /**
     * Update one external theoretical assessment before it has started.
     *
     * Preserve the senior implementation's in-place update behavior and only
     * rebuild assigned topics when a field that controls topic selection changes.
     */
    public function update(Request $request, string $assessmentId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'fname' => ['nullable', 'string', 'max:100'],
            'mname' => ['nullable', 'string', 'max:100'],
            'lname' => ['nullable', 'string', 'max:100'],
            'bs_course_id' => ['required', 'string', 'max:100'],
            'bs_exam_session_id' => ['required', 'string', 'max:100'],
            'exam_type' => ['required', 'in:New,Resit'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'proctor_name' => ['nullable', 'string', 'max:100'],
            'access_exp_date' => ['required', 'date_format:Y-m-d'],
            'access_exp_time' => ['required', 'date_format:H:i'],
            'access_exp_date_to' => ['required', 'date_format:Y-m-d'],
            'access_exp_time_to' => ['required', 'date_format:H:i'],
        ]);
        $data['email'] = strtolower(trim($data['email']));
        $start = \Illuminate\Support\Carbon::createFromFormat(
            '!Y-m-d H:i',
            $data['access_exp_date'].' '.$data['access_exp_time'],
        );
        $end = \Illuminate\Support\Carbon::createFromFormat(
            '!Y-m-d H:i',
            $data['access_exp_date_to'].' '.$data['access_exp_time_to'],
        );
        if ($end->lessThanOrEqualTo($start)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'access_exp_date_to' =>
                    'The access end must be after the access start.',
            ]);
        }
        $courseExists = $db
            ->table('bs_course')
            ->where('id', $data['bs_course_id'])
            ->exists();
        $sessionExists = $db
            ->table('bs_exam_session')
            ->where('id', $data['bs_exam_session_id'])
            ->exists();
        if (! $courseExists || ! $sessionExists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bs_course_id' =>
                    'Select an existing exam package and session.',
            ]);
        }
        $db->transaction(function () use (
            $db,
            $data,
            $assessmentId,
            $request,
        ): void {
            $record = $db
                ->table('bs_person_exam_ext')
                ->where('id', $assessmentId)
                ->lockForUpdate()
                ->first();
            if (! $record) {
                abort(404, 'Assessment not found.');
            }
            if (trim((string) ($record->started ?? '')) !== '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'assessment' =>
                        'This assessment can no longer be edited because the examinee has already started it.',
                ]);
            }
            $packageChanged =
                (string) $record->bs_course_id !== $data['bs_course_id'];
            $examTypeChanged =
                (string) $record->exam_type !== $data['exam_type'];
            $emailChanged = strtolower(
                trim((string) ($record->email ?? '')),
            ) !== $data['email'];
            $rebuildTopics =
                $packageChanged
                || $examTypeChanged
                || ($data['exam_type'] === 'Resit' && $emailChanged);
            if ($rebuildTopics) {
                if ($data['exam_type'] === 'New') {
                    $topics = $db
                        ->table('bs_topic')
                        ->where('bs_course_id', $data['bs_course_id'])
                        ->orderBy('order_no')
                        ->get(['id', 'no_quest', 'order_no'])
                        ->map(fn ($topic) => [
                            'bs_topic_id' => $topic->id,
                            'quest_cnt' => (int) $topic->no_quest,
                            'order_no' => $topic->order_no,
                        ]);
                } else {
                    $latestId = $db
                        ->table('bs_person_exam_ext')
                        ->where('id', '<>', $assessmentId)
                        ->where('email', $data['email'])
                        ->where('bs_course_id', $data['bs_course_id'])
                        ->whereNotNull('ended')
                        ->where('ended', '<>', '')
                        ->orderByDesc('ended')
                        ->value('id');
                    $topics = $latestId
                        ? $db
                            ->table('bs_person_exam_topic_ext')
                            ->where('bs_person_exam_id', $latestId)
                            ->where('passed', 'N')
                            ->orderBy('order_no')
                            ->get(['bs_topic_id', 'quest_cnt'])
                            ->values()
                            ->map(fn ($topic, $index) => [
                                'bs_topic_id' => $topic->bs_topic_id,
                                'quest_cnt' => (int) $topic->quest_cnt,
                                'order_no' => $index + 1,
                            ])
                        : collect();
                }
                if ($topics->isEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'exam_type' => $data['exam_type'] === 'Resit'
                            ? 'No failed topics were found in the latest completed exam.'
                            : 'This exam package has no topics.',
                    ]);
                }
                $topicIds = $db
                    ->table('bs_person_exam_topic_ext')
                    ->where('bs_person_exam_id', $assessmentId)
                    ->pluck('id');
                if ($topicIds->isNotEmpty()) {
                    $db
                        ->table('bs_person_exam_topic_quest_ext')
                        ->whereIn('bs_person_exam_topic_id', $topicIds)
                        ->delete();
                }
                $db
                    ->table('bs_person_exam_topic_ext')
                    ->where('bs_person_exam_id', $assessmentId)
                    ->delete();
                foreach ($topics as $topic) {
                    $db->table('bs_person_exam_topic_ext')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'bs_person_exam_id' => $assessmentId,
                        'bs_topic_id' => $topic['bs_topic_id'],
                        'quest_cnt' => $topic['quest_cnt'],
                        'order_no' => $topic['order_no'],
                    ]);
                }
            }
            $duration = (int) ($data['duration'] ?? 0);
            if ($duration === 0) {
                $duration = (int) $db
                    ->table('bs_person_exam_topic_ext')
                    ->where('bs_person_exam_id', $assessmentId)
                    ->sum('quest_cnt');
            }
            $db
                ->table('bs_person_exam_ext')
                ->where('id', $assessmentId)
                ->update([
                    'email' => $data['email'],
                    'fname' => trim($data['fname'] ?? ''),
                    'mname' => trim($data['mname'] ?? ''),
                    'lname' => trim($data['lname'] ?? ''),
                    'bs_course_id' => $data['bs_course_id'],
                    'bs_exam_session_id' => $data['bs_exam_session_id'],
                    'exam_type' => $data['exam_type'],
                    'proctor_name' => trim($data['proctor_name'] ?? ''),
                    'duration' => $duration,
                    'access_exp_date' => $data['access_exp_date'],
                    'access_exp_time' => $data['access_exp_time'],
                    'access_exp_date_to' => $data['access_exp_date_to'],
                    'access_exp_time_to' => $data['access_exp_time_to'],
                    'login_id' => (string) (
                        $request->user()?->getAuthIdentifier()
                        ?? ''
                    ),
                    'last_update' => now()->format('Y-m-d H:i:s'),
                ]);
        });
        return response()->json([
            'id' => $assessmentId,
            'message' => 'The record has been saved.',
        ]);
    }

    public function show(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $assessment = $db
            ->table('bs_person_exam_ext')
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
                'bs_person_exam_ext.exam_type',
                'bs_person_exam_ext.started',
                'bs_person_exam_ext.score',
                'bs_person_exam_ext.done',
                'bs_course.name_course',
                'bs_exam_session.session_code',
            ])
            ->first();
        if (! $assessment) {
            return response()->json([
                'message' =>
                    'The External assessment could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }
        $answers = $db
            ->table('bs_person_exam_topic_quest_ext')
            ->join(
                'bs_person_exam_topic_ext',
                'bs_person_exam_topic_ext.id',
                '=',
                'bs_person_exam_topic_quest_ext.bs_person_exam_topic_id',
            )
            ->leftJoin(
                'bs_quest',
                'bs_quest.id',
                '=',
                'bs_person_exam_topic_quest_ext.bs_quest_id',
            )
            ->where(
                'bs_person_exam_topic_ext.bs_person_exam_id',
                $assessmentId,
            )
            ->orderBy(
                'bs_person_exam_topic_ext.order_no',
            )
            ->orderBy(
                'bs_person_exam_topic_quest_ext.id',
            )
            ->select([
                'bs_person_exam_topic_quest_ext.id',
                'bs_person_exam_topic_quest_ext.answer',
                'bs_person_exam_topic_quest_ext.correct_ans',
                'bs_quest.quest_text',
            ])
            ->get()
            ->map(function ($answer, int $index): array {
                return [
                    'index' => $index + 1,
                    'question' => $this->decodeText(
                        $answer->quest_text,
                    ),
                    /*
                     * The legacy page had these two fields reversed.
                     */
                    'answer' => $answer->answer,
                    'correct_answer' =>
                        $answer->correct_ans,
                    'is_correct' =>
                        (string) $answer->answer ===
                        (string) $answer->correct_ans,
                ];
            });
        $assessment = (array) $assessment;
        $assessment['examinee_name'] =
            $this->formatExamineeName($assessment);
        $assessment['total_items'] =
            $answers->count();
        return response()->json([
            'assessment' => $assessment,
            'answers' => $answers,
        ]);
    }

    /**
     * Generate the External assessment certificate with mPDF.
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
            ->table('bs_person_exam_ext')
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
                'bs_person_exam_ext.*',
                'bs_course.name_course',
                'bs_exam_session.session_code',
            ])
            ->first();
        if (! $assessment) {
            return response()->json([
                'message' =>
                    'The External assessment could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }
        if ($assessment->done !== 'Y') {
            return response()->json([
                'message' =>
                    'A certificate is only available for a completed assessment.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /*
         * Aggregate correct answers and actual question counts
         * without making one query for every topic.
         */
        $topicResults = $db
            ->table('bs_person_exam_topic_quest_ext')
            ->select([
                'bs_person_exam_topic_id',
                DB::raw(
                    'COUNT(id) AS actual_question_count',
                ),
                DB::raw(
                    <<<'SQL'

                    SUM(

                        CASE

                            WHEN correct_ans = answer THEN 1

                            ELSE 0

                        END

                    ) AS correct_answer_count

                    SQL,

                ),

            ])

            ->groupBy(

                'bs_person_exam_topic_id',

            );

        $topics = $db

            ->table('bs_person_exam_topic_ext')

            ->leftJoin(

                'bs_topic',

                'bs_topic.id',

                '=',

                'bs_person_exam_topic_ext.bs_topic_id',

            )

            ->leftJoinSub(

                $topicResults,

                'topic_results',

                'topic_results.bs_person_exam_topic_id',

                '=',

                'bs_person_exam_topic_ext.id',

            )

            ->where(

                'bs_person_exam_topic_ext.bs_person_exam_id',

                $assessmentId,

            )

            ->orderBy(

                'bs_person_exam_topic_ext.order_no',

            )

            ->select([

                'bs_person_exam_topic_ext.id',

                'bs_person_exam_topic_ext.score',

                'bs_person_exam_topic_ext.quest_cnt',

                'bs_person_exam_topic_ext.started',

                'bs_person_exam_topic_ext.passed',

                'bs_topic.desc_topic',

                'bs_topic.passing_mark',

                'bs_topic.no_quest',

                DB::raw(

                    'COALESCE(topic_results.actual_question_count, 0) AS actual_question_count',

                ),

                DB::raw(

                    'COALESCE(topic_results.correct_answer_count, 0) AS correct_answer_count',

                ),

            ])

            ->get()

            ->map(function ($topic): array {

                $questionCount = (int) (

                    $topic->actual_question_count

                    ?: $topic->no_quest

                    ?: $topic->quest_cnt

                    ?: 0

                );

                $correctAnswers = (int) (

                    $topic->correct_answer_count ?? 0

                );

                return [

                    'description' =>

                        $this->decodeText(

                            $topic->desc_topic,

                        ),

                    'rating' => $questionCount > 0

                        ? round(

                            ($correctAnswers / $questionCount) * 100,

                            1,

                        )

                        : 0,

                    'remarks' =>

                        (float) $topic->score >=

                        (float) $topic->passing_mark

                            ? 'PASS'

                            : 'FAIL',

                    'date_taken' =>

                        $topic->started,

                ];

            });

        $schoolCode = $this->resolveSchoolCode(

            $request,

        );

        $school = config(

            "schools.schools.{$schoolCode}",

            [],

        );

        $html = view(

            'theoretical-external.certificate',

            [

                'assessment' => $assessment,

                'topics' => $topics,

                'school' => $school,

            ],

        )->render();

        $filename = sprintf(

            'external-theoretical-assessment-%s.pdf',

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

    private function formatExamineeName(

        array $record,

    ): string {

        $lastName = trim(

            (string) ($record['lname'] ?? ''),

        );

        $firstName = trim(

            (string) ($record['fname'] ?? ''),

        );

        $middleName = trim(

            (string) ($record['mname'] ?? ''),

        );

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

    private function resolveSchoolCode(

        Request $request,

    ): string {

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

        $schoolCode = $this->resolveSchoolCode(

            $request,

        );

        if ($schoolCode === '') {

            return response()->json([

                'message' =>

                    'No school database has been selected.',

            ], Response::HTTP_FORBIDDEN);

        }

        $school = config(

            "schools.schools.{$schoolCode}",

        );

        if (! is_array($school)) {

            return response()->json([

                'message' =>

                    'The selected school is not configured.',

            ], Response::HTTP_FORBIDDEN);

        }

        $configuredCode = strtoupper(

            trim(

                (string) ($school['code'] ?? ''),

            ),

        );

        if (

            $configuredCode === '' ||

            ! hash_equals(

                $configuredCode,

                $schoolCode,

            )

        ) {

            return response()->json([

                'message' =>

                    'The selected school code is invalid.',

            ], Response::HTTP_FORBIDDEN);

        }

        $connection =

            $school['connection'] ?? null;

        if (

            ! is_string($connection) ||

            ! is_array(

                config(

                    "database.connections.{$connection}",

                ),

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
