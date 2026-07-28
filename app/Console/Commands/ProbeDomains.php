<?php

namespace App\Console\Commands;

use App\Jobs\ProbeDomain;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domains:probe {--only-active : Re-probe only currently active domains} {--all : Probe every known domain}')]
#[Description('Dispatch probe jobs to (re)check Ollama domains for liveness and models.')]
class ProbeDomains extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Domain::query();

        if ($this->option('only-active')) {
            $query->active();
        } elseif (! $this->option('all')) {
            // Default: active domains plus anything never probed.
            $query->where(fn ($q) => $q->active()->orWhereNull('last_probed_at'));
        }

        $count = 0;

        $query->chunkById(200, function ($domains) use (&$count): void {
            foreach ($domains as $domain) {
                ProbeDomain::dispatch($domain);
                $count++;
            }
        });

        $this->info("Queued {$count} probe job(s).");

        return self::SUCCESS;
    }
}
