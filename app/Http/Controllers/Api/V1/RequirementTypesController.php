<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequirementTypesController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $documentsBaseUrl =
            $this->documentsBaseUrl($request);

        $query = $db
            ->table('requirement')
            ->leftJoin(
                'rank',
                'rank.id',
                '=',
                'requirement.rank_id',
            )
            ->leftJoin(
                'vessel_type',
                'vessel_type.id',
                '=',
                'requirement.vessel_type',
            )
            ->select([
                'requirement.id',
                'requirement.code_requirement',
                'requirement.desc_requirement',
                'requirement.cci_reqd',
                'requirement.rank_id',
                'requirement.vessel_type',
                'requirement.cat_requirement',
                'requirement.school_id',
                'requirement.prio',
                'requirement.template',
                'rank.desc_rank as rank_name',
                'vessel_type.desc_vessel_type as vessel_type_name',
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'requirement.code_requirement',
                'requirement.desc_requirement',
                'requirement.cat_requirement',
                'requirement.template',
                'rank.desc_rank',
                'vessel_type.desc_vessel_type',
            ],
            sortableColumns: [
                'code_requirement' =>
                    'requirement.code_requirement',
                'desc_requirement' =>
                    'requirement.desc_requirement',
                'cci_reqd' =>
                    'requirement.cci_reqd',
                'rank_name' =>
                    'rank.desc_rank',
                'vessel_type_name' =>
                    'vessel_type.desc_vessel_type',
                'cat_requirement' =>
                    'requirement.cat_requirement',
                'prio' =>
                    'requirement.prio',
                'template' =>
                    'requirement.template',
            ],
            defaultSortColumn:
                'desc_requirement',
            defaultSortDirection:
                'asc',
        );

        $result =
            $this->datatableService
                ->addRowNumbers(
                    response: $result,
                    key: 'index',
                );

        $result['data'] =
            collect($result['data'])
                ->map(
                    function (
                        array $row,
                    ) use (
                        $documentsBaseUrl,
                    ): array {
                        $template = trim(
                            (string) (
                                $row[
                                    'template'
                                ]
                                ?? ''
                            ),
                        );

                        $row[
                            'template_url'
                        ] =
                            $template !== ''
                                ? $documentsBaseUrl
                                    . rawurlencode(
                                        basename(
                                            $template,
                                        ),
                                    )
                                : null;

                        return $row;
                    },
                )
                ->all();

        return response()->json(
            $result,
        );
    }

    public function options(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $ranks = $db
            ->table('rank')
            ->select([
                'id',
                'desc_rank',
            ])
            ->orderBy('desc_rank')
            ->get()
            ->map(
                static function (
                    object $row,
                ): array {
                    return [
                        'label' => trim(
                            (string)
                                $row->desc_rank,
                        ),
                        'value' =>
                            (string) $row->id,
                    ];
                },
            )
            ->values()
            ->all();

        $vesselTypes = $db
            ->table('vessel_type')
            ->select([
                'id',
                'desc_vessel_type',
            ])
            ->orderBy(
                'desc_vessel_type',
            )
            ->get()
            ->map(
                static function (
                    object $row,
                ): array {
                    return [
                        'label' => trim(
                            (string)
                                $row
                                    ->desc_vessel_type,
                        ),
                        'value' =>
                            (string) $row->id,
                    ];
                },
            )
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'ranks' => $ranks,
                'vesselTypes' =>
                    $vesselTypes,
            ],
        ]);
    }

    public function store(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $input = $this->validatedInput(
            request: $request,
            db: $db,
        );

        $this->ensureUniqueRequirement(
            db: $db,
            code:
                $input[
                    'code_requirement'
                ],
            description:
                $input[
                    'desc_requirement'
                ],
        );

        $id = (string) Str::uuid();

        $templateName = null;

        try {
            if (
                $request->hasFile(
                    'template_file',
                )
            ) {
                $templateName =
                    $this->storeTemplate(
                        request: $request,
                        file:
                            $request->file(
                                'template_file',
                            ),
                        code:
                            $input[
                                'code_requirement'
                            ],
                    );
            }

            $db->transaction(
                function () use (
                    $db,
                    $id,
                    $input,
                    $templateName,
                ): void {
                    $db
                        ->table(
                            'requirement',
                        )
                        ->insert([
                            'id' => $id,

                            'code_requirement' =>
                                $input[
                                    'code_requirement'
                                ],

                            'desc_requirement' =>
                                $input[
                                    'desc_requirement'
                                ],

                            'cci_reqd' =>
                                $input[
                                    'cci_reqd'
                                ],

                            'rank_id' =>
                                $input[
                                    'rank_id'
                                ],

                            'vessel_type' =>
                                $input[
                                    'vessel_type'
                                ],

                            'cat_requirement' =>
                                $input[
                                    'cat_requirement'
                                ],

                            /*
                             * The legacy form does
                             * not expose school_id.
                             */
                            'school_id' =>
                                null,

                            /*
                             * Preserve the legacy
                             * create behavior:
                             * blank priority = 1.
                             */
                            'prio' =>
                                $input[
                                    'prio'
                                ]
                                ?? 1,

                            'template' =>
                                $templateName,
                        ]);
                },
            );
        } catch (Throwable $exception) {
            if (
                $templateName !== null
            ) {
                $this
                    ->deleteTemplateQuietly(
                        request:
                            $request,
                        filename:
                            $templateName,
                    );
            }

            if (
                $exception
                    instanceof
                    QueryException
                &&
                (string)
                    $exception
                        ->getCode()
                    === '23000'
            ) {
                throw ValidationException
                    ::withMessages([
                        'code_requirement' => [
                            'The requirement code or description already exists.',
                        ],
                    ]);
            }

            throw $exception;
        }

        return response()->json([
            'message' =>
                'Requirement type created successfully.',

            'data' =>
                $this->findRequirement(
                    request:
                        $request,
                    db: $db,
                    requirementId:
                        $id,
                ),
        ], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        string $requirementId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $existing = $db
            ->table('requirement')
            ->where(
                'id',
                $requirementId,
            )
            ->first();

        if (! $existing) {
            return response()->json([
                'message' =>
                    'Requirement type not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $input = $this->validatedInput(
            request: $request,
            db: $db,
        );

        $this->ensureUniqueRequirement(
            db: $db,
            code:
                $input[
                    'code_requirement'
                ],
            description:
                $input[
                    'desc_requirement'
                ],
            exceptId:
                $requirementId,
        );

        $oldTemplate = trim(
            (string) (
                $existing->template
                ?? ''
            ),
        );

        $newTemplate = null;

        try {
            if (
                $request->hasFile(
                    'template_file',
                )
            ) {
                $newTemplate =
                    $this->storeTemplate(
                        request: $request,
                        file:
                            $request->file(
                                'template_file',
                            ),
                        code:
                            $input[
                                'code_requirement'
                            ],
                    );
            }

            $update = [
                'code_requirement' =>
                    $input[
                        'code_requirement'
                    ],

                'desc_requirement' =>
                    $input[
                        'desc_requirement'
                    ],

                'cci_reqd' =>
                    $input[
                        'cci_reqd'
                    ],

                'rank_id' =>
                    $input[
                        'rank_id'
                    ],

                'vessel_type' =>
                    $input[
                        'vessel_type'
                    ],

                'cat_requirement' =>
                    $input[
                        'cat_requirement'
                    ],

                'prio' =>
                    $input['prio']
                    ?? 1,
            ];

            if (
                $newTemplate !== null
            ) {
                $update['template'] =
                    $newTemplate;
            }

            $db
                ->table('requirement')
                ->where(
                    'id',
                    $requirementId,
                )
                ->update($update);
        } catch (Throwable $exception) {
            if (
                $newTemplate !== null
            ) {
                $this
                    ->deleteTemplateQuietly(
                        request:
                            $request,
                        filename:
                            $newTemplate,
                    );
            }

            if (
                $exception
                    instanceof
                    QueryException
                &&
                (string)
                    $exception
                        ->getCode()
                    === '23000'
            ) {
                throw ValidationException
                    ::withMessages([
                        'code_requirement' => [
                            'The requirement code or description already exists.',
                        ],
                    ]);
            }

            throw $exception;
        }

        if (
            $newTemplate !== null
            &&
            $oldTemplate !== ''
            &&
            $oldTemplate
                !== $newTemplate
        ) {
            $stillReferenced = $db
                ->table('requirement')
                ->where(
                    'template',
                    $oldTemplate,
                )
                ->exists();

            if (! $stillReferenced) {
                $this
                    ->deleteTemplateQuietly(
                        request:
                            $request,
                        filename:
                            $oldTemplate,
                    );
            }
        }

        return response()->json([
            'message' =>
                'Requirement type updated successfully.',

            'data' =>
                $this->findRequirement(
                    request:
                        $request,
                    db: $db,
                    requirementId:
                        $requirementId,
                ),
        ]);
    }

    public function destroy(
        Request $request,
        string $requirementId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $requirement = $db
            ->table('requirement')
            ->where(
                'id',
                $requirementId,
            )
            ->first([
                'id',
                'code_requirement',
                'desc_requirement',
            ]);

        if (! $requirement) {
            return response()->json([
                'message' =>
                    'Requirement type not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $deleted = $db
                ->table('requirement')
                ->where(
                    'id',
                    $requirementId,
                )
                ->delete();
        } catch (QueryException $exception) {
            if (
                (string)
                    $exception->getCode()
                === '23000'
            ) {
                return response()->json([
                    'message' =>
                        'This requirement type is already being used and cannot be deleted.',
                ], Response::HTTP_CONFLICT);
            }

            throw $exception;
        }

        if ($deleted < 1) {
            return response()->json([
                'message' =>
                    'Requirement type was not deleted.',
            ], Response::HTTP_CONFLICT);
        }

        /*
         * Preserve legacy delete behavior:
         * remove the DB record only. Do not
         * automatically remove a legacy
         * template file because another old
         * record may reference the same file.
         */

        return response()->json([
            'message' =>
                'Requirement type deleted successfully.',
        ]);
    }

    /**
     * @return array{
     *     code_requirement: string,
     *     desc_requirement: string,
     *     cci_reqd: string,
     *     rank_id: string|null,
     *     vessel_type: string|null,
     *     cat_requirement: string|null,
     *     prio: int|null
     * }
     */
    private function validatedInput(
        Request $request,
        ConnectionInterface $db,
    ): array {
        $input = $request->validate([
            'code_requirement' => [
                'required',
                'string',
                'max:100',
            ],

            'desc_requirement' => [
                'required',
                'string',
                'max:200',
            ],

            'cci_reqd' => [
                'required',
                Rule::in([
                    'Y',
                    'N',
                ]),
            ],

            'rank_id' => [
                'nullable',
                'string',
                'max:36',
            ],

            'vessel_type' => [
                'nullable',
                'string',
                'max:50',
            ],

            'cat_requirement' => [
                'nullable',
                'string',
                'max:20',
            ],

            'prio' => [
                'nullable',
                'integer',
            ],

            'template_file' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,gif,bmp,pdf,doc,docx,xls,xlsx,csv',
            ],
        ]);

        $rankId =
            $this->nullableTrim(
                $input['rank_id']
                ?? null,
            );

        $vesselType =
            $this->nullableTrim(
                $input[
                    'vessel_type'
                ]
                ?? null,
            );

        if (
            $rankId !== null
            &&
            ! $db
                ->table('rank')
                ->where(
                    'id',
                    $rankId,
                )
                ->exists()
        ) {
            throw ValidationException
                ::withMessages([
                    'rank_id' => [
                        'The selected rank is invalid.',
                    ],
                ]);
        }

        if (
            $vesselType !== null
            &&
            ! $db
                ->table(
                    'vessel_type',
                )
                ->where(
                    'id',
                    $vesselType,
                )
                ->exists()
        ) {
            throw ValidationException
                ::withMessages([
                    'vessel_type' => [
                        'The selected vessel type is invalid.',
                    ],
                ]);
        }

        return [
            'code_requirement' => trim(
                (string)
                    $input[
                        'code_requirement'
                    ],
            ),

            'desc_requirement' => trim(
                (string)
                    $input[
                        'desc_requirement'
                    ],
            ),

            'cci_reqd' =>
                (string)
                    $input['cci_reqd'],

            'rank_id' =>
                $rankId,

            'vessel_type' =>
                $vesselType,

            'cat_requirement' =>
                $this->nullableTrim(
                    $input[
                        'cat_requirement'
                    ]
                    ?? null,
                ),

            'prio' =>
                isset(
                    $input['prio'],
                )
                    ? (int)
                        $input['prio']
                    : null,
        ];
    }

    private function ensureUniqueRequirement(
        ConnectionInterface $db,
        string $code,
        string $description,
        ?string $exceptId = null,
    ): void {
        $codeQuery = $db
            ->table('requirement')
            ->where(
                'code_requirement',
                $code,
            );

        if ($exceptId !== null) {
            $codeQuery->where(
                'id',
                '!=',
                $exceptId,
            );
        }

        if ($codeQuery->exists()) {
            throw ValidationException
                ::withMessages([
                    'code_requirement' => [
                        'The requirement code already exists.',
                    ],
                ]);
        }

        $descriptionQuery = $db
            ->table('requirement')
            ->where(
                'desc_requirement',
                $description,
            );

        if ($exceptId !== null) {
            $descriptionQuery->where(
                'id',
                '!=',
                $exceptId,
            );
        }

        if (
            $descriptionQuery->exists()
        ) {
            throw ValidationException
                ::withMessages([
                    'desc_requirement' => [
                        'The requirement description already exists.',
                    ],
                ]);
        }
    }

    private function storeTemplate(
        Request $request,
        ?UploadedFile $file,
        string $code,
    ): string {
        if (
            ! $file
            ||
            ! $file->isValid()
        ) {
            throw ValidationException
                ::withMessages([
                    'template_file' => [
                        'The selected template file is invalid.',
                    ],
                ]);
        }

        $diskName =
            $this->schoolDocumentDiskName(
                $request,
            );

        $extension = strtolower(
            $file
                ->getClientOriginalExtension(),
        );

        $safeCode = trim(
            (string) preg_replace(
                '/[^A-Za-z0-9_-]+/',
                '_',
                $code,
            ),
            '_',
        );

        if ($safeCode === '') {
            $safeCode =
                'requirement';
        }

        $filename = sprintf(
            'requirement_%s_%s_%s.%s',
            strtolower($safeCode),
            now('Asia/Manila')->format(
                'Ymd_His',
            ),
            Str::lower(
                Str::random(8),
            ),
            $extension,
        );

        $stream = fopen(
            $file->getRealPath(),
            'rb',
        );

        if ($stream === false) {
            throw new RuntimeException(
                'The selected template file could not be read.',
            );
        }

        try {
            $stored = Storage
                ::disk($diskName)
                ->put(
                    $filename,
                    $stream,
                );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new RuntimeException(
                'The requirement template could not be uploaded.',
            );
        }

        return $filename;
    }

    private function deleteTemplateQuietly(
        Request $request,
        string $filename,
    ): void {
        $filename = basename(
            trim($filename),
        );

        if ($filename === '') {
            return;
        }

        try {
            $diskName =
                $this->schoolDocumentDiskName(
                    $request,
                );

            $disk =
                Storage::disk(
                    $diskName,
                );

            if (
                $disk->exists(
                    $filename,
                )
            ) {
                $disk->delete(
                    $filename,
                );
            }
        } catch (Throwable) {
            /*
             * The database update remains
             * authoritative if cleanup of an
             * older remote template fails.
             */
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRequirement(
        Request $request,
        ConnectionInterface $db,
        string $requirementId,
    ): ?array {
        $row = $db
            ->table('requirement')
            ->leftJoin(
                'rank',
                'rank.id',
                '=',
                'requirement.rank_id',
            )
            ->leftJoin(
                'vessel_type',
                'vessel_type.id',
                '=',
                'requirement.vessel_type',
            )
            ->where(
                'requirement.id',
                $requirementId,
            )
            ->first([
                'requirement.id',
                'requirement.code_requirement',
                'requirement.desc_requirement',
                'requirement.cci_reqd',
                'requirement.rank_id',
                'requirement.vessel_type',
                'requirement.cat_requirement',
                'requirement.school_id',
                'requirement.prio',
                'requirement.template',
                'rank.desc_rank as rank_name',
                'vessel_type.desc_vessel_type as vessel_type_name',
            ]);

        if (! $row) {
            return null;
        }

        $data = (array) $row;

        $template = trim(
            (string) (
                $data['template']
                ?? ''
            ),
        );

        $data['template_url'] =
            $template !== ''
                ? $this
                    ->documentsBaseUrl(
                        $request,
                    )
                    . rawurlencode(
                        basename(
                            $template,
                        ),
                    )
                : null;

        return $data;
    }

    private function documentsBaseUrl(
        Request $request,
    ): string {
        $schoolCode =
            $this->resolveSchoolCode(
                $request,
            );

        $url = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.documents_url",
                    '',
                ),
            ),
            '/',
        );

        if (
            $url === ''
            ||
            filter_var(
                $url,
                FILTER_VALIDATE_URL,
            ) === false
        ) {
            throw new RuntimeException(
                'The selected school document URL is not configured.',
            );
        }

        return $url . '/';
    }

    private function schoolDocumentDiskName(
        Request $request,
    ): string {
        $schoolCode = strtolower(
            $this->resolveSchoolCode(
                $request,
            ),
        );

        $diskName = sprintf(
            'admapro_%s_documents',
            $schoolCode,
        );

        if (
            ! is_array(
                config(
                    "filesystems.disks.{$diskName}",
                ),
            )
        ) {
            throw new RuntimeException(
                'The selected school document storage is not configured.',
            );
        }

        return $diskName;
    }

    private function resolveSchoolCode(
        Request $request,
    ): string {
        $schoolCode = strtoupper(
            trim(
                (string)
                    $request
                        ->session()
                        ->get(
                            'school_code',
                            '',
                        ),
            ),
        );

        if ($schoolCode === '') {
            throw new RuntimeException(
                'No school database has been selected.',
            );
        }

        return $schoolCode;
    }

    private function nullableTrim(
        mixed $value,
    ): ?string {
        $text = trim(
            (string) (
                $value
                ?? ''
            ),
        );

        return $text === ''
            ? null
            : $text;
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = strtoupper(
            trim(
                (string)
                    $request
                        ->session()
                        ->get(
                            'school_code',
                            '',
                        ),
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

        $school =
            $schools[$schoolCode]
            ?? null;

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

        if (
            $configuredCode === ''
            ||
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
            $school['connection']
            ?? null;

        if (
            ! is_string($connection)
            ||
            $connection === ''
        ) {
            return response()->json([
                'message' =>
                    'The school database connection is missing.',
                'schoolCode' =>
                    $schoolCode,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $connectionConfig =
            config(
                "database.connections.{$connection}",
            );

        if (
            ! is_array(
                $connectionConfig,
            )
        ) {
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
