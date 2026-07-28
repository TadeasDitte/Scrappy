<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    /**
     * Show the ranked list of discovered Ollama domains.
     */
    public function index(Request $request): Response
    {
        $sort = $this->normalizeSort($request->string('sort')->value());

        return Inertia::render('Domains', [
            'sort' => $sort,
            'stats' => [
                'total' => Domain::count(),
                'active' => Domain::query()->active()->count(),
                'models' => (int) Domain::query()->active()->sum('model_count'),
            ],
            'domains' => Inertia::defer(fn () => $this->rankedDomains($sort)),
        ]);
    }

    /**
     * Fetch active domains ordered by the requested ranking.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rankedDomains(string $sort): array
    {
        return Domain::query()
            ->active()
            ->when(
                $sort === 'models',
                fn ($q) => $q->rankedByModelCount(),
                fn ($q) => $q->rankedBySpeed(),
            )
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'host' => $domain->host,
                'response_time_ms' => $domain->response_time_ms,
                'model_count' => $domain->model_count,
                'last_active_at' => $domain->last_active_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Constrain the sort value to a supported ranking.
     */
    protected function normalizeSort(?string $sort): string
    {
        return in_array($sort, ['speed', 'models'], true) ? $sort : 'speed';
    }
}
