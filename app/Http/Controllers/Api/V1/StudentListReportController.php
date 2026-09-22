<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentListReportController extends Controller
{
    private const BASE_COLUMNS = [
        'date_reg' => [
            'database' => 'person.date_reg',
            'header' => 'Date Registered',
        ],
        'school_id_no' => [
            'database' => 'person.school_id_no',
            'header' => 'School ID No.',
        ],
        'lname' => [
            'database' => 'person.lname',
            'header' => 'Last Name',
        ],
        'fname' => [
            'database' => 'person.fname',
            'header' => 'First Name',
        ],
        'mname' => [
            'database' => 'person.mname',
            'header' => 'Middle Name',
        ],
        'dept' => [
            'database' => 'person.dept',
            'header' => 'Department',
        ],
        'batch_no' => [
            'database' => 'person.batch_no',
            'header' => 'CCI Year',
        ],
    ];

    private const OPTIONAL_COLUMNS = [
        'etrb_type' => ['database' => 'person.etrb_type', 'header' => 'e-TRB Type'],
        'mobile' => ['database' => 'person.mobile', 'header' => 'Mobile No.'],
        'phone' => ['database' => 'person.phone', 'header' => 'Phone No.'],
        'email' => ['database' => 'person.email', 'header' => 'Email'],
        'st_address' => ['database' => 'person.st_address', 'header' => 'Address'],
        'birth_date' => ['database' => 'person.birth_date', 'header' => 'Date of Birth'],
        'gender' => ['database' => 'person.gender', 'header' => 'Gender'],
        'civ_status' => ['database' => 'person.civ_status', 'header' => 'Civil Status'],
        'mother_name' => ['database' => 'person.mother_name', 'header' => "Mother's Name"],
        'mother_nos' => ['database' => 'person.mother_nos', 'header' => "Mother's Contact No."],
        'father_name' => ['database' => 'person.father_name', 'header' => "Father's Name"],
        'father_nos' => ['database' => 'person.father_nos', 'header' => "Father's Contact No."],
        'spouse_name' => ['database' => 'person.spouse_name', 'header' => "Spouse's Name"],
        'spouse_nos' => ['database' => 'person.spouse_nos', 'header' => "Spouse's Contact No."],
    ];

    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $cciYears = $db
            ->table('person')
            ->whereNotNull('batch_no')
            ->where('batch_no', '!=', '')
            ->distinct()
            ->orderBy('batch_no')
            ->pluck('batch_no')
            ->map(static fn (mixed $value): array => [
                'label' => (string) $value,
                'value' => (string) $value,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'cciYears' => $cciYears,
                'departments' => [
                    ['label' => 'DECK', 'value' => 'DECK'],
                    ['label' => 'ENGINE', 'value' => 'ENGINE'],
                    ['label' => 'NON-MARITIME', 'value' => 'NON-MARITIME'],
                ],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            return $db;
        }

        $input = $this->validatedFilters($request);
        $selectedOptionalColumns = $this->selectedOptionalColumns($input);
        $query = $this->buildQuery($db, $input, $selectedOptionalColumns);

        $searchableColumns = array_values(array_map(
            static fn (array $definition): string => $definition['database'],
            [
                ...self::BASE_COLUMNS,
                ...array_intersect_key(
                    self::OPTIONAL_COLUMNS,
                    array_flip($selectedOptionalColumns),
                ),
            ],
        ));

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: $searchableColumns,
            sortableColumns: $this->sortableColumns($selectedOptionalColumns),
            defaultSortColumn: 'date_reg',
            defaultSortDirection: 'asc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        return response()->json([
            ...$result,
            'context' => [
                'fromDate' => $input['from_date'],
                'toDate' => $input['to_date'],
                'cciYear' => $input['batch_no'] ?? null,
                'department' => $input['dept'] ?? null,
                'columns' => $selectedOptionalColumns,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $db = $this->resolveSchoolConnection($request);

        if ($db instanceof JsonResponse) {
            abort(
                $db->getStatusCode(),
                (string) ($db->getData(true)['message'] ?? 'Unable to resolve the selected school database.'),
            );
        }

        $input = $this->validatedFilters($request);
        $selectedOptionalColumns = $this->selectedOptionalColumns($input);
        $query = $this->buildQuery($db, $input, $selectedOptionalColumns);

        $this->applyExportSearch(
            query: $query,
            search: trim((string) ($input['search'] ?? '')),
            selectedOptionalColumns: $selectedOptionalColumns,
        );

        $sortField = (string) ($input['sort_field'] ?? 'date_reg');
        $sortDirection = strtolower((string) ($input['sort_direction'] ?? 'asc'));
        $sortableColumns = $this->sortableColumns($selectedOptionalColumns);
        $sortColumn = $sortableColumns[$sortField] ?? $sortableColumns['date_reg'];

        $query->orderBy(
            $sortColumn,
            $sortDirection === 'desc' ? 'desc' : 'asc',
        );

        $schoolCode = $this->selectedSchoolCode($request);
        $filename = 'student-list-report-' . now('Asia/Manila')->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(
            function () use ($query, $input, $selectedOptionalColumns, $schoolCode): void {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Student List Report');

                $exportColumns = [
                    'index' => ['header' => 'No.'],
                    ...self::BASE_COLUMNS,
                    ...array_intersect_key(
                        self::OPTIONAL_COLUMNS,
                        array_flip($selectedOptionalColumns),
                    ),
                ];

                $lastColumn = $this->excelColumnName(count($exportColumns));

                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', 'Student List Report');

                $sheet->mergeCells("A2:{$lastColumn}2");

                $filterDescription = 'School: ' . ($schoolCode !== '' ? $schoolCode : '—')
                    . ' | Registration Date: ' . $input['from_date'] . ' to ' . $input['to_date'];

                if (! empty($input['batch_no'])) {
                    $filterDescription .= ' | CCI Year: ' . $input['batch_no'];
                }

                if (! empty($input['dept'])) {
                    $filterDescription .= ' | Department: ' . $input['dept'];
                }

                $sheet->setCellValue('A2', $filterDescription);

                $sheet->mergeCells("A3:{$lastColumn}3");
                $sheet->setCellValue(
                    'A3',
                    'Generated: ' . now('Asia/Manila')->format('M d, Y h:i A'),
                );

                $headerRow = 5;
                $dataRow = 6;
                $columnNumber = 1;

                foreach ($exportColumns as $definition) {
                    $sheet->setCellValue(
                        [$columnNumber, $headerRow],
                        $definition['header'],
                    );
                    $columnNumber++;
                }

                $headerRange = "A{$headerRow}:{$lastColumn}{$headerRow}";

                $sheet->getStyle($headerRange)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['argb' => 'FFFFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF123A63'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FFD1D5DB'],
                        ],
                    ],
                ]);

                $sheet->getStyle('A1')->getFont()
                    ->setBold(true)
                    ->setSize(16)
                    ->getColor()
                    ->setARGB('FF123A63');

                $sheet->getStyle('A1:A3')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $rowNumber = 0;

                $query->chunk(
                    500,
                    function (Collection $rows) use (
                        $sheet,
                        $exportColumns,
                        &$dataRow,
                        &$rowNumber,
                    ): void {
                        foreach ($rows as $row) {
                            $rowNumber++;
                            $columnNumber = 1;

                            foreach (array_keys($exportColumns) as $field) {
                                $value = $field === 'index'
                                    ? $rowNumber
                                    : $this->exportValue($field, $row->{$field} ?? null);

                                if ($field === 'index') {
                                    $sheet->setCellValue(
                                        [$columnNumber, $dataRow],
                                        $value,
                                    );
                                } else {
                                    $sheet->setCellValueExplicit(
                                        [$columnNumber, $dataRow],
                                        (string) $value,
                                        DataType::TYPE_STRING,
                                    );
                                }

                                $columnNumber++;
                            }

                            $dataRow++;
                        }
                    },
                );

                if ($rowNumber === 0) {
                    $sheet->mergeCells("A{$dataRow}:{$lastColumn}{$dataRow}");
                    $sheet->setCellValue("A{$dataRow}", 'No records to display.');
                }

                $sheet->freezePane('A6');
                $sheet->setAutoFilter($headerRange);

                for ($column = 1; $column <= count($exportColumns); $column++) {
                    $sheet->getColumnDimension(
                        $this->excelColumnName($column),
                    )->setAutoSize(true);
                }

                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');

                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'batch_no' => ['nullable', 'string', 'max:20'],
            'dept' => ['nullable', Rule::in(['DECK', 'ENGINE', 'NON-MARITIME'])],
            'columns' => ['nullable', 'array', 'max:' . count(self::OPTIONAL_COLUMNS)],
            'columns.*' => [
                'string',
                'distinct',
                Rule::in(array_keys(self::OPTIONAL_COLUMNS)),
            ],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50, 100])],
            'sort_field' => [
                'nullable',
                Rule::in([
                    ...array_keys(self::BASE_COLUMNS),
                    ...array_keys(self::OPTIONAL_COLUMNS),
                ]),
            ],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    private function selectedOptionalColumns(array $input): array
    {
        $requested = $input['columns'] ?? [];

        if (! is_array($requested)) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::OPTIONAL_COLUMNS),
            static fn (string $column): bool => in_array($column, $requested, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $selectedOptionalColumns
     */
    private function buildQuery(
        ConnectionInterface $db,
        array $input,
        array $selectedOptionalColumns,
    ): Builder {
        $select = [
            'person.id',
            'person.gender as avatar_gender',
        ];

        foreach (self::BASE_COLUMNS as $field => $definition) {
            $select[] = $definition['database'] . ' as ' . $field;
        }

        foreach ($selectedOptionalColumns as $field) {
            $select[] = self::OPTIONAL_COLUMNS[$field]['database'] . ' as ' . $field;
        }

        $toExclusive = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $input['to_date'],
        )->addDay()->format('Y-m-d');

        $query = $db
            ->table('person')
            ->select($select)
            ->where('person.active', 'Y')
            ->where('person.date_reg', '>=', $input['from_date'])
            ->where('person.date_reg', '<', $toExclusive);

        if (! empty($input['batch_no'])) {
            $query->where('person.batch_no', $input['batch_no']);
        }

        if (! empty($input['dept'])) {
            $query->where('person.dept', $input['dept']);
        }

        return $query;
    }

    /**
     * @param  array<int, string>  $selectedOptionalColumns
     * @return array<string, string>
     */
    private function sortableColumns(array $selectedOptionalColumns): array
    {
        $sortable = [];

        foreach (self::BASE_COLUMNS as $field => $definition) {
            $sortable[$field] = $definition['database'];
        }

        foreach ($selectedOptionalColumns as $field) {
            $sortable[$field] = self::OPTIONAL_COLUMNS[$field]['database'];
        }

        return $sortable;
    }

    /** @param  array<int, string>  $selectedOptionalColumns */
    private function applyExportSearch(
        Builder $query,
        string $search,
        array $selectedOptionalColumns,
    ): void {
        if ($search === '') {
            return;
        }

        $searchableColumns = [
            ...array_values(array_map(
                static fn (array $definition): string => $definition['database'],
                self::BASE_COLUMNS,
            )),
            ...array_values(array_map(
                static fn (string $field): string => self::OPTIONAL_COLUMNS[$field]['database'],
                $selectedOptionalColumns,
            )),
        ];

        $query->where(function (Builder $searchQuery) use ($searchableColumns, $search): void {
            foreach ($searchableColumns as $index => $column) {
                if ($index === 0) {
                    $searchQuery->where($column, 'like', "%{$search}%");
                    continue;
                }

                $searchQuery->orWhere($column, 'like', "%{$search}%");
            }
        });
    }

    private function exportValue(string $field, mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return '';
        }

        if ($field === 'date_reg' || $field === 'birth_date') {
            if ($text === '1970-01-01' || $text === '1970-01-01 00:00:00') {
                return '';
            }

            try {
                return CarbonImmutable::parse($text)->format('M d, Y');
            } catch (\Throwable) {
                return $text;
            }
        }

        if ($field === 'gender') {
            return match (strtoupper($text)) {
                'F', 'FEMALE' => 'Female',
                'M', 'MALE' => 'Male',
                default => $text,
            };
        }

        return $text;
    }

    private function excelColumnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function selectedSchoolCode(Request $request): string
    {
        return strtoupper(trim((string) $request->session()->get('school_code', '')));
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = $this->selectedSchoolCode($request);

        if ($schoolCode === '') {
            return response()->json([
                'message' => 'No school database has been selected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $schools = config('schools.schools', []);

        if (! is_array($schools)) {
            return response()->json([
                'message' => 'School configuration is unavailable.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $school = $schools[$schoolCode] ?? null;

        if (! is_array($school)) {
            return response()->json([
                'message' => 'The selected school is not configured.',
                'schoolCode' => $schoolCode,
            ], Response::HTTP_FORBIDDEN);
        }

        $configuredCode = strtoupper(trim((string) ($school['code'] ?? $schoolCode)));

        if ($configuredCode === '' || ! hash_equals($configuredCode, $schoolCode)) {
            return response()->json([
                'message' => 'The selected school code is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = $school['connection'] ?? null;

        if (! is_string($connection) || $connection === '') {
            return response()->json([
                'message' => 'The school database connection is missing.',
                'schoolCode' => $schoolCode,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $connectionConfig = config("database.connections.{$connection}");

        if (! is_array($connectionConfig)) {
            return response()->json([
                'message' => 'The school database connection is not configured.',
                'schoolCode' => $schoolCode,
                'connection' => $connection,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        return DB::connection($connection);
    }
}
