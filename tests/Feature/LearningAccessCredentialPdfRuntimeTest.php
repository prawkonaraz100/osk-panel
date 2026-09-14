<?php

namespace Tests\Feature;

use App\Modules\LearningAccess\LearningAccountService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class LearningAccessCredentialPdfRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_later_password_reset_handoff_pdf_is_secret_free_and_does_not_mutate_credentials(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $accounts = app(LearningAccountService::class);
        $account = $accounts->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'password.pdf@example.test',
            'language_code' => 'pl',
            'initial_password' => str_repeat('x', 16),
        ], (string) Str::uuid7());

        $reset = $accounts->resetPassword(
            $actor['session_id'],
            $student['id'],
            $account['id'],
            1,
            (string) Str::uuid7(),
        );
        $handoffId = (string) $reset['body']['id'];
        $plaintext = (string) $reset['body']['one_time_plaintext_password'];
        $this->assertNotSame('', $plaintext);

        $userId = (string) DB::table('student_learning_accounts')->where('id', $account['id'])->value('user_id');
        $versionBefore = (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version');
        $assetsBefore = DB::table('file_assets')->count();
        $handoffsBefore = DB::table('student_access_handoffs')->count();

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->get("/api/v1/students/{$student['id']}/learning-accounts/{$account['id']}/access-handoffs/{$handoffId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringContainsString('% PNR-STUDENT-ACCESS-NONSECRET', $bytes);
        $this->assertStringContainsString(bin2hex('password.pdf@example.test'), $bytes);
        $this->assertStringNotContainsString($plaintext, $bytes);
        $this->assertStringNotContainsString(bin2hex($plaintext), $bytes);

        $this->assertSame(
            $versionBefore,
            (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version'),
        );
        $this->assertSame($assetsBefore, DB::table('file_assets')->count());
        $this->assertSame($handoffsBefore, DB::table('student_access_handoffs')->count());
        $this->assertNull(
            DB::table('student_access_handoffs')->where('id', $handoffId)->value('document_asset_id'),
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', 'learning_account_credentials_pdf_downloaded')
                ->where('entity_id', $handoffId)
                ->count(),
        );

        foreach (['audit_logs', 'domain_events', 'outbox_messages'] as $table) {
            $payloads = DB::table($table)
                ->get()
                ->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))
                ->implode("\n");
            $this->assertStringNotContainsString($plaintext, $payloads);
        }
    }

    public function test_handoff_pdf_requires_download_permission(): void
    {
        $actor = $this->accessActor();
        $student = $this->student($actor);
        $accounts = app(LearningAccountService::class);
        $account = $accounts->create($actor['session_id'], $student['id'], [
            'login_identifier' => 'permission.pdf@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $handoff = $accounts->createNonSecretHandoff(
            $actor['session_id'],
            $student['id'],
            $account['id'],
            (string) Str::uuid7(),
        );

        DB::table('membership_permissions')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'student_access.download_credentials_pdf')
            ->update(['granted' => false]);
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'student_access.download_credentials_pdf')
            ->delete();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->get("/api/v1/students/{$student['id']}/learning-accounts/{$account['id']}/access-handoffs/{$handoff['id']}/pdf")
            ->assertForbidden();
    }

    public function test_handoff_pdf_hides_foreign_tenant_target(): void
    {
        $actor = $this->accessActor();
        $foreign = $this->accessActor();
        $student = $this->student($foreign);
        $accounts = app(LearningAccountService::class);
        $account = $accounts->create($foreign['session_id'], $student['id'], [
            'login_identifier' => 'foreign.pdf@example.test',
            'language_code' => 'pl',
        ], (string) Str::uuid7());
        $handoff = $accounts->createNonSecretHandoff(
            $foreign['session_id'],
            $student['id'],
            $account['id'],
            (string) Str::uuid7(),
        );

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->get("/api/v1/students/{$student['id']}/learning-accounts/{$account['id']}/access-handoffs/{$handoff['id']}/pdf")
            ->assertNotFound();
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function accessActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.create',
            'student_access.create',
            'student_access.manage_credentials',
            'student_access.reset_password',
            'student_access.download_credentials_pdf',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function student(array $actor): array
    {
        return app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Jan',
            'last_name' => 'Dokument',
            'no_pesel' => true,
            'birth_date' => '1990-01-01',
        ], (string) Str::uuid7());
    }
}
