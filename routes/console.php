<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Discover new Ollama domains from netint once a day and probe the new ones.
Schedule::command('scrape:netint')->daily()->withoutOverlapping();

// Hourly health-check of active (and never-probed) domains: liveness, latency, models.
Schedule::command('domains:probe')->hourly()->withoutOverlapping();

// Full daily re-probe of every known domain to refresh model lists and revive hosts.
Schedule::command('domains:probe --all')->dailyAt('03:00')->withoutOverlapping();
