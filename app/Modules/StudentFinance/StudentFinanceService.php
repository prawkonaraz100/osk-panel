<?php

namespace App\Modules\StudentFinance;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentFinanceService
{
    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listCharges(string $sessionId, string $studentId): array
    {
        $membership = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.view', $studentId);

        return array_values(DB::table('student_charges')
            ->where('organization_id', $membership['organization_id'])
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => $this->presentCharge($row))
            ->all());
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function createCharge(string $sessionId, string $studentId, array $input, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.create_charge', $studentId);
        [$amountMinor, $currency] = $this->money($input['amount'] ?? null, true);
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw ResourceDomainException::rule('Charge title is required.');
        }

        return DB::transaction(function () use ($actor, $studentId, $input, $requestId, $amountMinor, $currency, $title): array {
            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $studentId)
                ->lockForUpdate()
                ->first();
            if ($student === null) {
                throw ResourceDomainException::notFound();
            }

            $courseId = $this->nullableString($input['course_enrollment_id'] ?? null);
            if ($courseId !== null) {
                $course = DB::table('course_enrollments')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $courseId)
                    ->where('student_id', $studentId)
                    ->first();
                if ($course === null) {
                    throw ResourceDomainException::rule('Charge course must belong to the same Student and organization.');
                }
            }

            $id = (string) Str::uuid7();
            DB::table('student_charges')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'student_id' => $studentId,
                'course_enrollment_id' => $courseId,
                'title' => $title,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'due_at' => $input['due_at'] ?? null,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student_charge_created', 'student_charge', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['student_id', 'course_enrollment_id', 'amount_minor', 'currency'], 'state' => 'open'],
            );

            return $this->presentCharge(DB::table('student_charges')->where('id', $id)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $money
     *  @return array<string,mixed>
     */
    public function createFromCourseCost(string $sessionId, string $courseId, array $money, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireCourseTarget($sessionId, 'student_finance.create_charge', $courseId);
        [$amountMinor, $currency] = $this->money($money, true);

        return DB::transaction(function () use ($actor, $courseId, $requestId, $amountMinor, $currency): array {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound();
            }

            $origin = DB::table('course_cost_charge_origins')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->first();
            if ($origin !== null) {
                $existing = DB::table('student_charges')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $origin->student_charge_id)
                    ->where('student_id', $course->student_id)
                    ->first();
                if ($existing === null) {
                    throw ResourceDomainException::conflict('Course cost origin points to a missing or mismatched charge.');
                }

                return $this->presentCharge($existing);
            }

            $categoryCode = DB::table('driving_categories')->where('id', $course->driving_category_id)->value('code');
            $title = 'Kurs kat. '.(is_string($categoryCode) && $categoryCode !== '' ? $categoryCode : '—');
            $chargeId = (string) Str::uuid7();
            $now = now();

            DB::table('student_charges')->insert([
                'id' => $chargeId,
                'organization_id' => $actor['organization_id'],
                'student_id' => $course->student_id,
                'course_enrollment_id' => $courseId,
                'title' => $title,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'due_at' => null,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => $now,
            ]);
            DB::table('course_cost_charge_origins')->insert([
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $courseId,
                'student_id' => $course->student_id,
                'student_charge_id' => $chargeId,
                'source_amount_minor' => $amountMinor,
                'source_currency' => $currency,
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student_charge_created', 'student_charge', $chargeId, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['student_id', 'course_enrollment_id', 'amount_minor', 'currency'], 'state' => 'open'],
            );

            return $this->presentCharge(DB::table('student_charges')->where('id', $chargeId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function cancelCharge(string $sessionId, string $studentId, string $chargeId, string $reason, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.cancel_charge', $studentId);
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Cancellation reason is required.');
        }

        return DB::transaction(function () use ($actor, $studentId, $chargeId, $reason, $requestId): array {
            $charge = $this->lockedCharge($actor['organization_id'], $studentId, $chargeId);
            if ($charge->cancelled_at !== null) {
                throw ResourceDomainException::conflict('Charge cancellation is write-once.');
            }
            if ($this->validPaidMinor($actor['organization_id'], $chargeId) !== 0) {
                throw ResourceDomainException::conflict('Charge with active payments cannot be cancelled before all payments are reversed.');
            }

            $now = now();
            DB::table('student_charges')->where('id', $chargeId)->update([
                'cancelled_at' => $now,
                'cancelled_by_user_id' => $actor['user_id'],
                'cancellation_reason' => $reason,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student_charge_cancelled', 'student_charge', $chargeId, $requestId,
                ['fields' => [], 'state' => 'open'],
                ['fields' => ['cancelled_at'], 'state' => 'cancelled'],
                $reason,
            );

            return $this->presentCharge(DB::table('student_charges')->where('id', $chargeId)->firstOrFail());
        });
    }

    /** @return list<array<string,mixed>> */
    public function listPayments(string $sessionId, string $studentId): array
    {
        $membership = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.view', $studentId);

        return array_values(DB::table('student_payments')
            ->where('organization_id', $membership['organization_id'])
            ->where('student_id', $studentId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => $this->presentPayment($row))
            ->all());
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function recordPayment(string $sessionId, string $studentId, array $input, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.record_payment', $studentId);
        [$amountMinor, $currency] = $this->money($input['amount'] ?? null, false);
        $chargeId = (string) ($input['charge_id'] ?? '');

        return DB::transaction(function () use ($actor, $studentId, $input, $requestId, $amountMinor, $currency, $chargeId): array {
            $charge = $this->lockedCharge($actor['organization_id'], $studentId, $chargeId);
            if ($charge->cancelled_at !== null) {
                throw ResourceDomainException::conflict('Cannot record a payment on a cancelled charge.');
            }
            if ($currency !== (string) $charge->currency) {
                throw ResourceDomainException::rule('Payment currency must match charge currency.');
            }

            $paidMinor = $this->validPaidMinor($actor['organization_id'], $chargeId);
            $remainingMinor = (int) $charge->amount_minor - $paidMinor;
            if ($amountMinor > $remainingMinor) {
                throw ResourceDomainException::conflict('Payment exceeds the remaining charge amount.');
            }

            $id = (string) Str::uuid7();
            DB::table('student_payments')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'student_id' => $studentId,
                'charge_id' => $chargeId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'paid_at' => (string) $input['paid_at'],
                'payment_method' => $this->nullableString($input['payment_method'] ?? null),
                'note' => $this->nullableString($input['note'] ?? null),
                'received_by_user_id' => $actor['user_id'],
                'created_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student_payment_recorded', 'student_payment', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['student_id', 'charge_id', 'amount_minor', 'currency', 'paid_at'], 'state' => 'active'],
            );

            return $this->presentPayment(DB::table('student_payments')->where('id', $id)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function reversePayment(string $sessionId, string $studentId, string $paymentId, string $reason, string $requestId): array
    {
        $actor = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.reverse_payment', $studentId);
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Reversal reason is required.');
        }

        return DB::transaction(function () use ($actor, $studentId, $paymentId, $reason, $requestId): array {
            $snapshot = DB::table('student_payments')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_id', $studentId)
                ->where('id', $paymentId)
                ->first();
            if ($snapshot === null) {
                throw ResourceDomainException::notFound();
            }

            $this->lockedCharge($actor['organization_id'], $studentId, (string) $snapshot->charge_id);
            $payment = DB::table('student_payments')
                ->where('organization_id', $actor['organization_id'])
                ->where('student_id', $studentId)
                ->where('id', $paymentId)
                ->where('charge_id', $snapshot->charge_id)
                ->lockForUpdate()
                ->first();
            if ($payment === null) {
                throw ResourceDomainException::notFound();
            }
            if ($payment->reversed_at !== null) {
                throw ResourceDomainException::conflict('Payment reversal is write-once.');
            }

            DB::table('student_payments')->where('id', $paymentId)->update([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor['user_id'],
                'reversal_reason' => $reason,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'student_payment_reversed', 'student_payment', $paymentId, $requestId,
                ['fields' => [], 'state' => 'active'],
                ['fields' => ['reversed_at'], 'state' => 'reversed'],
                $reason,
            );

            return $this->presentPayment(DB::table('student_payments')->where('id', $paymentId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function summary(string $sessionId, string $studentId): array
    {
        $membership = $this->scopeAuthorizer->requireStudentTarget($sessionId, 'student_finance.view', $studentId);
        $charges = DB::table('student_charges')
            ->where('organization_id', $membership['organization_id'])
            ->where('student_id', $studentId)
            ->whereNull('cancelled_at')
            ->get();

        $totalDue = (int) $charges->sum(static fn (object $row): int => (int) $row->amount_minor);
        $chargeIds = $charges->pluck('id')->all();
        $totalPaid = $chargeIds === [] ? 0 : (int) DB::table('student_payments')
            ->where('organization_id', $membership['organization_id'])
            ->where('student_id', $studentId)
            ->whereIn('charge_id', $chargeIds)
            ->whereNull('reversed_at')
            ->sum('amount_minor');

        return [
            'total_charged' => ['amount_minor' => $totalDue, 'currency' => 'PLN'],
            'total_paid' => ['amount_minor' => $totalPaid, 'currency' => 'PLN'],
            'balance' => ['amount_minor' => $totalDue - $totalPaid, 'currency' => 'PLN'],
        ];
    }

    /** @return array<string,mixed>|null */
    public function courseCostProjection(string $organizationId, string $courseId): ?array
    {
        $origin = DB::table('course_cost_charge_origins')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseId)
            ->first();

        return $origin === null ? null : [
            'amount_minor' => (int) $origin->source_amount_minor,
            'currency' => (string) $origin->source_currency,
        ];
    }

    private function lockedCharge(string $organizationId, string $studentId, string $chargeId): object
    {
        $charge = DB::table('student_charges')
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->where('id', $chargeId)
            ->lockForUpdate()
            ->first();
        if ($charge === null) {
            throw ResourceDomainException::notFound();
        }

        return $charge;
    }

    private function validPaidMinor(string $organizationId, string $chargeId): int
    {
        return (int) DB::table('student_payments')
            ->where('organization_id', $organizationId)
            ->where('charge_id', $chargeId)
            ->whereNull('reversed_at')
            ->sum('amount_minor');
    }

    /** @return array{0:int,1:string} */
    private function money(mixed $money, bool $allowZero): array
    {
        if (! is_array($money) || ! array_key_exists('amount_minor', $money) || ! array_key_exists('currency', $money)) {
            throw ResourceDomainException::rule('Money requires amount_minor and currency.');
        }

        $amountMinor = filter_var($money['amount_minor'], FILTER_VALIDATE_INT);
        $currency = strtoupper(trim((string) $money['currency']));
        if ($amountMinor === false || ($allowZero ? $amountMinor < 0 : $amountMinor <= 0)) {
            throw ResourceDomainException::rule($allowZero ? 'Amount cannot be negative.' : 'Payment amount must be positive.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ResourceDomainException::rule('Currency must be a three-letter code.');
        }

        return [(int) $amountMinor, $currency];
    }

    /** @return array<string,mixed> */
    private function presentCharge(object $row): array
    {
        $paidMinor = $this->validPaidMinor((string) $row->organization_id, (string) $row->id);
        $remainingMinor = max(0, (int) $row->amount_minor - $paidMinor);
        $status = $row->cancelled_at !== null
            ? 'cancelled'
            : ($remainingMinor === 0 ? 'paid' : ($paidMinor > 0 ? 'partially_paid' : 'open'));

        return [
            'id' => (string) $row->id,
            'student_id' => (string) $row->student_id,
            'course_enrollment_id' => $row->course_enrollment_id === null ? null : (string) $row->course_enrollment_id,
            'title' => (string) $row->title,
            'original_amount' => ['amount_minor' => (int) $row->amount_minor, 'currency' => (string) $row->currency],
            'paid_amount' => ['amount_minor' => $paidMinor, 'currency' => (string) $row->currency],
            'remaining_amount' => ['amount_minor' => $remainingMinor, 'currency' => (string) $row->currency],
            'status' => $status,
            'due_at' => $row->due_at === null ? null : (string) $row->due_at,
            'cancelled_at' => $row->cancelled_at === null ? null : (string) $row->cancelled_at,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function presentPayment(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'student_id' => (string) $row->student_id,
            'charge_id' => (string) $row->charge_id,
            'amount' => ['amount_minor' => (int) $row->amount_minor, 'currency' => (string) $row->currency],
            'paid_at' => (string) $row->paid_at,
            'payment_method' => $row->payment_method === null ? null : (string) $row->payment_method,
            'note' => $row->note === null ? null : (string) $row->note,
            'reversed_at' => $row->reversed_at === null ? null : (string) $row->reversed_at,
            'reversal_reason' => $row->reversal_reason === null ? null : (string) $row->reversal_reason,
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
