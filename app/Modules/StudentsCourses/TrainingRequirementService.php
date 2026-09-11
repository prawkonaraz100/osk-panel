<?php

namespace App\Modules\StudentsCourses;

use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Yaml\Yaml;

final class TrainingRequirementService
{
    private const RULE_SET_VERSION = 'formal_osk_training_theory_exemptions@2026-09-11';

    /** @return array<string,mixed> */
    public function current(string $organizationId, string $courseId): array
    {
        $row = DB::table('training_requirement_profiles')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->whereNull('superseded_at')
            ->first();

        if ($row === null) {
            throw ResourceDomainException::conflict('Current training requirement profile is missing.');
        }

        return $this->present($row);
    }

    /**
     * Caller must hold the exact CourseEnrollment row lock.
     *
     * @return array<string,mixed>
     */
    public function recalculate(object $course, int $courseVersionAfter, string $triggerCode, ?string $actorUserId): array
    {
        $rule = $this->ensureRuleSet();
        $organizationId = (string) $course->organization_id;
        $courseId = (string) $course->id;

        $context = DB::table('course_requirement_contexts')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->first();
        if ($context === null) {
            throw ResourceDomainException::conflict('Course requirement context is missing.');
        }

        $heldCategoryCodes = DB::table('course_requirement_context_held_categories as h')
            ->join('driving_categories as d', 'd.id', '=', 'h.driving_category_id')
            ->where('h.organization_id', $organizationId)
            ->where('h.course_enrollment_id', $courseId)
            ->orderBy('d.code')
            ->pluck('d.code')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        $categoryCode = DB::table('driving_categories')->where('id', $course->driving_category_id)->value('code');
        if (! is_string($categoryCode) || $categoryCode === '') {
            throw ResourceDomainException::conflict('Course driving category is unavailable.');
        }

        $exemption = DB::table('course_exemption_decisions')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->whereNull('revoked_at')
            ->first();

        $override = DB::table('course_requirement_override_decisions')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->whereNull('revoked_at')
            ->first();

        $source = [
            'target_category' => $categoryCode,
            'training_type' => (string) $course->training_type,
            'course_started_at' => (string) $course->started_at,
            'state_theory_passed' => (bool) $context->state_theory_passed,
            'context_evidence_reference' => $context->evidence_reference === null ? null : (string) $context->evidence_reference,
            'held_categories' => array_values($heldCategoryCodes),
            'exemption_basis_code' => $exemption?->basis_code === null ? null : (string) $exemption->basis_code,
            'exemption_evidence_reference' => $exemption?->evidence_reference === null ? null : (string) $exemption->evidence_reference,
            'rule_set_version' => self::RULE_SET_VERSION,
            'rule_set_content_hash' => $rule['content_hash'],
        ];

        $base = $this->calculateBase(
            $categoryCode,
            (string) $course->training_type,
            (int) ($course->declared_theory_minutes ?? 0),
            (int) ($course->declared_practical_minutes ?? 0),
            $heldCategoryCodes,
            (bool) $context->state_theory_passed,
            $exemption?->basis_code === null ? null : (string) $exemption->basis_code,
        );

        $effective = $base;
        $manualOverrideId = null;
        if ($override !== null) {
            $payload = json_decode((string) $override->override_payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw ResourceDomainException::conflict('Current requirement override payload is invalid.');
            }
            $allowed = [
                'theory_training_required',
                'minimum_theory_minutes',
                'internal_theory_exam_required',
                'practical_training_required',
                'minimum_practical_minutes',
                'internal_practical_exam_required',
            ];
            foreach ($payload as $key => $value) {
                if (! in_array((string) $key, $allowed, true)) {
                    throw ResourceDomainException::conflict('Current requirement override contains an unsupported key.');
                }
                $effective[(string) $key] = $value;
            }
            $manualOverrideId = (string) $override->id;
        }

        $now = now();
        DB::table('training_requirement_profiles')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => $now]);

        $id = (string) Str::uuid7();
        DB::table('training_requirement_profiles')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'course_enrollment_id' => $courseId,
            'requirements_revision' => (int) $course->requirements_revision,
            'course_version_after' => $courseVersionAfter,
            'rule_set_version' => self::RULE_SET_VERSION,
            'trigger_code' => $triggerCode,
            'calculation_reason' => null,
            'calculated_by_user_id' => $actorUserId,
            'input_snapshot' => json_encode($source, JSON_THROW_ON_ERROR),
            'base_output_snapshot' => json_encode($base, JSON_THROW_ON_ERROR),
            'effective_output_snapshot' => json_encode($effective, JSON_THROW_ON_ERROR),
            'manual_override_decision_id' => $manualOverrideId,
            'theory_training_required' => (bool) $effective['theory_training_required'],
            'minimum_theory_minutes' => (int) $effective['minimum_theory_minutes'],
            'internal_theory_exam_required' => (bool) $effective['internal_theory_exam_required'],
            'practical_training_required' => (bool) $effective['practical_training_required'],
            'minimum_practical_minutes' => (int) $effective['minimum_practical_minutes'],
            'internal_practical_exam_required' => (bool) $effective['internal_practical_exam_required'],
            'exemption_basis_code' => $effective['exemption_basis_code'],
            'calculated_at' => $now,
            'superseded_at' => null,
        ]);

        return $this->present(DB::table('training_requirement_profiles')->where('id', $id)->firstOrFail());
    }

    /**
     * @param  list<string>  $heldCategories
     * @return array<string,mixed>
     */
    private function calculateBase(
        string $category,
        string $trainingType,
        int $declaredTheoryMinutes,
        int $declaredPracticalMinutes,
        array $heldCategories,
        bool $stateTheoryPassed,
        ?string $exemptionBasis,
    ): array {
        $basic = [
            'AM' => [270, 300], 'A1' => [1350, 1200], 'A2' => [1350, 1200], 'A' => [1350, 1200],
            'B1' => [1350, 1800], 'B' => [1350, 1800], 'B+E' => [0, 900],
            'C1' => [900, 1200], 'C' => [900, 1800], 'C1+E' => [0, 1200], 'C+E' => [0, 1500],
            'D1' => [900, 1800], 'D' => [900, 3600], 'D1+E' => [0, 1200], 'D+E' => [0, 1500],
            'T' => [1350, 1200],
        ];
        if (! isset($basic[$category])) {
            throw ResourceDomainException::rule('Training requirements are not verified for this driving category.');
        }

        if ($trainingType === 'basic') {
            [$theory, $practical] = $basic[$category];
        } elseif ($trainingType === 'supplementary') {
            $theory = max(0, $declaredTheoryMinutes);
            $practical = max(0, $declaredPracticalMinutes);
        } else {
            throw ResourceDomainException::rule('Unsupported training type.');
        }

        $theoryRecognition = [
            'A2' => ['A1'],
            'A' => ['A1', 'A2'],
            'B' => ['B1'],
            'C' => ['C1'],
            'D' => ['D1'],
        ];
        $heldTheoryRecognition = array_intersect($heldCategories, $theoryRecognition[$category] ?? []) !== [];
        $explicitTheoryExemption = $exemptionBasis === 'art_23a';

        if ($stateTheoryPassed || $heldTheoryRecognition || $explicitTheoryExemption) {
            $theory = 0;
        }

        if ($trainingType === 'basic') {
            $reductions = [
                'A2' => ['A1' => 600],
                'A' => ['A2' => 600],
                'B' => ['B1' => 600],
                'C1' => ['D1' => 600],
                'C' => ['D' => 600],
                'D' => ['C1' => 1200, 'C' => 1200],
            ];
            $reduction = 0;
            foreach ($heldCategories as $held) {
                $reduction = max($reduction, $reductions[$category][$held] ?? 0);
            }
            $practical = max(0, $practical - $reduction);
        }

        $stateTheoryCategories = ['AM', 'A1', 'A2', 'A', 'B1', 'B', 'C1', 'C', 'D1', 'D', 'T'];
        $theoryRequired = $theory > 0;
        $practicalRequired = $practical > 0;

        return [
            'theory_training_required' => $theoryRequired,
            'minimum_theory_minutes' => $theory,
            'internal_theory_exam_required' => $theoryRequired && in_array($category, $stateTheoryCategories, true),
            'practical_training_required' => $practicalRequired,
            'minimum_practical_minutes' => $practical,
            'internal_practical_exam_required' => $practicalRequired,
            'exemption_basis_code' => $stateTheoryPassed
                ? 'art_23a'
                : ($heldTheoryRecognition ? 'recognized_prior_theory' : $exemptionBasis),
        ];
    }

    /** @return array{version:string,content_hash:string} */
    private function ensureRuleSet(): array
    {
        $path = base_path('specs/legal/training-theory-exemptions.yml');
        $document = Yaml::parseFile($path);
        if (! is_array($document) || ($document['legal_rule_set']['status'] ?? null) !== 'LEGAL_VERIFIED') {
            throw new LogicException('Verified training requirement rule artifact is unavailable.');
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new LogicException('Training requirement rule artifact cannot be hashed.');
        }

        $existing = DB::table('training_requirement_rule_sets')->where('version', self::RULE_SET_VERSION)->first();
        if ($existing === null) {
            DB::table('training_requirement_rule_sets')->insert([
                'version' => self::RULE_SET_VERSION,
                'jurisdiction' => 'PL',
                'content_hash' => $hash,
                'source_reference' => 'specs/legal/training-theory-exemptions.yml',
                'effective_from' => null,
                'published_at' => now(),
                'created_at' => now(),
            ]);
        } elseif (! hash_equals((string) $existing->content_hash, $hash)) {
            throw new LogicException('Training requirement rule-set version cannot be reused for changed content.');
        }

        return ['version' => self::RULE_SET_VERSION, 'content_hash' => $hash];
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        return [
            'rule_set_version' => (string) $row->rule_set_version,
            'theory_training_required' => (bool) $row->theory_training_required,
            'minimum_theory_minutes' => (int) $row->minimum_theory_minutes,
            'internal_theory_exam_required' => (bool) $row->internal_theory_exam_required,
            'practical_training_required' => (bool) $row->practical_training_required,
            'minimum_practical_minutes' => (int) $row->minimum_practical_minutes,
            'internal_practical_exam_required' => (bool) $row->internal_practical_exam_required,
            'exemption_basis_code' => $row->exemption_basis_code === null ? null : (string) $row->exemption_basis_code,
        ];
    }
}
