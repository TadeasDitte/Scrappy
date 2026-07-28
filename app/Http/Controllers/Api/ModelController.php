<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Models\Domain;
use App\Models\OllamaModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ModelController extends Controller
{
    /**
     * Search every model served by an active domain.
     *
     * Supports `search` (name substring), `family` and `parameter_size` filters,
     * and ranks results by the speed of the domain serving them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $models = OllamaModel::query()
            ->select('models.*')
            ->join('domains', 'domains.id', '=', 'models.domain_id')
            ->where('models.available', true)
            ->where('domains.is_active', true)
            ->with('domain')
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('models.name', 'like', '%'.$request->string('search')->value().'%'),
            )
            ->when(
                $request->filled('family'),
                fn ($q) => $q->where('models.family', $request->string('family')->value()),
            )
            ->when(
                $request->filled('parameter_size'),
                fn ($q) => $q->where('models.parameter_size', $request->string('parameter_size')->value()),
            )
            ->orderByRaw('domains.response_time_ms is null, domains.response_time_ms asc')
            ->orderBy('models.name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ModelResource::collection($models);
    }

    /**
     * List the models a single domain currently serves.
     */
    public function forDomain(Domain $domain): AnonymousResourceCollection
    {
        $models = $domain->models()
            ->where('available', true)
            ->orderBy('name')
            ->get();

        return ModelResource::collection($models);
    }
}
