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

class PracticalAssessmentSetupController extends Controller
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

        $query = $db
            ->table('p_assess_h')
            ->leftJoin('rubrics', 'p_assess_h.rubrics_id', '=', 'rubrics.id')
            ->select([
                'p_assess_h.id',
                'p_assess_h.title_assess',
                'p_assess_h.instruction_assess',
                'p_assess_h.grade_system',
                'p_assess_h.rubrics_id',
                'p_assess_h.passing_mark',
                'p_assess_h.last_update',
                'rubrics.rubrics_name',
            ])
            ->selectSub(
                $db->table('p_assess_d')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('p_assess_d.p_assess_h_id', 'p_assess_h.id'),
                'total_items',
            )
            ->selectSub(
                $db->table('p_assess_h_file')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('p_assess_h_file.p_assess_h_id', 'p_assess_h.id'),
                'total_attachments',
            );

        $result = $this->datatableService->paginate(
            query: $query,
            request: $request,
            searchableColumns: [
                'p_assess_h.title_assess',
                'p_assess_h.grade_system',
                'rubrics.rubrics_name',
            ],
            sortableColumns: [
                'title_assess' => 'p_assess_h.title_assess',
                'grade_system' => 'p_assess_h.grade_system',
                'passing_mark' => 'p_assess_h.passing_mark',
                'last_update' => 'p_assess_h.last_update',
            ],
            defaultSortColumn: 'title_assess',
            defaultSortDirection: 'asc',
        );

        $result = $this->datatableService->addRowNumbers(
            response: $result,
            key: 'index',
        );

        $result['data'] = collect($result['data'] ?? [])
            ->map(function ($row): array {
                $record = is_object($row) ? get_object_vars($row) : (array) $row;
                $record['instruction_assess'] = $this->decodeText(
                    (string) ($record['instruction_assess'] ?? ''),
                );
                return $record;
            })
            ->values()
            ->all();

        return response()->json($result);
    }

    public function options(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        return response()->json([
            'data' => [
                'gradingSystems' => ['Checklist', 'Points', 'Rubrics'],
                'rubrics' => $db->table('rubrics')
                    ->orderBy('rubrics_name')
                    ->get(['id', 'rubrics_name']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }

        $validated = $this->validateAssessment($request, $db);
        $id = (string) Str::uuid();

        $db->table('p_assess_h')->insert([
            'id' => $id,
            'title_assess' => trim($validated['title_assess']),
            'dept' => trim((string) ($validated['dept'] ?? '')),
            'instruction_assess' => $this->encodeText(
                (string) ($validated['instruction_assess'] ?? ''),
            ),
            'grade_system' => $validated['grade_system'],
            'rubrics_id' => $validated['grade_system'] === 'Rubrics'
                ? $validated['rubrics_id']
                : '',
            'passing_mark' => $validated['passing_mark'],
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
            'prio_file' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(
            ['id' => $id, 'message' => 'The practical assessment has been created.'],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }

        $validated = $this->validateAssessment($request, $db);
        $db->table('p_assess_h')->where('id', $assessmentId)->update([
            'title_assess' => trim($validated['title_assess']),
            'dept' => trim((string) ($validated['dept'] ?? '')),
            'instruction_assess' => $this->encodeText(
                (string) ($validated['instruction_assess'] ?? ''),
            ),
            'grade_system' => $validated['grade_system'],
            'rubrics_id' => $validated['grade_system'] === 'Rubrics'
                ? $validated['rubrics_id']
                : '',
            'passing_mark' => $validated['passing_mark'],
            'login_id' => $this->resolveLoginId($request),
            'last_update' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'id' => $assessmentId,
            'message' => 'The practical assessment has been updated.',
        ]);
    }

    public function destroy(Request $request, string $assessmentId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }

        $hasChildren = $db->table('p_assess_d')
            ->where('p_assess_h_id', $assessmentId)->exists()
            || $db->table('p_assess_h_file')
                ->where('p_assess_h_id', $assessmentId)->exists();

        if ($hasChildren) {
            return response()->json(
                ['message' => 'Delete all items and attachments before deleting this assessment.'],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $db->table('p_assess_h')->where('id', $assessmentId)->delete();
        } catch (QueryException) {
            return response()->json(
                ['message' => 'The assessment cannot be deleted because it is in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['message' => 'The practical assessment has been deleted.']);
    }

    public function items(Request $request, string $assessmentId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }

        $schoolCode = $this->schoolCode($request);
        $baseUrl = $this->uploadsBaseUrl($schoolCode);
        $items = $db->table('p_assess_d')
            ->where('p_assess_h_id', $assessmentId)
            ->orderBy('prio')
            ->get(['id', 'p_assess_h_id', 'item_d', 'filename_d', 'point_d', 'prio'])
            ->map(function ($item) use ($baseUrl): object {
                $item->item_d = $this->decodeText((string) $item->item_d);
                $item->file_url = $this->fileUrl($baseUrl, (string) $item->filename_d);
                return $item;
            });

        return response()->json(['data' => $items]);
    }

    public function storeItem(Request $request, string $assessmentId): JsonResponse
    {
        return $this->saveItem($request, $assessmentId);
    }

    public function updateItem(
        Request $request,
        string $assessmentId,
        string $itemId,
    ): JsonResponse {
        return $this->saveItem($request, $assessmentId, $itemId);
    }

    private function saveItem(
        Request $request,
        string $assessmentId,
        ?string $itemId = null,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }
        if ($itemId !== null && ! $this->itemExists($db, $assessmentId, $itemId)) {
            return $this->notFound('The assessment item could not be found.');
        }

        $assessment = $db->table('p_assess_h')
            ->where('id', $assessmentId)
            ->first(['grade_system']);
        $rules = [
            'item_d' => ['required', 'string'],
            'filename_d' => ['nullable', 'string', 'max:255'],
            'point_d' => $assessment?->grade_system === 'Points'
                ? ['required', 'numeric', 'min:0']
                : ['nullable', 'numeric', 'min:0'],
            'prio' => ['required', 'integer', 'min:1'],
        ];
        $validated = $request->validate($rules);

        $duplicateOrder = $db->table('p_assess_d')
            ->where('p_assess_h_id', $assessmentId)
            ->where('prio', $validated['prio']);
        if ($itemId !== null) {
            $duplicateOrder->where('id', '!=', $itemId);
        }
        if ($duplicateOrder->exists()) {
            throw ValidationException::withMessages([
                'prio' => ['Order number already exists.'],
            ]);
        }

        $data = [
            'p_assess_h_id' => $assessmentId,
            'item_d' => $this->encodeText($validated['item_d']),
            'filename_d' => basename((string) ($validated['filename_d'] ?? '')),
            'point_d' => $validated['point_d'] ?? 0,
            'prio' => $validated['prio'],
        ];

        if ($itemId === null) {
            $itemId = (string) Str::uuid();
            $db->table('p_assess_d')->insert(['id' => $itemId, ...$data]);
            $message = 'The assessment item has been added.';
            $status = Response::HTTP_CREATED;
        } else {
            $db->table('p_assess_d')->where('id', $itemId)->update($data);
            $message = 'The assessment item has been updated.';
            $status = Response::HTTP_OK;
        }

        return response()->json(['id' => $itemId, 'message' => $message], $status);
    }

    public function destroyItem(
        Request $request,
        string $assessmentId,
        string $itemId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->itemExists($db, $assessmentId, $itemId)) {
            return $this->notFound('The assessment item could not be found.');
        }

        $db->table('p_assess_d')
            ->where('id', $itemId)
            ->where('p_assess_h_id', $assessmentId)
            ->delete();

        return response()->json(['message' => 'The assessment item has been deleted.']);
    }

    public function attachments(Request $request, string $assessmentId): JsonResponse
    {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }

        $baseUrl = $this->uploadsBaseUrl($this->schoolCode($request));
        $attachments = $db->table('p_assess_h_file')
            ->where('p_assess_h_id', $assessmentId)
            ->orderBy('prio_file')
            ->get(['id', 'p_assess_h_id', 'assess_h_file', 'prio_file'])
            ->map(function ($attachment) use ($baseUrl): object {
                $attachment->file_url = $this->fileUrl(
                    $baseUrl,
                    (string) $attachment->assess_h_file,
                );
                return $attachment;
            });

        return response()->json(['data' => $attachments]);
    }

    public function storeAttachment(
        Request $request,
        string $assessmentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        if (! $this->assessmentExists($db, $assessmentId)) {
            return $this->notFound('The practical assessment could not be found.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);
        $filename = $this->storeUpload($request);
        $id = (string) Str::uuid();
        $db->table('p_assess_h_file')->insert([
            'id' => $id,
            'p_assess_h_id' => $assessmentId,
            'assess_h_file' => $filename,
            'prio_file' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(
            ['id' => $id, 'filename' => $filename, 'message' => 'The attachment has been uploaded.'],
            Response::HTTP_CREATED,
        );
    }

    public function uploadItemFile(Request $request): JsonResponse
    {
        $connection = $this->resolveSchoolConnection($request);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);
        $filename = $this->storeUpload($request);
        $baseUrl = $this->uploadsBaseUrl($this->schoolCode($request));

        return response()->json([
            'filename' => $filename,
            'file_url' => $this->fileUrl($baseUrl, $filename),
            'message' => 'The file has been uploaded.',
        ]);
    }

    public function destroyAttachment(
        Request $request,
        string $assessmentId,
        string $attachmentId,
    ): JsonResponse {
        $db = $this->resolveSchoolConnection($request);
        if ($db instanceof JsonResponse) {
            return $db;
        }
        $deleted = $db->table('p_assess_h_file')
            ->where('id', $attachmentId)
            ->where('p_assess_h_id', $assessmentId)
            ->delete();
        if ($deleted === 0) {
            return $this->notFound('The attachment could not be found.');
        }

        return response()->json(['message' => 'The attachment has been removed.']);
    }

    private function validateAssessment(
        Request $request,
        ConnectionInterface $db,
    ): array {
        $validated = $request->validate([
            'title_assess' => ['required', 'string', 'max:255'],
            'dept' => ['nullable', 'string', 'max:255'],
            'instruction_assess' => ['nullable', 'string'],
            'grade_system' => ['required', 'in:Checklist,Points,Rubrics'],
            'rubrics_id' => ['nullable', 'required_if:grade_system,Rubrics', 'string'],
            'passing_mark' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if (
            $validated['grade_system'] === 'Rubrics'
            && ! $db->table('rubrics')->where('id', $validated['rubrics_id'])->exists()
        ) {
            throw ValidationException::withMessages([
                'rubrics_id' => ['The selected rubric is invalid.'],
            ]);
        }

        return $validated;
    }

    private function storeUpload(Request $request): string
    {
        $file = $request->file('file');
        $extension = strtolower($file->extension());
        $filename = Str::uuid().'.'.$extension;
        $disk = 'admapro_'.strtolower($this->schoolCode($request)).'_uploads';

        if (! is_array(config("filesystems.disks.{$disk}"))) {
            throw ValidationException::withMessages([
                'file' => ['The selected school upload storage is not configured.'],
            ]);
        }
        if (! Storage::disk($disk)->putFileAs('', $file, $filename)) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be uploaded.'],
            ]);
        }

        return $filename;
    }

    private function encodeText(string $value): string
    {
        return urlencode(str_replace(["&", "'"], ['andxx', 'apostrophexx'], trim($value)));
    }

    private function decodeText(string $value): string
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value));
    }

    private function fileUrl(string $baseUrl, string $filename): ?string
    {
        $filename = basename(trim($filename));
        return $baseUrl !== '' && $filename !== ''
            ? $baseUrl.'/'.rawurlencode($filename)
            : null;
    }

    private function uploadsBaseUrl(string $schoolCode): string
    {
        return rtrim(trim((string) config(
            "schools.schools.{$schoolCode}.files.uploads_url",
            '',
        )), '/');
    }

    private function assessmentExists(ConnectionInterface $db, string $id): bool
    {
        return $db->table('p_assess_h')->where('id', $id)->exists();
    }

    private function itemExists(
        ConnectionInterface $db,
        string $assessmentId,
        string $itemId,
    ): bool {
        return $db->table('p_assess_d')
            ->where('id', $itemId)
            ->where('p_assess_h_id', $assessmentId)
            ->exists();
    }

    private function schoolCode(Request $request): string
    {
        return strtoupper(trim((string) $request->session()->get('school_code', '')));
    }

    private function resolveLoginId(Request $request): ?string
    {
        $id = trim((string) $request->session()->get('login_id', ''));
        $authId = $request->user()?->getAuthIdentifier();
        return $id !== '' ? $id : ($authId === null ? null : (string) $authId);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }

    private function resolveSchoolConnection(
        Request $request,
    ): ConnectionInterface|JsonResponse {
        $schoolCode = $this->schoolCode($request);
        if ($schoolCode === '') {
            return response()->json(
                ['message' => 'No school has been selected.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $schools = config('schools.schools', []);
        $school = is_array($schools) ? ($schools[$schoolCode] ?? null) : null;
        if (! is_array($school)) {
            return response()->json(
                ['message' => 'The selected school is not configured.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $configuredCode = strtoupper(trim((string) ($school['code'] ?? $schoolCode)));
        if ($configuredCode === '' || ! hash_equals($configuredCode, $schoolCode)) {
            return response()->json(
                ['message' => 'The selected school code is invalid.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $connection = $school['connection'] ?? null;
        if (
            ! is_string($connection)
            || $connection === ''
            || ! is_array(config("database.connections.{$connection}"))
        ) {
            return response()->json(
                ['message' => 'The school database connection is unavailable.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);
        return DB::connection($connection);
    }
}
