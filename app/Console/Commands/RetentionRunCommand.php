<?php

namespace App\Console\Commands;

use App\Support\Privacy\RetentionExecutor;
use Illuminate\Console\Command;
use Throwable;

final class RetentionRunCommand extends Command
{
    protected $signature = 'retention:run
        {dataClass : Exact retention data class}
        {--policy= : Exact approved retention policy version}
        {--organization= : Optional exact organization UUID scope}
        {--reason= : Nonblank operator reason}
        {--execute : Perform the privileged deletion; omitted means dry-run}
        {--confirm= : Exact destructive confirmation token required with --execute}
        {--json : Emit machine-readable result}';

    protected $description = 'Dry-run or execute the dedicated privileged retention path for explicitly allowlisted data classes.';

    public function handle(RetentionExecutor $executor): int
    {
        $dataClass = $this->argument('dataClass');
        $policy = $this->option('policy');
        $organization = $this->option('organization');
        $reason = $this->option('reason');

        if (! is_string($policy) || ! is_string($reason)) {
            $this->error('Retention arguments are invalid.');

            return self::FAILURE;
        }

        try {
            if (! $this->option('execute')) {
                $result = $executor->preview(
                    $dataClass,
                    $policy,
                    $reason,
                    $organization,
                );
            } else {
                $confirmation = $this->option('confirm');
                if (! is_string($confirmation)) {
                    $this->error('Explicit retention confirmation token is required.');

                    return self::FAILURE;
                }

                $result = $executor->execute(
                    $dataClass,
                    $policy,
                    $reason,
                    $confirmation,
                    $organization,
                );
            }
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'refused',
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('RETENTION_EXECUTOR='.strtoupper((string) $result['result']));
            $this->line('mode='.$result['mode']);
            $this->line('policy_version='.$result['policy_version']);
            $this->line('data_class='.$result['data_class']);
            $this->line('cutoff_at='.$result['cutoff_at']);
            $this->line('candidate_count='.$result['candidate_count']);
            $this->line('deleted_or_redacted_count='.$result['deleted_or_redacted_count']);
        }

        return $result['result'] === 'partial_requires_review'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
