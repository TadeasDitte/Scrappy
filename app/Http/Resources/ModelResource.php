<?php

namespace App\Http\Resources;

use App\Models\OllamaModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OllamaModel
 */
class ModelResource extends JsonResource
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
            'name' => $this->name,
            'family' => $this->family,
            'parameter_size' => $this->parameter_size,
            'quantization' => $this->quantization,
            'size_bytes' => $this->size_bytes,
            'available' => $this->available,
            'domain' => new DomainResource($this->whenLoaded('domain')),
        ];
    }
}
