<?php

namespace App\Modules\LearningAccess;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @phpstan-type StudentRow object{id:mixed,organization_id:mixed,first_name:mixed,last_name:mixed,archived_at:mixed}
 * @phpstan-type LearningAccountRow object{id:mixed,organization_id:mixed,student_id:mixed,user_id:mixed,auth_login_identifier_id:mixed,language_code:mixed,status:mixed,version:mixed,created_at:mixed}
 * @phpstan-type LearningAccountProjectionRow object{id:mixed,student_id:mixed,user_id:mixed,login_identifier:mixed,language_code:mixed,status:mixed,version:mixed,created_at:mixed}
 * @phpstan-type IdentifierRow object{id:mixed,user_id:mixed,identifier_normalized:mixed}
 * @phpstan-type UserRow object{status:mixed}
 * @phpstan-type PasswordManagementRow object{management_mode:mixed,managing_organization_id:mixed,credential_version:mixed}
 */
final class LearningAccountService
{
    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(string $sessionId, string $studentId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.view', $studentId);

        return array_values(DB::table('student_learning_accounts as a')
            ->join('auth_login_identifiers as i', function ($join): void {
                $join->on('i.id', '=', 'a.auth_login_identifier_id')
                    ->on('i.user_id', '=', 'a.user_id');
            })
            ->where('a.organization_id', $actor['organization_id'])
            ->where('a.student_id', $studentId)
            ->orderBy('a.created_at')
            ->get([
                'a.*',
                'i.identifier_normalized as login_identifier',
            ])
            ->map(function (object $row): array {
                /** @var LearningAccountProjectionRow $row */
                return $this->present($row);
            })
            ->values()
            ->all());
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, string $studentId, array $input, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.create', $studentId);
        if (($input['initial_password'] ?? null) !== null) {
            $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.manage_credentials', $studentId);
        }

        return DB::transaction(function () use ($actor, $studentId, $input, $requestId): array {
            $student = $this->lockStudent($actor['organization_id'], $studentId, true);

            return $this->createForLockedStudent($actor, $student, $input, $requestId);
        });
    }

    /**
     * Internal composition boundary for License assignment and Student create.
     * Caller must already hold the enclosing domain permission and transaction.
     *
     * @param  array{id:string,organization_id:string,user_id:string}  $actor
     * @param  StudentRow  $student
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function createForLockedStudent(array $actor, object $student, array $input, string $requestId): array
    {
        if ((string) $student->organization_id !== $actor['organization_id']) {
            throw ResourceDomainException::notFound();
        }
        if ($student->archived_at !== null) {
            throw ResourceDomainException::conflict('Archived Student cannot receive a new learning account.');
        }

        $login = $this->normalizeLogin((string) ($input['login_identifier'] ?? ''));
        $language = $this->activeLanguage((string) ($input['language_code'] ?? ''));
        $initialPassword = $this->nullableString($input['initial_password'] ?? null);

        $existingIdentifier = DB::table('auth_login_identifiers')
            ->where('identifier_normalized', $login)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
        if ($existingIdentifier !== null) {
            throw ResourceDomainException::conflict(
                'Login identifier is already attached to a global identity and requires explicit identity resolution.',
            );
        }

        $now = now();
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'first_name' => (string) $student->first_name,
            'last_name' => (string) $student->last_name,
            'password_hash' => $initialPassword === null ? null : Hash::make($initialPassword),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $identifierId = (string) Str::uuid7();
        DB::table('auth_login_identifiers')->insert([
            'id' => $identifierId,
            'user_id' => $userId,
            'identifier_type' => str_contains($login, '@') ? 'email' : 'username',
            'identifier_normalized' => $login,
            'is_primary_for_type' => false,
            'verified_at' => null,
            'created_at' => $now,
            'revoked_at' => null,
        ]);

        DB::table('user_password_management')->insert([
            'user_id' => $userId,
            'management_mode' => $initialPassword === null ? 'self_service' : 'organization_managed',
            'managing_organization_id' => $initialPassword === null ? null : $actor['organization_id'],
            'credential_version' => $initialPassword === null ? 0 : 1,
            'password_changed_at' => $initialPassword === null ? null : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $accountId = (string) Str::uuid7();
        DB::table('student_learning_accounts')->insert([
            'id' => $accountId,
            'organization_id' => $actor['organization_id'],
            'student_id' => (string) $student->id,
            'user_id' => $userId,
            'auth_login_identifier_id' => $identifierId,
            'language_code' => $language,
            'status' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->auditOutbox->recordOrganizationEvent(
            $actor['organization_id'],
            $actor['id'],
            $actor['user_id'],
            'learning_account_created',
            'student_learning_account',
            $accountId,
            $requestId,
            ['fields' => [], 'state' => 'absent'],
            ['fields' => ['login_identifier', 'language_code'], 'state' => 'active'],
        );

        return $this->getLockedProjection($actor['organization_id'], (string) $student->id, $accountId);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function update(
        string $sessionId,
        string $studentId,
        string $accountId,
        array $input,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.manage_credentials', $studentId);

        return DB::transaction(function () use ($actor, $studentId, $accountId, $input, $requestId, $expectedTag): array {
            $this->lockStudent($actor['organization_id'], $studentId, true);
            $account = $this->lockAccount($actor['organization_id'], $studentId, $accountId);
            $this->assertAccountExpectedVersion($account, $expectedTag);

            $updates = [];
            $changedFields = [];

            if (array_key_exists('login_identifier', $input)) {
                $login = $this->normalizeLogin((string) $input['login_identifier']);
                /** @var IdentifierRow|null $current */
                $current = DB::table('auth_login_identifiers')
                    ->where('id', $account->auth_login_identifier_id)
                    ->where('user_id', $account->user_id)
                    ->whereNull('revoked_at')
                    ->first();
                if ($current === null) {
                    throw ResourceDomainException::conflict('Learning account points to a non-current login identifier.');
                }
                if ((string) $current->identifier_normalized !== $login) {
                    /** @var IdentifierRow|null $candidate */
                    $candidate = DB::table('auth_login_identifiers')
                        ->where('identifier_normalized', $login)
                        ->whereNull('revoked_at')
                        ->lockForUpdate()
                        ->first();
                    if ($candidate !== null && (string) $candidate->user_id !== (string) $account->user_id) {
                        throw ResourceDomainException::conflict('Login identifier belongs to another global User.');
                    }
                    if ($candidate === null) {
                        $identifierId = (string) Str::uuid7();
                        DB::table('auth_login_identifiers')->insert([
                            'id' => $identifierId,
                            'user_id' => (string) $account->user_id,
                            'identifier_type' => str_contains($login, '@') ? 'email' : 'username',
                            'identifier_normalized' => $login,
                            'is_primary_for_type' => false,
                            'verified_at' => null,
                            'created_at' => now(),
                            'revoked_at' => null,
                        ]);
                    } else {
                        $identifierId = (string) $candidate->id;
                    }
                    $updates['auth_login_identifier_id'] = $identifierId;
                    $changedFields[] = 'login_identifier';
                }
            }

            if (array_key_exists('language_code', $input)) {
                $language = $this->activeLanguage((string) $input['language_code']);
                if ((string) $account->language_code !== $language) {
                    $effectiveAt = now();
                    $pending = DB::table('license_assignments')
                        ->where('organization_id', $actor['organization_id'])
                        ->where('student_learning_account_id', $accountId)
                        ->where('status', 'assigned')
                        ->exists();
                    $liveOrFuture = DB::table('license_activations')
                        ->where('organization_id', $actor['organization_id'])
                        ->where('student_learning_account_id', $accountId)
                        ->where('effective_to', '>', $effectiveAt)
                        ->exists();
                    if ($pending || $liveOrFuture) {
                        throw ResourceDomainException::conflict(
                            'Learning account language cannot change while an assignment is pending or entitlement is live/future.',
                        );
                    }
                    $updates['language_code'] = $language;
                    $changedFields[] = 'language_code';
                }
            }

            if ($updates !== []) {
                $updates['version'] = (int) $account->version + 1;
                $updates['updated_at'] = now();
                DB::table('student_learning_accounts')->where('id', $accountId)->update($updates);

                $this->auditOutbox->recordOrganizationEvent(
                    $actor['organization_id'],
                    $actor['id'],
                    $actor['user_id'],
                    'learning_account_updated',
                    'student_learning_account',
                    $accountId,
                    $requestId,
                    ['fields' => $changedFields, 'state' => (string) $account->status],
                    ['fields' => $changedFields, 'state' => (string) $account->status],
                );
            }

            return $this->getLockedProjection($actor['organization_id'], $studentId, $accountId);
        });
    }

    /**
     * @return array{body:array<string,mixed>,replay_body:array<string,mixed>}
     */
    public function resetPassword(
        string $sessionId,
        string $studentId,
        string $accountId,
        int $expectedCredentialVersion,
        string $requestId,
    ): array {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.reset_password', $studentId);

        return DB::transaction(function () use ($actor, $studentId, $accountId, $expectedCredentialVersion, $requestId): array {
            $this->lockStudent($actor['organization_id'], $studentId, true);
            $account = $this->lockAccount($actor['organization_id'], $studentId, $accountId);
            $this->assertOperationalAccount($account);

            /** @var UserRow|null $user */
            $user = DB::table('users')->where('id', $account->user_id)->lockForUpdate()->first();
            /** @var PasswordManagementRow|null $management */
            $management = DB::table('user_password_management')->where('user_id', $account->user_id)->lockForUpdate()->first();
            if ($user === null || $management === null) {
                throw ResourceDomainException::conflict('Global password management authority is incomplete.');
            }
            if ($user->status !== 'active') {
                throw ResourceDomainException::conflict('Global User is not authentication-eligible.');
            }
            if ($management->management_mode !== 'organization_managed'
                || (string) $management->managing_organization_id !== $actor['organization_id']) {
                throw ResourceDomainException::conflict('OSK does not own password-management authority for this global User.');
            }
            if ((int) $management->credential_version !== $expectedCredentialVersion) {
                throw ResourceDomainException::conflict('Credential version changed since it was loaded.');
            }
            $this->assertExclusiveOrganizationManagedPrincipal(
                (string) $account->user_id,
                $actor['organization_id'],
                $accountId,
            );

            $plaintext = Str::password(18, true, true, false, false);
            $nextVersion = (int) $management->credential_version + 1;
            $now = now();

            DB::table('users')->where('id', $account->user_id)->update([
                'password_hash' => Hash::make($plaintext),
                'updated_at' => $now,
            ]);
            DB::table('user_password_management')->where('user_id', $account->user_id)->update([
                'credential_version' => $nextVersion,
                'password_changed_at' => $now,
                'updated_at' => $now,
            ]);

            $handoffId = (string) Str::uuid7();
            DB::table('student_access_handoffs')->insert([
                'id' => $handoffId,
                'organization_id' => $actor['organization_id'],
                'student_learning_account_id' => $accountId,
                'handoff_type' => 'password_reset',
                'generated_by_user_id' => $actor['user_id'],
                'document_asset_id' => null,
                'credential_version_snapshot' => $nextVersion,
                'contains_fresh_secret' => true,
                'fresh_secret_issued_at' => $now,
                'batch_id' => null,
                'batch_ordinal' => null,
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'learning_account_password_reset',
                'student_learning_account',
                $accountId,
                $requestId,
                ['fields' => ['credential_version'], 'state' => 'active'],
                ['fields' => ['credential_version'], 'state' => 'active'],
            );

            $body = [
                'id' => $handoffId,
                'account_id' => $accountId,
                'created_at' => $now->toISOString(),
                'credential_version' => $nextVersion,
                'one_time_plaintext_password' => $plaintext,
            ];
            $replay = $body;
            $replay['one_time_plaintext_password'] = null;

            return ['body' => $body, 'replay_body' => $replay];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function createNonSecretHandoff(
        string $sessionId,
        string $studentId,
        string $accountId,
        string $requestId,
    ): array {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_access.manage_credentials', $studentId);

        return DB::transaction(function () use ($actor, $studentId, $accountId, $requestId): array {
            $this->lockStudent($actor['organization_id'], $studentId, true);
            $account = $this->lockAccount($actor['organization_id'], $studentId, $accountId);
            $this->assertOperationalAccount($account);
            /** @var PasswordManagementRow|null $management */
            $management = DB::table('user_password_management')->where('user_id', $account->user_id)->first();
            $credentialVersion = $management === null ? 0 : (int) $management->credential_version;
            $id = (string) Str::uuid7();
            $now = now();

            DB::table('student_access_handoffs')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'student_learning_account_id' => $accountId,
                'handoff_type' => 'credentials_document',
                'generated_by_user_id' => $actor['user_id'],
                'document_asset_id' => null,
                'credential_version_snapshot' => $credentialVersion,
                'contains_fresh_secret' => false,
                'fresh_secret_issued_at' => null,
                'batch_id' => null,
                'batch_ordinal' => null,
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'learning_account_handoff_created',
                'student_access_handoff',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['account_id'], 'state' => 'created'],
            );

            return [
                'id' => $id,
                'account_id' => $accountId,
                'created_at' => $now->toISOString(),
                'one_time_plaintext_password' => null,
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function documentForHandoff(
        string $sessionId,
        string $studentId,
        string $accountId,
        string $handoffId,
        string $requestId,
    ): array {
        $actor = $this->scopeAuthorizer->requireStudentTarget(
            $sessionId,
            'student_access.download_credentials_pdf',
            $studentId,
        );

        return DB::transaction(function () use ($actor, $studentId, $accountId, $handoffId, $requestId): array {
            $handoff = DB::table('student_access_handoffs')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_learning_account_id', $accountId)
                ->where('id', $handoffId)
                ->first();
            if ($handoff === null) {
                throw ResourceDomainException::notFound();
            }

            $document = $this->documentProjection(
                $actor['organization_id'],
                $studentId,
                $accountId,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'learning_access_credentials_exported',
                'student_access_handoff',
                $handoffId,
                $requestId,
                ['fields' => ['account_id'], 'state' => 'ready'],
                ['fields' => ['account_id', 'export_mode'], 'state' => 'downloaded'],
            );

            return $document;
        });
    }

    /**
     * @param list<string> $accountIds
     * @return array{id:string,selected_account_count:int}
     */
    public function createBulkExport(
        string $sessionId,
        array $accountIds,
        string $requestId,
    ): array {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.access_documents.download');
        $organizationId = $visibility['membership']['organization_id'];
        $actor = $visibility['membership'];
        $accountIds = array_values(array_unique($accountIds));

        if ($accountIds === [] || count($accountIds) > 100) {
            throw ResourceDomainException::rule('Bulk credentials export requires between 1 and 100 unique learning accounts.');
        }

        return DB::transaction(function () use ($sessionId, $actor, $organizationId, $accountIds, $requestId): array {
            $rows = DB::table('student_learning_accounts')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $accountIds)
                ->get(['id', 'student_id'])
                ->keyBy(static fn (object $row): string => (string) $row->id);
            if ($rows->count() !== count($accountIds)) {
                throw ResourceDomainException::notFound();
            }

            foreach ($accountIds as $accountId) {
                $row = $rows->get($accountId);
                if (! is_object($row)) {
                    throw ResourceDomainException::notFound();
                }
                $this->scopeAuthorizer->requireStudentTarget(
                    $sessionId,
                    'licenses.access_documents.download',
                    (string) $row->student_id,
                );
            }

            $batchId = (string) Str::uuid7();
            $now = now();
            DB::table('student_access_export_batches')->insert([
                'id' => $batchId,
                'organization_id' => $organizationId,
                'requested_by_user_id' => $actor['user_id'],
                'export_mode' => 'combined_credentials_pdf',
                'selected_account_count' => count($accountIds),
                'created_at' => $now,
            ]);

            foreach ($accountIds as $ordinal => $accountId) {
                DB::table('student_access_handoffs')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organizationId,
                    'student_learning_account_id' => $accountId,
                    'handoff_type' => 'credentials_document',
                    'generated_by_user_id' => $actor['user_id'],
                    'document_asset_id' => null,
                    'credential_version_snapshot' => $this->credentialVersion($accountId),
                    'contains_fresh_secret' => false,
                    'fresh_secret_issued_at' => null,
                    'batch_id' => $batchId,
                    'batch_ordinal' => $ordinal + 1,
                    'created_at' => $now,
                ]);
            }

            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $actor['id'],
                $actor['user_id'],
                'learning_access_credentials_exported',
                'student_access_export_batch',
                $batchId,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['selected_account_count', 'export_mode'], 'state' => 'created'],
            );

            return ['id' => $batchId, 'selected_account_count' => count($accountIds)];
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bulkExportDocuments(string $sessionId, string $batchId): array
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

        $handoffs = DB::table('student_access_handoffs')
            ->where('organization_id', $organizationId)
            ->where('batch_id', $batchId)
            ->orderBy('batch_ordinal')
            ->get(['student_learning_account_id']);

        $documents = [];
        foreach ($handoffs as $handoff) {
            $accountId = (string) $handoff->student_learning_account_id;
            $studentId = DB::table('student_learning_accounts')
                ->where('organization_id', $organizationId)
                ->where('id', $accountId)
                ->value('student_id');
            if (! is_string($studentId) || $studentId === '') {
                throw ResourceDomainException::notFound();
            }
            $this->scopeAuthorizer->requireStudentTarget(
                $sessionId,
                'licenses.access_documents.download',
                $studentId,
            );
            $documents[] = $this->documentProjection($organizationId, $studentId, $accountId);
        }

        if (count($documents) !== (int) $batch->selected_account_count) {
            throw ResourceDomainException::conflict('Credential export batch item count does not match its immutable snapshot.');
        }

        return $documents;
    }

    public function loginIdentifierForAccount(string $organizationId, string $accountId): string
    {
        $value = DB::table('student_learning_accounts as a')
            ->join('auth_login_identifiers as i', function ($join): void {
                $join->on('i.id', '=', 'a.auth_login_identifier_id')
                    ->on('i.user_id', '=', 'a.user_id');
            })
            ->where('a.organization_id', $organizationId)
            ->where('a.id', $accountId)
            ->whereNull('i.revoked_at')
            ->value('i.identifier_normalized');

        if (! is_string($value) || $value === '') {
            throw ResourceDomainException::conflict('Current login identifier is unavailable.');
        }

        return $value;
    }

    /** @param LearningAccountRow $account */
    public function assertOperationalAccount(object $account): void
    {
        if ((string) $account->status !== 'active') {
            throw ResourceDomainException::conflict('Learning account is suspended.');
        }
        $identifierCurrent = DB::table('auth_login_identifiers')
            ->where('id', $account->auth_login_identifier_id)
            ->where('user_id', $account->user_id)
            ->whereNull('revoked_at')
            ->exists();
        $userActive = DB::table('users')
            ->where('id', $account->user_id)
            ->where('status', 'active')
            ->exists();
        if (! $identifierCurrent || ! $userActive) {
            throw ResourceDomainException::conflict('Learning account identity is not operationally eligible.');
        }
    }

    /** @return LearningAccountRow */
    public function lockAccount(string $organizationId, string $studentId, string $accountId): object
    {
        $row = DB::table('student_learning_accounts')
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->where('id', $accountId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        /** @var LearningAccountRow $row */
        return $row;
    }

    /** @return StudentRow */
    private function lockStudent(string $organizationId, string $studentId, bool $requireCurrent): object
    {
        $row = DB::table('students')
            ->where('organization_id', $organizationId)
            ->where('id', $studentId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }
        if ($requireCurrent && $row->archived_at !== null) {
            throw ResourceDomainException::conflict('Archived Student cannot receive a new learning-access effect.');
        }

        /** @var StudentRow $row */
        return $row;
    }

    private function assertExclusiveOrganizationManagedPrincipal(
        string $userId,
        string $organizationId,
        string $accountId,
    ): void {
        if (DB::table('organization_memberships')->where('user_id', $userId)->exists()) {
            throw ResourceDomainException::conflict('Organization-managed learner User cannot also have an organization membership.');
        }
        if (DB::table('student_learning_accounts')
            ->where('user_id', $userId)
            ->where('id', '<>', $accountId)
            ->where('organization_id', '<>', $organizationId)
            ->exists()) {
            throw ResourceDomainException::conflict('Organization-managed learner User cannot be shared across organizations.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function documentProjection(string $organizationId, string $studentId, string $accountId): array
    {
        $row = DB::table('student_learning_accounts as a')
            ->join('students as s', function ($join): void {
                $join->on('s.id', '=', 'a.student_id')
                    ->on('s.organization_id', '=', 'a.organization_id');
            })
            ->join('auth_login_identifiers as i', function ($join): void {
                $join->on('i.id', '=', 'a.auth_login_identifier_id')
                    ->on('i.user_id', '=', 'a.user_id');
            })
            ->where('a.organization_id', $organizationId)
            ->where('a.student_id', $studentId)
            ->where('a.id', $accountId)
            ->whereNull('i.revoked_at')
            ->first([
                'a.id',
                'a.language_code',
                'i.identifier_normalized as login_identifier',
                's.first_name',
                's.last_name',
            ]);
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        $active = DB::table('license_activations')
            ->where('organization_id', $organizationId)
            ->where('student_learning_account_id', $accountId)
            ->where('effective_to', '>', now())
            ->exists();

        return [
            'learning_account_id' => $accountId,
            'student_id' => $studentId,
            'student_full_name' => trim((string) $row->first_name.' '.(string) $row->last_name),
            'login_identifier' => (string) $row->login_identifier,
            'language_code' => (string) $row->language_code,
            'access_status' => $active ? 'active' : 'not_active',
            'login_url' => rtrim((string) config('app.url'), '/').'/nauka?lang='.(string) $row->language_code,
        ];
    }

    private function credentialVersion(string $accountId): int
    {
        $userId = DB::table('student_learning_accounts')->where('id', $accountId)->value('user_id');
        if (! is_string($userId) || $userId === '') {
            throw ResourceDomainException::notFound();
        }

        return (int) DB::table('user_password_management')->where('user_id', $userId)->value('credential_version');
    }

    private function activeLanguage(string $code): string
    {
        $code = mb_strtolower(trim($code));
        if ($code === '' || ! DB::table('languages')->where('code', $code)->where('active', true)->exists()) {
            throw ResourceDomainException::rule('Learning language must be active in the global language catalog.');
        }

        return $code;
    }

    private function normalizeLogin(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '' || mb_strlen($value) > 320 || preg_match('/\s/u', $value) === 1) {
            throw ResourceDomainException::rule('Login identifier is invalid.');
        }

        return $value;
    }

    /** @param LearningAccountRow $account */
    private function assertAccountExpectedVersion(object $account, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current learning-account version is required.',
            );
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $account->version) {
            throw ResourceDomainException::conflict('Learning account changed since it was loaded.');
        }
    }

    /** @return array<string,mixed> */
    private function getLockedProjection(string $organizationId, string $studentId, string $accountId): array
    {
        /** @var LearningAccountProjectionRow|null $row */
        $row = DB::table('student_learning_accounts as a')
            ->join('auth_login_identifiers as i', function ($join): void {
                $join->on('i.id', '=', 'a.auth_login_identifier_id')
                    ->on('i.user_id', '=', 'a.user_id');
            })
            ->where('a.organization_id', $organizationId)
            ->where('a.student_id', $studentId)
            ->where('a.id', $accountId)
            ->first([
                'a.*',
                'i.identifier_normalized as login_identifier',
            ]);
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /**
     * @param  LearningAccountProjectionRow  $row
     * @return array<string,mixed>
     */
    private function present(object $row): array
    {
        /** @var PasswordManagementRow|null $management */
        $management = DB::table('user_password_management')->where('user_id', $row->user_id)->first();

        return [
            'id' => (string) $row->id,
            'student_id' => (string) $row->student_id,
            'login_identifier' => (string) $row->login_identifier,
            'language_code' => (string) $row->language_code,
            'status' => (string) $row->status,
            'version' => (int) $row->version,
            'credential_version' => $management === null ? 0 : (int) $management->credential_version,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
