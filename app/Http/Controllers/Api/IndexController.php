<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatGenerateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    /**
     * Describe the v1 API: its endpoints, their parameters and the abilities
     * a personal access token needs to call them.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'name' => config('app.name').' API',
            'version' => 'v1',
            'authentication' => [
                'scheme' => 'Bearer',
                'header' => 'Authorization: Bearer <token>',
                'tokens_url' => route('api-tokens.index'),
                'abilities' => ['domains:read', 'chat:generate'],
            ],
            'endpoints' => $this->endpoints(),
            'generation_options' => ChatGenerateRequest::documentedOptions(),
        ]);
    }

    /**
     * The catalog of callable endpoints.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function endpoints(): array
    {
        return [
            [
                'method' => 'GET',
                'url' => route('api.v1.domains.index'),
                'ability' => 'domains:read',
                'description' => 'List active domains, ranked by response time.',
                'parameters' => [
                    'sort' => 'speed (default) | models',
                    'per_page' => 'integer, default 25',
                ],
            ],
            [
                'method' => 'GET',
                'url' => url('/api/v1/domains/{domain}'),
                'ability' => 'domains:read',
                'description' => 'Show one domain together with its available models.',
                'parameters' => [],
            ],
            [
                'method' => 'GET',
                'url' => url('/api/v1/domains/{domain}/models'),
                'ability' => 'domains:read',
                'description' => 'List the models a single domain currently serves.',
                'parameters' => [],
            ],
            [
                'method' => 'GET',
                'url' => route('api.v1.models.index'),
                'ability' => 'domains:read',
                'description' => 'Search models across every active domain, fastest domain first.',
                'parameters' => [
                    'search' => 'substring of the model name',
                    'family' => 'exact model family, e.g. llama',
                    'parameter_size' => 'exact parameter size, e.g. 8B',
                    'per_page' => 'integer, default 25',
                ],
            ],
            [
                'method' => 'POST',
                'url' => route('api.v1.chat.generate'),
                'ability' => 'chat:generate',
                'description' => 'Single-prompt completion. Streams text/plain chunks unless stream is false.',
                'parameters' => [
                    'domain_id' => 'required, id of an active domain',
                    'model' => 'required, a model name served by that domain',
                    'prompt' => 'required, max 8000 characters',
                    'system' => 'optional system prompt, max 4000 characters',
                    'stream' => 'boolean, default true',
                    'keep_alive' => 'how long the host keeps the model loaded, e.g. 10m',
                    'options' => 'object, see generation_options',
                ],
            ],
            [
                'method' => 'POST',
                'url' => route('api.v1.chat.messages'),
                'ability' => 'chat:generate',
                'description' => 'Multi-turn conversation. Streams text/plain chunks unless stream is false.',
                'parameters' => [
                    'domain_id' => 'required, id of an active domain',
                    'model' => 'required, a model name served by that domain',
                    'messages' => 'required array of {role: system|user|assistant, content: string}',
                    'system' => 'optional system prompt, prepended to messages',
                    'stream' => 'boolean, default true',
                    'keep_alive' => 'how long the host keeps the model loaded, e.g. 10m',
                    'options' => 'object, see generation_options',
                ],
            ],
        ];
    }
}
