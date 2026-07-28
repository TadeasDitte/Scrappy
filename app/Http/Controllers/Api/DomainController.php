<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DomainController extends Controller
{
    /**
     * List active domains, ranked by speed (default) or model count.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $domains = Domain::query()
            ->active()
            ->withCount('models')
            ->when(
                $request->string('sort')->value() === 'models',
                fn ($q) => $q->rankedByModelCount(),
                fn ($q) => $q->rankedBySpeed(),
            )
            ->paginate($request->integer('per_page', 25));

        return DomainResource::collection($domains);
    }

    /**
     * Show a single domain with its available models.
     */
    public function show(Domain $domain): DomainResource
    {
        $domain->load(['models' => fn ($q) => $q->where('available', true)->orderBy('name')]);

        return new DomainResource($domain);
    }
}
