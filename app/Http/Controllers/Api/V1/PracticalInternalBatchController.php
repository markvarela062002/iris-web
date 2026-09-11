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

class PracticalInternalBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $perPage = (int) $request->integer('per_page', 10);

        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $search = trim((string) $request->input('search', ''));
        $sortField = (string) $request->input('sort_field', 'due_date');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc'));

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $sortableColumns = [
            'last_update' => 'person_assess_batch.last_update',
            'title_assess' => 'p_assess_h.title_assess',
            'assessor' => 'person_assess_batch.assessor',
            'from_date' => 'person_assess_batch.from_date',
            'due_date' => 'person_assess_batch.due_date',
            'examinee_count' => 'examinee_count',
        ];

        $counts = $database
            ->table('person_assess_batch_d')
            ->select([
                'person_assess_batch_id',
                DB::raw('COUNT(*) AS examinee_count'),
            ])
            ->groupBy('person_assess_batch_id');

        $query = $database
            ->table('person_assess_batch')
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'person_assess_batch.p_assess_h_id',
            )
            ->leftJoinSub(
                $counts,
                'examinee_counts',
                'examinee_counts.person_assess_batch_id',
                '=',
                'person_assess_batch.id',
            )
            ->select([
                'person_assess_batch.id',
                'person_assess_batch.p_assess_h_id',
                'person_assess_batch.from_date',
                'person_assess_batch.due_date',
                'person_assess_batch.assessor',
                'person_assess_batch.last_update',
                'p_assess_h.title_assess',
                DB::raw('COALESCE(examinee_counts.examinee_count, 0) AS examinee_count'),
            ]);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $value = "%{$search}%";

                $builder
                    ->where('p_assess_h.title_assess', 'like', $value)
                    ->orWhere('person_assess_batch.assessor', 'like', $value)
                    ->orWhere('person_assess_batch.from_date', 'like', $value)
                    ->orWhere('person_assess_batch.due_date', 'like', $value);
            });
        }

        $paginator = $query
            ->orderBy(
                $sortableColumns[$sortField] ?? 'person_assess_batch.due_date',
                $sortDirection,
            )
            ->paginate($perPage)
            ->withQueryString();

        $records = collect($paginator->items())->map(
            fn (object $row): array => [
                'id' => (string) $row->id,
                'p_assess_h_id' => (string) ($row->p_assess_h_id ?? ''),
                'title_assess' => $row->title_assess ?: 'No Practical Assessment',
                'assessor' => strtoupper(trim((string) ($row->assessor ?: 'No Assessor'))),
                'from_date' => $row->from_date,
                'due_date' => $row->due_date,
                'last_update' => $row->last_update,
                'examinee_count' => (int) ($row->examinee_count ?? 0),
            ],
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
                'last' => $paginator->url($paginator->lastPage()),
                'previous' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $database = $this->database($request);

        $assessments = $database
            ->table('p_assess_h')
            ->orderBy('title_assess')
            ->get(['id', 'title_assess', 'grade_system', 'passing_mark'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'label' => trim((string) $row->title_assess),
                'grade_system' => $row->grade_system,
                'passing_mark' => $row->passing_mark,
            ])
            ->values();

        return response()->json(['data' => ['assessments' => $assessments]]);
    }

    public function students(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $search = trim((string) $request->input('search', ''));

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $value = "%{$search}%";
        $students = $database
            ->table('person')
            ->where(function (Builder $builder) use ($value): void {
                $builder
                    ->where('lname', 'like', $value)
                    ->orWhere('fname', 'like', $value)
                    ->orWhere('mname', 'like', $value)
                    ->orWhere('school_id_no', 'like', $value)
                    ->orWhere('code_person', 'like', $value)
                    ->orWhere('email', 'like', $value);
            })
            ->orderBy('lname')
            ->orderBy('fname')
            ->limit(20)
            ->get([
                'id', 'school_id_no', 'code_person', 'fname', 'mname',
                'lname', 'email', 'dept', 'gender',
            ])
            ->map(fn (object $student): array => [
                'id' => (string) $student->id,
                'school_id_no' => trim((string) ($student->school_id_no ?? '')),
                'code_person' => trim((string) ($student->code_person ?? '')),
                'fname' => trim((string) ($student->fname ?? '')),
                'mname' => trim((string) ($student->mname ?? '')),
                'lname' => trim((string) ($student->lname ?? '')),
                'name' => $this->studentName($student),
                'email' => strtolower(trim((string) ($student->email ?? ''))),
                'dept' => trim((string) ($student->dept ?? '')),
                'gender' => $student->gender ?? null,
            ])
            ->values();

        return response()->json(['data' => $students]);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        $database = $this->database($request);

        $batch = $database
            ->table('person_assess_batch')
            ->leftJoin('p_assess_h', 'p_assess_h.id', '=', 'person_assess_batch.p_assess_h_id')
            ->where('person_assess_batch.id', $batchId)
            ->select(['person_assess_batch.*', 'p_assess_h.title_assess'])
            ->first();

        abort_unless($batch, Response::HTTP_NOT_FOUND, 'The Practical Internal batch was not found.');

        $students = $database
            ->table('person_assess_batch_d')
            ->leftJoin('person', 'person.id', '=', 'person_assess_batch_d.person_id')
            ->where('person_assess_batch_d.person_assess_batch_id', $batchId)
            ->orderBy('person.lname')
            ->orderBy('person.fname')
            ->get([
                'person_assess_batch_d.id as detail_id',
                'person_assess_batch_d.person_assess_h_id',
                'person_assess_batch_d.person_id as id',
                'person_assess_batch_d.email_sent',
                'person.school_id_no', 'person.code_person', 'person.fname',
                'person.mname', 'person.lname', 'person.email', 'person.dept',
                'person.gender',
            ])
            ->map(fn (object $student): array => [
                'detail_id' => (string) $student->detail_id,
                'assessment_id' => (string) $student->person_assess_h_id,
                'id' => (string) $student->id,
                'school_id_no' => trim((string) ($student->school_id_no ?? '')),
                'code_person' => trim((string) ($student->code_person ?? '')),
                'name' => $this->studentName($student),
                'email' => strtolower(trim((string) ($student->email ?? ''))),
                'dept' => trim((string) ($student->dept ?? '')),
                'gender' => $student->gender ?? null,
                'email_sent' => strtoupper((string) $student->email_sent) === 'Y',
            ])
            ->values();

        return response()->json(['data' => [
            'id' => (string) $batch->id,
            'p_assess_h_id' => (string) $batch->p_assess_h_id,
            'title_assess' => $batch->title_assess,
            'from_date' => $batch->from_date,
            'due_date' => $batch->due_date,
            'assessor' => $batch->assessor ?? '',
            'students' => $students,
            'students_locked' => true,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $validated = $request->validate([
            'p_assess_h_id' => ['required', 'string', 'max:100'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'assessor' => ['nullable', 'string', 'max:100'],
            'students' => ['required', 'array', 'min:1', 'max:40'],
            'students.*.id' => ['required', 'string', 'max:100', 'distinct'],
        ]);

        $personIds = collect($validated['students'])
            ->pluck('id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values();

        $this->validateReferences($database, $validated['p_assess_h_id'], $personIds->all());

        $batchId = (string) Str::uuid();
        $loginId = (string) ($request->user()?->getAuthIdentifier() ?? '');
        $timestamp = now()->format('Y-m-d H:i:s');
        $emailRecipients = [];

        try {
            $database->transaction(function () use (
                $database, $validated, $personIds, $batchId, $loginId,
                $timestamp, &$emailRecipients,
            ): void {
                $assessment = $database
                    ->table('p_assess_h')
                    ->where('id', $validated['p_assess_h_id'])
                    ->first(['title_assess']);

                $templateItems = $database
                    ->table('p_assess_d')
                    ->where('p_assess_h_id', $validated['p_assess_h_id'])
                    ->orderBy('prio')
                    ->get(['id']);

                $database->table('person_assess_batch')->insert([
                    'id' => $batchId,
                    'p_assess_h_id' => $validated['p_assess_h_id'],
                    'from_date' => $validated['from_date'],
                    'due_date' => $validated['due_date'],
                    'date_taken' => null,
                    'obt_supervisor' => '',
                    'assessor' => strtoupper(trim((string) ($validated['assessor'] ?? ''))),
                    'dean' => '',
                    'login_id' => $loginId,
                    'last_update' => $timestamp,
                ]);

                $students = $database
                    ->table('person')
                    ->whereIn('id', $personIds->all())
                    ->get(['id', 'fname', 'mname', 'lname', 'email'])
                    ->keyBy('id');

                foreach ($personIds as $personId) {
                    $student = $students->get($personId);
                    $assessmentId = (string) Str::uuid();
                    $detailId = (string) Str::uuid();

                    $database->table('person_assess_batch_d')->insert([
                        'id' => $detailId,
                        'person_assess_batch_id' => $batchId,
                        'person_assess_h_id' => $assessmentId,
                        'person_id' => $personId,
                        'email_sent' => 'N',
                    ]);

                    $database->table('person_assess_h')->insert([
                        'id' => $assessmentId,
                        'person_id' => $personId,
                        'p_assess_h_id' => $validated['p_assess_h_id'],
                        'from_date' => $validated['from_date'],
                        'due_date' => $validated['due_date'],
                        'obt_supervisor' => '',
                        'dean' => '',
                        'assessor' => strtoupper(trim((string) ($validated['assessor'] ?? ''))),
                        'login_id' => $loginId,
                        'last_update' => $timestamp,
                        'for_assess' => 'N',
                        'done' => 'N',
                    ]);

                    foreach ($templateItems as $item) {
                        $database->table('person_assess_d')->insert([
                            'id' => (string) Str::uuid(),
                            'assess_d_id' => (string) $item->id,
                            'points' => 0,
                            'remarks' => '',
                            'person_assess_h_id' => $assessmentId,
                            'filename_d' => '',
                        ]);
                    }

                    if ($student && trim((string) $student->email) !== '') {
                        $emailRecipients[] = [
                            'detail_id' => $detailId,
                            'email' => strtolower(trim((string) $student->email)),
                            'name' => $this->studentName($student),
                            'content' => $this->emailContent(
                                $student,
                                (string) ($assessment->title_assess ?? 'Practical Assessment'),
                                $validated['from_date'],
                                $validated['due_date'],
                            ),
                        ];
                    }
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The Practical Internal batch could not be created.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $emailResult = $this->sendEmails($database, $emailRecipients);

        return response()->json([
            'message' => "Batch created successfully. {$emailResult['sent']} email(s) sent.",
            'data' => [
                'id' => $batchId,
                'examinee_count' => $personIds->count(),
                'emails_sent' => $emailResult['sent'],
                'emails_failed' => $emailResult['failed'],
            ],
        ], Response::HTTP_CREATED);
    }

    private function validateReferences(
        ConnectionInterface $database,
        string $assessmentId,
        array $personIds,
    ): void {
        if (! $database->table('p_assess_h')->where('id', $assessmentId)->exists()) {
            throw ValidationException::withMessages([
                'p_assess_h_id' => ['The selected Practical Assessment is invalid.'],
            ]);
        }

        $existingCount = $database
            ->table('person')
            ->whereIn('id', $personIds)
            ->count();

        if ($existingCount !== count($personIds)) {
            throw ValidationException::withMessages([
                'students' => ['One or more selected students are invalid.'],
            ]);
        }

        $pendingStudent = $database
            ->table('person_assess_h')
            ->leftJoin('person', 'person.id', '=', 'person_assess_h.person_id')
            ->where('person_assess_h.p_assess_h_id', $assessmentId)
            ->whereIn('person_assess_h.person_id', $personIds)
            ->where(function (Builder $builder): void {
                $builder->whereNull('person_assess_h.date_taken')
                    ->orWhere('person_assess_h.date_taken', '0000-00-00')
                    ->orWhere('person_assess_h.date_taken', '1970-01-01');
            })
            ->select(['person.lname', 'person.fname'])
            ->first();

        if ($pendingStudent) {
            throw ValidationException::withMessages([
                'students' => [
                    $this->studentName($pendingStudent).
                    ' already has the same pending Practical Assessment.',
                ],
            ]);
        }
    }

    private function emailContent(
        object $student,
        string $assessmentTitle,
        string $fromDate,
        string $dueDate,
    ): string {
        $name = $this->studentName($student) ?: 'Student';
        $loginUrl = rtrim((string) config('app.url'), '/').'/login';

        return implode('', [
            '<html><body>',
            'Hi '.e($name).',<br><br>',
            'You have a scheduled Practical Assessment with the following details:<br><br>',
            'Assessment: <b>'.e($assessmentTitle).'</b><br>',
            'Assessment period: <b>'.e(date('M d, Y', strtotime($fromDate))).
                ' - '.e(date('M d, Y', strtotime($dueDate))).'</b><br><br>',
            'Log in to your IRIS-SAM account here: ',
            '<a href="'.e($loginUrl).'" target="_blank" rel="noopener noreferrer">'.
                e($loginUrl).'</a>',
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
                Mail::html($recipient['content'], function ($message) use ($recipient): void {
                    $message
                        ->to($recipient['email'], $recipient['name'])
                        ->subject('You have a scheduled Practical Assessment on IRIS-SAM');
                });

                $database
                    ->table('person_assess_batch_d')
                    ->where('id', $recipient['detail_id'])
                    ->update(['email_sent' => 'Y']);

                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function database(Request $request): ConnectionInterface
    {
        $schoolCode = strtoupper(trim((string) $request->session()->get('school_code', '')));

        abort_if(
            $schoolCode === '',
            Response::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $school = config("schools.schools.{$schoolCode}");

        abort_unless(
            is_array($school),
            Response::HTTP_FORBIDDEN,
            'The selected school is not configured.',
        );

        $connection = $school['connection'] ?? null;

        abort_unless(
            is_string($connection) && $connection !== '',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        return DB::connection($connection);
    }

    private function studentName(object $student): string
    {
        $lastName = trim((string) ($student->lname ?? ''));
        $otherNames = collect([$student->fname ?? '', $student->mname ?? ''])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->implode(' ');

        return strtoupper(
            $lastName !== '' && $otherNames !== ''
                ? "{$lastName}, {$otherNames}"
                : ($lastName ?: $otherNames),
        );
    }
}
