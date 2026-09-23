<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class JournalsController extends Controller
{
    /**
     * Return daily journals for the server-side DataTable.
     */
    public function index(Request $request): JsonResponse
    {
        [$connection, $school] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);

        /*
         * Journal objective evidence is stored in the same public
         * person_task directory as activity files.
         */
        $evidenceBaseUrl = rtrim(
            trim(
                (string) data_get(
                    $school,
                    'files.activity_url',
                    '',
                ),
            ),
            '/',
        );

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

        $records = collect(
            $paginator->items(),
        )
            ->map(
                fn (object $journal): array =>
                    $this->transformJournal(
                        journal: $journal,
                        evidenceBaseUrl: $evidenceBaseUrl,
                    ),
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
     * Return one daily journal for the monitoring edit page.
     */
    public function show(
        Request $request,
        string $journalId,
    ): JsonResponse {
        [$connection, $school] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);

        $journal = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        return response()->json([
            'data' => $this->transformJournalForEdit(
                journal: $journal,
                personTaskBaseUrl: $this->personTaskBaseUrl(
                    $school,
                ),
            ),
        ]);
    }

    /**
     * Update a daily journal from the administrator monitoring page.
     *
     * Validation status is retained, but administrators may correct
     * journal information after STO validation.
     */
    public function update(
        Request $request,
        string $journalId,
    ): JsonResponse {
        [$connection, $school] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);

        $this->findJournalOrFail(
            $database,
            $journalId,
        );

        $validated = $request->validate([
            'date_journal' => [
                'required',
                'date_format:Y-m-d',
            ],
            'journal_time' => [
                'required',
                'date_format:H:i',
            ],
            'journal_time_to' => [
                'required',
                'date_format:H:i',
            ],
            'vessel_name' => [
                'required',
                'string',
                'max:50',
            ],
            'ship_lat' => [
                'nullable',
                'string',
                'max:50',
            ],
            'ship_long' => [
                'nullable',
                'string',
                'max:50',
            ],
            'ship_vicinity' => [
                'nullable',
                'string',
                'max:50',
            ],
            'port_depart' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'port_dest' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'pos_fix' => [
                'nullable',
                'string',
                'max:50',
            ],
            'course_speed' => [
                'nullable',
                'string',
                'max:50',
            ],
            'fo_rob' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'fo_dob' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'fo_lob' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'fo_cons' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'do_cons' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'average_rpm' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'average_speed' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'activities' => [
                'required',
                'string',
                'max:20000',
            ],
            'key_areas' => [
                'nullable',
                'string',
                'max:20000',
            ],
            'sto_name' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $hours = $this->calculateDutyHoursDecimal(
            $validated['date_journal'],
            $validated['journal_time'],
            $validated['journal_time_to'],
        );

        $database
            ->table('person_journal')
            ->where('id', $journalId)
            ->update([
                'date_journal' =>
                    $validated['date_journal'],
                'journal_time' =>
                    $validated['journal_time'],
                'journal_time_to' =>
                    $validated['journal_time_to'],
                'hrs' =>
                    $hours,
                'vessel_name' =>
                    trim($validated['vessel_name']),
                'ship_lat' =>
                    $this->nullableString(
                        $validated['ship_lat'] ?? null,
                    ),
                'ship_long' =>
                    $this->nullableString(
                        $validated['ship_long'] ?? null,
                    ),
                'ship_vicinity' =>
                    $this->nullableString(
                        $validated['ship_vicinity'] ?? null,
                    ),
                'port_depart' =>
                    $this->nullableString(
                        $validated['port_depart'] ?? null,
                    ),
                'port_dest' =>
                    $this->nullableString(
                        $validated['port_dest'] ?? null,
                    ),
                'pos_fix' =>
                    $this->nullableString(
                        $validated['pos_fix'] ?? null,
                    ),
                'course_speed' =>
                    $this->nullableString(
                        $validated['course_speed'] ?? null,
                    ),
                'fo_rob' =>
                    $this->nullableString(
                        $validated['fo_rob'] ?? null,
                    ),
                'fo_dob' =>
                    $this->nullableString(
                        $validated['fo_dob'] ?? null,
                    ),
                'fo_lob' =>
                    $this->nullableString(
                        $validated['fo_lob'] ?? null,
                    ),
                'fo_cons' =>
                    $this->nullableString(
                        $validated['fo_cons'] ?? null,
                    ),
                'do_cons' =>
                    $this->nullableString(
                        $validated['do_cons'] ?? null,
                    ),
                'average_rpm' =>
                    $this->nullableString(
                        $validated['average_rpm'] ?? null,
                    ),
                'average_speed' =>
                    $this->nullableString(
                        $validated['average_speed'] ?? null,
                    ),
                'activities' =>
                    trim($validated['activities']),
                'key_areas' =>
                    $this->nullableString(
                        $validated['key_areas'] ?? null,
                    ),
                'sto_name' =>
                    $this->nullableString(
                        $validated['sto_name'] ?? null,
                    ),
            ]);

        $updated = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        return response()->json([
            'message' =>
                'Daily journal saved successfully.',
            'data' =>
                $this->transformJournalForEdit(
                    journal: $updated,
                    personTaskBaseUrl: $this->personTaskBaseUrl(
                        $school,
                    ),
                ),
        ]);
    }

    /**
     * Replace the journal objective evidence on the selected school's
     * existing person_task FTP disk. Administrators may correct evidence
     * even when the journal has already been validated.
     */
    public function uploadEvidence(
        Request $request,
        string $journalId,
    ): JsonResponse {
        [$connection, $school] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);
        $journal = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        $validated = $request->validate([
            'evidence' => [
                'required',
                'file',
                'max:20480',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv',
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['evidence'];

        $diskName = $this->schoolDiskName(
            $request,
            'person_task',
        );

        $newFilename = $this->storeRemoteFile(
            diskName: $diskName,
            file: $file,
            prefix: 'journal',
        );

        $oldFilename = basename(
            str_replace(
                '\\',
                '/',
                trim((string) ($journal->file_name ?? '')),
            ),
        );

        try {
            $database
                ->table('person_journal')
                ->where('id', $journalId)
                ->update([
                    'file_name' => $newFilename,
                    'gdrive_link' => null,
                ]);
        } catch (Throwable $exception) {
            $this->deleteRemoteFileQuietly(
                $diskName,
                $newFilename,
            );

            throw $exception;
        }

        if (
            $oldFilename !== ''
            && $oldFilename !== $newFilename
        ) {
            $this->deleteRemoteFileQuietly(
                $diskName,
                $oldFilename,
            );
        }

        $updated = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        return response()->json([
            'message' =>
                'Objective evidence uploaded successfully.',
            'data' =>
                $this->transformJournalForEdit(
                    journal: $updated,
                    personTaskBaseUrl: $this->personTaskBaseUrl(
                        $school,
                    ),
                ),
        ]);
    }

    /**
     * Save the STO signature and validate the journal.
     *
     * The legacy application uses person_journal.esig_file as the STO
     * signature / validation marker, so this method preserves that rule.
     */
    public function uploadSignature(
        Request $request,
        string $journalId,
    ): JsonResponse {
        [$connection, $school] = $this->resolveSchoolConnection(
            $request,
        );

        $database = DB::connection($connection);
        $journal = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        $this->ensureJournalIsEditable($journal);

        $validated = $request->validate([
            'sto_name' => [
                'required',
                'string',
                'max:50',
            ],
            'signature' => [
                'required',
                'file',
                'max:5120',
                'mimes:png,jpg,jpeg,webp',
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['signature'];

        $diskName = $this->schoolDiskName(
            $request,
            'person_task',
        );

        $newFilename = $this->storeRemoteFile(
            diskName: $diskName,
            file: $file,
            prefix: 'journal_sto_signature',
        );

        try {
            $database
                ->table('person_journal')
                ->where('id', $journalId)
                ->update([
                    'sto_name' =>
                        trim($validated['sto_name']),
                    'esig_file' =>
                        $newFilename,
                ]);
        } catch (Throwable $exception) {
            $this->deleteRemoteFileQuietly(
                $diskName,
                $newFilename,
            );

            throw $exception;
        }

        $updated = $this->findJournalOrFail(
            $database,
            $journalId,
        );

        return response()->json([
            'message' =>
                'STO signature saved. The journal is now validated.',
            'data' =>
                $this->transformJournalForEdit(
                    journal: $updated,
                    personTaskBaseUrl: $this->personTaskBaseUrl(
                        $school,
                    ),
                ),
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

        $studentSignatureDisk = $this->schoolDiskName(
            $request,
            'esig',
        );

        $personTaskDisk = $this->schoolDiskName(
            $request,
            'person_task',
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
                ) use (
                    $student,
                    $studentSignatureDisk,
                    $personTaskDisk,
                ): object {
                    $journal->duty_hours =
                        $this->calculateDutyHours(
                            $journal->date_journal,
                            $journal->journal_time,
                            $journal->journal_time_to,
                        );

                    $journal->student_signature =
                        $this->remoteImageDataUri(
                            $studentSignatureDisk,
                            $student->dig_signature
                                ?? null,
                        );

                    $journal->officer_signature =
                        $this->remoteImageDataUri(
                            $personTaskDisk,
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
        string $evidenceBaseUrl,
    ): array {
        $fileName = basename(
            str_replace(
                '\\',
                '/',
                trim(
                    (string) (
                        $journal->file_name ?? ''
                    ),
                ),
            ),
        );

        $googleDriveId = trim(
            (string) (
                $journal->gdrive_link ?? ''
            ),
        );

        $evidenceUrl = null;
        $evidenceSource = null;

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

            $evidenceSource = 'google-drive';
        } elseif (
            $fileName !== ''
            && $evidenceBaseUrl !== ''
        ) {
            $evidenceUrl =
                $evidenceBaseUrl.
                '/'.
                rawurlencode($fileName);

            $evidenceSource = 'school-server';
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
                $this->studentName(
                    $journal,
                ),

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

            'evidence_source' =>
                $evidenceSource,

            'status' =>
                $isValidated
                    ? 'Validated'
                    : 'Pending',

            'validated' =>
                $isValidated,
        ];
    }

    /**
     * Convert a journal row into the edit-page response.
     *
     * @return array<string, mixed>
     */
    private function transformJournalForEdit(
        object $journal,
        string $personTaskBaseUrl = '',
    ): array {
        $fileName = $this->safeFilename(
            $journal->file_name ?? null,
        );

        $officerSignature = $this->safeFilename(
            $journal->esig_file ?? null,
        );

        $googleDriveId = trim(
            (string) (
                $journal->gdrive_link ?? ''
            ),
        );

        $evidenceUrl = null;
        $evidenceSource = null;

        if (
            $fileName !== ''
            && $googleDriveId !== ''
        ) {
            $evidenceUrl = Str::startsWith(
                $googleDriveId,
                ['http://', 'https://'],
            )
                ? $googleDriveId
                : 'https://drive.google.com/file/d/'.
                    rawurlencode($googleDriveId).
                    '/view';

            $evidenceSource = 'google-drive';
        } elseif ($fileName !== '') {
            $evidenceUrl = $this->personTaskFileUrl(
                filename: $fileName,
                personTaskBaseUrl: $personTaskBaseUrl,
            );

            $evidenceSource = 'school-server';
        }

        $validated = $this->isValidated(
            $journal->esig_file ?? null,
        );

        return [
            'id' => (string) $journal->id,
            'person_id' => (string) $journal->person_id,
            'school_id_no' => $journal->school_id_no,
            'fname' => $journal->fname,
            'mname' => $journal->mname,
            'lname' => $journal->lname,
            'gender' => $journal->gender,
            'department' => $journal->dept,
            'student_name' => $this->studentName($journal),
            'date_journal' => $journal->date_journal,
            'journal_time' => $journal->journal_time,
            'journal_time_to' => $journal->journal_time_to,
            'duty_hours' => $this->calculateDutyHours(
                $journal->date_journal,
                $journal->journal_time,
                $journal->journal_time_to,
            ),
            'vessel_name' => $journal->vessel_name,
            'ship_lat' => $journal->ship_lat,
            'ship_long' => $journal->ship_long,
            'ship_vicinity' => $journal->ship_vicinity,
            'port_depart' => $journal->port_depart,
            'port_dest' => $journal->port_dest,
            'pos_fix' => $journal->pos_fix,
            'course_speed' => $journal->course_speed,
            'fo_rob' => $journal->fo_rob,
            'fo_dob' => $journal->fo_dob,
            'fo_lob' => $journal->fo_lob,
            'fo_cons' => $journal->fo_cons,
            'do_cons' => $journal->do_cons,
            'average_rpm' => $journal->average_rpm,
            'average_speed' => $journal->average_speed,
            'activities' => $this->decodeLegacyText(
                $journal->activities ?? null,
            ),
            'key_areas' => $this->decodeLegacyText(
                $journal->key_areas ?? null,
            ),
            'sto_name' => $journal->sto_name,
            'file_name' => $fileName,
            'gdrive_link' => $journal->gdrive_link,
            'evidence_url' => $evidenceUrl,
            'evidence_source' => $evidenceSource,
            'officer_signature_file' => $officerSignature,
            'officer_signature_url' =>
                $officerSignature !== ''
                    ? $this->personTaskFileUrl(
                        filename: $officerSignature,
                        personTaskBaseUrl: $personTaskBaseUrl,
                    )
                    : null,
            'validated' => $validated,
            'status' => $validated
                ? 'Validated'
                : 'Pending',
        ];
    }

    /**
     * Return the selected school's existing public person_task URL.
     * This is the same location used by the legacy daily-journal STO
     * signatures and by the existing activity-file configuration.
     */
    private function personTaskBaseUrl(
        array $school,
    ): string {
        return rtrim(
            trim(
                (string) data_get(
                    $school,
                    'files.activity_url',
                    '',
                ),
            ),
            '/',
        );
    }

    private function personTaskFileUrl(
        string $filename,
        string $personTaskBaseUrl,
    ): string {
        if ($personTaskBaseUrl !== '') {
            return $personTaskBaseUrl.
                '/'.
                rawurlencode($filename);
        }

        return '/dashboard/files/person-task/'.
            rawurlencode($filename);
    }

    /**
     * Locate one journal with its student data.
     */
    private function findJournalOrFail(
        ConnectionInterface $database,
        string $journalId,
    ): object {
        $journal = $this
            ->journalQuery($database)
            ->where(
                'person_journal.id',
                $journalId,
            )
            ->first();

        abort_if(
            $journal === null,
            HttpResponse::HTTP_NOT_FOUND,
            'The selected daily journal was not found.',
        );

        return $journal;
    }

    private function ensureJournalIsEditable(
        object $journal,
    ): void {
        abort_if(
            $this->isValidated(
                $journal->esig_file ?? null,
            ),
            HttpResponse::HTTP_CONFLICT,
            'This daily journal is already validated and can no longer be edited.',
        );
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
        $minutes = $this->calculateDutyMinutes(
            $date,
            $timeFrom,
            $timeTo,
        );

        return sprintf(
            '%d hr %d min',
            intdiv($minutes, 60),
            $minutes % 60,
        );
    }

    private function calculateDutyHoursDecimal(
        mixed $date,
        mixed $timeFrom,
        mixed $timeTo,
    ): float {
        return round(
            $this->calculateDutyMinutes(
                $date,
                $timeFrom,
                $timeTo,
            ) / 60,
            2,
        );
    }

    private function calculateDutyMinutes(
        mixed $date,
        mixed $timeFrom,
        mixed $timeTo,
    ): int {
        $startTime = trim((string) $timeFrom);
        $endTime = trim((string) $timeTo);

        if (
            $startTime === ''
            || $endTime === ''
        ) {
            return 0;
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

            return (int) $start
                ->diffInMinutes($end);
        } catch (Throwable) {
            return 0;
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

    private function nullableString(
        mixed $value,
    ): ?string {
        $text = trim((string) ($value ?? ''));

        return $text === ''
            ? null
            : $text;
    }

    private function decodeLegacyText(
        mixed $value,
    ): string {
        $text = (string) ($value ?? '');

        return urldecode($text);
    }

    private function safeFilename(
        mixed $filename,
    ): string {
        return basename(
            str_replace(
                '\\',
                '/',
                trim((string) ($filename ?? '')),
            ),
        );
    }

    /**
     * Resolve the selected school's configured FTP disk name.
     */
    private function schoolDiskName(
        Request $request,
        string $storageType,
    ): string {
        $schoolCode = strtolower(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        abort_if(
            $schoolCode === '',
            HttpResponse::HTTP_FORBIDDEN,
            'No school has been selected.',
        );

        abort_unless(
            preg_match(
                '/\A[a-z0-9_-]+\z/',
                $schoolCode,
            ) === 1,
            HttpResponse::HTTP_FORBIDDEN,
            'The selected school code is invalid.',
        );

        abort_unless(
            in_array(
                $storageType,
                [
                    'person_task',
                    'esig',
                ],
                true,
            ),
            HttpResponse::HTTP_BAD_REQUEST,
            'The requested storage type is invalid.',
        );

        $diskName = sprintf(
            'admapro_%s_%s',
            $schoolCode,
            $storageType,
        );

        abort_unless(
            is_array(
                config(
                    "filesystems.disks.{$diskName}",
                ),
            ),
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'The selected school file storage is not configured.',
        );

        return $diskName;
    }

    private function storeRemoteFile(
        string $diskName,
        UploadedFile $file,
        string $prefix,
    ): string {
        $extension = strtolower(
            $file->getClientOriginalExtension(),
        );

        if ($extension === '') {
            $extension = strtolower(
                (string) $file->extension(),
            );
        }

        $extension = preg_replace(
            '/[^a-z0-9]+/',
            '',
            $extension,
        ) ?: 'bin';

        $filename = sprintf(
            '%s_%s_%s.%s',
            $prefix,
            now('Asia/Manila')->format(
                'Ymd_His',
            ),
            Str::lower(Str::random(8)),
            $extension,
        );

        $stream = fopen(
            $file->getRealPath(),
            'rb',
        );

        abort_if(
            $stream === false,
            HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            'The selected file could not be read.',
        );

        try {
            $stored = Storage::disk($diskName)
                ->put(
                    $filename,
                    $stream,
                );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        abort_unless(
            $stored,
            HttpResponse::HTTP_BAD_GATEWAY,
            'The file could not be uploaded to the selected school storage.',
        );

        return $filename;
    }

    private function deleteRemoteFileQuietly(
        string $diskName,
        string $filename,
    ): void {
        if ($filename === '') {
            return;
        }

        try {
            $disk = Storage::disk($diskName);

            if ($disk->exists($filename)) {
                $disk->delete($filename);
            }
        } catch (Throwable) {
            // The database update remains authoritative.
        }
    }

    /**
     * Convert an image stored on the selected school's FTP disk into
     * an embedded data URI for the PDF renderer.
     */
    private function remoteImageDataUri(
        string $diskName,
        mixed $filename,
    ): ?string {
        $safeFilename = $this->safeFilename($filename);

        if ($safeFilename === '') {
            return null;
        }

        try {
            $disk = Storage::disk($diskName);

            if (! $disk->exists($safeFilename)) {
                return null;
            }

            $contents = $disk->get($safeFilename);

            $mimeType = $disk->mimeType($safeFilename);

            if (
                ! is_string($mimeType)
                || ! str_starts_with(
                    $mimeType,
                    'image/',
                )
            ) {
                $mimeType = match (
                    strtolower(
                        pathinfo(
                            $safeFilename,
                            PATHINFO_EXTENSION,
                        ),
                    )
                ) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };
            }

            return sprintf(
                'data:%s;base64,%s',
                $mimeType,
                base64_encode($contents),
            );
        } catch (Throwable) {
            return null;
        }
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
