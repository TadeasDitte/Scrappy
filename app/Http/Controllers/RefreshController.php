<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

class RefreshController extends Controller
{
    /**
     * Trigger an on-demand scrape + probe run from the UI.
     */
    public function store(): RedirectResponse
    {
        // Queue the scrape so the request returns immediately; new domains get
        // probed automatically as part of the command.
        Artisan::queue('scrape:netint');
        Artisan::queue('domains:probe', ['--only-active' => true]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Refresh queued — new domains and health checks are running in the background.'),
        ]);

        return back();
    }
}
