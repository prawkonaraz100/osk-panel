<?php

namespace App\Modules\StudentsCourses;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentFinance\StudentFinanceService;
use App\Support\Security\SensitiveIdentifierCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CourseEnrollmentService
{
    private const NONTERMINAL_STAGES = [
        'unassigned',
        'theory',
        'practice',
        'documentation',
        'word_exam',
        'supplementary_training',
    ];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly TrainingRequirementService $requirements,
        private readonly AtomicAuditOutbox $auditOutbox,
        private readonly StudentFinanceService $studentFinance,
        private readonly SensitiveIdentifierCrypto $sensitiveIdentifiers,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForStudent(string $sessionId, string $studentId): array
    {
        $membership = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'courses.view', $studentId);

        return array_values(DB::table('course_enrollments')
            ->where('organization_id', $membership['organization_id'])
            ->where('student_id', $studentId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row): array => $this->present($row))
            ->all());
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $courseId): array
    {
        $membership = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.view', $courseId);
        $row = DB::table('course_enrollments')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $courseId)
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, string $studentId, array $input, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $studentId, $input, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission(
                $sessionId,
                $snapshot['organization_id'],
                'courses.create',
            );

            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($student === null || $student->archived_at !== null) {
                throw ResourceDomainException::notFound('Active Student is required for CourseEnrollment.');
            }
            if (! $this->studentHasFormalIdentity($student)) {
                throw ResourceDomainException::rule('Formal CourseEnrollment requires PESEL or explicit no-PESEL declaration with birth date.');
            }
            if (($input['import_existing_current_osk_hours'] ?? false) === true) {
                throw ResourceDomainException::rule('Opening-balance formal hours require the later Training Hour Ledger slice.');
            }

            $trainingType = $this->trainingType((string) $input['training_type']);
            $category = $this->categoryByCode((string) $input['driving_category_code']);
            $instructorId = (string) $input['lead_instructor_id'];
            $this->assertInstructor($actor['organization_id'], $instructorId);
            $locationId = $input['location_id'] ?? null;
            $this->assertLocation($actor['organization_id'], $locationId);

            $declaredTheory = (int) ($input['declared_theory_minutes'] ?? 0);
            $declaredPractical = (int) ($input['declared_practical_minutes'] ?? 0);
            if ($declaredTheory < 0 || $declaredPractical < 0) {
                throw ResourceDomainException::rule('Declared training minutes cannot be negative.');
            }

            [$pkkCiphertext, $pkkHash] = $this->pkkStorage($input['pkk_number'] ?? null);
            $id = (string) Str::uuid7();
            $now = now();

            DB::table('course_enrollments')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'student_id' => $studentId,
                'training_type' => $trainingType,
                'driving_category_id' => $category['id'],
                'started_at' => (string) $input['started_at'],
                'lead_instructor_id' => $instructorId,
                'location_id' => $locationId,
                'training_stage' => 'unassigned',
                'declared_theory_minutes' => $declaredTheory,
                'declared_practical_minutes' => $declaredPractical,
                'created_by_user_id' => $actor['user_id'],
                'version' => 1,
                'requirements_revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('course_requirement_contexts')->insert([
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $id,
                'state_theory_passed' => false,
                'evidence_reference' => null,
                'effective_from' => $now,
                'updated_by_user_id' => $actor['user_id'],
                'updated_at' => $now,
            ]);

            DB::table('pkk_profiles')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $id,
                'pkk_number_ciphertext' => $pkkCiphertext,
                'pkk_lookup_hash' => $pkkHash,
                'identity_revision' => 1,
                'bound_driving_category_id' => $category['id'],
                'bound_training_type' => $trainingType,
                'record_origin' => 'course_create',
                'recorded_at' => $now,
                'recorded_by_user_id' => $actor['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ([
                'theory' => (int) ($input['recognized_external_theory_minutes'] ?? 0),
                'practical' => (int) ($input['recognized_external_practical_minutes'] ?? 0),
            ] as $part => $minutes) {
                if ($minutes > 0) {
                    $this->insertExternal(
                        $actor['organization_id'],
                        $id,
                        $part,
                        $minutes,
                        'course_form_projection',
                        'course_form_initial',
                        null,
                        null,
                        'Initial course form projection',
                        $actor['user_id'],
                        (string) $category['id'],
                        $trainingType,
                    );
                }
            }

            $course = DB::table('course_enrollments')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (($input['initial_cost'] ?? null) !== null) {
                /** @var array<string,mixed> $initialCost */
                $initialCost = $input['initial_cost'];
                $this->studentFinance->createFromCourseCost($sessionId, $id, $initialCost, $requestId);
            }
            $this->requirements->recalculate($course, 1, 'course_create', $actor['user_id']);
            $this->appendHistory($course, 'created', null, 'active', null, 'unassigned', null, $actor['user_id']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.created', 'course_enrollment', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['training_type', 'driving_category', 'started_at', 'lead_instructor_id', 'location_id', 'training_stage', 'formal_identity'], 'state' => 'active'],
            );

            return $this->present(DB::table('course_enrollments')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function update(string $sessionId, string $courseId, array $input, string $requestId, ?string $expectedTag): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.edit', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $input, $requestId, $expectedTag): array {
            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'active') {
                throw ResourceDomainException::conflict('Terminal CourseEnrollment requires explicit correction mode.');
            }
            if (array_key_exists('initial_cost', $input)) {
                throw ResourceDomainException::rule('Initial course cost is create-time only; later pricing changes require an explicit Student Finance command.');
            }

            $updates = [];
            $requirementInputsChanged = false;
            $finalTrainingType = (string) $row->training_type;
            $finalCategoryId = (string) $row->driving_category_id;

            if (array_key_exists('training_type', $input)) {
                $finalTrainingType = $this->trainingType((string) $input['training_type']);
                $updates['training_type'] = $finalTrainingType;
                $requirementInputsChanged = $finalTrainingType !== (string) $row->training_type;
            }
            if (array_key_exists('driving_category_code', $input)) {
                $category = $this->categoryByCode((string) $input['driving_category_code']);
                $finalCategoryId = (string) $category['id'];
                $updates['driving_category_id'] = $finalCategoryId;
                $requirementInputsChanged = $requirementInputsChanged || $finalCategoryId !== (string) $row->driving_category_id;
            }
            if (array_key_exists('started_at', $input)) {
                $updates['started_at'] = (string) $input['started_at'];
                $requirementInputsChanged = $requirementInputsChanged || (string) $row->started_at !== (string) $input['started_at'];
            }
            if (array_key_exists('declared_theory_minutes', $input)) {
                $value = (int) $input['declared_theory_minutes'];
                if ($value < 0) {
                    throw ResourceDomainException::rule('Declared theory minutes cannot be negative.');
                }
                $updates['declared_theory_minutes'] = $value;
                if ($finalTrainingType === 'supplementary') {
                    $requirementInputsChanged = $requirementInputsChanged || $value !== (int) ($row->declared_theory_minutes ?? 0);
                }
            }
            if (array_key_exists('declared_practical_minutes', $input)) {
                $value = (int) $input['declared_practical_minutes'];
                if ($value < 0) {
                    throw ResourceDomainException::rule('Declared practical minutes cannot be negative.');
                }
                $updates['declared_practical_minutes'] = $value;
                if ($finalTrainingType === 'supplementary') {
                    $requirementInputsChanged = $requirementInputsChanged || $value !== (int) ($row->declared_practical_minutes ?? 0);
                }
            }
            if (array_key_exists('lead_instructor_id', $input)) {
                $this->assertInstructor($actor['organization_id'], (string) $input['lead_instructor_id']);
                $updates['lead_instructor_id'] = (string) $input['lead_instructor_id'];
            }
            if (array_key_exists('location_id', $input)) {
                $this->assertLocation($actor['organization_id'], $input['location_id']);
                $updates['location_id'] = $input['location_id'];
            }

            $contextChanged = $finalTrainingType !== (string) $row->training_type || $finalCategoryId !== (string) $row->driving_category_id;
            $pkkSupplied = array_key_exists('pkk_number', $input);
            $currentPkk = DB::table('pkk_profiles')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();
            if ($currentPkk === null) {
                throw ResourceDomainException::conflict('Current Course PKK identity is missing.');
            }

            if ($contextChanged && ! $pkkSupplied) {
                throw ResourceDomainException::conflict('Category or training type change requires explicit PKK context revalidation.');
            }

            $pkkChanged = false;
            $newPkkCiphertext = null;
            $newPkkHash = null;
            if ($pkkSupplied) {
                [$newPkkCiphertext, $newPkkHash, $normalizedPkk] = $this->pkkStorage($input['pkk_number']);
                $pkkChanged = ! $this->sensitiveIdentifiers->matchesStoredLookupHash(
                    $normalizedPkk,
                    (string) $currentPkk->pkk_lookup_hash,
                );
            }

            $changed = false;
            foreach ($updates as $column => $value) {
                if ((string) ($row->{$column} ?? '') !== (string) ($value ?? '')) {
                    $changed = true;
                    break;
                }
            }
            $changed = $changed || $pkkChanged || $contextChanged;
            if (! $changed) {
                return $this->present($row);
            }

            $nextVersion = (int) $row->version + 1;
            $nextRequirementsRevision = (int) $row->requirements_revision + ($requirementInputsChanged ? 1 : 0);
            $updates['version'] = $nextVersion;
            $updates['requirements_revision'] = $nextRequirementsRevision;
            $updates['updated_at'] = now();
            DB::table('course_enrollments')->where('id', $courseId)->update($updates);

            if ($pkkChanged || $contextChanged) {
                $now = now();
                DB::table('pkk_profiles')->where('id', $currentPkk->id)->update([
                    'superseded_at' => $now,
                    'superseded_by_user_id' => $actor['user_id'],
                    'updated_at' => $now,
                ]);
                DB::table('pkk_profiles')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'course_enrollment_id' => $courseId,
                    'pkk_number_ciphertext' => $pkkChanged ? $newPkkCiphertext : (string) $currentPkk->pkk_number_ciphertext,
                    'pkk_lookup_hash' => $pkkChanged ? $newPkkHash : (string) $currentPkk->pkk_lookup_hash,
                    'identity_revision' => (int) $currentPkk->identity_revision + 1,
                    'bound_driving_category_id' => $finalCategoryId,
                    'bound_training_type' => $finalTrainingType,
                    'record_origin' => $contextChanged ? 'context_revalidation' : 'course_edit',
                    'recorded_at' => $now,
                    'recorded_by_user_id' => $actor['user_id'],
                    'supersedes_pkk_profile_id' => (string) $currentPkk->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $updated = DB::table('course_enrollments')->where('id', $courseId)->lockForUpdate()->firstOrFail();
            if ($requirementInputsChanged) {
                $this->requirements->recalculate($updated, $nextVersion, 'course_update', $actor['user_id']);
            }
            if ($contextChanged) {
                $this->revalidateExternalContext(
                    $actor['organization_id'],
                    $updated,
                    $actor['user_id'],
                );
            }

            $this->appendHistory(
                $updated,
                'updated',
                'active',
                'active',
                (string) $row->training_stage,
                (string) $updated->training_stage,
                null,
                $actor['user_id'],
                (int) $row->version,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.updated', 'course_enrollment', $courseId, $requestId,
                ['fields' => $this->auditFields($input), 'state' => 'active'],
                ['fields' => $this->auditFields($input), 'state' => 'active'],
            );

            return $this->present($updated);
        });
    }

    /** @return array<string,mixed> */
    public function cancel(string $sessionId, string $courseId, string $reason, string $requestId, ?string $expectedTag): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.cancel', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $reason, $requestId, $expectedTag): array {
            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'active') {
                throw ResourceDomainException::conflict('Only an active CourseEnrollment can be cancelled.');
            }

            $nextVersion = (int) $row->version + 1;
            DB::table('course_enrollments')->where('id', $courseId)->update([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor['user_id'],
                'version' => $nextVersion,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();
            $this->appendHistory($updated, 'cancelled', 'active', 'cancelled', (string) $row->training_stage, (string) $row->training_stage, $reason, $actor['user_id'], (int) $row->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.cancelled', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['cancelled_at'], 'state' => 'active'],
                ['fields' => ['cancelled_at'], 'state' => 'cancelled'],
                $reason,
            );

            return $this->present($updated);
        });
    }

    /** @return array<string,mixed> */
    public function restore(string $sessionId, string $courseId, string $requestId, ?string $expectedTag): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.restore', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $requestId, $expectedTag): array {
            $courseSnapshot = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->first();
            if ($courseSnapshot === null) {
                throw ResourceDomainException::notFound();
            }

            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseSnapshot->student_id)
                ->lockForUpdate()
                ->first();
            if ($student === null || $student->archived_at !== null || ! $this->studentHasFormalIdentity($student)) {
                throw ResourceDomainException::conflict('Course restore requires a current formal Student identity.');
            }

            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'cancelled') {
                throw ResourceDomainException::conflict('Normal restore is allowed only for a cancelled CourseEnrollment.');
            }

            DB::table('course_enrollments')->where('id', $courseId)->update([
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();
            $this->appendHistory($updated, 'restored', 'cancelled', 'active', (string) $row->training_stage, (string) $row->training_stage, null, $actor['user_id'], (int) $row->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.restored', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['cancelled_at'], 'state' => 'cancelled'],
                ['fields' => ['cancelled_at'], 'state' => 'active'],
            );

            return $this->present($updated);
        });
    }

    /** @return array<string,mixed> */
    public function changeStage(string $sessionId, string $courseId, string $targetStage, ?string $reason, string $requestId, ?string $expectedTag): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.stage.change', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $targetStage, $reason, $requestId, $expectedTag): array {
            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'active') {
                throw ResourceDomainException::conflict('Training stage can be changed only for an active CourseEnrollment.');
            }
            if ($targetStage === (string) $row->training_stage) {
                return $this->present($row);
            }
            if ($targetStage === 'training_completed') {
                throw ResourceDomainException::conflict('Course completion remains closed until training-hour and internal-exam evidence slices are active.');
            }
            if (! in_array($targetStage, self::NONTERMINAL_STAGES, true)) {
                throw ResourceDomainException::rule('Unsupported training stage.');
            }

            DB::table('course_enrollments')->where('id', $courseId)->update([
                'training_stage' => $targetStage,
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();
            $this->appendHistory($updated, 'stage_changed', 'active', 'active', (string) $row->training_stage, $targetStage, $reason, $actor['user_id'], (int) $row->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.stage_changed', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['training_stage'], 'state' => (string) $row->training_stage],
                ['fields' => ['training_stage'], 'state' => $targetStage],
                $reason,
            );

            return $this->present($updated);
        });
    }

    /** @return array<string,mixed> */
    public function currentRequirements(string $sessionId, string $courseId): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.view', $courseId);

        return $this->requirements->current($actor['organization_id'], $courseId);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function updateRequirementContext(string $sessionId, string $courseId, array $input, string $requestId, ?string $expectedTag): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'course_requirements.correct', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $input, $requestId, $expectedTag): array {
            $allowed = ['state_theory_passed', 'evidence_reference', 'held_categories'];
            $unknown = array_values(array_diff(array_keys($input), $allowed));
            if ($unknown !== []) {
                throw ResourceDomainException::rule('Unsupported requirement context fields: '.implode(', ', $unknown));
            }

            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'active') {
                throw ResourceDomainException::conflict('Terminal CourseEnrollment requirement change requires correction mode.');
            }

            $context = DB::table('course_requirement_contexts')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->firstOrFail();

            $stateTheoryPassed = array_key_exists('state_theory_passed', $input)
                ? (bool) $input['state_theory_passed']
                : (bool) $context->state_theory_passed;
            $evidence = array_key_exists('evidence_reference', $input)
                ? $this->nullableString($input['evidence_reference'])
                : ($context->evidence_reference === null ? null : (string) $context->evidence_reference);

            $heldIds = DB::table('course_requirement_context_held_categories')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->pluck('driving_category_id')
                ->map(static fn ($v): string => (string) $v)
                ->all();
            if (array_key_exists('held_categories', $input)) {
                $heldIds = $this->categoryIdsByCodes((array) $input['held_categories']);
            }

            $currentHeld = DB::table('course_requirement_context_held_categories')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->orderBy('driving_category_id')
                ->pluck('driving_category_id')
                ->map(static fn ($v): string => (string) $v)
                ->all();
            $nextHeld = $heldIds;
            sort($currentHeld);
            sort($nextHeld);

            $changed = $stateTheoryPassed !== (bool) $context->state_theory_passed
                || (string) ($evidence ?? '') !== (string) ($context->evidence_reference ?? '')
                || $currentHeld !== $nextHeld;
            if (! $changed) {
                return $this->requirements->current($actor['organization_id'], $courseId);
            }

            DB::table('course_requirement_contexts')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->update([
                    'state_theory_passed' => $stateTheoryPassed,
                    'evidence_reference' => $evidence,
                    'effective_from' => now(),
                    'updated_by_user_id' => $actor['user_id'],
                    'updated_at' => now(),
                ]);
            DB::table('course_requirement_context_held_categories')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->delete();
            foreach ($nextHeld as $categoryId) {
                DB::table('course_requirement_context_held_categories')->insert([
                    'organization_id' => $actor['organization_id'],
                    'course_enrollment_id' => $courseId,
                    'driving_category_id' => $categoryId,
                ]);
            }

            DB::table('course_enrollments')->where('id', $courseId)->update([
                'version' => (int) $row->version + 1,
                'requirements_revision' => (int) $row->requirements_revision + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->lockForUpdate()->firstOrFail();
            $profile = $this->requirements->recalculate($updated, (int) $updated->version, 'requirement_context_update', $actor['user_id']);
            $this->appendHistory($updated, 'updated', 'active', 'active', (string) $row->training_stage, (string) $row->training_stage, null, $actor['user_id'], (int) $row->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.requirements.updated', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['requirement_context'], 'state' => 'active'],
                ['fields' => ['requirement_context'], 'state' => 'active'],
            );

            return $profile;
        });
    }

    /** @return array<string,mixed> */
    public function addExemptionDecision(
        string $sessionId,
        string $courseId,
        string $basisCode,
        ?string $evidenceReference,
        string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        if ($basisCode !== 'art_23a') {
            throw ResourceDomainException::rule('Unsupported exemption basis. Current verified runtime basis is art_23a.');
        }
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'course_requirements.correct', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $basisCode, $evidenceReference, $reason, $requestId, $expectedTag): array {
            $row = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($row, $expectedTag);
            if ($this->lifecycleState($row) !== 'active') {
                throw ResourceDomainException::conflict('Terminal CourseEnrollment exemption change requires correction mode.');
            }

            $current = DB::table('course_exemption_decisions')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($current !== null && (string) $current->basis_code === $basisCode
                && (string) ($current->evidence_reference ?? '') === (string) ($evidenceReference ?? '')) {
                return $this->requirements->current($actor['organization_id'], $courseId);
            }
            if ($current !== null) {
                DB::table('course_exemption_decisions')->where('id', $current->id)->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor['user_id'],
                    'revocation_reason' => $reason,
                ]);
            }

            $ruleVersion = $this->requirements->current($actor['organization_id'], $courseId)['rule_set_version'];
            DB::table('course_exemption_decisions')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $courseId,
                'basis_code' => $basisCode,
                'evidence_reference' => $this->nullableString($evidenceReference),
                'reason' => $reason,
                'approved_by_user_id' => $actor['user_id'],
                'rule_set_version' => $ruleVersion,
                'created_at' => now(),
            ]);

            DB::table('course_enrollments')->where('id', $courseId)->update([
                'version' => (int) $row->version + 1,
                'requirements_revision' => (int) $row->requirements_revision + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->lockForUpdate()->firstOrFail();
            $profile = $this->requirements->recalculate($updated, (int) $updated->version, 'exemption_decision', $actor['user_id']);
            $this->appendHistory($updated, 'updated', 'active', 'active', (string) $row->training_stage, (string) $row->training_stage, null, $actor['user_id'], (int) $row->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.exemption.updated', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['exemption_basis'], 'state' => 'active'],
                ['fields' => ['exemption_basis'], 'state' => 'active'],
                $reason,
            );

            return $profile;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listExternalTraining(string $sessionId, string $courseId): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'courses.view', $courseId);

        return array_values(DB::table('recognized_external_training')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => $this->presentExternal($row))
            ->all());
    }

    /** @return array<string,mixed> */
    public function recognizeExternalTraining(
        string $sessionId,
        string $courseId,
        string $trainingPart,
        int $recognizedMinutes,
        ?string $sourceSchoolReference,
        ?string $evidenceReference,
        string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        if (! in_array($trainingPart, ['theory', 'practical'], true) || $recognizedMinutes <= 0) {
            throw ResourceDomainException::rule('External training requires theory/practical part and positive minutes.');
        }
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'external_training.recognize', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $trainingPart, $recognizedMinutes, $sourceSchoolReference, $evidenceReference, $reason, $requestId, $expectedTag): array {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($course, $expectedTag);
            if ($this->lifecycleState($course) !== 'active') {
                throw ResourceDomainException::conflict('External training can be recognized only for an active CourseEnrollment in normal flow.');
            }

            $id = $this->insertExternal(
                $actor['organization_id'],
                $courseId,
                $trainingPart,
                $recognizedMinutes,
                'documented_transfer',
                'documented_transfer',
                $sourceSchoolReference,
                $evidenceReference,
                $reason,
                $actor['user_id'],
                (string) $course->driving_category_id,
                (string) $course->training_type,
            );

            DB::table('course_enrollments')->where('id', $courseId)->update([
                'version' => (int) $course->version + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();
            $this->appendHistory($updated, 'updated', 'active', 'active', (string) $course->training_stage, (string) $course->training_stage, null, $actor['user_id'], (int) $course->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.external_training.recognized', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['recognized_external_training'], 'state' => 'active'],
                ['fields' => ['recognized_external_training'], 'state' => 'active'],
                $reason,
            );

            return $this->presentExternal(DB::table('recognized_external_training')->where('id', $id)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function revokeExternalTraining(
        string $sessionId,
        string $courseId,
        string $recordId,
        string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'external_training.recognize', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $recordId, $reason, $requestId, $expectedTag): array {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertExpectedVersion($course, $expectedTag);

            $record = DB::table('recognized_external_training')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->where('id', $recordId)
                ->lockForUpdate()
                ->first();
            if ($record === null) {
                throw ResourceDomainException::notFound();
            }
            if ($record->revoked_at !== null) {
                return $this->presentExternal($record);
            }

            DB::table('recognized_external_training')->where('id', $recordId)->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor['user_id'],
                'revocation_reason' => $reason,
            ]);
            DB::table('course_enrollments')->where('id', $courseId)->update([
                'version' => (int) $course->version + 1,
                'updated_at' => now(),
            ]);
            $updated = DB::table('course_enrollments')->where('id', $courseId)->firstOrFail();
            $this->appendHistory($updated, 'updated', $this->lifecycleState($course), $this->lifecycleState($course), (string) $course->training_stage, (string) $course->training_stage, null, $actor['user_id'], (int) $course->version);
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'course.external_training.revoked', 'course_enrollment', $courseId, $requestId,
                ['fields' => ['recognized_external_training'], 'state' => 'current'],
                ['fields' => ['recognized_external_training'], 'state' => 'revoked'],
                $reason,
            );

            return $this->presentExternal(DB::table('recognized_external_training')->where('id', $recordId)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $course */
    public function etag(array $course): string
    {
        return '"v'.(int) $course['version'].'"';
    }

    private function studentHasFormalIdentity(object $student): bool
    {
        $data = get_object_vars($student);
        $noPesel = (bool) ($data['no_pesel_declared'] ?? false);
        $peselCiphertext = $data['pesel_ciphertext'] ?? null;
        $peselLookupHash = $data['pesel_lookup_hash'] ?? null;
        $birthDate = $data['birth_date'] ?? null;

        $hasPesel = ! $noPesel
            && $peselCiphertext !== null
            && $peselLookupHash !== null;

        return $hasPesel || ($noPesel
            && $peselCiphertext === null
            && $peselLookupHash === null
            && $birthDate !== null);
    }

    private function trainingType(string $value): string
    {
        if (! in_array($value, ['basic', 'supplementary'], true)) {
            throw ResourceDomainException::rule('Training type must be basic or supplementary.');
        }

        return $value;
    }

    /** @return array{id:string,code:string} */
    private function categoryByCode(string $code): array
    {
        $row = DB::table('driving_categories')->where('code', $code)->where('active', true)->first();
        if ($row === null) {
            throw ResourceDomainException::rule('Unknown, inactive or not-yet-verified driving category.');
        }
        $data = get_object_vars($row);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'code' => (string) ($data['code'] ?? ''),
        ];
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<string>
     */
    private function categoryIdsByCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), $codes)));
        if ($codes === []) {
            return [];
        }

        $rows = DB::table('driving_categories')->whereIn('code', $codes)->where('active', true)->get(['id', 'code']);
        if ($rows->count() !== count($codes)) {
            throw ResourceDomainException::rule('Held categories contain an unknown or inactive category.');
        }

        return array_values($rows->pluck('id')->map(static fn ($v): string => (string) $v)->all());
    }

    private function assertInstructor(string $organizationId, string $staffId): void
    {
        $valid = DB::table('staff_profiles as s')
            ->join('staff_type_assignments as t', 't.staff_profile_id', '=', 's.id')
            ->where('s.organization_id', $organizationId)
            ->where('s.id', $staffId)
            ->whereNull('s.archived_at')
            ->where('t.staff_type_code', 'Instructor')
            ->exists();
        if (! $valid) {
            throw ResourceDomainException::rule('Lead instructor must be an active Instructor in the same organization.');
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
            throw ResourceDomainException::rule('Course location must be active and belong to the same organization.');
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function pkkStorage(mixed $value): array
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw ResourceDomainException::rule('PKK number is required for a formal CourseEnrollment.');
        }
        if (mb_strlen($normalized) > 128) {
            throw ResourceDomainException::rule('PKK input is too long.');
        }

        return [
            $this->sensitiveIdentifiers->encrypt($normalized),
            $this->sensitiveIdentifiers->currentLookupHash($normalized),
            $normalized,
        ];
    }

    private function assertExpectedVersion(object $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current CourseEnrollment version is required.');
        }
        $data = get_object_vars($row);
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) ($data['version'] ?? 0)) {
            throw ResourceDomainException::conflict('CourseEnrollment changed since it was loaded.');
        }
    }

    private function lifecycleState(object $row): string
    {
        $data = get_object_vars($row);
        if (($data['completed_at'] ?? null) !== null) {
            return 'completed';
        }
        if (($data['interrupted_at'] ?? null) !== null) {
            return 'interrupted';
        }
        if (($data['cancelled_at'] ?? null) !== null) {
            return 'cancelled';
        }

        return 'active';
    }

    private function appendHistory(
        object $course,
        string $eventType,
        ?string $fromState,
        string $toState,
        ?string $fromStage,
        string $toStage,
        ?string $reason,
        ?string $actorUserId,
        ?int $versionBefore = null,
    ): void {
        $data = get_object_vars($course);
        DB::table('course_enrollment_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => (string) ($data['organization_id'] ?? ''),
            'course_enrollment_id' => (string) ($data['id'] ?? ''),
            'event_type' => $eventType,
            'from_lifecycle_state' => $fromState,
            'to_lifecycle_state' => $toState,
            'from_training_stage' => $fromStage,
            'to_training_stage' => $toStage,
            'course_version_before' => $versionBefore,
            'course_version_after' => (int) ($data['version'] ?? 0),
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'correction_of_event_id' => null,
            'event_payload_redacted' => null,
            'occurred_at' => now(),
        ]);
    }

    private function insertExternal(
        string $organizationId,
        string $courseId,
        string $trainingPart,
        int $minutes,
        string $recordRole,
        string $sourceKind,
        ?string $sourceSchoolReference,
        ?string $evidenceReference,
        ?string $reason,
        ?string $actorUserId,
        string $categoryId,
        string $trainingType,
        ?string $supersedesId = null,
    ): string {
        $id = (string) Str::uuid7();
        DB::table('recognized_external_training')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'course_enrollment_id' => $courseId,
            'training_part' => $trainingPart,
            'recognized_minutes' => $minutes,
            'record_role' => $recordRole,
            'source_kind' => $sourceKind,
            'source_school_reference' => $this->nullableString($sourceSchoolReference),
            'evidence_reference' => $this->nullableString($evidenceReference),
            'reason' => $this->nullableString($reason),
            'approved_by_user_id' => $actorUserId,
            'recognized_for_driving_category_id' => $categoryId,
            'recognized_for_training_type' => $trainingType,
            'supersedes_record_id' => $supersedesId,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function revalidateExternalContext(string $organizationId, object $course, string $actorUserId): void
    {
        $courseData = get_object_vars($course);
        $current = DB::table('recognized_external_training')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', (string) ($courseData['id'] ?? ''))
            ->whereNull('superseded_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get();

        foreach ($current as $record) {
            if ((string) ($record->recognized_for_driving_category_id ?? '') === (string) ($courseData['driving_category_id'] ?? '')
                && (string) ($record->recognized_for_training_type ?? '') === (string) ($courseData['training_type'] ?? '')) {
                continue;
            }

            DB::table('recognized_external_training')->where('id', $record->id)->update(['superseded_at' => now()]);
            $this->insertExternal(
                $organizationId,
                (string) ($courseData['id'] ?? ''),
                (string) $record->training_part,
                (int) $record->recognized_minutes,
                (string) $record->record_role,
                'context_revalidation',
                $record->source_school_reference === null ? null : (string) $record->source_school_reference,
                $record->evidence_reference === null ? null : (string) $record->evidence_reference,
                'Course context revalidation',
                $actorUserId,
                (string) ($courseData['driving_category_id'] ?? ''),
                (string) ($courseData['training_type'] ?? ''),
                (string) $record->id,
            );
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

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function auditFields(array $input): array
    {
        $fields = [];
        foreach (array_keys($input) as $field) {
            if ($field === 'pkk_number') {
                $fields[] = 'formal_identity';
            } else {
                $fields[] = (string) $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $data = get_object_vars($row);
        $organizationId = (string) ($data['organization_id'] ?? '');
        $courseId = (string) ($data['id'] ?? '');
        $categoryCode = DB::table('driving_categories')->where('id', $data['driving_category_id'] ?? null)->value('code');
        $pkk = DB::table('pkk_profiles')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->whereNull('superseded_at')
            ->first();

        return [
            'id' => $courseId,
            'student_id' => (string) ($data['student_id'] ?? ''),
            'training_type' => (string) ($data['training_type'] ?? ''),
            'driving_category_code' => is_string($categoryCode) ? $categoryCode : '',
            'pkk_reference_masked' => $pkk === null ? null : $this->maskedPkk((string) $pkk->pkk_number_ciphertext),
            'started_at' => (string) ($data['started_at'] ?? ''),
            'initial_cost' => $this->studentFinance->courseCostProjection($organizationId, $courseId),
            'declared_theory_minutes' => (int) ($data['declared_theory_minutes'] ?? 0),
            'declared_practical_minutes' => (int) ($data['declared_practical_minutes'] ?? 0),
            'lead_instructor_id' => (string) ($data['lead_instructor_id'] ?? ''),
            'location_id' => ($data['location_id'] ?? null) === null ? null : (string) $data['location_id'],
            'training_stage' => (string) ($data['training_stage'] ?? ''),
            'cancelled_at' => ($data['cancelled_at'] ?? null) === null ? null : (string) $data['cancelled_at'],
            'version' => (int) ($data['version'] ?? 0),
        ];
    }

    private function maskedPkk(string $ciphertext): string
    {
        try {
            $plain = $this->sensitiveIdentifiers->decrypt($ciphertext);
        } catch (\Throwable) {
            return '****';
        }
        $length = mb_strlen($plain);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($plain, -4);
    }

    /** @return array<string,mixed> */
    private function presentExternal(object $row): array
    {
        $data = get_object_vars($row);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'training_part' => (string) ($data['training_part'] ?? ''),
            'recognized_minutes' => (int) ($data['recognized_minutes'] ?? 0),
            'source_school_reference' => ($data['source_school_reference'] ?? null) === null ? null : (string) $data['source_school_reference'],
            'evidence_reference' => ($data['evidence_reference'] ?? null) === null ? null : (string) $data['evidence_reference'],
            'created_at' => (string) ($data['created_at'] ?? ''),
            'revoked_at' => ($data['revoked_at'] ?? null) === null ? null : (string) $data['revoked_at'],
        ];
    }
}
