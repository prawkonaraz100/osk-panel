<?php

namespace App\Console\Commands;

use App\Support\Operations\OperationalAlertDispatcher;
use Illuminate\Console\Command;

final class OperationalAlertSmokeCommand extends Command
{
    public const CONFIRMATION = 'SEND-SYNTHETIC-OPERATIONAL-ALERT';

    protected $signature = 'operations:alert:smoke
        {--confirm= : Exact confirmation token required to emit a synthetic alert}
        {--json : Emit machine-readable result}';

    protected $description = 'Send one safe synthetic alert through the configured provider-neutral operational alert sink.';

    public function handle(OperationalAlertDispatcher $alerts): int
    {
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Exact synthetic operational alert confirmation token is required.');

            return self::FAILURE;
        }

        $result = $alerts->dispatch('synthetic_smoke');

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('OPERATIONAL_ALERT_SMOKE='.strtoupper($result['status']));
            $this->line('event_id='.$result['event_id']);
            $this->line('event_code='.$result['event_code']);
        }

        return $result['status'] === 'delivered'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
