<?php

namespace App\Modules\StudentsCourses;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class StudentService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
        private readonly CourseEnrollmentService $courses,
    ) {}

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $stages
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function list(
        string $sessionId,
        int $page,
        int $perPage,
        ?string $q,
        ?string $sort,
        string $direction,
        array $categories,
        array $stages,
        bool $internalExamNotPassed,
        bool $includeArchived,
    ): array {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'students.view');
        $query = DB::table('students')->where('organization_id', $visibility['membership']['organization_id']);

        if (! $visibility['unrestricted']) {
            if ($visibility['student_ids'] === []) {
                return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
            }
            $query->whereIn('id', $visibility['student_ids']);
        }
        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }
        if ($q !== null && trim($q) !== '') {
            $needle = '%'.mb_strtolower(trim($q)).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(contact_email_normalized, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$needle]);
            });
        }

        if ($categories !== [] || $stages !== []) {
            $query->whereExists(function ($sub) use ($categories, $stages): void {
                $sub->selectRaw('1')
                    ->from('course_enrollments as c')
                    ->join('driving_categories as d', 'd.id', '=', 'c.driving_category_id')
                    ->whereColumn('c.student_id', 'students.id')
                    ->whereColumn('c.organization_id', 'students.organization_id');
                if ($categories !== []) {
                    $sub->whereIn('d.code', $categories);
                }
                if ($stages !== []) {
                    $sub->whereIn('c.training_stage', $stages);
                }
            });
        }

        if ($internalExamNotPassed && Schema::hasTable('internal_exam_attempts')) {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('internal_exam_attempts as e')
                    ->whereColumn('e.student_id', 'students.id')
                    ->whereColumn('e.organization_id', 'students.organization_id')
                    ->where('e.status', 'passed');
            });
        }

        $sortMap = [
            'full_name' => 'last_name',
            'phone' => 'phone',
            'created_at' => 'created_at',
        ];
        if (in_array($sort, ['learning_identifier', 'internal_exam'], true)) {
            throw ResourceDomainException::rule('Requested sort depends on a later module that is not active yet.');
        }
        $sortColumn = $sortMap[$sort ?? 'full_name'] ?? 'last_name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortColumn, $direction)
            ->orderBy('first_name', $direction)
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        $studentIds = $rows
            ->pluck('id')
            ->map(static fn ($value): string => (string) $value)
            ->all();
        $coursesByStudent = [];
        if ($studentIds !== []) {
            $courseRows = DB::table('course_enrollments as c')
                ->join('driving_categories as d', 'd.id', '=', 'c.driving_category_id')
                ->where('c.organization_id', $visibility['membership']['organization_id'])
                ->whereIn('c.student_id', $studentIds)
                ->orderByDesc('c.started_at')
                ->orderByDesc('c.id')
                ->get([
                    'c.id',
                    'c.student_id',
                    'c.training_type',
                    'c.training_stage',
                    'c.cancelled_at',
                    'd.code as driving_category_code',
                ]);
            foreach ($courseRows as $course) {
                $studentKey = (string) $course->student_id;
                $coursesByStudent[$studentKey] ??= [];
                $coursesByStudent[$studentKey][] = [
                    'id' => (string) $course->id,
                    'training_type' => (string) $course->training_type,
                    'driving_category_code' => (string) $course->driving_category_code,
                    'training_stage' => (string) $course->training_stage,
                    'cancelled_at' => $course->cancelled_at === null ? null : (string) $course->cancelled_at,
                ];
            }
        }

        return [
            'data' => array_values($rows->map(function ($row) use ($coursesByStudent): array {
                return [
                    ...$this->present($row),
                    'courses_summary' => $coursesByStudent[(string) $row->id] ?? [],
                    'payments_summary' => (object) [],
                ];
            })->all()),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $studentId): array
    {
        $membership = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'students.view', $studentId);
        $row = DB::table('students')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $studentId)
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /** @return array<string,mixed> */
    public function preview(string $sessionId, string $studentId): array
    {
        $student = $this->get($sessionId, $studentId);

        return [
            ...$student,
            'courses_summary' => $this->courses->listForStudent($sessionId, $studentId),
            'payments_summary' => (object) [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission(
                $sessionId,
                $snapshot['organization_id'],
                'students.create',
            );

            if (($input['initial_license'] ?? null) !== null) {
                throw ResourceDomainException::rule('Initial license assignment belongs to the later Learning Access/Licenses slice.');
            }

            $this->assertLocation($actor['organization_id'], $input['location_id'] ?? null);
            [$ciphertext, $lookupHash] = $this->peselStorage($input['pesel'] ?? null);
            $noPesel = (bool) ($input['no_pesel'] ?? false);
            $birthDate = $this->nullableString($input['birth_date'] ?? null);
            $this->assertIdentityBranch($ciphertext, $lookupHash, $noPesel, $birthDate, false);
            $this->assertPeselAvailable($actor['organization_id'], $lookupHash, null);

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('students')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'first_name' => trim((string) $input['first_name']),
                'last_name' => trim((string) $input['last_name']),
                'birth_date' => $birthDate,
                'no_pesel_declared' => $noPesel,
                'pesel_ciphertext' => $ciphertext,
                'pesel_lookup_hash' => $lookupHash,
                'contact_email_normalized' => $this->nullableEmail($input['contact_email'] ?? null),
                'phone' => $this->nullableString($input['phone'] ?? null),
                'default_location_id' => $input['location_id'] ?? null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student.created', 'student', $id, $requestId,
                ['fields' => [], 'state' => 'absent', 'archived' => false],
                ['fields' => $this->auditFields($input), 'state' => 'active', 'archived' => false],
            );

            if (($input['initial_course'] ?? null) !== null) {
                if (! is_array($input['initial_course'])) {
                    throw ResourceDomainException::rule('Initial course payload must be an object.');
                }
                $this->courses->create($sessionId, $id, $input['initial_course'], $requestId);
            }

            return $this->present(DB::table('students')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function update(string $sessionId, string $studentId, array $input, string $requestId, ?string $expectedTag): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $studentId, $input, $requestId, $expectedTag): array {
            $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'students.edit', $studentId);
            $row = DB::table('students')
                ->where('organization_id', $snapshot['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);

            $this->assertLocation($actor['organization_id'], $input['location_id'] ?? $row->default_location_id);

            $nextNoPesel = array_key_exists('no_pesel', $input) ? (bool) $input['no_pesel'] : (bool) $row->no_pesel_declared;
            $nextBirthDate = array_key_exists('birth_date', $input)
                ? $this->nullableString($input['birth_date'])
                : ($row->birth_date === null ? null : (string) $row->birth_date);
            $nextCiphertext = $row->pesel_ciphertext === null ? null : (string) $row->pesel_ciphertext;
            $nextLookupHash = $row->pesel_lookup_hash === null ? null : (string) $row->pesel_lookup_hash;

            if (array_key_exists('pesel', $input)) {
                [$nextCiphertext, $nextLookupHash] = $this->peselStorage($input['pesel']);
            }
            if ($nextNoPesel) {
                $nextCiphertext = null;
                $nextLookupHash = null;
            }

            $hasFormalHistory = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_id', $studentId)
                ->exists();
            $this->assertIdentityBranch($nextCiphertext, $nextLookupHash, $nextNoPesel, $nextBirthDate, $hasFormalHistory);
            $this->assertPeselAvailable($actor['organization_id'], $nextLookupHash, $studentId);

            $updates = [];
            $map = [
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'phone' => 'phone',
                'contact_email' => 'contact_email_normalized',
                'location_id' => 'default_location_id',
            ];
            foreach ($map as $source => $target) {
                if (! array_key_exists($source, $input)) {
                    continue;
                }
                $value = $input[$source];
                if (in_array($source, ['first_name', 'last_name'], true)) {
                    $value = trim((string) $value);
                } elseif ($source === 'contact_email') {
                    $value = $this->nullableEmail($value);
                } elseif ($source === 'phone') {
                    $value = $this->nullableString($value);
                }
                $updates[$target] = $value;
            }
            if (array_key_exists('birth_date', $input) || array_key_exists('no_pesel', $input) || array_key_exists('pesel', $input)) {
                $updates['birth_date'] = $nextBirthDate;
                $updates['no_pesel_declared'] = $nextNoPesel;
                $updates['pesel_ciphertext'] = $nextCiphertext;
                $updates['pesel_lookup_hash'] = $nextLookupHash;
            }

            $changed = false;
            foreach ($updates as $column => $value) {
                $current = $row->{$column};
                if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                    $changed = true;
                    break;
                }
            }

            if ($changed) {
                $updates['version'] = (int) $row->version + 1;
                $updates['updated_at'] = now();
                DB::table('students')->where('id', $studentId)->update($updates);

                $this->auditOutbox->recordOrganizationEvent(
                    $actor['organization_id'], $actor['id'], $actor['user_id'],
                    'student.updated', 'student', $studentId, $requestId,
                    ['fields' => $this->auditFields($input), 'state' => $row->archived_at === null ? 'active' : 'archived', 'archived' => $row->archived_at !== null],
                    ['fields' => $this->auditFields($input), 'state' => $row->archived_at === null ? 'active' : 'archived', 'archived' => $row->archived_at !== null],
                );
            }

            return $this->present(DB::table('students')->where('id', $studentId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function archive(string $sessionId, string $studentId, string $requestId, ?string $reason): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'students.archive', $studentId);

        return DB::transaction(function () use ($actor, $studentId, $requestId, $reason): array {
            $row = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            if ($row->archived_at !== null) {
                return $this->present($row);
            }

            $openCourse = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_id', $studentId)
                ->whereNull('completed_at')
                ->whereNull('interrupted_at')
                ->whereNull('cancelled_at')
                ->exists();
            if ($openCourse) {
                throw ResourceDomainException::conflict('Student with an active course cannot be archived.');
            }

            DB::table('students')->where('id', $studentId)->update([
                'archived_at' => now(),
                'archived_by_user_id' => $actor['user_id'],
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student.archived', 'student', $studentId, $requestId,
                ['fields' => ['archived_at'], 'state' => 'active', 'archived' => false],
                ['fields' => ['archived_at'], 'state' => 'archived', 'archived' => true],
                $reason,
            );

            return $this->present(DB::table('students')->where('id', $studentId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function restore(string $sessionId, string $studentId, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'students.restore', $studentId);

        return DB::transaction(function () use ($actor, $studentId, $requestId): array {
            $row = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            if ($row->archived_at === null) {
                return $this->present($row);
            }

            DB::table('students')->where('id', $studentId)->update([
                'archived_at' => null,
                'archived_by_user_id' => null,
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student.restored', 'student', $studentId, $requestId,
                ['fields' => ['archived_at'], 'state' => 'archived', 'archived' => true],
                ['fields' => ['archived_at'], 'state' => 'active', 'archived' => false],
            );

            return $this->present(DB::table('students')->where('id', $studentId)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $student */
    public function etag(array $student): string
    {
        return '"v'.(int) $student['version'].'"';
    }

    /** @return array{0:?string,1:?string} */
    private function peselStorage(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [null, null];
        }
        $normalized = (string) preg_replace('/\D/', '', (string) $value);
        if (strlen($normalized) !== 11) {
            throw ResourceDomainException::rule('PESEL must contain 11 digits.');
        }
        $key = (string) config('app.key');
        if ($key === '') {
            throw ResourceDomainException::conflict('Application encryption key is unavailable.');
        }

        return [Crypt::encryptString($normalized), hash_hmac('sha256', $normalized, $key)];
    }

    private function assertIdentityBranch(
        ?string $ciphertext,
        ?string $lookupHash,
        bool $noPesel,
        ?string $birthDate,
        bool $formalIdentityRequired,
    ): void {
        if (($ciphertext === null) !== ($lookupHash === null)) {
            throw ResourceDomainException::rule('PESEL ciphertext and lookup identity must be changed atomically.');
        }
        if ($noPesel && ($ciphertext !== null || $birthDate === null)) {
            throw ResourceDomainException::rule('No-PESEL declaration requires birth date and forbids PESEL.');
        }
        if ($noPesel === false && $ciphertext !== null) {
            return;
        }
        if ($noPesel) {
            return;
        }
        if ($formalIdentityRequired) {
            throw ResourceDomainException::rule('Formal course history requires PESEL or explicit no-PESEL declaration with birth date.');
        }
    }

    private function assertPeselAvailable(string $organizationId, ?string $hash, ?string $ignoreId): void
    {
        if ($hash === null) {
            return;
        }
        $query = DB::table('students')
            ->where('organization_id', $organizationId)
            ->where('pesel_lookup_hash', $hash);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw ResourceDomainException::conflict('PESEL is already present in organization history.');
        }
    }

    private function assertLocation(string $organizationId, mixed $locationId): void
    {
        if ($locationId === null) {
            return;
        }
        if (! is_string($locationId) || ! DB::table('locations')
            ->where('organization_id', $organizationId)
            ->where('id', $locationId)
            ->whereNull('archived_at')
            ->exists()) {
            throw ResourceDomainException::rule('Student location must be an active location in the same organization.');
        }
    }

    private function assertExpectedVersion(object $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current Student version is required.');
        }
        $data = get_object_vars($row);
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) ($data['version'] ?? 0)) {
            throw ResourceDomainException::conflict('Student changed since it was loaded.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableEmail(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_strtolower($value);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function auditFields(array $input): array
    {
        $fields = [];
        foreach (array_keys($input) as $field) {
            if (in_array($field, ['pesel', 'no_pesel'], true)) {
                $fields[] = 'formal_identity';
            } elseif (! in_array($field, ['initial_course', 'initial_license'], true)) {
                $fields[] = (string) $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $data = get_object_vars($row);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'birth_date' => ($data['birth_date'] ?? null) === null ? null : (string) $data['birth_date'],
            'no_pesel' => (bool) ($data['no_pesel_declared'] ?? false),
            'pesel_masked' => ($data['pesel_ciphertext'] ?? null) === null ? null : '***********',
            'contact_email' => ($data['contact_email_normalized'] ?? null) === null ? null : (string) $data['contact_email_normalized'],
            'phone' => ($data['phone'] ?? null) === null ? null : (string) $data['phone'],
            'default_location_id' => ($data['default_location_id'] ?? null) === null ? null : (string) $data['default_location_id'],
            'archived_at' => ($data['archived_at'] ?? null) === null ? null : (string) $data['archived_at'],
            'created_at' => (string) ($data['created_at'] ?? ''),
            'version' => (int) ($data['version'] ?? 0),
        ];
    }
}
