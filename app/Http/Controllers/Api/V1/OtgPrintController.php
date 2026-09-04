<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OtgPrintController extends Controller
{
    /**
     * Generate and display a student's OTG PDF.
     */
    public function download(
        Request $request,
        string $personId,
    ): Response {
        /* Allow enough time for rendering a large OTG PDF. */
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        [$connection, $school] =
            $this->resolveSchoolConnection($request);

        $database = DB::connection($connection);

        /*
         * Retrieve the student from the selected school database.
         */
        $student = $database
            ->table('person')
            ->select([
                'id',
                'school_id_no',
                'fname',
                'mname',
                'lname',
                'dept',
                'batch_no',
            ])
            ->where('id', $personId)
            ->first();

        abort_if(
            $student === null,
            HttpResponse::HTTP_NOT_FOUND,
            'The selected student was not found.',
        );

        /*
         * Retrieve every vessel assignment in chronological order.
         */
        $vesselAssignments = $database
            ->table('person_trb_setup')
            ->leftJoin(
                'vessel_type',
                'person_trb_setup.vessel_type_id',
                '=',
                'vessel_type.id',
            )
            ->select([
                'person_trb_setup.id',
                'person_trb_setup.person_id',
                'person_trb_setup.vessel_name',
                'person_trb_setup.vessel_type_id',
                'person_trb_setup.ship_company',
                'person_trb_setup.flag',
                'person_trb_setup.from_date',
                'person_trb_setup.to_date',
                'vessel_type.desc_vessel_type as vessel_type',
            ])
            ->where(
                'person_trb_setup.person_id',
                $personId,
            )
            ->orderBy('person_trb_setup.from_date')
            ->get();

        /*
         * Match the legacy first-sign-on calculation.
         */
        $firstSignOn = $database
            ->table('person_trb_setup')
            ->where('person_id', $personId)
            ->min('from_date');

        /*
         * Build the student's complete TRB hierarchy:
         *
         * TRB Book
         *   -> Competence
         *      -> Sub-competence
         *         -> Task
         *            -> Student task
         *               -> Evidence files
         */
        $trbBooks = $this->buildTrbBooks(
            database: $database,
            personId: $personId,
        );

        /*
         * Use a writable custom mPDF temporary directory.
         */
        $temporaryDirectory = storage_path(
            'app/mpdf',
        );

        File::ensureDirectoryExists(
            $temporaryDirectory,
        );

        $mpdf = new Mpdf([
            'format' => 'Legal',
            'orientation' => 'L',
            'tempDir' => $temporaryDirectory,
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 8,
            'margin_bottom' => 15,
            'margin_header' => 5,
            'margin_footer' => 5,
            'default_font' => 'dejavusans',
        ]);

        /*
         * Safety net: even with WriteHTML() chunked per book below, an
         * unusually large book (many competences/tasks) could still trip
         * mPDF's internal PCRE parsing. Raise the backtrack limit as a
         * belt-and-suspenders guard. This only affects this request.
         */
        ini_set('pcre.backtrack_limit', '10000000');

        $mpdf->SetDisplayMode('fullwidth');

        $printedAt = now()
            ->timezone('Asia/Manila')
            ->format('M d, Y h:i A');

        $mpdf->SetHTMLFooter(
            '
            <div
                style="
                    width: 100%;
                    border-top: 1px solid #cccccc;
                    padding-top: 4px;
                    text-align: right;
                    font-size: 9px;
                    color: #555555;
                "
            >
                Printed '.$printedAt.'
                &nbsp;&nbsp;|&nbsp;&nbsp;
                OTG Page {PAGENO} of {nbpg}
            </div>
            ',
        );

        /*
         * Write the header/cover section as its own chunk.
         */
        $headerHtml = view('otg.print', [
            'mode' => 'header',
            'student' => $student,
            'school' => $school,
            'vesselAssignments' => $vesselAssignments,
            'firstSignOn' => $firstSignOn,
            'trbBooks' => $trbBooks,
        ])->render();

        $mpdf->WriteHTML($headerHtml);

        /*
         * Write each TRB book as its own WriteHTML() chunk. This keeps any
         * single call well under mPDF's pcre.backtrack_limit, regardless of
         * how many books/tasks a given student has accumulated.
         */
        foreach ($trbBooks as $book) {
            $bookHtml = view('otg.print', [
                'mode' => 'book',
                'book' => $book,
            ])->render();

            $mpdf->WriteHTML($bookHtml);
        }

        $schoolIdNo = trim((string) ($student->school_id_no ?? ''));
        $documentTitle = $schoolIdNo !== '' ? $schoolIdNo : $personId;

        $filename = sprintf(
            '%s-otg.pdf',
            Str::slug($documentTitle),
        );

        $mpdf->SetTitle($documentTitle);

        $pdf = $mpdf->Output(
            '',
            Destination::STRING_RETURN,
        );

        /*
         * "inline" opens the PDF in the browser.
         * Change inline to attachment to force a download.
         */
        return response(
            $pdf,
            HttpResponse::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' =>
                    'inline; filename="'.$filename.'"',
                'Cache-Control' =>
                    'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ],
        );
    }

    /**
     * Resolve and activate the database selected during login.
     *
     * @return array{
     *     0: string,
     *     1: array<string, mixed>
     * }
     */
    private function resolveSchoolConnection(
        Request $request,
    ): array {
        $schoolCode = strtoupper(trim(
            (string) $request
                ->session()
                ->get('school_code', ''),
        ));

        abort_if(
            $schoolCode === '',
            HttpResponse::HTTP_FORBIDDEN,
            'No school database has been selected.',
        );

        $schools = config(
            'schools.schools',
            [],
        );

        abort_unless(
            is_array($schools),
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'School configuration is unavailable.',
        );

        $school = $schools[$schoolCode] ?? null;

        abort_unless(
            is_array($school),
            HttpResponse::HTTP_FORBIDDEN,
            'The selected school is not configured.',
        );

        $configuredCode = strtoupper(trim(
            (string) (
                $school['code'] ??
                $schoolCode
            ),
        ));

        abort_unless(
            hash_equals(
                $configuredCode,
                $schoolCode,
            ),
            HttpResponse::HTTP_FORBIDDEN,
            'The selected school code is invalid.',
        );

        $connection =
            $school['connection'] ?? null;

        abort_unless(
            is_string($connection) &&
            $connection !== '',
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        $connectionConfiguration = config(
            "database.connections.{$connection}",
        );

        abort_unless(
            is_array($connectionConfiguration),
            HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is not configured.',
        );

        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        return [
            $connection,
            $school,
        ];
    }

    /**
     * Build the TRB book hierarchy used by the PDF.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrbBooks(
        ConnectionInterface $database,
        string $personId,
    ): array {
        $books = $database
            ->table('person_trb_book')
            ->leftJoin(
                'trb_type',
                'person_trb_book.trb_type_id',
                '=',
                'trb_type.id',
            )
            ->select([
                'person_trb_book.id',
                'person_trb_book.person_id',
                'person_trb_book.trb_type_id',
                'trb_type.prio',
                'trb_type.desc_trb_type',
            ])
            ->where(
                'person_trb_book.person_id',
                $personId,
            )
            ->orderBy('trb_type.prio')
            ->get();

        if ($books->isEmpty()) {
            return [];
        }

        /*
         * Load every level in bulk. This avoids running queries inside the
         * nested mapping loops, which previously caused the PDF request to
         * exceed PHP's 30-second execution limit.
         */
        $trbTypeIds = $books
            ->pluck('trb_type_id')
            ->filter()
            ->unique()
            ->values();

        $functions = $database
            ->table('trb_function')
            ->select([
                'id',
                'trb_type_id',
            ])
            ->whereIn('trb_type_id', $trbTypeIds)
            ->get();

        $functionIds = $functions
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $competences = $database
            ->table('trb_competence')
            ->select([
                'id',
                'ref_no',
                'desc_competence',
                'prio',
                'trb_function_id',
            ])
            ->whereIn('trb_function_id', $functionIds)
            ->orderBy('prio')
            ->get();

        $competenceIds = $competences
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $subCompetences = $database
            ->table('trb_sub_competence')
            ->select([
                'id',
                'ref_no',
                'desc_sub_competence',
                'prio',
                'trb_competence_id',
            ])
            ->whereIn('trb_competence_id', $competenceIds)
            ->orderBy('prio')
            ->get();

        $subCompetenceIds = $subCompetences
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $tasks = $database
            ->table('task')
            ->select([
                'id',
                'ref_no',
                'desc_task',
                'prio',
                'trb_competence_id',
                'trb_sub_competence_id',
            ])
            ->whereIn('trb_sub_competence_id', $subCompetenceIds)
            ->orderBy('prio')
            ->get();

        $taskIds = $tasks
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $personTasks = $database
            ->table('person_task')
            ->select([
                'id',
                'person_id',
                'task_id',
                'month_no',
                'completed',
                'not_app',
            ])
            ->where('person_id', $personId)
            ->whereIn('task_id', $taskIds)
            ->get();

        $personTaskIds = $personTasks
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $evidenceFiles = $database
            ->table('person_task_file')
            ->select([
                'id',
                'person_task_id',
                'filename',
                'gdrive_link',
            ])
            ->whereIn('person_task_id', $personTaskIds)
            ->orderBy('filename')
            ->get()
            ->groupBy('person_task_id');

        $functionIdsByType = $functions
            ->groupBy('trb_type_id')
            ->map(
                fn ($items) => $items->pluck('id'),
            );

        $competencesByFunction = $competences
            ->groupBy('trb_function_id');

        $subCompetencesByCompetence = $subCompetences
            ->groupBy('trb_competence_id');

        $tasksBySubCompetence = $tasks
            ->groupBy('trb_sub_competence_id');

        $personTasksByTask = $personTasks
            ->groupBy('task_id');

        return $books
            ->map(function (object $book) use (
                $functionIdsByType,
                $competencesByFunction,
                $subCompetencesByCompetence,
                $tasksBySubCompetence,
                $personTasksByTask,
                $evidenceFiles,
            ): array {
                $bookFunctionIds = $functionIdsByType->get(
                    $book->trb_type_id,
                    collect(),
                );

                $bookCompetences = $bookFunctionIds
                    ->flatMap(
                        fn ($functionId) =>
                            $competencesByFunction->get(
                                $functionId,
                                collect(),
                            ),
                    )
                    ->sortBy('prio')
                    ->values();

                $competenceData = $bookCompetences
                    ->map(function (object $competence) use (
                        $subCompetencesByCompetence,
                        $tasksBySubCompetence,
                        $personTasksByTask,
                        $evidenceFiles,
                    ): array {
                        $subCompetenceData =
                            $subCompetencesByCompetence
                                ->get(
                                    $competence->id,
                                    collect(),
                                )
                                ->map(function (
                                    object $subCompetence,
                                ) use (
                                    $tasksBySubCompetence,
                                    $personTasksByTask,
                                    $evidenceFiles,
                                ): array {
                                    $taskData =
                                        $tasksBySubCompetence
                                            ->get(
                                                $subCompetence->id,
                                                collect(),
                                            )
                                            ->map(function (
                                                object $task,
                                            ) use (
                                                $personTasksByTask,
                                                $evidenceFiles,
                                            ): array {
                                                $personTask =
                                                    $personTasksByTask
                                                        ->get(
                                                            $task->id,
                                                            collect(),
                                                        )
                                                        ->first();

                                                return [
                                                    'id' => $task->id,
                                                    'ref_no' => $task->ref_no,
                                                    'description' => urldecode(
                                                        (string) $task->desc_task,
                                                    ),
                                                    'person_task' => $personTask,
                                                    'evidence_files' =>
                                                        $personTask === null
                                                            ? collect()
                                                            : $evidenceFiles->get(
                                                                $personTask->id,
                                                                collect(),
                                                            ),
                                                ];
                                            })
                                            ->all();

                                    return [
                                        'id' => $subCompetence->id,
                                        'ref_no' => $subCompetence->ref_no,
                                        'description' => urldecode(
                                            (string) $subCompetence
                                                ->desc_sub_competence,
                                        ),
                                        'tasks' => $taskData,
                                    ];
                                })
                                ->all();

                        return [
                            'id' => $competence->id,
                            'ref_no' => $competence->ref_no,
                            'description' => urldecode(
                                (string) $competence->desc_competence,
                            ),
                            'sub_competences' => $subCompetenceData,
                        ];
                    })
                    ->all();

                return [
                    'id' => $book->id,
                    'trb_type_id' => $book->trb_type_id,
                    'title' => urldecode(
                        (string) $book->desc_trb_type,
                    ),
                    'priority' => $book->prio,
                    'competences' => $competenceData,
                ];
            })
            ->all();
    }
}