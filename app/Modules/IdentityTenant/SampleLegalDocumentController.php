<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class SampleLegalDocumentController
{
    public function currentTerms(): JsonResponse
    {
        $terms = $this->termsConfig();
        $version = (string) $terms['version'];

        $exists = DB::table('legal_documents')
            ->where('document_type', 'terms')
            ->where('version', $version)
            ->where('published_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->exists();

        abort_unless($exists, 404);

        return response()->json([
            'document_type' => 'terms',
            'version' => $version,
            'document_url' => (string) $terms['document_url'],
            'sample_data' => true,
        ]);
    }

    public function termsDocument(): Response
    {
        $terms = $this->termsConfig();

        return response()
            ->view((string) $terms['view'])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed> */
    private function termsConfig(): array
    {
        abort_unless((bool) config('sample_data.enabled', false), 404);
        abort_if(app()->environment('production'), 404);

        $terms = config('sample_data.terms');
        abort_unless(is_array($terms), 404);

        return $terms;
    }
}
