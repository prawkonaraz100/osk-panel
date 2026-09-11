<?php

namespace App\Modules\LearningAccess;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LicenseService
{
    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly LearningAccountService $accounts,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function products(string $sessionId): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.view');
        unset($visibility);

        return DB::table('license_products')
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(function (object $row): array {
                $languages = DB::table('license_product_language_capabilities')
                    ->where('license_product_id', $row->id)
                    ->whereNull('disabled_at')
                    ->orderBy('language_code')
                    ->pluck('language_code')
                    ->map(static fn ($code): string => (string) $code)
                    ->all();

                return [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'duration_days' => (int) $row->duration_days,
                    'active' => (bool) $row->active,
                    'languages' => array_values($languages),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array{code:string,label:string}> */
    public function productLanguages(string $sessionId, string $productId): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.view');
        unset($visibility);

        if (! DB::table('license_products')->where('id', $productId)->exists()) {
            throw ResourceDomainException::notFound();
        }

        return DB::table('license_product_language_capabilities as c')
            ->join('languages as l', 'l.code', '=', 'c.language_code')
            ->where('c.license_product_id', $productId)
            ->whereNull('c.disabled_at')
            ->where('l.active', true)
            ->orderBy('c.language_code')
            ->get(['c.language_code', 'l.label_key'])
            ->map(static fn (object $row): array => [
                'code' => (string) $row->language_code,
                'label' => (string) $row->label_key,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function inventory(string $sessionId, ?string $productId, array $statuses): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.view');
        $query = DB::table('license_inventory_entries')
            ->where('organization_id', $visibility['membership']['organization_id']);
        if ($productId !== null) {
            $query->where('license_product_id', $productId);
        }
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query->orderBy('granted_at')->orderBy('id')->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'product_id' => (string) $row->license_product_id,
                'status' => (string) $row->status,
                'granted_at' => (string) $row->granted_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function createAssignment(string $sessionId, array $input, string $requestId): array
    {
        $inventoryId = (string) ($input['license_inventory_entry_id'] ?? '');
        $target = $input['target'] ?? null;
        if (! is_array($target)) {
            throw ResourceDomainException::rule('License assignment target is required.');
        }
        $existingAccountId = $target['existing_learning_account_id'] ?? null;
        $newStudentId = $target['student_id'] ?? null;
        $newAccount = $target['new_learning_account'] ?? null;
        $existingBranch = is_string($existingAccountId) && $existingAccountId !== '';
        $newBranch = is_string($newStudentId) && $newStudentId !== '' && is_array($newAccount);
        if ($existingBranch === $newBranch) {
            throw ResourceDomainException::rule('Exactly one license-assignment target branch is required.');
        }

        if ($existingBranch) {
            $accountSnapshot = DB::table('student_learning_accounts')->where('id', $existingAccountId)->first();
            if ($accountSnapshot === null) {
                throw ResourceDomainException::notFound();
            }
            $studentId = (string) $accountSnapshot->student_id;
        } else {
            $studentId = (string) $newStudentId;
        }

        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'licenses.assign', $studentId);

        return DB::transaction(function () use (
            $actor, $studentId, $inventoryId, $input, $requestId,
            $existingBranch, $existingAccountId, $newAccount,
        ): array {
            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($student === null) {
                throw ResourceDomainException::notFound();
            }
            if ($student->archived_at !== null) {
                throw ResourceDomainException::conflict('Archived Student cannot receive a new license assignment.');
            }

            if ($existingBranch) {
                $account = $this->accounts->lockAccount($actor['organization_id'], $studentId, (string) $existingAccountId);
                $this->accounts->assertOperationalAccount($account);
                $requestedLanguage = mb_strtolower(trim((string) ($input['language_code'] ?? '')));
                if ($requestedLanguage !== (string) $account->language_code) {
                    throw ResourceDomainException::rule('Existing learning-account assignment must inherit its current language.');
                }
                $accountId = (string) $account->id;
            } else {
                $newAccountInput = $newAccount;
                $requestedLanguage = mb_strtolower(trim((string) ($input['language_code'] ?? '')));
                if ((string) ($newAccountInput['language_code'] ?? '') !== $requestedLanguage) {
                    throw ResourceDomainException::rule('New learning account and license assignment must use the same language.');
                }
                $created = $this->accounts->createForLockedStudent($actor, $student, $newAccountInput, $requestId);
                $accountId = (string) $created['id'];
                $account = $this->accounts->lockAccount($actor['organization_id'], $studentId, $accountId);
            }

            $inventory = DB::table('license_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $inventoryId)
                ->lockForUpdate()
                ->first();
            if ($inventory === null) {
                throw ResourceDomainException::notFound('License inventory entry not found.');
            }
            if ((string) $inventory->status !== 'available') {
                throw ResourceDomainException::conflict('License inventory entry is not available.');
            }
            if (DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('license_inventory_entry_id', $inventoryId)
                ->whereIn('status', ['assigned', 'activated'])
                ->whereNull('revoked_at')
                ->exists()) {
                throw ResourceDomainException::conflict('License inventory entry already has a current assignment.');
            }

            $capability = DB::table('license_product_language_capabilities')
                ->where('license_product_id', $inventory->license_product_id)
                ->where('language_code', $requestedLanguage)
                ->whereNull('disabled_at')
                ->lockForUpdate()
                ->first();
            if ($capability === null) {
                throw ResourceDomainException::rule('Selected license product does not currently support the learning-account language.');
            }
            if (! DB::table('languages')->where('code', $requestedLanguage)->where('active', true)->exists()) {
                throw ResourceDomainException::rule('Selected language is not active.');
            }

            $sequence = (int) DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_learning_account_id', $accountId)
                ->max('assignment_sequence') + 1;
            $now = now();
            if ($now->lt($capability->enabled_at)
                || ($capability->disabled_at !== null && ! $now->lt($capability->disabled_at))) {
                throw ResourceDomainException::conflict('License language capability is not effective at assignment time.');
            }

            $id = (string) Str::uuid7();
            DB::table('license_assignments')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'license_inventory_entry_id' => $inventoryId,
                'student_id' => $studentId,
                'student_learning_account_id' => $accountId,
                'license_product_language_capability_id' => (string) $capability->id,
                'language_code' => $requestedLanguage,
                'assignment_sequence' => $sequence,
                'status' => 'assigned',
                'assigned_by_user_id' => $actor['user_id'],
                'assigned_at' => $now,
                'version' => 1,
                'created_at' => $now,
            ]);
            DB::table('license_inventory_entries')->where('id', $inventoryId)->update(['status' => 'assigned']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'license_assignment_created',
                'license_assignment',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['inventory', 'student', 'learning_account', 'language'], 'state' => 'assigned'],
            );

            return $this->assignmentProjection($actor['organization_id'], $id, now());
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function activate(
        string $sessionId,
        string $assignmentId,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $snapshot = DB::table('license_assignments')->where('id', $assignmentId)->first();
        if ($snapshot === null) {
            throw ResourceDomainException::notFound();
        }
        $actor = $this->scopeAuthorizer->requireStudentTarget(
            $sessionId,
            'licenses.activate',
            (string) $snapshot->student_id,
        );

        return DB::transaction(function () use ($actor, $assignmentId, $requestId, $expectedTag): array {
            $snapshot = DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $assignmentId)
                ->first();
            if ($snapshot === null) {
                throw ResourceDomainException::notFound();
            }

            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $snapshot->student_id)
                ->lockForUpdate()
                ->first();
            if ($student === null) {
                throw ResourceDomainException::notFound();
            }
            if ($student->archived_at !== null) {
                throw ResourceDomainException::conflict('Archived Student cannot receive a license activation.');
            }

            $account = $this->accounts->lockAccount(
                $actor['organization_id'],
                (string) $snapshot->student_id,
                (string) $snapshot->student_learning_account_id,
            );
            $this->accounts->assertOperationalAccount($account);

            $inventory = DB::table('license_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $snapshot->license_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null) {
                throw ResourceDomainException::notFound();
            }

            $assignment = DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAssignmentVersion($assignment, $expectedTag);

            if ($assignment->status !== 'assigned' || $inventory->status !== 'assigned') {
                throw ResourceDomainException::conflict('License assignment is not in activatable state.');
            }
            if (DB::table('license_activations')
                ->where('organization_id', $actor['organization_id'])
                ->where('license_assignment_id', $assignmentId)
                ->exists()) {
                throw ResourceDomainException::conflict('License assignment was already activated.');
            }
            if ((string) $assignment->student_learning_account_id !== (string) $account->id
                || (string) $assignment->language_code !== (string) $account->language_code) {
                throw ResourceDomainException::conflict('Assignment no longer matches the learning-account activation boundary.');
            }

            $product = DB::table('license_products')
                ->where('id', $inventory->license_product_id)
                ->lock('FOR SHARE')
                ->first();
            if ($product === null || (int) $product->duration_days <= 0) {
                throw ResourceDomainException::conflict('License product duration is invalid.');
            }

            $latest = DB::table('license_activations')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_learning_account_id', $account->id)
                ->orderByDesc('entitlement_sequence')
                ->first();
            $nextSequence = $latest === null ? 1 : (int) $latest->entitlement_sequence + 1;
            $effectiveAt = now();
            $expiryBefore = $latest === null ? null : (string) $latest->effective_to;
            $effectiveFrom = $effectiveAt->copy();
            if ($latest !== null) {
                $previousEnd = Carbon::parse((string) $latest->effective_to);
                if ($previousEnd->gt($effectiveFrom)) {
                    $effectiveFrom = $previousEnd;
                }
            }
            $effectiveTo = $effectiveFrom->copy()->addSeconds((int) $product->duration_days * 86400);

            $activationId = (string) Str::uuid7();
            DB::table('license_activations')->insert([
                'id' => $activationId,
                'organization_id' => $actor['organization_id'],
                'license_assignment_id' => $assignmentId,
                'student_learning_account_id' => (string) $account->id,
                'entitlement_sequence' => $nextSequence,
                'activation_origin' => 'organization_user',
                'duration_snapshot_source' => 'product_at_activation',
                'duration_days_snapshot' => (int) $product->duration_days,
                'expiry_before' => $expiryBefore,
                'activated_by_user_id' => $actor['user_id'],
                'activated_at' => $effectiveAt,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'created_at' => $effectiveAt,
            ]);
            DB::table('license_assignments')->where('id', $assignmentId)->update([
                'status' => 'activated',
                'version' => (int) $assignment->version + 1,
            ]);
            DB::table('license_inventory_entries')->where('id', $inventory->id)->update(['status' => 'consumed']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'license_assignment_activated',
                'license_assignment',
                $assignmentId,
                $requestId,
                ['fields' => ['status'], 'state' => 'assigned'],
                ['fields' => ['status', 'entitlement'], 'state' => 'activated'],
            );

            return [
                'id' => $activationId,
                'assignment_id' => $assignmentId,
                'activated_at' => $effectiveAt->toISOString(),
                'effective_from' => $effectiveFrom->toISOString(),
                'effective_to' => $effectiveTo->toISOString(),
                'entitlement_sequence' => $nextSequence,
                'duration_days_snapshot' => (int) $product->duration_days,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function revokeUnactivated(
        string $sessionId,
        string $assignmentId,
        string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $snapshot = DB::table('license_assignments')->where('id', $assignmentId)->first();
        if ($snapshot === null) {
            throw ResourceDomainException::notFound();
        }
        $actor = $this->scopeAuthorizer->requireStudentTarget(
            $sessionId,
            'licenses.revoke_unactivated',
            (string) $snapshot->student_id,
        );
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Revocation reason is required.');
        }

        return DB::transaction(function () use ($actor, $assignmentId, $reason, $requestId, $expectedTag): array {
            $snapshot = DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $assignmentId)
                ->first();
            if ($snapshot === null) {
                throw ResourceDomainException::notFound();
            }

            DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $snapshot->student_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->accounts->lockAccount(
                $actor['organization_id'],
                (string) $snapshot->student_id,
                (string) $snapshot->student_learning_account_id,
            );
            $inventory = DB::table('license_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $snapshot->license_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            $assignment = DB::table('license_assignments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAssignmentVersion($assignment, $expectedTag);

            if ($inventory === null || $assignment->status !== 'assigned' || $inventory->status !== 'assigned') {
                throw ResourceDomainException::conflict('Only an unactivated current assignment can be revoked.');
            }
            if (DB::table('license_activations')
                ->where('organization_id', $actor['organization_id'])
                ->where('license_assignment_id', $assignmentId)
                ->exists()) {
                throw ResourceDomainException::conflict('Activated assignment cannot be revoked as unactivated.');
            }

            $now = now();
            DB::table('license_assignments')->where('id', $assignmentId)->update([
                'status' => 'revoked_before_activation',
                'revoked_at' => $now,
                'revoked_by_user_id' => $actor['user_id'],
                'revoke_reason' => $reason,
                'version' => (int) $assignment->version + 1,
            ]);
            DB::table('license_inventory_entries')->where('id', $inventory->id)->update(['status' => 'available']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'license_assignment_revoked',
                'license_assignment',
                $assignmentId,
                $requestId,
                ['fields' => ['status'], 'state' => 'assigned'],
                ['fields' => ['status'], 'state' => 'revoked_before_activation'],
                $reason,
            );

            return $this->assignmentProjection($actor['organization_id'], $assignmentId, now());
        });
    }

    /** @return array<string,mixed> */
    public function getAssignment(string $sessionId, string $assignmentId): array
    {
        $snapshot = DB::table('license_assignments')->where('id', $assignmentId)->first();
        if ($snapshot === null) {
            throw ResourceDomainException::notFound();
        }
        $actor = $this->scopeAuthorizer->requireStudentTarget(
            $sessionId,
            'licenses.view',
            (string) $snapshot->student_id,
        );

        return $this->assignmentProjection($actor['organization_id'], $assignmentId, now());
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function assignments(string $sessionId, array $filters): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'licenses.view');
        $org = $visibility['membership']['organization_id'];
        $effectiveAt = now();
        $query = DB::table('student_learning_accounts as a')
            ->join('students as s', function ($join): void {
                $join->on('s.id', '=', 'a.student_id')->on('s.organization_id', '=', 'a.organization_id');
            })
            ->join('auth_login_identifiers as i', function ($join): void {
                $join->on('i.id', '=', 'a.auth_login_identifier_id')->on('i.user_id', '=', 'a.user_id');
            })
            ->where('a.organization_id', $org)
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')->from('license_assignments as la')
                    ->whereColumn('la.organization_id', 'a.organization_id')
                    ->whereColumn('la.student_learning_account_id', 'a.id');
            });

        if (! $visibility['unrestricted']) {
            if ($visibility['student_ids'] === []) {
                return ['data' => [], 'meta' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]];
            }
            $query->whereIn('a.student_id', $visibility['student_ids']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(i.identifier_normalized) LIKE ?', [$needle])
                    ->orWhereRaw("LOWER(s.first_name || ' ' || s.last_name) LIKE ?", [$needle]);
            });
        }
        if (($filters['hide_finished'] ?? false) === true) {
            $query->where(function ($q) use ($effectiveAt): void {
                $q->whereExists(function ($sub): void {
                    $sub->selectRaw('1')->from('license_assignments as p')
                        ->whereColumn('p.organization_id', 'a.organization_id')
                        ->whereColumn('p.student_learning_account_id', 'a.id')
                        ->where('p.status', 'assigned');
                })->orWhereExists(function ($sub) use ($effectiveAt): void {
                    $sub->selectRaw('1')->from('license_activations as ac')
                        ->whereColumn('ac.organization_id', 'a.organization_id')
                        ->whereColumn('ac.student_learning_account_id', 'a.id')
                        ->where('ac.effective_to', '>', $effectiveAt);
                });
            });
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $total = (clone $query)->count();

        $rows = $query->orderBy('s.last_name')->orderBy('s.first_name')->orderBy('a.id')
            ->forPage($page, $perPage)
            ->get([
                'a.id as account_id', 'a.student_id', 'a.language_code',
                'i.identifier_normalized as login_identifier',
                's.first_name', 's.last_name',
            ]);

        $data = $rows->map(function (object $row) use ($org, $effectiveAt): array {
            $history = $this->assignmentHistoryProjection($org, (string) $row->account_id, $effectiveAt);
            $latest = $history[0] ?? null;
            $expiry = DB::table('license_activations')
                ->where('organization_id', $org)
                ->where('student_learning_account_id', $row->account_id)
                ->max('effective_to');

            return [
                'learning_account_id' => (string) $row->account_id,
                'student_id' => (string) $row->student_id,
                'student_full_name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'learning_identifier' => (string) $row->login_identifier,
                'learning_access_language' => (string) $row->language_code,
                'assigned_license_count' => count(array_filter(
                    $history,
                    static fn (array $item): bool => in_array($item['status'], ['assigned', 'activated'], true),
                )),
                'latest_license_status' => $latest['presentation_status'] ?? null,
                'latest_license_generated_at' => $latest['assigned_at'] ?? null,
                'current_learning_access_expiry' => $expiry === null ? null : (string) $expiry,
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function history(string $sessionId, string $studentId, string $accountId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'licenses.view', $studentId);
        $account = DB::table('student_learning_accounts')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $studentId)
            ->where('id', $accountId)
            ->first();
        if ($account === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->assignmentHistoryProjection($actor['organization_id'], $accountId, now());
    }

    /**
     * Internal composition boundary used only after Student row creation while the
     * outer Student transaction is still active.
     *
     * @param  array{id:string,organization_id:string,user_id:string}  $actor
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function createAssignmentForNewStudent(
        array $actor,
        object $student,
        array $input,
        string $requestId,
    ): array {
        $target = $input['target'] ?? null;
        if (! is_array($target) || isset($target['existing_learning_account_id'])) {
            throw ResourceDomainException::rule('Initial Student license must create a new learning account.');
        }
        $target['student_id'] = (string) $student->id;
        $input['target'] = $target;

        // Student creation command owns the surrounding transaction and permission.
        return $this->createAssignmentWithLockedStudent($actor, $student, $input, $requestId);
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string}  $actor
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function createAssignmentWithLockedStudent(
        array $actor,
        object $student,
        array $input,
        string $requestId,
    ): array {
        $target = $input['target'];
        $newAccount = $target['new_learning_account'] ?? null;
        if (! is_array($newAccount)) {
            throw ResourceDomainException::rule('New learning account payload is required.');
        }
        $requestedLanguage = mb_strtolower(trim((string) ($input['language_code'] ?? '')));
        if ((string) ($newAccount['language_code'] ?? '') !== $requestedLanguage) {
            throw ResourceDomainException::rule('New learning account and license assignment must use the same language.');
        }
        $created = $this->accounts->createForLockedStudent($actor, $student, $newAccount, $requestId);
        $accountId = (string) $created['id'];

        $inventoryId = (string) ($input['license_inventory_entry_id'] ?? '');
        $inventory = DB::table('license_inventory_entries')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $inventoryId)
            ->lockForUpdate()
            ->first();
        if ($inventory === null || $inventory->status !== 'available') {
            throw ResourceDomainException::conflict('Initial license inventory entry is unavailable.');
        }
        $capability = DB::table('license_product_language_capabilities')
            ->where('license_product_id', $inventory->license_product_id)
            ->where('language_code', $requestedLanguage)
            ->whereNull('disabled_at')
            ->lockForUpdate()
            ->first();
        if ($capability === null) {
            throw ResourceDomainException::rule('Selected product does not support the requested language.');
        }

        $id = (string) Str::uuid7();
        $now = now();
        DB::table('license_assignments')->insert([
            'id' => $id,
            'organization_id' => $actor['organization_id'],
            'license_inventory_entry_id' => $inventoryId,
            'student_id' => (string) $student->id,
            'student_learning_account_id' => $accountId,
            'license_product_language_capability_id' => (string) $capability->id,
            'language_code' => $requestedLanguage,
            'assignment_sequence' => 1,
            'status' => 'assigned',
            'assigned_by_user_id' => $actor['user_id'],
            'assigned_at' => $now,
            'version' => 1,
            'created_at' => $now,
        ]);
        DB::table('license_inventory_entries')->where('id', $inventoryId)->update(['status' => 'assigned']);
        $this->auditOutbox->recordOrganizationEvent(
            $actor['organization_id'],
            $actor['id'],
            $actor['user_id'],
            'license_assignment_created',
            'license_assignment',
            $id,
            $requestId,
            ['fields' => [], 'state' => 'absent'],
            ['fields' => ['inventory', 'student', 'learning_account', 'language'], 'state' => 'assigned'],
        );

        return $this->assignmentProjection($actor['organization_id'], $id, now());
    }

    /** @return array<string,mixed> */
    private function assignmentProjection(string $organizationId, string $assignmentId, $effectiveAt): array
    {
        $row = DB::table('license_assignments')
            ->where('organization_id', $organizationId)
            ->where('id', $assignmentId)
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }
        $activation = DB::table('license_activations')
            ->where('organization_id', $organizationId)
            ->where('license_assignment_id', $assignmentId)
            ->first();

        $presentation = match ((string) $row->status) {
            'assigned' => 'not_activated',
            'revoked_before_activation' => 'revoked',
            'activated' => $activation !== null && Carbon::parse((string) $activation->effective_to)->gt($effectiveAt) ? 'active' : 'expired',
            default => 'unknown',
        };

        return [
            'id' => (string) $row->id,
            'inventory_entry_id' => (string) $row->license_inventory_entry_id,
            'student_id' => (string) $row->student_id,
            'learning_account_id' => (string) $row->student_learning_account_id,
            'language_code' => (string) $row->language_code,
            'assignment_sequence' => (int) $row->assignment_sequence,
            'status' => (string) $row->status,
            'presentation_status' => $presentation,
            'assigned_at' => (string) $row->assigned_at,
            'revoked_at' => $row->revoked_at === null ? null : (string) $row->revoked_at,
            'expires_at' => $activation === null ? null : (string) $activation->effective_to,
            'version' => (int) $row->version,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function assignmentHistoryProjection(string $organizationId, string $accountId, $effectiveAt): array
    {
        return DB::table('license_assignments')
            ->where('organization_id', $organizationId)
            ->where('student_learning_account_id', $accountId)
            ->orderByDesc('assignment_sequence')
            ->get()
            ->map(fn (object $row): array => $this->assignmentProjection(
                $organizationId,
                (string) $row->id,
                $effectiveAt,
            ))
            ->values()
            ->all();
    }

    private function assertAssignmentVersion(object $assignment, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current license-assignment version is required.',
            );
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $assignment->version) {
            throw ResourceDomainException::conflict('License assignment changed since it was loaded.');
        }
    }
}
