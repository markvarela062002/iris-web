<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExternalAssessmentAccessService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class PracticalExternalExamController extends Controller
{
    public function __construct(
        private readonly ExternalAssessmentAccessService $externalAssessmentAccessService,
    ) {
    }

    public function page(string $accessToken): InertiaResponse
    {
        $context = $this->context($accessToken);
        $assessment = $this->assessment(
            $context['db'],
            (string) $context['token']['assessment_id'],
        );

        return Inertia::render('assessments/practical-external/exam/Index', [
            'accessToken' => $accessToken,
            'assessmentId' => (string) $assessment->id,
            'examineeName' => $this->examineeName($assessment),
        ]);
    }

    public function state(string $accessToken): JsonResponse
    {
        $context = $this->context($accessToken);
        $database = $context['db'];
        $assessmentId = (string) $context['token']['assessment_id'];
        $assessment = $this->assessment($database, $assessmentId);

        $done = strtoupper((string) ($assessment->done ?? 'N')) === 'Y';
        $pending = strtoupper((string) ($assessment->for_assess ?? 'N')) === 'Y';
        $this->assertAssessmentWindowReadable($assessment, $done, $pending);

        $items = $database
            ->table('assess_d_ext')
            ->leftJoin(
                'p_assess_d',
                'p_assess_d.id',
                '=',
                'assess_d_ext.assess_d_id',
            )
            ->where('assess_d_ext.assess_h_ext_id', $assessmentId)
            ->orderBy('p_assess_d.prio')
            ->get([
                'assess_d_ext.id',
                'assess_d_ext.remarks',
                'assess_d_ext.filename_d',
                'assess_d_ext.points',
                'p_assess_d.item_d',
                'p_assess_d.filename_d as item_file',
                'p_assess_d.point_d as maximum_points',
            ])
            ->map(fn (object $item): array => [
                'id' => (string) $item->id,
                'description' => $this->decodeLegacyText($item->item_d),
                'remarks' => $this->decodeLegacyText($item->remarks),
                'reference_file' => $this->fileData(
                    $accessToken,
                    'reference',
                    $item->item_file,
                ),
                'evidence_file' => $this->fileData(
                    $accessToken,
                    'evidence',
                    $item->filename_d,
                    (string) $item->id,
                ),
                'points' => $done ? (float) ($item->points ?? 0) : null,
                'maximum_points' => $done
                    ? (float) ($item->maximum_points ?? 0)
                    : null,
            ])
            ->values();

        $referenceFiles = $database
            ->table('p_assess_h_file')
            ->where('p_assess_h_id', $assessment->p_assess_h_id)
            ->orderBy('prio_file')
            ->get([
                'id',
                'assess_h_file',
            ])
            ->map(fn (object $file): array => [
                'id' => (string) $file->id,
                'name' => basename((string) $file->assess_h_file),
                'url' => $this->externalFileUrl(
                    $accessToken,
                    'reference',
                    (string) $file->assess_h_file,
                ),
            ])
            ->filter(fn (array $file): bool => $file['name'] !== '')
            ->values();

        $maximumPoints = $done
            ? (float) $items->sum(
                fn (array $item): float =>
                    (float) ($item['maximum_points'] ?? 0),
            )
            : 0.0;

        $earnedPoints = $done
            ? (float) ($assessment->total_pts ?? 0)
            : 0.0;

        $percentage = $done && $maximumPoints > 0
            ? round(($earnedPoints / $maximumPoints) * 100, 1)
            : null;

        $passingMark = (float) ($assessment->passing_mark ?? 0);

        return response()->json([
            'data' => [
                'id' => (string) $assessment->id,
                'examinee_name' => $this->examineeName($assessment),
                'email' => strtolower(trim((string) ($assessment->email ?? ''))),
                'title' => $this->decodeLegacyText($assessment->title_assess)
                    ?: 'Untitled Practical Assessment',
                'instructions' => $this->decodeLegacyText(
                    $assessment->instruction_assess,
                ),
                'grade_system' => $assessment->grade_system ?: 'Checklist',
                'from_date' => $this->normalDate($assessment->from_date),
                'due_date' => $this->normalDate($assessment->due_date),
                'date_taken' => $this->normalDate($assessment->date_taken),
                'date_assessed' => $done
                    ? $this->normalDate($assessment->date_assessed)
                    : null,
                'assessor' => trim((string) ($assessment->assessor ?? '')),
                'is_completed' => $done,
                'is_pending' => $pending,
                'is_editable' => ! $done && ! $pending
                    && $this->withinAssessmentWindow($assessment),
                'status' => $done
                    ? 'Completed'
                    : ($pending ? 'For Assessment' : 'Assigned'),
                'items' => $items,
                'reference_files' => $referenceFiles,
                'result' => $done ? [
                    'earned_points' => $earnedPoints,
                    'maximum_points' => $maximumPoints,
                    'percentage' => $percentage,
                    'passing_mark' => $passingMark,
                    'remarks' => $percentage !== null
                        && $percentage >= $passingMark
                            ? 'PASS'
                            : 'FAIL',
                ] : null,
            ],
        ]);
    }

    public function saveItem(
        Request $request,
        string $accessToken,
        string $itemId,
    ): JsonResponse {
        $context = $this->context($accessToken);
        $database = $context['db'];
        $assessmentId = (string) $context['token']['assessment_id'];
        $assessment = $this->assessment($database, $assessmentId);

        $this->assertAssessmentEditable($assessment);

        $item = $database
            ->table('assess_d_ext')
            ->where('id', $itemId)
            ->where('assess_h_ext_id', $assessmentId)
            ->first([
                'id',
                'filename_d',
            ]);

        abort_unless(
            $item !== null,
            Response::HTTP_NOT_FOUND,
            'The selected practical assessment item was not found.',
        );

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:5000'],
            'evidence' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg',
                'max:20480',
            ],
        ]);

        $updates = [
            'remarks' => trim((string) ($validated['remarks'] ?? '')),
        ];

        if ($request->hasFile('evidence')) {
            $file = $request->file('evidence');
            $extension = strtolower(
                (string) $file->getClientOriginalExtension(),
            );
            $filename = 'practical_'
                .now()->format('Ymd_His')
                .'_'
                .Str::lower(Str::random(8))
                .'.'
                .$extension;

            $disk = $this->personTaskDisk(
                (string) $context['token']['school'],
            );

            $stored = Storage::disk($disk)->putFileAs(
                '',
                $file,
                $filename,
            );

            if ($stored === false) {
                return response()->json([
                    'message' => 'The evidence file could not be uploaded.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $updates['filename_d'] = $filename;
        }

        $database
            ->table('assess_d_ext')
            ->where('id', $itemId)
            ->where('assess_h_ext_id', $assessmentId)
            ->update($updates);

        return response()->json([
            'message' => 'Practical assessment item saved.',
        ]);
    }

    public function submit(string $accessToken): JsonResponse
    {
        $context = $this->context($accessToken);
        $database = $context['db'];
        $assessmentId = (string) $context['token']['assessment_id'];

        $database->transaction(
            function () use ($database, $assessmentId): void {
                $assessment = $database
                    ->table('assess_h_ext')
                    ->where('id', $assessmentId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'from_date',
                        'due_date',
                        'done',
                        'for_assess',
                    ]);

                abort_unless(
                    $assessment !== null,
                    Response::HTTP_NOT_FOUND,
                    'The selected practical assessment was not found.',
                );

                $this->assertAssessmentEditable($assessment);

                $database
                    ->table('assess_h_ext')
                    ->where('id', $assessmentId)
                    ->update([
                        'for_assess' => 'Y',
                        'done' => 'N',
                        'date_taken' => now()->toDateString(),
                        'last_update' => now()->format('Y-m-d H:i:s'),
                    ]);
            },
        );

        return response()->json([
            'message' => 'Practical assessment submitted for grading.',
        ]);
    }

    public function file(
        string $accessToken,
        string $fileType,
        string $filename,
    ) {
        $context = $this->context($accessToken);
        $database = $context['db'];
        $assessmentId = (string) $context['token']['assessment_id'];
        $assessment = $this->assessment($database, $assessmentId);

        $safeName = basename(trim($filename));
        abort_if(
            $safeName === '' || $safeName !== $filename,
            Response::HTTP_NOT_FOUND,
        );

        if ($fileType === 'evidence') {
            $owned = $database
                ->table('assess_d_ext')
                ->where('assess_h_ext_id', $assessmentId)
                ->where('filename_d', $safeName)
                ->exists();

            abort_unless($owned, Response::HTTP_NOT_FOUND);

            $disk = $this->personTaskDisk(
                (string) $context['token']['school'],
            );

            abort_unless(
                Storage::disk($disk)->exists($safeName),
                Response::HTTP_NOT_FOUND,
            );

            return Storage::disk($disk)->response($safeName);
        }

        abort_unless(
            $fileType === 'reference',
            Response::HTTP_NOT_FOUND,
        );

        $isHeaderFile = $database
            ->table('p_assess_h_file')
            ->where('p_assess_h_id', $assessment->p_assess_h_id)
            ->where('assess_h_file', $safeName)
            ->exists();

        $isItemFile = $database
            ->table('assess_d_ext')
            ->join(
                'p_assess_d',
                'p_assess_d.id',
                '=',
                'assess_d_ext.assess_d_id',
            )
            ->where('assess_d_ext.assess_h_ext_id', $assessmentId)
            ->where('p_assess_d.filename_d', $safeName)
            ->exists();

        abort_unless(
            $isHeaderFile || $isItemFile,
            Response::HTTP_NOT_FOUND,
        );

        $disk = $this->uploadDisk(
            (string) $context['token']['school'],
        );

        abort_unless(
            Storage::disk($disk)->exists($safeName),
            Response::HTTP_NOT_FOUND,
        );

        return Storage::disk($disk)->response($safeName);
    }

    private function context(string $accessToken): array
    {
        return $this->externalAssessmentAccessService->resolveAccess(
            $accessToken,
            ExternalAssessmentAccessService::TYPE_PRACTICAL,
        );
    }

    private function assessment(
        ConnectionInterface $database,
        string $assessmentId,
    ): object {
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
            ])
            ->first();

        abort_unless(
            $assessment !== null,
            Response::HTTP_NOT_FOUND,
            'The selected practical assessment was not found.',
        );

        return $assessment;
    }

    private function assertAssessmentEditable(object $assessment): void
    {
        if (strtoupper((string) ($assessment->done ?? 'N')) === 'Y') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'This practical assessment has already been graded.',
                ],
            ]);
        }

        if (strtoupper((string) ($assessment->for_assess ?? 'N')) === 'Y') {
            throw ValidationException::withMessages([
                'assessment' => [
                    'This practical assessment has already been submitted for grading.',
                ],
            ]);
        }

        if (! $this->withinAssessmentWindow($assessment)) {
            throw ValidationException::withMessages([
                'assessment' => [
                    'This practical assessment is outside its access period.',
                ],
            ]);
        }
    }

    private function assertAssessmentWindowReadable(
        object $assessment,
        bool $done,
        bool $pending,
    ): void {
        if ($done || $pending) {
            return;
        }

        if (! $this->withinAssessmentWindow($assessment)) {
            throw ValidationException::withMessages([
                'assessment' => [
                    'This practical assessment is outside its access period.',
                ],
            ]);
        }
    }

    private function withinAssessmentWindow(object $assessment): bool
    {
        $today = now()->toDateString();
        $from = $this->normalDate($assessment->from_date ?? null);
        $due = $this->normalDate($assessment->due_date ?? null);

        if ($from !== null && $today < $from) {
            return false;
        }

        if ($due !== null && $today > $due) {
            return false;
        }

        return true;
    }

    private function personTaskDisk(string $schoolCode): string
    {
        $schoolCode = strtolower(trim($schoolCode));

        abort_if(
            $schoolCode === '',
            Response::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $disk = "admapro_{$schoolCode}_person_task";

        abort_unless(
            is_array(config("filesystems.disks.{$disk}")),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school person-task storage disk is not configured.',
        );

        return $disk;
    }

    private function uploadDisk(string $schoolCode): string
    {
        $schoolCode = strtolower(trim($schoolCode));

        abort_if(
            $schoolCode === '',
            Response::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $disk = "admapro_{$schoolCode}_upload";

        abort_unless(
            is_array(config("filesystems.disks.{$disk}")),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school upload storage disk is not configured.',
        );

        return $disk;
    }

    private function normalDate(mixed $value): ?string
    {
        $date = trim((string) ($value ?? ''));

        return $date === ''
            || $date === '0000-00-00'
            || $date === '1970-01-01'
                ? null
                : $date;
    }

    private function examineeName(object $assessment): string
    {
        $lastName = trim((string) ($assessment->lname ?? ''));
        $otherNames = collect([
            $assessment->fname ?? '',
            $assessment->mname ?? '',
        ])
            ->map(
                fn ($name): string => trim((string) $name),
            )
            ->filter()
            ->implode(' ');

        if ($lastName !== '' && $otherNames !== '') {
            return strtoupper("{$lastName}, {$otherNames}");
        }

        return strtoupper($lastName ?: $otherNames);
    }

    private function decodeLegacyText(mixed $value): string
    {
        $text = urldecode((string) ($value ?? ''));

        return str_replace(
            ['andxx', 'apostrophexx', '%0A'],
            ['&', "'", "\n"],
            $text,
        );
    }

    private function fileData(
        string $accessToken,
        string $fileType,
        mixed $filename,
        ?string $itemId = null,
    ): ?array {
        $name = basename(trim((string) ($filename ?? '')));

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'url' => $this->externalFileUrl(
                $accessToken,
                $fileType,
                $name,
            ),
            'item_id' => $itemId,
        ];
    }

    private function externalFileUrl(
        string $accessToken,
        string $fileType,
        mixed $filename,
    ): string {
        $name = basename(trim((string) ($filename ?? '')));

        if ($name === '') {
            return '';
        }

        return route(
            'api.v1.external.practical.file',
            [
                'accessToken' => $accessToken,
                'fileType' => $fileType,
                'filename' => $name,
            ],
        );
    }
}
