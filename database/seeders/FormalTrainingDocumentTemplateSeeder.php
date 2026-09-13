<?php

namespace Database\Seeders;

use App\Modules\FormalDocuments\FormalTrainingDocumentRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FormalTrainingDocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['0199f88d-8d00-7000-8000-000000000101', 'training_record_card'],
                ['0199f88d-8d00-7000-8000-000000000102', 'theory_delivery_journal'],
            ] as [$templateId, $documentType]) {
                DB::table('formal_training_document_templates')->insertOrIgnore([
                    'id' => $templateId,
                    'document_type' => $documentType,
                    'template_version' => 'v1',
                    'renderer_version' => FormalTrainingDocumentRenderer::RENDERER_VERSION,
                    'template_content_hash' => hash(
                        'sha256',
                        'prawkonaraz|formal_training_document|'.$documentType.'|v1|'.FormalTrainingDocumentRenderer::RENDERER_VERSION,
                    ),
                    'effective_from' => '2026-01-01 00:00:00+00',
                    'effective_to' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
