<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-CON-RESOURCES');

        ConstraintWriteFence::validate('MIG-CON-RESOURCES', [
            [
                'name' => 'staff_pesel_pair_consistent',
                'table' => 'staff_profiles',
                'columns' => ['pesel_ciphertext', 'pesel_lookup_hash'],
                'predicate' => '(src.pesel_ciphertext IS NULL) = (src.pesel_lookup_hash IS NULL)',
            ],
            [
                'name' => 'staff_membership_link_time_order',
                'table' => 'staff_membership_links',
                'columns' => ['linked_at', 'unlinked_at'],
                'predicate' => 'src.unlinked_at IS NULL OR src.unlinked_at >= src.linked_at',
            ],
            [
                'name' => 'staff_document_supersession_time_order',
                'table' => 'staff_documents',
                'columns' => ['created_at', 'superseded_at'],
                'predicate' => 'src.superseded_at IS NULL OR src.superseded_at >= src.created_at',
            ],
            [
                'name' => 'vehicle_document_supersession_time_order',
                'table' => 'vehicle_documents',
                'columns' => ['created_at', 'superseded_at'],
                'predicate' => 'src.superseded_at IS NULL OR src.superseded_at >= src.created_at',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
