<?php

namespace App\Console\Commands;

use App\Jobs\ProbeDomain;
use App\Models\Domain;
use App\Services\NetintScraper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scrape:netint {--no-probe : Only sync domains without dispatching probe jobs}')]
#[Description('Scrape netint.xyz for candidate Ollama domains and queue probes for new ones.')]
class ScrapeNetint extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NetintScraper $scraper): int
    {
        $this->info('Scraping netint.xyz…');

        $domains = $scraper->syncDomains();

        $this->info("Synced {$domains->count()} domain(s).");

        if ($this->option('no-probe')) {
            return self::SUCCESS;
        }

        // Probe domains that have never been checked so newly discovered hosts
        // become usable without waiting for the hourly health-check.
        $unprobed = Domain::query()->whereNull('last_probed_at')->get();

        $unprobed->each(fn (Domain $domain) => ProbeDomain::dispatch($domain));

        $this->info("Queued {$unprobed->count()} probe job(s).");

        return self::SUCCESS;
    }
}
