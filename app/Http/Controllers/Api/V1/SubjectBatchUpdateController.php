<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectBatchUpdateController extends Controller
{
    private const SCHOOL_CATEGORY = '7f003d93-e079-11e8-8e51-0cc47a556ba7';

    public function options(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $admin = $this->isAdministrator($request);
        if (! $admin) $this->companyScope($request, $db);

        return response()->json([
            'is_administrator' => $admin,
            'companies' => $admin
                ? $db->table('company')->where('cat_company_id', self::SCHOOL_CATEGORY)
                    ->orderBy('company')->get(['id', 'company'])
                : [],
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $companyId = $this->companyScope($request, $db);
        return response()->json([
            // Preserve legacy empty-string scope; NULL is not treated as ''.
            'data' => $db->table('bs_course')->where('company_id', $companyId)
                ->orderBy('name_course')->get(['id', 'name_course'])
                ->map(fn ($row): array => ['id' => (string) $row->id, 'name_course' => $this->decode((string) $row->name_course)]),
        ]);
    }

    public function subjects(Request $request, string $courseId): JsonResponse
    {
        $db = $this->schoolConnection($request);
        $this->authorizedPackage($request, $db, $courseId);
        $data = $db->table('bs_topic')->where('bs_course_id', $courseId)
            ->orderBy('order_no')->orderBy('id')
            ->get(['id', 'desc_topic', 'order_no', 'no_quest', 'passing_mark'])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'desc_topic' => $this->decode((string) $row->desc_topic),
                'order_no' => $row->order_no === null ? null : (float) $row->order_no,
                'no_quest' => $row->no_quest === null ? null : (float) $row->no_quest,
                'passing_mark' => $row->passing_mark === null ? null : (float) $row->passing_mark,
            ]);
        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $courseId): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'subjects' => ['required', 'array', 'min:1', 'max:2000'],
            'subjects.*.id' => ['required', 'string', 'distinct'],
            'subjects.*.order_no' => ['required', 'integer', 'min:0', 'max:1000000'],
            'subjects.*.no_quest' => ['required', 'integer', 'min:0', 'max:100000'],
            'subjects.*.passing_mark' => ['required', 'numeric', 'min:0', 'max:100000'],
            'subjects.*.original' => ['required', 'array'],
            'subjects.*.original.order_no' => ['present', 'nullable', 'numeric'],
            'subjects.*.original.no_quest' => ['present', 'nullable', 'numeric'],
            'subjects.*.original.passing_mark' => ['present', 'nullable', 'numeric'],
        ]);
        foreach ($data['subjects'] as $index => $row) {
            if ((float) $row['passing_mark'] > (int) $row['no_quest']) {
                throw ValidationException::withMessages([
                    "subjects.{$index}.passing_mark" => 'Passing mark cannot exceed the question count.',
                ]);
            }
        }
        $db = $this->schoolConnection($request);
        $companyId = $this->companyScope($request, $db);
        $this->authorizedPackage($request, $db, $courseId);
        foreach (['bs_course', 'bs_topic'] as $table) {
            $engine = $db->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $db->getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE');
            if (strtoupper((string) $engine) !== 'INNODB') {
                throw ValidationException::withMessages([
                    'subjects' => "Table {$table} must use InnoDB for transactional batch updates. Ask your database administrator before changing table engines.",
                ]);
            }
        }

        $changed = $db->transaction(function () use ($db, $courseId, $companyId, $data): int {
            $package = $db->table('bs_course')->where('id', $courseId)
                ->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($package, 403, 'The package is no longer accessible.');
            $current = $db->table('bs_topic')->where('bs_course_id', $courseId)
                ->orderBy('id')->lockForUpdate()->get(['id', 'order_no', 'no_quest', 'passing_mark'])->keyBy('id');
            $incomingIds = collect($data['subjects'])->pluck('id')->sort()->values()->all();
            $existingIds = $current->keys()->sort()->values()->all();
            abort_unless($incomingIds === $existingIds, 409, 'The subject list changed. Reload the package before updating; no changes were saved.');

            // Verify every original value before applying any edits.
            foreach ($data['subjects'] as $row) {
                foreach (['order_no', 'no_quest', 'passing_mark'] as $field) {
                    $live = $current[$row['id']]->{$field};
                    $original = $row['original'][$field];
                    $same = ($live === null && $original === null)
                        || ($live !== null && $original !== null && (float) $live === (float) $original);
                    abort_unless($same, 409, 'A subject was edited by another user. Reload before updating; no changes were saved.');
                }
            }
            $changed = 0;
            foreach ($data['subjects'] as $row) {
                $changed += $db->table('bs_topic')->where('id', $row['id'])->where('bs_course_id', $courseId)->update([
                    'order_no' => (int) $row['order_no'],
                    'no_quest' => (int) $row['no_quest'],
                    'passing_mark' => (float) $row['passing_mark'],
                ]);
            }
            return $changed;
        });
        return response()->json(['message' => 'The subject batch has been saved.', 'changed' => $changed]);
    }

    private function authorizedPackage(Request $request, ConnectionInterface $db, string $courseId): void
    {
        $companyId = $this->companyScope($request, $db);
        abort_unless($db->table('bs_course')->where('id', $courseId)->where('company_id', $companyId)->exists(), 403, 'This exam package is not accessible in the selected company scope.');
    }

    /** Resolve role ONLY from trusted login state, never from request inputs. */
private function isAdministrator(Request $request): bool
{
    $user = $request->user();

    abort_unless($user, 401, 'Authentication is required.');

    $loginTypeId = trim(
        (string) $user->login_type_id,
    );

    abort_if(
        $loginTypeId === '',
        403,
        'This login has no assigned role.',
    );

    $role = $this->schoolConnection($request)
        ->table('login_type')
        ->where('id', $loginTypeId)
        ->value('code_type');

    abort_if(
        $role === null,
        403,
        'The assigned login role could not be found.',
    );

    return trim((string) $role) === 'Administrator';
}

    private function companyScope(Request $request, ConnectionInterface $db): string
    {
        if (! $this->isAdministrator($request)) {
            $id = trim((string) ($request->session()->get('company_id') ?? $request->user()?->company_id ?? ''));
            abort_if($id === '', 403, 'No company is assigned to this login.');
            return $id;
        }
        $validated = $request->validate(['company_id' => ['nullable', 'string']]);
        $id = trim((string) ($validated['company_id'] ?? ''));
        if ($id !== '' && ! $db->table('company')->where('id', $id)->where('cat_company_id', self::SCHOOL_CATEGORY)->exists()) {
            throw ValidationException::withMessages(['company_id' => 'Select a valid school/company.']);
        }
        return $id;
    }

    private function decode(string $value): string 
    {
        return str_replace(['andxx', 'apostrophexx'], ['&', "'"], urldecode($value));
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
