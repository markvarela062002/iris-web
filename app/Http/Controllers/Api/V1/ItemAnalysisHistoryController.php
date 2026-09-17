<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemAnalysisHistoryController extends Controller
{
    private const VIEW_ABILITY = 'item-analysis-history.view';
    private const DELETE_ABILITY = 'item-analysis-history.delete';

    private function connection(Request $request): Connection
    {
        abort_unless($request->user(), 401);
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$code] ?? null) : null;
        abort_unless($code !== '' && is_array($school) && ! empty($school['connection']), 403, 'No valid school database has been selected.');
        abort_unless(hash_equals(strtoupper(trim((string) ($school['code'] ?? $code))), $code), 403, 'School configuration does not match the selected code.');
        $db = DB::connection($school['connection']);
        // Check the authenticated ID against the selected school, never a posted login ID.
        $login = $db->table('login')->where('id', $request->user()->getAuthIdentifier())->first(['id', 'active']);
        abort_unless($login && strtoupper(trim((string) $login->active)) === 'Y', 403, 'Your active account was not found in the selected school.');
        if (Gate::has(self::VIEW_ABILITY)) {
            Gate::forUser($request->user())->authorize(self::VIEW_ABILITY, [$code]);
        }

        return $db;
    }

    private function role(Connection $db, Request $request): ?object
    {
        return $db->table('login as l')->join('login_type as t', 't.id', '=', 'l.login_type_id')
            ->where('l.id', $request->user()->getAuthIdentifier())
            ->first(['t.code_type', 't.allow_delete']);
    }

    private function canDelete(Connection $db, Request $request): bool
    {
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        if (Gate::has(self::DELETE_ABILITY)) {
            return Gate::forUser($request->user())->allows(self::DELETE_ABILITY, [$code]);
        }
        // Conservative default. is_admin alone is NOT sufficient.
        $role = $this->role($db, $request);

        return $role && $role->code_type === 'Administrator'
            && strtoupper(trim((string) $role->allow_delete)) === 'Y';
    }

    private function historyQuery(Connection $db): Builder
    {
        return $db->table('bs_iar_history as h')
            ->leftJoin('login as l', 'l.id', '=', 'h.analyzed_by_id')
            ->leftJoin('bs_exam_session as s', 's.id', '=', 'h.bs_exam_session_id')
            ->leftJoin('bs_course as c', 'c.id', '=', 'h.bs_course_id')
            ->leftJoin('bs_topic as t', 't.id', '=', 'h.bs_topic_id')
            ->select(['h.id', 'h.date_analyzed', 'h.analyzed_by_id', 'h.bs_exam_session_id', 'h.bs_course_id', 'h.bs_topic_id', 'h.pct',
                'l.login_name as analyzed_by', 's.session_code', 'c.name_course', 'c.randomize', 't.desc_topic']);
    }

    private function decode(?string $value): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value ?? ''));
    }

    private function decodeHistory(object $row): object
    {
        $row->desc_topic = $this->decode($row->desc_topic);
        $row->pct = (float) $row->pct;

        return $row;
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->connection($request);

        return response()->json([
            'sessions' => $db->table('bs_exam_session')->orderByDesc('date_from')->get(['id', 'session_code']),
            'packages' => $db->table('bs_course')->orderBy('name_course')->get(['id', 'name_course']),
            'can_delete' => $this->canDelete($db, $request),
        ]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->connection($request);
        abort_unless($db->table('bs_course')->where('id', $courseId)->exists(), 404, 'Exam package not found.');
        $rows = $db->table('bs_topic')->where('bs_course_id', $courseId)->orderBy('order_no')->get(['id', 'desc_topic']);
        $rows->each(function ($row): void { $row->desc_topic = $this->decode($row->desc_topic); });

        return response()->json(['data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        $input = $request->validate([
            'bs_exam_session_id' => ['nullable', 'string', 'max:36'],
            'bs_course_id' => ['nullable', 'string', 'max:36'],
            'bs_topic_id' => ['nullable', 'string', 'max:36'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:10,20,50,100'],
            'sort_field' => ['nullable', 'string', 'in:date_analyzed,analyzed_by,session_code,name_course,desc_topic,pct'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);
        $query = $this->historyQuery($db);
        foreach (['bs_exam_session_id', 'bs_course_id', 'bs_topic_id'] as $field) {
            if (! empty($input[$field])) {
                $query->where('h.'.$field, $input[$field]);
            }
        }
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('l.login_name', 'like', '%'.$search.'%')
                    ->orWhere('s.session_code', 'like', '%'.$search.'%')
                    ->orWhere('c.name_course', 'like', '%'.$search.'%')
                    ->orWhere('t.desc_topic', 'like', '%'.urlencode(str_replace('&', 'andxx', $search)).'%')
                    ->orWhere('t.desc_topic', 'like', '%'.urlencode($search).'%');
            });
        }
        $sorts = ['date_analyzed' => 'h.date_analyzed', 'analyzed_by' => 'l.login_name', 'session_code' => 's.session_code',
            'name_course' => 'c.name_course', 'desc_topic' => 't.desc_topic', 'pct' => 'h.pct'];
        $page = $query->orderBy($sorts[$input['sort_field'] ?? 'date_analyzed'], $input['sort_direction'] ?? 'desc')
            ->orderBy('h.id')->paginate((int) ($input['per_page'] ?? 10));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->decodeHistory($row))->values(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'can_delete' => $this->canDelete($db, $request),
        ]);
    }

    public function show(Request $request, string $historyId): JsonResponse
    {
        $db = $this->connection($request);
        $request->validate(['metric_mode' => ['nullable', 'in:legacy,normalized']]);

        return response()->json($db->transaction(fn () => $this->report($db, $historyId, $request->query('metric_mode') ?: 'legacy')));
    }

    /** Recompute from current data, without UPDATEs or creating shared export files. */
    private function report(Connection $db, string $historyId, string $mode): array
    {
        $history = $this->historyQuery($db)->where('h.id', $historyId)->first();
        abort_unless($history, 404, 'History record not found.');
        $history = $this->decodeHistory($history);
        $pct = $history->pct;
        if (! is_finite($pct) || $pct <= 0 || $pct > 50) {
            throw ValidationException::withMessages(['pct' => 'Group percentage must be greater than 0 and at most 50 to avoid overlapping groups.']);
        }
        $attempts = $db->table('bs_person_exam_topic as t')->join('bs_person_exam as e', 'e.id', '=', 't.bs_person_exam_id')
            ->where('e.bs_exam_session_id', $history->bs_exam_session_id)
            ->where('e.bs_course_id', $history->bs_course_id)->where('t.bs_topic_id', $history->bs_topic_id)
            ->whereNotNull('t.score')->whereNotNull('e.person_id')->where('e.person_id', '<>', '')
            ->orderBy('t.score')->orderBy('e.person_id')->orderBy('t.id')->limit(20001)
            ->get(['t.id', 't.score', 'e.person_id']);
        abort_if($attempts->count() > 20000, 422, 'This report exceeds 20,000 attempts. Use a smaller exam session.');
        // The old code silently overwrote retakes in an unordered array. Do not guess a retake policy.
        if ($attempts->pluck('person_id')->unique()->count() !== $attempts->count()) {
            throw ValidationException::withMessages(['attempts' => 'Multiple subject attempts exist for a student. Confirm whether to use first, latest, or highest-scoring attempts before analyzing this history record.']);
        }
        $groupSize = (int) floor($attempts->count() * $pct / 100);
        $lowerIds = $attempts->take($groupSize)->pluck('id')->all();
        $upperIds = $groupSize > 0 ? $attempts->slice(-$groupSize)->pluck('id')->all() : [];
        $questions = $db->table('bs_quest')->where('bs_topic_id', $history->bs_topic_id)->where('active', 'Y')
            ->orderBy('quest_text')->orderBy('id')->limit(10001)->get(['id', 'quest_text']);
        abort_if($questions->count() > 10000, 422, 'This subject exceeds 10,000 active questions.');
        $aggregate = function (array $ids) use ($db, $history) {
            if ($ids === []) {
                return collect();
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
        $warnings = ['Recalculated from current exam data and currently active questions; this is not an immutable historical snapshot.',
            'Ties at group boundaries use student ID, then attempt ID, for deterministic selection.'];
        if ($groupSize === 0) {
            $warnings[] = 'Too few scored students for the selected group percentage. DF, DS, and decisions are unavailable.';
        }
        $warnings[] = $mode === 'legacy'
            ? 'Legacy mode retains percentage-scale DF/DS and the original remark thresholds, including their scale mismatch.'
            : 'Normalized mode uses 0–1 proportions for DF/DS. Values and classifications differ from the legacy report.';
        $items = $questions->values()->map(function ($question, $i) use ($upper, $lower, $groupSize, $mode): array {
            $u = $upper->get($question->id);
            $l = $lower->get($question->id);
            $uc = (int) ($u->correct_count ?? 0);
            $lc = (int) ($l->correct_count ?? 0);
            $appears = (int) ($u->appearances ?? 0) + (int) ($l->appearances ?? 0);
            $up = $groupSize > 0 ? $uc / $groupSize : null;
            $lp = $groupSize > 0 ? $lc / $groupSize : null;
            $available = $appears > 0 && $groupSize > 0;
            // Preserve the legacy blank DF/DS when no upper-group student answered correctly.
            $df = $available && ($mode !== 'legacy' || $uc > 0) ? (($up + $lp) / 2) * ($mode === 'legacy' ? 100 : 1) : null;
            $ds = $available && ($mode !== 'legacy' || $uc > 0) ? ($up - $lp) * ($mode === 'legacy' ? 100 : 1) : null;
            if ($mode === 'legacy') {
                // The original classified already-rounded values, coercing blank metrics to zero.
                $f = round($df ?? 0, 2);
                $s = round($ds ?? 0, 2);
                $difficulty = ! $available ? null : ($f >= 0 && $f <= .20 ? 'Very Difficult' : ($f >= .21 && $f <= .40 ? 'Difficult' : ($f >= .41 && $f <= .60 ? 'Average' : ($f >= .61 && $f <= .80 ? 'Easy' : ($f >= .11 && $f <= 1 ? 'Very Easy' : '')))));
                $discrimination = ! $available ? null : ($s >= 0 && $s <= .19 ? 'Poor' : ($s >= .20 && $s <= .29 ? 'Marginal' : ($s >= .30 && $s <= .39 ? 'Satisfactory' : ($s >= .40 ? 'High' : ''))));
                $decision = ! $available ? null : (in_array($discrimination, ['High', 'Satisfactory'], true) ? 'Retain' : ($discrimination === 'Marginal' ? 'Revise' : 'Reject'));
            } else {
                $difficulty = $df === null ? null : ($df <= .20 ? 'Very Difficult' : ($df <= .40 ? 'Difficult' : ($df <= .60 ? 'Average' : ($df <= .80 ? 'Easy' : 'Very Easy'))));
                $discrimination = $ds === null ? null : ($ds < .20 ? 'Poor' : ($ds < .30 ? 'Marginal' : ($ds < .40 ? 'Satisfactory' : 'High')));
                $decision = $ds === null ? null : ($ds >= .30 ? 'Retain' : ($ds >= .20 ? 'Revise' : 'Reject'));
            }
            $options = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                $key = 'option_'.$letter;
                $options[$letter] = ['upper' => (int) ($u->{$key} ?? 0), 'lower' => (int) ($l->{$key} ?? 0)];
            }

            return ['id' => $question->id, 'index' => $i + 1, 'question' => $this->decode($question->quest_text), 'appearances' => $appears,
                'upper_correct' => $uc, 'lower_correct' => $lc, 'upper_pct' => $up === null ? null : round($up * 100, 2),
                'lower_pct' => $lp === null ? null : round($lp * 100, 2), 'df' => $df === null ? null : round($df, 2),
                'df_remarks' => $difficulty, 'ds' => $ds === null ? null : round($ds, 2), 'ds_remarks' => $discrimination, 'decision' => $decision, 'options' => $options];
        });

        return ['history' => $history, 'summary' => ['students' => $attempts->count(), 'upper' => $groupSize, 'lower' => $groupSize,
            'metric_mode' => $mode, 'show_options' => $history->randomize === 'N', 'recalculated_at' => now('Asia/Manila')->toIso8601String()],
            'warnings' => $warnings, 'items' => $items];
    }

    public function export(Request $request, string $historyId): StreamedResponse
    {
        $db = $this->connection($request);
        $request->validate(['metric_mode' => ['nullable', 'in:legacy,normalized']]);
        $report = $db->transaction(fn () => $this->report($db, $historyId, $request->query('metric_mode') ?: 'legacy'));

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $write = static function (array $values) use ($stream): void {
                // Stop spreadsheet-formula injection in names, questions and other untrusted text.
                $values = array_map(static fn ($v) => is_string($v) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $v) ? "'".$v : $v, $values);
                fputcsv($stream, $values, ',', '"', '');
            };
            $h = $report['history'];
            $write(['Item Analysis History', $h->id]);
            $write(['Exam Session', $h->session_code, 'Exam Package', $h->name_course, 'Subject', $h->desc_topic]);
            $write(['Originally Analyzed', $h->date_analyzed, 'Analyzed By', $h->analyzed_by]);
            $write(['Group Percentage', $h->pct, 'Metric Mode', $report['summary']['metric_mode'], 'Recalculated At', $report['summary']['recalculated_at']]);
            foreach ($report['warnings'] as $warning) { $write(['Note', $warning]); }
            $write(['No.', 'Question', 'Appearances', 'Group', 'Correct', 'Percentage', 'DF', 'DF Remarks', 'DS', 'DS Remarks', 'Decision', 'A', 'B', 'C', 'D', 'E']);
            foreach ($report['items'] as $item) {
                foreach (['upper', 'lower'] as $group) {
                    $values = [$item['index'], $item['question'], $item['appearances'], ucfirst($group), $item[$group.'_correct'], $item[$group.'_pct'],
                        $item['df'], $item['df_remarks'], $item['ds'], $item['ds_remarks'], $item['decision']];
                    foreach (['A', 'B', 'C', 'D', 'E'] as $letter) { $values[] = $report['summary']['show_options'] ? $item['options'][$letter][$group] : null; }
                    $write($values);
                }
            }
            fclose($stream);
        }, 'item-analysis-'.$historyId.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, string $historyId): JsonResponse
    {
        $db = $this->connection($request);
        abort_unless($this->canDelete($db, $request), 403, 'You do not have permission to delete item-analysis history.');
        $role = $this->role($db, $request);
        $db->transaction(function () use ($db, $request, $historyId, $role): void {
            $record = $db->table('bs_iar_history')->where('id', $historyId)->lockForUpdate()->first();
            abort_unless($record, 404, 'History record not found.');
            $deleted = $db->table('bs_iar_history')->where('id', $historyId)->delete();
            abort_unless($deleted === 1, 409, 'The history record could not be deleted.');
            // If auditing fails, deletion rolls back as well. Related exam data is never deleted.
            $db->table('activity_log')->insert(['id' => (string) Str::uuid(), 'date_log' => now('Asia/Manila')->format('Y-m-d H:i:s'),
                'activity_desc' => 'Deleted item analysis history '.$historyId.'.', 'table_ref' => 'bs_iar_history', 'table_id' => $historyId,
                'login_id' => $request->user()->getAuthIdentifier(), 'user_type' => $role->code_type ?? '']);
        });

        return response()->json(['message' => 'History record deleted.']);
    }
}
