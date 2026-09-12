<?php

namespace Database\Seeders;

use App\Modules\InternalExams\InternalExamAnswerSheetRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResourceReferenceCatalogSeeder extends Seeder
{
    /** @var array<string,string> */
    private const LOCATION_TYPES = [
        'branch' => 'Filia',
        'lecture_room' => 'Sala wykładowa',
        'maneuvering_area' => 'Plac manewrowy',
    ];

    /** @var array<string,string> */
    private const STAFF_TYPES = [
        'OfficeWorker' => 'Pracownik biurowy',
        'Instructor' => 'Instruktor',
    ];

    /** @var list<string> */
    private const DRIVING_LICENCE_CATEGORIES = [
        'AM', 'A1', 'A2', 'A', 'B1', 'B', 'B+E', 'C1', 'C', 'C1+E',
        'C+E', 'D1', 'D', 'D1+E', 'D+E', 'T',
    ];

    private const TRAM_PERMIT_OBSERVED_ALIAS = 'PT';

    /** @var list<string> */
    private const LANGUAGES = [
        'pl' => 'Polski',
    ];

    /** @var list<string> */
    private const AUDIT_ACTIONS = [
        'location.created', 'location.updated', 'location.archived', 'location.restored',
        'staff.created', 'staff.updated', 'staff.archived', 'staff.restored',
        'staff.panel_account.created', 'staff.panel_account.revoked',
        'vehicle.created', 'vehicle.updated', 'vehicle.archived', 'vehicle.restored',
        'vehicle.document.replaced',
        'student.created', 'student.updated', 'student.archived', 'student.restored',
        'course.created', 'course.updated', 'course.stage_changed', 'course.cancelled', 'course.restored',
        'course.requirements.updated', 'course.exemption.updated',
        'course.external_training.recognized', 'course.external_training.revoked',
        'training.session.created', 'training.session.updated', 'training.session.attendance_recorded',
        'training.session.completed', 'training.session.cancelled', 'training.hours.corrected',
        'calendar.event.created', 'calendar.event.updated', 'calendar.event.completed', 'calendar.event.cancelled',
        'availability.slot.created', 'availability.slot.updated', 'availability.slot.booked', 'availability.slot.formalized', 'availability.slot.cancelled',
        'student_charge_created', 'student_charge_cancelled', 'student_payment_recorded', 'student_payment_reversed',
        'commerce.payment.started',
        'learning_account_created', 'learning_account_updated', 'learning_account_password_reset', 'learning_account_handoff_created',
        'license_assignment_created', 'license_assignment_activated', 'license_assignment_revoked',
        'internal_exam.attempt.created', 'internal_exam.access.created', 'internal_exam.access.sent', 'internal_exam.access.delivery_failed', 'internal_exam.access.revoked',
        'internal_exam.started', 'internal_exam.submitted', 'internal_exam.technical_aborted',
        'internal_exam.station_transferred',
        'internal_exam.station.registered', 'internal_exam.station_credential.provisioned', 'internal_exam.station_credential.rotated',
        'internal_exam.answer_sheet.downloaded', 'internal_exam.inventory.adjusted',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::LANGUAGES as $code => $label) {
                DB::table('languages')->updateOrInsert(
                    ['code' => $code],
                    ['label_key' => $label, 'active' => true],
                );
            }

            foreach (self::LOCATION_TYPES as $code => $label) {
                DB::table('location_types')->updateOrInsert(
                    ['code' => $code],
                    ['label_key' => $label, 'active' => true],
                );
            }

            foreach (self::STAFF_TYPES as $code => $label) {
                DB::table('staff_types')->updateOrInsert(
                    ['code' => $code],
                    ['label_key' => $label, 'active' => true],
                );
            }

            foreach (self::DRIVING_LICENCE_CATEGORIES as $code) {
                $row = DB::table('driving_categories')->where('code', $code)->first();
                $values = [
                    'label' => $code,
                    'active' => true,
                    'metadata' => json_encode([
                        'entitlement_kind' => 'driving_licence_category',
                        'rule_engine_eligible' => true,
                        'legal_verified_at' => '2026-09-12',
                    ], JSON_THROW_ON_ERROR),
                ];
                if ($row === null) {
                    DB::table('driving_categories')->insert([
                        'id' => (string) Str::uuid7(),
                        'code' => $code,
                        ...$values,
                    ]);
                } else {
                    DB::table('driving_categories')->where('id', $row->id)->update($values);
                }
            }

            $tramAlias = DB::table('driving_categories')
                ->where('code', self::TRAM_PERMIT_OBSERVED_ALIAS)
                ->first();
            $tramAliasValues = [
                'label' => self::TRAM_PERMIT_OBSERVED_ALIAS,
                'active' => false,
                'metadata' => json_encode([
                    'entitlement_kind' => 'tram_permit',
                    'official_label' => 'Pozwolenie na kierowanie tramwajem',
                    'is_driving_licence_category' => false,
                    'rule_engine_eligible' => false,
                    'preservation_status' => 'USER_CONFIRMED_AUTH_SCREEN_ALIAS',
                    'legal_verified_at' => '2026-09-12',
                ], JSON_THROW_ON_ERROR),
            ];
            if ($tramAlias === null) {
                DB::table('driving_categories')->insert([
                    'id' => (string) Str::uuid7(),
                    'code' => self::TRAM_PERMIT_OBSERVED_ALIAS,
                    ...$tramAliasValues,
                ]);
            } else {
                DB::table('driving_categories')
                    ->where('id', $tramAlias->id)
                    ->update($tramAliasValues);
            }

            DB::table('internal_exam_document_templates')->insertOrIgnore([
                'id' => '0199f88d-8d00-7000-8000-000000000001',
                'document_type' => 'internal_exam_answer_sheet',
                'exam_part' => 'theory',
                'template_version' => 'v1',
                'renderer_version' => InternalExamAnswerSheetRenderer::RENDERER_VERSION,
                'template_content_hash' => hash(
                    'sha256',
                    'prawkonaraz|internal_exam_answer_sheet|theory|v1|'.InternalExamAnswerSheetRenderer::RENDERER_VERSION,
                ),
                'effective_from' => '2026-01-01 00:00:00+00',
                'effective_to' => null,
                'created_at' => now(),
            ]);

            foreach (self::AUDIT_ACTIONS as $action) {
                DB::table('audit_action_policy_revisions')->updateOrInsert(
                    ['action' => $action, 'policy_version' => 1],
                    [
                        'payload_validator_code' => 'resources.lifecycle.v1',
                        'before_payload_requirement' => 'required',
                        'after_payload_requirement' => 'required',
                        'reason_requirement' => 'optional',
                        'policy_hash' => hash('sha256', $action.'|1|resources.lifecycle.v1'),
                        'created_at' => now(),
                    ],
                );
                DB::table('audit_action_policy_currents')->updateOrInsert(
                    ['action' => $action],
                    ['policy_version' => 1, 'updated_at' => now()],
                );
            }
        });
    }
}
