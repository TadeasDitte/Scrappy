<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatGenerateRequest;
use App\Models\Domain;
use App\Models\OllamaModel;
use App\Services\OllamaClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Show the chat UI with the ranked list of active domains and their models.
     */
    public function index(Request $request): Response
    {
        $domains = Domain::query()
            ->active()
            ->with(['models' => fn ($q) => $q->where('available', true)->orderBy('name')])
            ->rankedBySpeed()
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'host' => $domain->host,
                'response_time_ms' => $domain->response_time_ms,
                'model_count' => $domain->model_count,
                'models' => $domain->models
                    ->values()
                    ->map(fn (OllamaModel $model): array => [
                        'id' => $model->id,
                        'name' => $model->name,
                        'parameter_size' => $model->parameter_size,
                    ])
                    ->all(),
            ])
            ->all();

        return Inertia::render('Chat', [
            'domains' => $domains,
            'selectedDomainId' => $request->integer('domain') ?: null,
        ]);
    }

    /**
     * Proxy a prompt to the selected domain's Ollama API, streaming the reply.
     */
    public function stream(ChatGenerateRequest $request, OllamaClient $ollama): StreamedResponse
    {
        $domain = $request->domain();
        $model = $request->model();
        $prompt = $request->string('prompt')->value();
        $options = $request->generationOptions();
        $system = $request->validated('system');

        // Yielding (rather than echoing) makes Laravel flush the output buffer
        // between chunks, so the browser paints tokens as the model emits them.
        return response()->stream(function () use ($ollama, $domain, $model, $prompt, $options, $system): \Generator {
            try {
                yield from $ollama->generateStream($domain, $model, $prompt, $options, $system);
            } catch (\Throwable $e) {
                yield "\n[stream error: {$e->getMessage()}]";
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
