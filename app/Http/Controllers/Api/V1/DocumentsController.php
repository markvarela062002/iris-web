<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
     * Return documents waiting for verification.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
         * Combine all uploaded detail files into one result for every
         * file_upload record.
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
            ->groupBy('file_upload_id');

        /*
         * Match the legacy administrator filter:
         *
         * (
         *     sto_validated != 'Y'
         *     OR sto_validated = ''
         *     OR sto_validated IS NULL
         * )
         * AND for_app = 'Y'
         */
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
            ->where(function ($query): void {
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
            })
            ->where(
                'file_upload.for_app',
                '=',
                'Y',
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
                'fname' => 'person.fname',
                'lname' => 'person.lname',
                'school_id_no' => 'person.school_id_no',
                'file_desc' => 'file_upload.file_desc',
                'desc_requirement' =>
                    'requirement.desc_requirement',
                'date_uploaded' =>
                    'file_upload.date_uploaded',
            ],
            defaultSortColumn: 'date_uploaded',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        /*
         * Add secure Laravel file URLs.
         *
         * Do not check Storage::exists() here. Doing so would execute
         * one FTP request per file and make the DataTable very slow.
         * The RemoteFileController checks the file when it is opened.
         */
        $result['data'] = collect(
            $result['data'] ?? [],
        )
            ->map(function ($row): array {
                $record = is_object($row)
                    ? get_object_vars($row)
                    : (array) $row;

                $record['files'] = $this->buildFileList(
                    $record['filenames'] ?? null,
                );

                return $record;
            })
            ->values()
            ->all();

        return response()->json($result);
    }

    /**
     * Verify an uploaded document.
     */
    public function verify(
        Request $request,
        string $fileUploadId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $documentExists = $db
            ->table('file_upload')
            ->where('id', $fileUploadId)
            ->exists();

        if (! $documentExists) {
            return response()->json([
                'message' =>
                    'The uploaded document could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $db
            ->table('file_upload')
            ->where('id', $fileUploadId)
            ->update([
                'sto_validated' => 'Y',
                'for_app' => 'N',
                'last_update' =>
                    now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'message' =>
                'The file has been validated.',
        ]);
    }

    /**
     * Return an uploaded document for revision.
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

        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $documentExists = $db
            ->table('file_upload')
            ->where('id', $fileUploadId)
            ->exists();

        if (! $documentExists) {
            return response()->json([
                'message' =>
                    'The uploaded document could not be found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $db
            ->table('file_upload')
            ->where('id', $fileUploadId)
            ->update([
                'for_app' => 'N',
                'revise_remarks' => trim(
                    $validated['revise_remarks'],
                ),
                'last_update' =>
                    now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'message' =>
                'The submitted record has been saved.',
        ]);
    }

    /**
     * Convert the GROUP_CONCAT result into file information
     * consumed by the Vue page.
     *
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     url: string
     * }>
     */
    private function buildFileList(
        mixed $filenames,
    ): array {
        $value = trim((string) $filenames);

        if ($value === '') {
            return [];
        }

        return collect(
            explode('|||FILE|||', $value),
        )
            ->map(
                static fn (string $filename): string =>
                    basename(trim($filename)),
            )
            ->filter(
                static fn (string $filename): bool =>
                    $filename !== '',
            )
            ->unique()
            ->values()
            ->map(
                static function (
                    string $filename,
                    int $index,
                ): array {
                    return [
                        'name' => $filename,

                        'label' =>
                            'View document '.
                            ($index + 1),

                        'url' => route(
                            'dashboard.files.upload',
                            [
                                'filename' => $filename,
                            ],
                        ),
                    ];
                },
            )
            ->all();
    }

    /**
     * Resolve the school database selected during login.
     */
    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        if ($schoolCode === '') {
            return response()->json([
                'message' =>
                    'No school database has been selected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $schools = config(
            'schools.schools',
            [],
        );

        if (! is_array($schools)) {
            return response()->json([
                'message' =>
                    'School configuration is unavailable.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $school = $schools[$schoolCode] ?? null;

        if (! is_array($school)) {
            return response()->json([
                'message' =>
                    'The selected school is not configured.',

                'schoolCode' =>
                    $schoolCode,
            ], Response::HTTP_FORBIDDEN);
        }

        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code']
                    ?? $schoolCode
                ),
            ),
        );

        if (! hash_equals(
            $configuredCode,
            $schoolCode,
        )) {
            return response()->json([
                'message' =>
                    'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection =
            $school['connection'] ?? null;

        if (
            ! is_string($connection) ||
            $connection === ''
        ) {
            return response()->json([
                'message' =>
                    'The school database connection is missing.',

                'schoolCode' =>
                    $schoolCode,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (! is_array($connectionConfig)) {
            return response()->json([
                'message' =>
                    'The school database connection is not configured.',

                'schoolCode' =>
                    $schoolCode,

                'connection' =>
                    $connection,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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