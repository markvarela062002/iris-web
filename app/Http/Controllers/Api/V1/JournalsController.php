<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class JournalsController extends Controller
{
    /**
     * Return daily journals for the server-side DataTable.
     */
    public function index(Request $request): JsonResponse
    {
        [$connection] = $this->resolveSchoolConnection($request);

        $database = DB::connection($connection);

        $validated = $request->validate([
            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'person_id' => [
                'nullable',
                'string',
                'max:64',
            ],
            'school_id' => [
                'nullable',
                'string',
                'max:100',
            ],
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],
            'sort_field' => [
                'nullable',
                'string',
            ],
            'sort_direction' => [
                'nullable',
                'in:asc,desc',
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
            ],
        ]);

        $allowedPageSizes = [
            10,
            20,
            50,
            100,
        ];

        $perPage = (int) (
            $validated['per_page'] ?? 10
        );

        if (! in_array(
            $perPage,
            $allowedPageSizes,
            true,
        )) {
            $perPage = 10;
        }

        $sortableColumns = [
            'date_journal' =>
                'person_journal.date_journal',

            'student_name' =>
                'person.lname',

            'school_id_no' =>
                'person.school_id_no',

            'department' =>
                'person.dept',

            'journal_time' =>
                'person_journal.journal_time',

            'status' =>
                'person_journal.esig_file',
        ];

        $sortField = (string) (
            $validated['sort_field']
            ?? 'date_journal'
        );

        $sortColumn = $sortableColumns[$sortField]
            ?? 'person_journal.date_journal';

        $sortDirection = (
            $validated['sort_direction'] ?? 'desc'
        ) === 'asc'
            ? 'asc'
            : 'desc';

        $query = $this->journalQuery($database);

        $this->applyJournalFilters(
            query: $query,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            personId: $validated['person_id'] ?? null,
            schoolId: $validated['school_id'] ?? null,
            search: $validated['search'] ?? null,
        );

        $paginator = $query
            ->orderBy(
                $sortColumn,
                $sortDirection,
            )
            ->orderBy(
                'person_journal.journal_time',
                'desc',
            )
            ->paginate($perPage)
            ->withQueryString();

        $records = collect($paginator->items())
            ->map(
                fn (object $journal): array =>
                    $this->transformJournal($journal),
            )
            ->values();

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
                'first' =>
                    $paginator->url(1),

                'last' =>
                    $paginator->url(
                        $paginator->lastPage(),
                    ),

                'previous' =>
                    $paginator->previousPageUrl(),

                'next' =>
                    $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Return student suggestions for the PrimeVue AutoComplete.
     */
    public function students(Request $request): JsonResponse
    {
        [$connection] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $search = trim((string) (
            $validated['search'] ?? ''
        ));

        $query = $database
            ->table('person')
            ->select([
                'person.id',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $studentQuery) use (
                    $search,
                ): void {
                    $studentQuery
                        ->where(
                            'person.school_id_no',
                            'like',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'person.lname',
                            'like',
                            "%{$search}%",
                        )
                        ->orWhere(
                            'person.fname',
                            'like',
                            "%{$search}%",
                        )
                        ->orWhereRaw(
                            "CONCAT_WS(
                                ' ',
                                person.fname,
                                person.mname,
                                person.lname
                            ) LIKE ?",
                            ["%{$search}%"],
                        )
                        ->orWhereRaw(
                            "CONCAT_WS(
                                ', ',
                                person.lname,
                                person.fname
                            ) LIKE ?",
                            ["%{$search}%"],
                        );
                },
            );
        }

        $students = $query
            ->orderBy('person.lname')
            ->orderBy('person.fname')
            ->limit(20)
            ->get()
            ->map(function (object $student): array {
                $name = $this->studentName($student);

                $schoolId = trim((string) (
                    $student->school_id_no ?? ''
                ));

                return [
                    'id' =>
                        (string) $student->id,

                    'school_id_no' =>
                        $student->school_id_no,

                    'fname' =>
                        $student->fname,

                    'mname' =>
                        $student->mname,

                    'lname' =>
                        $student->lname,

                    'gender' =>
                        $student->gender,

                    'dept' =>
                        $student->dept,

                    'name' =>
                        $name,

                    'label' =>
                        $schoolId !== ''
                            ? "{$name} ({$schoolId})"
                            : $name,
                ];
            })
            ->values();

        return response()->json([
            'data' => $students,
        ]);
    }

    /**
     * Generate and display the selected student's journal PDF.
     */
    public function download(Request $request): Response
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        [$connection, $school] =
            $this->resolveSchoolConnection($request);

        $database = DB::connection($connection);

        $validated = $request->validate([
            'date_from' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'person_id' => [
                'nullable',
                'string',
                'max:64',
            ],
            'school_id' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $personId = trim((string) (
            $validated['person_id'] ?? ''
        ));

        $schoolId = trim((string) (
            $validated['school_id'] ?? ''
        ));

        if (
            $personId === ''
            && $schoolId === ''
        ) {
            throw ValidationException::withMessages([
                'student' =>
                    'A student or exact school ID is required.',
            ]);
        }

        $studentQuery = $database
            ->table('person')
            ->select([
                'person.id',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
                'person.dig_signature',
            ]);

        if ($personId !== '') {
            $studentQuery->where(
                'person.id',
                $personId,
            );
        } else {
            $studentQuery->where(
                'person.school_id_no',
                $schoolId,
            );
        }

        $student = $studentQuery->first();

        abort_if(
            $student === null,
            HttpResponse::HTTP_NOT_FOUND,
            'The selected student was not found.',
        );

        $query = $this->journalQuery($database);

        $this->applyJournalFilters(
            query: $query,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            personId: (string) $student->id,
            schoolId: null,
            search: null,
        );

        $journals = $query
            ->orderBy(
                'person_journal.date_journal',
            )
            ->orderBy(
                'person_journal.journal_time',
            )
            ->get()
            ->map(
                function (
                    object $journal,
                ) use ($student): object {
                    $journal->duty_hours =
                        $this->calculateDutyHours(
                            $journal->date_journal,
                            $journal->journal_time,
                            $journal->journal_time_to,
                        );

                    $journal->student_signature =
                        $this->imageDataUri(
                            'images',
                            $student->dig_signature
                                ?? null,
                        );

                    $journal->officer_signature =
                        $this->imageDataUri(
                            'person_task',
                            $journal->esig_file
                                ?? null,
                        );

                    return $journal;
                },
            );

        abort_if(
            $journals->isEmpty(),
            HttpResponse::HTTP_NOT_FOUND,
            'No daily journals matched the selected filters.',
        );

        $temporaryDirectory = storage_path(
            'app/mpdf',
        );

        File::ensureDirectoryExists(
            $temporaryDirectory,
        );

        $mpdf = new Mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $temporaryDirectory,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 16,
            'margin_header' => 5,
            'margin_footer' => 5,
            'default_font' => 'dejavusans',
        ]);

        ini_set(
            'pcre.backtrack_limit',
            '10000000',
        );

        $mpdf->SetDisplayMode('fullwidth');

        $printedAt = now()
            ->timezone('Asia/Manila')
            ->format('M d, Y h:i A');

        $mpdf->SetHTMLFooter(
            '
            <div
                style="
                    width: 100%;
                    border-top: 1px solid #cccccc;
                    padding-top: 4px;
                    text-align: right;
                    font-size: 8px;
                    color: #555555;
                "
            >
                Printed '.$printedAt.'
                &nbsp;&nbsp;|&nbsp;&nbsp;
                Page {PAGENO} of {nbpg}
            </div>
            ',
        );

        $html = view(
            'daily-journals.print',
            [
                'student' => $student,
                'school' => $school,
                'journals' => $journals,

                'dateFrom' =>
                    $validated['date_from'] ?? null,

                'dateTo' =>
                    $validated['date_to'] ?? null,
            ],
        )->render();

        $mpdf->WriteHTML($html);

        $documentTitle = trim((string) (
            $student->school_id_no
            ?? $student->id
        ));

        $filename = sprintf(
            '%s-daily-journals.pdf',
            Str::slug($documentTitle),
        );

        $mpdf->SetTitle(
            'Daily Journals - '.
            $this->studentName($student),
        );

        $pdf = $mpdf->Output(
            '',
            Destination::STRING_RETURN,
        );

        return response(
            $pdf,
            HttpResponse::HTTP_OK,
            [
                'Content-Type' =>
                    'application/pdf',

                'Content-Disposition' =>
                    'inline; filename="'.
                    $filename.
                    '"',

                'Cache-Control' =>
                    'private, no-store, no-cache, must-revalidate',

                'Pragma' =>
                    'no-cache',
            ],
        );
    }

    /**
     * Build the shared daily-journal query.
     */
    private function journalQuery(
        ConnectionInterface $database,
    ): Builder {
        return $database
            ->table('person_journal')
            ->leftJoin(
                'person',
                'person_journal.person_id',
                '=',
                'person.id',
            )
            ->select([
                'person_journal.*',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.dept',
                'person.dig_signature',
            ]);
    }

    /**
     * Apply the same filters to the table and PDF queries.
     */
    private function applyJournalFilters(
        Builder $query,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $personId,
        ?string $schoolId,
        ?string $search,
    ): void {
        if (
            $dateFrom !== null
            && $dateFrom !== ''
        ) {
            $query->whereDate(
                'person_journal.date_journal',
                '>=',
                $dateFrom,
            );
        }

        if (
            $dateTo !== null
            && $dateTo !== ''
        ) {
            $query->whereDate(
                'person_journal.date_journal',
                '<=',
                $dateTo,
            );
        }

        if (
            $personId !== null
            && $personId !== ''
        ) {
            $query->where(
                'person.id',
                $personId,
            );
        }

        if (
            $schoolId !== null
            && trim($schoolId) !== ''
        ) {
            $query->where(
                'person.school_id_no',
                'like',
                '%'.trim($schoolId).'%',
            );
        }

        if (
            $search !== null
            && trim($search) !== ''
        ) {
            $keyword = trim($search);

            $query->where(
                function (
                    Builder $searchQuery,
                ) use ($keyword): void {
                    $searchQuery
                        ->where(
                            'person.school_id_no',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person.lname',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person.fname',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhereRaw(
                            "CONCAT_WS(
                                ' ',
                                person.fname,
                                person.mname,
                                person.lname
                            ) LIKE ?",
                            ["%{$keyword}%"],
                        )
                        ->orWhereRaw(
                            "CONCAT_WS(
                                ', ',
                                person.lname,
                                person.fname
                            ) LIKE ?",
                            ["%{$keyword}%"],
                        )
                        ->orWhere(
                            'person_journal.vessel_name',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person_journal.port_depart',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person_journal.port_dest',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person_journal.activities',
                            'like',
                            "%{$keyword}%",
                        )
                        ->orWhere(
                            'person_journal.key_areas',
                            'like',
                            "%{$keyword}%",
                        );
                },
            );
        }
    }

    /**
     * Convert a journal row into the DataTable response.
     *
     * @return array<string, mixed>
     */
    private function transformJournal(
        object $journal,
    ): array {
        $fileName = trim((string) (
            $journal->file_name ?? ''
        ));

        $googleDriveId = trim((string) (
            $journal->gdrive_link ?? ''
        ));

        $evidenceUrl = null;

        if (
            $fileName !== ''
            && $googleDriveId !== ''
        ) {
            $evidenceUrl = Str::startsWith(
                $googleDriveId,
                [
                    'http://',
                    'https://',
                ],
            )
                ? $googleDriveId
                : 'https://drive.google.com/file/d/'.
                    rawurlencode($googleDriveId).
                    '/view';
        } elseif ($fileName !== '') {
            $evidenceUrl =
                '/person_task/'.
                rawurlencode(
                    basename($fileName),
                );
        }

        $isValidated = $this->isValidated(
            $journal->esig_file ?? null,
        );

        return [
            'id' =>
                (string) $journal->id,

            'person_id' =>
                (string) $journal->person_id,

            'date_journal' =>
                $journal->date_journal,

            'school_id_no' =>
                $journal->school_id_no,

            'fname' =>
                $journal->fname,

            'mname' =>
                $journal->mname,

            'lname' =>
                $journal->lname,

            'gender' =>
                $journal->gender,

            'department' =>
                $journal->dept,

            'student_name' =>
                $this->studentName($journal),

            'vessel_name' =>
                $journal->vessel_name,

            'journal_time' =>
                $journal->journal_time,

            'journal_time_to' =>
                $journal->journal_time_to,

            'duty_hours' =>
                $this->calculateDutyHours(
                    $journal->date_journal,
                    $journal->journal_time,
                    $journal->journal_time_to,
                ),

            'port_depart' =>
                $journal->port_depart,

            'port_dest' =>
                $journal->port_dest,

            'file_name' =>
                $fileName,

            'evidence_url' =>
                $evidenceUrl,

            'status' =>
                $isValidated
                    ? 'Validated'
                    : 'Pending',

            'validated' =>
                $isValidated,
        ];
    }

    /**
     * Build a student's display name.
     */
    private function studentName(
        object $student,
    ): string {
        $lastName = trim((string) (
            $student->lname ?? ''
        ));

        $givenNames = collect([
            $student->fname ?? null,
            $student->mname ?? null,
        ])
            ->filter(
                fn ($value): bool =>
                    is_string($value)
                    && trim($value) !== '',
            )
            ->map(
                fn (string $value): string =>
                    trim($value),
            )
            ->implode(' ');

        if (
            $lastName !== ''
            && $givenNames !== ''
        ) {
            return strtoupper(
                "{$lastName}, {$givenNames}",
            );
        }

        return strtoupper(
            $lastName !== ''
                ? $lastName
                : $givenNames,
        );
    }

    /**
     * Calculate watchkeeping duty hours.
     */
    private function calculateDutyHours(
        mixed $date,
        mixed $timeFrom,
        mixed $timeTo,
    ): string {
        $startTime = trim((string) $timeFrom);
        $endTime = trim((string) $timeTo);

        if (
            $startTime === ''
            || $endTime === ''
        ) {
            return '0 hr 0 min';
        }

        try {
            $journalDate = $date
                ? Carbon::parse($date)
                    ->format('Y-m-d')
                : now()->format('Y-m-d');

            $start = Carbon::parse(
                "{$journalDate} {$startTime}",
            );

            $end = Carbon::parse(
                "{$journalDate} {$endTime}",
            );

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $minutes = (int) $start
                ->diffInMinutes($end);

            return sprintf(
                '%d hr %d min',
                intdiv($minutes, 60),
                $minutes % 60,
            );
        } catch (\Throwable) {
            return '0 hr 0 min';
        }
    }

    /**
     * Determine whether the journal was validated.
     */
    private function isValidated(
        mixed $signature,
    ): bool {
        $value = strtolower(
            trim((string) $signature),
        );

        return (
            $value !== ''
            && $value !== 'null'
        );
    }

    /**
     * Convert a local signature image to an embedded data URI.
     */
    private function imageDataUri(
        string $directory,
        mixed $filename,
    ): ?string {
        $safeFilename = basename(
            trim((string) $filename),
        );

        if ($safeFilename === '') {
            return null;
        }

        $path = public_path(
            $directory.
            DIRECTORY_SEPARATOR.
            $safeFilename,
        );

        if (! File::isFile($path)) {
            return null;
        }

        $mimeType = File::mimeType($path)
            ?: 'image/png';

        return sprintf(
            'data:%s;base64,%s',
            $mimeType,
            base64_encode(
                (string) File::get($path),
            ),
        );
    }

    /**
     * Resolve the database selected during login.
     *
     * @return array{
     *     0: string,
     *     1: array<string, mixed>
     * }
     */
    private function resolveSchoolConnection(
        Request $request,
    ): array {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get(
                        'school_code',
                        '',
                    ),
            ),
        );

        abort_if(
            $schoolCode === '',
            HttpResponse::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $schools = config(
            'schools.schools',
            [],
        );

        abort_unless(
            is_array($schools),
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'School configuration is unavailable.',
        );

        $school = $schools[$schoolCode]
            ?? null;

        abort_unless(
            is_array($school),
            HttpResponse::HTTP_FORBIDDEN,
            'The selected school is not configured.',
        );

        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code']
                    ?? $schoolCode
                ),
            ),
        );

        abort_unless(
            hash_equals(
                $configuredCode,
                $schoolCode,
            ),
            HttpResponse::HTTP_FORBIDDEN,
            'The selected school code is invalid.',
        );

        $connection = $school['connection']
            ?? null;

        abort_unless(
            is_string($connection)
            && $connection !== '',
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        $connectionConfiguration = config(
            "database.connections.{$connection}",
        );

        abort_unless(
            is_array($connectionConfiguration),
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is not configured.',
        );

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        return [
            $connection,
            $school,
        ];
    }
}