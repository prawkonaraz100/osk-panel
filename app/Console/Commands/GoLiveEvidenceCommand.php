<?php

namespace App\Console\Commands;

use App\Support\Operations\GoLiveEvidenceValidator;
use Illuminate\Console\Command;
use Throwable;

final class GoLiveEvidenceCommand extends Command
{
    protected $signature = 'operations:go-live:evidence
        {manifest : Path to the sanitized go-live evidence JSON manifest}
        {--release= : Exact 40-character release Git SHA}
        {--artifact-sha256= : Exact SHA-256 of the immutable release archive}
        {--json : Emit machine-readable result}';

    protected $description = 'Validate the complete external go-live evidence set for one immutable release without activating production.';

    public function handle(GoLiveEvidenceValidator $validator): int
    {
        $manifest = $this->argument('manifest');
        $release = $this->option('release');
        $artifactSha256 = $this->option('artifact-sha256');

        if (! is_string($release) || ! is_string($artifactSha256)) {
            $this->error('Exact release SHA and artifact SHA-256 are required.');

            return self::FAILURE;
        }

        try {
            $result = $validator->validateFile($manifest, $release, $artifactSha256);
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'FAIL',
                    'error' => $exception->getMessage(),
                    'production_activation_performed' => false,
                    'production_ready_claim' => false,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('GO_LIVE_EVIDENCE=PASS');
            $this->line('release_sha='.$result['release_sha']);
            $this->line('artifact_sha256='.$result['artifact_sha256']);
            $this->line('evidence_count='.$result['evidence_count']);
            $this->line('production_activation_performed=false');
            $this->line('production_ready_claim=false');
        }

        return self::SUCCESS;
    }
}
