<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ActivitiesController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return activities waiting for verification.
     */
    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
         * Resolve the school selected during login.
         */
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        /*
         * Get the school's public activity-file URL.
         *
         * Example:
         * https://igcfi-iris.com/person_task
         */
        $activityFileBaseUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.activity_url",
                    '',
                ),
            ),
            '/',
        );

        /*
         * Legacy administrator filter:
         *
         * sto_validated != 'Y'
         * for_app = 'Y'
         * ORDER BY last_update DESC
         */
        $query = $db
            ->table('person_activity')
            ->leftJoin(
                'person',
                'person_activity.person_id',
                '=',
                'person.id',
            )
            ->leftJoin(
                'activity',
                'person_activity.activity_id',
                '=',
                'activity.id',
            )
            ->select([
                'person_activity.id',
                'person_activity.person_id',
                'person_activity.activity_id',
                'person_activity.filename',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.last_update',
                'person_activity.sto_validated',
                'person_activity.for_app',
                'person_activity.revise_remarks',

                'person.code_person',
                'person.school_id_no',
                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',

                'activity.desc_activity',
            ]);

            if (! $request->boolean('monitoring')) {
                $query
                    ->where(
                        'person_activity.sto_validated',
                        '!=',
                        'Y',
                    )
                    ->where(
                        'person_activity.for_app',
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
                'activity.desc_activity',
                'person_activity.filename',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.last_update',
            ],
            sortableColumns: [
                'last_update' =>
                    'person_activity.last_update',

                'code_person' =>
                    'person.code_person',

                'school_id_no' =>
                    'person.school_id_no',

                'fname' =>
                    'person.fname',

                'lname' =>
                    'person.lname',

                'desc_activity' =>
                    'activity.desc_activity',

                'filename' =>
                    'person_activity.filename',

                'start_date' =>
                    'person_activity.start_date',

                'end_date' =>
                    'person_activity.end_date',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        /*
         * Generate a direct public HTTPS URL for every
         * activity attachment.
         *
         * The browser requests the public file directly,
         * so Laravel does not need to proxy it through FTP.
         */
        $result['data'] = collect(
            $result['data'] ?? [],
        )
            ->map(
                function ($row) use (
                    $activityFileBaseUrl,
                ): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    /*
                     * Remove accidental directory components
                     * stored in the database.
                     */
                    $filename = basename(
                        str_replace(
                            '\\',
                            '/',
                            trim(
                                (string) (
                                    $record['filename']
                                    ?? ''
                                ),
                            ),
                        ),
                    );

                    $record['filename'] =
                        $filename;

                    $record['file_url'] =
                        $filename !== '' &&
                        $activityFileBaseUrl !== ''
                            ? $activityFileBaseUrl.
                                '/'.
                                rawurlencode(
                                    $filename,
                                )
                            : null;

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
 * Return the authenticated student's activity summary.
 */
public function studentDashboard(
    Request $request,
): JsonResponse {
    $account = $request->user();

    /*
     * Only authenticated student accounts may use this
     * endpoint.
     */
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

    /*
     * Count every activity belonging to the currently
     * authenticated student.
     */
    $total = $db
        ->table('person_activity')
        ->where(
            'person_id',
            $studentId,
        )
        ->count();

    /*
     * Return only the student's 10 most recent activities.
     */
    $activities = $db
        ->table('person_activity')
        ->leftJoin(
            'activity',
            'person_activity.activity_id',
            '=',
            'activity.id',
        )
        ->where(
            'person_activity.person_id',
            $studentId,
        )
        ->select([
            'person_activity.id',
            'person_activity.activity_id',
            'person_activity.filename',
            'person_activity.start_date',
            'person_activity.end_date',
            'person_activity.last_update',
            'person_activity.sto_validated',
            'person_activity.for_app',
            'person_activity.revise_remarks',
            'activity.desc_activity',
        ])
        ->orderByDesc(
            'person_activity.last_update',
        )
        ->limit(10)
        ->get()
        ->map(
            function (object $activity): array {
                $validated = strtoupper(
                    trim(
                        (string) (
                            $activity->sto_validated
                            ?? ''
                        ),
                    ),
                );

                $forApproval = strtoupper(
                    trim(
                        (string) (
                            $activity->for_app
                            ?? ''
                        ),
                    ),
                );

                $remarks = trim(
                    (string) (
                        $activity->revise_remarks
                        ?? ''
                    ),
                );

                if ($validated === 'Y') {
                    $status = 'Verified';
                } elseif ($forApproval === 'Y') {
                    $status = 'For Verification';
                } elseif ($remarks !== '') {
                    $status = 'For Revision';
                } else {
                    $status = 'Draft';
                }

                return [
                    'id' => $activity->id,

                    'activity_id' =>
                        $activity->activity_id,

                    'description' =>
                        $activity->desc_activity
                        ?? 'Activity',

                    'filename' =>
                        $activity->filename,

                    'start_date' =>
                        $activity->start_date,

                    'end_date' =>
                        $activity->end_date,

                    'last_update' =>
                        $activity->last_update,

                    'sto_validated' =>
                        $activity->sto_validated,

                    'for_app' =>
                        $activity->for_app,

                    'revise_remarks' =>
                        $remarks,

                    'status' => $status,
                ];
            },
        )
        ->values();

    return response()->json([
        'total' => $total,
        'data' => $activities,
    ]);
}


    /**
     * Return only the authenticated student's activity records.
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
            ->table('person_activity')
            ->leftJoin(
                'activity',
                'person_activity.activity_id',
                '=',
                'activity.id',
            )
            ->where(
                'person_activity.person_id',
                $studentId,
            )
            ->select([
                'person_activity.id',
                'person_activity.person_id',
                'person_activity.activity_id',
                'person_activity.filename',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.remarks',
                'person_activity.last_update',
                'person_activity.sto_validated',
                'person_activity.for_app',
                'person_activity.revise_remarks',
                'activity.desc_activity',
            ]);

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'activity.desc_activity',
                'person_activity.remarks',
                'person_activity.revise_remarks',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.filename',
            ],
            sortableColumns: [
                'desc_activity' =>
                    'activity.desc_activity',
                'start_date' =>
                    'person_activity.start_date',
                'end_date' =>
                    'person_activity.end_date',
                'last_update' =>
                    'person_activity.last_update',
                'sto_validated' =>
                    'person_activity.sto_validated',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $activityBaseUrl =
            $this->studentActivityBaseUrl(
                $request,
            );

        $result['data'] = collect(
            $result['data'] ?? [],
        )
            ->map(
                function ($row) use (
                    $activityBaseUrl,
                ): array {
                    $record = is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;

                    $filename = basename(
                        str_replace(
                            '\\\\',
                            '/',
                            trim(
                                (string) (
                                    $record['filename']
                                    ?? ''
                                ),
                            ),
                        ),
                    );

                    $record['filename'] =
                        $filename;

                    $record['file_url'] =
                        $filename !== ''
                        && $activityBaseUrl !== ''
                            ? (
                                $activityBaseUrl
                                . '/'
                                . rawurlencode(
                                    $filename,
                                )
                            )
                            : null;

                    $record['status'] =
                        $this->studentActivityStatus(
                            $record,
                        );

                    $record['can_edit'] =
                        ! $this->studentActivityIsVerified(
                            $record,
                        );

                    $record['can_delete'] =
                        ! $this->studentActivityIsVerified(
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
     * Return activity choices for the authenticated student's form.
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

        $activities = $db
            ->table('activity')
            ->whereNotNull('id')
            ->whereNotNull('desc_activity')
            ->where('desc_activity', '!=', '')
            ->orderBy('desc_activity')
            ->get([
                'id',
                'desc_activity',
            ]);

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * Create an activity submission for the authenticated student.
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
            'activity_id' => [
                'required',
                'string',
                'max:64',
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'confirm_authenticity' => [
                'required',
                'accepted',
            ],
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,gif,bmp,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
                'max:20480',
            ],
        ], [
            'confirm_authenticity.accepted' =>
                'You must confirm the authenticity and responsibility notice.',
            'file.required' =>
                'Attach activity evidence before submitting.',
            'file.max' =>
                'The activity evidence must not exceed 20 MB.',
        ]);

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $studentId = (string) $account
            ->getAuthIdentifier();

        $activityExists = $db
            ->table('activity')
            ->where(
                'id',
                $validated['activity_id'],
            )
            ->exists();

        if (! $activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The selected activity could not be found.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $uploadedFile = $request->file(
            'file',
        );

        if (
            ! $uploadedFile instanceof UploadedFile
            || ! $uploadedFile->isValid()
        ) {
            return response()->json(
                [
                    'message' =>
                        'The selected activity evidence is invalid.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        );

        $activityRecordId =
            $this->uniqueStudentActivityId(
                $db,
            );

        $filename = null;

        try {
            $disk = $this->studentActivityDisk(
                $request,
            );

            $filename =
                $this->storeStudentActivityFile(
                    $disk,
                    $uploadedFile,
                    $timestamp,
                );

            $db
                ->table('person_activity')
                ->insert([
                    'id' =>
                        $activityRecordId,
                    'person_id' =>
                        $studentId,
                    'activity_id' =>
                        $validated['activity_id'],
                    'start_date' =>
                        $validated['start_date'],
                    'end_date' =>
                        $validated['end_date'],
                    'remarks' =>
                        filled(
                            $validated['remarks']
                            ?? null,
                        )
                            ? trim(
                                (string) $validated[
                                    'remarks'
                                ],
                            )
                            : null,
                    'filename' =>
                        $filename,
                    'current' =>
                        'Y',
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
        } catch (Throwable $exception) {
            if ($filename) {
                $this->deleteStudentActivityFile(
                    $request,
                    $filename,
                );
            }

            report($exception);

            return response()->json(
                [
                    'message' =>
                        'Unable to submit the activity.',
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
                    'Activity submitted for verification.',
                'data' => [
                    'id' =>
                        $activityRecordId,
                ],
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Update or resubmit a non-verified activity owned by the student.
     */
    public function studentUpdate(
        Request $request,
        string $activityRecordId,
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
            'activity_id' => [
                'required',
                'string',
                'max:64',
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'confirm_authenticity' => [
                'required',
                'accepted',
            ],
            'file' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,gif,bmp,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
                'max:20480',
            ],
            'remove_file' => [
                'sometimes',
                'boolean',
            ],
        ], [
            'confirm_authenticity.accepted' =>
                'You must confirm the authenticity and responsibility notice.',
            'file.max' =>
                'The activity evidence must not exceed 20 MB.',
        ]);

        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $studentId = (string) $account
            ->getAuthIdentifier();

        $existing = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityRecordId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->first();

        if (! $existing) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            strtoupper(
                trim(
                    (string) (
                        $existing->sto_validated
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return response()->json(
                [
                    'message' =>
                        'Verified activities can no longer be edited.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $activityExists = $db
            ->table('activity')
            ->where(
                'id',
                $validated['activity_id'],
            )
            ->exists();

        if (! $activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The selected activity could not be found.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $oldFilename = basename(
            str_replace(
                '\\\\',
                '/',
                trim(
                    (string) (
                        $existing->filename
                        ?? ''
                    ),
                ),
            ),
        );

        $uploadedFile = $request->file(
            'file',
        );

        $removeExisting = $request->boolean(
            'remove_file',
        );

        if (
            $removeExisting
            && ! $uploadedFile
        ) {
            return response()->json(
                [
                    'message' =>
                        'Attach replacement activity evidence before removing the existing file.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            $oldFilename === ''
            && ! $uploadedFile
        ) {
            return response()->json(
                [
                    'message' =>
                        'Attach activity evidence before resubmitting.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        );

        $newFilename = null;

        try {
            if ($uploadedFile) {
                if (
                    ! $uploadedFile instanceof UploadedFile
                    || ! $uploadedFile->isValid()
                ) {
                    throw new RuntimeException(
                        'The replacement activity evidence is invalid.',
                    );
                }

                $disk = $this->studentActivityDisk(
                    $request,
                );

                $newFilename =
                    $this->storeStudentActivityFile(
                        $disk,
                        $uploadedFile,
                        $timestamp,
                    );
            }

            $updateData = [
                'activity_id' =>
                    $validated['activity_id'],
                'start_date' =>
                    $validated['start_date'],
                'end_date' =>
                    $validated['end_date'],
                'remarks' =>
                    filled(
                        $validated['remarks']
                        ?? null,
                    )
                        ? trim(
                            (string) $validated[
                                'remarks'
                            ],
                        )
                        : null,
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
            ];

            if ($newFilename) {
                $updateData['filename'] =
                    $newFilename;
            }

            $db
                ->table('person_activity')
                ->where(
                    'id',
                    $activityRecordId,
                )
                ->where(
                    'person_id',
                    $studentId,
                )
                ->update(
                    $updateData,
                );
        } catch (Throwable $exception) {
            if ($newFilename) {
                $this->deleteStudentActivityFile(
                    $request,
                    $newFilename,
                );
            }

            report($exception);

            return response()->json(
                [
                    'message' =>
                        'Unable to update the activity.',
                    'error' =>
                        config('app.debug')
                            ? $exception
                                ->getMessage()
                            : null,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        if (
            $newFilename
            && $oldFilename !== ''
            && $oldFilename !== $newFilename
        ) {
            $this->deleteStudentActivityFile(
                $request,
                $oldFilename,
            );
        }

        return response()->json([
            'message' =>
                'Activity submitted for verification.',
        ]);
    }

    /**
     * Delete a non-verified activity owned by the authenticated student.
     */
    public function studentDestroy(
        Request $request,
        string $activityRecordId,
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

        $activity = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityRecordId,
            )
            ->where(
                'person_id',
                $studentId,
            )
            ->first();

        if (! $activity) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            strtoupper(
                trim(
                    (string) (
                        $activity->sto_validated
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return response()->json(
                [
                    'message' =>
                        'Verified activities cannot be deleted.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $filename = basename(
            str_replace(
                '\\\\',
                '/',
                trim(
                    (string) (
                        $activity->filename
                        ?? ''
                    ),
                ),
            ),
        );

        $timestamp = CarbonImmutable::now(
            'Asia/Manila',
        );

        $db->transaction(
            function () use (
                $db,
                $activityRecordId,
                $studentId,
                $timestamp,
            ): void {
                $db
                    ->table('person_activity')
                    ->where(
                        'id',
                        $activityRecordId,
                    )
                    ->where(
                        'person_id',
                        $studentId,
                    )
                    ->delete();

                if (
                    $db
                        ->getSchemaBuilder()
                        ->hasTable(
                            'backup_activity',
                        )
                ) {
                    $db
                        ->table('backup_activity')
                        ->insert([
                            'id' =>
                                (string) Str::uuid(),
                            'trans_table' =>
                                'person_activity',
                            'trans_id' =>
                                $activityRecordId,
                            'person_id' =>
                                $studentId,
                            'login_id' =>
                                $studentId,
                            'last_update' =>
                                $timestamp->format(
                                    'Y-m-d H:i:s',
                                ),
                            'synced' =>
                                'N',
                            'activity_desc' =>
                                'delete',
                        ]);
                }
            },
        );

        if ($filename !== '') {
            $this->deleteStudentActivityFile(
                $request,
                $filename,
            );
        }

        return response()->json([
            'message' =>
                'Activity deleted successfully.',
        ]);
    }

    /**
     * Return activity options for the Monitoring filter.
     */
    public function activityOptions(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activities = $db
            ->table('activity')
            ->select([
                'id',
                'desc_activity',
            ])
            ->orderBy(
                'desc_activity',
            )
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * Verify a submitted activity.
     *
     * Legacy update:
     *
     * sto_validated = 'Y'
     * for_app = 'N'
     * revise_remarks = ''
     * last_update = current date and time
     */
    public function verify(
        Request $request,
        string $activityId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $activityExists = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->exists();

        if (!$activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
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
                'The activity has been validated.',
        ]);
    }

    /**
     * Return an activity to the student for revision.
     *
     * Legacy update:
     *
     * for_app = 'N'
     * revise_remarks = submitted remarks
     * last_update = current date and time
     */
    public function revise(
        Request $request,
        string $activityId,
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

        $activityExists = $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
            )
            ->exists();

        if (!$activityExists) {
            return response()->json(
                [
                    'message' =>
                        'The activity could not be found.',
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        $db
            ->table('person_activity')
            ->where(
                'id',
                $activityId,
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
                'The activity has been saved.',
        ]);
    }


    private function studentActivityStatus(
        array $record,
    ): string {
        if (
            strtoupper(
                trim(
                    (string) (
                        $record['sto_validated']
                        ?? ''
                    ),
                ),
            ) === 'Y'
        ) {
            return 'Verified';
        }

        $remarks = trim(
            (string) (
                $record['revise_remarks']
                ?? ''
            ),
        );

        $forApp = strtoupper(
            trim(
                (string) (
                    $record['for_app']
                    ?? ''
                ),
            ),
        );

        if (
            $remarks !== ''
            && $forApp !== 'Y'
        ) {
            return 'For Revision';
        }

        if ($forApp === 'Y') {
            return 'Pending';
        }

        return 'Draft';
    }

    private function studentActivityIsVerified(
        array|object $record,
    ): bool {
        $value = is_object($record)
            ? ($record->sto_validated ?? '')
            : ($record['sto_validated'] ?? '');

        return strtoupper(
            trim(
                (string) $value,
            ),
        ) === 'Y';
    }

    private function studentActivityBaseUrl(
        Request $request,
    ): string {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        $configured = trim(
            (string) config(
                "schools.schools.{$schoolCode}.files.activity_url",
                '',
            ),
        );

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    "schools.schools.{$schoolCode}.storage.activity.file_url",
                    '',
                ),
            );
        }

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    "database.connections.admapro.schools.{$schoolCode}.storage.activity.file_url",
                    '',
                ),
            );
        }

        if ($configured === '') {
            $configured = trim(
                (string) config(
                    'admapro.activity.file_url',
                    '',
                ),
            );
        }

        return rtrim(
            $configured,
            '/',
        );
    }

    private function studentActivityDisk(
        Request $request,
    ) {
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        $school = config(
            "schools.schools.{$schoolCode}",
            [],
        );

        $storage = is_array($school)
        && is_array(
            $school['storage']
            ?? null,
        )
            ? $school['storage']
            : [];

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

        $activity = is_array(
            $storage['activity'] ?? null,
        )
            ? $storage['activity']
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
                $activity['ftp_root']
                ?? config(
                    'admapro.activity.ftp_root',
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
            || trim($root, '/') === ''
            || str_contains($root, '\\\\')
            || preg_match(
                '~(?:^|/)\\.\\.(?:/|$)~',
                $root,
            )
            || $port < 1
            || $port > 65535
        ) {
            throw new RuntimeException(
                'Selected school activity storage is incomplete.',
            );
        }

        return Storage::build([
            'driver' => 'ftp',
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'port' => $port,
            'root' => $root,
            'passive' => true,
            'ssl' => false,
            'timeout' => 30,
            'throw' => true,
        ]);
    }

    private function uniqueStudentActivityId(
        ConnectionInterface $db,
    ): string {
        do {
            $id = (string) Str::uuid();
        } while (
            $db
                ->table('person_activity')
                ->where('id', $id)
                ->exists()
        );

        return $id;
    }

    private function storeStudentActivityFile(
        $disk,
        UploadedFile $file,
        CarbonImmutable $timestamp,
    ): string {
        $extension = strtolower(
            $file->getClientOriginalExtension(),
        );

        $extension = preg_replace(
            '/[^a-z0-9]/',
            '',
            $extension,
        ) ?: 'bin';

        $candidateTime = $timestamp;

        do {
            $filename =
                'sam_'
                . $candidateTime->format(
                    'Ymd_His',
                )
                . '.'
                . $extension;

            if ($disk->exists($filename)) {
                $candidateTime =
                    $candidateTime->addSecond();
            }
        } while ($disk->exists($filename));

        $realPath = $file->getRealPath();

        if (! $realPath) {
            throw new RuntimeException(
                'Unable to locate the selected activity evidence.',
            );
        }

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to read the selected activity evidence.',
            );
        }

        try {
            $stored = $disk->put(
                $filename,
                $stream,
            );
        } finally {
            fclose($stream);
        }

        if (
            ! $stored
            || ! $disk->exists($filename)
        ) {
            throw new RuntimeException(
                'The activity evidence could not be stored on the selected school server.',
            );
        }

        return $filename;
    }

    private function deleteStudentActivityFile(
        Request $request,
        ?string $filename,
    ): void {
        $filename = basename(
            str_replace(
                '\\\\',
                '/',
                trim(
                    (string) $filename,
                ),
            ),
        );

        if ($filename === '') {
            return;
        }

        try {
            $disk = $this->studentActivityDisk(
                $request,
            );

            if ($disk->exists($filename)) {
                $disk->delete($filename);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

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
            return response()->json(
                [
                    'message' =>
                        'No school has been selected.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $schools = config(
            'schools.schools',
            [],
        );

        if (!is_array($schools)) {
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

        if (!is_array($school)) {
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
            !hash_equals(
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
            !is_string($connection) ||
            $connection === ''
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

        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (!is_array($connectionConfig)) {
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