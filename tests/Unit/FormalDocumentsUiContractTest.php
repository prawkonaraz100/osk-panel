<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormalDocumentsUiContractTest extends TestCase
{
    public function test_student_profile_materializes_exact_formal_document_and_signed_scan_workflow(): void
    {
        $root = dirname(__DIR__, 2);
        $studentWorkspace = file_get_contents($root.'/resources/js/modules/StudentsCourses/StudentCourseWorkspace.vue');
        $panel = file_get_contents($root.'/resources/js/modules/FormalDocuments/FormalTrainingDocumentsPanel.vue');

        $this->assertIsString($studentWorkspace);
        $this->assertIsString($panel);

        $this->assertStringContainsString(
            "import FormalTrainingDocumentsPanel from '../FormalDocuments/FormalTrainingDocumentsPanel.vue'",
            $studentWorkspace,
        );
        $this->assertStringContainsString('<FormalTrainingDocumentsPanel', $studentWorkspace);

        foreach ([
            '/formal-documents\`',
            '/formal-documents/freshness\`',
            '/formal-documents/preview?document_type=training_record_card',
            '/formal-documents/preview?document_type=theory_delivery_journal',
            '/formal-training-documents/\${document.id}/file',
            '/formal-training-documents/\${document.id}/events',
            '/formal-training-documents/\${document.id}/delivery-events',
            '/api/v1/uploads/presign',
            '/api/v1/uploads/\${presign.data.upload_id}/complete',
        ] as $endpoint) {
            $this->assertStringContainsString($endpoint, $panel);
        }

        $this->assertStringContainsString('headers: { \'If-Match\': \`"v\${preview.course_version}"\` }', $panel);
        $this->assertStringContainsString('requirements_revision: preview.requirements_revision', $panel);
        $this->assertStringContainsString('evidence_bundle_hash: preview.evidence_bundle_hash', $panel);
        $this->assertStringContainsString('template_content_hash: preview.template.template_content_hash', $panel);
        $this->assertStringContainsString("freshnessFor(type) === 'fresh'", $panel);

        $this->assertStringContainsString("purpose: 'formal_training_signed_scan'", $panel);
        $this->assertStringContainsString("parent_type: 'formal_training_document'", $panel);
        $this->assertStringContainsString('parent_id: document.id', $panel);
        $this->assertStringContainsString("method: 'PUT'", $panel);
        $this->assertStringContainsString("event_type: 'signed_scan_attached'", $panel);
        $this->assertStringContainsString('Ponów tylko zapis zdarzenia — nie przesyłaj pliku drugi raz.', $panel);

        $this->assertStringContainsString("document_mode_snapshot === 'paper'", $panel);
        $this->assertStringContainsString("document_mode_snapshot === 'electronic'", $panel);
        $this->assertStringContainsString('nie zastępuje kanonicznego PDF', $panel);
        $this->assertStringContainsString('podpisu kwalifikowanego', $panel);

        $this->assertStringNotContainsString('XAdES', $panel);
        $this->assertStringNotContainsString('/pkk', $panel);
    }
}
