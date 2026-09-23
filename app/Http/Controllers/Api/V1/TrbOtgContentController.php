<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class TrbOtgContentController extends Controller
{
    private const HEADERS = [
        'TYPE',
        'REF. NO',
        'DESCRIPTION',
        'PRIO',
    ];

    private const MAX_UPLOAD_KILOBYTES = 10000;

    public function options(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);

        $types = $db->table('trb_type')
            ->orderBy('prio')
            ->orderBy('desc_trb_type')
            ->get(['id', 'desc_trb_type', 'prio', 'trb', 'dept'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'desc_trb_type' => trim((string) $row->desc_trb_type),
                'prio' => $row->prio !== null ? (int) $row->prio : null,
                'trb' => trim((string) ($row->trb ?? '')),
                'dept' => trim((string) ($row->dept ?? '')),
            ])
            ->values();

        return response()->json(['data' => $types]);
    }

    public function template(Request $request): BinaryFileResponse|StreamedResponse
    {
        $this->schoolConnection($request);

        $legacyTemplate = public_path('templates/trb_template.xlsx');

        if (is_file($legacyTemplate)) {
            return response()->download(
                $legacyTemplate,
                'trb_template.xlsx',
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
            );
        }

        return response()->streamDownload(function (): void {
            $book = new Spreadsheet();

            try {
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('TRB Content');
                $sheet->fromArray([self::HEADERS], null, 'A1');

                foreach (range('A', 'D') as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                $sheet->freezePane('A2');

                $instructions = $book->createSheet();
                $instructions->setTitle('Instructions');
                $instructions->fromArray([
                    ['TYPE', 'MEANING'],
                    ['1', 'Function'],
                    ['2', 'Competence'],
                    ['3', 'Topic / Sub-Competence'],
                    ['4', 'Task'],
                ], null, 'A1');
                $instructions->getColumnDimension('A')->setAutoSize(true);
                $instructions->getColumnDimension('B')->setAutoSize(true);

                $book->setActiveSheetIndex(0);
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'trb_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function show(Request $request, string $trbTypeId): JsonResponse
    {
        $db = $this->schoolConnection($request);

        $trbType = $db->table('trb_type')
            ->where('id', $trbTypeId)
            ->first(['id', 'desc_trb_type', 'prio', 'trb', 'dept']);

        if (! $trbType) {
            return response()->json([
                'message' => 'TRB Type was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $functions = $db->table('trb_function')
            ->where('trb_type_id', $trbTypeId)
            ->orderBy('prio')
            ->orderBy('code_trb_function')
            ->get([
                'id',
                'desc_trb_function',
                'dept',
                'prio',
                'vessel_type_id',
                'dp',
                'ice_class',
                'trb_type_id',
                'code_trb_function',
            ]);

        $functionIds = $functions->pluck('id')->map(
            static fn (mixed $value): string => (string) $value,
        )->all();

        $competences = $functionIds === []
            ? collect()
            : $db->table('trb_competence')
                ->whereIn('trb_function_id', $functionIds)
                ->orderBy('prio')
                ->orderBy('ref_no')
                ->get([
                    'id',
                    'ref_no',
                    'desc_competence',
                    'trb_function_id',
                    'prio',
                ]);

        $competenceIds = $competences->pluck('id')->map(
            static fn (mixed $value): string => (string) $value,
        )->all();

        $topics = $competenceIds === []
            ? collect()
            : $db->table('trb_sub_competence')
                ->whereIn('trb_competence_id', $competenceIds)
                ->orderBy('prio')
                ->orderBy('ref_no')
                ->get([
                    'id',
                    'ref_no',
                    'desc_sub_competence',
                    'trb_competence_id',
                    'criteria_id',
                    'prio',
                    'trb_function_id',
                ]);

        $topicIds = $topics->pluck('id')->map(
            static fn (mixed $value): string => (string) $value,
        )->all();

        $tasks = $topicIds === []
            ? collect()
            : $db->table('task')
                ->whereIn('trb_sub_competence_id', $topicIds)
                ->orderBy('prio')
                ->orderBy('ref_no')
                ->get([
                    'id',
                    'ref_no',
                    'desc_task',
                    'trb_competence_id',
                    'prio',
                    'trb_sub_competence_id',
                    'phase_no',
                ]);

        $tasksByTopic = $tasks->groupBy('trb_sub_competence_id');

        $topicsByCompetence = $topics
            ->map(function (object $topic) use ($tasksByTopic): array {
                $topicTasks = $tasksByTopic
                    ->get($topic->id, collect())
                    ->map(function (object $task): array {
                        return [
                            'id' => (string) $task->id,
                            'ref_no' => trim((string) $task->ref_no),
                            'description' => $this->decode((string) ($task->desc_task ?? '')),
                            'prio' => $task->prio !== null ? (int) $task->prio : null,
                            'phase_no' => trim((string) ($task->phase_no ?? '')),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'id' => (string) $topic->id,
                    'ref_no' => trim((string) $topic->ref_no),
                    'description' => $this->decode((string) ($topic->desc_sub_competence ?? '')),
                    'prio' => $topic->prio !== null ? (int) $topic->prio : null,
                    'tasks' => $topicTasks,
                    'trb_competence_id' => (string) $topic->trb_competence_id,
                ];
            })
            ->groupBy('trb_competence_id');

        $competencesByFunction = $competences
            ->map(function (object $competence) use ($topicsByCompetence): array {
                $competenceTopics = $topicsByCompetence
                    ->get($competence->id, collect())
                    ->map(static function (array $topic): array {
                        unset($topic['trb_competence_id']);

                        return $topic;
                    })
                    ->values()
                    ->all();

                return [
                    'id' => (string) $competence->id,
                    'ref_no' => trim((string) $competence->ref_no),
                    'description' => $this->decode((string) ($competence->desc_competence ?? '')),
                    'prio' => $competence->prio !== null ? (int) $competence->prio : null,
                    'topics' => $competenceTopics,
                    'trb_function_id' => (string) $competence->trb_function_id,
                ];
            })
            ->groupBy('trb_function_id');

        $hierarchy = $functions
            ->map(function (object $function) use ($competencesByFunction): array {
                $functionCompetences = $competencesByFunction
                    ->get($function->id, collect())
                    ->map(static function (array $competence): array {
                        unset($competence['trb_function_id']);

                        return $competence;
                    })
                    ->values()
                    ->all();

                return [
                    'id' => (string) $function->id,
                    'code' => trim((string) ($function->code_trb_function ?? '')),
                    'description' => $this->decode((string) ($function->desc_trb_function ?? '')),
                    'prio' => $function->prio !== null ? (int) $function->prio : null,
                    'competences' => $functionCompetences,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'trb_type' => [
                    'id' => (string) $trbType->id,
                    'desc_trb_type' => trim((string) $trbType->desc_trb_type),
                    'prio' => $trbType->prio !== null ? (int) $trbType->prio : null,
                    'trb' => trim((string) ($trbType->trb ?? '')),
                    'dept' => trim((string) ($trbType->dept ?? '')),
                ],
                'functions' => $hierarchy,
                'counts' => [
                    'functions' => $functions->count(),
                    'competences' => $competences->count(),
                    'topics' => $topics->count(),
                    'tasks' => $tasks->count(),
                ],
            ],
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trb_type_id' => ['required', 'string'],
            'file' => [
                'required',
                'file',
                'mimes:xls,xlsx',
                'extensions:xls,xlsx',
                'max:'.self::MAX_UPLOAD_KILOBYTES,
            ],
        ]);

        $db = $this->schoolConnection($request);
        $trbTypeId = (string) $validated['trb_type_id'];

        if (! $db->table('trb_type')->where('id', $trbTypeId)->exists()) {
            throw ValidationException::withMessages([
                'trb_type_id' => 'Select a valid TRB Type.',
            ]);
        }

        $this->ensureTransactionalTables($db);

        $book = null;

        try {
            $file = $request->file('file');
            $path = $file->getRealPath();
            $extension = strtolower($file->getClientOriginalExtension());

            if ($extension === 'xlsx') {
                $this->validateXlsxArchive($path);
                $reader = IOFactory::createReader('Xlsx');
            } else {
                $reader = IOFactory::createReader('Xls');
            }

            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            $estimatedRows = 0;

            foreach ($reader->listWorksheetInfo($path) as $info) {
                $estimatedRows += max(0, (int) $info['totalRows'] - 1);

                if ($estimatedRows > 10000) {
                    throw new \RuntimeException('Workbook exceeds the 10,000-row safety limit.');
                }
            }

            $book = $reader->load($path);
            [$rows, $skipped, $issues] = $this->readRows($book);
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            throw ValidationException::withMessages([
                'file' => 'Unable to read this TRB workbook. Use the TRB template and upload an XLS or XLSX file.',
            ]);
        } finally {
            $book?->disconnectWorksheets();
        }

        if ($issues !== []) {
            return response()->json([
                'message' => 'Import cancelled. Fix the listed rows and try again; no TRB records were changed.',
                'issues' => array_slice($issues, 0, 100),
                'issue_count' => count($issues),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'The workbook contains no TRB content rows.',
            ]);
        }

        try {
            $counts = $db->transaction(function () use ($db, $rows, $trbTypeId): array {
                $db->table('trb_type')
                    ->where('id', $trbTypeId)
                    ->lockForUpdate()
                    ->first(['id']);

                $functionId = null;
                $competenceId = null;
                $topicId = null;

                $counts = [
                    'functions_added' => 0,
                    'functions_updated' => 0,
                    'competences_added' => 0,
                    'competences_updated' => 0,
                    'topics_added' => 0,
                    'topics_updated' => 0,
                    'tasks_added' => 0,
                    'tasks_updated' => 0,
                ];

                foreach ($rows as $row) {
                    $type = $row['type'];
                    $refNo = $row['ref_no'];
                    $description = urlencode($row['description']);
                    $priority = $row['prio'];

                    if ($type === 1) {
                        $existing = $db->table('trb_function')
                            ->where('code_trb_function', $refNo)
                            ->where('trb_type_id', $trbTypeId)
                            ->lockForUpdate()
                            ->first(['id']);

                        if ($existing) {
                            $functionId = (string) $existing->id;

                            $db->table('trb_function')
                                ->where('id', $functionId)
                                ->update([
                                    'desc_trb_function' => $description,
                                    'dept' => '',
                                    'prio' => $priority,
                                    'code_trb_function' => $refNo,
                                ]);

                            $counts['functions_updated']++;
                        } else {
                            $functionId = (string) Str::uuid();

                            $db->table('trb_function')->insert([
                                'id' => $functionId,
                                'desc_trb_function' => $description,
                                'dept' => '',
                                'prio' => $priority,
                                'trb_type_id' => $trbTypeId,
                                'code_trb_function' => $refNo,
                            ]);

                            $counts['functions_added']++;
                        }

                        $competenceId = null;
                        $topicId = null;

                        continue;
                    }

                    if ($type === 2) {
                        $existing = $db->table('trb_competence')
                            ->where('ref_no', $refNo)
                            ->where('trb_function_id', $functionId)
                            ->lockForUpdate()
                            ->first(['id']);

                        if ($existing) {
                            $competenceId = (string) $existing->id;

                            $db->table('trb_competence')
                                ->where('id', $competenceId)
                                ->update([
                                    'ref_no' => $refNo,
                                    'desc_competence' => $description,
                                    'prio' => $priority,
                                ]);

                            $counts['competences_updated']++;
                        } else {
                            $competenceId = (string) Str::uuid();

                            $db->table('trb_competence')->insert([
                                'id' => $competenceId,
                                'ref_no' => $refNo,
                                'desc_competence' => $description,
                                'prio' => $priority,
                                'trb_function_id' => $functionId,
                            ]);

                            $counts['competences_added']++;
                        }

                        $topicId = null;

                        continue;
                    }

                    if ($type === 3) {
                        $existing = $db->table('trb_sub_competence')
                            ->where('ref_no', $refNo)
                            ->where('trb_competence_id', $competenceId)
                            ->where('trb_function_id', $functionId)
                            ->lockForUpdate()
                            ->first(['id']);

                        if ($existing) {
                            $topicId = (string) $existing->id;

                            $db->table('trb_sub_competence')
                                ->where('id', $topicId)
                                ->update([
                                    'ref_no' => $refNo,
                                    'desc_sub_competence' => $description,
                                    'prio' => $priority,
                                    'criteria_id' => '',
                                    'trb_function_id' => $functionId,
                                ]);

                            $counts['topics_updated']++;
                        } else {
                            $topicId = (string) Str::uuid();

                            $db->table('trb_sub_competence')->insert([
                                'id' => $topicId,
                                'ref_no' => $refNo,
                                'desc_sub_competence' => $description,
                                'prio' => $priority,
                                'criteria_id' => '',
                                'trb_competence_id' => $competenceId,
                                'trb_function_id' => $functionId,
                            ]);

                            $counts['topics_added']++;
                        }

                        continue;
                    }

                    $existing = $db->table('task')
                        ->where('ref_no', $refNo)
                        ->where('trb_competence_id', $competenceId)
                        ->where('trb_sub_competence_id', $topicId)
                        ->lockForUpdate()
                        ->first(['id']);

                    if ($existing) {
                        $taskId = (string) $existing->id;

                        $db->table('task')
                            ->where('id', $taskId)
                            ->update([
                                'ref_no' => $refNo,
                                'desc_task' => $description,
                                'prio' => $priority,
                                'trb_competence_id' => $competenceId,
                                'trb_sub_competence_id' => $topicId,
                            ]);

                        $counts['tasks_updated']++;
                    } else {
                        $db->table('task')->insert([
                            'id' => (string) Str::uuid(),
                            'ref_no' => $refNo,
                            'desc_task' => $description,
                            'prio' => $priority,
                            'trb_competence_id' => $competenceId,
                            'trb_sub_competence_id' => $topicId,
                        ]);

                        $counts['tasks_added']++;
                    }
                }

                return $counts;
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'TRB import failed. No changes were committed.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $added = $counts['functions_added']
            + $counts['competences_added']
            + $counts['topics_added']
            + $counts['tasks_added'];

        $updated = $counts['functions_updated']
            + $counts['competences_updated']
            + $counts['topics_updated']
            + $counts['tasks_updated'];

        return response()->json([
            'message' => 'TRB content uploaded successfully.',
            'processed' => count($rows),
            'skipped' => $skipped,
            'added' => $added,
            'updated' => $updated,
            'counts' => $counts,
        ]);
    }

    public function destroy(Request $request, string $trbTypeId): JsonResponse
    {
        $db = $this->schoolConnection($request);

        if (! $db->table('trb_type')->where('id', $trbTypeId)->exists()) {
            return response()->json([
                'message' => 'TRB Type was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->ensureTransactionalTables($db);

        try {
            $deleted = $db->transaction(function () use ($db, $trbTypeId): array {
                $db->table('trb_type')
                    ->where('id', $trbTypeId)
                    ->lockForUpdate()
                    ->first(['id']);

                $functionIds = $db->table('trb_function')
                    ->where('trb_type_id', $trbTypeId)
                    ->pluck('id')
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->all();

                if ($functionIds === []) {
                    return [
                        'functions' => 0,
                        'competences' => 0,
                        'topics' => 0,
                        'tasks' => 0,
                    ];
                }

                $competenceIds = $db->table('trb_competence')
                    ->whereIn('trb_function_id', $functionIds)
                    ->pluck('id')
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->all();

                $topicIds = $competenceIds === []
                    ? []
                    : $db->table('trb_sub_competence')
                        ->whereIn('trb_competence_id', $competenceIds)
                        ->pluck('id')
                        ->map(static fn (mixed $value): string => (string) $value)
                        ->all();

                $taskCount = 0;

                if ($competenceIds !== []) {
                    $taskQuery = $db->table('task')
                        ->whereIn('trb_competence_id', $competenceIds);

                    if ($topicIds !== []) {
                        $taskQuery->orWhereIn('trb_sub_competence_id', $topicIds);
                    }

                    $taskCount = $taskQuery->delete();
                }

                $topicCount = $competenceIds === []
                    ? 0
                    : $db->table('trb_sub_competence')
                        ->whereIn('trb_competence_id', $competenceIds)
                        ->delete();

                $competenceCount = $db->table('trb_competence')
                    ->whereIn('trb_function_id', $functionIds)
                    ->delete();

                $functionCount = $db->table('trb_function')
                    ->where('trb_type_id', $trbTypeId)
                    ->delete();

                return [
                    'functions' => $functionCount,
                    'competences' => $competenceCount,
                    'topics' => $topicCount,
                    'tasks' => $taskCount,
                ];
            });
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'TRB content could not be deleted. No changes were committed.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'message' => 'TRB content deleted successfully.',
            'deleted' => $deleted,
        ]);
    }

    private function readRows(Spreadsheet $book): array
    {
        $rows = [];
        $issues = [];
        $skipped = 0;
        $scanned = 0;

        $hasFunction = false;
        $hasCompetence = false;
        $hasTopic = false;

        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheetName = $sheet->getTitle();

            if (strcasecmp($sheetName, 'Instructions') === 0) {
                continue;
            }

            $headers = [];

            foreach (range('A', 'D') as $column) {
                $headers[] = $this->normalizeHeader(
                    (string) $sheet->getCell($column.'1')->getValue(),
                );
            }

            if ($headers !== ['TYPE', 'REFNO', 'DESCRIPTION', 'PRIO']) {
                $issues[] = [
                    'sheet' => $sheetName,
                    'row' => 1,
                    'message' => 'Headers must match the TRB template: TYPE, REF. NO, DESCRIPTION, PRIO.',
                ];

                continue;
            }

            $lastRow = $sheet->getHighestDataRow();
            $scanned += max(0, $lastRow - 1);

            if ($scanned > 10000) {
                $issues[] = [
                    'sheet' => $sheetName,
                    'row' => 1,
                    'message' => 'Maximum 10,000 TRB content rows per workbook.',
                ];

                break;
            }

            for ($number = 2; $number <= $lastRow; $number++) {
                $values = [];
                $formula = false;

                foreach (range('A', 'D') as $column) {
                    $cell = $sheet->getCell($column.$number);
                    $formula = $formula || $cell->getDataType() === DataType::TYPE_FORMULA;
                    $values[] = trim((string) $cell->getValue());
                }

                if (implode('', $values) === '') {
                    $skipped++;
                    continue;
                }

                $errors = [];

                if ($formula) {
                    $errors[] = 'Use plain values, not formulas.';
                }

                $typeRaw = $values[0];
                $type = preg_match('/^[1-4](?:\.0+)?$/', $typeRaw) === 1
                    ? (int) $typeRaw
                    : 0;

                if (! in_array($type, [1, 2, 3, 4], true)) {
                    $errors[] = 'TYPE must be 1, 2, 3, or 4.';
                }

                $refNo = trim($values[1]);
                $description = trim($values[2]);
                $priorityRaw = trim($values[3]);

                if ($refNo === '') {
                    $errors[] = 'REF. NO is required.';
                } else {
                    $maximumLength = $type === 4 ? 20 : 10;

                    if (mb_strlen($refNo) > $maximumLength) {
                        $errors[] = "REF. NO must not exceed {$maximumLength} characters for this row type.";
                    }
                }

                if ($description === '') {
                    $errors[] = 'DESCRIPTION is required.';
                }

                if ($type === 1 && mb_strlen(urlencode($description)) > 200) {
                    $errors[] = 'Function DESCRIPTION is too long for desc_trb_function after legacy encoding.';
                }

                if (
                    $priorityRaw === ''
                    || filter_var($priorityRaw, FILTER_VALIDATE_INT) === false
                    || (int) $priorityRaw < 0
                ) {
                    $errors[] = 'PRIO must be a whole number of 0 or greater.';
                }

                if ($type === 2 && ! $hasFunction) {
                    $errors[] = 'A Competence must follow a Function row.';
                }

                if ($type === 3 && (! $hasFunction || ! $hasCompetence)) {
                    $errors[] = 'A Topic must follow a Function and Competence row.';
                }

                if ($type === 4 && (! $hasCompetence || ! $hasTopic)) {
                    $errors[] = 'A Task must follow a Competence and Topic row.';
                }

                if ($errors !== []) {
                    $issues[] = [
                        'sheet' => $sheetName,
                        'row' => $number,
                        'message' => implode(' ', $errors),
                    ];

                    continue;
                }

                if ($type === 1) {
                    $hasFunction = true;
                    $hasCompetence = false;
                    $hasTopic = false;
                } elseif ($type === 2) {
                    $hasCompetence = true;
                    $hasTopic = false;
                } elseif ($type === 3) {
                    $hasTopic = true;
                }

                $rows[] = [
                    'sheet' => $sheetName,
                    'row' => $number,
                    'type' => $type,
                    'ref_no' => $refNo,
                    'description' => $description,
                    'prio' => (int) $priorityRaw,
                ];
            }
        }

        return [$rows, $skipped, $issues];
    }

    private function validateXlsxArchive(string $path): void
    {
        $archive = new ZipArchive();

        if ($archive->open($path) !== true) {
            throw new \RuntimeException('Invalid XLSX archive.');
        }

        try {
            $expandedSize = 0;

            for ($entry = 0; $entry < $archive->numFiles; $entry++) {
                $expandedSize += (int) ($archive->statIndex($entry)['size'] ?? 0);
            }

            if ($expandedSize > 64 * 1024 * 1024 || $archive->numFiles > 10000) {
                throw new \RuntimeException('Workbook archive is too large.');
            }
        } finally {
            $archive->close();
        }
    }

    private function ensureTransactionalTables(ConnectionInterface $db): void
    {
        $tables = [
            'trb_type',
            'trb_function',
            'trb_competence',
            'trb_sub_competence',
            'task',
        ];

        $engines = $db->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->whereIn('TABLE_NAME', $tables)
            ->pluck('ENGINE', 'TABLE_NAME');

        foreach ($tables as $table) {
            if (strtoupper((string) ($engines[$table] ?? '')) !== 'INNODB') {
                throw ValidationException::withMessages([
                    'file' => "Table {$table} must use InnoDB for safe TRB uploads and deletes.",
                ]);
            }
        }
    }

    private function normalizeHeader(string $value): string
    {
        return strtoupper(
            preg_replace('/[^A-Za-z0-9]+/', '', trim($value)) ?? '',
        );
    }

    private function decode(string $value): string
    {
        return urldecode($value);
    }

    private function schoolConnection(Request $request): ConnectionInterface
    {
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$code] ?? null) : null;

        abort_unless(
            $code !== '' && is_array($school),
            403,
            'No valid school has been selected.',
        );

        abort_unless(
            hash_equals(strtoupper((string) ($school['code'] ?? $code)), $code),
            403,
            'The selected school code is invalid.',
        );

        $connection = $school['connection'] ?? null;

        abort_unless(
            is_string($connection)
                && is_array(config("database.connections.{$connection}")),
            500,
            'The school database connection is unavailable.',
        );

        return DB::connection($connection);
    }
}
