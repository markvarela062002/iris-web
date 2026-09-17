<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DifficultyLevelController extends Controller
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
        if (Gate::has('difficulty-level.view')) {
            Gate::forUser($request->user())->authorize('difficulty-level.view', [$code]);
        }

        return $db;
    }

    private function decode(?string $value): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value ?? ''));
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->connection($request);

        return response()->json([
            'sessions' => $db->table('bs_exam_session')->orderByDesc('date_from')->get(['id', 'session_code']),
            'packages' => $db->table('bs_course')->orderBy('name_course')->get(['id', 'name_course']),
        ]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->connection($request);
        abort_unless($db->table('bs_course')->where('id', $courseId)->exists(), 404, 'Exam package not found.');
        $rows = $db->table('bs_topic')->where('bs_course_id', $courseId)->orderBy('order_no')->orderBy('id')->get(['id', 'desc_topic']);
        $rows->each(function ($row): void { $row->desc_topic = $this->decode($row->desc_topic); });

        return response()->json(['data' => $rows]);
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'bs_exam_session_id' => ['required', 'string', 'max:36'],
            'bs_course_id' => ['required', 'string', 'max:36'],
            'bs_topic_id' => ['required', 'string', 'max:36'],
            'pct' => ['required', 'integer', 'in:25,27,33'],
            'metric_mode' => ['required', 'in:legacy,normalized'],
        ]);
    }

    private function context(Connection $db, Request $request, array $input): object
    {
        $session = $db->table('bs_exam_session')->where('id', $input['bs_exam_session_id'])->first(['id', 'session_code']);
        $package = $db->table('bs_course')->where('id', $input['bs_course_id'])->first(['id', 'name_course', 'randomize']);
        $subject = $db->table('bs_topic')->where('id', $input['bs_topic_id'])->where('bs_course_id', $input['bs_course_id'])->first(['id', 'desc_topic']);
        if (! $session || ! $package || ! $subject) {
            throw ValidationException::withMessages(['bs_topic_id' => 'Select a valid session and a subject belonging to the selected exam package.']);
        }
        $login = $db->table('login')->where('id', $request->user()->getAuthIdentifier())->first(['login_name']);

        return (object) [
            'id' => null, 'bs_exam_session_id' => $session->id, 'bs_course_id' => $package->id, 'bs_topic_id' => $subject->id,
            'pct' => (float) $input['pct'], 'session_code' => $session->session_code, 'name_course' => $package->name_course,
            'desc_topic' => $this->decode($subject->desc_topic), 'randomize' => $package->randomize,
            'analyzed_by_id' => $request->user()->getAuthIdentifier(), 'analyzed_by' => $login->login_name,
            'date_analyzed' => now('Asia/Manila')->format('Y-m-d H:i:s'),
        ];
    }

    public function generate(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        $input = $this->input($request);
        // Read-only: membership is calculated in memory, never persisted globally.
        $report = $db->transaction(fn () => $this->calculate($db, $this->context($db, $request, $input), $input['metric_mode']));
        $report['message'] = 'Report generated. No history, question decisions, or student classifications were changed.';

        return response()->json($report);
    }

    private function calculate(Connection $db, object $history, string $mode): array
    {
        $pct = $history->pct;
        if (! is_finite($pct) || $pct <= 0 || $pct > 50) {
            throw ValidationException::withMessages(['pct' => 'Group percentage must be greater than 0 and at most 50 to avoid overlapping groups.']);
        }
        $attempts = $db->table('bs_person_exam_topic as t')->join('bs_person_exam as e', 'e.id', '=', 't.bs_person_exam_id')
            ->where('e.bs_exam_session_id', $history->bs_exam_session_id)
            ->where('e.bs_course_id', $history->bs_course_id)->where('t.bs_topic_id', $history->bs_topic_id)
            ->whereNotNull('t.score')->whereNotNull('e.person_id')->where('e.person_id', '<>', '')
            ->orderByRaw('CAST(t.score AS DECIMAL(15, 4))')->orderBy('e.person_id')->orderBy('t.id')->limit(20001)
            ->get(['t.id', 't.score', 'e.person_id']);
        abort_if($attempts->count() > 20000, 422, 'This report exceeds 20,000 attempts. Use a smaller exam session.');
        // The old code silently overwrote retakes in an unordered array. Do not guess a retake policy.
        if ($attempts->pluck('person_id')->unique()->count() !== $attempts->count()) {
            throw ValidationException::withMessages(['attempts' => 'Multiple subject attempts exist for a student. Confirm whether to use first, latest, or highest-scoring attempts before generating this report.']);
        }
        $groupSize = (int) floor($attempts->count() * $pct / 100);
        $lowerIds = $attempts->take($groupSize)->pluck('id')->all();
        $upperIds = $groupSize > 0 ? $attempts->slice(-$groupSize)->pluck('id')->all() : [];
        $questionQuery = $db->table('bs_quest')->where('bs_topic_id', $history->bs_topic_id)->where('active', 'Y')
            ->orderBy('quest_text')->orderBy('id')->limit(10001);
        $questions = $questionQuery->get(['id', 'quest_text']);
        abort_if($questions->count() > 10000, 422, 'This subject exceeds 10,000 active questions.');
        $aggregate = function (array $ids) use ($db, $history) {
            if ($ids === []) {
                return collect();
            }
            $duplicate = $db->table('bs_person_exam_topic_quest')->where('bs_exam_session_id', $history->bs_exam_session_id)
                ->whereIn('bs_person_exam_topic_id', $ids)->select(['bs_quest_id', 'bs_person_exam_topic_id'])
                ->groupBy('bs_quest_id', 'bs_person_exam_topic_id')->havingRaw('COUNT(*) > 1')->first();
            if ($duplicate) {
                throw ValidationException::withMessages(['responses' => 'Duplicate question responses exist within an attempt. Correct the source data before generating this analysis.']);
            }
            $q = $db->table('bs_person_exam_topic_quest')->where('bs_exam_session_id', $history->bs_exam_session_id)
                ->whereIn('bs_person_exam_topic_id', $ids)->select('bs_quest_id')
                ->selectRaw('COUNT(*) AS appearances')
                ->selectRaw("SUM(CASE WHEN TRIM(answer) <> '' AND TRIM(correct_ans) <> '' AND correct_ans = answer THEN 1 ELSE 0 END) AS correct_count");
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                $q->selectRaw('SUM(CASE WHEN answer = ? THEN 1 ELSE 0 END) AS option_'.$letter, [$letter]);
            }

            return $q->groupBy('bs_quest_id')->get()->keyBy('bs_quest_id');
        };
        $upper = $aggregate($upperIds);
        $lower = $aggregate($lowerIds);
        $warnings = ['Calculated from current exam data and active questions. This report is read-only and does not save analysis history.',
            'Ties at group boundaries use student ID, then attempt ID, for deterministic selection.'];
        if ($groupSize === 0) {
            $warnings[] = 'Too few scored students for the selected group percentage. Difficulty values are unavailable.';
        }
        $warnings[] = $mode === 'legacy'
            ? 'Legacy mode retains percentage-scale difficulty values and the original remark thresholds, including their scale mismatch.'
            : 'Normalized mode uses 0–1 proportions for difficulty values. Values and classifications differ from the legacy report.';
        $items = $questions->values()->map(function ($question, $i) use ($upper, $lower, $groupSize, $mode): array {
            $u = $upper->get($question->id);
            $l = $lower->get($question->id);
            $uc = (int) ($u->correct_count ?? 0);
            $lc = (int) ($l->correct_count ?? 0);
            $appears = (int) ($u->appearances ?? 0) + (int) ($l->appearances ?? 0);
            $up = $groupSize > 0 ? $uc / $groupSize : null;
            $lp = $groupSize > 0 ? $lc / $groupSize : null;
            $available = $appears > 0 && $groupSize > 0;
            // Preserve the legacy blank difficulty values when no upper-group student answered correctly.
            $df = $available && ($mode !== 'legacy' || $uc > 0) ? (($up + $lp) / 2) * ($mode === 'legacy' ? 100 : 1) : null;
            if ($mode === 'legacy') {
                // The original classified already-rounded values, coercing blank metrics to zero.
                $f = round($df ?? 0, 2);
                $difficulty = ! $available ? null : ($f >= 0 && $f <= .20 ? 'Very Difficult' : ($f >= .21 && $f <= .40 ? 'Difficult' : ($f >= .41 && $f <= .60 ? 'Average' : ($f >= .61 && $f <= .80 ? 'Easy' : ($f >= .11 && $f <= 1 ? 'Very Easy' : '')))));
            } else {
                $difficulty = $df === null ? null : ($df <= .20 ? 'Very Difficult' : ($df <= .40 ? 'Difficult' : ($df <= .60 ? 'Average' : ($df <= .80 ? 'Easy' : 'Very Easy'))));
            }
            $options = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                $key = 'option_'.$letter;
                $options[$letter] = ['upper' => (int) ($u->{$key} ?? 0), 'lower' => (int) ($l->{$key} ?? 0)];
            }

            return ['id' => $question->id, 'index' => $i + 1, 'question' => $this->decode($question->quest_text), 'appearances' => $appears,
                'upper_correct' => $uc, 'lower_correct' => $lc, 'upper_pct' => $up === null ? null : round($up * 100, 2),
                'lower_pct' => $lp === null ? null : round($lp * 100, 2), 'df' => $df === null ? null : round($df, 2),
                'df_remarks' => $difficulty, 'options' => $options];
        });

        return ['history' => $history, 'summary' => ['students' => $attempts->count(), 'upper' => $groupSize, 'lower' => $groupSize,
            'metric_mode' => $mode, 'show_options' => $history->randomize === 'N', 'recalculated_at' => now('Asia/Manila')->toIso8601String()],
            'warnings' => $warnings, 'items' => $items];
    }

}
