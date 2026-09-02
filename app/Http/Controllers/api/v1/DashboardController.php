<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return student records from the database
     * selected during login.
     */
    public function students(
        Request $request,
    ): JsonResponse {
        /*
         * Read the school selected during login.
         * There is intentionally no default school.
         */
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
                        'No school database has been selected.',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        /*
         * Resolve the school using trusted configuration.
         */
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

        $school = $schools[$schoolCode] ?? null;

        if (! is_array($school)) {
            return response()->json(
                [
                    'message' =>
                        'The selected school is not configured.',

                    'schoolCode' => $schoolCode,
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        /*
         * Confirm that the configured school code matches
         * the code stored in the session.
         */
        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code'] ??
                    $schoolCode
                ),
            ),
        );

        if (
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

        /*
         * Retrieve the named database connection.
         */
        $connection = $school['connection'] ?? null;

        if (
            ! is_string($connection) ||
            $connection === ''
        ) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is missing.',

                    'schoolCode' => $schoolCode,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /*
         * Confirm that the connection exists in
         * config/database.php.
         */
        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        if (! is_array($connectionConfig)) {
            return response()->json(
                [
                    'message' =>
                        'The school database connection is not configured.',

                    'schoolCode' => $schoolCode,
                    'connection' => $connection,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /*
         * Ensure this request uses the selected school
         * connection.
         */
        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        /*
         * Retrieve students from the selected school.
         */
        $query = DB::connection($connection)
            ->table('person')
            ->select([
                'code_person',
                'school_id_no',
                'fname',
                'mname',
                'lname',
                'gender',
                'dept',
                'batch_no',
                'last_update',
            ])
            ->whereNotNull('last_update');

        /*
         * Apply searching, pagination, and sorting.
         * Only last_update may be sorted.
         */
        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,

            searchableColumns: [
                'code_person',
                'school_id_no',
                'fname',
                'mname',
                'lname',
                'gender',
                'dept',
                'batch_no',
            ],

            sortableColumns: [
                'last_update' => 'last_update',
            ],

            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        /*
         * Add a continuous index across paginated pages.
         */
        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        return response()->json($result);
    }
}