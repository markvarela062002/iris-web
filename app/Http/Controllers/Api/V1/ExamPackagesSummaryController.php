<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExamPackagesSummaryController extends Controller
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
        if (Gate::has('exam-packages-summary.view')) {
            Gate::forUser($request->user())->authorize('exam-packages-summary.view', [$code]);
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

        return response()->json(['packages' => $db->table('bs_course')->orderBy('name_course')->orderBy('id')->get(['id', 'name_course'])]);
    }

    public function subjects(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        $input = $request->validate(['bs_course_id' => ['nullable', 'string', 'max:36']]);
        if (! empty($input['bs_course_id'])) {
            abort_unless($db->table('bs_course')->where('id', $input['bs_course_id'])->exists(), 404, 'Exam package not found.');
        }
        // Clearing a package permits all subjects, matching the legacy onchange endpoint.
        $rows = $db->table('bs_topic as t')->join('bs_course as c', 'c.id', '=', 't.bs_course_id')
            ->when(! empty($input['bs_course_id']), fn ($query) => $query->where('t.bs_course_id', $input['bs_course_id']))
            ->orderBy('c.name_course')->orderBy('t.order_no')->orderBy('t.id')->limit(20001)
            ->get(['t.id', 't.desc_topic', 'c.name_course']);
        abort_if($rows->count() > 20000, 422, 'Select a package to narrow the subject list.');
        $rows->each(function ($row): void {
            $row->desc_topic = $this->decode($row->desc_topic);
            $row->label = $row->name_course.' — '.$row->desc_topic;
        });

        return response()->json(['data' => $rows]);
    }

    public function generate(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        $input = $request->validate([
            'rep_type' => ['required', 'integer', 'in:1,2'],
            'bs_course_id' => ['nullable', 'string', 'max:36'],
            'bs_topic_id' => ['nullable', 'string', 'max:36'],
        ]);

        $report = $db->transaction(function () use ($db, $input): array {
            $package = empty($input['bs_course_id']) ? null : $db->table('bs_course')->where('id', $input['bs_course_id'])->first(['id', 'name_course']);
            if (! empty($input['bs_course_id']) && ! $package) {
                throw ValidationException::withMessages(['bs_course_id' => 'Exam package not found.']);
            }
            $detailed = (int) $input['rep_type'] === 2;
            $subject = null;
            if ($detailed && ! empty($input['bs_topic_id'])) {
                $subject = $db->table('bs_topic')->where('id', $input['bs_topic_id'])->first(['id', 'bs_course_id', 'desc_topic']);
                if (! $subject || ($package && $subject->bs_course_id !== $package->id)) {
                    throw ValidationException::withMessages(['bs_topic_id' => 'Select a subject belonging to the selected exam package.']);
                }
            }
            // Never apply the subject filter to package totals.
            $marks = $db->table('bs_topic')
                ->when($package, fn ($query) => $query->where('bs_course_id', $package->id))
                ->select('bs_course_id')->selectRaw('SUM(no_quest) AS question_count, SUM(passing_mark) AS passing_mark')->groupBy('bs_course_id');
            $packages = $db->table('bs_course as c')->leftJoinSub($marks, 'marks', fn ($join) => $join->on('marks.bs_course_id', '=', 'c.id'))
                ->when($package, fn ($query) => $query->where('c.id', $package->id))
                ->orderBy('c.name_course')->orderBy('c.id')->limit(10001)
                ->get(['c.id', 'c.code_course', 'c.name_course', 'c.randomize', 'marks.question_count', 'marks.passing_mark']);
            abort_if($packages->count() > 10000, 422, 'Select a package to narrow this report.');
            $items = $packages->values()->map(function ($row, int $index): array {
                $total = (float) ($row->question_count ?? 0);
                $passing = (float) ($row->passing_mark ?? 0);

                return ['id' => $row->id, 'index' => $index + 1, 'code_course' => $row->code_course, 'name_course' => $row->name_course,
                    'question_count' => $total, 'passing_mark' => $passing, 'passing_pct' => $total > 0 ? round($passing / $total * 100, 0) : 0,
                    'randomize' => $row->randomize === 'Y' ? 'Yes' : 'No'];
            });
            $details = collect();
            if ($detailed && $packages->isNotEmpty()) {
                // Aggregate the question bank once; do not join raw questions to package marks.
                $bank = $db->table('bs_quest as q')->join('bs_topic as t', 't.id', '=', 'q.bs_topic_id')
                    ->when($package, fn ($query) => $query->where('t.bs_course_id', $package->id))
                    ->when($subject, fn ($query) => $query->where('t.id', $subject->id))
                    ->select('q.bs_topic_id')->selectRaw('COUNT(*) AS bank_count')
                    ->selectRaw("SUM(CASE WHEN q.active = 'Y' THEN 1 ELSE 0 END) AS active_count")->groupBy('q.bs_topic_id');
                $rows = $db->table('bs_topic as t')->join('bs_course as c', 'c.id', '=', 't.bs_course_id')
                    ->leftJoinSub($bank, 'bank', fn ($join) => $join->on('bank.bs_topic_id', '=', 't.id'))
                    ->when($package, fn ($query) => $query->where('c.id', $package->id))
                    ->when($subject, fn ($query) => $query->where('t.id', $subject->id))
                    ->orderBy('c.name_course')->orderBy('c.id')->orderBy('t.order_no')->orderBy('t.id')->limit(20001)
                    ->get(['t.id', 't.bs_course_id', 'c.code_course', 'c.name_course', 't.desc_topic', 't.order_no', 't.no_quest', 't.passing_mark', 'bank.active_count', 'bank.bank_count']);
                abort_if($rows->count() > 20000, 422, 'This detailed report exceeds 20,000 subjects. Select a package or subject.');
                $details = $rows->values()->map(fn ($row, int $index) => [
                    'id' => $row->id, 'index' => $index + 1, 'bs_course_id' => $row->bs_course_id, 'code_course' => $row->code_course,
                    'name_course' => $row->name_course, 'desc_topic' => $this->decode($row->desc_topic), 'order_no' => $row->order_no,
                    'no_quest' => (float) ($row->no_quest ?? 0), 'passing_mark' => (float) ($row->passing_mark ?? 0),
                    'active_count' => (int) ($row->active_count ?? 0), 'bank_count' => (int) ($row->bank_count ?? 0),
                ]);
            }

            return ['items' => $items, 'subjects' => $details, 'context' => [
                'rep_type' => (int) $input['rep_type'], 'package_name' => $package->name_course ?? 'All Exam Packages',
                'subject_name' => $subject ? $this->decode($subject->desc_topic) : 'All Subjects', 'generated_at' => now('Asia/Manila')->toIso8601String(),
            ], 'warnings' => [
                'Package totals use configured subject item counts and passing marks, not actual question-bank counts.',
                'Subject filters affect detailed rows only. Package totals always include all subjects; Summary ignores the Subject filter.',
            ], 'message' => 'Report generated. No database records were changed.'];
        });

        return response()->json($report);
    }
}
