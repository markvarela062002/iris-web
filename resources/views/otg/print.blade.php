@php
    $formatDate = static function ($value): string {
        if (empty($value) || $value === '1970-01-01') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('M d, Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    };

    $mode ??= 'header';
@endphp

@if ($mode === 'header')
    @php
        $fullName = trim(implode(' ', array_filter([
            $student->lname ? $student->lname.',' : null,
            $student->fname ?? null,
            $student->mname ?? null,
        ])));

        $program = strtoupper(trim((string) ($student->dept ?? ''))) === 'DECK'
            ? 'BSMT'
            : 'BSMarE';

        $schoolName = (string) ($school['name'] ?? '');
        $assignmentCount = max(3, $vesselAssignments->count());
    @endphp

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">

        <style>
            body {
                color: #111827;
                font-family: dejavusans, sans-serif;
                font-size: 9px;
                line-height: 1.35;
            }

            h1,
            h2,
            p {
                margin: 0;
            }

            .report-title {
                margin-bottom: 8px;
                text-align: center;
                font-size: 14px;
                font-weight: bold;
            }

            .section-title {
                margin-bottom: 7px;
                text-align: center;
                font-size: 13px;
                font-weight: bold;
            }

            .spacer {
                height: 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                border: 1px solid #111827;
                padding: 4px;
                vertical-align: top;
            }

            .label-cell,
            .competence-row td,
            .sub-competence-row td {
                background: #b5cef7;
            }

            .label-cell {
                width: 18%;
                font-style: italic;
            }

            .instructions {
                margin-top: 9px;
                font-size: 9px;
                line-height: 1.4;
            }

            .instructions ol {
                margin: 3px 0 0 18px;
                padding: 0;
            }

            .instructions li {
                margin-bottom: 3px;
            }

            .approval-table {
                margin-top: 16px;
            }

            .approval-table td {
                border: 0;
            }

            .signature-line {
                border-bottom: 1px solid #111827;
                height: 24px;
            }

            .page-break {
                page-break-before: always;
            }

            .task-table {
                table-layout: fixed;
                font-size: 7px;
            }

            .task-table thead {
                display: table-header-group;
            }

            .task-table tr {
                page-break-inside: avoid;
            }

            .task-table th {
                background: #9ca3af;
                text-align: center;
                vertical-align: middle;
                font-weight: bold;
            }

            .ref-column {
                width: 6%;
            }

            .task-column {
                width: 24%;
            }

            .evidence-column {
                width: 16%;
            }

            .month-column {
                width: 4.5%;
                text-align: center;
            }

            .task-description {
                white-space: normal;
                overflow-wrap: break-word;
            }

            .evidence-link {
                color: #1d4ed8;
                text-decoration: underline;
            }

            .muted {
                color: #6b7280;
            }

            .center {
                text-align: center;
            }
        </style>
    </head>

    <body>
        <div class="report-title">
            ONBOARD TRAINING GUIDANCE (OTG) FOR {{ $program }} STUDENTS
        </div>

        <table>
            <tr>
                <td class="label-cell">Name of Student</td>
                <td>{{ $fullName }}</td>
                <td class="label-cell">Name of MHEI</td>
                <td>{{ $schoolName }}</td>
            </tr>
            <tr>
                <td class="label-cell">School ID No.</td>
                <td>{{ $student->school_id_no ?: '—' }}</td>
                <td class="label-cell">First Sign On</td>
                <td>{{ $formatDate($firstSignOn) ?: '—' }}</td>
            </tr>
        </table>

        <div class="spacer"></div>

        <table>
            <tr>
                <td class="label-cell">Name of Vessel</td>
                @for ($index = 0; $index < $assignmentCount; $index++)
                    @php
                        $assignment = $vesselAssignments->get($index);
                    @endphp
                    <td>
                        Vessel {{ $index + 1 }}:
                        {{ $assignment?->vessel_name ?: '' }}
                    </td>
                @endfor
            </tr>

            <tr>
                <td class="label-cell">Type of Vessel</td>
                @for ($index = 0; $index < $assignmentCount; $index++)
                    @php
                        $assignment = $vesselAssignments->get($index);
                    @endphp
                    <td>{{ $assignment?->vessel_type ?: '' }}</td>
                @endfor
            </tr>

            <tr>
                <td class="label-cell">Shipping Company</td>
                @for ($index = 0; $index < $assignmentCount; $index++)
                    @php
                        $assignment = $vesselAssignments->get($index);
                    @endphp
                    <td>{{ $assignment?->ship_company ?: '' }}</td>
                @endfor
            </tr>

            <tr>
                <td class="label-cell">Flag Nationality</td>
                @for ($index = 0; $index < $assignmentCount; $index++)
                    @php
                        $assignment = $vesselAssignments->get($index);
                    @endphp
                    <td>{{ $assignment?->flag ?: '' }}</td>
                @endfor
            </tr>
        </table>

        <div class="instructions">
            <strong>Instructions:</strong>

            <ol>
                <li>
                    Upon joining the ship, the student must become familiar with
                    duties, ship arrangements, installations, equipment,
                    procedures, and ship characteristics relevant to routine and
                    emergency duties. These must be based on the ship's SMS and
                    recorded in the TRB.
                </li>
                <li>
                    The Onboard Training Supervisor shall coordinate with the
                    shipping or manning company to identify the vessel type,
                    machinery, equipment, instruments, and appropriate objective
                    evidence for each assigned task.
                </li>
                <li>
                    The objective evidence shown in the guidance is illustrative.
                    The supervisor must identify evidence that can realistically
                    be produced aboard the assigned vessel.
                </li>
                <li>
                    Duties and tasks are classified as sea projects and operational
                    tasks. Sea projects are expected to be completed fully, while
                    at least 70% of operational tasks are expected to be completed
                    during onboard training.
                </li>
                <li>
                    Project works are additional assignments intended to develop
                    knowledge of the ship, its equipment, and life-saving
                    appliances. At least 70% should be completed.
                </li>
                <li>
                    Reports may contain detailed information, drawings, photos,
                    calculations, or narrative descriptions of activities.
                </li>
                <li>
                    Students should distribute tasks across Months 1–12 and report
                    accomplishments during monthly monitoring with corresponding
                    TRB entries and objective evidence.
                </li>
                <li>
                    This OTG is used to monitor student progress by the Onboard
                    Training Supervisor, Commission monitoring team, Maritime
                    Administration, and Shipboard Training Supervisor.
                </li>
            </ol>
        </div>

        <table class="approval-table">
            <tr>
                <td style="width: 50%;"></td>
                <td style="width: 50%;">Approved by:</td>
            </tr>
            <tr>
                <td></td>
                <td class="signature-line"></td>
            </tr>
            <tr>
                <td></td>
                <td class="center">
                    Name and Signature of Onboard Training Supervisor / Date
                </td>
            </tr>
        </table>

        @if (empty($trbBooks))
            <div class="page-break"></div>

            <div class="section-title">TRAINING RECORD BOOK</div>

            <p class="center muted">
                No Training Record Book is assigned to this student.
            </p>
        @endif
    </body>
    </html>
@elseif ($mode === 'book')
    <div class="page-break"></div>

    <div class="section-title">
        {{ $book['title'] ?: 'TRAINING RECORD BOOK' }}
    </div>

    <table class="task-table">
        <thead>
            <tr>
                <th class="ref-column" rowspan="2">Ref No.</th>
                <th class="task-column" rowspan="2">Duty / Task</th>
                <th class="evidence-column" rowspan="2">
                    Objective Evidence
                    <br>
                    <span style="font-size: 6px; font-weight: normal;">
                        Pictures, videos, documents, drawings,
                        calculations, attestations, dates, and signatures
                    </span>
                </th>
                <th colspan="12">Task Schedule</th>
            </tr>
            <tr>
                @for ($month = 1; $month <= 12; $month++)
                    <th class="month-column">Month {{ $month }}</th>
                @endfor
            </tr>
        </thead>

        <tbody>
            @forelse ($book['competences'] as $competence)
                <tr class="competence-row">
                    <td>{{ $competence['ref_no'] }}</td>
                    <td class="task-description">
                        <strong>{{ $competence['description'] }}</strong>
                    </td>
                    <td></td>
                    @for ($month = 1; $month <= 12; $month++)
                        <td></td>
                    @endfor
                </tr>

                @foreach ($competence['sub_competences'] as $subCompetence)
                    <tr class="sub-competence-row">
                        <td>{{ $subCompetence['ref_no'] }}</td>
                        <td class="task-description">
                            {{ $subCompetence['description'] }}
                        </td>
                        <td></td>
                        @for ($month = 1; $month <= 12; $month++)
                            <td></td>
                        @endfor
                    </tr>

                    @foreach ($subCompetence['tasks'] as $task)
                        @php
                            $personTask = $task['person_task'];
                            $notApplicable = strtoupper(
                                trim((string) ($personTask?->not_app ?? '')),
                            ) === 'Y';
                            $scheduledMonth = (int) ($personTask?->month_no ?? 0);
                            $completedDate = $formatDate(
                                $personTask?->completed ?? null,
                            );
                        @endphp

                        <tr>
                            <td>{{ $task['ref_no'] }}</td>
                            <td class="task-description">
                                {{ $task['description'] }}
                            </td>
                            <td>
                                @if ($notApplicable)
                                    <strong>N/A</strong>
                                @else
                                    @forelse ($task['evidence_files'] as $evidence)
                                        @php
                                            $fileName = trim((string) (
                                                $evidence->filename ?? ''
                                            ));

                                            $googleDriveLink = trim((string) (
                                                $evidence->gdrive_link ?? ''
                                            ));

                                            $displayName = $fileName !== ''
                                                ? $fileName
                                                : ($googleDriveLink !== ''
                                                    ? $googleDriveLink
                                                    : 'Evidence unavailable');

                                            /*
                                            * Newer records may already contain a full
                                            * URL in gdrive_link.
                                            *
                                            * Older records may only contain a filename.
                                            * Those files are served through the existing
                                            * person_task FTP route.
                                            */
                                            $evidenceUrl = '';

                                            if ($googleDriveLink !== '') {
                                                $evidenceUrl =
                                                    $googleDriveLink;
                                            } elseif ($fileName !== '') {
                                                $safeFileName = basename(
                                                    str_replace(
                                                        '\\',
                                                        '/',
                                                        $fileName,
                                                    ),
                                                );

                                                $evidenceUrl = route(
                                                    'dashboard.files.person-task',
                                                    [
                                                        'filename' =>
                                                            $safeFileName,
                                                    ],
                                                );
                                            }
                                        @endphp

                                        @if ($evidenceUrl !== '')
                                            <a
                                                class="evidence-link"
                                                href="{{ $evidenceUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {{ $displayName }}
                                            </a>
                                        @else
                                            <span class="muted">
                                                {{ $displayName }}
                                            </span>
                                        @endif

                                        @if (! $loop->last)
                                            <br>
                                        @endif
                                    @empty
                                        <span class="muted">—</span>
                                    @endforelse
                                @endif
                            </td>

                            @for ($month = 1; $month <= 12; $month++)
                                <td class="month-column">
                                    @if (
                                        ! $notApplicable &&
                                        $scheduledMonth === $month &&
                                        $completedDate !== ''
                                    )
                                        {{ $completedDate }}
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                @endforeach
            @empty
                <tr>
                    <td class="center" colspan="15">
                        No TRB competencies were found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endif