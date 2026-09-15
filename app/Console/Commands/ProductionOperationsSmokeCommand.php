<?php

namespace App\Console\Commands;

use App\Support\Operations\ProductionOperationsSmoke;
use Illuminate\Console\Command;
use Throwable;

final class ProductionOperationsSmokeCommand extends Command
{
    protected $signature = 'operations:production:smoke
        {--send-alert : Send the deployment-configured paging smoke message}
        {--confirm= : Exact destructive-style acknowledgement required before sending}
        {--json : Emit machine-readable result}';

    protected $description = 'Validate production incident/reconciliation readiness and optionally send a safe paging smoke message.';

    public function handle(ProductionOperationsSmoke $smoke): int
    {
        $confirmation = $this->option('confirm');

        try {
            $result = $smoke->run(
                (bool) $this->option('send-alert'),
                is_string($confirmation) ? $confirmation : null,
            );
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'result' => 'REFUSED',
                    'error' => $exception->getMessage(),
                    'human_ack_proven' => false,
                    'scheduler_runtime_proven' => false,
                    'pkk_in_scope' => false,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('PRODUCTION_OPS_SMOKE='.$result['result']);
            $this->line('contact_refs_configured='.($result['contact_refs_configured'] ? 'true' : 'false'));
            $this->line('reconciliation_status='.$result['reconciliation_status']);
            $this->line('alert_sent='.($result['alert_sent'] ? 'true' : 'false'));
            $this->line('human_ack_proven=false');
            $this->line('scheduler_runtime_proven=false');
        }

        return str_starts_with($result['result'], 'PASS_')
            ? self::SUCCESS
            : self::FAILURE;
    }
}
