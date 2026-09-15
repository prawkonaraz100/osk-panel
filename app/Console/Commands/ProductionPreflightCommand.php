<?php

namespace App\Console\Commands;

use App\Support\Operations\ProductionDeploymentPreflight;
use Illuminate\Console\Command;

final class ProductionPreflightCommand extends Command
{
    protected $signature = 'operations:production:preflight
        {--json : Emit machine-readable result}';

    protected $description = 'Validate fail-closed production configuration without mutating state or calling external providers.';

    public function handle(ProductionDeploymentPreflight $preflight): int
    {
        $result = $preflight->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('PRODUCTION_PREFLIGHT='.strtoupper($result['status']));
            $this->line('errors='.$result['error_count']);
            $this->line('warnings='.$result['warning_count']);

            foreach ($result['checks'] as $check) {
                if ($check['status'] === 'fail') {
                    $this->line($check['severity'].':'.$check['code']);
                }
            }
        }

        return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
