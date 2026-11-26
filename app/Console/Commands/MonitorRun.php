<?php

namespace App\Console\Commands;

use App\Monitor\MonitorEngine;
use Illuminate\Console\Command;

class MonitorRun extends Command
{
    protected $signature = 'monitor:run
        {--for= : Stop after this many seconds (testing)}
        {--simulate= : Inject a fault for testing, as kind:after:duration in seconds, e.g. upstream:10:15 or gateway:5:8}';

    protected $description = 'Run the connection monitor daemon (keep it running under systemd)';

    public function handle(): int
    {
        $lock = fopen(storage_path('app/monitor.lock'), 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another monitor is already running.');

            return self::FAILURE;
        }

        $engine = new MonitorEngine;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use ($engine) {
                    $engine->stop = true;
                });
            }
        }

        if ($sim = $this->option('simulate')) {
            [$kind, $after, $duration] = explode(':', $sim) + [1 => 10, 2 => 15];
            $engine->faultInjector = fn (float $t) => ($t >= (float) $after && $t < (float) $after + (float) $duration) ? $kind : null;
            $this->warn("SIMULATION: '$kind' fault from {$after}s for {$duration}s. This writes simulated findings into the session.");
        }

        $engine->run(
            fn (string $m) => $this->line('['.date('H:i:s').'] '.$m),
            $this->option('for') !== null ? (float) $this->option('for') : null,
        );

        return self::SUCCESS;
    }
}
