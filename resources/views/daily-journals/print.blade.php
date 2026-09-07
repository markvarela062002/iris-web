@php
    use Illuminate\Support\Carbon;

    $formatDate = static function ($value): string {
        if (empty($value) || $value === '1970-01-01') {
            return '—';
        }

        try {
            return Carbon::parse($value)->format('M d, Y');
        } catch (Throwable) {
            return (string) $value;
        }
    };

    $decodeText = static function ($value): string {
        $text = trim((string) $value);

        return $text !== '' ? urldecode($text) : '—';
    };

    $studentName = strtoupper(trim(implode(' ', array_filter([
        ! empty($student->lname)
            ? $student->lname.','
            : null,
        $student->fname ?? null,
        $student->mname ?? null,
    ]))));

    $department = strtoupper(
        trim((string) ($student->dept ?? '')),
    );

    $reportTitle = $department === 'DECK'
        ? 'DAILY JOURNAL OF BRIDGE WATCHKEEPING DUTIES'
        : 'DAILY JOURNAL OF ENGINE-ROOM WATCHKEEPING DUTIES';

    $regulation = $department === 'DECK'
        ? '(STCW Convention, Regulation II/1, 2, 3)'
        : '(STCW Convention, Regulation III/1, 2, 3)';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <style>
        @page {
            margin: 10mm 10mm 16mm;
        }

        body {
            margin: 0;
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
            text-align: center;
            font-size: 14px;
            font-weight: bold;
        }

        .regulation {
            margin-top: 2px;
            text-align: center;
            font-size: 9px;
        }

        .student-table,
        .journal-table,
        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }

        .student-table {
            margin-top: 12px;
        }

        .student-table td,
        .journal-table td {
            border: 1px solid #1f2937;
            padding: 5px;
            vertical-align: top;
        }

        .label {
            width: 18%;
            background: #dbeafe;
            font-weight: bold;
        }

        /*
         * Allow every journal to continue naturally.
         *
         * Do not use page-break-inside: avoid here because a large
         * journal would be pushed entirely onto the next page and
         * leave unnecessary white space.
         */
        .journal {
            margin-top: 10px;
            page-break-before: auto;
            page-break-after: auto;
            page-break-inside: auto;
        }

        /*
         * Keep the heading attached to the first journal row when
         * the remaining page space is insufficient.
         */
        .journal-heading {
            padding: 6px 8px;
            background: #1d4ed8;
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            page-break-after: avoid;
        }

        .journal-table {
            table-layout: fixed;
            page-break-before: avoid;
        }

        /*
         * Individual rows remain together, while the journal table
         * itself is still allowed to continue on the next page.
         */
        .journal-table tr {
            page-break-inside: avoid;
        }

        .section-label {
            background: #eff6ff;
            font-weight: bold;
        }

        .evidence {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .muted {
            color: #6b7280;
        }

        /*
         * Keep the complete signature area together.
         */
        .signature-table {
            margin-top: 8px;
            table-layout: fixed;
            page-break-inside: avoid;
        }

        .signature-table td {
            width: 50%;
            height: 92px;
            border: 1px solid #1f2937;
            padding: 8px 12px;
            text-align: center;
            vertical-align: bottom;
        }

        /*
         * Both columns use an equal-height signing space.
         */
        .signature-area {
            height: 50px;
            text-align: center;
            vertical-align: bottom;
        }

        .signature-image {
            display: block;
            width: auto;
            max-width: 145px;
            height: 40px;
            margin: 0 auto;
        }

        .signature-placeholder {
            height: 40px;
        }

        /*
         * Both signature lines always have the same width.
         */
        .signature-line {
            width: 78%;
            height: 1px;
            margin: 3px auto 0;
            border-top: 1px solid #111827;
        }

        .signature-name {
            min-height: 12px;
            margin-top: 4px;
            font-size: 9px;
            font-weight: bold;
            line-height: 1.2;
            text-align: center;
        }

        .signature-description {
            min-height: 18px;
            margin-top: 2px;
            font-size: 7px;
            line-height: 1.2;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="report-title">
        {{ $reportTitle }}
    </div>

    <div class="regulation">
        {{ $regulation }}
    </div>

    <table class="student-table">
        <tr>
            <td class="label">
                Name of Student
            </td>

            <td>
                {{ $studentName }}
            </td>

            <td class="label">
                Name of MHEI
            </td>

            <td>
                {{ $school['name'] ?? '—' }}
            </td>
        </tr>

        <tr>
            <td class="label">
                School ID No.
            </td>

            <td>
                {{
                    $student->school_id_no
                    ?: ($student->school_id_no ?: '—')
                }}
            </td>

            <td class="label">
                Department
            </td>

            <td>
                {{ $department ?: '—' }}
            </td>
        </tr>

        <tr>
            <td class="label">
                Report Period
            </td>

            <td colspan="3">
                {{ $formatDate($dateFrom) }}
                –
                {{ $formatDate($dateTo) }}
            </td>
        </tr>
    </table>

    @foreach ($journals as $journal)
        <div class="journal">
            <div class="journal-heading">
                Journal {{ $loop->iteration }}:
                {{ $formatDate($journal->date_journal) }}
            </div>

            <table class="journal-table">
                <tr>
                    <td class="section-label">
                        Name of Vessel
                    </td>

                    <td colspan="3">
                        {{ $journal->vessel_name ?: '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="section-label">
                        Date
                    </td>

                    <td>
                        {{ $formatDate($journal->date_journal) }}
                    </td>

                    <td class="section-label">
                        Watch Time
                    </td>

                    <td>
                        {{ $journal->journal_time ?: '—' }}
                        –
                        {{ $journal->journal_time_to ?: '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="section-label">
                        Watchkeeping Duty Hours
                    </td>

                    <td>
                        {{ $journal->duty_hours ?: '0 hr 0 min' }}
                    </td>

                    <td class="section-label">
                        Verification
                    </td>

                    <td>
                        @if (
                            ! empty($journal->esig_file) &&
                            strtolower(
                                trim((string) $journal->esig_file),
                            ) !== 'null'
                        )
                            Validated
                        @else
                            Pending
                        @endif
                    </td>
                </tr>

                <tr>
                    <td class="section-label">
                        Port Departure
                    </td>

                    <td>
                        {{ $journal->port_depart ?: '—' }}
                    </td>

                    <td class="section-label">
                        Destination
                    </td>

                    <td>
                        {{ $journal->port_dest ?: '—' }}
                    </td>
                </tr>

                @if ($department === 'DECK')
                    <tr>
                        <td class="section-label">
                            Ship Position
                        </td>

                        <td colspan="3">
                            LAT:
                            {{ $journal->ship_lat ?: '—' }}

                            &nbsp;&nbsp;|&nbsp;&nbsp;

                            LONG:
                            {{ $journal->ship_long ?: '—' }}

                            &nbsp;&nbsp;|&nbsp;&nbsp;

                            VICINITY:
                            {{ $journal->ship_vicinity ?: '—' }}
                        </td>
                    </tr>

                    <tr>
                        <td class="section-label">
                            Position-Fixing Method
                        </td>

                        <td>
                            {{ $journal->pos_fix ?: '—' }}
                        </td>

                        <td class="section-label">
                            Course and Speed
                        </td>

                        <td>
                            {{ $journal->course_speed ?: '—' }}
                        </td>
                    </tr>

                    <tr>
                        <td class="section-label">
                            F.O. ROB
                        </td>

                        <td>
                            {{ $journal->fo_rob ?: '—' }}
                        </td>

                        <td class="section-label">
                            F.O. DOB / LOB
                        </td>

                        <td>
                            DOB:
                            {{ $journal->fo_dob ?: '—' }}

                            &nbsp;&nbsp;|&nbsp;&nbsp;

                            LOB:
                            {{ $journal->fo_lob ?: '—' }}
                        </td>
                    </tr>
                @else
                    <tr>
                        <td class="section-label">
                            F.O. Consumption
                        </td>

                        <td>
                            {{ $journal->fo_cons ?: '—' }}
                        </td>

                        <td class="section-label">
                            D.O. Consumption
                        </td>

                        <td>
                            {{ $journal->do_cons ?: '—' }}
                        </td>
                    </tr>

                    <tr>
                        <td class="section-label">
                            Average RPM
                        </td>

                        <td>
                            {{ $journal->average_rpm ?: '—' }}
                        </td>

                        <td class="section-label">
                            Average Engine Speed
                        </td>

                        <td>
                            {{ $journal->average_speed ?: '—' }}
                        </td>
                    </tr>
                @endif

                <tr>
                    <td
                        class="section-label"
                        colspan="4"
                    >
                        @if ($department === 'DECK')
                            Bridge Watchkeeping Activities,
                            Specific Duties and Events During the
                            Watch
                        @else
                            Engine-Room Watchkeeping Activities,
                            Specific Duties and Events During the
                            Watch
                        @endif
                    </td>
                </tr>

                <tr>
                    <td colspan="4">
                        {!! nl2br(
                            e($decodeText($journal->activities))
                        ) !!}
                    </td>
                </tr>

                <tr>
                    <td
                        class="section-label"
                        colspan="4"
                    >
                        Key Areas Learned During the Watch
                    </td>
                </tr>

                <tr>
                    <td colspan="4">
                        {!! nl2br(
                            e($decodeText($journal->key_areas))
                        ) !!}
                    </td>
                </tr>

                @if (! empty($journal->file_name))
                    <tr>
                        <td class="section-label">
                            Objective Evidence
                        </td>

                        <td colspan="3">
                            @if (! empty($journal->gdrive_link))
                                @php
                                    $googleDriveValue = trim(
                                        (string) $journal->gdrive_link,
                                    );

                                    $driveLink = str_starts_with(
                                        $googleDriveValue,
                                        'http',
                                    )
                                        ? $googleDriveValue
                                        : 'https://drive.google.com/file/d/'.
                                            rawurlencode($googleDriveValue).
                                            '/view';
                                @endphp

                                <a
                                    class="evidence"
                                    href="{{ $driveLink }}"
                                >
                                    {{ $journal->file_name }}
                                </a>
                            @else
                                {{ $journal->file_name }}
                            @endif
                        </td>
                    </tr>
                @endif
            </table>

            <table class="signature-table">
                <tr>
                    <!-- Student signature -->

                    <td>
                        <div class="signature-area">
                            @if ($journal->student_signature)
                                <img
                                    class="signature-image"
                                    src="{{ $journal->student_signature }}"
                                    alt="Student signature"
                                >
                            @else
                                <div
                                    class="signature-placeholder"
                                ></div>
                            @endif
                        </div>

                        <div class="signature-line"></div>

                        <div class="signature-name">
                            {{ $studentName }}
                        </div>

                        <div class="signature-description">
                            Full Name and Signature of Student
                        </div>
                    </td>

                    <!-- Supervising officer signature -->

                    <td>
                        <div class="signature-area">
                            @if ($journal->officer_signature)
                                <img
                                    class="signature-image"
                                    src="{{ $journal->officer_signature }}"
                                    alt="Supervising officer signature"
                                >
                            @else
                                <div
                                    class="signature-placeholder"
                                ></div>
                            @endif
                        </div>

                        <div class="signature-line"></div>

                        <div class="signature-name">
                            {{ $journal->sto_name ?: '—' }}
                        </div>

                        <div class="signature-description">
                            Full Name and Signature of Supervising
                            Officer
                            <br>
                            (Master or Qualified Officer)
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach
</body>
</html>