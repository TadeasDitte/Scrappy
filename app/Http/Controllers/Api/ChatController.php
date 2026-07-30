<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatGenerateRequest;
use App\Http\Requests\ChatMessagesRequest;
use App\Models\Domain;
use App\Services\OllamaClient;
use Generator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Single-prompt generation against a selected domain + model.
     *
     * Streams `text/plain` chunks by default; pass `stream: false` for a single
     * JSON document containing the whole completion and its timing metrics.
     */
    public function generate(ChatGenerateRequest $request, OllamaClient $ollama): StreamedResponse|JsonResponse
    {
        $domain = $request->domain();
        $model = $request->model();
        $prompt = $request->string('prompt')->value();
        $options = $request->generationOptions();
        $system = $request->validated('system');
        $keepAlive = $request->keepAlive();

        if (! $request->shouldStream()) {
            return $this->completion(
                fn (): array => $ollama->generate($domain, $model, $prompt, $options, $system, $keepAlive),
                $domain,
                $model,
            );
        }

        return $this->streamed(
            fn (): Generator => yield from $ollama->generateStream($domain, $model, $prompt, $options, $system, $keepAlive),
        );
    }

    /**
     * Multi-turn conversation against a selected domain + model.
     *
     * Accepts an Ollama-style `messages` array and, like `generate`, streams by
     * default or returns a single JSON document when `stream` is false.
     */
    public function chat(ChatMessagesRequest $request, OllamaClient $ollama): StreamedResponse|JsonResponse
    {
        $domain = $request->domain();
        $model = $request->model();
        $messages = $request->conversation();
        $options = $request->generationOptions();
        $keepAlive = $request->keepAlive();

        if (! $request->shouldStream()) {
            return $this->completion(
                fn (): array => $ollama->chat($domain, $model, $messages, $options, $keepAlive),
                $domain,
                $model,
            );
        }

        return $this->streamed(
            fn (): Generator => yield from $ollama->chatStream($domain, $model, $messages, $options, $keepAlive),
        );
    }

    /**
     * Wrap a generator of text chunks in a flushed plain-text stream.
     *
     * Returning a generator lets Laravel flush the output buffer between chunks
     * so tokens reach the client as the model produces them.
     *
     * @param  callable(): Generator<int, string>  $chunks
     */
    protected function streamed(callable $chunks): StreamedResponse
    {
        return response()->stream(function () use ($chunks): Generator {
            try {
                yield from $chunks();
            } catch (\Throwable $e) {
                yield "\n[stream error: {$e->getMessage()}]";
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Run a blocking completion and shape it into the JSON envelope.
     *
     * @param  callable(): array{response: string, done_reason: string|null, metrics: array<string, int|float|null>}  $completion
     */
    protected function completion(callable $completion, Domain $domain, string $model): JsonResponse
    {
        try {
            $result = $completion();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "The endpoint could not be reached: {$e->getMessage()}",
            ], 502);
        }

        return response()->json([
            'data' => [
                'domain' => ['id' => $domain->id, 'host' => $domain->host],
                'model' => $model,
                ...$result,
            ],
        ]);
    }
}
