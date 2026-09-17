<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionActivationController extends Controller
{
    public function __construct(private readonly DatatableService $datatableService)
    {
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->schoolConnection($request)->table('bs_course')
                ->orderBy('name_course')->get(['id', 'name_course']),
        ]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $data = $this->schoolConnection($request)->table('bs_topic')
            ->where('bs_course_id', $courseId)->orderBy('order_no')
            ->get(['id', 'desc_topic'])->map(fn ($row): array => [
                'id' => (string) $row->id,
                'desc_topic' => $this->decode((string) $row->desc_topic),
            ]);
        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $scope = $this->validateScope($request, $db);
        $query = $db->table('bs_quest')
            ->where('bs_topic_id', $scope['bs_topic_id'])
            ->where('bs_course_id', $scope['bs_course_id'])
            ->select(['id', 'quest_text', 'quest_img', 'level', 'active', 'for_item']);

        // Search plain and encoded text, including legacy andxx descriptions.
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $query->where(function ($where) use ($term): void {
                foreach (array_unique([$term, urlencode($term), urlencode(str_replace('&', 'andxx', $term))]) as $value) {
                    $where->orWhere('quest_text', 'like', '%'.$value.'%');
                }
            });
        }
        $paginationRequest = $request->duplicate();
        $paginationRequest->merge(['search' => '']);
        $result = $this->datatableService->paginate(
            query: $query,
            request: $paginationRequest,
            searchableColumns: [],
            sortableColumns: [
                'quest_text' => 'quest_text', 'level' => 'level',
                'active' => 'active', 'for_item' => 'for_item',
            ],
            defaultSortColumn: 'quest_text',
            defaultSortDirection: 'asc',
        );
        $result = $this->datatableService->addRowNumbers(response: $result, key: 'index');
        $records = collect($result['data'] ?? [])->map(fn ($row): array => (array) $row);
        $answers = $db->table('bs_quest_ans')->whereIn('bs_quest_id', $records->pluck('id')->all())
            ->orderBy('answer_text')->orderBy('id')
            ->get(['id', 'bs_quest_id', 'answer_text', 'filename', 'answer'])
            ->groupBy('bs_quest_id');

        $result['data'] = $records->map(function ($row) use ($answers, $request): array {
            $row['quest_text'] = $this->decode((string) $row['quest_text']);
            $row['level'] = (int) $row['level'];
            $row['image_url'] = $this->imageUrl($request, $row['quest_img'] ?? '');
            $row['options'] = collect($answers->get($row['id'], []))->values()->map(function ($answer, $index) use ($request): array {
                return [
                    'id' => (string) $answer->id,
                    'letter' => chr(65 + $index),
                    'text' => $this->decode((string) $answer->answer_text),
                    'correct' => $answer->answer === 'Y',
                    'image_url' => $this->imageUrl($request, $answer->filename ?? ''),
                ];
            })->all();
            // Include E, and expose all marked correct options if legacy data is inconsistent.
            $row['correct_answers'] = collect($row['options'])->where('correct', true)->pluck('letter')->all();
            return $row;
        })->values()->all();
        $result['summary'] = $this->summary($db, $scope);
        return response()->json($result);
    }

    public function update(Request $request, string $questionId): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $scope = $this->validateScope($request, $db);
        $data = $request->validate(['field' => ['required', 'in:active,for_item'], 'value' => ['required', 'in:Y,N']]);
        $query = $db->table('bs_quest')->where('id', $questionId)
            ->where('bs_topic_id', $scope['bs_topic_id'])->where('bs_course_id', $scope['bs_course_id']);
        abort_unless((clone $query)->exists(), 404, 'The question is not in the selected subject.');
        // Preserve last_update, matching legacy flag updates and avoiding shifted chart dates.
        $query->update([$data['field'] => $data['value']]);
        return response()->json(['message' => 'Question updated.', 'summary' => $this->summary($db, $scope)]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $scope = $this->validateScope($request, $db);
        $data = $request->validate([
            'field' => ['required', 'in:active,for_item'],
            'value' => ['required', 'in:Y,N'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $query = $db->table('bs_quest')
            ->where('bs_topic_id', $scope['bs_topic_id'])->where('bs_course_id', $scope['bs_course_id']);
        if ($data['field'] === 'for_item') {
            $query->where('level', 1);
        }
        // Search and pagination are intentionally not part of bulk scope.
        $changed = $query->update([$data['field'] => $data['value']]);
        return response()->json([
            'message' => $data['field'] === 'active'
                ? 'Activation updated for all questions in the selected subject.'
                : 'Item Analysis updated for Easy questions only in the selected subject.',
            'changed' => $changed,
            'summary' => $this->summary($db, $scope),
        ]);
    }

    private function summary(ConnectionInterface $db, array $scope): array
    {
        $row = $db->table('bs_quest')->where('bs_topic_id', $scope['bs_topic_id'])
            ->where('bs_course_id', $scope['bs_course_id'])
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(active = 'Y'), 0) AS active, COALESCE(SUM(for_item = 'Y'), 0) AS for_item, COALESCE(SUM(level = 1), 0) AS easy_total")
            ->first();
        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'for_item' => (int) $row->for_item,
            'easy_total' => (int) $row->easy_total,
        ];
    }

    private function validateScope(Request $request, ConnectionInterface $db): array
    {
        $data = $request->validate(['bs_course_id' => ['required', 'string'], 'bs_topic_id' => ['required', 'string']]);
        if (! $db->table('bs_course')->where('id', $data['bs_course_id'])->exists()
            || ! $db->table('bs_topic')->where('id', $data['bs_topic_id'])->where('bs_course_id', $data['bs_course_id'])->exists()) {
            throw ValidationException::withMessages(['bs_topic_id' => 'Select a valid subject belonging to the exam package.']);
        }
        return $data;
    }

    private function decode(string $text): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($text));
    }

    private function imageUrl(Request $request, string $filename): ?string
    {
        if ($filename === '') return null;
        $code = strtoupper(trim((string) $request->session()->get('school_code')));
        $base = config("schools.schools.{$code}.files.question_images_url");
        return $base ? rtrim((string) $base, '/').'/'.rawurlencode($filename) : null;
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
