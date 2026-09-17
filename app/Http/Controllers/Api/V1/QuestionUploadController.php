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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class QuestionUploadController extends Controller
{
    private const HEADERS = [
        'QUESTIONS', 'CHOICE 1', 'CHOICE 2', 'CHOICE 3',
        'CHOICE 4', 'CHOICE 5', 'ANSWER', 'LEVEL',
    ];

    public function options(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);
        return response()->json([
            'data' => $db->table('bs_course')->orderBy('name_course')
                ->get(['id', 'name_course']),
        ]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->schoolConnection($request);
        return response()->json([
            'data' => $db->table('bs_topic')->where('bs_course_id', $courseId)
                ->orderBy('order_no')->get(['id', 'desc_topic'])
                ->map(fn ($topic): array => [
                    'id' => (string) $topic->id,
                    'desc_topic' => $this->decode((string) $topic->desc_topic),
                ]),
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->schoolConnection($request);
        return response()->streamDownload(function (): void {
            $book = new Spreadsheet();
            try {
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('Questions');
                $sheet->fromArray([self::HEADERS], null, 'A1');
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'question-sample.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Validate every row before writing; the entire import is atomic. */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bs_course_id' => ['required', 'string'],
            'bs_topic_id' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:5120'],
            'replace_existing' => ['accepted'],
        ]);
        $db = $this->schoolConnection($request);
        $courseId = $validated['bs_course_id'];
        $topicId = $validated['bs_topic_id'];
        if (! $db->table('bs_course')->where('id', $courseId)->exists()) {
            throw ValidationException::withMessages(['bs_course_id' => 'Select a valid exam package.']);
        }
        if (! $db->table('bs_topic')->where('id', $topicId)->where('bs_course_id', $courseId)->exists()) {
            throw ValidationException::withMessages(['bs_topic_id' => 'The subject must belong to the selected exam package.']);
        }

        $book = null;
        try {
            $archive = new ZipArchive();
            if ($archive->open($request->file('file')->getRealPath()) !== true) {
                throw new \RuntimeException('Invalid XLSX archive.');
            }
            try {
                $expandedSize = 0;
                for ($entry = 0; $entry < $archive->numFiles; $entry++) {
                    $expandedSize += (int) ($archive->statIndex($entry)['size'] ?? 0);
                }
                if ($expandedSize > 32 * 1024 * 1024 || $archive->numFiles > 10000) {
                    throw new \RuntimeException('Workbook archive is too large.');
                }
            } finally {
                $archive->close();
            }
            // Restrict the actual reader, not just the filename extension.
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $estimatedRows = 0;
            foreach ($reader->listWorksheetInfo($request->file('file')->getRealPath()) as $info) {
                $estimatedRows += max(0, (int) $info['totalRows'] - 1);
                if ($estimatedRows > 2000 || (int) $info['totalColumns'] > 8) {
                    throw new \RuntimeException('Workbook exceeds the row or column limit.');
                }
            }
            $book = $reader->load($request->file('file')->getRealPath());
            [$rows, $skipped, $issues] = $this->readRows($book);
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['file' => 'Unable to read this XLSX file. Please use the downloadable template.']);
        } finally {
            $book?->disconnectWorksheets();
        }

        if ($issues !== []) {
            return response()->json([
                'message' => 'Import cancelled. Fix the listed rows and try again; no database records were changed.',
                'issues' => array_slice($issues, 0, 100),
                'issue_count' => count($issues),
            ], 422);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The workbook contains no question rows.']);
        }

        $loginId = (string) ($request->session()->get('login_id') ?? $request->user()?->getAuthIdentifier() ?? '');
        // Rollback cannot protect MyISAM tables; refuse an unsafe import.
        $engines = $db->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->whereIn('TABLE_NAME', ['bs_topic', 'bs_quest', 'bs_quest_ans'])
            ->pluck('ENGINE', 'TABLE_NAME');
        foreach (['bs_topic', 'bs_quest', 'bs_quest_ans'] as $table) {
            if (strtoupper((string) ($engines[$table] ?? '')) !== 'INNODB') {
                throw ValidationException::withMessages([
                    'file' => "Table {$table} must use InnoDB for safe transactional imports. Ask your database administrator before changing table engines.",
                ]);
            }
        }
        try {
            $counts = $db->transaction(function () use ($db, $rows, $courseId, $topicId, $loginId): array {
                // Serializes imports into the same subject on transactional tables.
                $db->table('bs_topic')->where('id', $topicId)->lockForUpdate()->first();
                $added = 0;
                $updated = 0;
                foreach ($rows as $row) {
                    $text = $row['question'];
                    $variants = array_values(array_unique([
                        urlencode($text),
                        urlencode(str_replace('&', 'andxx', $text)),
                        $text,
                    ]));
                    $matches = $db->table('bs_quest')->where('bs_topic_id', $topicId)
                        ->whereIn('quest_text', $variants)->lockForUpdate()->get(['id']);
                    if ($matches->count() > 1) {
                        throw ValidationException::withMessages([
                            'file' => "Multiple existing records match {$row['sheet']} row {$row['row']}. Resolve those duplicates before importing.",
                        ]);
                    }
                    $existingId = $matches->first()?->id;
                    $id = $existingId ? (string) $existingId : (string) Str::uuid();
                    $payload = [
                        'quest_text' => urlencode($text),
                        'bs_course_id' => $courseId,
                        'bs_topic_id' => $topicId,
                        'login_id' => $loginId,
                        'level' => $row['level'],
                        'last_update' => now()->format('Y-m-d H:i:s'),
                    ];
                    if ($existingId) {
                        $db->table('bs_quest')->where('id', $id)->update($payload);
                        $db->table('bs_quest_ans')->where('bs_quest_id', $id)->delete();
                        $updated++;
                    } else {
                        $db->table('bs_quest')->insert([
                            'id' => $id, ...$payload,
                            'company_id' => '', 'for_item' => 'Y', 'prio' => 0, 'active' => 'Y',
                            'choice_1' => '', 'choice_2' => '', 'choice_3' => '', 'choice_4' => '', 'choice_5' => '',
                            'choice_1_wt' => 0, 'choice_2_wt' => 0, 'choice_3_wt' => 0, 'choice_4_wt' => 0, 'choice_5_wt' => 0,
                            'ans_exp' => '', 'keyword' => '', 'quest_img' => '', 'quest_vid' => '', 'info' => '',
                            'bs_func' => '', 'validated' => '', 'decision' => '', 'revised_to_id' => '', 'revised_from_id' => '',
                        ]);
                        $added++;
                    }
                    foreach ($row['choices'] as $index => $choice) {
                        if ($choice === '') continue;
                        $db->table('bs_quest_ans')->insert([
                            'id' => (string) Str::uuid(),
                            'bs_quest_id' => $id,
                            'answer_text' => urlencode($choice),
                            'filename' => '',
                            'answer' => $row['answer'] === chr(65 + $index) ? 'Y' : 'N',
                        ]);
                    }
                }
                return ['added' => $added, 'updated' => $updated];
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            return response()->json(['message' => 'Import failed. No changes were committed. Check the server log and database table engines.'], 500);
        }

        return response()->json([
            'message' => 'Questions imported successfully.',
            ...$counts,
            'processed' => count($rows),
            'skipped' => $skipped,
        ]);
    }

    private function readRows(Spreadsheet $book): array
    {
        $rows = [];
        $issues = [];
        $skipped = 0;
        $seen = [];
        $scanned = 0;
        foreach ($book->getWorksheetIterator() as $sheet) {
            $name = $sheet->getTitle();
            $headers = [];
            foreach (range('A', 'H') as $column) {
                $headers[] = strtoupper(trim((string) $sheet->getCell($column.'1')->getValue()));
            }
            if (array_slice($headers, 0, 7) !== array_slice(self::HEADERS, 0, 7)
                || ! in_array($headers[7], ['', 'LEVEL'], true)) {
                $issues[] = ['sheet' => $name, 'row' => 1, 'message' => 'Headers must match the template: QUESTIONS, CHOICE 1–5, ANSWER, and optional LEVEL.'];
                continue;
            }
            $lastRow = $sheet->getHighestDataRow();
            $scanned += max(0, $lastRow - 1);
            if ($scanned > 2000) {
                $issues[] = ['sheet' => $name, 'row' => 1, 'message' => 'Maximum 2,000 data rows across the workbook. Remove unused trailing rows or split the file.'];
                break;
            }
            for ($number = 2; $number <= $lastRow; $number++) {
                $values = [];
                $formula = false;
                foreach (range('A', 'H') as $column) {
                    $cell = $sheet->getCell($column.$number);
                    $formula = $formula || $cell->getDataType() === DataType::TYPE_FORMULA;
                    $values[] = trim((string) $cell->getValue());
                }
                if (implode('', $values) === '') { $skipped++; continue; }
                // Skip only the exact instruction pattern in the supplied legacy sample.
                if (implode('', array_slice($values, 1)) === '' && (
                    strtoupper($values[0]) === 'NOTE:'
                    || str_starts_with($values[0], '- Column G (Answer)')
                    || str_starts_with($values[0], '- Column H (Level)')
                )) { $skipped++; continue; }

                $question = $values[0];
                $choices = array_slice($values, 1, 5);
                $answer = strtoupper($values[6]);
                $level = $values[7] === '' ? '1' : $values[7];
                $errors = [];
                if ($formula) $errors[] = 'Use plain values, not formulas.';
                if ($question === '' || mb_strlen($question) > 10000) $errors[] = 'Question is required (maximum 10,000 characters).';
                foreach (array_slice($choices, 0, 4) as $index => $choice) {
                    if ($choice === '') $errors[] = 'Option '.chr(65 + $index).' is required.';
                }
                foreach ($choices as $choice) {
                    if (mb_strlen($choice) > 5000) $errors[] = 'Option text exceeds 5,000 characters.';
                }
                $nonempty = array_values(array_filter($choices, static fn ($value): bool => $value !== ''));
                if (count(array_unique($nonempty)) !== count($nonempty)) $errors[] = 'Options must not contain duplicate text.';
                if (! in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $errors[] = 'ANSWER must be A, B, C, D, or E.';
                } elseif ($choices[ord($answer) - 65] === '') {
                    $errors[] = 'The correct answer points to a blank option.';
                }
                if (! in_array($level, ['1', '2', '3'], true)) $errors[] = 'LEVEL must be 1, 2, or 3.';
                $key = hash('sha256', $question);
                if (isset($seen[$key])) $errors[] = 'Question repeats an earlier row in this workbook.';
                $seen[$key] = true;
                if ($errors !== []) {
                    $issues[] = ['sheet' => $name, 'row' => $number, 'message' => implode(' ', $errors)];
                } else {
                    $rows[] = ['sheet' => $name, 'row' => $number, 'question' => $question, 'choices' => $choices, 'answer' => $answer, 'level' => (int) $level];
                }
            }
        }
        return [$rows, $skipped, $issues];
    }

    private function decode(string $value): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value));
    }

    private function schoolConnection(Request $request): ConnectionInterface
    {
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$code] ?? null) : null;
        abort_unless($code !== '' && is_array($school), 403, 'No valid school has been selected.');
        abort_unless(hash_equals(strtoupper((string) ($school['code'] ?? $code)), $code), 403, 'The selected school code is invalid.');
        $connection = $school['connection'] ?? null;
        abort_unless(is_string($connection) && is_array(config("database.connections.{$connection}")), 500, 'The school database connection is unavailable.');
        return DB::connection($connection);
    }
}
