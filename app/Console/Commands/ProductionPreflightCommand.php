<?php

namespace App\Console\Commands;

use App\Support\Operations\ProductionEnvironmentPreflight;
use Illuminate\Console\Command;

final class ProductionPreflightCommand extends Command
{
    protected $signature = 'operations:production:preflight
        {--live : Run controlled live dependency checks after static configuration passes}
        {--json : Emit the full machine-readable report}';

    protected $description = 'Fail-closed production target configuration and optional live dependency preflight.';

    public function handle(ProductionEnvironmentPreflight $preflight): int
    {
        $report = $preflight->run((bool) $this->option('live'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('PRODUCTION_PREFLIGHT='.strtoupper($report['status']));
            $this->line('policy_version='.$report['policy_version']);
            $this->line('live_requested='.($report['live_requested'] ? 'true' : 'false'));
            $this->line('live_skipped='.($report['live_skipped'] ? 'true' : 'false'));
            $this->line('failures='.$report['failures']);
            $this->line('PKK_required=false');
            $this->line('payment_provider_webhook_required=false');
        }

        return $report['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
