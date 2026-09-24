<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StudentsController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    /**
     * Return students from the selected school database.
     */
    public function index(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        /*
        |--------------------------------------------------------------------------
        | Base student query
        |--------------------------------------------------------------------------
        |
        | Legacy equivalent:
        |
        | SELECT * FROM person
        |
        | Only the fields needed by the DataTable are returned.
        |
        */

        $query = $db
            ->table('person')
            ->select([
                'person.id',
                'person.code_person',
                'person.school_id_no',
                'person.lname',
                'person.fname',
                'person.mname',
                'person.gender',
                'person.dept',
                'person.batch_no',
                'person.etrb_type',
                'person.active',
                'person.photo_file',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Legacy search filters
        |--------------------------------------------------------------------------
        |
        | System ID No.
        | School ID No.
        | Last Name
        | First Name
        |
        */

        $systemId = trim(
            (string) $request->input(
                'system_id',
                '',
            ),
        );

        if ($systemId !== '') {
            $query->where(
                'person.code_person',
                'like',
                "%{$systemId}%",
            );
        }

        $schoolId = trim(
            (string) $request->input(
                'school_id',
                '',
            ),
        );

        if ($schoolId !== '') {
            $query->where(
                'person.school_id_no',
                'like',
                "%{$schoolId}%",
            );
        }

        $lastName = trim(
            (string) $request->input(
                'last_name',
                '',
            ),
        );

        if ($lastName !== '') {
            $query->where(
                'person.lname',
                'like',
                "%{$lastName}%",
            );
        }

        $firstName = trim(
            (string) $request->input(
                'first_name',
                '',
            ),
        );

        if ($firstName !== '') {
            $query->where(
                'person.fname',
                'like',
                "%{$firstName}%",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Server-side DataTable
        |--------------------------------------------------------------------------
        */

        $result = $this
            ->datatableService
            ->paginate(
                query: $query,
                request: $request,

                searchableColumns: [
                    'person.code_person',
                    'person.school_id_no',
                    'person.lname',
                    'person.fname',
                    'person.mname',
                    'person.gender',
                    'person.dept',
                    'person.batch_no',
                    'person.etrb_type',
                ],

                sortableColumns: [
                    'code_person' =>
                        'person.code_person',

                    'school_id_no' =>
                        'person.school_id_no',

                    'lname' =>
                        'person.lname',

                    'fname' =>
                        'person.fname',

                    'dept' =>
                        'person.dept',

                    'batch_no' =>
                        'person.batch_no',
                ],

                defaultSortColumn:
                    'lname',

                defaultSortDirection:
                    'asc',
            );

        $result = $this
            ->datatableService
            ->addRowNumbers(
                response: $result,
                key: 'index',
            );

        /*
        |--------------------------------------------------------------------------
        | Current page student IDs
        |--------------------------------------------------------------------------
        |
        | Instead of running activity/file queries once for every
        | student like the legacy PHP page, collect the IDs from
        | the current paginated result and load the related records
        | in two additional queries.
        |
        */

        $records = collect(
            $result['data'] ?? [],
        )
            ->map(
                static function (
                    mixed $row,
                ): array {
                    return is_object($row)
                        ? get_object_vars($row)
                        : (array) $row;
                },
            );

        $personIds = $records
            ->pluck('id')
            ->filter(
                static fn (
                    mixed $id,
                ): bool =>
                    is_string($id) &&
                    trim($id) !== '',
            )
            ->values()
            ->all();

        if ($personIds === []) {
            $result['data'] = [];

            return response()->json(
                $result,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student activities
        |--------------------------------------------------------------------------
        |
        | Legacy equivalent:
        |
        | SELECT *
        | FROM person_activity
        | WHERE person_id = ?
        | ORDER BY start_date DESC
        |
        */

        $activities = $db
            ->table('person_activity')
            ->leftJoin(
                'activity',
                'activity.id',
                '=',
                'person_activity.activity_id',
            )
            ->whereIn(
                'person_activity.person_id',
                $personIds,
            )
            ->select([
                'person_activity.id',
                'person_activity.person_id',
                'person_activity.activity_id',
                'person_activity.start_date',
                'person_activity.end_date',
                'person_activity.sto_validated',
                'activity.desc_activity',
            ])
            ->orderBy(
                'person_activity.person_id',
            )
            ->orderByDesc(
                'person_activity.start_date',
            )
            ->get()
            ->groupBy('person_id');

        /*
        |--------------------------------------------------------------------------
        | Student uploaded documents
        |--------------------------------------------------------------------------
        |
        | Legacy equivalent:
        |
        | SELECT *
        | FROM file_upload
        | WHERE owner_id = ?
        | ORDER BY date_uploaded DESC
        |
        */

        $uploadedFiles = $db
            ->table('file_upload')
            ->leftJoin(
                'requirement',
                'requirement.id',
                '=',
                'file_upload.requirement_id',
            )
            ->whereIn(
                'file_upload.owner_id',
                $personIds,
            )
            ->select([
                'file_upload.id',
                'file_upload.owner_id',
                'file_upload.requirement_id',
                'file_upload.file_desc',
                'file_upload.date_uploaded',
                'file_upload.time_uploaded',
                'file_upload.sto_validated',
                'requirement.desc_requirement',
            ])
            ->orderBy(
                'file_upload.owner_id',
            )
            ->orderByDesc(
                'file_upload.date_uploaded',
            )
            ->orderByDesc(
                'file_upload.time_uploaded',
            )
            ->get()
            ->groupBy('owner_id');

        /*
        |--------------------------------------------------------------------------
        | Attach related records to each student
        |--------------------------------------------------------------------------
        */

        $result['data'] = $records
            ->map(
                static function (
                    array $student,
                ) use (
                    $activities,
                    $uploadedFiles,
                ): array {
                    $personId = (string) (
                        $student['id'] ??
                        ''
                    );

                    $student[
                        'activities'
                    ] = collect(
                        $activities->get(
                            $personId,
                            collect(),
                        ),
                    )
                        ->map(
                            static function (
                                object $activity,
                            ): array {
                                return [
                                    'id' =>
                                        $activity->id,

                                    'activity_id' =>
                                        $activity->activity_id,

                                    'description' =>
                                        $activity->desc_activity,

                                    'start_date' =>
                                        $activity->start_date,

                                    'end_date' =>
                                        $activity->end_date,

                                    'verified' =>
                                        $activity->sto_validated,
                                ];
                            },
                        )
                        ->values()
                        ->all();

                    $student[
                        'uploaded_files'
                    ] = collect(
                        $uploadedFiles->get(
                            $personId,
                            collect(),
                        ),
                    )
                        ->map(
                            static function (
                                object $file,
                            ): array {
                                return [
                                    'id' =>
                                        $file->id,

                                    'requirement_id' =>
                                        $file->requirement_id,

                                    'requirement' =>
                                        $file->desc_requirement,

                                    'description' =>
                                        $file->file_desc,

                                    'date_uploaded' =>
                                        $file->date_uploaded,

                                    'time_uploaded' =>
                                        $file->time_uploaded,

                                    'verified' =>
                                        $file->sto_validated,
                                ];
                            },
                        )
                        ->values()
                        ->all();

                    return $student;
                },
            )
            ->values()
            ->all();

        return response()->json(
            $result,
        );
    }


    /**
     * Create a student in the selected school database.
     */
    public function store(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $validated = $request->validate([
            'school_id_no' => [
                'required',
                'string',
                'max:20',
            ],
            'fname' => [
                'required',
                'string',
                'max:30',
            ],
            'mname' => [
                'nullable',
                'string',
                'max:30',
            ],
            'lname' => [
                'required',
                'string',
                'max:30',
            ],
            'gender' => [
                'required',
                'in:MALE,FEMALE',
            ],
            'civ_status' => [
                'nullable',
                'in:SINGLE,MARRIED,SEPARATED,WIDOW/ER',
            ],
            'birth_date' => [
                'nullable',
                'date',
            ],
            'birth_place' => [
                'nullable',
                'string',
            ],
            'batch_no' => [
                'required',
                'string',
                'max:20',
            ],
            'st_address' => [
                'nullable',
                'string',
            ],
            'city_id' => [
                'nullable',
            ],
            'province_id' => [
                'nullable',
            ],
            'mobile' => [
                'nullable',
                'string',
            ],
            'phone' => [
                'nullable',
                'string',
            ],
            'email' => [
                'required',
                'email',
                'max:80',
            ],
            'facebook' => [
                'nullable',
                'string',
            ],
            'st_address_province' => [
                'nullable',
                'string',
            ],
            'phone_province' => [
                'nullable',
                'string',
            ],
            'mother_name' => [
                'nullable',
                'string',
            ],
            'mother_nos' => [
                'nullable',
                'string',
            ],
            'father_name' => [
                'nullable',
                'string',
            ],
            'father_nos' => [
                'nullable',
                'string',
            ],
            'spouse_name' => [
                'nullable',
                'string',
            ],
            'spouse_nos' => [
                'nullable',
                'string',
            ],
            'date_reg' => [
                'nullable',
                'date',
            ],
            'dept' => [
                'required',
                'in:DECK,ENGINE,NON-MARITIME',
            ],
            'etrb_type' => [
                'required',
                'in:GMET,ISF,GMET and ISF,TRMF,SCHOOL',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
            'ins_company' => [
                'nullable',
                'string',
            ],
            'ins_amt' => [
                'nullable',
                'numeric',
            ],
            'ins_hospital' => [
                'nullable',
                'numeric',
            ],
            'ins_disability' => [
                'nullable',
                'numeric',
            ],
            'ins_death' => [
                'nullable',
                'numeric',
            ],
            'stipend' => [
                'nullable',
                'numeric',
            ],
            'active' => [
                'required',
                'in:Y,N',
            ],
        ]);

        $schoolId = trim(
            (string) $validated[
                'school_id_no'
            ],
        );

        if (
            $db
                ->table('person')
                ->where(
                    'school_id_no',
                    $schoolId,
                )
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'The School ID No. is already being used by another student.',
                'errors' => [
                    'school_id_no' => [
                        'The School ID No. is already being used by another student.',
                    ],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $loginId = (string) (
            $request
                ->session()
                ->get('login_id')
            ??
            $request
                ->user()
                ?->getAuthIdentifier()
            ??
            ''
        );

        $created = $db->transaction(
            function () use (
                $db,
                $validated,
                $schoolId,
                $loginId,
            ): array {
                $db
                    ->table('person')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first([
                        'id',
                    ]);

                $prefix =
                    now()->format(
                        'Ym',
                    );

                $latestCode =
                    (string) (
                        $db
                            ->table('person')
                            ->where(
                                'code_person',
                                'like',
                                $prefix . '%',
                            )
                            ->orderByDesc(
                                'code_person',
                            )
                            ->value(
                                'code_person',
                            )
                        ??
                        ''
                    );

                $nextSequence = 1;

                if (
                    strlen(
                        $latestCode,
                    ) >= 10
                ) {
                    $nextSequence =
                        (
                            (int) substr(
                                $latestCode,
                                -4,
                            )
                        ) + 1;
                }

                do {
                    $codePerson =
                        $prefix .
                        str_pad(
                            (string) $nextSequence,
                            4,
                            '0',
                            STR_PAD_LEFT,
                        );

                    $nextSequence++;
                } while (
                    $db
                        ->table('person')
                        ->where(
                            'code_person',
                            $codePerson,
                        )
                        ->exists()
                );

                $loginName =
                    $codePerson;

                $characters =
                    '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ@$*';

                $password = '';
                $maximum =
                    strlen(
                        $characters,
                    ) - 1;

                for (
                    $index = 0;
                    $index < 6;
                    $index++
                ) {
                    $password .=
                        $characters[
                            random_int(
                                0,
                                $maximum,
                            )
                        ];
                }

                $studentId =
                    (string) Str::uuid();

                $db
                    ->table('person')
                    ->insert([
                        'id' =>
                            $studentId,
                        'code_person' =>
                            $codePerson,
                        'school_id_no' =>
                            $schoolId,
                        'fname' =>
                            $validated['fname'],
                        'mname' =>
                            $validated['mname'] ?? '',
                        'lname' =>
                            $validated['lname'],
                        'gender' =>
                            $this->genderDatabaseValue(
                                $validated['gender'],
                            ),
                        'civ_status' =>
                            $validated['civ_status'] ?? '',
                        'birth_date' =>
                            $validated['birth_date'] ??
                            '1970-01-01',
                        'birth_place' =>
                            $validated['birth_place'] ?? '',
                        'batch_no' =>
                            $validated['batch_no'],
                        'st_address' =>
                            $validated['st_address'] ?? '',
                        'city_id' =>
                            $validated['city_id'] ?? '',
                        'province_id' =>
                            $validated['province_id'] ?? '',
                        'mobile' =>
                            $validated['mobile'] ?? '',
                        'phone' =>
                            $validated['phone'] ?? '',
                        'email' =>
                            $validated['email'],
                        'facebook' =>
                            $validated['facebook'] ?? '',
                        'st_address_province' =>
                            $validated['st_address_province'] ?? '',
                        'phone_province' =>
                            $validated['phone_province'] ?? '',
                        'mother_name' =>
                            $validated['mother_name'] ?? '',
                        'mother_nos' =>
                            $validated['mother_nos'] ?? '',
                        'father_name' =>
                            $validated['father_name'] ?? '',
                        'father_nos' =>
                            $validated['father_nos'] ?? '',
                        'spouse_name' =>
                            $validated['spouse_name'] ?? '',
                        'spouse_nos' =>
                            $validated['spouse_nos'] ?? '',
                        'date_reg' =>
                            $validated['date_reg'] ??
                            now()->format('Y-m-d'),
                        'dept' =>
                            $validated['dept'],
                        'etrb_type' =>
                            $validated['etrb_type'],
                        'notes' =>
                            $validated['notes'] ?? '',
                        'ins_company' =>
                            $validated['ins_company'] ?? '',
                        'ins_amt' =>
                            $validated['ins_amt'] ?? 0,
                        'ins_hospital' =>
                            $validated['ins_hospital'] ?? 0,
                        'ins_disability' =>
                            $validated['ins_disability'] ?? 0,
                        'ins_death' =>
                            $validated['ins_death'] ?? 0,
                        'stipend' =>
                            $validated['stipend'] ?? 0,
                        'login_name' =>
                            $loginName,
                        'login_pass' =>
                            $password,
                        'active' =>
                            $validated['active'],
                        'login_id' =>
                            $loginId,
                        'last_update' =>
                            now()->format(
                                'Y-m-d H:i:s',
                            ),
                    ]);

                return [
                    'id' =>
                        $studentId,
                    'code_person' =>
                        $codePerson,
                    'login_name' =>
                        $loginName,
                    'password' =>
                        $password,
                ];
            },
        );

        /*
         * The student account is already committed at this point.
         * Email delivery is intentionally outside the database transaction:
         * an SMTP problem must never remove a successfully created account.
         */
        $emailSent = false;

        try {
            $this->sendCredentialEmail(
                email: (string) $validated['email'],
                studentName: trim(
                    (string) $validated['lname']
                    .', '
                    .(string) $validated['fname']
                    .' '
                    .(string) ($validated['mname'] ?? ''),
                ),
                loginName: (string) $created['login_name'],
                password: (string) $created['password'],
                schoolCode: $this->selectedSchoolCode(
                    $request,
                ),
            );

            $emailSent = true;
        } catch (Throwable $error) {
            report($error);
        }

        $created['email_sent'] =
            $emailSent;

        $created['email'] =
            (string) $validated['email'];

        return response()->json([
            'message' =>
                $emailSent
                    ? 'Student added successfully. Login credentials were sent to the student email.'
                    : 'Student added successfully, but the credential email could not be sent. Use Send Email on the student profile to retry.',
            'data' =>
                $created,
        ], Response::HTTP_CREATED);
    }


    /**
     * Return one student profile from the selected school database.
     */
    public function show(
        Request $request,
        string $studentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $student = $db
            ->table('person')
            ->select([
                'id',
                'code_person',
                'school_id_no',
                'fname',
                'mname',
                'lname',
                'gender',
                'civ_status',
                'birth_date',
                'birth_place',
                'batch_no',
                'st_address',
                'city_id',
                'province_id',
                'mobile',
                'phone',
                'email',
                'facebook',
                'st_address_province',
                'phone_province',
                'mother_name',
                'mother_nos',
                'father_name',
                'father_nos',
                'spouse_name',
                'spouse_nos',
                'date_reg',
                'dept',
                'etrb_type',
                'notes',
                'ins_company',
                'ins_amt',
                'ins_hospital',
                'ins_disability',
                'ins_death',
                'stipend',
                'login_name',
                'active',
                'photo_file',
            ])
            ->where(
                'id',
                $studentId,
            )
            ->first();

        if (! $student) {
            return response()->json([
                'message' =>
                    'Student not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $record = get_object_vars(
            $student,
        );

        $record['photo_url'] =
            $this->buildStudentPhotoUrl(
                $request,
                isset($record['photo_file'])
                    ? (string) $record['photo_file']
                    : null,
            );

        return response()->json([
            'data' => $record,
        ]);
    }

    /**
     * Return lookup options used by the student profile form.
     */
    public function options(
        Request $request,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $cities = $db
            ->table('city')
            ->select([
                'id as value',
                'name_city as label',
            ])
            ->orderBy(
                'name_city',
            )
            ->get();

        $provinces = $db
            ->table('province')
            ->select([
                'id as value',
                'name_province as label',
            ])
            ->orderBy(
                'name_province',
            )
            ->get();

        return response()->json([
            'data' => [
                'cities' => $cities,
                'provinces' => $provinces,
            ],
        ]);
    }

    /**
     * Update the editable student profile fields.
     */
    public function update(
        Request $request,
        string $studentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $student = $db
            ->table('person')
            ->where(
                'id',
                $studentId,
            )
            ->first();

        if (! $student) {
            return response()->json([
                'message' =>
                    'Student not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'school_id_no' => [
                'required',
                'string',
                'max:20',
            ],
            'fname' => [
                'required',
                'string',
                'max:30',
            ],
            'mname' => [
                'nullable',
                'string',
                'max:30',
            ],
            'lname' => [
                'required',
                'string',
                'max:30',
            ],
            'gender' => [
                'required',
                'in:MALE,FEMALE',
            ],
            'civ_status' => [
                'nullable',
                'in:SINGLE,MARRIED,SEPARATED,WIDOW/ER',
            ],
            'birth_date' => [
                'nullable',
                'date',
            ],
            'birth_place' => [
                'nullable',
                'string',
            ],
            'batch_no' => [
                'required',
                'string',
                'max:20',
            ],
            'st_address' => [
                'nullable',
                'string',
            ],
            'city_id' => [
                'nullable',
            ],
            'province_id' => [
                'nullable',
            ],
            'mobile' => [
                'nullable',
                'string',
            ],
            'phone' => [
                'nullable',
                'string',
            ],
            'email' => [
                'required',
                'email',
                'max:80',
            ],
            'facebook' => [
                'nullable',
                'string',
            ],
            'st_address_province' => [
                'nullable',
                'string',
            ],
            'phone_province' => [
                'nullable',
                'string',
            ],
            'mother_name' => [
                'nullable',
                'string',
            ],
            'mother_nos' => [
                'nullable',
                'string',
            ],
            'father_name' => [
                'nullable',
                'string',
            ],
            'father_nos' => [
                'nullable',
                'string',
            ],
            'spouse_name' => [
                'nullable',
                'string',
            ],
            'spouse_nos' => [
                'nullable',
                'string',
            ],
            'date_reg' => [
                'nullable',
                'date',
            ],
            'dept' => [
                'required',
                'in:DECK,ENGINE,NON-MARITIME',
            ],
            'etrb_type' => [
                'required',
                'in:GMET,ISF,GMET and ISF,TRMF,SCHOOL',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
            'ins_company' => [
                'nullable',
                'string',
            ],
            'ins_amt' => [
                'nullable',
                'numeric',
            ],
            'ins_hospital' => [
                'nullable',
                'numeric',
            ],
            'ins_disability' => [
                'nullable',
                'numeric',
            ],
            'ins_death' => [
                'nullable',
                'numeric',
            ],
            'stipend' => [
                'nullable',
                'numeric',
            ],
            'login_name' => [
                'required',
                'string',
            ],
            'new_password' => [
                'nullable',
                'string',
                'min:6',
                'confirmed',
            ],
            'new_password_confirmation' => [
                'nullable',
                'string',
            ],
            'active' => [
                'required',
                'in:Y,N',
            ],
        ]);

        $loginName = trim(
            (string) $validated['login_name'],
        );

        $duplicateLogin = $db
            ->table('person')
            ->where(
                'login_name',
                $loginName,
            )
            ->where(
                'id',
                '!=',
                $studentId,
            )
            ->exists();

        if ($duplicateLogin) {
            return response()->json([
                'message' =>
                    'The login name is already being used by another student.',
                'errors' => [
                    'login_name' => [
                        'The login name is already being used by another student.',
                    ],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $updateData = [
            'school_id_no' =>
                $validated['school_id_no'],
            'fname' =>
                $validated['fname'],
            'mname' =>
                $validated['mname'] ?? '',
            'lname' =>
                $validated['lname'],
            'gender' =>
                $this->genderDatabaseValue(
                    $validated['gender'],
                ),
            'civ_status' =>
                $validated['civ_status'] ?? '',
            'birth_date' =>
                $validated['birth_date'] ??
                '1970-01-01',
            'birth_place' =>
                $validated['birth_place'] ?? '',
            'batch_no' =>
                $validated['batch_no'],
            'st_address' =>
                $validated['st_address'] ?? '',
            'city_id' =>
                $validated['city_id'] ?? '',
            'province_id' =>
                $validated['province_id'] ?? '',
            'mobile' =>
                $validated['mobile'] ?? '',
            'phone' =>
                $validated['phone'] ?? '',
            'email' =>
                $validated['email'] ?? '',
            'facebook' =>
                $validated['facebook'] ?? '',
            'st_address_province' =>
                $validated[
                    'st_address_province'
                ] ?? '',
            'phone_province' =>
                $validated['phone_province'] ?? '',
            'mother_name' =>
                $validated['mother_name'] ?? '',
            'mother_nos' =>
                $validated['mother_nos'] ?? '',
            'father_name' =>
                $validated['father_name'] ?? '',
            'father_nos' =>
                $validated['father_nos'] ?? '',
            'spouse_name' =>
                $validated['spouse_name'] ?? '',
            'spouse_nos' =>
                $validated['spouse_nos'] ?? '',
            'date_reg' =>
                $validated['date_reg'] ??
                now()->format('Y-m-d'),
            'dept' =>
                $validated['dept'],
            'etrb_type' =>
                $validated['etrb_type'],
            'notes' =>
                $validated['notes'] ?? '',
            'ins_company' =>
                $validated['ins_company'] ?? '',
            'ins_amt' =>
                $validated['ins_amt'] ?? 0,
            'ins_hospital' =>
                $validated['ins_hospital'] ?? 0,
            'ins_disability' =>
                $validated['ins_disability'] ?? 0,
            'ins_death' =>
                $validated['ins_death'] ?? 0,
            'stipend' =>
                $validated['stipend'] ?? 0,
            'login_name' =>
                $loginName,
            'active' =>
                $validated['active'],
            'last_update' =>
                now()->format(
                    'Y-m-d H:i:s',
                ),
        ];

        $newPassword = trim(
            (string) (
                $validated['new_password'] ??
                ''
            ),
        );

        /*
         * Keep compatibility with the existing
         * legacy student authentication, which
         * reads person.login_pass directly.
         *
         * The existing password is never returned
         * by the profile API. It is only replaced
         * when an administrator supplies a new one.
         */
        if ($newPassword !== '') {
            $updateData['login_pass'] =
                $newPassword;
        }

        $db
            ->table('person')
            ->where(
                'id',
                $studentId,
            )
            ->update(
                $updateData,
            );

        return response()->json([
            'message' =>
                $newPassword !== ''
                    ? 'Student profile and login credentials updated successfully.'
                    : 'Student profile saved successfully.',
        ]);
    }

    /**
     * Send the student's CURRENT saved login credentials to the email
     * address stored on the selected school database.
     *
     * The password is read only on the server and is never returned to
     * the browser by this endpoint.
     */
    public function sendCredentials(
        Request $request,
        string $studentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $student = $db
            ->table('person')
            ->where(
                'id',
                $studentId,
            )
            ->first([
                'fname',
                'mname',
                'lname',
                'email',
                'login_name',
                'login_pass',
            ]);

        if (! $student) {
            return response()->json([
                'message' =>
                    'Student not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $email = trim(
            (string) (
                $student->email
                ?? ''
            ),
        );

        if (
            $email === ''
            ||
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL,
            ) === false
        ) {
            return response()->json([
                'message' =>
                    'The student does not have a valid email address. Save a valid email address first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $loginName = trim(
            (string) (
                $student->login_name
                ?? ''
            ),
        );

        $password = (string) (
            $student->login_pass
            ?? ''
        );

        if (
            $loginName === ''
            ||
            $password === ''
        ) {
            return response()->json([
                'message' =>
                    'The student account does not have complete login credentials to send.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $studentName = trim(
            (string) (
                $student->lname
                ?? ''
            )
            .', '
            .(string) (
                $student->fname
                ?? ''
            )
            .' '
            .(string) (
                $student->mname
                ?? ''
            ),
        );

        try {
            $this->sendCredentialEmail(
                email: $email,
                studentName: $studentName,
                loginName: $loginName,
                password: $password,
                schoolCode: $this->selectedSchoolCode(
                    $request,
                ),
            );
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' =>
                    'The credential email could not be sent. The student account was not changed. Please try again.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'message' =>
                "Login credentials were sent to {$email}.",
        ]);
    }

    public function uploadPhoto(
        Request $request,
        string $studentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection(
            $request,
        );

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $request->validate([
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $student = $db
            ->table('person')
            ->where(
                'id',
                $studentId,
            )
            ->first();

        if (! $student) {
            return response()->json([
                'message' =>
                    'Student not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $photo = $request->file(
            'photo',
        );

        if (
            ! $photo ||
            ! $photo->isValid()
        ) {
            return response()->json([
                'message' =>
                    'The selected profile photo is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $schoolCode = $this
            ->selectedSchoolCode(
                $request,
            );

        $uploadUrl = trim(
            (string) config(
                "schools.schools.{$schoolCode}.files.photo_upload_url",
                '',
            ),
        );

        if ($uploadUrl === '') {
            return response()->json([
                'message' =>
                    'Student photo upload is not configured for the selected school.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $extension = strtolower(
            $photo->getClientOriginalExtension(),
        );

        $filename =
            now()->format('Ymd_His') .
            '_' .
            Str::lower(
                Str::random(8),
            ) .
            ($extension !== ''
                ? '.' . $extension
                : '');

        $contents = file_get_contents(
            $photo->getRealPath(),
        );

        if ($contents === false) {
            return response()->json([
                'message' =>
                    'Unable to read the selected profile photo.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $uploadResponse = Http::timeout(60)
            ->attach(
                'uploaded_file',
                $contents,
                $filename,
            )
            ->post(
                $uploadUrl,
            );

        if (
            ! $uploadResponse->successful() ||
            strtolower(
                trim(
                    $uploadResponse->body(),
                ),
            ) !== 'success'
        ) {
            return response()->json([
                'message' =>
                    'Unable to upload the student profile photo.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $db
            ->table('person')
            ->where(
                'id',
                $studentId,
            )
            ->update([
                'photo_file' =>
                    $filename,
                'last_update' =>
                    now()->format(
                        'Y-m-d H:i:s',
                    ),
            ]);

        return response()->json([
            'message' =>
                'Student profile photo updated successfully.',
            'data' => [
                'photo_file' =>
                    $filename,
                'photo_url' =>
                    $this->buildStudentPhotoUrl(
                        $request,
                        $filename,
                    ),
            ],
        ]);
    }

    private function genderDatabaseValue(
        string $gender,
    ): string {
        return match (
            strtoupper(
                trim(
                    $gender,
                ),
            )
        ) {
            'MALE', 'M' => 'M',
            'FEMALE', 'F' => 'F',
            default => '',
        };
    }

    /**
     * Shared account-email view used by Student Profile and Batch Upload.
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

    private function buildStudentPhotoUrl(
        Request $request,
        ?string $photoFile,
    ): ?string {
        $schoolCode = $this
            ->selectedSchoolCode(
                $request,
            );

        if ($schoolCode === '') {
            return null;
        }

        $photosUrl = rtrim(
            trim(
                (string) config(
                    "schools.schools.{$schoolCode}.files.photos_url",
                    '',
                ),
            ),
            '/',
        );

        if ($photosUrl === '') {
            return null;
        }

        $filename = basename(
            str_replace(
                '\\',
                '/',
                trim(
                    (string) $photoFile,
                ),
            ),
        );

        /*
         * The legacy application uses profile.jpg as a generic
         * silhouette. It is not an actual student profile photo.
         *
         * Returning null here allows the Vue profile page to use
         * the MALE/FEMALE cadet avatar, then initials as the final
         * fallback.
         */
        if (
            $filename === '' ||
            strtolower($filename) === 'profile.jpg'
        ) {
            return null;
        }

        return $photosUrl .
            '/' .
            rawurlencode(
                $filename,
            );
    }

    /**
     * Resolve the database selected during login.
     */
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
            $schools[$schoolCode] ??
            null;

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
                    $school['code'] ??
                    $schoolCode
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
            return response()->json([
                'message' =>
                    'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection =
            $school['connection'] ??
            null;

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