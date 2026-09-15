<?php

namespace App\Console\Commands;

use App\Support\Operations\ProductionEnvironmentPreflight;
use Illuminate\Console\Command;

final class ProductionPreflightCommand extends Command
{
    protected $signature = 'operations:production:preflight
        {--json : Emit machine-readable report}';

    protected $description = 'Validate production configuration without claiming external go-live evidence.';

    public function handle(ProductionEnvironmentPreflight $preflight): int
    {
        $report = $preflight->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('PRODUCTION_CONFIG_PREFLIGHT='.$report['configuration_status']);
            $this->line('go_live_status='.$report['go_live_status']);
            $this->line('production_ready=false');

            foreach ($report['checks'] as $check) {
                $this->line($check['status'].' '.$check['id'].' - '.$check['message']);
            }

            $this->line('external_evidence_pending='.count($report['external_evidence_required']));
            $this->line('PKK_required=false');
            $this->line('payment_provider_specific_webhook_required=false');
        }

        return $report['configuration_status'] === 'PASS'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
