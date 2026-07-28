<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class NetintScraper
{
    /**
     * Fetch the netint listing and extract the candidate Ollama hostnames.
     *
     * The listing renders one table row per host as
     * `<tr><td>host</td><td>first seen</td><td>last seen</td></tr>`.
     *
     * @return Collection<int, lowercase-string>
     */
    public function fetch(): Collection
    {
        $html = Http::withHeaders([
            'User-Agent' => config('services.ollama.user_agent'),
        ])->timeout(30)->get(config('services.netint.scrape_url'))->body();

        return $this->parse($html);
    }

    /**
     * Parse hostnames out of the netint results HTML.
     *
     * @return Collection<int, lowercase-string>
     */
    public function parse(string $html): Collection
    {
        preg_match_all(
            '/<tr>\s*<td>\s*([^<\s][^<]*?)\s*<\/td>\s*<td>[^<]*<\/td>\s*<td>[^<]*<\/td>\s*<\/tr>/i',
            $html,
            $matches,
        );

        return collect($matches[1])
            ->map(fn (string $host): string => mb_strtolower(trim($host)))
            ->filter(fn (string $host): bool => $this->isValidHost($host))
            ->unique()
            ->values();
    }

    /**
     * Persist scraped hosts, returning the domains that are newly discovered.
     *
     * Existing domains only have their `last_seen_at` bumped; liveness is left
     * to the probe pipeline.
     *
     * @return Collection<int, Domain>
     */
    public function syncDomains(): Collection
    {
        $now = now();

        return $this->fetch()->map(function (string $host) use ($now): Domain {
            $domain = Domain::firstOrNew(['host' => $host]);

            if (! $domain->exists) {
                $domain->first_seen_at = $now;
            }

            $domain->last_seen_at = $now;
            $domain->save();

            return $domain;
        });
    }

    /**
     * Guard against markup that isn't actually a hostname.
     */
    protected function isValidHost(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host);
    }
}
