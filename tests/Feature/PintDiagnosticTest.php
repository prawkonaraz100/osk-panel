<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PintDiagnosticTest extends TestCase
{
    public function test_management_service_pint_diff_is_visible(): void
    {
        $source = base_path('app/Modules/InternalExams/InternalExamManagementService.php');
        $temporary = storage_path('framework/testing/InternalExamManagementService.php');
        @mkdir(dirname($temporary), 0777, true);

        $before = file_get_contents($source);
        $this->assertIsString($before);
        file_put_contents($temporary, $before);

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(base_path('vendor/bin/pint')).' '.escapeshellarg($temporary).' 2>&1', $output, $exitCode);
        $after = file_get_contents($temporary);
        @unlink($temporary);

        $this->assertIsString($after);

        if ($before !== $after) {
            $beforePath = storage_path('framework/testing/pint-before.php');
            $afterPath = storage_path('framework/testing/pint-after.php');
            file_put_contents($beforePath, $before);
            file_put_contents($afterPath, $after);
            $diff = shell_exec('diff -u '.escapeshellarg($beforePath).' '.escapeshellarg($afterPath).' 2>&1');
            @unlink($beforePath);
            @unlink($afterPath);

            $this->fail("Pint diagnostic diff:\n".($diff ?? implode("\n", $output)));
        }

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
