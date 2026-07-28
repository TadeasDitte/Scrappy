<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Show the landing page with a live count of the endpoints we track.
     *
     * Only aggregates are exposed here; hostnames stay behind authentication.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'stats' => Cache::remember('welcome.stats', now()->addMinutes(5), fn (): array => [
                'active' => Domain::query()->active()->count(),
                'models' => (int) Domain::query()->active()->sum('model_count'),
            ]),
        ]);
    }
}
