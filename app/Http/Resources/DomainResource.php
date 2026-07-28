<?php

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'base_url' => $this->baseUrl(),
            'is_active' => $this->is_active,
            'response_time_ms' => $this->response_time_ms,
            'model_count' => $this->model_count,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'last_probed_at' => $this->last_probed_at?->toIso8601String(),
            'models' => ModelResource::collection($this->whenLoaded('models')),
        ];
    }
}
