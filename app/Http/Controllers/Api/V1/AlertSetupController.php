<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AlertSetupController extends Controller
{
    private const ALERT_TYPES = [
        'person_activity' => 'Activity Updates',
        'file_upload' => 'Document Uploading',
        'person_task' => 'Training Record Book - OTG',
        'person_journal' => 'Daily Journal',
    ];

    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    public function index(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $query = $db
            ->table('alert_setup')
            ->select([
                'alert_setup.id',
                'alert_setup.as_of',
                'alert_setup.alert_type',
                'alert_setup.inactive_days',
                'alert_setup.login_id',
                'alert_setup.last_update',
            ])
            ->whereIn(
                'alert_setup.alert_type',
                array_keys(
                    self::ALERT_TYPES,
                ),
            );

        $result = $this
            ->datatableService
            ->paginate(
                query: $query,
                request: $request,
                searchableColumns: [
                    'alert_setup.alert_type',
                ],
                sortableColumns: [
                    'as_of' =>
                        'alert_setup.as_of',
                    'alert_type' =>
                        'alert_setup.alert_type',
                    'inactive_days' =>
                        'alert_setup.inactive_days',
                ],
                defaultSortColumn:
                    'as_of',
                defaultSortDirection:
                    'desc',
            );

        $result = $this
            ->datatableService
            ->addRowNumbers(
                response: $result,
                key: 'index',
            );

        $result['data'] = collect(
            $result['data'],
        )
            ->map(
                static function (
                    array $row,
                ): array {
                    $alertType = trim(
                        (string) (
                            $row[
                                'alert_type'
                            ]
                            ?? ''
                        ),
                    );

                    $row[
                        'alert_type_label'
                    ] =
                        self::ALERT_TYPES[
                            $alertType
                        ]
                        ?? $alertType;

                    return $row;
                },
            )
            ->all();

        return response()->json(
            $result,
        );
    }

    public function update(
        Request $request,
        string $alertSetupId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $record = $db
            ->table('alert_setup')
            ->where(
                'id',
                $alertSetupId,
            )
            ->first();

        if (! $record) {
            return response()->json([
                'message' =>
                    'Alert setup record was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $alertType = trim(
            (string) (
                $record->alert_type
                ?? ''
            ),
        );

        if (
            ! array_key_exists(
                $alertType,
                self::ALERT_TYPES,
            )
        ) {
            return response()->json([
                'message' =>
                    'This alert type is not supported.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->validate([
            'inactive_days' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $loginId = trim(
            (string) (
                $request->user()?->id
                ?? $request
                    ->session()
                    ->get(
                        'login_id',
                        '',
                    )
            ),
        );

        $db
            ->table('alert_setup')
            ->where(
                'id',
                $alertSetupId,
            )
            ->update([
                'inactive_days' =>
                    (int) $input[
                        'inactive_days'
                    ],
                'login_id' =>
                    $loginId !== ''
                        ? $loginId
                        : null,
                'last_update' =>
                    now(
                        'Asia/Manila',
                    )->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'Alert inactivity days updated successfully.',
            'data' => [
                'id' =>
                    $alertSetupId,
                'inactive_days' =>
                    (int) $input[
                        'inactive_days'
                    ],
            ],
        ]);
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
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
            ! is_string(
                $connection,
            )
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
