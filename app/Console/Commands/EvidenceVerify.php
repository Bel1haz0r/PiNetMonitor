<?php

namespace App\Console\Commands;

use App\Models\MonitorSession;
use App\Monitor\EvidenceChain;
use Illuminate\Console\Command;

class EvidenceVerify extends Command
{
    protected $signature = 'evidence:verify {session? : Session id, session name, or path to a RawEvidence.jsonl file (default: latest session)}';

    protected $description = 'Verify the hash chain of a session. Exit code 0 = VALID, 1 = BROKEN';

    public function handle(): int
    {
        $arg = $this->argument('session');
        if ($arg !== null && is_file($arg)) {
            $path = $arg;
        } else {
            $session = $arg === null
                ? MonitorSession::query()->latest('id')->first()
                : MonitorSession::query()->where('id', $arg)->orWhere('name', $arg)->first();
            if ($session === null) {
                $this->error('Session not found.');

                return 2;
            }
            $path = $session->chain_path;
        }

        $r = EvidenceChain::verify($path);
        $this->line($path);
        if ($r['valid']) {
            $this->info("VALID — {$r['entries']} entries, last hash {$r['last_hash']}");

            return 0;
        }
        $this->error("BROKEN at line {$r['first_bad_line']}: {$r['reason']} ({$r['entries']} entries before it are intact)");

        return 1;
    }
}
