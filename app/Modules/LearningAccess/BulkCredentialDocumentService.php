<?php

namespace App\Modules\LearningAccess;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @phpstan-type BulkTarget array{learning_account_id:string,expected_credential_version?:int}
 * @phpstan-type Visibility array{membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},unrestricted:bool,student_ids:list<string>}
 */
final class BulkCredentialDocumentService
{
    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly LearningAccountService $accounts,
        private readonly LearningAccessBulkCredentialsPdfRenderer $renderer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param list<BulkTarget> $targets
     * @return array{bytes:string,filename:string,content_hash:string,batch_id:string,export_mode:string,selected_account_count:int}
     */
    public function generate(
        string $sessionId,
        array $targets,
        bool $reset,
        string $requestId,
    ): array {
        if ($targets === []) {
            throw ResourceDomainException::rule('At least one learning account is required.');
        }

        $downloadVisibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.access_documents.download');
        $organizationId = $downloadVisibility['membership']['organization_id'];
        $resetVisibility = $reset
            ? $this->scopeAuthorizer->visibility($sessionId, 'student_access.reset_password')
            : null;

        $accountIds = array_map(static fn (array $target): string => $target['learning_account_id'], $targets);
        if (count(array_unique($accountIds)) !== count($accountIds)) {
            throw ResourceDomainException::rule('Duplicate learning account target is not allowed in one bulk export.');
        }

        $resolvedRows = DB::table('student_learning_accounts')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->id);

        if ($resolvedRows->count() !== count($accountIds)) {
            throw ResourceDomainException::notFound();
        }

        /** @var list<array{learning_account_id:string,expected_credential_version?:int,student_id:string,user_id:string}> $resolvedTargets */
        $resolvedTargets = [];
        foreach ($targets as $target) {
            $row = $resolvedRows->get($target['learning_account_id']);
            if (! is_object($row)) {
                throw ResourceDomainException::notFound();
            }
            $studentId = (string) $row->student_id;
            $this->assertVisible($downloadVisibility, $studentId);
            if ($resetVisibility !== null) {
                $this->assertVisible($resetVisibility, $studentId);
            }

            $resolved = [
                'learning_account_id' => $target['learning_account_id'],
                'student_id' => $studentId,
                'user_id' => (string) $row->user_id,
            ];
            if ($reset) {
                if (! array_key_exists('expected_credential_version', $target)) {
                    throw ResourceDomainException::rule('Expected credential version is required for every reset target.');
                }
                $resolved['expected_credential_version'] = (int) $target['expected_credential_version'];
            }
            $resolvedTargets[] = $resolved;
        }

        return DB::transaction(function () use (
            $downloadVisibility,
            $organizationId,
            $resolvedTargets,
            $reset,
            $requestId,
        ): array {
            $studentIds = array_values(array_unique(array_column($resolvedTargets, 'student_id')));
            sort($studentIds, SORT_STRING);
            $students = [];
            foreach ($studentIds as $studentId) {
                $student = DB::table('students')
                    ->where('organization_id', $organizationId)
                    ->where('id', $studentId)
                    ->lockForUpdate()
                    ->first();
                if ($student === null) {
                    throw ResourceDomainException::notFound();
                }
                if ($student->archived_at !== null) {
                    throw ResourceDomainException::conflict('Archived Student cannot receive a learning-access export effect.');
                }
                $students[$studentId] = $student;
            }

            $accountIds = array_column($resolvedTargets, 'learning_account_id');
            $sortedAccountIds = $accountIds;
            sort($sortedAccountIds, SORT_STRING);
            $accounts = [];
            foreach ($sortedAccountIds as $accountId) {
                $account = DB::table('student_learning_accounts')
                    ->where('organization_id', $organizationId)
                    ->where('id', $accountId)
                    ->lockForUpdate()
                    ->first();
                if ($account === null) {
                    throw ResourceDomainException::notFound();
                }
                $this->accounts->assertOperationalAccount($account);
                $accounts[$accountId] = $account;
            }

            foreach ($resolvedTargets as $target) {
                $account = $accounts[$target['learning_account_id']];
                if ((string) $account->student_id !== $target['student_id']
                    || (string) $account->user_id !== $target['user_id']) {
                    throw ResourceDomainException::conflict('Learning account identity changed while the bulk export was being prepared.');
                }
            }

            $userIds = array_values(array_unique(array_map(
                static fn (object $account): string => (string) $account->user_id,
                $accounts,
            )));
            sort($userIds, SORT_STRING);

            $users = [];
            foreach ($userIds as $userId) {
                $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                if ($user === null || (string) $user->status !== 'active') {
                    throw ResourceDomainException::conflict('Global User is not authentication-eligible.');
                }
                $users[$userId] = $user;
            }

            $managementRows = [];
            foreach ($userIds as $userId) {
                $management = DB::table('user_password_management')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if ($management === null) {
                    throw ResourceDomainException::conflict('Global password management authority is incomplete.');
                }
                $managementRows[$userId] = $management;
            }

            /** @var array<string,int> $expectedByUser */
            $expectedByUser = [];
            /** @var array<string,string> $firstAccountByUser */
            $firstAccountByUser = [];
            if ($reset) {
                foreach ($resolvedTargets as $target) {
                    $account = $accounts[$target['learning_account_id']];
                    $userId = (string) $account->user_id;
                    $expected = (int) $target['expected_credential_version'];

                    if (isset($expectedByUser[$userId]) && $expectedByUser[$userId] !== $expected) {
                        throw ResourceDomainException::conflict(
                            'Selected learning accounts for the same global User disagree on expected credential version.',
                        );
                    }
                    $expectedByUser[$userId] = $expected;
                    $firstAccountByUser[$userId] ??= $target['learning_account_id'];
                }

                foreach ($userIds as $userId) {
                    $management = $managementRows[$userId];
                    if ((string) $management->management_mode !== 'organization_managed'
                        || (string) $management->managing_organization_id !== $organizationId) {
                        throw ResourceDomainException::conflict(
                            'OSK does not own password-management authority for a selected global User.',
                        );
                    }
                    if (! array_key_exists($userId, $expectedByUser)
                        || (int) $management->credential_version !== $expectedByUser[$userId]) {
                        throw ResourceDomainException::conflict('Credential version changed since it was loaded.');
                    }
                    $this->assertExclusiveOrganizationManagedPrincipal($userId, $organizationId);
                }
            }

            $loginUrl = config('app.url');
            if (! is_string($loginUrl) || trim($loginUrl) === '') {
                throw ResourceDomainException::conflict('Application login URL is not configured.');
            }
            $loginUrl = rtrim($loginUrl, '/');

            /** @var array<string,array{plaintext:string,hash:string,next_version:int}> $freshCredentials */
            $freshCredentials = [];
            if ($reset) {
                foreach ($userIds as $userId) {
                    $plaintext = Str::password(18, true, true, false, false);
                    $freshCredentials[$userId] = [
                        'plaintext' => $plaintext,
                        'hash' => Hash::make($plaintext),
                        'next_version' => (int) $managementRows[$userId]->credential_version + 1,
                    ];
                }
            }

            $pages = [];
            foreach ($resolvedTargets as $target) {
                $account = $accounts[$target['learning_account_id']];
                $student = $students[(string) $account->student_id];
                $userId = (string) $account->user_id;
                $pages[] = [
                    'student_name' => trim((string) $student->first_name.' '.(string) $student->last_name),
                    'login_identifier' => $this->accounts->loginIdentifierForAccount(
                        $organizationId,
                        $target['learning_account_id'],
                    ),
                    'language_code' => (string) $account->language_code,
                    'password_is_set' => is_string($users[$userId]->password_hash) && $users[$userId]->password_hash !== '',
                    'login_url' => $loginUrl,
                    'plaintext_password' => $reset ? $freshCredentials[$userId]['plaintext'] : null,
                ];
            }

            // Render the complete response before the first durable password mutation.
            $bytes = $this->renderer->render($pages, $reset);

            $now = now();
            if ($reset) {
                foreach ($userIds as $userId) {
                    $credential = $freshCredentials[$userId];
                    DB::table('users')->where('id', $userId)->update([
                        'password_hash' => $credential['hash'],
                        'updated_at' => $now,
                    ]);
                    DB::table('user_password_management')->where('user_id', $userId)->update([
                        'credential_version' => $credential['next_version'],
                        'password_changed_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->auditOutbox->recordOrganizationEvent(
                        $organizationId,
                        $downloadVisibility['membership']['id'],
                        $downloadVisibility['membership']['user_id'],
                        'learning_account_password_reset',
                        'student_learning_account',
                        $firstAccountByUser[$userId],
                        $requestId,
                        ['fields' => ['credential_version'], 'state' => 'active'],
                        ['fields' => ['credential_version'], 'state' => 'active'],
                    );
                }
            }

            $batchId = (string) Str::uuid7();
            $exportMode = $reset ? 'reset_and_secret_combined_pdf' : 'nonsecret_combined_pdf';
            DB::table('student_access_export_batches')->insert([
                'id' => $batchId,
                'organization_id' => $organizationId,
                'requested_by_user_id' => $downloadVisibility['membership']['user_id'],
                'export_mode' => $exportMode,
                'selected_account_count' => count($resolvedTargets),
                'created_at' => $now,
            ]);

            foreach ($resolvedTargets as $index => $target) {
                $account = $accounts[$target['learning_account_id']];
                $userId = (string) $account->user_id;
                $handoffId = (string) Str::uuid7();
                $credentialVersion = $reset
                    ? $freshCredentials[$userId]['next_version']
                    : (int) $managementRows[$userId]->credential_version;

                DB::table('student_access_handoffs')->insert([
                    'id' => $handoffId,
                    'organization_id' => $organizationId,
                    'student_learning_account_id' => $target['learning_account_id'],
                    'handoff_type' => $reset ? 'password_reset' : 'credentials_document',
                    'generated_by_user_id' => $downloadVisibility['membership']['user_id'],
                    'document_asset_id' => null,
                    'credential_version_snapshot' => $credentialVersion,
                    'contains_fresh_secret' => $reset,
                    'fresh_secret_issued_at' => $reset ? $now : null,
                    'batch_id' => $batchId,
                    'batch_ordinal' => $index + 1,
                    'created_at' => $now,
                ]);

                $this->auditOutbox->recordOrganizationEvent(
                    $organizationId,
                    $downloadVisibility['membership']['id'],
                    $downloadVisibility['membership']['user_id'],
                    'learning_account_handoff_created',
                    'student_access_handoff',
                    $handoffId,
                    $requestId,
                    ['fields' => [], 'state' => 'absent'],
                    ['fields' => ['account_id'], 'state' => 'created'],
                );
                $this->auditOutbox->recordOrganizationEvent(
                    $organizationId,
                    $downloadVisibility['membership']['id'],
                    $downloadVisibility['membership']['user_id'],
                    'learning_account_credentials_pdf_downloaded',
                    'student_access_handoff',
                    $handoffId,
                    $requestId,
                    ['fields' => ['account_id', 'handoff_id'], 'state' => 'ready'],
                    ['fields' => ['account_id', 'handoff_id'], 'state' => 'served'],
                );
            }

            return [
                'bytes' => $bytes,
                'filename' => 'dostepy-'.$batchId.'.pdf',
                'content_hash' => hash('sha256', $bytes),
                'batch_id' => $batchId,
                'export_mode' => $exportMode,
                'selected_account_count' => count($resolvedTargets),
            ];
        });
    }

    /**
     * Re-render a completed nonsecret batch for an idempotent retry without
     * creating a second batch, handoff or audit effect.
     *
     * @return array{bytes:string,filename:string,content_hash:string,batch_id:string,export_mode:string,selected_account_count:int}
     */
    public function renderNonsecretBatch(string $sessionId, string $batchId): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.access_documents.download');
        $organizationId = $visibility['membership']['organization_id'];
        $batch = DB::table('student_access_export_batches')
            ->where('organization_id', $organizationId)
            ->where('id', $batchId)
            ->first();
        if ($batch === null) {
            throw ResourceDomainException::notFound();
        }
        if ((string) $batch->export_mode !== 'nonsecret_combined_pdf') {
            throw ResourceDomainException::conflict('Secret-bearing bulk credential response is not replayable.');
        }

        $handoffs = DB::table('student_access_handoffs')
            ->where('organization_id', $organizationId)
            ->where('batch_id', $batchId)
            ->orderBy('batch_ordinal')
            ->get();
        if ($handoffs->count() !== (int) $batch->selected_account_count) {
            throw ResourceDomainException::conflict('Bulk access batch handoff set is incomplete.');
        }

        $loginUrl = config('app.url');
        if (! is_string($loginUrl) || trim($loginUrl) === '') {
            throw ResourceDomainException::conflict('Application login URL is not configured.');
        }

        $pages = [];
        foreach ($handoffs as $handoff) {
            $account = DB::table('student_learning_accounts')
                ->where('organization_id', $organizationId)
                ->where('id', $handoff->student_learning_account_id)
                ->first();
            if ($account === null) {
                throw ResourceDomainException::notFound();
            }
            $studentId = (string) $account->student_id;
            $this->assertVisible($visibility, $studentId);
            $student = DB::table('students')
                ->where('organization_id', $organizationId)
                ->where('id', $studentId)
                ->whereNull('archived_at')
                ->first();
            if ($student === null) {
                throw ResourceDomainException::conflict('Student is no longer eligible for a sensitive learning-access export.');
            }
            $this->accounts->assertOperationalAccount($account);

            $passwordHash = DB::table('users')->where('id', $account->user_id)->value('password_hash');
            $pages[] = [
                'student_name' => trim((string) $student->first_name.' '.(string) $student->last_name),
                'login_identifier' => $this->accounts->loginIdentifierForAccount(
                    $organizationId,
                    (string) $account->id,
                ),
                'language_code' => (string) $account->language_code,
                'password_is_set' => is_string($passwordHash) && $passwordHash !== '',
                'login_url' => rtrim($loginUrl, '/'),
                'plaintext_password' => null,
            ];
        }

        $bytes = $this->renderer->render($pages, false);

        return [
            'bytes' => $bytes,
            'filename' => 'dostepy-'.$batchId.'.pdf',
            'content_hash' => hash('sha256', $bytes),
            'batch_id' => $batchId,
            'export_mode' => 'nonsecret_combined_pdf',
            'selected_account_count' => (int) $batch->selected_account_count,
        ];
    }

    /** @param Visibility $visibility */
    private function assertVisible(array $visibility, string $studentId): void
    {
        if (! $visibility['unrestricted'] && ! in_array($studentId, $visibility['student_ids'], true)) {
            throw ResourceDomainException::notFound();
        }
    }

    private function assertExclusiveOrganizationManagedPrincipal(string $userId, string $organizationId): void
    {
        if (DB::table('organization_memberships')->where('user_id', $userId)->exists()) {
            throw ResourceDomainException::conflict(
                'Organization-managed learner User cannot also have an organization membership.',
            );
        }
        if (DB::table('student_learning_accounts')
            ->where('user_id', $userId)
            ->where('organization_id', '<>', $organizationId)
            ->exists()) {
            throw ResourceDomainException::conflict(
                'Organization-managed learner User cannot be shared across organizations.',
            );
        }
        if (DB::table('auth_social_accounts')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists()) {
            throw ResourceDomainException::conflict(
                'Organization-managed learner User cannot have a current social login.',
            );
        }
    }
}
