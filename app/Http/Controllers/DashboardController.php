<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the overview dashboard.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'total' => Domain::count(),
                'active' => Domain::query()->active()->count(),
                'models' => (int) Domain::query()->active()->sum('model_count'),
                'lastScrape' => Domain::query()->max('last_seen_at'),
            ],
            'fastest' => Domain::query()
                ->active()
                ->rankedBySpeed()
                ->limit(5)
                ->get()
                ->map(fn (Domain $domain): array => [
                    'id' => $domain->id,
                    'host' => $domain->host,
                    'response_time_ms' => $domain->response_time_ms,
                    'model_count' => $domain->model_count,
                ])
                ->all(),
        ]);
    }
}
