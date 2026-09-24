<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class StudentBatchUploadController extends Controller
{
    private const HEADERS = [
        'SCHOOL ID NO.',
        'LAST NAME',
        'FIRST NAME',
        'MIDDLE NAME',
        'GENDER',
        'CCI YEAR',
        'DEPARTMENT',
        'TRB TYPE',
        'EMAIL',
    ];

    private const MAX_NEW_STUDENTS = 40;

    private const GENDERS = [
        'MALE',
        'FEMALE',
    ];

    private const DEPARTMENTS = [
        'DECK',
        'ENGINE',
        'NON-MARITIME',
    ];

    private const ETRB_TYPES = [
        'GMET',
        'ISF',
        'GMET AND ISF',
        'TRMF',
        'SCHOOL',
    ];

    public function template(Request $request): StreamedResponse
    {
        $this->schoolConnection($request);

        return response()->streamDownload(
            function (): void {
                $book =
                    new Spreadsheet();

                try {
                    $sheet =
                        $book->getActiveSheet();

                    $sheet->setTitle(
                        'Students',
                    );

                    /*
                     * Keep the downloaded workbook import-safe:
                     * only the required header row is pre-filled.
                     * Administrators enter real students starting
                     * on row 2.
                     */
                    $sheet->fromArray(
                        [
                            self::HEADERS,
                        ],
                        null,
                        'A1',
                    );

                    $sheet->freezePane(
                        'A2',
                    );

                    $sheet->setAutoFilter(
                        'A1:I1',
                    );

                    /*
                     * Excel-like header styling.
                     */
                    $sheet
                        ->getRowDimension(1)
                        ->setRowHeight(
                            24,
                        );

                    $sheet
                        ->getStyle('A1:I1')
                        ->getFont()
                        ->setBold(true)
                        ->getColor()
                        ->setARGB(
                            'FFFFFFFF',
                        );

                    $sheet
                        ->getStyle('A1:I1')
                        ->getFill()
                        ->setFillType(
                            Fill::FILL_SOLID,
                        )
                        ->getStartColor()
                        ->setARGB(
                            'FF217346',
                        );

                    $sheet
                        ->getStyle('A1:I1')
                        ->getAlignment()
                        ->setVertical(
                            Alignment::VERTICAL_CENTER,
                        );

                    $sheet
                        ->getStyle('A1:I1')
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(
                            Border::BORDER_THIN,
                        )
                        ->getColor()
                        ->setARGB(
                            'FFB7B7B7',
                        );

                    /*
                     * Fixed widths keep the downloaded template
                     * readable immediately when opened in Excel.
                     */
                    $columnWidths = [
                        'A' => 18,
                        'B' => 20,
                        'C' => 20,
                        'D' => 20,
                        'E' => 12,
                        'F' => 12,
                        'G' => 18,
                        'H' => 18,
                        'I' => 32,
                    ];

                    foreach (
                        $columnWidths
                        as $column =>
                            $width
                    ) {
                        $sheet
                            ->getColumnDimension(
                                $column,
                            )
                            ->setWidth(
                                $width,
                            );
                    }

                    /*
                     * Prepare 40 blank rows because one import may
                     * create a maximum of 40 new student accounts.
                     * These remain blank, so downloading the template
                     * cannot accidentally import fake/sample students.
                     */
                    $sheet
                        ->getStyle('A2:I41')
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(
                            Border::BORDER_HAIR,
                        )
                        ->getColor()
                        ->setARGB(
                            'FFD9D9D9',
                        );

                    $sheet
                        ->getStyle('A2:I41')
                        ->getAlignment()
                        ->setVertical(
                            Alignment::VERTICAL_CENTER,
                        );

                    for (
                        $row = 2;
                        $row <= 41;
                        $row++
                    ) {
                        $sheet
                            ->getRowDimension(
                                $row,
                            )
                            ->setRowHeight(
                                20,
                            );
                    }

                    /*
                     * Preserve values such as leading-zero School IDs.
                     */
                    $sheet
                        ->getStyle('A2:A41')
                        ->getNumberFormat()
                        ->setFormatCode(
                            NumberFormat::FORMAT_TEXT,
                        );

                    $sheet
                        ->getStyle('F2:F41')
                        ->getNumberFormat()
                        ->setFormatCode(
                            NumberFormat::FORMAT_TEXT,
                        );

                    $writer =
                        new Xlsx(
                            $book,
                        );

                    $writer->save(
                        'php://output',
                    );
                } finally {
                    $book
                        ->disconnectWorksheets();
                }
            },
            'student-template.xlsx',
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

                'Cache-Control' =>
                    'no-store, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * Validate every row before writing; the import is atomic.
     *
     * Existing students are matched using school_id_no and updated.
     * New students receive a generated System ID, login name and password.
     * A maximum of 40 NEW students may be created in one upload.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'confirm_update' => ['accepted'],
        ]);

        $db = $this->schoolConnection($request);
        $book = null;

        try {
            $uploadedFile =
                $request->file(
                    'file',
                );

            if (
                ! $uploadedFile
                ||
                ! $uploadedFile->isValid()
            ) {
                throw new \RuntimeException(
                    'The uploaded file is invalid.',
                );
            }

            $path =
                $uploadedFile
                    ->getRealPath();

            if (
                ! is_string($path)
                ||
                $path === ''
                ||
                ! is_file($path)
            ) {
                throw new \RuntimeException(
                    'The uploaded file is unavailable.',
                );
            }

            /*
             * Identify the workbook by its real content, not by its
             * filename. This allows administrators to rename the
             * workbook freely while still restricting imports to
             * genuine XLS/XLSX files.
             */
            $readerType =
                IOFactory::identify(
                    $path,
                );

            if (
                ! in_array(
                    $readerType,
                    [
                        'Xls',
                        'Xlsx',
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'file' =>
                        'The selected file is not a supported Excel workbook. Use a genuine XLS or XLSX file.',
                ]);
            }

            /*
             * Keep the XLSX archive-bomb protection when ZipArchive
             * is available. XLS files are not ZIP archives.
             */
            if (
                $readerType === 'Xlsx'
                &&
                class_exists(
                    ZipArchive::class,
                )
            ) {
                $archive =
                    new ZipArchive();

                if (
                    $archive->open(
                        $path,
                    ) !== true
                ) {
                    throw ValidationException::withMessages([
                        'file' =>
                            'The XLSX workbook could not be opened. Re-save it in Excel and try again.',
                    ]);
                }

                try {
                    $expandedSize = 0;

                    for (
                        $entry = 0;
                        $entry <
                            $archive->numFiles;
                        $entry++
                    ) {
                        $entryStat =
                            $archive->statIndex(
                                $entry,
                            );

                        $expandedSize +=
                            (int) (
                                $entryStat[
                                    'size'
                                ]
                                ?? 0
                            );
                    }

                    if (
                        $expandedSize >
                            32 * 1024 * 1024
                        ||
                        $archive->numFiles >
                            10000
                    ) {
                        throw ValidationException::withMessages([
                            'file' =>
                                'The Excel workbook is too large after extraction.',
                        ]);
                    }
                } finally {
                    $archive->close();
                }
            }

            /*
             * createReaderForFile is more tolerant across supported
             * PhpSpreadsheet versions than forcing a reader by name.
             */
            $reader =
                IOFactory::createReaderForFile(
                    $path,
                );

            $reader
                ->setReadDataOnly(
                    true,
                );

            $book =
                $reader->load(
                    $path,
                );
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            $detail =
                app()->environment(
                    'local',
                )
                    ? ' '.$error->getMessage()
                    : '';

            throw ValidationException::withMessages([
                'file' =>
                    'Unable to read this Excel workbook. Make sure it is a valid XLS or XLSX file and try again.'
                    .$detail,
            ]);
        }

        /*
         * Validate the workbook structure after it has loaded so header
         * and row problems are returned as useful row-level issues instead
         * of being reported as a generic file-read error.
         */
        [
            $rows,
            $skipped,
            $issues,
        ] = $this->readRows(
            $book,
        );

        $book
            ->disconnectWorksheets();

        if ($issues !== []) {
            return response()->json([
                'message' => 'Import cancelled. Fix the listed rows and try again; no database records were changed.',
                'issues' => array_slice($issues, 0, 100),
                'issue_count' => count($issues),
            ], 422);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'The workbook contains no student rows.',
            ]);
        }

        $schoolIds = collect($rows)
            ->pluck('school_id_no')
            ->unique()
            ->values();

        $existingSchoolIds = $db->table('person')
            ->whereIn('school_id_no', $schoolIds->all())
            ->pluck('school_id_no')
            ->map(static fn ($value): string => trim((string) $value))
            ->filter()
            ->flip();

        $newRows = collect($rows)
            ->filter(static fn (array $row): bool => ! $existingSchoolIds->has($row['school_id_no']))
            ->values();

        if ($newRows->count() > self::MAX_NEW_STUDENTS) {
            $limitIssues = $newRows
                ->slice(self::MAX_NEW_STUDENTS)
                ->take(100)
                ->map(static fn (array $row): array => [
                    'sheet' => $row['sheet'],
                    'row' => $row['row'],
                    'message' => 'This row would create a new student beyond the 40-new-student limit for one upload.',
                ])
                ->all();

            return response()->json([
                'message' => 'Import cancelled. This workbook would create more than 40 new students. Split the new students into multiple uploads. Existing students may remain in the workbook because they are updated, not recreated.',
                'issues' => $limitIssues,
                'issue_count' => $newRows->count() - self::MAX_NEW_STUDENTS,
            ], 422);
        }

        /*
         * Follow the senior QuestionUploadController safety pattern.
         * A database transaction cannot safely rollback a MyISAM table.
         */
        $engine = $db->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->where('TABLE_NAME', 'person')
            ->value('ENGINE');

        if (strtoupper((string) $engine) !== 'INNODB') {
            throw ValidationException::withMessages([
                'file' => 'Table person must use InnoDB for safe transactional imports. Ask your database administrator before changing the table engine.',
            ]);
        }

        $loginId = (string) (
            $request->session()->get('login_id')
            ?? $request->user()?->getAuthIdentifier()
            ?? ''
        );

        $currentRow = null;

        try {
            $result = $db->transaction(function () use (
                $db,
                $rows,
                $loginId,
                &$currentRow,
            ): array {
                /*
                 * Serialize batch imports before generating System IDs.
                 */
                $db->table('person')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first(['id']);

                $prefix = now()->format('Ym');

                $latestCode = (string) (
                    $db->table('person')
                        ->where('code_person', 'like', $prefix.'%')
                        ->orderByDesc('code_person')
                        ->value('code_person')
                    ?? ''
                );

                $nextSequence = 1;

                if (strlen($latestCode) >= 10) {
                    $nextSequence = ((int) substr($latestCode, -4)) + 1;
                }

                $added = 0;
                $updated = 0;
                $createdAccounts = [];

                foreach ($rows as $row) {
                    /*
                     * Keep the current workbook row available to the outer
                     * error handler. This does not change the legacy import
                     * decision: School ID No. remains the identity used for
                     * update-vs-insert.
                     */
                    $currentRow = $row;

                    $matches = $db->table('person')
                        ->where('school_id_no', $row['school_id_no'])
                        ->lockForUpdate()
                        ->get(['id']);

                    if ($matches->count() > 1) {
                        throw ValidationException::withMessages([
                            'file' => "Multiple existing students use School ID {$row['school_id_no']} (worksheet {$row['sheet']}, row {$row['row']}). Resolve the duplicate records before importing.",
                        ]);
                    }

                    $existing = $matches->first();

                    if ($existing) {
                        /*
                         * Preserve the exact fields updated by the legacy
                         * upload_person_data.php implementation.
                         * Existing login credentials are not changed.
                         */
                        $db->table('person')
                            ->where('id', $existing->id)
                            ->update([
                                'lname' => $row['lname'],
                                'fname' => $row['fname'],
                                'mname' => $row['mname'],
                                'gender' => $this->genderDatabaseValue($row['gender']),
                                'email' => $row['email'],
                                'dept' => $row['dept'],
                                'school_id_no' => $row['school_id_no'],
                                'etrb_type' => $row['etrb_type'],
                                'batch_no' => $row['batch_no'],
                            ]);

                        $updated++;

                        continue;
                    }

                    if ($added >= self::MAX_NEW_STUDENTS) {
                        throw ValidationException::withMessages([
                            'file' => 'The import would create more than 40 new students. Split the new students into multiple uploads.',
                        ]);
                    }

                    $codePerson = $prefix.str_pad(
                        (string) $nextSequence,
                        4,
                        '0',
                        STR_PAD_LEFT,
                    );

                    $nextSequence++;

                    while ($db->table('person')->where('code_person', $codePerson)->exists()) {
                        $codePerson = $prefix.str_pad(
                            (string) $nextSequence,
                            4,
                            '0',
                            STR_PAD_LEFT,
                        );

                        $nextSequence++;
                    }

                    $loginName = $codePerson;
                    $loginPassword = $this->generatePassword();
                    $personId = (string) Str::uuid();

                    $db->table('person')->insert([
                        'id' => $personId,
                        'code_person' => $codePerson,
                        'lname' => $row['lname'],
                        'fname' => $row['fname'],
                        'mname' => $row['mname'],
                        'gender' => $this->genderDatabaseValue($row['gender']),
                        'email' => $row['email'],
                        'login_name' => $loginName,

                        /*
                         * Keep compatibility with the existing legacy
                         * student authentication.
                         */
                        'login_pass' => $loginPassword,

                        'dept' => $row['dept'],
                        'school_id_no' => $row['school_id_no'],
                        'etrb_type' => $row['etrb_type'],
                        'batch_no' => $row['batch_no'],
                        'login_id' => $loginId,
                        'last_update' => now()->format('Y-m-d H:i:s'),
                        'date_reg' => now()->format('Y-m-d'),

                        /*
                         * person_insert.php explicitly creates student
                         * accounts as active.
                         */
                        'active' => 'Y',
                    ]);

                    $added++;

                    $createdAccounts[] = [
                        'row' => $row['row'],
                        'school_id_no' => $row['school_id_no'],
                        'name' => trim(
                            $row['lname'].', '.$row['fname'].' '.$row['mname'],
                        ),
                        'email' => $row['email'],
                        'login_name' => $loginName,

                        /*
                         * Returned only in this successful response.
                         * This lets authorized staff copy credentials
                         * until the existing emailer is wired later.
                         */
                        'password' => $loginPassword,
                    ];
                }

                return [
                    'added' => $added,
                    'updated' => $updated,
                    'created_accounts' => $createdAccounts,
                ];
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (UniqueConstraintViolationException $error) {
            report($error);

            return $this->duplicateConstraintResponse(
                $error,
                $db,
                $currentRow,
            );
        } catch (QueryException $error) {
            report($error);

            return $this->databaseQueryErrorResponse(
                $error,
                $currentRow,
            );
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' =>
                    'Student import failed unexpectedly. No changes were committed. Please try again or contact the system administrator if the problem continues.',
            ], 500);
        }

        /*
         * Database work has committed successfully.
         * Send emails only to NEW accounts, after commit, so an SMTP
         * failure can never rollback student records.
         */
        $schoolCode =
            $this->selectedSchoolCode(
                $request,
            );

        $emailsSent = 0;
        $emailsFailed = 0;

        foreach (
            $result['created_accounts']
            as &$account
        ) {
            try {
                $this->sendCredentialEmail(
                    email:
                        (string) $account['email'],
                    studentName:
                        (string) $account['name'],
                    loginName:
                        (string) $account['login_name'],
                    password:
                        (string) $account['password'],
                    schoolCode:
                        $schoolCode,
                );

                $account['email_status'] =
                    'sent';

                $emailsSent++;
            } catch (Throwable $error) {
                report($error);

                $account['email_status'] =
                    'failed';

                $emailsFailed++;
            }
        }

        unset($account);

        $result['emails_sent'] =
            $emailsSent;

        $result['emails_failed'] =
            $emailsFailed;

        $message =
            'Students imported successfully.';

        if (
            $emailsSent > 0
            ||
            $emailsFailed > 0
        ) {
            $message .=
                " {$emailsSent} credential email(s) sent.";

            if ($emailsFailed > 0) {
                $message .=
                    " {$emailsFailed} email(s) could not be sent; the student accounts were still created.";
            }
        }

        return response()->json([
            'message' =>
                $message,
            ...$result,
            'processed' =>
                count($rows),
            'skipped' =>
                $skipped,
        ]);
    }

    private function selectedSchoolCode(
        Request $request,
    ): string {
        return strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get(
                        'school_code',
                        '',
                    ),
            ),
        );
    }

    /**
     * Use the same credential email template as Student Profile.
     */
    private function sendCredentialEmail(
        string $email,
        string $studentName,
        string $loginName,
        string $password,
        string $schoolCode,
    ): void {
        $webUrl = rtrim(
            trim(
                (string) config(
                    'mail.iris.web_url',
                    config(
                        'app.url',
                        '',
                    ),
                ),
            ),
            '/',
        );

        $androidUrl = trim(
            (string) config(
                'mail.iris.android_url',
                '',
            ),
        );

        $appStoreUrl = trim(
            (string) config(
                'mail.iris.app_store_url',
                '',
            ),
        );

        $bcc = config(
            'mail.iris.bcc',
            [],
        );

        $bcc = is_array($bcc)
            ? array_values(
                array_filter(
                    $bcc,
                    static fn (
                        mixed $address,
                    ): bool =>
                        is_string($address)
                        &&
                        filter_var(
                            $address,
                            FILTER_VALIDATE_EMAIL,
                        ) !== false,
                ),
            )
            : [];

        Mail::send(
            'emails.student-account',
            [
                'studentName' =>
                    $studentName,
                'username' =>
                    $loginName,
                'password' =>
                    $password,
                'schoolCode' =>
                    $schoolCode,
                'webUrl' =>
                    $webUrl,
                'androidUrl' =>
                    $androidUrl,
                'appStoreUrl' =>
                    $appStoreUrl,
            ],
            static function (
                $message,
            ) use (
                $email,
                $studentName,
                $bcc,
            ): void {
                $message
                    ->to(
                        $email,
                        $studentName,
                    )
                    ->subject(
                        'Your IRIS-SAM account',
                    );

                if ($bcc !== []) {
                    $message->bcc(
                        $bcc,
                    );
                }
            },
        );
    }

    /**
     * Convert a database duplicate-key error into a safe, understandable
     * admin message while preserving the current legacy database rules.
     *
     * Batch Upload still follows the legacy architecture:
     * - same school_id_no => update
     * - new school_id_no => insert
     *
     * The existing database remains authoritative for its unique indexes.
     */
    private function duplicateConstraintResponse(
        UniqueConstraintViolationException $error,
        ConnectionInterface $db,
        ?array $row,
    ): JsonResponse {
        $databaseMessage =
            strtolower(
                $error->getMessage(),
            );

        $sheet =
            (string) (
                $row['sheet']
                ?? 'Database'
            );

        $rowNumber =
            (int) (
                $row['row']
                ?? 0
            );

        if (
            str_contains(
                $databaseMessage,
                'full_name',
            )
        ) {
            $studentName =
                $row
                    ? trim(
                        $row['lname']
                        .', '
                        .$row['fname']
                        .' '
                        .$row['mname'],
                    )
                    : 'this student';

            $uploadedSchoolId =
                trim(
                    (string) (
                        $row[
                            'school_id_no'
                        ]
                        ?? ''
                    ),
                );

            $existingSchoolId = '';

            if ($row) {
                /*
                 * The legacy unique index is:
                 * lname + fname + mname + st_address.
                 *
                 * Batch Upload does not provide st_address, so new rows use
                 * the table default blank address. Find the most relevant
                 * existing record only to make the error useful to the admin.
                 */
                $existing =
                    $db
                        ->table('person')
                        ->where(
                            'lname',
                            $row['lname'],
                        )
                        ->where(
                            'fname',
                            $row['fname'],
                        )
                        ->where(
                            'mname',
                            $row['mname'],
                        )
                        ->orderByRaw(
                            "CASE WHEN school_id_no = ? THEN 1 ELSE 0 END",
                            [
                                $uploadedSchoolId,
                            ],
                        )
                        ->first([
                            'school_id_no',
                            'code_person',
                        ]);

                if ($existing) {
                    $existingSchoolId =
                        trim(
                            (string) (
                                $existing
                                    ->school_id_no
                                ?? ''
                            ),
                        );

                    if ($existingSchoolId === '') {
                        $existingSchoolId =
                            trim(
                                (string) (
                                    $existing
                                        ->code_person
                                    ?? ''
                                ),
                            );
                    }
                }
            }

            $uploadedText =
                $uploadedSchoolId !== ''
                    ? " Uploaded School ID: {$uploadedSchoolId}."
                    : '';

            $existingText =
                $existingSchoolId !== ''
                    ? " Existing record: {$existingSchoolId}."
                    : '';

            $friendly =
                "Cannot save {$studentName}."
                .$uploadedText
                .$existingText
                .' The current school database already contains a person that conflicts with its legacy full-name/address uniqueness rule. '
                .'If this is the same student, use the existing School ID in the Excel file. '
                .'If this is a different student, contact the system administrator before changing the database rule.';
        } elseif (
            str_contains(
                $databaseMessage,
                'login_name',
            )
        ) {
            $friendly =
                'The generated Login Name already exists. No records were changed. Please retry the import; if the problem continues, contact the system administrator.';
        } elseif (
            str_contains(
                $databaseMessage,
                'code_person',
            )
        ) {
            $friendly =
                'The generated System ID already exists. No records were changed. Please retry the import; if the problem continues, contact the system administrator.';
        } else {
            $friendly =
                'A duplicate database value prevented this student from being saved. No records were changed. Review the student information and try again.';
        }

        return response()->json([
            'message' =>
                'Import cancelled because one row conflicts with an existing database record. No changes were committed.',
            'issues' => [
                [
                    'sheet' =>
                        $sheet,
                    'row' =>
                        $rowNumber,
                    'message' =>
                        $friendly,
                ],
            ],
            'issue_count' => 1,
        ], 422);
    }

    /**
     * Convert common database errors into safe end-user messages.
     * Raw SQL remains in the server log through report($error).
     */
    private function databaseQueryErrorResponse(
        QueryException $error,
        ?array $row,
    ): JsonResponse {
        $databaseMessage =
            strtolower(
                $error->getMessage(),
            );

        $sheet =
            (string) (
                $row['sheet']
                ?? 'Database'
            );

        $rowNumber =
            (int) (
                $row['row']
                ?? 0
            );

        if (
            str_contains(
                $databaseMessage,
                'data too long',
            )
        ) {
            $friendly =
                'One or more values in this row are longer than the database allows. Shorten the affected student information and try again.';
        } elseif (
            str_contains(
                $databaseMessage,
                'cannot be null',
            )
        ) {
            $friendly =
                'A value required by the school database is missing for this row. Review the student information and try again.';
        } elseif (
            str_contains(
                $databaseMessage,
                'foreign key constraint',
            )
        ) {
            $friendly =
                'This row references information that is not available in the selected school database. Review the student information or contact the system administrator.';
        } else {
            $friendly =
                'The selected school database could not save this row. No changes were committed. Review the student information and try again.';
        }

        return response()->json([
            'message' =>
                'Student import could not be completed. No changes were committed.',
            'issues' => [
                [
                    'sheet' =>
                        $sheet,
                    'row' =>
                        $rowNumber,
                    'message' =>
                        $friendly,
                ],
            ],
            'issue_count' => 1,
        ], 422);
    }

    private function readRows(Spreadsheet $book): array
    {
        $rows = [];
        $issues = [];
        $skipped = 0;
        $seenSchoolIds = [];
        $scanned = 0;

        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheetName = $sheet->getTitle();

            if (strcasecmp($sheetName, 'Sample') === 0) {
                continue;
            }

            $headers = [];

            foreach (range('A', 'I') as $column) {
                $headers[] = trim((string) $sheet->getCell($column.'1')->getValue());
            }

            if (! $this->headersMatch($headers)) {
                $issues[] = [
                    'sheet' => $sheetName,
                    'row' => 1,
                    'message' => 'Headers must match the Student Batch Upload template: SCHOOL ID NO., LAST NAME, FIRST NAME, MIDDLE NAME, GENDER, CCI YEAR, DEPARTMENT, TRB TYPE, EMAIL.',
                ];

                continue;
            }

            $lastRow = $sheet->getHighestDataRow();
            $scanned += max(0, $lastRow - 1);

            if ($scanned > 2000) {
                $issues[] = [
                    'sheet' => $sheetName,
                    'row' => 1,
                    'message' => 'Maximum 2,000 data rows across the workbook. Remove unused trailing rows or split the file.',
                ];

                break;
            }

            for ($number = 2; $number <= $lastRow; $number++) {
                $values = [];
                $formula = false;

                foreach (range('A', 'I') as $column) {
                    $cell = $sheet->getCell($column.$number);
                    $formula = $formula || $cell->getDataType() === DataType::TYPE_FORMULA;
                    $values[] = trim((string) $cell->getValue());
                }

                if (implode('', $values) === '') {
                    $skipped++;
                    continue;
                }

                $schoolId = $this->cleanText($values[0]);
                $lastName = $this->cleanName($values[1]);
                $firstName = $this->cleanName($values[2]);
                $middleName = $this->cleanName($values[3]);
                $gender = $this->normalizeGender($values[4]);
                $batchNo = $this->cleanText($values[5]);
                $department = strtoupper($this->cleanText($values[6]));
                $etrbType = $this->normalizeEtrbType($values[7]);
                $email = $this->cleanText($values[8]);

                $errors = [];

                if ($formula) {
                    $errors[] = 'Use plain values, not formulas.';
                }

                if ($schoolId === '') {
                    $errors[] = 'School ID No. is required.';
                } elseif (mb_strlen($schoolId) > 20) {
                    $errors[] = 'School ID No. must not exceed 20 characters.';
                }

                if ($lastName === '') {
                    $errors[] = 'Last Name is required.';
                } elseif (mb_strlen($lastName) > 30) {
                    $errors[] = 'Last Name must not exceed 30 characters.';
                }

                if ($firstName === '') {
                    $errors[] = 'First Name is required.';
                } elseif (mb_strlen($firstName) > 30) {
                    $errors[] = 'First Name must not exceed 30 characters.';
                }

                if (mb_strlen($middleName) > 30) {
                    $errors[] = 'Middle Name must not exceed 30 characters.';
                }

                if ($gender === '') {
                    $errors[] = 'Gender is required.';
                } elseif (! in_array($gender, self::GENDERS, true)) {
                    $errors[] = 'Gender must be MALE or FEMALE.';
                }

                if ($batchNo === '') {
                    $errors[] = 'CCI Year is required.';
                } elseif (mb_strlen($batchNo) > 20) {
                    $errors[] = 'CCI Year must not exceed 20 characters.';
                }

                if (! in_array($department, self::DEPARTMENTS, true)) {
                    $errors[] = 'Department must be DECK, ENGINE, or NON-MARITIME.';
                }

                if (! in_array(strtoupper($etrbType), self::ETRB_TYPES, true)) {
                    $errors[] = 'TRB Type must be GMET, ISF, GMET and ISF, TRMF, or SCHOOL.';
                }

                if ($email === '') {
                    $errors[] = 'Email is required.';
                } elseif (
                    mb_strlen($email) > 80
                    || filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL,
                    ) === false
                ) {
                    $errors[] = 'Email must be a valid email address not exceeding 80 characters.';
                }

                $schoolIdKey = mb_strtolower($schoolId);

                if ($schoolId !== '' && isset($seenSchoolIds[$schoolIdKey])) {
                    $errors[] = 'School ID No. repeats an earlier row in this workbook.';
                }

                if ($schoolId !== '') {
                    $seenSchoolIds[$schoolIdKey] = true;
                }

                if ($errors !== []) {
                    $issues[] = [
                        'sheet' => $sheetName,
                        'row' => $number,
                        'message' => implode(' ', $errors),
                    ];

                    continue;
                }

                $rows[] = [
                    'sheet' => $sheetName,
                    'row' => $number,
                    'school_id_no' => $schoolId,
                    'lname' => $lastName,
                    'fname' => $firstName,
                    'mname' => $middleName,
                    'gender' => $gender,
                    'batch_no' => $batchNo,
                    'dept' => $department,
                    'etrb_type' => $etrbType,
                    'email' => $email,
                ];
            }
        }

        return [$rows, $skipped, $issues];
    }

    /**
     * Accept the generated template plus common punctuation/name variants
     * used by the legacy student spreadsheet.
     */
    private function headersMatch(array $headers): bool
    {
        if (count($headers) !== 9) {
            return false;
        }

        $normalized = array_map(
            fn (string $header): string => $this->normalizeHeader($header),
            $headers,
        );

        $accepted = [
            [
                'SCHOOLIDNO',
                'SCHOOLID',
                'SCHOOLIDNUMBER',
                'STUDENTID',
                'STUDENTIDNO',
            ],
            [
                'LASTNAME',
                'LNAME',
                'SURNAME',
            ],
            [
                'FIRSTNAME',
                'FNAME',
                'GIVENNAME',
            ],
            [
                'MIDDLENAME',
                'MNAME',
                'MIDDLEINITIAL',
                'MI',
            ],
            [
                'GENDER',
                'SEX',
            ],
            [
                'CCIYEAR',
                'BATCHNO',
                'BATCH',
                'YEAR',
            ],
            [
                'DEPARTMENT',
                'DEPT',
            ],
            [
                'TRBTYPE',
                'ETRBTYPE',
                'TRB',
                'ETRB',
            ],
            [
                'EMAIL',
                'EMAILADDRESS',
                'EMAILADD',
            ],
        ];

        foreach ($normalized as $index => $header) {
            if (! in_array($header, $accepted[$index], true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        return strtoupper(
            preg_replace('/[^A-Za-z0-9]+/', '', trim($value)) ?? '',
        );
    }

    private function cleanText(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * Preserve the legacy behavior that removes apostrophes from names.
     */
    private function cleanName(mixed $value): string
    {
        return str_replace("'", '', trim((string) $value));
    }

    private function normalizeGender(mixed $value): string
    {
        $normalized = strtoupper(
            trim((string) $value),
        );

        return match ($normalized) {
            'M', 'MALE' => 'MALE',
            'F', 'FEMALE' => 'FEMALE',
            default => $normalized,
        };
    }

    private function genderDatabaseValue(string $gender): string
    {
        return match ($gender) {
            'MALE' => 'M',
            'FEMALE' => 'F',
            'M' => 'M',
            'F' => 'F',
            default => '',
        };
    }

    private function normalizeEtrbType(mixed $value): string
    {
        $normalized = strtoupper(
            trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''),
        );

        if ($normalized === 'GMET AND ISF') {
            return 'GMET and ISF';
        }

        return $normalized;
    }

    /**
     * Legacy accounts use six characters from this exact character set.
     * random_int keeps the same visible format without using str_shuffle.
     */
    private function generatePassword(): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ@$*';
        $password = '';
        $maximum = strlen($characters) - 1;

        for ($index = 0; $index < 6; $index++) {
            $password .= $characters[random_int(0, $maximum)];
        }

        return $password;
    }

    /**
     * Follow QuestionUploadController's existing school resolver.
     */
    private function schoolConnection(Request $request): ConnectionInterface
    {
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$code] ?? null) : null;

        abort_unless(
            $code !== '' && is_array($school),
            403,
            'No valid school has been selected.',
        );

        abort_unless(
            hash_equals(strtoupper((string) ($school['code'] ?? $code)), $code),
            403,
            'The selected school code is invalid.',
        );

        $connection = $school['connection'] ?? null;

        abort_unless(
            is_string($connection) && is_array(config("database.connections.{$connection}")),
            500,
            'The school database connection is unavailable.',
        );

        return DB::connection($connection);
    }
}
