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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PracticalExternalBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $perPage = (int) $request->integer('per_page', 10);

        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $search = trim((string) $request->input('search', ''));
        $direction = strtolower((string) $request->input('sort_direction', 'desc'));

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $sortable = [
            'last_update' => 'assess_ext_batch.last_update',
            'title_assess' => 'p_assess_h.title_assess',
            'assessor' => 'assess_ext_batch.assessor',
            'from_date' => 'assess_ext_batch.from_date',
            'due_date' => 'assess_ext_batch.due_date',
            'examinee_count' => 'examinee_count',
        ];

        $counts = $database
            ->table('assess_ext_batch_d')
            ->select([
                'assess_ext_batch_id',
                DB::raw('COUNT(*) AS examinee_count'),
            ])
            ->groupBy('assess_ext_batch_id');

        $query = $database
            ->table('assess_ext_batch')
            ->leftJoin(
                'p_assess_h',
                'p_assess_h.id',
                '=',
                'assess_ext_batch.p_assess_h_id',
            )
            ->leftJoinSub(
                $counts,
                'examinee_counts',
                'examinee_counts.assess_ext_batch_id',
                '=',
                'assess_ext_batch.id',
            )
            ->select([
                'assess_ext_batch.id',
                'assess_ext_batch.p_assess_h_id',
                'assess_ext_batch.from_date',
                'assess_ext_batch.due_date',
                'assess_ext_batch.assessor',
                'assess_ext_batch.remarks',
                'assess_ext_batch.last_update',
                'p_assess_h.title_assess',
                DB::raw('COALESCE(examinee_counts.examinee_count, 0) AS examinee_count'),
            ]);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $value = "%{$search}%";
                $builder
                    ->where('p_assess_h.title_assess', 'like', $value)
                    ->orWhere('assess_ext_batch.assessor', 'like', $value)
                    ->orWhere('assess_ext_batch.from_date', 'like', $value)
                    ->orWhere('assess_ext_batch.due_date', 'like', $value);
            });
        }

        $sortField = (string) $request->input('sort_field', 'last_update');
        $paginator = $query
            ->orderBy($sortable[$sortField] ?? 'assess_ext_batch.last_update', $direction)
            ->paginate($perPage)
            ->withQueryString();

        $records = collect($paginator->items())->map(
            fn (object $row): array => [
                'id' => (string) $row->id,
                'p_assess_h_id' => (string) ($row->p_assess_h_id ?? ''),
                'title_assess' => $row->title_assess ?: 'No Practical Assessment',
                'assessor' => strtoupper(trim((string) ($row->assessor ?: 'No Assessor'))),
                'from_date' => $this->nullableLegacyDate($row->from_date),
                'due_date' => $this->nullableLegacyDate($row->due_date),
                'remarks' => (string) ($row->remarks ?? ''),
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
        $assessments = $this->database($request)
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

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:25600'],
        ]);

        try {
            $sheet = IOFactory::load($validated['file']->getRealPath())
                ->getActiveSheet();
            $rows = [];
            $errors = [];

            foreach ($sheet->toArray(null, true, true, false) as $index => $values) {
                if ($index === 0) {
                    continue;
                }

                $email = strtolower(trim((string) ($values[0] ?? '')));
                $lname = $this->cleanName($values[1] ?? '');
                $fname = $this->cleanName($values[2] ?? '');
                $mname = $this->cleanName($values[3] ?? '');

                if ($email === '' && $lname === '' && $fname === '' && $mname === '') {
                    continue;
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Row '.($index + 1).' has an invalid email address.';
                    continue;
                }

                if (mb_strlen($email) > 50) {
                    $errors[] = 'Row '.($index + 1).' email exceeds 50 characters.';
                    continue;
                }

                $rows[$email] = compact('email', 'fname', 'mname', 'lname');
            }

            return response()->json([
                'data' => array_values($rows),
                'meta' => [
                    'accepted' => count($rows),
                    'rejected' => count($errors),
                    'errors' => $errors,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The Excel file could not be read.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        $database = $this->database($request);
        $batch = $database
            ->table('assess_ext_batch')
            ->leftJoin('p_assess_h', 'p_assess_h.id', '=', 'assess_ext_batch.p_assess_h_id')
            ->where('assess_ext_batch.id', $batchId)
            ->select(['assess_ext_batch.*', 'p_assess_h.title_assess'])
            ->first();

        abort_unless($batch, Response::HTTP_NOT_FOUND, 'The Practical External batch was not found.');

        $examinees = $database
            ->table('assess_ext_batch_d')
            ->where('assess_ext_batch_id', $batchId)
            ->orderBy('email')
            ->orderBy('lname')
            ->get(['id', 'assess_h_ext_id', 'email', 'fname', 'mname', 'lname', 'email_sent'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'assessment_id' => (string) $row->assess_h_ext_id,
                'email' => strtolower(trim((string) $row->email)),
                'fname' => trim((string) $row->fname),
                'mname' => trim((string) $row->mname),
                'lname' => trim((string) $row->lname),
                'name' => $this->examineeName($row),
                'email_sent' => strtoupper((string) $row->email_sent) === 'Y',
            ])
            ->values();

        return response()->json(['data' => [
            'id' => (string) $batch->id,
            'p_assess_h_id' => (string) $batch->p_assess_h_id,
            'title_assess' => $batch->title_assess,
            'from_date' => $this->nullableLegacyDate($batch->from_date),
            'due_date' => $this->nullableLegacyDate($batch->due_date),
            'assessor' => $batch->assessor ?? '',
            'remarks' => $batch->remarks ?? '',
            'examinees' => $examinees,
            'examinees_locked' => true,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $database = $this->database($request);
        $validated = $request->validate([
            'p_assess_h_id' => ['required', 'string', 'max:100'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assessor' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'examinees' => ['required', 'array', 'min:1', 'max:40'],
            'examinees.*.email' => ['required', 'email:rfc', 'max:50', 'distinct'],
            'examinees.*.fname' => ['nullable', 'string', 'max:100'],
            'examinees.*.mname' => ['nullable', 'string', 'max:100'],
            'examinees.*.lname' => ['nullable', 'string', 'max:100'],
        ]);

        if (
            ! empty($validated['from_date']) &&
            ! empty($validated['due_date']) &&
            $validated['due_date'] < $validated['from_date']
        ) {
            throw ValidationException::withMessages([
                'due_date' => ['The Due Date must be on or after the From Date.'],
            ]);
        }

        $examinees = collect($validated['examinees'])->map(fn (array $row): array => [
            'email' => strtolower(trim($row['email'])),
            'fname' => $this->cleanName($row['fname'] ?? ''),
            'mname' => $this->cleanName($row['mname'] ?? ''),
            'lname' => $this->cleanName($row['lname'] ?? ''),
        ]);

        $this->validateReferences(
            $database,
            $validated['p_assess_h_id'],
            $examinees->pluck('email')->all(),
        );

        $batchId = (string) Str::uuid();
        $loginId = (string) ($request->user()?->getAuthIdentifier() ?? '');
        $timestamp = now()->format('Y-m-d H:i:s');
        $fromDate = $validated['from_date'] ?? '1970-01-01';
        $dueDate = $validated['due_date'] ?? '1970-01-01';
        $recipients = [];

        try {
            $database->transaction(function () use (
                $database, $request, $validated, $examinees, $batchId,
                $loginId, $timestamp, $fromDate, $dueDate, &$recipients,
            ): void {
                $assessment = $database
                    ->table('p_assess_h')
                    ->where('id', $validated['p_assess_h_id'])
                    ->first(['title_assess']);
                $items = $database
                    ->table('p_assess_d')
                    ->where('p_assess_h_id', $validated['p_assess_h_id'])
                    ->orderBy('prio')
                    ->get(['id']);

                $database->table('assess_ext_batch')->insert([
                    'id' => $batchId,
                    'p_assess_h_id' => $validated['p_assess_h_id'],
                    'from_date' => $fromDate,
                    'due_date' => $dueDate,
                    'date_taken' => null,
                    'assessor' => strtoupper(trim((string) ($validated['assessor'] ?? ''))),
                    'remarks' => trim((string) ($validated['remarks'] ?? '')),
                    'login_id' => $loginId,
                    'last_update' => $timestamp,
                ]);

                foreach ($examinees as $examinee) {
                    $assessmentId = (string) Str::uuid();
                    $detailId = (string) Str::uuid();

                    $database->table('assess_ext_batch_d')->insert([
                        'id' => $detailId,
                        'assess_ext_batch_id' => $batchId,
                        'assess_h_ext_id' => $assessmentId,
                        'email' => $examinee['email'],
                        'fname' => $examinee['fname'],
                        'mname' => $examinee['mname'],
                        'lname' => $examinee['lname'],
                        'email_sent' => 'N',
                    ]);

                    $database->table('assess_h_ext')->insert([
                        'id' => $assessmentId,
                        'p_assess_h_id' => $validated['p_assess_h_id'],
                        'email' => $examinee['email'],
                        'fname' => $examinee['fname'],
                        'mname' => $examinee['mname'],
                        'lname' => $examinee['lname'],
                        'from_date' => $fromDate,
                        'due_date' => $dueDate,
                        'assessor' => strtoupper(trim((string) ($validated['assessor'] ?? ''))),
                    ]);

                    foreach ($items as $item) {
                        $database->table('assess_d_ext')->insert([
                            'id' => (string) Str::uuid(),
                            'assess_h_ext_id' => $assessmentId,
                            'assess_d_id' => (string) $item->id,
                            'points' => 0,
                            'filename_d' => '',
                            'remarks' => '',
                        ]);
                    }

                    $recipients[] = [
                        'detail_id' => $detailId,
                        'email' => $examinee['email'],
                        'name' => $this->examineeName((object) $examinee),
                        'content' => $this->emailContent(
                            $request,
                            $examinee,
                            (string) ($assessment->title_assess ?? 'Practical Assessment'),
                            $assessmentId,
                            $fromDate,
                            $dueDate,
                        ),
                    ];
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'message' => 'The Practical External batch could not be created.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $emailResult = $this->sendEmails($database, $recipients);

        return response()->json([
            'message' => "Batch created successfully. {$emailResult['sent']} email(s) sent.",
            'data' => [
                'id' => $batchId,
                'examinee_count' => $examinees->count(),
                'emails_sent' => $emailResult['sent'],
                'emails_failed' => $emailResult['failed'],
            ],
        ], Response::HTTP_CREATED);
    }

    private function validateReferences(
        ConnectionInterface $database,
        string $assessmentId,
        array $emails,
    ): void {
        if (! $database->table('p_assess_h')->where('id', $assessmentId)->exists()) {
            throw ValidationException::withMessages([
                'p_assess_h_id' => ['The selected Practical Assessment is invalid.'],
            ]);
        }

        $pending = $database
            ->table('assess_h_ext')
            ->where('p_assess_h_id', $assessmentId)
            ->whereIn('email', $emails)
            ->where(function (Builder $builder): void {
                $builder->whereNull('date_taken')
                    ->orWhere('date_taken', '0000-00-00')
                    ->orWhere('date_taken', '1970-01-01');
            })
            ->value('email');

        if ($pending) {
            throw ValidationException::withMessages([
                'examinees' => ["{$pending} already has the same pending Practical Assessment."],
            ]);
        }
    }

    private function emailContent(
        Request $request,
        array $examinee,
        string $title,
        string $assessmentId,
        string $fromDate,
        string $dueDate,
    ): string {
        $schoolCode = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $configuredUrl = trim((string) config(
            "schools.schools.{$schoolCode}.practical_external_exam_url",
            '',
        ));
        $baseUrl = rtrim($configuredUrl !== '' ? $configuredUrl : (string) config('app.url'), '/');
        $examUrl = "{$baseUrl}/practical-exam.php?id=".rawurlencode($assessmentId);
        $name = $this->examineeName((object) $examinee) ?: 'Examinee';

        return implode('', [
            '<html><body>',
            'Hi '.e($name).',<br><br>',
            'You have a scheduled Practical Assessment with the following details:<br><br>',
            'Assessment: <b>'.e($title).'</b><br>',
            e($this->validityText($fromDate, $dueDate)).'<br><br>',
            'Click this <a href="'.e($examUrl).'" target="_blank" rel="noopener noreferrer">link</a> to start your assessment.<br><br>',
            'If the link does not work, copy this URL:<br>'.e($examUrl),
            '</body></html>',
        ]);
    }

    private function sendEmails(ConnectionInterface $database, array $recipients): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::html($recipient['content'], function ($message) use ($recipient): void {
                    $message
                        ->to($recipient['email'], $recipient['name'])
                        ->subject('You have a scheduled Practical Assessment');
                });
                $database->table('assess_ext_batch_d')
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
        abort_if($schoolCode === '', Response::HTTP_FORBIDDEN, 'No school database has been selected.');
        $school = config("schools.schools.{$schoolCode}");
        abort_unless(is_array($school), Response::HTTP_FORBIDDEN, 'The selected school is not configured.');
        $connection = $school['connection'] ?? null;
        abort_unless(
            is_string($connection) && $connection !== '',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );
        return DB::connection($connection);
    }

    private function validityText(string $fromDate, string $dueDate): string
    {
        $hasFrom = $fromDate !== '1970-01-01';
        $hasDue = $dueDate !== '1970-01-01';

        if ($hasFrom && $hasDue) {
            return 'Available from '.date('M d, Y', strtotime($fromDate)).
                ' until '.date('M d, Y', strtotime($dueDate)).'.';
        }
        if ($hasFrom) {
            return 'You may start the assessment on '.date('M d, Y', strtotime($fromDate)).'.';
        }
        if ($hasDue) {
            return 'You have until '.date('M d, Y', strtotime($dueDate)).' to take the assessment.';
        }
        return 'You can take the assessment at any time.';
    }

    private function nullableLegacyDate(mixed $date): ?string
    {
        $value = trim((string) ($date ?? ''));
        return in_array($value, ['', '0000-00-00', '1970-01-01'], true) ? null : $value;
    }

    private function examineeName(object $examinee): string
    {
        $last = trim((string) ($examinee->lname ?? ''));
        $others = collect([$examinee->fname ?? '', $examinee->mname ?? ''])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->implode(' ');
        return strtoupper($last && $others ? "{$last}, {$others}" : ($last ?: $others));
    }

    private function cleanName(mixed $value): string
    {
        return trim(str_replace("'", '', (string) $value));
    }
}
