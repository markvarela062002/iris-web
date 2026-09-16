<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DatatableService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class QuestionBankController extends Controller
{
    public function __construct(
        private readonly DatatableService $datatableService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $query = $db->table('bs_quest')
            ->leftJoin('bs_course', 'bs_course.id', '=', 'bs_quest.bs_course_id')
            ->leftJoin('bs_topic', 'bs_topic.id', '=', 'bs_quest.bs_topic_id')
            ->select([
                'bs_quest.id',
                'bs_quest.quest_text',
                'bs_quest.bs_course_id',
                'bs_quest.bs_topic_id',
                'bs_quest.quest_img',
                'bs_quest.active',
                'bs_quest.validated',
                'bs_quest.last_update',
                'bs_course.name_course',
                'bs_topic.desc_topic',
            ]);

        if ($request->filled('course_id')) {
            $query->where('bs_quest.bs_course_id', (string) $request->input('course_id'));
        }
        if ($request->filled('topic_id')) {
            $query->where('bs_quest.bs_topic_id', (string) $request->input('topic_id'));
        }

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'bs_quest.quest_text',
                'bs_course.name_course',
                'bs_topic.desc_topic',
            ],
            sortableColumns: [
                'quest_text' => 'bs_quest.quest_text',
                'name_course' => 'bs_course.name_course',
                'desc_topic' => 'bs_topic.desc_topic',
                'active' => 'bs_quest.active',
                'validated' => 'bs_quest.validated',
                'last_update' => 'bs_quest.last_update',
            ],
            defaultSortColumn: 'last_update',
            defaultSortDirection: 'desc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        $result['data'] = collect($result['data'] ?? [])->map(function ($row) use ($db, $request): array {
            $record = is_object($row) ? get_object_vars($row) : (array) $row;
            $record['quest_text'] = $this->decodeLegacyText((string) ($record['quest_text'] ?? ''));
            $record['desc_topic'] = $this->decodeLegacyText((string) ($record['desc_topic'] ?? ''));
            $record['question_image_url'] = $this->imageUrl($request, $record['quest_img'] ?? null);
            $record['answers'] = $db->table('bs_quest_ans')
                ->where('bs_quest_id', $record['id'])
                ->orderBy('answer_text')
                ->limit(5)
                ->get(['id', 'answer_text', 'filename', 'answer'])
                ->map(fn ($answer): array => [
                    'id' => (string) $answer->id,
                    'answer_text' => $this->decodeLegacyText((string) $answer->answer_text),
                    'filename' => (string) ($answer->filename ?? ''),
                    'answer' => (string) ($answer->answer ?? 'N'),
                    'image_url' => $this->imageUrl($request, $answer->filename ?? null),
                ])->all();
            return $record;
        })->values()->all();

        return response()->json($result);
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $courses = $db->table('bs_course')->orderBy('name_course')->get(['id', 'name_course']);
        return response()->json(['courses' => $courses]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $subjects = $db->table('bs_topic')
            ->where('bs_course_id', $courseId)
            ->orderBy('order_no')
            ->get(['id', 'desc_topic'])
            ->map(fn ($topic): array => [
                'id' => (string) $topic->id,
                'desc_topic' => $this->decodeLegacyText((string) $topic->desc_topic),
            ]);

        return response()->json(['data' => $subjects]);
    }

    public function store(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $validated = $this->validateQuestion($request, $db);
        $id = (string) Str::uuid();

        $db->table('bs_quest')->insert($this->questionPayload($request, $validated, $id, true));

        return response()->json([
            'id' => $id,
            'message' => 'The question has been created.',
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $questionId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->questionExists($db, $questionId)) {
            return $this->notFound('The question could not be found.');
        }

        $validated = $this->validateQuestion($request, $db);
        $payload = $this->questionPayload($request, $validated, $questionId, false);
        unset($payload['id']);
        $db->table('bs_quest')->where('id', $questionId)->update($payload);

        return response()->json([
            'id' => $questionId,
            'message' => 'The question has been updated.',
        ]);
    }

    public function destroy(Request $request, string $questionId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->questionExists($db, $questionId)) {
            return $this->notFound('The question could not be found.');
        }

        try {
            $db->transaction(function () use ($db, $questionId): void {
                $db->table('bs_quest_ans')->where('bs_quest_id', $questionId)->delete();
                $db->table('bs_quest')->where('id', $questionId)->delete();
            });
        } catch (QueryException) {
            return response()->json(
                ['message' => 'The question cannot be deleted because it is already in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['message' => 'The question has been deleted.']);
    }

    public function answers(Request $request, string $questionId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->questionExists($db, $questionId)) {
            return $this->notFound('The question could not be found.');
        }

        $answers = $db->table('bs_quest_ans')
            ->where('bs_quest_id', $questionId)
            ->orderBy('answer_text')
            ->get(['id', 'bs_quest_id', 'answer_text', 'filename', 'answer'])
            ->map(fn ($answer): array => [
                'id' => (string) $answer->id,
                'bs_quest_id' => (string) $answer->bs_quest_id,
                'answer_text' => $this->decodeLegacyText((string) $answer->answer_text),
                'filename' => (string) ($answer->filename ?? ''),
                'answer' => (string) ($answer->answer ?? 'N'),
                'image_url' => $this->imageUrl($request, $answer->filename ?? null),
            ]);

        return response()->json(['data' => $answers]);
    }

    public function storeAnswer(Request $request, string $questionId): JsonResponse
    {
        return $this->saveAnswer($request, $questionId);
    }

    public function updateAnswer(
        Request $request,
        string $questionId,
        string $answerId,
    ): JsonResponse {
        return $this->saveAnswer($request, $questionId, $answerId);
    }

    private function saveAnswer(
        Request $request,
        string $questionId,
        ?string $answerId = null,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->questionExists($db, $questionId)) {
            return $this->notFound('The question could not be found.');
        }

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:5000'],
            'filename' => ['nullable', 'string', 'max:255'],
            'answer' => ['required', 'in:Y,N'],
        ]);
        $text = trim((string) ($validated['answer_text'] ?? ''));
        $filename = trim((string) ($validated['filename'] ?? ''));
        if ($text === '' && $filename === '') {
            throw ValidationException::withMessages([
                'answer_text' => 'Enter option text or upload an option image.',
            ]);
        }

        $query = $db->table('bs_quest_ans')->where('bs_quest_id', $questionId);
        if ($answerId === null && (clone $query)->count() >= 5) {
            throw ValidationException::withMessages([
                'answer_text' => 'A question can have a maximum of five options.',
            ]);
        }
        if ($answerId !== null && ! (clone $query)->where('id', $answerId)->exists()) {
            return $this->notFound('The option could not be found.');
        }

        if ($text !== '') {
            $duplicate = (clone $query)->where('answer_text', $this->encodeLegacyText($text));
            if ($answerId !== null) {
                $duplicate->where('id', '!=', $answerId);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages([
                    'answer_text' => 'This option already exists for the question.',
                ]);
            }
        }

        $id = $answerId ?? (string) Str::uuid();
        $db->transaction(function () use ($db, $questionId, $id, $answerId, $text, $filename, $validated): void {
            if ($validated['answer'] === 'Y') {
                $db->table('bs_quest_ans')->where('bs_quest_id', $questionId)->update(['answer' => 'N']);
            }
            $payload = [
                'bs_quest_id' => $questionId,
                'answer_text' => $this->encodeLegacyText($text),
                'filename' => $filename,
                'answer' => $validated['answer'],
            ];
            if ($answerId === null) {
                $db->table('bs_quest_ans')->insert(['id' => $id, ...$payload]);
            } else {
                $db->table('bs_quest_ans')->where('id', $id)->where('bs_quest_id', $questionId)->update($payload);
            }
        });

        return response()->json([
            'id' => $id,
            'message' => $answerId === null ? 'The option has been added.' : 'The option has been updated.',
        ], $answerId === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroyAnswer(
        Request $request,
        string $questionId,
        string $answerId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $deleted = $db->table('bs_quest_ans')
            ->where('id', $answerId)
            ->where('bs_quest_id', $questionId)
            ->delete();
        if ($deleted === 0) {
            return $this->notFound('The option could not be found.');
        }

        return response()->json(['message' => 'The option has been deleted.']);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $schoolCode = $this->schoolCode($request);
        if ($schoolCode instanceof JsonResponse) {
            return $schoolCode;
        }
        $diskName = 'admapro_'.strtolower($schoolCode).'_question_images';
        if (! config("filesystems.disks.{$diskName}")) {
            return response()->json([
                'message' => "Filesystem disk [{$diskName}] is not configured.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $file = $request->file('image');
            $filename = (string) Str::uuid().'.'.strtolower($file->extension());
            Storage::disk($diskName)->putFileAs('', $file, $filename);
        } catch (Throwable) {
            return response()->json(
                ['message' => 'The image could not be uploaded.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'filename' => $filename,
            'url' => $this->imageUrl($request, $filename),
            'message' => 'The image has been uploaded.',
        ], Response::HTTP_CREATED);
    }

    private function validateQuestion(Request $request, ConnectionInterface $db): array
    {
        $validated = $request->validate([
            'quest_text' => ['required', 'string', 'max:10000'],
            'bs_course_id' => ['required', 'string'],
            'bs_topic_id' => ['required', 'string'],
            'quest_img' => ['nullable', 'string', 'max:255'],
            'active' => ['required', 'in:Y,N'],
        ]);

        if (! $db->table('bs_course')->where('id', $validated['bs_course_id'])->exists()) {
            throw ValidationException::withMessages(['bs_course_id' => 'The selected exam package is invalid.']);
        }
        if (! $db->table('bs_topic')
            ->where('id', $validated['bs_topic_id'])
            ->where('bs_course_id', $validated['bs_course_id'])
            ->exists()) {
            throw ValidationException::withMessages(['bs_topic_id' => 'The selected subject does not belong to this exam package.']);
        }

        return $validated;
    }

    private function questionPayload(Request $request, array $validated, string $id, bool $creating): array
    {
        $payload = [
            'id' => $id,
            'quest_text' => $this->encodeLegacyText(trim($validated['quest_text'])),
            'bs_course_id' => $validated['bs_course_id'],
            'bs_topic_id' => $validated['bs_topic_id'],
            'quest_img' => trim((string) ($validated['quest_img'] ?? '')),
            'active' => $validated['active'],
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ];
        if ($creating) {
            $payload += [
                'prio' => 0,
                'choice_1' => '', 'choice_2' => '', 'choice_3' => '', 'choice_4' => '', 'choice_5' => '',
                'choice_1_wt' => 0, 'choice_2_wt' => 0, 'choice_3_wt' => 0, 'choice_4_wt' => 0, 'choice_5_wt' => 0,
                'ans_exp' => '', 'keyword' => '', 'quest_vid' => '', 'info' => '', 'bs_func' => '',
                'company_id' => '', 'level' => 0, 'for_item' => '', 'validated' => '',
                'decision' => '', 'revised_to_id' => '', 'revised_from_id' => '',
            ];
        }
        return $payload;
    }

    private function encodeLegacyText(string $value): string
    {
        return urlencode($value);
    }

    private function decodeLegacyText(string $value): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value));
    }

    private function imageUrl(Request $request, ?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }
        $schoolCode = $this->schoolCode($request);
        if ($schoolCode instanceof JsonResponse) {
            return null;
        }
        $base = config('schools.schools.'.strtolower($schoolCode).'.files.question_images_url')
            ?? config('schools.schools.'.strtoupper($schoolCode).'.files.question_images_url');
        return $base ? rtrim((string) $base, '/').'/'.rawurlencode($filename) : null;
    }

    private function questionExists(ConnectionInterface $db, string $id): bool
    {
        return $db->table('bs_quest')->where('id', $id)->exists();
    }

    private function resolveSchoolConnection(Request $request): ConnectionInterface|JsonResponse
    {
        $code = $this->schoolCode($request);
        if ($code instanceof JsonResponse) {
            return $code;
        }
        $schools = (array) config('schools.schools', []);
        $school = $schools[strtolower($code)] ?? $schools[$code] ?? null;
        $connection = is_array($school) ? ($school['connection'] ?? null) : null;
        if (! is_string($connection) || ! config("database.connections.{$connection}")) {
            return response()->json(['message' => 'The school database connection is not configured.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);
        return DB::connection($connection);
    }

    private function schoolCode(Request $request): string|JsonResponse
    {
        $code = strtoupper(trim((string) $request->session()->get('school_code', '')));
        if ($code === '') {
            return response()->json(['message' => 'No school is selected for this session.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        foreach ((array) config('schools.schools', []) as $key => $school) {
            $configured = strtoupper((string) (is_array($school) ? ($school['code'] ?? $key) : $key));
            if (hash_equals($configured, $code)) {
                return $code;
            }
        }
        return response()->json(['message' => 'The selected school is not configured.'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function resolveLoginId(Request $request): string
    {
        return (string) ($request->session()->get('login_id') ?? $request->user()?->getAuthIdentifier() ?? '');
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }
}
