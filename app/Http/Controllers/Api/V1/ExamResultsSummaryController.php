<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExamResultsSummaryController extends Controller
{
    private function connection(Request $request): Connection
    {
        abort_unless($request->user(), 401);
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$code] ?? null) : null;
        abort_unless($code !== '' && is_array($school) && ! empty($school['connection']), 403, 'No valid school database has been selected.');
        abort_unless(hash_equals(strtoupper(trim((string) ($school['code'] ?? $code))), $code), 403, 'School configuration does not match the selected code.');
        $db = DB::connection($school['connection']);
        $login = $db->table('login')->where('id', $request->user()->getAuthIdentifier())->first(['id', 'active']);
        abort_unless($login && strtoupper(trim((string) $login->active)) === 'Y', 403, 'Your active account was not found in the selected school.');
        if (Gate::has('exam-results-summary.view')) {
            Gate::forUser($request->user())->authorize('exam-results-summary.view', [$code]);
        }

        return $db;
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->connection($request);

        return response()->json(['packages' => $db->table('bs_course')->orderBy('name_course')->orderBy('id')->get(['id', 'name_course'])]);
    }

    private function cohort(Connection $db, array $input): Builder
    {
        // Legacy datetime values are treated as Philippine local time.
        $endExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $input['date_to'], 'Asia/Manila')->addDay()->format('Y-m-d H:i:s');

        return $db->table('bs_person_exam as e')
            ->where('e.started', '>=', $input['date_from'].' 00:00:00')
            ->where('e.started', '<', $endExclusive)
            ->when(! empty($input['bs_course_id']), fn (Builder $query) => $query->where('e.bs_course_id', $input['bs_course_id']));
    }

    public function generate(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        $input = $request->validate([
            'rep_type' => ['required', 'integer', 'in:1,2'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'bs_course_id' => ['nullable', 'string', 'max:36'],
        ]);
        if (! empty($input['bs_course_id']) && ! $db->table('bs_course')->where('id', $input['bs_course_id'])->exists()) {
            throw ValidationException::withMessages(['bs_course_id' => 'Exam package not found.']);
        }

        $report = $db->transaction(function () use ($db, $input): array {
            return (int) $input['rep_type'] === 1 ? $this->students($db, $input) : $this->packages($db, $input);
        });
        $report['context'] = [
            'rep_type' => (int) $input['rep_type'], 'date_from' => $input['date_from'], 'date_to' => $input['date_to'],
            'package_name' => empty($input['bs_course_id']) ? 'All Exam Packages' : $db->table('bs_course')->where('id', $input['bs_course_id'])->value('name_course'),
            'generated_at' => now('Asia/Manila')->toIso8601String(),
        ];
        $report['message'] = 'Report generated. No database records were changed.';

        return response()->json($report);
    }

    private function students(Connection $db, array $input): array
    {
        // Aggregate each relation before joining to avoid multiplying topic scores.
        $packageMarks = $db->table('bs_topic')->whereIn('bs_course_id', $this->cohort($db, $input)->select('e.bs_course_id'))
            ->select('bs_course_id')->selectRaw('SUM(passing_mark) AS passing_score, SUM(no_quest) AS question_count')->groupBy('bs_course_id');
        $scores = $db->table('bs_person_exam_topic')->whereIn('bs_person_exam_id', $this->cohort($db, $input)->select('e.id'))
            ->select('bs_person_exam_id')->selectRaw('SUM(score) AS total_score')->groupBy('bs_person_exam_id');
        $rows = $this->cohort($db, $input)
            ->leftJoin('person as p', 'p.id', '=', 'e.person_id')
            ->leftJoin('bs_course as c', 'c.id', '=', 'e.bs_course_id')
            ->leftJoinSub($packageMarks, 'marks', fn ($join) => $join->on('marks.bs_course_id', '=', 'e.bs_course_id'))
            ->leftJoinSub($scores, 'scores', fn ($join) => $join->on('scores.bs_person_exam_id', '=', 'e.id'))
            ->orderBy('e.started')->orderBy('p.lname')->orderBy('e.id')->limit(20001)
            ->get(['e.id', 'p.code_person', 'p.lname', 'p.fname', 'p.mname', 'c.name_course', 'e.started', 'e.exam_type', 'marks.passing_score', 'marks.question_count', 'scores.total_score']);
        abort_if($rows->count() > 20000, 422, 'This student report exceeds 20,000 attempts. Select a smaller date range or an exam package.');
        $warnings = [
            'Student List includes all exam attempts in the date range, including incomplete attempts and retakes.',
            'PASSED/FAILED compares summed subject scores with current package passing marks, not the stored passed flag. Package settings may have changed since the exam.',
        ];
        if ($rows->contains(fn ($row) => (float) ($row->question_count ?? 0) <= 0)) {
            $warnings[] = 'Some packages have zero or missing question totals. Their percentages are unavailable; PASSED/FAILED still follows the legacy score comparison.';
        }
        $items = $rows->values()->map(function ($row, int $index): array {
            $passing = (float) ($row->passing_score ?? 0);
            $score = (float) ($row->total_score ?? 0);
            $questions = (float) ($row->question_count ?? 0);
            $surname = trim((string) $row->lname);
            $given = trim(implode(' ', array_filter([$row->fname, $row->mname], fn ($value) => $value !== null && trim((string) $value) !== '')));

            return [
                'id' => $row->id, 'index' => $index + 1, 'code_person' => (string) ($row->code_person ?? ''),
                'student_name' => $surname.($surname !== '' && $given !== '' ? ', ' : '').$given,
                'name_course' => $row->name_course ?? 'Unknown package', 'started' => $row->started,
                'exam_type' => $row->exam_type, 'remarks' => $score >= $passing ? 'PASSED' : 'FAILED',
                'passing_pct' => $questions > 0 ? round($passing / $questions * 100, 1) : null,
                'grade_pct' => $questions > 0 ? round($score / $questions * 100, 1) : null,
            ];
        });

        return ['items' => $items, 'totals' => null, 'warnings' => $warnings];
    }

    private function packages(Connection $db, array $input): array
    {
        // EXISTS avoids duplicate counting when an attempt has many topic/response rows.
        $classified = $this->cohort($db, $input)->where('e.done', 'Y')->select('e.bs_course_id')
            ->selectRaw("CASE WHEN e.passed = 'Y' THEN 'passed' WHEN EXISTS (SELECT 1 FROM bs_person_exam_topic AS t INNER JOIN bs_person_exam_topic_quest AS r ON r.bs_person_exam_topic_id = t.id WHERE t.bs_person_exam_id = e.id) THEN 'failed' ELSE 'no_confirmation' END AS outcome");
        $counts = $db->query()->fromSub($classified, 'attempts')->select('bs_course_id')
            ->selectRaw('COUNT(*) AS attempt_count')
            ->selectRaw("SUM(CASE WHEN outcome = 'passed' THEN 1 ELSE 0 END) AS passed_count")
            ->selectRaw("SUM(CASE WHEN outcome = 'failed' THEN 1 ELSE 0 END) AS failed_count")
            ->selectRaw("SUM(CASE WHEN outcome = 'no_confirmation' THEN 1 ELSE 0 END) AS no_confirmation_count")
            ->groupBy('bs_course_id');
        $rows = $db->table('bs_course as c')->leftJoinSub($counts, 'counts', fn ($join) => $join->on('counts.bs_course_id', '=', 'c.id'))
            ->when(! empty($input['bs_course_id']), fn (Builder $query) => $query->where('c.id', $input['bs_course_id']))
            ->orderBy('c.name_course')->orderBy('c.id')->limit(10001)
            ->get(['c.id', 'c.name_course', 'counts.attempt_count', 'counts.passed_count', 'counts.failed_count', 'counts.no_confirmation_count']);
        abort_if($rows->count() > 10000, 422, 'This summary exceeds 10,000 exam packages. Select a package.');
        $items = $rows->values()->map(fn ($row, int $index) => array_merge([
            'id' => $row->id, 'index' => $index + 1, 'name_course' => $row->name_course,
        ], $this->counts((int) $row->attempt_count, (int) $row->passed_count, (int) $row->failed_count, (int) $row->no_confirmation_count, 1)));
        $totals = $this->counts($items->sum('attempt_count'), $items->sum('passed_count'), $items->sum('failed_count'), $items->sum('no_confirmation_count'), 2);

        return ['items' => $items, 'totals' => $totals, 'warnings' => [
            'Per Exam Package includes completed attempts only (done = Y). Counts are exam attempts, not distinct students; retakes are counted separately.',
            'Stored passed = Y means Passed. Otherwise, an attempt with question-response records is Failed; without them it is No Confirmation. These rules differ from Student List.',
            'Packages with no completed attempts remain listed. Overall percentages use total attempts, not an average of package percentages.',
        ]];
    }

    private function counts(int $total, int $passed, int $failed, int $unconfirmed, int $precision): array
    {
        return [
            'attempt_count' => $total, 'passed_count' => $passed, 'failed_count' => $failed, 'no_confirmation_count' => $unconfirmed,
            'passed_pct' => $total > 0 ? round($passed / $total * 100, $precision) : null,
            'failed_pct' => $total > 0 ? round($failed / $total * 100, $precision) : null,
            'no_confirmation_pct' => $total > 0 ? round($unconfirmed / $total * 100, $precision) : null,
        ];
    }
}
