<?php

namespace App\Modules\FormalDocuments;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type TemplateBinding array{id:string,document_type:string,template_version:string,renderer_version:string,template_content_hash:string}
 * @phpstan-type EvidenceBundle array<string,mixed>
 */
final class FormalTrainingDocumentService
{
    public const ASSET_PURPOSE = 'formal_training_document';

    public const SIGNED_SCAN_ASSET_PURPOSE = 'formal_training_signed_scan';

    /** @var list<string> */
    private const DOCUMENT_TYPES = ['training_record_card', 'theory_delivery_journal'];

    /** @var list<string> */
    private const DELIVERY_EVENT_TYPES = ['printed', 'signed_scan_attached', 'electronic_presented'];

    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scope,
        private readonly FormalTrainingDocumentRenderer $renderer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $sessionId, string $courseId, string $documentType): array
    {
        $this->assertDocumentType($documentType);
        $actor = $this->scope->requireCourseTarget($sessionId, 'formal_documents.view', $courseId);

        $course = DB::table('course_enrollments')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $courseId)
            ->first();
        if ($course === null) {
            throw ResourceDomainException::notFound();
        }
        $courseRow = (array) $course;

        $evidence = $this->buildEvidence($actor['organization_id'], $course, $documentType);
        $binding = $this->resolveTemplateBinding($documentType, CarbonImmutable::now());
        $hash = $this->evidenceHash($evidence);

        $latest = DB::table('formal_training_documents')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->where('document_type', $documentType)
            ->orderByDesc('revision')
            ->first();

        return [
            'course_enrollment_id' => $courseId,
            'document_type' => $documentType,
            'document_mode' => (string) $courseRow['document_mode'],
            'course_version' => (int) $courseRow['version'],
            'requirements_revision' => (int) $courseRow['requirements_revision'],
            'evidence_bundle_hash' => $hash,
            'template' => $binding,
            'totals' => $evidence['totals'],
            'latest_document' => $latest === null ? null : $this->presentDocument($latest),
            'freshness' => $latest !== null && (string) $latest->evidence_bundle_hash === $hash
                ? 'fresh'
                : 'regeneration_required',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(string $sessionId, string $courseId): array
    {
        $actor = $this->scope->requireCourseTarget($sessionId, 'formal_documents.view', $courseId);

        return array_values(DB::table('formal_training_documents')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->orderBy('document_type')
            ->orderByDesc('revision')
            ->get()
            ->map(fn ($row): array => $this->presentDocument($row))
            ->all());
    }

    /** @return array<string,mixed> */
    public function freshness(string $sessionId, string $courseId): array
    {
        $actor = $this->scope->requireCourseTarget($sessionId, 'formal_documents.view', $courseId);

        $course = DB::table('course_enrollments')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $courseId)
            ->first();
        if ($course === null) {
            throw ResourceDomainException::notFound();
        }
        $courseRow = (array) $course;

        $documents = [];
        foreach (self::DOCUMENT_TYPES as $documentType) {
            $documents[] = $this->freshnessForType(
                $actor['organization_id'],
                $course,
                $documentType,
            );
        }

        return [
            'course_enrollment_id' => $courseId,
            'course_version' => (int) $courseRow['version'],
            'requirements_revision' => (int) $courseRow['requirements_revision'],
            'documents' => $documents,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function events(string $sessionId, string $documentId): array
    {
        $membership = $this->scope->visibility($sessionId, 'formal_documents.view')['membership'];

        $document = DB::table('formal_training_documents')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $documentId)
            ->first();
        if ($document === null) {
            throw ResourceDomainException::notFound();
        }
        $documentRow = (array) $document;

        $this->scope->requireCourseTarget(
            $sessionId,
            'formal_documents.view',
            (string) $documentRow['course_enrollment_id'],
        );

        return array_values(DB::table('formal_training_document_events')
            ->where('organization_id', $membership['organization_id'])
            ->where('formal_training_document_id', $documentId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => $this->presentEvent($row))
            ->all());
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordDeliveryEvent(
        string $sessionId,
        string $documentId,
        array $input,
        string $requestId,
    ): array {
        $eventType = (string) ($input['event_type'] ?? '');
        if (! in_array($eventType, self::DELIVERY_EVENT_TYPES, true)) {
            throw ResourceDomainException::rule('Unsupported formal document delivery event.');
        }

        $membership = $this->scope->visibility($sessionId, 'formal_documents.deliver')['membership'];

        return DB::transaction(function () use (
            $sessionId,
            $documentId,
            $eventType,
            $input,
            $requestId,
            $membership,
        ): array {
            $document = DB::table('formal_training_documents')
                ->where('organization_id', $membership['organization_id'])
                ->where('id', $documentId)
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                throw ResourceDomainException::notFound();
            }
            $documentRow = (array) $document;

            $actor = $this->scope->requireCourseTarget(
                $sessionId,
                'formal_documents.deliver',
                (string) $documentRow['course_enrollment_id'],
            );

            $assetId = $this->nullableString($input['optional_asset_id'] ?? null);
            $reason = $this->nullableString($input['reason'] ?? null);
            $mode = (string) $documentRow['document_mode_snapshot'];

            if ($eventType === 'printed') {
                if ($mode !== 'paper') {
                    throw ResourceDomainException::rule('Only paper-mode formal documents may be recorded as printed.');
                }
                if ($assetId !== null) {
                    throw ResourceDomainException::rule('Printed event must not bind an optional asset.');
                }
            } elseif ($eventType === 'electronic_presented') {
                if ($mode !== 'electronic') {
                    throw ResourceDomainException::rule('Only electronic-mode formal documents may be recorded as electronically presented.');
                }
                if ($assetId !== null) {
                    throw ResourceDomainException::rule('Electronic presentation event must not bind an optional asset.');
                }
            } else {
                if ($mode !== 'paper') {
                    throw ResourceDomainException::rule('Signed scan attachment is only valid for paper-mode formal documents.');
                }
                if ($assetId === null) {
                    throw ResourceDomainException::rule('Signed scan attachment requires a ready asset.');
                }

                $asset = DB::table('file_assets')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $assetId)
                    ->where('purpose', self::SIGNED_SCAN_ASSET_PURPOSE)
                    ->where('status', 'ready')
                    ->whereNull('deleted_at')
                    ->first();

                if ($asset === null) {
                    throw ResourceDomainException::rule('Signed scan asset is not ready for the required tenant purpose.');
                }
                $assetRow = (array) $asset;
                if (preg_match('/^[a-f0-9]{64}$/', (string) ($assetRow['sha256'] ?? '')) !== 1) {
                    throw ResourceDomainException::rule('Signed scan asset must have a verified SHA-256 before attachment.');
                }
            }

            $eventId = (string) Str::uuid7();
            $now = CarbonImmutable::now();

            DB::table('formal_training_document_events')->insert([
                'id' => $eventId,
                'organization_id' => $actor['organization_id'],
                'formal_training_document_id' => $documentId,
                'event_type' => $eventType,
                'actor_user_id' => $actor['user_id'],
                'reason' => $reason,
                'optional_asset_id' => $assetId,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'formal_document.delivery_recorded',
                'formal_training_document',
                $documentId,
                $requestId,
                ['fields' => ['delivery_state'], 'state' => 'approved'],
                ['fields' => ['delivery_state'], 'state' => $eventType],
            );

            $row = DB::table('formal_training_document_events')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $eventId)
                ->first();
            if ($row === null) {
                throw ResourceDomainException::conflict('Formal document delivery event was not persisted.');
            }

            return $this->presentEvent($row);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function approve(
        string $sessionId,
        string $courseId,
        array $input,
        string $requestId,
        ?string $expectedCourseTag,
    ): array {
        $documentType = (string) ($input['document_type'] ?? '');
        $this->assertDocumentType($documentType);
        $actor = $this->scope->requireCourseTarget($sessionId, 'formal_documents.approve', $courseId);

        $stored = null;

        try {
            return DB::transaction(function () use (
                $actor,
                $courseId,
                $documentType,
                $input,
                $requestId,
                $expectedCourseTag,
                &$stored,
            ): array {
                $course = DB::table('course_enrollments')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $courseId)
                    ->lockForUpdate()
                    ->first();
                if ($course === null) {
                    throw ResourceDomainException::notFound();
                }
                $courseRow = (array) $course;

                $this->assertExpectedCourseVersion($course, $expectedCourseTag);
                if ((int) ($input['requirements_revision'] ?? 0) !== (int) $courseRow['requirements_revision']) {
                    throw ResourceDomainException::conflict('Training requirements changed since formal document preview.');
                }

                $evidence = $this->buildEvidence($actor['organization_id'], $course, $documentType);
                $evidenceHash = $this->evidenceHash($evidence);
                if (! hash_equals($evidenceHash, (string) ($input['evidence_bundle_hash'] ?? ''))) {
                    throw ResourceDomainException::conflict('Formal document evidence changed since preview.');
                }

                $binding = $this->resolveTemplateBinding($documentType, CarbonImmutable::now());
                $this->assertRequestedTemplateBinding($binding, $input);

                $existing = DB::table('formal_training_documents')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('course_enrollment_id', $courseId)
                    ->where('document_type', $documentType)
                    ->where('evidence_bundle_hash', $evidenceHash)
                    ->where('template_version_snapshot', $binding['template_version'])
                    ->where('renderer_version_snapshot', $binding['renderer_version'])
                    ->first();
                if ($existing !== null) {
                    return $this->presentDocument($existing);
                }

                $bytes = $this->renderer->render([
                    ...$evidence,
                    'document_type' => $documentType,
                    'template_version' => $binding['template_version'],
                    'renderer_version' => $binding['renderer_version'],
                    'template_content_hash' => $binding['template_content_hash'],
                    'evidence_bundle_hash' => $evidenceHash,
                ]);
                $contentHash = hash('sha256', $bytes);
                $assetId = (string) Str::uuid7();
                $documentId = (string) Str::uuid7();
                $now = CarbonImmutable::now();
                $disk = $this->storageDisk();
                $storageKey = 'formal-training-documents/'
                    .$actor['organization_id'].'/'.$courseId.'/'.$documentType.'/'.$assetId.'.pdf';

                if (Storage::disk($disk)->put($storageKey, $bytes) !== true) {
                    throw ResourceDomainException::conflict('Unable to persist canonical formal training document.');
                }
                $stored = ['disk' => $disk, 'key' => $storageKey];

                DB::table('file_assets')->insert([
                    'id' => $assetId,
                    'organization_id' => $actor['organization_id'],
                    'storage_disk' => $disk,
                    'storage_key' => $storageKey,
                    'original_filename' => $this->filename($documentType, $courseId),
                    'mime_type_declared' => 'application/pdf',
                    'mime_type_detected' => 'application/pdf',
                    'size_bytes' => strlen($bytes),
                    'sha256' => $contentHash,
                    'purpose' => self::ASSET_PURPOSE,
                    'status' => 'ready',
                    'created_by_user_id' => $actor['user_id'],
                    'created_at' => $now,
                    'ready_at' => $now,
                    'deleted_at' => null,
                ]);

                $revision = ((int) DB::table('formal_training_documents')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('course_enrollment_id', $courseId)
                    ->where('document_type', $documentType)
                    ->max('revision')) + 1;

                DB::table('formal_training_documents')->insert([
                    'id' => $documentId,
                    'organization_id' => $actor['organization_id'],
                    'course_enrollment_id' => $courseId,
                    'document_type' => $documentType,
                    'revision' => $revision,
                    'document_mode_snapshot' => (string) $courseRow['document_mode'],
                    'formal_training_document_template_id' => $binding['id'],
                    'template_version_snapshot' => $binding['template_version'],
                    'renderer_version_snapshot' => $binding['renderer_version'],
                    'template_hash_snapshot' => $binding['template_content_hash'],
                    'course_version_snapshot' => (int) $courseRow['version'],
                    'requirements_revision_snapshot' => (int) $courseRow['requirements_revision'],
                    'evidence_bundle_hash' => $evidenceHash,
                    'asset_id' => $assetId,
                    'content_hash' => $contentHash,
                    'approved_by_user_id' => $actor['user_id'],
                    'approved_at' => $now,
                    'generated_by_user_id' => $actor['user_id'],
                    'generated_at' => $now,
                    'created_at' => $now,
                ]);

                foreach (['generated', 'approved'] as $eventType) {
                    DB::table('formal_training_document_events')->insert([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $actor['organization_id'],
                        'formal_training_document_id' => $documentId,
                        'event_type' => $eventType,
                        'actor_user_id' => $actor['user_id'],
                        'reason' => null,
                        'optional_asset_id' => null,
                        'occurred_at' => $now,
                        'created_at' => $now,
                    ]);
                }

                $this->auditOutbox->recordOrganizationEvent(
                    $actor['organization_id'],
                    $actor['id'],
                    $actor['user_id'],
                    'formal_document.approved',
                    'formal_training_document',
                    $documentId,
                    $requestId,
                    ['fields' => ['document_type'], 'state' => 'absent', 'document_type' => $documentType],
                    [
                        'fields' => ['document_type', 'revision', 'evidence_bundle_hash'],
                        'state' => 'approved',
                        'document_type' => $documentType,
                    ],
                );

                $row = DB::table('formal_training_documents')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $documentId)
                    ->first();
                if ($row === null) {
                    throw ResourceDomainException::conflict('Approved formal document was not persisted.');
                }

                return $this->presentDocument($row);
            });
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                Storage::disk((string) $stored['disk'])->delete((string) $stored['key']);
            }

            throw $exception;
        }
    }

    /** @return array{bytes:string,filename:string,content_hash:string,document_id:string} */
    public function download(string $sessionId, string $documentId, string $requestId): array
    {
        $membership = $this->scope->visibility($sessionId, 'formal_documents.download')['membership'];

        return DB::transaction(function () use ($sessionId, $membership, $documentId, $requestId): array {
            $document = DB::table('formal_training_documents')
                ->where('organization_id', $membership['organization_id'])
                ->where('id', $documentId)
                ->first();
            if ($document === null) {
                throw ResourceDomainException::notFound();
            }

            $actor = $this->scope->requireCourseTarget(
                $sessionId,
                'formal_documents.download',
                (string) $document->course_enrollment_id,
            );

            $asset = DB::table('file_assets')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $document->asset_id)
                ->first();
            if ($asset === null
                || (string) $asset->status !== 'ready'
                || (string) $asset->purpose !== self::ASSET_PURPOSE
                || $asset->deleted_at !== null
                || (string) $asset->sha256 !== (string) $document->content_hash) {
                throw ResourceDomainException::conflict('Canonical formal document asset metadata is invalid.');
            }

            $bytes = Storage::disk((string) $asset->storage_disk)->get((string) $asset->storage_key);
            if (! is_string($bytes)
                || hash('sha256', $bytes) !== (string) $document->content_hash
                || strlen($bytes) !== (int) $asset->size_bytes) {
                throw ResourceDomainException::conflict('Stored formal document bytes do not match immutable content hash.');
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'formal_document.downloaded',
                'formal_training_document',
                $documentId,
                $requestId,
                ['fields' => ['document_type'], 'state' => 'ready', 'document_type' => (string) $document->document_type],
                ['fields' => ['document_type'], 'state' => 'served', 'document_type' => (string) $document->document_type],
            );

            return [
                'bytes' => $bytes,
                'filename' => $this->filename((string) $document->document_type, (string) $document->course_enrollment_id),
                'content_hash' => (string) $document->content_hash,
                'document_id' => $documentId,
            ];
        });
    }

    /**
     * @return array{id:string,document_type:string,template_version:string,renderer_version:string,template_content_hash:string}
     */
    private function resolveTemplateBinding(string $documentType, CarbonImmutable $at): array
    {
        $templates = DB::table('formal_training_document_templates')
            ->where('document_type', $documentType)
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->sharedLock()
            ->get();

        if ($templates->count() !== 1) {
            throw ResourceDomainException::conflict('Exactly one formal document template must be effective.');
        }

        $template = $templates->first();
        if ($template === null
            || (string) $template->renderer_version !== FormalTrainingDocumentRenderer::RENDERER_VERSION
            || preg_match('/^[a-f0-9]{64}$/', (string) $template->template_content_hash) !== 1) {
            throw ResourceDomainException::conflict('Effective formal document template is unsupported or invalid.');
        }

        return [
            'id' => (string) $template->id,
            'document_type' => (string) $template->document_type,
            'template_version' => (string) $template->template_version,
            'renderer_version' => (string) $template->renderer_version,
            'template_content_hash' => (string) $template->template_content_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  TemplateBinding  $binding
     */
    private function assertRequestedTemplateBinding(array $binding, array $input): void
    {
        $requested = [
            'id' => (string) ($input['template_id'] ?? ''),
            'template_version' => (string) ($input['template_version'] ?? ''),
            'renderer_version' => (string) ($input['renderer_version'] ?? ''),
            'template_content_hash' => (string) ($input['template_content_hash'] ?? ''),
        ];

        foreach ($requested as $key => $value) {
            if (! hash_equals((string) $binding[$key], $value)) {
                throw ResourceDomainException::conflict('Formal document template binding changed since preview.');
            }
        }
    }

    /** @return EvidenceBundle */
    private function buildEvidence(string $organizationId, object $course, string $documentType): array
    {
        $courseRow = (array) $course;

        $student = DB::table('students')
            ->where('organization_id', $organizationId)
            ->where('id', $courseRow['student_id'])
            ->first();
        if ($student === null) {
            throw ResourceDomainException::conflict('Course student identity is unavailable.');
        }

        $category = DB::table('driving_categories')->where('id', $courseRow['driving_category_id'])->first();
        if ($category === null) {
            throw ResourceDomainException::conflict('Course driving category is unavailable.');
        }

        $instructor = DB::table('staff_profiles')
            ->where('organization_id', $organizationId)
            ->where('id', $courseRow['lead_instructor_id'])
            ->first();
        if ($instructor === null) {
            throw ResourceDomainException::conflict('Course lead instructor is unavailable.');
        }

        $requirements = DB::table('training_requirement_profiles')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseRow['id'])
            ->whereNull('superseded_at')
            ->get();
        if ($requirements->count() !== 1) {
            throw ResourceDomainException::conflict('Exactly one current training requirement profile is required.');
        }
        $requirement = $requirements->first();
        if ($requirement === null
            || (int) $requirement->requirements_revision !== (int) $courseRow['requirements_revision']) {
            throw ResourceDomainException::conflict('Current training requirement revision does not match CourseEnrollment.');
        }

        $ledgerRows = DB::table('training_hour_ledger_entries as ledger')
            ->leftJoin('training_sessions as sessions', function ($join): void {
                $join->on('sessions.id', '=', 'ledger.training_session_id')
                    ->on('sessions.organization_id', '=', 'ledger.organization_id');
            })
            ->leftJoin('staff_profiles as instructors', function ($join): void {
                $join->on('instructors.id', '=', 'sessions.instructor_id')
                    ->on('instructors.organization_id', '=', 'sessions.organization_id');
            })
            ->where('ledger.organization_id', $organizationId)
            ->where('ledger.course_enrollment_id', $courseRow['id'])
            ->orderBy('ledger.created_at')
            ->orderBy('ledger.id')
            ->select([
                'ledger.id',
                'ledger.training_session_id',
                'ledger.entry_type',
                'ledger.training_part',
                'ledger.minutes',
                'ledger.source_entry_id',
                'ledger.created_at',
                'sessions.starts_at',
                'sessions.ends_at',
                'sessions.instructor_id',
                'instructors.first_name as instructor_first_name',
                'instructors.last_name as instructor_last_name',
                'instructors.authorization_number as instructor_authorization_number',
            ])
            ->get();

        $ledger = [];
        $oskTheory = 0;
        $oskPractical = 0;
        foreach ($ledgerRows as $row) {
            $minutes = (int) $row->minutes;
            if ((string) $row->training_part === 'theory') {
                $oskTheory += $minutes;
            } elseif ((string) $row->training_part === 'practical') {
                $oskPractical += $minutes;
            }

            $instructorName = trim(
                (string) ($row->instructor_first_name ?? '').' '.(string) ($row->instructor_last_name ?? '')
            );
            $sourceTime = $row->starts_at ?? $row->created_at;
            $ledger[] = [
                'id' => (string) $row->id,
                'training_session_id' => $this->nullableString($row->training_session_id),
                'entry_type' => (string) $row->entry_type,
                'training_part' => (string) $row->training_part,
                'minutes' => $minutes,
                'source_entry_id' => $this->nullableString($row->source_entry_id),
                'date' => CarbonImmutable::parse((string) $sourceTime)->utc()->format('Y-m-d'),
                'session_starts_at' => $this->nullableTimestamp($row->starts_at),
                'session_ends_at' => $this->nullableTimestamp($row->ends_at),
                'instructor_id' => $this->nullableString($row->instructor_id),
                'instructor_name' => $instructorName === '' ? null : $instructorName,
                'instructor_authorization_number' => $this->nullableString($row->instructor_authorization_number),
                'ledger_created_at' => $this->timestamp($row->created_at),
            ];
        }

        $externalRows = DB::table('recognized_external_training')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', $courseRow['id'])
            ->whereNull('superseded_at')
            ->whereNull('revoked_at')
            ->where('recognized_for_driving_category_id', (string) $courseRow['driving_category_id'])
            ->where('recognized_for_training_type', (string) $courseRow['training_type'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $external = [];
        $externalTheory = 0;
        $externalPractical = 0;
        foreach ($externalRows as $row) {
            $minutes = (int) $row->recognized_minutes;
            if ((string) $row->training_part === 'theory') {
                $externalTheory += $minutes;
            } elseif ((string) $row->training_part === 'practical') {
                $externalPractical += $minutes;
            }

            $external[] = [
                'id' => (string) $row->id,
                'training_part' => (string) $row->training_part,
                'recognized_minutes' => $minutes,
                'record_role' => (string) $row->record_role,
                'source_kind' => (string) $row->source_kind,
                'source_school_reference' => $this->nullableString($row->source_school_reference),
                'evidence_reference' => $this->nullableString($row->evidence_reference),
                'created_at' => $this->timestamp($row->created_at),
            ];
        }

        return [
            'document_type' => $documentType,
            'student' => [
                'id' => (string) $student->id,
                'first_name' => (string) $student->first_name,
                'last_name' => (string) $student->last_name,
                'birth_date' => $student->birth_date === null ? null : (string) $student->birth_date,
                'no_pesel_declared' => (bool) $student->no_pesel_declared,
            ],
            'course' => [
                'id' => (string) $courseRow['id'],
                'version' => (int) $courseRow['version'],
                'requirements_revision' => (int) $courseRow['requirements_revision'],
                'training_type' => (string) $courseRow['training_type'],
                'driving_category_id' => (string) $courseRow['driving_category_id'],
                'driving_category_code' => (string) $category->code,
                'started_at' => $this->timestamp($courseRow['started_at']),
                'lead_instructor_id' => (string) $courseRow['lead_instructor_id'],
                'location_id' => $this->nullableString($courseRow['location_id']),
                'document_mode' => (string) $courseRow['document_mode'],
            ],
            'lead_instructor' => [
                'id' => (string) $instructor->id,
                'first_name' => (string) $instructor->first_name,
                'last_name' => (string) $instructor->last_name,
                'authorization_number' => $this->nullableString($instructor->authorization_number),
            ],
            'requirements' => [
                'id' => (string) $requirement->id,
                'requirements_revision' => (int) $requirement->requirements_revision,
                'rule_set_version' => (string) $requirement->rule_set_version,
                'theory_training_required' => (bool) $requirement->theory_training_required,
                'minimum_theory_minutes' => (int) $requirement->minimum_theory_minutes,
                'internal_theory_exam_required' => (bool) $requirement->internal_theory_exam_required,
                'practical_training_required' => (bool) $requirement->practical_training_required,
                'minimum_practical_minutes' => (int) $requirement->minimum_practical_minutes,
                'internal_practical_exam_required' => (bool) $requirement->internal_practical_exam_required,
                'exemption_basis_code' => $this->nullableString($requirement->exemption_basis_code),
            ],
            'ledger' => $ledger,
            'external_training' => $external,
            'totals' => [
                'osk_theory_minutes' => $oskTheory,
                'osk_practical_minutes' => $oskPractical,
                'external_theory_minutes' => $externalTheory,
                'external_practical_minutes' => $externalPractical,
                'combined_theory_minutes' => $oskTheory + $externalTheory,
                'combined_practical_minutes' => $oskPractical + $externalPractical,
            ],
        ];
    }

    /** @param EvidenceBundle $evidence */
    private function evidenceHash(array $evidence): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($evidence),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function assertExpectedCourseVersion(object $course, ?string $expectedTag): void
    {
        $courseRow = (array) $course;

        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current CourseEnrollment version is required.',
            );
        }

        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $courseRow['version']) {
            throw ResourceDomainException::conflict('CourseEnrollment changed since formal document preview.');
        }
    }

    /** @return array<string,mixed> */
    private function freshnessForType(string $organizationId, object $course, string $documentType): array
    {
        $courseRow = (array) $course;
        $currentHash = $this->evidenceHash($this->buildEvidence($organizationId, $course, $documentType));

        $latest = DB::table('formal_training_documents')
            ->where('organization_id', $organizationId)
            ->where('course_enrollment_id', (string) $courseRow['id'])
            ->where('document_type', $documentType)
            ->orderByDesc('revision')
            ->first();

        if ($latest === null) {
            return [
                'document_type' => $documentType,
                'freshness' => 'regeneration_required',
                'current_evidence_bundle_hash' => $currentHash,
                'latest_document_id' => null,
                'latest_revision' => null,
                'latest_evidence_bundle_hash' => null,
            ];
        }
        $latestRow = (array) $latest;
        $latestHash = (string) $latestRow['evidence_bundle_hash'];

        return [
            'document_type' => $documentType,
            'freshness' => hash_equals($latestHash, $currentHash) ? 'fresh' : 'regeneration_required',
            'current_evidence_bundle_hash' => $currentHash,
            'latest_document_id' => (string) $latestRow['id'],
            'latest_revision' => (int) $latestRow['revision'],
            'latest_evidence_bundle_hash' => $latestHash,
        ];
    }

    /** @return array<string,mixed> */
    private function presentEvent(object $row): array
    {
        $data = (array) $row;

        return [
            'id' => (string) $data['id'],
            'formal_training_document_id' => (string) $data['formal_training_document_id'],
            'event_type' => (string) $data['event_type'],
            'actor_user_id' => $this->nullableString($data['actor_user_id']),
            'reason' => $this->nullableString($data['reason']),
            'optional_asset_id' => $this->nullableString($data['optional_asset_id']),
            'occurred_at' => $this->timestamp($data['occurred_at']),
            'created_at' => $this->timestamp($data['created_at']),
        ];
    }

    private function assertDocumentType(string $documentType): void
    {
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw ResourceDomainException::rule('Unsupported formal training document type.');
        }
    }

    /** @return array<string,mixed> */
    private function presentDocument(object $row): array
    {
        $data = (array) $row;

        return [
            'id' => (string) $data['id'],
            'course_enrollment_id' => (string) $data['course_enrollment_id'],
            'document_type' => (string) $data['document_type'],
            'revision' => (int) $data['revision'],
            'document_mode_snapshot' => (string) $data['document_mode_snapshot'],
            'template_id' => (string) $data['formal_training_document_template_id'],
            'template_version' => (string) $data['template_version_snapshot'],
            'renderer_version' => (string) $data['renderer_version_snapshot'],
            'template_content_hash' => (string) $data['template_hash_snapshot'],
            'course_version' => (int) $data['course_version_snapshot'],
            'requirements_revision' => (int) $data['requirements_revision_snapshot'],
            'evidence_bundle_hash' => (string) $data['evidence_bundle_hash'],
            'content_hash' => (string) $data['content_hash'],
            'approved_by_user_id' => $this->nullableString($data['approved_by_user_id']),
            'approved_at' => $this->nullableTimestamp($data['approved_at']),
            'generated_at' => $this->timestamp($data['generated_at']),
            'created_at' => $this->timestamp($data['created_at']),
        ];
    }

    private function storageDisk(): string
    {
        $disk = config('formal_documents.document_storage_disk');
        if (! is_string($disk) || trim($disk) === '') {
            throw ResourceDomainException::conflict('Formal document storage disk is not configured.');
        }

        return $disk;
    }

    private function filename(string $documentType, string $courseId): string
    {
        return ($documentType === 'training_record_card' ? 'karta-ewidencji-szkolenia-' : 'dziennik-realizacji-teorii-')
            .$courseId.'.pdf';
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        return $value === null ? null : $this->timestamp($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || (string) $value === '' ? null : (string) $value;
    }
}
