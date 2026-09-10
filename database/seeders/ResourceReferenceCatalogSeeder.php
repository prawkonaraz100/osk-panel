<?php

namespace Database\Seeders;

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
    private const DRIVING_CATEGORIES = [
        'A', 'B', 'C', 'D', 'T', 'A1', 'B1', 'C1', 'D1', 'AM', 'A2',
        'B+E', 'C1+E', 'C+E', 'D1+E', 'D+E', 'PT',
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
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
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

            foreach (self::DRIVING_CATEGORIES as $code) {
                $row = DB::table('driving_categories')->where('code', $code)->first();
                $values = [
                    'label' => $code,
                    'active' => $code !== 'PT',
                    'metadata' => $code === 'PT'
                        ? json_encode(['verification_required' => true], JSON_THROW_ON_ERROR)
                        : null,
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
