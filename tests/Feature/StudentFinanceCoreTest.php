<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentFinance\StudentFinanceService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class StudentFinanceCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_partial_payments_and_reversal_drive_finance_projection_without_mutable_balance(): void
    {
        $actor = $this->financeActor();
        $student = $this->student($actor);
        $finance = app(StudentFinanceService::class);

        $charge = $finance->createCharge($actor['session_id'], $student['id'], [
            'title' => 'Kurs kat. B',
            'amount' => ['amount_minor' => 400000, 'currency' => 'PLN'],
            'due_at' => '2026-09-30',
        ], (string) Str::uuid7());

        $first = $finance->recordPayment($actor['session_id'], $student['id'], [
            'charge_id' => $charge['id'],
            'amount' => ['amount_minor' => 100000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T10:00:00+02:00',
            'payment_method' => 'bank_transfer',
        ], (string) Str::uuid7());
        $finance->recordPayment($actor['session_id'], $student['id'], [
            'charge_id' => $charge['id'],
            'amount' => ['amount_minor' => 50000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T11:00:00+02:00',
            'payment_method' => 'cash',
        ], (string) Str::uuid7());

        $projected = $finance->listCharges($actor['session_id'], $student['id'])[0];
        $this->assertSame('partially_paid', $projected['status']);
        $this->assertSame(150000, $projected['paid_amount']['amount_minor']);
        $this->assertSame(250000, $projected['remaining_amount']['amount_minor']);

        $summary = $finance->summary($actor['session_id'], $student['id']);
        $this->assertSame(400000, $summary['total_charged']['amount_minor']);
        $this->assertSame(150000, $summary['total_paid']['amount_minor']);
        $this->assertSame(250000, $summary['balance']['amount_minor']);

        $finance->reversePayment(
            $actor['session_id'],
            $student['id'],
            $first['id'],
            'Błędnie przypisana wpłata',
            (string) Str::uuid7(),
        );

        $reopened = $finance->listCharges($actor['session_id'], $student['id'])[0];
        $this->assertSame(50000, $reopened['paid_amount']['amount_minor']);
        $this->assertSame(350000, $reopened['remaining_amount']['amount_minor']);
        $this->assertSame(2, DB::table('student_payments')->where('charge_id', $charge['id'])->count());
        $this->assertSame(1, DB::table('student_payments')->where('charge_id', $charge['id'])->whereNotNull('reversed_at')->count());
    }

    public function test_overpayment_cancellation_and_payment_after_cancellation_fail_closed(): void
    {
        $actor = $this->financeActor();
        $student = $this->student($actor);
        $finance = app(StudentFinanceService::class);
        $charge = $finance->createCharge($actor['session_id'], $student['id'], [
            'title' => 'Rata kursu',
            'amount' => ['amount_minor' => 10000, 'currency' => 'PLN'],
        ], (string) Str::uuid7());

        $payment = $finance->recordPayment($actor['session_id'], $student['id'], [
            'charge_id' => $charge['id'],
            'amount' => ['amount_minor' => 6000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T10:00:00+02:00',
        ], (string) Str::uuid7());

        $overpayment = $this->captureDomainException(fn () => $finance->recordPayment($actor['session_id'], $student['id'], [
            'charge_id' => $charge['id'],
            'amount' => ['amount_minor' => 5000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T10:05:00+02:00',
        ], (string) Str::uuid7()));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $overpayment->machineCode);

        $cancelWithPayment = $this->captureDomainException(fn () => $finance->cancelCharge(
            $actor['session_id'], $student['id'], $charge['id'], 'Anulowanie', (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $cancelWithPayment->machineCode);

        $finance->reversePayment(
            $actor['session_id'], $student['id'], $payment['id'], 'Korekta', (string) Str::uuid7(),
        );
        $cancelled = $finance->cancelCharge(
            $actor['session_id'], $student['id'], $charge['id'], 'Kurs anulowany', (string) Str::uuid7(),
        );
        $this->assertSame('cancelled', $cancelled['status']);

        $afterCancel = $this->captureDomainException(fn () => $finance->recordPayment($actor['session_id'], $student['id'], [
            'charge_id' => $charge['id'],
            'amount' => ['amount_minor' => 1000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T12:00:00+02:00',
        ], (string) Str::uuid7()));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $afterCancel->machineCode);

        $summary = $finance->summary($actor['session_id'], $student['id']);
        $this->assertSame(0, $summary['total_charged']['amount_minor']);
        $this->assertSame(0, $summary['total_paid']['amount_minor']);
        $this->assertSame(0, $summary['balance']['amount_minor']);
    }

    public function test_same_idempotency_key_replays_charge_payment_reversal_and_cancel_without_second_effect(): void
    {
        $actor = $this->financeActor();
        $student = $this->student($actor);

        $chargeKey = (string) Str::uuid7();
        $chargePayload = [
            'title' => 'Kurs',
            'amount' => ['amount_minor' => 20000, 'currency' => 'PLN'],
        ];
        $firstCharge = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $chargeKey)
            ->postJson("/api/v1/students/{$student['id']}/charges", $chargePayload)
            ->assertCreated();
        $chargeId = (string) $firstCharge->json('id');
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $chargeKey)
            ->postJson("/api/v1/students/{$student['id']}/charges", $chargePayload)
            ->assertCreated()
            ->assertJsonPath('id', $chargeId);
        $this->assertSame(1, DB::table('student_charges')->where('student_id', $student['id'])->count());

        $paymentKey = (string) Str::uuid7();
        $paymentPayload = [
            'charge_id' => $chargeId,
            'amount' => ['amount_minor' => 20000, 'currency' => 'PLN'],
            'paid_at' => '2026-09-11T10:00:00+02:00',
            'payment_method' => 'cash',
        ];
        $firstPayment = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $paymentKey)
            ->postJson("/api/v1/students/{$student['id']}/payments", $paymentPayload)
            ->assertCreated();
        $paymentId = (string) $firstPayment->json('id');
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $paymentKey)
            ->postJson("/api/v1/students/{$student['id']}/payments", $paymentPayload)
            ->assertCreated()
            ->assertJsonPath('id', $paymentId);
        $this->assertSame(1, DB::table('student_payments')->where('charge_id', $chargeId)->count());

        $reverseKey = (string) Str::uuid7();
        $reversePayload = ['reason' => 'Korekta'];
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $reverseKey)
            ->postJson("/api/v1/students/{$student['id']}/payments/{$paymentId}/reverse", $reversePayload)
            ->assertOk();
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $reverseKey)
            ->postJson("/api/v1/students/{$student['id']}/payments/{$paymentId}/reverse", $reversePayload)
            ->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'student_payment_reversed')->where('entity_id', $paymentId)->count());

        $cancelKey = (string) Str::uuid7();
        $cancelPayload = ['reason' => 'Kurs anulowany'];
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $cancelKey)
            ->postJson("/api/v1/students/{$student['id']}/charges/{$chargeId}/cancel", $cancelPayload)
            ->assertOk();
        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $cancelKey)
            ->postJson("/api/v1/students/{$student['id']}/charges/{$chargeId}/cancel", $cancelPayload)
            ->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'student_charge_cancelled')->where('entity_id', $chargeId)->count());
    }

    public function test_course_initial_cost_creates_one_exact_origin_and_retry_does_not_duplicate_charge(): void
    {
        $actor = $this->financeActor();
        $student = $this->student($actor);
        $instructor = $this->instructor($actor);
        $key = (string) Str::uuid7();
        $payload = [
            'training_type' => 'basic',
            'driving_category_code' => 'B',
            'pkk_number' => 'PKK-FINANCE-100',
            'started_at' => '2026-09-11T08:00:00+02:00',
            'lead_instructor_id' => $instructor['id'],
            'initial_cost' => ['amount_minor' => 350000, 'currency' => 'PLN'],
        ];

        $created = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/students/{$student['id']}/course-enrollments", $payload)
            ->assertCreated()
            ->assertJsonPath('initial_cost.amount_minor', 350000)
            ->assertJsonPath('initial_cost.currency', 'PLN');
        $courseId = (string) $created->json('id');

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/students/{$student['id']}/course-enrollments", $payload)
            ->assertCreated()
            ->assertJsonPath('id', $courseId);

        $this->assertSame(1, DB::table('student_charges')->where('course_enrollment_id', $courseId)->count());
        $this->assertSame(1, DB::table('course_cost_charge_origins')->where('course_enrollment_id', $courseId)->count());

        app(StudentFinanceService::class)->createCharge($actor['session_id'], $student['id'], [
            'title' => 'Dodatkowa należność',
            'amount' => ['amount_minor' => 10000, 'currency' => 'PLN'],
            'course_enrollment_id' => $courseId,
        ], (string) Str::uuid7());

        $this->assertSame(2, DB::table('student_charges')->where('course_enrollment_id', $courseId)->count());
        $this->assertSame(1, DB::table('course_cost_charge_origins')->where('course_enrollment_id', $courseId)->count());
    }

    public function test_initial_cost_requires_finance_permission_and_rolls_back_whole_course(): void
    {
        $actor = $this->courseActorWithoutFinance();
        $student = $this->student($actor);
        $instructor = $this->instructor($actor);

        try {
            app(CourseEnrollmentService::class)->create($actor['session_id'], $student['id'], [
                'training_type' => 'basic',
                'driving_category_code' => 'B',
                'pkk_number' => 'PKK-NO-FINANCE',
                'started_at' => '2026-09-11T08:00:00+02:00',
                'lead_instructor_id' => $instructor['id'],
                'initial_cost' => ['amount_minor' => 300000, 'currency' => 'PLN'],
            ], (string) Str::uuid7());
            $this->fail('Expected finance authorization failure.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('course_enrollments', 0);
            $this->assertDatabaseCount('student_charges', 0);
            $this->assertDatabaseCount('course_cost_charge_origins', 0);
            $this->assertDatabaseCount('pkk_profiles', 0);
        }
    }

    public function test_cross_tenant_finance_target_is_not_visible(): void
    {
        $owner = $this->financeActor();
        $other = $this->financeActor();
        $student = $this->student($owner);

        $exception = $this->captureDomainException(
            fn () => app(StudentFinanceService::class)->listCharges($other['session_id'], $student['id']),
        );

        $this->assertSame('RESOURCE_NOT_FOUND', $exception->machineCode);
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function financeActor(): array
    {
        $actor = $this->courseActorWithoutFinance();
        foreach ([
            'student_finance.view',
            'student_finance.create_charge',
            'student_finance.cancel_charge',
            'student_finance.record_payment',
            'student_finance.reverse_payment',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function courseActorWithoutFinance(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view', 'students.create',
            'courses.view', 'courses.create',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @return array<string,mixed>
     */
    private function student(array $actor): array
    {
        return app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'pesel' => '02070803628',
            'no_pesel' => false,
        ], (string) Str::uuid7());
    }

    /**
     * @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor
     * @return array<string,mixed>
     */
    private function instructor(array $actor): array
    {
        return app(StaffService::class)->create($actor['session_id'], [
            'email' => 'finance.instructor.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
            'first_name' => 'Jan',
            'last_name' => 'Instruktor',
            'staff_type_codes' => ['Instructor'],
            'category_ids' => [],
            'location_ids' => [],
        ], (string) Str::uuid7());
    }

    /** @param callable():mixed $callback */
    private function captureDomainException(callable $callback): ResourceDomainException
    {
        try {
            $callback();
        } catch (ResourceDomainException $exception) {
            return $exception;
        }

        $this->fail('Expected ResourceDomainException.');
    }
}
