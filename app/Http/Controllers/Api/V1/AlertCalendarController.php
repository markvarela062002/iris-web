<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AlertCalendarController extends Controller
{
    private const TYPES = [
        'person_activity',
        'file_upload',
        'person_task',
        'person_journal',
    ];

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => [
                'required',
                'date_format:Y-m',
            ],
        ]);

        $db = $this->schoolConnection($request);
        $studentId = $this->authenticatedStudentId($request);

        [$from, $to] = $this->monthRange(
            $validated['month'],
        );

        /*
         * Keep the same monitoring colors already used by the project:
         *
         * Activity Updates    #FF6B00
         * Uploaded Documents  #00BF8F
         * OTG Updates         #FF293D
         * Daily Journals      #347EFF
         */
        $activityEvents = $db
            ->table('person_activity')
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'person_activity.person_id',
                    $studentId,
                ),
            )
            ->selectRaw(
                'start_date AS event_date, COUNT(id) AS qty',
            )
            ->whereNotNull('start_date')
            ->whereBetween(
                'start_date',
                [$from, $to],
            )
            ->groupBy('start_date')
            ->orderBy('start_date')
            ->get()
            ->map(
                static function (object $row): array {
                    $count = (int) $row->qty;

                    return [
                        'id' =>
                            'person_activity-'
                            . $row->event_date,
                        'type' =>
                            'person_activity',
                        'date' =>
                            (string) $row->event_date,
                        'count' =>
                            $count,
                        'label' =>
                            $count
                            . (
                                $count === 1
                                    ? ' activity'
                                    : ' activities'
                            ),
                        'severity' =>
                            'warn',
                        'color' =>
                            '#FF6B00',
                        'icon' =>
                            'pi pi-bolt',
                    ];
                },
            );

        $documentEvents = $db
            ->table('file_upload')
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'file_upload.owner_id',
                    $studentId,
                ),
            )
            ->selectRaw(
                'date_uploaded AS event_date, COUNT(id) AS qty',
            )
            ->whereNotNull('date_uploaded')
            ->whereBetween(
                'date_uploaded',
                [$from, $to],
            )
            ->groupBy('date_uploaded')
            ->orderBy('date_uploaded')
            ->get()
            ->map(
                static function (object $row): array {
                    $count = (int) $row->qty;

                    return [
                        'id' =>
                            'file_upload-'
                            . $row->event_date,
                        'type' =>
                            'file_upload',
                        'date' =>
                            (string) $row->event_date,
                        'count' =>
                            $count,
                        'label' =>
                            $count
                            . (
                                $count === 1
                                    ? ' uploaded document'
                                    : ' uploaded documents'
                            ),
                        'severity' =>
                            'success',
                        'color' =>
                            '#00BF8F',
                        'icon' =>
                            'pi pi-file-arrow-up',
                    ];
                },  
            );

        $taskEvents = $db
            ->table('person_task')
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'person_task.person_id',
                    $studentId,
                ),
            )
            ->selectRaw(
                'completed AS event_date, COUNT(id) AS qty',
            )
            ->whereNotNull('completed')
            ->where(
                'completed',
                '<>',
                '1970-01-01',
            )
            ->whereBetween(
                'completed',
                [$from, $to],
            )
            ->groupBy('completed')
            ->orderBy('completed')
            ->get()
            ->map(
                static function (object $row): array {
                    $count = (int) $row->qty;

                    return [
                        'id' =>
                            'person_task-'
                            . $row->event_date,
                        'type' =>
                            'person_task',
                        'date' =>
                            (string) $row->event_date,
                        'count' =>
                            $count,
                        'label' =>
                            $count
                            . (
                                $count === 1
                                    ? ' task uploaded'
                                    : ' tasks uploaded'
                            ),
                        'severity' =>
                            'danger',
                        'color' =>
                            '#FF293D',
                        'icon' =>
                            'pi pi-bookmark',
                    ];
                },
            );

        $journalEvents = $db
            ->table('person_journal')
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'person_journal.person_id',
                    $studentId,
                ),
            )
            ->selectRaw(
                'date_journal AS event_date, COUNT(id) AS qty',
            )
            ->whereNotNull('date_journal')
            ->whereBetween(
                'date_journal',
                [$from, $to],
            )
            ->groupBy('date_journal')
            ->orderBy('date_journal')
            ->get()
            ->map(
                static function (object $row): array {
                    $count = (int) $row->qty;

                    return [
                        'id' =>
                            'person_journal-'
                            . $row->event_date,
                        'type' =>
                            'person_journal',
                        'date' =>
                            (string) $row->event_date,
                        'count' =>
                            $count,
                        'label' =>
                            $count
                            . (
                                $count === 1
                                    ? ' daily journal'
                                    : ' daily journals'
                            ),
                        'severity' =>
                            'info',
                        'color' =>
                            '#347EFF',
                        'icon' =>
                            'pi pi-book',
                    ];
                },
            );

        return response()->json([
            'data' => $activityEvents
                ->concat($documentEvents)
                ->concat($taskEvents)
                ->concat($journalEvents)
                ->sortBy([
                    ['date', 'asc'],
                    ['type', 'asc'],
                ])
                ->values(),
        ]);
    }

    public function details(
    Request $request,
    string $type,
    string $date,
): JsonResponse {
    validator(
        [
            'type' => $type,
            'date' => $date,
        ],
        [
            'type' => [
                'required',
                Rule::in(self::TYPES),
            ],
            'date' => [
                'required',
                'date_format:Y-m-d',
            ],
        ],
    )->validate();

    $db = $this->schoolConnection($request);
    $studentId = $this->authenticatedStudentId($request);

    if ($type === 'person_activity') {
        $rows = $db
            ->table('person_activity')
            ->join(
                'person',
                'person_activity.person_id',
                '=',
                'person.id',
            )
            ->join(
                'activity',
                'person_activity.activity_id',
                '=',
                'activity.id',
            )
            ->where(
                'person_activity.start_date',
                $date,
            )
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'person_activity.person_id',
                    $studentId,
                ),
            )
            ->orderBy('person.lname')
            ->orderBy('person.fname')
            ->get([
                'person_activity.id',
                'person_activity.person_id',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.sto_validated',
                'person_activity.revise_remarks',

                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.school_id_no',

                'activity.desc_activity',
            ])
            ->map(
                static function (object $row): array {
                    $remarks = trim(
                        (string) (
                            $row->revise_remarks
                            ?? ''
                        ),
                    );

                    return [
                        'id' => (string) $row->id,
                        'person_id' => (string) $row->person_id,

                        'fname' => trim(
                            (string) (
                                $row->fname
                                ?? ''
                            ),
                        ),
                        'mname' => trim(
                            (string) (
                                $row->mname
                                ?? ''
                            ),
                        ),
                        'lname' => trim(
                            (string) (
                                $row->lname
                                ?? ''
                            ),
                        ),
                        'gender' => trim(
                            (string) (
                                $row->gender
                                ?? ''
                            ),
                        ),
                        'school_id_no' => trim(
                            (string) (
                                $row->school_id_no
                                ?? ''
                            ),
                        ),

                        'student' => self::studentName($row),

                        'desc_activity' => trim(
                            (string) (
                                $row->desc_activity
                                ?? ''
                            ),
                        ),

                        'start_date' => trim(
                            (string) (
                                $row->start_date
                                ?? ''
                            ),
                        ),

                        'end_date' => trim(
                            (string) (
                                $row->end_date
                                ?? ''
                            ),
                        ),

                        'sto_validated' => trim(
                            (string) (
                                $row->sto_validated
                                ?? ''
                            ),
                        ),

                        'revise_remarks' => $remarks,

                        'status' =>
                            $row->sto_validated === 'Y'
                                ? 'Verified'
                                : (
                                    $remarks !== ''
                                        ? 'Revise'
                                        : 'Pending'
                                ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    if ($type === 'file_upload') {
        $rows = $db
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
            ->where(
                'file_upload.date_uploaded',
                $date,
            )
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'file_upload.owner_id',
                    $studentId,
                ),
            )
            ->orderBy('person.lname')
            ->orderBy('person.fname')
            ->orderBy('file_upload.time_uploaded')
            ->get([
                'file_upload.id',
                'file_upload.owner_id',
                'file_upload.file_desc',
                'file_upload.date_uploaded',
                'file_upload.time_uploaded',
                'file_upload.sto_validated',
                'file_upload.revise_remarks',

                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.school_id_no',

                'requirement.desc_requirement',
            ])
            ->map(
                static function (object $row): array {
                    $remarks = trim(
                        (string) (
                            $row->revise_remarks
                            ?? ''
                        ),
                    );

                    return [
                        'id' => (string) $row->id,
                        'person_id' => (string) (
                            $row->owner_id
                            ?? ''
                        ),

                        'fname' => trim(
                            (string) (
                                $row->fname
                                ?? ''
                            ),
                        ),
                        'mname' => trim(
                            (string) (
                                $row->mname
                                ?? ''
                            ),
                        ),
                        'lname' => trim(
                            (string) (
                                $row->lname
                                ?? ''
                            ),
                        ),
                        'gender' => trim(
                            (string) (
                                $row->gender
                                ?? ''
                            ),
                        ),
                        'school_id_no' => trim(
                            (string) (
                                $row->school_id_no
                                ?? ''
                            ),
                        ),

                        'student' => self::studentName($row),

                        'desc_requirement' => trim(
                            (string) (
                                $row->desc_requirement
                                ?? ''
                            ),
                        ),

                        'file_desc' => trim(
                            (string) (
                                $row->file_desc
                                ?? ''
                            ),
                        ),

                        'date_uploaded' => trim(
                            (string) (
                                $row->date_uploaded
                                ?? ''
                            ),
                        ),

                        'time_uploaded' => trim(
                            (string) (
                                $row->time_uploaded
                                ?? ''
                            ),
                        ),

                        'sto_validated' => trim(
                            (string) (
                                $row->sto_validated
                                ?? ''
                            ),
                        ),

                        'revise_remarks' => $remarks,

                        'status' =>
                            $row->sto_validated === 'Y'
                                ? 'Verified'
                                : (
                                    $remarks !== ''
                                        ? 'Revise'
                                        : 'Pending'
                                ),
                    ];
                },
            )
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    if ($type === 'person_journal') {
        $rows = $db
            ->table('person_journal')
            ->leftJoin(
                'person',
                'person_journal.person_id',
                '=',
                'person.id',
            )
            ->where(
                'person_journal.date_journal',
                $date,
            )
            ->when(
                $studentId !== null,
                static fn ($query) => $query->where(
                    'person_journal.person_id',
                    $studentId,
                ),
            )
            ->orderBy('person.lname')
            ->orderBy('person.fname')
            ->orderBy('person_journal.journal_time')
            ->get([
                'person_journal.id',
                'person_journal.person_id',
                'person_journal.date_journal',
                'person_journal.journal_time',
                'person_journal.journal_time_to',
                'person_journal.vessel_name',
                'person_journal.port_depart',
                'person_journal.port_dest',
                'person_journal.activities',
                'person_journal.esig_file',

                'person.fname',
                'person.mname',
                'person.lname',
                'person.gender',
                'person.school_id_no',
                'person.dept',
            ])
            ->map(
                static function (object $row): array {
                    $signature = strtolower(
                        trim(
                            (string) (
                                $row->esig_file
                                ?? ''
                            ),
                        ),
                    );

                    $validated =
                        $signature !== ''
                        && $signature !== 'null';

                    return [
                        'id' => (string) $row->id,
                        'person_id' => (string) $row->person_id,

                        'fname' => trim(
                            (string) (
                                $row->fname
                                ?? ''
                            ),
                        ),
                        'mname' => trim(
                            (string) (
                                $row->mname
                                ?? ''
                            ),
                        ),
                        'lname' => trim(
                            (string) (
                                $row->lname
                                ?? ''
                            ),
                        ),
                        'gender' => trim(
                            (string) (
                                $row->gender
                                ?? ''
                            ),
                        ),
                        'school_id_no' => trim(
                            (string) (
                                $row->school_id_no
                                ?? ''
                            ),
                        ),
                        'department' => trim(
                            (string) (
                                $row->dept
                                ?? ''
                            ),
                        ),

                        'student' => self::studentName($row),

                        'date_journal' => trim(
                            (string) (
                                $row->date_journal
                                ?? ''
                            ),
                        ),

                        'journal_time' => trim(
                            (string) (
                                $row->journal_time
                                ?? ''
                            ),
                        ),

                        'journal_time_to' => trim(
                            (string) (
                                $row->journal_time_to
                                ?? ''
                            ),
                        ),

                        'vessel_name' => trim(
                            (string) (
                                $row->vessel_name
                                ?? ''
                            ),
                        ),

                        'port_depart' => trim(
                            (string) (
                                $row->port_depart
                                ?? ''
                            ),
                        ),

                        'port_dest' => trim(
                            (string) (
                                $row->port_dest
                                ?? ''
                            ),
                        ),

                        'activities' => trim(
                            (string) (
                                $row->activities
                                ?? ''
                            ),
                        ),

                        'validated' => $validated,

                        'status' =>
                            $validated
                                ? 'Signed'
                                : 'Pending',
                    ];
                },
            )
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    $rows = $db
        ->table('person_task')
        ->join(
            'person',
            'person_task.person_id',
            '=',
            'person.id',
        )
        ->join(
            'task',
            'person_task.task_id',
            '=',
            'task.id',
        )
        ->where(
            'person_task.completed',
            $date,
        )
        ->when(
            $studentId !== null,
            static fn ($query) => $query->where(
                'person_task.person_id',
                $studentId,
            ),
        )
        ->orderBy('person.lname')
        ->orderBy('person.fname')
        ->orderBy('task.prio')
        ->get([
            'person_task.id',
            'person_task.person_id',
            'person_task.month_no',
            'person_task.completed',
            'person_task.passed',

            'person.fname',
            'person.mname',
            'person.lname',
            'person.gender',
            'person.school_id_no',
            'person.dept',

            'task.ref_no',
            'task.desc_task',
        ])
        ->map(
            static function (object $row): array {
                return [
                    'id' => (string) $row->id,
                    'person_id' => (string) $row->person_id,

                    'fname' => trim(
                        (string) (
                            $row->fname
                            ?? ''
                        ),
                    ),
                    'mname' => trim(
                        (string) (
                            $row->mname
                            ?? ''
                        ),
                    ),
                    'lname' => trim(
                        (string) (
                            $row->lname
                            ?? ''
                        ),
                    ),
                    'gender' => trim(
                        (string) (
                            $row->gender
                            ?? ''
                        ),
                    ),
                    'school_id_no' => trim(
                        (string) (
                            $row->school_id_no
                            ?? ''
                        ),
                    ),
                    'dept' => trim(
                        (string) (
                            $row->dept
                            ?? ''
                        ),
                    ),

                    'student' => self::studentName($row),

                    'ref_no' => trim(
                        (string) (
                            $row->ref_no
                            ?? ''
                        ),
                    ),

                    'desc_task' => trim(
                        (string) (
                            $row->desc_task
                            ?? ''
                        ),
                    ),

                    'month_no' => trim(
                        (string) (
                            $row->month_no
                            ?? ''
                        ),
                    ),

                    'completed' => trim(
                        (string) (
                            $row->completed
                            ?? ''
                        ),
                    ),

                    'passed' => trim(
                        (string) (
                            $row->passed
                            ?? ''
                        ),
                    ),

                    'status' => 'Completed',
                ];
            },
        )
        ->values();

    return response()->json([
        'data' => $rows,
    ]);
}


    /**
     * Return the authenticated student's person ID when the
     * current account is a student. Administrator requests
     * return null so their existing school-wide calendar is
     * left unchanged.
     */
    private function authenticatedStudentId(
        Request $request,
    ): ?string {
        $account = $request->user();

        if (! $account instanceof Student) {
            return null;
        }

        return (string) $account
            ->getAuthIdentifier();
    }

    /** @return array{0: string, 1: string} */
    private function monthRange(
        string $month,
    ): array {
        [$year, $monthNumber] =
            array_map(
                'intval',
                explode('-', $month),
            );

        $first = sprintf(
            '%04d-%02d-01',
            $year,
            $monthNumber,
        );

        $last = date(
            'Y-m-t',
            strtotime($first),
        );

        return [$first, $last];
    }

    private static function studentName(
        object $row,
    ): string {
        $lastName = trim(
            (string) (
                $row->lname
                ?? ''
            ),
        );

        $firstName = trim(
            (string) (
                $row->fname
                ?? ''
            ),
        );

        $middleName = trim(
            (string) (
                $row->mname
                ?? ''
            ),
        );

        $given = trim(
            implode(
                ' ',
                array_filter([
                    $firstName,
                    $middleName,
                ]),
            ),
        );

        if (
            $lastName !== ''
            &&
            $given !== ''
        ) {
            return $lastName . ', ' . $given;
        }

        return $lastName !== ''
            ? $lastName
            : $given;
    }

    private function schoolConnection(
        Request $request,
    ): ConnectionInterface {
        $code = strtoupper(
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

        $schools = config(
            'schools.schools',
            [],
        );

        $school = is_array($schools)
            ? ($schools[$code] ?? null)
            : null;

        abort_unless(
            $code !== ''
                && is_array($school),
            Response::HTTP_FORBIDDEN,
            'No valid school has been selected.',
        );

        $connection = $school['connection']
            ?? null;

        abort_unless(
            is_string($connection)
                && is_array(
                    config(
                        "database.connections.{$connection}",
                    ),
                ),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is unavailable.',
        );

        return DB::connection($connection);
    }
}
