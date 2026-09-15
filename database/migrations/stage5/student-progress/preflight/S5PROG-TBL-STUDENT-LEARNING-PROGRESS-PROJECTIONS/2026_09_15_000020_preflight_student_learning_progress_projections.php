<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'preflight',
            'S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student learning progress projection preflight requires PostgreSQL.');
        }

        foreach (['students', 'student_learning_accounts', 'driving_categories'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException($table.' must exist before student learning progress projection materialization.');
            }
        }

        if (Schema::hasTable('student_learning_progress_projections')
            && ! Schema::hasTable('learning_progress_source_bindings')) {
            throw new LogicException('Progress projection table may not pre-exist without its canonical source-binding table.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
