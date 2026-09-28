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
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
                        $status = 'Pending';
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
     * Return only the authenticated student's uploaded documents.
     */
    public function studentIndex(
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

        $query = $db
            ->table('file_upload')
            ->leftJoin(
                'requirement',
                'requirement.id',
                '=',
                'file_upload.requirement_id',
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
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'file_upload.file_desc',
                'requirement.desc_requirement',
                'file_upload.revise_remarks',
                'file_upload.date_uploaded',
            ],
            sortableColumns: [
                'file_desc' =>
                    'file_upload.file_desc',
                'desc_requirement' =>
                    'requirement.desc_requirement',
                'date_uploaded' =>
                    'file_upload.date_uploaded',
                'last_update' =>
                    'file_upload.last_update',
                'sto_validated' =>
                    'file_upload.sto_validated',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $records = collect(
            $result['data'] ?? [],
        );

        $documentIds = $records
            ->map(
                static function ($row): string {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    return trim(
                        (string) (
                            $record['id']
                            ?? ''
                        ),
                    );
                },
            )
            ->filter()
            ->values();

        $documentFiles = $documentIds->isEmpty()
            ? collect()
            : $db
                ->table('file_upload_d')
                ->whereIn(
                    'file_upload_id',
                    $documentIds->all(),
                )
                ->orderBy('order_no')
                ->orderBy('id')
                ->get([
                    'id',
                    'file_upload_id',
                    'filename_d',
                    'order_no',
                ])
                ->groupBy(
                    static fn (
                        object $file,
                    ): string => (string)
                        $file->file_upload_id,
                );

        $documentsBaseUrl =
            $this->documentsBaseUrl(
                $request,
            );

        $result['data'] = $records
            ->map(
                function ($row) use (
                    $documentFiles,
                    $documentsBaseUrl,
                ): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    $documentId = trim(
                        (string) (
                            $record['id']
                            ?? ''
                        ),
                    );

                    $record['files'] = collect(
                        $documentFiles->get(
                            $documentId,
                            collect(),
                        ),
                    )
                        ->map(
                            static function (
                                object $file,
                            ) use (
                                $documentsBaseUrl,
                            ): array {
                                $filename = basename(
                                    str_replace(
                                        '\\',
                                        '/',
                                        trim(
                                            (string)
                                                $file
                                                    ->filename_d,
                                        ),
                                    ),
                                );

                                return [
                                    'id' =>
                                        (string) $file->id,
                                    'name' =>
                                        $filename,
                                    'label' =>
                                        $filename,
                                    'url' =>
                                        $documentsBaseUrl !== ''
                                            ? (
                                                $documentsBaseUrl
                                                . '/'
                                                . rawurlencode(
                                                    $filename,
                                                )
                                            )
                                            : null,
                                    'order_no' =>
                                        $file->order_no,
                                ];
                            },
                        )
                        ->filter(
                            static fn (
                                array $file,
                            ): bool => (
                                $file['name'] ?? ''
                            ) !== '',
                        )
                        ->values()
                        ->all();

                    $record['status'] =
                        $this->documentStatus(
                            $record,
                        );

                    $record['can_edit'] =
                        ! $this->recordIsVerified(
                            $record,
                        );

                    $record['can_delete'] =
                        ! $this->recordIsVerified(
                            $record,
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
     * Return requirement choices used by the student document uploader.
     */
    public function studentOptions(
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

        $documentsBaseUrl =
            $this->documentsBaseUrl(
                $request,
            );

        $requirements = $db
            ->table('requirement')
            ->whereNotNull(
                'desc_requirement',
            )
            ->where(
                'desc_requirement',
                '!=',
                '',
            )
            ->orderByRaw(
                'CASE WHEN prio IS NULL THEN 1 ELSE 0 END',
            )
            ->orderByDesc('prio')
            ->orderBy(
                'desc_requirement',
            )
            ->get([
                'id',
                'code_requirement',
                'desc_requirement',
                'template',
                'prio',
            ])
            ->map(
                static function (
                    object $requirement,
                ) use (
                    $documentsBaseUrl,
                ): array {
                    $template = basename(
                        str_replace(
                            '\\',
                            '/',
                            trim(
                                (string) (
                                    $requirement
                                        ->template
                                    ?? ''
                                ),
                            ),
                        ),
                    );

                    return [
                        'id' =>
                            (string) $requirement->id,
                        'code_requirement' =>
                            $requirement
                                ->code_requirement,
                        'desc_requirement' =>
                            $requirement
                                ->desc_requirement,
                        'prio' =>
                            $requirement->prio,
                        'template' =>
                            $template !== ''
                                ? $template
                                : null,
                        'template_url' =>
                            $template !== ''
                            && $documentsBaseUrl !== ''
                                ? (
                                    $documentsBaseUrl
                                    . '/'
                                    . rawurlencode(
                                        $template,
                                    )
                                )
                                : null,
                    ];
                },
            )
            ->values();

        return response()->json([
            'data' =>
                $requirements,
        ]);
    }

    /**
     * Create a new document submission for the authenticated student.
     */
    public function studentStore(
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

        $validated = $request->validate([
            'requirement_id' => [
                'required',
                'string',
                'max:64',
            ],
            'file_desc' => [
                'required',
                'string',
                'max:100',
            ],
            'files' => [
                'required',
                'array',
                'min:1',
            ],
            'files.*' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,jpg,jpeg,png,gif,bmp,webp,xls,xlsx,ppt,pptx,txt,csv',
                'max:25600',
            ],
            'confirm_authenticity' => [
                'accepted',
            ],
        ], [
            'files.required' =>
                'Attach at least one document.',
            'files.min' =>
                'Attach at least one document.',
            'files.*.max' =>
                'Each attachment must not exceed 25 MB.',
            'confirm_authenticity.accepted' =>
                'You must confirm the authenticity and responsibility notice before submitting.',
        ]);

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $studentId = (string) $account
            ->getAuthIdentifier();

        $requirementExists = $db
            ->table('requirement')
            ->where(
                'id',
                $validated['requirement_id'],
            )
            ->exists();

        if (! $requirementExists) {
            return response()->json(
                [
                    'message' =>
                        'The selected requirement could not be found.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        );

        $fileUploadId =
            $this->uniqueTableId(
                $db,
                'file_upload',
            );

        $uploadedFiles = [];

        try {
            $disk = $this->studentDocumentDisk(
                $request,
            );

            foreach (
                $request->file(
                    'files',
                    [],
                ) as $uploadedFile
            ) {
                if (
                    ! $uploadedFile instanceof UploadedFile
                    || ! $uploadedFile->isValid()
                ) {
                    throw new RuntimeException(
                        'One of the selected attachments is invalid.',
                    );
                }

                $filename =
                    $this->storeStudentDocumentFile(
                        $disk,
                        $uploadedFile,
                        $timestamp,
                    );

                $uploadedFiles[] =
                    $filename;
            }

            $db->transaction(
                function () use (
                    $db,
                    $validated,
                    $studentId,
                    $timestamp,
                    $fileUploadId,
                    $uploadedFiles,
                ): void {
                    $db
                        ->table('file_upload')
                        ->insert([
                            'id' =>
                                $fileUploadId,
                            'file_name' =>
                                trim(
                                    $validated[
                                        'file_desc'
                                    ],
                                ),
                            'file_desc' =>
                                trim(
                                    $validated[
                                        'file_desc'
                                    ],
                                ),
                            'date_uploaded' =>
                                $timestamp->format(
                                    'Y-m-d',
                                ),
                            'time_uploaded' =>
                                $timestamp->format(
                                    'H:i:s',
                                ),
                            'requirement_id' =>
                                $validated[
                                    'requirement_id'
                                ],
                            'owner_id' =>
                                $studentId,
                            'sto_validated' =>
                                'N',
                            'for_app' =>
                                'Y',
                            'revise_remarks' =>
                                '',
                            'last_update' =>
                                $timestamp->format(
                                    'Y-m-d H:i:s',
                                ),
                        ]);

                    foreach (
                        $uploadedFiles as
                        $index => $filename
                    ) {
                        $db
                            ->table(
                                'file_upload_d',
                            )
                            ->insert([
                                'id' =>
                                    $this
                                        ->uniqueTableId(
                                            $db,
                                            'file_upload_d',
                                        ),
                                'file_upload_id' =>
                                    $fileUploadId,
                                'filename_d' =>
                                    $filename,
                                'order_no' =>
                                    $index + 1,
                            ]);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->deleteStudentDocumentFiles(
                $request,
                $uploadedFiles,
            );

            report($exception);

            return response()->json(
                [
                    'message' =>
                        'Unable to upload the document.',
                    'error' =>
                        config('app.debug')
                            ? $exception
                                ->getMessage()
                            : null,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json(
            [
                'message' =>
                    'Document submitted for verification.',
                'data' => [
                    'id' =>
                        $fileUploadId,
                ],
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Update or resubmit a non-verified document owned by the student.
     */
    public function studentUpdate(
        Request $request,
        string $fileUploadId,
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

        $validated = $request->validate([
            'requirement_id' => [
                'required',
                'string',
                'max:64',
            ],
            'file_desc' => [
                'required',
                'string',
                'max:100',
            ],
            'files' => [
                'nullable',
                'array',
            ],
            'files.*' => [
                'file',
                'mimes:pdf,doc,docx,jpg,jpeg,png,gif,bmp,webp,xls,xlsx,ppt,pptx,txt,csv',
                'max:25600',
            ],
            'remove_file_ids' => [
                'nullable',
                'array',
            ],
            'remove_file_ids.*' => [
                'string',
                'max:64',
            ],
            'confirm_authenticity' => [
                'accepted',
            ],
        ], [
            'files.*.max' =>
                'Each attachment must not exceed 25 MB.',
            'confirm_authenticity.accepted' =>
                'You must confirm the authenticity and responsibility notice before submitting.',
        ]);

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $studentId = (string) $account
            ->getAuthIdentifier();

        $document = $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->where(
                'owner_id',
                $studentId,
            )
            ->first();

        if (! $document) {
            return response()->json(
                [
                    'message' =>
                        'The uploaded document could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            strtoupper(
                trim(
                    (string) (
                        $document
                            ->sto_validated
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return response()->json(
                [
                    'message' =>
                        'Verified documents can no longer be edited or deleted.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $requirementExists = $db
            ->table('requirement')
            ->where(
                'id',
                $validated['requirement_id'],
            )
            ->exists();

        if (! $requirementExists) {
            return response()->json(
                [
                    'message' =>
                        'The selected requirement could not be found.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingFiles = $db
            ->table('file_upload_d')
            ->where(
                'file_upload_id',
                $fileUploadId,
            )
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        $removeFileIds = collect(
            $validated['remove_file_ids']
            ?? [],
        )
            ->map(
                static fn ($id): string =>
                    trim(
                        (string) $id,
                    ),
            )
            ->filter()
            ->unique()
            ->values();

        $removableFiles = $existingFiles
            ->filter(
                static fn (
                    object $file,
                ): bool => $removeFileIds
                    ->contains(
                        (string) $file->id,
                    ),
            )
            ->values();

        if (
            $removeFileIds->count()
            !== $removableFiles->count()
        ) {
            return response()->json(
                [
                    'message' =>
                        'One or more selected attachments do not belong to this document.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $newFiles = collect(
            $request->file(
                'files',
                [],
            ),
        );

        $remainingFileCount =
            $existingFiles->count()
            - $removableFiles->count()
            + $newFiles->count();

        if ($remainingFileCount < 1) {
            return response()->json(
                [
                    'message' =>
                        'At least one document attachment is required.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        );

        $uploadedFiles = [];

        try {
            $disk = $this->studentDocumentDisk(
                $request,
            );

            foreach (
                $newFiles as $uploadedFile
            ) {
                if (
                    ! $uploadedFile instanceof UploadedFile
                    || ! $uploadedFile->isValid()
                ) {
                    throw new RuntimeException(
                        'One of the selected attachments is invalid.',
                    );
                }

                $uploadedFiles[] =
                    $this->storeStudentDocumentFile(
                        $disk,
                        $uploadedFile,
                        $timestamp,
                    );
            }

            $db->transaction(
                function () use (
                    $db,
                    $validated,
                    $fileUploadId,
                    $studentId,
                    $removeFileIds,
                    $uploadedFiles,
                    $timestamp,
                ): void {
                    $db
                        ->table('file_upload')
                        ->where(
                            'id',
                            $fileUploadId,
                        )
                        ->where(
                            'owner_id',
                            $studentId,
                        )
                        ->update([
                            'file_name' =>
                                trim(
                                    $validated[
                                        'file_desc'
                                    ],
                                ),
                            'file_desc' =>
                                trim(
                                    $validated[
                                        'file_desc'
                                    ],
                                ),
                            'requirement_id' =>
                                $validated[
                                    'requirement_id'
                                ],
                            'sto_validated' =>
                                'N',
                            'for_app' =>
                                'Y',
                            'revise_remarks' =>
                                '',
                            'last_update' =>
                                $timestamp->format(
                                    'Y-m-d H:i:s',
                                ),
                        ]);

                    if (
                        $removeFileIds
                            ->isNotEmpty()
                    ) {
                        $db
                            ->table(
                                'file_upload_d',
                            )
                            ->where(
                                'file_upload_id',
                                $fileUploadId,
                            )
                            ->whereIn(
                                'id',
                                $removeFileIds
                                    ->all(),
                            )
                            ->delete();
                    }

                    $nextOrder = (int) (
                        $db
                            ->table(
                                'file_upload_d',
                            )
                            ->where(
                                'file_upload_id',
                                $fileUploadId,
                            )
                            ->max('order_no')
                        ?? 0
                    );

                    foreach (
                        $uploadedFiles as
                        $filename
                    ) {
                        $nextOrder++;

                        $db
                            ->table(
                                'file_upload_d',
                            )
                            ->insert([
                                'id' =>
                                    $this
                                        ->uniqueTableId(
                                            $db,
                                            'file_upload_d',
                                        ),
                                'file_upload_id' =>
                                    $fileUploadId,
                                'filename_d' =>
                                    $filename,
                                'order_no' =>
                                    $nextOrder,
                            ]);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->deleteStudentDocumentFiles(
                $request,
                $uploadedFiles,
            );

            report($exception);

            return response()->json(
                [
                    'message' =>
                        'Unable to update the document.',
                    'error' =>
                        config('app.debug')
                            ? $exception
                                ->getMessage()
                            : null,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $this->deleteStudentDocumentFiles(
            $request,
            $removableFiles
                ->pluck('filename_d')
                ->map(
                    static fn ($filename): string =>
                        trim(
                            (string) $filename,
                        ),
                )
                ->filter()
                ->values()
                ->all(),
        );

        return response()->json([
            'message' =>
                'Document submitted for verification.',
        ]);
    }

    /**
     * Delete a non-verified document owned by the authenticated student.
     */
    public function studentDestroy(
        Request $request,
        string $fileUploadId,
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

        $document = $db
            ->table('file_upload')
            ->where(
                'id',
                $fileUploadId,
            )
            ->where(
                'owner_id',
                $studentId,
            )
            ->first();

        if (! $document) {
            return response()->json(
                [
                    'message' =>
                        'The uploaded document could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            strtoupper(
                trim(
                    (string) (
                        $document
                            ->sto_validated
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return response()->json(
                [
                    'message' =>
                        'Verified documents can no longer be edited or deleted.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $details = $db
            ->table('file_upload_d')
            ->where(
                'file_upload_id',
                $fileUploadId,
            )
            ->get([
                'id',
                'filename_d',
            ]);

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        )->format(
            'Y-m-d H:i:s',
        );

        try {
            $db->transaction(
                function () use (
                    $db,
                    $fileUploadId,
                    $studentId,
                    $details,
                    $timestamp,
                ): void {
                    $db
                        ->table(
                            'backup_activity',
                        )
                        ->insert([
                            'id' =>
                                $this
                                    ->uniqueTableId(
                                        $db,
                                        'backup_activity',
                                    ),
                            'trans_table' =>
                                'file_upload',
                            'trans_id' =>
                                $fileUploadId,
                            'person_id' =>
                                $studentId,
                            'login_id' =>
                                $studentId,
                            'last_update' =>
                                $timestamp,
                            'synced' =>
                                'N',
                            'activity_desc' =>
                                'delete',
                        ]);

                    foreach (
                        $details as $detail
                    ) {
                        $db
                            ->table(
                                'backup_activity',
                            )
                            ->insert([
                                'id' =>
                                    $this
                                        ->uniqueTableId(
                                            $db,
                                            'backup_activity',
                                        ),
                                'trans_table' =>
                                    'file_upload_d',
                                'trans_id' =>
                                    (string)
                                        $detail->id,
                                'person_id' =>
                                    $studentId,
                                'login_id' =>
                                    $studentId,
                                'last_update' =>
                                    $timestamp,
                                'synced' =>
                                    'N',
                                'activity_desc' =>
                                    'delete',
                            ]);
                    }

                    $db
                        ->table('file_upload_d')
                        ->where(
                            'file_upload_id',
                            $fileUploadId,
                        )
                        ->delete();

                    $db
                        ->table('file_upload')
                        ->where(
                            'id',
                            $fileUploadId,
                        )
                        ->where(
                            'owner_id',
                            $studentId,
                        )
                        ->delete();
                },
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                [
                    'message' =>
                        'Unable to delete the document.',
                    'error' =>
                        config('app.debug')
                            ? $exception
                                ->getMessage()
                            : null,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $this->deleteStudentDocumentFiles(
            $request,
            $details
                ->pluck('filename_d')
                ->map(
                    static fn ($filename): string =>
                        trim(
                            (string) $filename,
                        ),
                )
                ->filter()
                ->values()
                ->all(),
        );

        return response()->json([
            'message' =>
                'Document deleted successfully.',
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
     * Return the public selected-school document URL.
     */
    private function documentsBaseUrl(
        Request $request,
    ): string {
        $schoolCode = $this->resolveSchoolCode(
            $request,
        );

        $configured = trim(
            (string) config(
                "schools.schools.{$schoolCode}.files.documents_url",
                '',
            ),
        );

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    "schools.schools.{$schoolCode}.storage.document.upload_url",
                    '',
                ),
            );
        }

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    "database.connections.admapro.schools.{$schoolCode}.storage.document.upload_url",
                    '',
                ),
            );
        }

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    'admapro.document.upload_url',
                    '',
                ),
            );
        }

        return rtrim(
            $configured,
            '/',
        );
    }

    /**
     * Build the selected school's document FTP disk without changing
     * the application's global filesystem configuration.
     */
    private function studentDocumentDisk(
        Request $request,
    ) {
        $schoolCode = $this->resolveSchoolCode(
            $request,
        );

        $school = config(
            "schools.schools.{$schoolCode}",
            [],
        );

        $storage = is_array(
            $school,
        )
        && is_array(
            $school['storage']
            ?? null,
        )
            ? $school['storage']
            : [];

        /*
         * Compatibility with older schools.php revisions that nested
         * storage inside the files section.
         */
        if (
            $storage === []
            && is_array($school)
            && is_array(
                $school['files']['storage']
                ?? null,
            )
        ) {
            $storage =
                $school['files']['storage'];
        }

        if ($storage === []) {
            $legacyStorage = config(
                "database.connections.admapro.schools.{$schoolCode}.storage",
                [],
            );

            $storage = is_array(
                $legacyStorage,
            )
                ? $legacyStorage
                : [];
        }

        $ftp = is_array(
            $storage['ftp'] ?? null,
        )
            ? $storage['ftp']
            : [];

        $document = is_array(
            $storage['document'] ?? null,
        )
            ? $storage['document']
            : [];

        $host = trim(
            (string) (
                $ftp['host']
                ?? config(
                    'admapro.ftp.host',
                    '',
                )
            ),
        );

        $username = trim(
            (string) (
                $ftp['username']
                ?? config(
                    'admapro.ftp.username',
                    '',
                )
            ),
        );

        $password = (string) (
            $ftp['password']
            ?? config(
                'admapro.ftp.password',
                '',
            )
        );

        $port = (int) (
            $ftp['port']
            ?? config(
                'admapro.ftp.port',
                21,
            )
        );

        $root = trim(
            (string) (
                $document['ftp_root']
                ?? config(
                    'admapro.document.ftp_root',
                    '',
                )
            ),
        );

        if (
            $schoolCode === ''
            || $host === ''
            || $username === ''
            || $password === ''
            || $root === ''
            || trim(
                $root,
                '/',
            ) === ''
            || str_contains(
                $root,
                '\\',
            )
            || preg_match(
                '~(?:^|/)\.\.(?:/|$)~',
                $root,
            )
            || $port < 1
            || $port > 65535
        ) {
            throw new RuntimeException(
                'Selected school document storage is incomplete.',
            );
        }

        return Storage::build([
            'driver' =>
                'ftp',
            'host' =>
                $host,
            'username' =>
                $username,
            'password' =>
                $password,
            'port' =>
                $port,
            'root' =>
                $root,
            'passive' =>
                true,
            'ssl' =>
                false,
            'timeout' =>
                30,
            'throw' =>
                true,
        ]);
    }

    /**
     * Generate a collision-resistant ID for a legacy UUID-keyed table.
     */
    private function uniqueTableId(
        ConnectionInterface $db,
        string $table,
    ): string {
        do {
            $id = (string) Str::uuid();
        } while (
            $db
                ->table($table)
                ->where(
                    'id',
                    $id,
                )
                ->exists()
        );

        return $id;
    }

    /**
     * Store one student attachment in the selected school's document folder.
     */
    private function storeStudentDocumentFile(
        $disk,
        UploadedFile $file,
        CarbonImmutable $timestamp,
    ): string {
        $extension = strtolower(
            $file
                ->getClientOriginalExtension(),
        );

        $extension = preg_replace(
            '/[^a-z0-9]/',
            '',
            $extension,
        ) ?: 'bin';

        do {
            $filename =
                'docs_'
                . $timestamp->format(
                    'Ymd_His',
                )
                . '_'
                . bin2hex(
                    random_bytes(4),
                )
                . '.'
                . $extension;
        } while (
            $disk->exists(
                $filename,
            )
        );

        $realPath = $file
            ->getRealPath();

        if (! $realPath) {
            throw new RuntimeException(
                'Unable to locate the selected attachment.',
            );
        }

        $stream = fopen(
            $realPath,
            'rb',
        );

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to read the selected attachment.',
            );
        }

        try {
            $stored = $disk->put(
                $filename,
                $stream,
            );
        } finally {
            fclose(
                $stream,
            );
        }

        if (
            ! $stored
            || ! $disk->exists(
                $filename,
            )
        ) {
            throw new RuntimeException(
                'The attachment could not be stored on the selected school server.',
            );
        }

        return $filename;
    }

    /**
     * Remove uploaded document files from the selected school's FTP storage.
     * Cleanup failures are reported but do not reverse a successful DB commit.
     *
     * @param array<int, string> $filenames
     */
    private function deleteStudentDocumentFiles(
        Request $request,
        array $filenames,
    ): void {
        $filenames = collect(
            $filenames,
        )
            ->map(
                static fn ($filename): string =>
                    basename(
                        str_replace(
                            '\\',
                            '/',
                            trim(
                                (string) $filename,
                            ),
                        ),
                    ),
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($filenames === []) {
            return;
        }

        try {
            $disk = $this->studentDocumentDisk(
                $request,
            );

            foreach (
                $filenames as $filename
            ) {
                if (
                    $disk->exists(
                        $filename,
                    )
                ) {
                    $disk->delete(
                        $filename,
                    );
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Normalize a document status exactly for the student-facing list.
     */
    private function documentStatus(
        array $record,
    ): string {
        if (
            $this->recordIsVerified(
                $record,
            )
        ) {
            return 'Verified';
        }

        $remarks = trim(
            (string) (
                $record[
                    'revise_remarks'
                ]
                ?? ''
            ),
        );

        $forApproval = strtoupper(
            trim(
                (string) (
                    $record[
                        'for_app'
                    ]
                    ?? ''
                ),
            ),
        );

        if (
            $remarks !== ''
            && $forApproval !== 'Y'
        ) {
            return 'For Revision';
        }

        if ($forApproval === 'Y') {
            return 'Pending';
        }

        return 'Draft';
    }

    private function recordIsVerified(
        array $record,
    ): bool {
        return strtoupper(
            trim(
                (string) (
                    $record[
                        'sto_validated'
                    ]
                    ?? ''
                ),
            ),
        ) === 'Y';
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