<?php

namespace App\Concerns;

use App\Models\Domain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ResolvesChatTarget
{
    /**
     * Rules pinning a request to an active domain and one of its models.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function chatTargetRules(): array
    {
        return [
            'domain_id' => [
                'required',
                Rule::exists('domains', 'id')->where('is_active', true),
            ],
            'model' => [
                'required',
                'string',
                Rule::exists('models', 'name')->where('domain_id', $this->input('domain_id')),
            ],
        ];
    }

    /**
     * Resolve the validated, active domain for the request.
     */
    public function domain(): Domain
    {
        return Domain::query()->active()->findOrFail($this->integer('domain_id'));
    }

    /**
     * The model name the request targets.
     */
    public function model(): string
    {
        return $this->string('model')->value();
    }

    /**
     * Whether the caller wants a streamed response. Streaming is the default.
     */
    public function shouldStream(): bool
    {
        return ! $this->has('stream') || $this->boolean('stream');
    }
}
