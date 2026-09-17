<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionListController extends Controller
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
        // Preserve legacy authenticated reading unless a stricter application Gate is registered.
        if (Gate::has('question-list.view')) {
            Gate::forUser($request->user())->authorize('question-list.view', [$code]);
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

        return response()->json(['packages' => $db->table('bs_course')->orderBy('name_course')->get(['id', 'name_course'])]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->connection($request);
        abort_unless($db->table('bs_course')->where('id', $courseId)->exists(), 404, 'Exam package not found.');
        $subjects = $db->table('bs_topic')->where('bs_course_id', $courseId)->orderBy('order_no')->orderBy('id')->get(['id', 'desc_topic']);
        $subjects->each(function ($row): void { $row->desc_topic = $this->decode($row->desc_topic); });

        return response()->json(['data' => $subjects]);
    }

    private function filters(Request $request, Connection $db): array
    {
        $input = $request->validate([
            'bs_course_id' => ['required', 'string', 'max:36'],
            'bs_topic_id' => ['required', 'string', 'max:36'],
            'status' => ['nullable', 'in:Y,N'],
            'validated' => ['nullable', 'in:Y,N'],
            'decision' => ['nullable', 'in:Retain,Revise,Reject'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:10,20,50,100'],
            'sort_field' => ['nullable', 'in:quest_text,validated,decision'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $package = $db->table('bs_course')->where('id', $input['bs_course_id'])->first(['id', 'name_course']);
        $subject = $db->table('bs_topic')->where('id', $input['bs_topic_id'])
            ->where('bs_course_id', $input['bs_course_id'])->first(['id', 'desc_topic']);
        if (! $package || ! $subject) {
            throw ValidationException::withMessages(['bs_topic_id' => 'Select a subject that belongs to the selected exam package.']);
        }

        return [$input, ['package' => $package->name_course, 'subject' => $this->decode($subject->desc_topic)]];
    }

    private function query(Connection $db, array $input): Builder
    {
        // As in legacy: select questions by subject; package is validated through subject ownership.
        $query = $db->table('bs_quest as q')->where('q.bs_topic_id', $input['bs_topic_id'])
            ->select(['q.id', 'q.quest_text', 'q.active', 'q.validated', 'q.decision']);
        foreach (['status' => 'active', 'validated' => 'validated', 'decision' => 'decision'] as $parameter => $column) {
            if (! empty($input[$parameter])) {
                // Exact N matching, like legacy. NULL/blank is not silently included in the N filter.
                $query->where('q.'.$column, $input[$parameter]);
            }
        }
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('q.quest_text', 'like', '%'.urlencode($search).'%')
                    ->orWhere('q.quest_text', 'like', '%'.urlencode(str_replace('&', 'andxx', $search)).'%')
                    ->orWhere('q.quest_text', 'like', '%'.$search.'%');
            });
        }
        $sorts = ['quest_text' => 'q.quest_text', 'validated' => 'q.validated', 'decision' => 'q.decision'];

        return $query->orderBy($sorts[$input['sort_field'] ?? 'quest_text'], $input['sort_direction'] ?? 'asc')->orderBy('q.id');
    }

    /** Fetch options once per page/chunk instead of one query per question. */
    private function rows(Connection $db, Collection $questions, int $offset): Collection
    {
        if ($questions->isEmpty()) {
            return collect();
        }
        $answers = $db->table('bs_quest_ans')->whereIn('bs_quest_id', $questions->pluck('id')->all())
            ->orderBy('answer_text')->orderBy('id')->get(['id', 'bs_quest_id', 'answer_text', 'answer'])->groupBy('bs_quest_id');

        return $questions->values()->map(function ($q, $i) use ($answers, $offset): array {
            $options = $answers->get($q->id, collect())->values()->map(fn ($a) => [
                'id' => $a->id, 'text' => $this->decode($a->answer_text), 'correct' => $a->answer === 'Y',
            ]);
            $row = ['id' => $q->id, 'index' => $offset + $i + 1, 'quest_text' => $this->decode($q->quest_text),
                'active' => $q->active, 'validated' => $q->validated, 'marked' => $q->validated === 'Y' ? 'Validated' : 'Not Yet Validated',
                'decision' => $q->decision, 'option_count' => $options->count(), 'extra_options' => $options->slice(5)->values()->all()];
            for ($n = 1; $n <= 5; $n++) {
                $row['option_'.$n] = $options->get($n - 1); // Pad every missing option; no column misalignment.
            }

            return $row;
        });
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->connection($request);
        [$input, $context] = $this->filters($request, $db);
        $page = $this->query($db, $input)->paginate((int) ($input['per_page'] ?? 10));

        return response()->json([
            'data' => $this->rows($db, $page->getCollection(), ($page->currentPage() - 1) * $page->perPage()),
            'context' => $context,
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $db = $this->connection($request);
        [$input, $context] = $this->filters($request, $db);
        $query = $this->query($db, $input);

        return response()->streamDownload(function () use ($db, $query, $input, $context): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $write = static function (array $cells) use ($stream): void {
                $cells = array_map(static fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value, $cells);
                fputcsv($stream, $cells, ',', '"', '');
            };
            $write(['Questions List']);
            $write(['Exam Package', $context['package'], 'Subject', $context['subject']]);
            $write(['Status', $input['status'] ?? 'All', 'Mark', $input['validated'] ?? 'All', 'Decision', $input['decision'] ?? 'All']);
            $write(['Search', $input['search'] ?? '']);
            $write(['Note', 'Decisions are stored bs_quest.decision values, not recalculated by this report.']);
            $write(['No.', 'Question', 'Option 1', 'Option 2', 'Option 3', 'Option 4', 'Option 5', 'Marked', 'Decision', 'Correct Option Numbers', 'Extra Options']);
            $offset = 0;
            // Read-only consistent snapshot while exporting; chunked to avoid loading every question at once.
            $db->transaction(function () use ($db, $query, $write, &$offset): void {
                $query->chunk(500, function (Collection $questions) use ($db, $write, &$offset): void {
                    foreach ($this->rows($db, $questions, $offset) as $row) {
                        $cells = [$row['index'], $row['quest_text']];
                        $correct = [];
                        for ($n = 1; $n <= 5; $n++) {
                            $option = $row['option_'.$n];
                            $cells[] = $option['text'] ?? '';
                            if ($option['correct'] ?? false) { $correct[] = (string) $n; }
                        }
                        foreach ($row['extra_options'] as $i => $option) {
                            if ($option['correct']) { $correct[] = (string) ($i + 6); }
                        }
                        $cells[] = $row['marked']; $cells[] = $row['decision']; $cells[] = implode(', ', $correct);
                        $cells[] = $row['extra_options'] === [] ? '' : json_encode($row['extra_options'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $write($cells);
                    }
                    $offset += $questions->count();
                });
            });
            fclose($stream);
        }, 'questions-list-'.now('Asia/Manila')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
