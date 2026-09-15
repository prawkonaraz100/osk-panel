<?php

namespace App\Console\Commands;

use App\Support\Operations\OperationalAlertDispatcher;
use Illuminate\Console\Command;
use LogicException;

final class ReconciliationAlertSmokeCommand extends Command
{
    public const CONFIRMATION = 'SEND-SYNTHETIC-RECONCILIATION-ALERT';

    protected $signature = 'operations:reconciliation:alert:smoke
        {--confirm= : Exact confirmation token required to emit the synthetic reconciliation alert}
        {--json : Emit machine-readable result}';

    protected $description = 'Send one explicitly marked synthetic reconciliation-findings alert without mutating business state.';

    public function handle(OperationalAlertDispatcher $alerts): int
    {
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Exact synthetic reconciliation alert confirmation token is required.');

            return self::FAILURE;
        }

        $policyVersion = config('reconciliation.policy_version');
        if (! is_string($policyVersion) || trim($policyVersion) === '') {
            throw new LogicException('Reconciliation policy version is unavailable.');
        }

        $result = $alerts->dispatch('reconciliation_findings', [
            'policy_version' => $policyVersion,
            'findings_total' => 1,
            'findings_by_scope' => [
                'synthetic_smoke' => 1,
            ],
            'synthetic_smoke' => true,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RECONCILIATION_ALERT_SMOKE='.strtoupper($result['status']));
            $this->line('event_id='.$result['event_id']);
            $this->line('event_code='.$result['event_code']);
            $this->line('synthetic_smoke=true');
        }

        return $result['status'] === 'delivered'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
