<?php

namespace App\Modules\InternalExams;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @phpstan-type AnswerSheetAttempt object{id:mixed,organization_id:mixed,status:mixed,started_at:mixed,finished_at:mixed,candidate_snapshot:mixed,requirement_basis_snapshot:mixed,exam_part:mixed}
 * @phpstan-type AnswerSheetResult object{answer_sheet_template_binding_snapshot:mixed,evidence_bundle_hash:mixed,result_snapshot:mixed,score:mixed,max_score:mixed,passed:mixed}
 * @phpstan-type AnswerSheetTemplate object{id:mixed,document_type:mixed,exam_part:mixed,template_version:mixed,renderer_version:mixed,template_content_hash:mixed}
 * @phpstan-type AnswerSheetDocument object{id:mixed,internal_exam_document_template_id:mixed,template_version_snapshot:mixed,renderer_version_snapshot:mixed,template_hash_snapshot:mixed,evidence_bundle_hash:mixed,asset_id:mixed,content_hash:mixed}
 * @phpstan-type AnswerSheetAsset object{storage_disk:mixed,storage_key:mixed,status:mixed,purpose:mixed,deleted_at:mixed,sha256:mixed,size_bytes:mixed}
 */
final class InternalExamAnswerSheetService
{
    public const DOCUMENT_TYPE = 'internal_exam_answer_sheet';

    public const ASSET_PURPOSE = 'internal_exam_answer_sheet';

    public function __construct(
        private readonly InternalExamScopeAuthorizer $scope,
        private readonly InternalExamAnswerSheetRenderer $renderer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} */
    public function resolveTemplateBinding(string $examPart, CarbonImmutable $finishedAt): array
    {
        $rows = DB::table('internal_exam_document_templates')
            ->where('document_type', self::DOCUMENT_TYPE)
            ->where('exam_part', $examPart)
            ->where('effective_from', '<=', $finishedAt)
            ->where(function ($query) use ($finishedAt): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $finishedAt);
            })
            ->sharedLock()
            ->get();

        if ($rows->count() !== 1) {
            throw ResourceDomainException::conflict('Exactly one answer-sheet template must be effective when the exam finishes.');
        }

        /** @var AnswerSheetTemplate|null $template */
        $template = $rows->first();
        if ($template === null
            || (string) $template->renderer_version !== InternalExamAnswerSheetRenderer::RENDERER_VERSION
            || preg_match('/^[a-f0-9]{64}$/', (string) $template->template_content_hash) !== 1) {
            throw ResourceDomainException::conflict('Effective answer-sheet template is unsupported or invalid.');
        }

        return [
            'id' => (string) $template->id,
            'document_type' => (string) $template->document_type,
            'exam_part' => (string) $template->exam_part,
            'template_version' => (string) $template->template_version,
            'renderer_version' => (string) $template->renderer_version,
            'template_content_hash' => (string) $template->template_content_hash,
        ];
    }

    /** @return array{bytes:string,filename:string,content_hash:string,document_id:string} */
    public function download(string $sessionId, string $attemptId, string $requestId): array
    {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.documents.download', $attemptId);

        return DB::transaction(function () use ($actor, $attemptId, $requestId): array {
            /** @var AnswerSheetAttempt|null $attempt */
            $attempt = DB::table('internal_exam_attempts')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                throw ResourceDomainException::notFound();
            }
            if (! in_array((string) $attempt->status, ['passed', 'failed'], true) || $attempt->finished_at === null) {
                throw ResourceDomainException::conflict('Answer sheet is available only for a finished scored attempt.');
            }

            /** @var AnswerSheetResult|null $result */
            $result = DB::table('internal_exam_results')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->first();
            if ($result === null) {
                throw ResourceDomainException::conflict('Finished attempt is missing immutable result evidence.');
            }

            $binding = $this->decodeBinding($result->answer_sheet_template_binding_snapshot);
            /** @var AnswerSheetTemplate|null $template */
            $template = DB::table('internal_exam_document_templates')->where('id', $binding['id'])->first();
            if ($template === null
                || (string) $template->document_type !== $binding['document_type']
                || (string) $template->exam_part !== $binding['exam_part']
                || (string) $template->template_version !== $binding['template_version']
                || (string) $template->renderer_version !== $binding['renderer_version']
                || (string) $template->template_content_hash !== $binding['template_content_hash']) {
                throw ResourceDomainException::conflict('Frozen answer-sheet template binding no longer matches immutable template history.');
            }

            /** @var AnswerSheetDocument|null $document */
            $document = DB::table('internal_exam_documents')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('document_type', self::DOCUMENT_TYPE)
                ->first();

            if ($document === null) {
                [$document, $bytes] = $this->generateDocument(
                    $actor['organization_id'],
                    $actor['user_id'],
                    $attempt,
                    $result,
                    $binding,
                );
            } else {
                $bytes = $this->readOrRestoreDocument(
                    $actor['organization_id'],
                    $attempt,
                    $result,
                    $binding,
                    $document,
                );
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'internal_exam.answer_sheet.downloaded',
                'internal_exam_document',
                (string) $document->id,
                $requestId,
                ['fields' => ['document_type'], 'state' => 'ready'],
                ['fields' => ['document_type'], 'state' => 'served'],
            );

            return [
                'bytes' => $bytes,
                'filename' => 'arkusz-odpowiedzi-'.$attemptId.'.pdf',
                'content_hash' => (string) $document->content_hash,
                'document_id' => (string) $document->id,
            ];
        });
    }

    /**
     * @param AnswerSheetAttempt $attempt
     * @param AnswerSheetResult $result
     * @param array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} $binding
     * @return array{0:object,1:string}
     */
    private function generateDocument(
        string $organizationId,
        string $actorUserId,
        object $attempt,
        object $result,
        array $binding,
    ): array {
        $bytes = $this->renderFrozen($attempt, $result, $binding);
        $contentHash = hash('sha256', $bytes);
        $disk = $this->storageDisk();
        $storageKey = 'internal-exams/'.$organizationId.'/'.$attempt->id.'/answer-sheet/'.$contentHash.'.pdf';

        if (Storage::disk($disk)->put($storageKey, $bytes) !== true) {
            throw ResourceDomainException::conflict('Unable to persist canonical answer-sheet artifact.');
        }

        $assetId = (string) Str::uuid7();
        $documentId = (string) Str::uuid7();
        $now = CarbonImmutable::now();
        DB::table('file_assets')->insert([
            'id' => $assetId,
            'organization_id' => $organizationId,
            'storage_disk' => $disk,
            'storage_key' => $storageKey,
            'original_filename' => 'arkusz-odpowiedzi-'.$attempt->id.'.pdf',
            'mime_type_declared' => 'application/pdf',
            'mime_type_detected' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => $contentHash,
            'purpose' => self::ASSET_PURPOSE,
            'status' => 'ready',
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
            'ready_at' => $now,
            'deleted_at' => null,
        ]);
        DB::table('internal_exam_documents')->insert([
            'id' => $documentId,
            'organization_id' => $organizationId,
            'internal_exam_attempt_id' => (string) $attempt->id,
            'document_type' => self::DOCUMENT_TYPE,
            'internal_exam_document_template_id' => $binding['id'],
            'template_version_snapshot' => $binding['template_version'],
            'renderer_version_snapshot' => $binding['renderer_version'],
            'template_hash_snapshot' => $binding['template_content_hash'],
            'evidence_bundle_hash' => (string) $result->evidence_bundle_hash,
            'asset_id' => $assetId,
            'content_hash' => $contentHash,
            'generated_at' => $now,
            'generated_by_user_id' => $actorUserId,
            'created_at' => $now,
        ]);

        $document = DB::table('internal_exam_documents')
            ->where('organization_id', $organizationId)
            ->where('id', $documentId)
            ->first();
        if ($document === null) {
            throw ResourceDomainException::conflict('Canonical answer-sheet document was not persisted.');
        }

        return [$document, $bytes];
    }

    /**
     * @param AnswerSheetAttempt $attempt
     * @param AnswerSheetResult $result
     * @param array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} $binding
     * @param AnswerSheetDocument $document
     */
    private function readOrRestoreDocument(
        string $organizationId,
        object $attempt,
        object $result,
        array $binding,
        object $document,
    ): string {
        if ((string) $document->internal_exam_document_template_id !== $binding['id']
            || (string) $document->template_version_snapshot !== $binding['template_version']
            || (string) $document->renderer_version_snapshot !== $binding['renderer_version']
            || (string) $document->template_hash_snapshot !== $binding['template_content_hash']
            || (string) $document->evidence_bundle_hash !== (string) $result->evidence_bundle_hash) {
            throw ResourceDomainException::conflict('Canonical answer-sheet document does not match frozen evidence/template binding.');
        }

        /** @var AnswerSheetAsset|null $asset */
        $asset = DB::table('file_assets')
            ->where('organization_id', $organizationId)
            ->where('id', $document->asset_id)
            ->first();
        if ($asset === null
            || (string) $asset->status !== 'ready'
            || (string) $asset->purpose !== self::ASSET_PURPOSE
            || $asset->deleted_at !== null
            || (string) $asset->sha256 !== (string) $document->content_hash) {
            throw ResourceDomainException::conflict('Canonical answer-sheet asset metadata is invalid.');
        }

        $disk = (string) $asset->storage_disk;
        $key = (string) $asset->storage_key;
        if (Storage::disk($disk)->exists($key)) {
            $bytes = Storage::disk($disk)->get($key);
            if (hash('sha256', $bytes) !== (string) $document->content_hash
                || strlen($bytes) !== (int) $asset->size_bytes) {
                throw ResourceDomainException::conflict('Stored answer-sheet bytes do not match immutable document hash.');
            }

            return $bytes;
        }

        $bytes = $this->renderFrozen($attempt, $result, $binding);
        if (hash('sha256', $bytes) !== (string) $document->content_hash) {
            throw ResourceDomainException::conflict('Deterministic answer-sheet regeneration does not match immutable document hash.');
        }
        if (Storage::disk($disk)->put($key, $bytes) !== true) {
            throw ResourceDomainException::conflict('Unable to restore verified canonical answer-sheet bytes.');
        }

        return $bytes;
    }

    /**
     * @param AnswerSheetAttempt $attempt
     * @param AnswerSheetResult $result
     * @param array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} $binding
     */
    private function renderFrozen(object $attempt, object $result, array $binding): string
    {
        $candidate = json_decode((string) $attempt->candidate_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($candidate)) {
            throw ResourceDomainException::conflict('Frozen candidate snapshot is invalid.');
        }
        $resultSnapshot = json_decode((string) $result->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($resultSnapshot)) {
            throw ResourceDomainException::conflict('Immutable result snapshot is invalid.');
        }

        $requirementBasis = json_decode((string) $attempt->requirement_basis_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $categoryCode = is_array($requirementBasis) ? ($requirementBasis['driving_category_code'] ?? null) : null;
        if (! is_string($categoryCode) || $categoryCode === '') {
            throw ResourceDomainException::conflict('Frozen exam category snapshot is unavailable.');
        }

        $questions = DB::table('internal_exam_attempt_questions')
            ->where('organization_id', $attempt->organization_id)
            ->where('internal_exam_attempt_id', $attempt->id)
            ->orderBy('ordinal')
            ->get();
        if ($questions->count() === 0) {
            throw ResourceDomainException::conflict('Answer sheet requires immutable question evidence.');
        }

        $questionPayload = [];
        foreach ($questions as $question) {
            if ($question->candidate_answer === null
                || $question->points_awarded === null
                || $question->is_correct === null) {
                throw ResourceDomainException::conflict('Answer sheet requires finalized question evidence.');
            }
            $questionPayload[] = [
                'ordinal' => (int) $question->ordinal,
                'group' => (string) $question->group,
                'identifier' => $question->source_question_identifier === null
                    ? null
                    : (string) $question->source_question_identifier,
                'max_points' => (int) $question->max_points_snapshot,
                'answer' => json_decode((string) $question->candidate_answer, true, 512, JSON_THROW_ON_ERROR),
                'awarded_points' => (int) $question->points_awarded,
            ];
        }

        if ($attempt->started_at === null) {
            throw ResourceDomainException::conflict('Finished attempt is missing frozen exam start time.');
        }
        $startedAt = CarbonImmutable::parse((string) $attempt->started_at);

        return $this->renderer->render([
            'template_version' => $binding['template_version'],
            'renderer_version' => $binding['renderer_version'],
            'template_content_hash' => $binding['template_content_hash'],
            'evidence_bundle_hash' => (string) $result->evidence_bundle_hash,
            'candidate_snapshot' => $candidate,
            'exam_date' => $startedAt->format('d-m-Y'),
            'driving_category_code' => $categoryCode,
            'exam_part' => (string) $attempt->exam_part,
            'questions' => $questionPayload,
            'score' => (int) $result->score,
            'max_score' => (int) $result->max_score,
            'passed' => (bool) $result->passed,
        ]);
    }

    /** @return array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} */
    private function decodeBinding(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            throw ResourceDomainException::conflict('Finished result is missing frozen answer-sheet template binding.');
        }
        $binding = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($binding)) {
            throw ResourceDomainException::conflict('Frozen answer-sheet template binding is invalid.');
        }

        foreach (['id', 'document_type', 'exam_part', 'template_version', 'renderer_version', 'template_content_hash'] as $key) {
            if (! isset($binding[$key]) || ! is_string($binding[$key]) || $binding[$key] === '') {
                throw ResourceDomainException::conflict('Frozen answer-sheet template binding is incomplete.');
            }
        }
        if ($binding['document_type'] !== self::DOCUMENT_TYPE
            || $binding['renderer_version'] !== InternalExamAnswerSheetRenderer::RENDERER_VERSION
            || preg_match('/^[a-f0-9]{64}$/', $binding['template_content_hash']) !== 1) {
            throw ResourceDomainException::conflict('Frozen answer-sheet template binding is unsupported.');
        }

        /** @var array{id:string,document_type:string,exam_part:string,template_version:string,renderer_version:string,template_content_hash:string} $binding */
        return $binding;
    }

    private function storageDisk(): string
    {
        $disk = config('internal_exams.document_storage_disk');
        if (! is_string($disk) || trim($disk) === '') {
            throw ResourceDomainException::conflict('Internal exam document storage disk is not configured.');
        }

        return $disk;
    }
}
