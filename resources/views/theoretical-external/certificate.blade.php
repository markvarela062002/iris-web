<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <style>
        body {
            color: #111827;
            font-family: dejavusans, sans-serif;
            font-size: 10px;
        }

        .header {
            text-align: center;
        }

        .school-name {
            font-size: 18px;
            font-weight: bold;
        }

        .certificate-title {
            margin: 30px 0 18px;
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 6px;
            text-align: center;
        }

        .statement {
            font-size: 11px;
            line-height: 1.7;
            text-align: justify;
        }

        table {
            width: 100%;
            margin-top: 16px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 6px;
            border: 1px solid #334155;
        }

        th {
            background-color: #dbeafe;
            font-weight: bold;
            text-align: center;
        }

        .center {
            text-align: center;
        }

        .pass {
            color: #15803d;
            font-weight: bold;
        }

        .fail {
            color: #dc2626;
            font-weight: bold;
        }

        .signatures {
            width: 100%;
            margin-top: 35px;
            border: 0;
        }

        .signatures td {
            width: 50%;
            padding: 12px 20px;
            border: 0;
            vertical-align: top;
        }

        .signature-space {
            height: 38px;
        }

        .signature-line {
            padding-top: 4px;
            border-top: 1px solid #111827;
            font-weight: bold;
        }

        .designation {
            margin-top: 3px;
            font-size: 8px;
        }
    </style>
</head>

<body>
    @php
        $examineeName = trim(
            implode(' ', array_filter([
                $assessment->fname,
                $assessment->mname,
                $assessment->lname,
            ])),
        );

        $schoolName =
            $school['name'] ??
            config('app.name');
    @endphp

    <div class="header">
        <div class="school-name">
            {{ $schoolName }}
        </div>
    </div>

    <div class="certificate-title">
        CERTIFICATION
    </div>

    <p class="statement">
        This is to certify that, according to the records,
        <strong>{{ $examineeName }}</strong> has taken the
        <strong>{{ $assessment->name_course }}</strong>
        dated
        <strong>
            {{ \Illuminate\Support\Carbon::parse(
                $assessment->access_exp_date,
            )->format('M d, Y') }}
        </strong>
        with the following details:
    </p>

    <table>
        <thead>
            <tr>
                <th style="width: 7%">#</th>
                <th style="width: 48%">Items</th>
                <th style="width: 15%">Rating</th>
                <th style="width: 15%">Remarks</th>
                <th style="width: 15%">Date Taken</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($topics as $index => $topic)
                <tr>
                    <td class="center">
                        {{ $index + 1 }}
                    </td>

                    <td>
                        {{ $topic['description'] }}
                    </td>

                    <td class="center">
                        {{ number_format(
                            $topic['rating'],
                            1,
                        ) }}%
                    </td>

                    <td
                        class="center {{ $topic['remarks'] === 'PASS' ? 'pass' : 'fail' }}"
                    >
                        {{ $topic['remarks'] }}
                    </td>

                    <td class="center">
                        @if ($topic['date_taken'])
                            {{ \Illuminate\Support\Carbon::parse(
                                $topic['date_taken'],
                            )->format('M d, Y') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="5"
                        class="center"
                    >
                        No assessment topics are available.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="signature-space"></div>

                <div class="signature-line">
                    {{ $examineeName }}
                </div>

                <div class="designation">
                    External Examinee
                </div>
            </td>

            <td>
                <div>Validated by:</div>

                <div class="signature-space"></div>

                <div class="signature-line">
                    {{
                        $assessment->proctor_name
                        ?: 'Assessment Proctor'
                    }}
                </div>

                <div class="designation">
                    Assessor, {{ $schoolName }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>