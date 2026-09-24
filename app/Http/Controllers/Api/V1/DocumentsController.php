<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DocumentsController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return uploaded documents for administrator monitoring.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $schoolCode = $this->resolveSchoolCode(
            $request,
        );

        $documentsBaseUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.documents_url",
                    '',
                ),
            ),
            '/',
        );

        /*
         * Combine every file_upload_d filename into its
         * parent file_upload record.
         */
        $uploadedFiles = $db
            ->table('file_upload_d')
            ->select([
                'file_upload_id',

                DB::raw(
                    <<<'SQL'
                    GROUP_CONCAT(
                        filename_d
                        ORDER BY order_no
                        SEPARATOR '|||FILE|||'
                    ) AS filenames
                    SQL,
                ),
            ])
            ->groupBy(
                'file_upload_id',
            );

        $query = $db
            ->table('file_upload')
            ->leftJoin(
                'person',
                'person.id',
                '=',
                'file_upload.owner_id',
            )
            ->leftJoin(
                'requirement',
                'requirement.id',
                '=',
                'file_upload.requirement_id',
            )
            ->leftJoinSub(
                $uploadedFiles,
                'uploaded_files',
                'uploaded_files.file_upload_id',
                '=',
                'file_upload.id',
            )
            ->select([
                'file_upload.id',
                'file_upload.owner_id',
                'file_upload.requirement_id',
                'file_upload.file_desc',
                'file_upload.date_uploaded',
                'file_upload.time_uploaded',
                'file_upload.sto_validated',
                'file_upload.for_app',
                'file_upload.revise_remarks',
                'file_upload.last_update',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',

                'requirement.desc_requirement',

                'uploaded_files.filenames',
            ]);

        if (! $request->boolean('monitoring')) {
            $query
                ->where(
                    function ($query): void {
                        $query
                            ->where(
                                'file_upload.sto_validated',
                                '!=',
                                'Y',
                            )
                            ->orWhere(
                                'file_upload.sto_validated',
                                '=',
                                '',
                            )
                            ->orWhereNull(
                                'file_upload.sto_validated',
                            );
                    },
                )
                ->where(
                    'file_upload.for_app',
                    '=',
                    'Y',
                );
        }

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'file_upload.file_desc',
                'requirement.desc_requirement',
                'uploaded_files.filenames',
                'file_upload.date_uploaded',
                'file_upload.time_uploaded',
            ],
            sortableColumns: [
                'fname' =>
                    'person.fname',

                'lname' =>
                    'person.lname',

                'school_id_no' =>
                    'person.school_id_no',

                'file_desc' =>
                    'file_upload.file_desc',

                'desc_requirement' =>
                    'requirement.desc_requirement',

                'date_uploaded' =>
                    'file_upload.date_uploaded',

                'last_update' =>
                    'file_upload.last_update',
            ],
            defaultSortColumn: 'date_uploaded',
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
                function ($row) use (
                    $documentsBaseUrl,
                ): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    $record['files'] =
                        $this->buildFileList(
                            filenames:
                                $record['filenames']
                                ?? null,

                            documentsBaseUrl:
                                $documentsBaseUrl,
                        );

                    return $record;
                },
            )
            ->values()
            ->all();

        return response()->json(
            $result,
        );
    }

    /**
     * Return the authenticated student's document summary.
     */
    public function studentDashboard(
        Request $request,
    ): JsonResponse {
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

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $studentId = (string) $account
            ->getAuthIdentifier();

        $schoolCode = $this->resolveSchoolCode(
            $request,
        );

        $documentsBaseUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.documents_url",
                    '',
                ),
            ),
            '/',
        );

        /*
         * Count every uploaded document belonging to the
         * authenticated student, regardless of status.
         */
        $total = $db
            ->table('file_upload')
            ->where(
                'owner_id',
                $studentId,
            )
            ->count();

        /*
         * Combine the filenames belonging to each upload.
         */
        $uploadedFiles = $db
            ->table('file_upload_d')
            ->select([
                'file_upload_id',

                DB::raw(
                    <<<'SQL'
                    GROUP_CONCAT(
                        filename_d
                        ORDER BY order_no
                        SEPARATOR '|||FILE|||'
                    ) AS filenames
                    SQL,
                ),
            ])
            ->groupBy(
                'file_upload_id',
            );

        /*
         * Return the student's 10 latest uploaded documents.
         */
        $documents = $db
            ->table('file_upload')
            ->leftJoin(
                'requirement',
                'requirement.id',
                '=',
                'file_upload.requirement_id',
            )
            ->leftJoinSub(
                $uploadedFiles,
                'uploaded_files',
                'uploaded_files.file_upload_id',
                '=',
                'file_upload.id',
            )
            ->where(
                'file_upload.owner_id',
                $studentId,
            )
            ->select([
                'file_upload.id',
                'file_upload.owner_id',
                'file_upload.requirement_id',
                'file_upload.file_desc',
                'file_upload.date_uploaded',
                'file_upload.time_uploaded',
                'file_upload.sto_validated',
                'file_upload.for_app',
                'file_upload.revise_remarks',
                'file_upload.last_update',

                'requirement.desc_requirement',

                'uploaded_files.filenames',
            ])
            ->orderByDesc(
                'file_upload.last_update',
            )
            ->limit(10)
            ->get()
            ->map(
                function (object $document) use (
                    $documentsBaseUrl,
                ): array {
                    $validated = strtoupper(
                        trim(
                            (string) (
                                $document->sto_validated
                                ?? ''
                            ),
                        ),
                    );

                    $forApproval = strtoupper(
                        trim(
                            (string) (
                                $document->for_app
                                ?? ''
                            ),
                        ),
                    );

                    $remarks = trim(
                        (string) (
                            $document->revise_remarks
                            ?? ''
                        ),
                    );

                    if ($validated === 'Y') {
                        $status = 'Verified';
                    } elseif ($remarks !== '') {
                        $status = 'For Revision';
                    } elseif ($forApproval === 'Y') {
                        $status = 'For Verification';
                    } else {
                        $status = 'Draft';
                    }

                    return [
                        'id' =>
                            $document->id,

                        'requirement_id' =>
                            $document->requirement_id,

                        'description' =>
                            $document->file_desc
                            ?: (
                                $document
                                    ->desc_requirement
                                ?? 'Uploaded Document'
                            ),

                        'requirement' =>
                            $document->desc_requirement
                            ?? null,

                        'date_uploaded' =>
                            $document->date_uploaded,

                        'time_uploaded' =>
                            $document->time_uploaded,

                        'last_update' =>
                            $document->last_update,

                        'sto_validated' =>
                            $document->sto_validated,

                        'for_app' =>
                            $document->for_app,

                        'revise_remarks' =>
                            $remarks,

                        'status' =>
                            $status,

                        'files' =>
                            $this->buildFileList(
                                filenames:
                                    $document->filenames
                                    ?? null,

                                documentsBaseUrl:
                                    $documentsBaseUrl,
                            ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'total' => $total,
            'data' => $documents,
        ]);
    }

    /**
     * Verify an uploaded document.
     */
    public function verify(
        Request $request,
        string $fileUploadId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $documentExists = $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->exists();

        if (! $documentExists) {
            return response()->json(
                [
                    'message' =>
                        'The uploaded document could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->update([
                'sto_validated' => 'Y',
                'for_app' => 'N',
                'revise_remarks' => '',

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'The file has been validated.',
        ]);
    }

    /**
     * Return an uploaded document to the student for revision.
     */
    public function revise(
        Request $request,
        string $fileUploadId,
    ): JsonResponse {
        $validated = $request->validate(
            [
                'revise_remarks' => [
                    'required',
                    'string',
                ],
            ],
            [
                'revise_remarks.required' =>
                    'Reason for revision is required.',
            ],
        );

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $documentExists = $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->exists();

        if (! $documentExists) {
            return response()->json(
                [
                    'message' =>
                        'The uploaded document could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->update([
                'for_app' => 'N',

                'revise_remarks' => trim(
                    $validated['revise_remarks'],
                ),

                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'The submitted record has been saved.',
        ]);
    }

    /**
     * Convert concatenated filenames into file information
     * consumed by the Vue DataTable.
     *
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     url: string
     * }>
     */
    private function buildFileList(
        mixed $filenames,
        string $documentsBaseUrl,
    ): array {
        $value = trim(
            (string) $filenames,
        );

        if (
            $value === '' ||
            $documentsBaseUrl === ''
        ) {
            return [];
        }

        return collect(
            explode(
                '|||FILE|||',
                $value,
            ),
        )
            ->map(
                static function (
                    string $filename,
                ): string {
                    return basename(
                        str_replace(
                            '\\',
                            '/',
                            trim($filename),
                        ),
                    );
                },
            )
            ->filter(
                static fn (
                    string $filename,
                ): bool => $filename !== '',
            )
            ->unique()
            ->values()
            ->map(
                static function (
                    string $filename,
                    int $index,
                ) use (
                    $documentsBaseUrl,
                ): array {
                    return [
                        'name' =>
                            $filename,

                        'label' =>
                            'View or download document '.
                            ($index + 1),

                        'url' =>
                            $documentsBaseUrl.
                            '/'.
                            rawurlencode(
                                $filename,
                            ),
                    ];
                },
            )
            ->all();
    }

    /**
     * Read the school code selected during login.
     */
    private function resolveSchoolCode(
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
     * Resolve the database selected during login.
     */
    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = $this->resolveSchoolCode(
            $request,
        );

        if ($schoolCode === '') {
            return response()->json(
                [
                    'message' =>
                        'No school database has been selected.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $schools = config(
            'schools.schools',
            [],
        );

        if (! is_array($schools)) {
            return response()->json(
                [
                    'message' =>
                        'School configuration is unavailable.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $school =
            $schools[$schoolCode] ?? null;

        if (! is_array($school)) {
            return response()->json(
                [
                    'message' =>
                        'The selected school is not configured.',

                    'schoolCode' =>
                        $schoolCode,
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code']
                    ?? $schoolCode
                ),
            ),
        );

        if (
            $configuredCode === '' ||
            ! hash_equals(
                $configuredCode,
                $schoolCode,
            )
        ) {
            return response()->json(
                [
                    'message' =>
                        'The selected school code is invalid.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $connection =
            $school['connection'] ?? null;

        if (
            ! is_string($connection) ||
            trim($connection) === ''
        ) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is missing.',

                    'schoolCode' =>
                        $schoolCode,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $connection = trim(
            $connection,
        );

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (! is_array($connectionConfig)) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is not configured.',

                    'schoolCode' =>
                        $schoolCode,

                    'connection' =>
                        $connection,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        config([
            'database.default' =>
                $connection,
        ]);

        DB::setDefaultConnection(
            $connection,
        );

        return DB::connection(
            $connection,
        );
    }
}