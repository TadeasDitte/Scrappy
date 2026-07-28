<?php

namespace App\Services;

use App\Models\Domain;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OllamaClient
{
    /**
     * Probe a domain's `/api/tags` endpoint.
     *
     * Returns the decoded `models` array on a legitimate Ollama response, or
     * `null` when the host is unreachable or does not speak the Ollama API.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function tags(Domain $domain): ?array
    {
        try {
            $response = $this->request(config('services.ollama.probe_timeout'))
                ->get($domain->baseUrl().'/api/tags');
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $models = $response->json('models');

        return is_array($models) ? $models : null;
    }

    /**
     * Stream a completion from `/api/generate`, yielding response text chunks.
     *
     * Ollama replies with newline-delimited JSON objects; each `response` field
     * is yielded as it arrives so the caller can forward pieces to the browser.
     *
     * @param  array<string, mixed>  $options
     * @return Generator<int, string>
     */
    public function generateStream(Domain $domain, string $model, string $prompt, array $options = [], ?string $system = null): Generator
    {
        yield from $this->streamNdjson(
            $domain,
            '/api/generate',
            $this->generatePayload($model, $prompt, $options, $system, stream: true),
            fn (array $decoded): ?string => $this->stringOrNull($decoded['response'] ?? null),
        );
    }

    /**
     * Request a complete (non-streamed) `/api/generate` completion.
     *
     * @param  array<string, mixed>  $options
     * @return array{response: string, done_reason: string|null, metrics: array<string, int|float|null>}
     */
    public function generate(Domain $domain, string $model, string $prompt, array $options = [], ?string $system = null): array
    {
        $decoded = $this->postJson(
            $domain,
            '/api/generate',
            $this->generatePayload($model, $prompt, $options, $system, stream: false),
        );

        return [
            'response' => $this->stringOrNull($decoded['response'] ?? null) ?? '',
            'done_reason' => $this->stringOrNull($decoded['done_reason'] ?? null),
            'metrics' => $this->metrics($decoded),
        ];
    }

    /**
     * Stream a multi-turn conversation from `/api/chat`, yielding text chunks.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return Generator<int, string>
     */
    public function chatStream(Domain $domain, string $model, array $messages, array $options = []): Generator
    {
        yield from $this->streamNdjson(
            $domain,
            '/api/chat',
            $this->chatPayload($model, $messages, $options, stream: true),
            fn (array $decoded): ?string => $this->stringOrNull($decoded['message']['content'] ?? null),
        );
    }

    /**
     * Request a complete (non-streamed) `/api/chat` reply.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{response: string, done_reason: string|null, metrics: array<string, int|float|null>}
     */
    public function chat(Domain $domain, string $model, array $messages, array $options = []): array
    {
        $decoded = $this->postJson(
            $domain,
            '/api/chat',
            $this->chatPayload($model, $messages, $options, stream: false),
        );

        return [
            'response' => $this->stringOrNull($decoded['message']['content'] ?? null) ?? '',
            'done_reason' => $this->stringOrNull($decoded['done_reason'] ?? null),
            'metrics' => $this->metrics($decoded),
        ];
    }

    /**
     * Build the `/api/generate` request payload.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function generatePayload(string $model, string $prompt, array $options, ?string $system, bool $stream): array
    {
        return array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'system' => $system,
            'stream' => $stream,
            'options' => $options === [] ? null : $options,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Build the `/api/chat` request payload.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function chatPayload(string $model, array $messages, array $options, bool $stream): array
    {
        return array_filter([
            'model' => $model,
            'messages' => array_values($messages),
            'stream' => $stream,
            'options' => $options === [] ? null : $options,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * POST a payload and decode the single JSON object Ollama replies with.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function postJson(Domain $domain, string $path, array $payload): array
    {
        $response = $this->request(config('services.ollama.generate_timeout'))
            ->post($domain->baseUrl().$path, $payload);

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read an NDJSON stream line by line, yielding the text each line carries.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): ?string  $extractor
     * @return Generator<int, string>
     */
    protected function streamNdjson(Domain $domain, string $path, array $payload, callable $extractor): Generator
    {
        $response = $this->request(config('services.ollama.generate_timeout'))
            ->withOptions(['stream' => true])
            ->post($domain->baseUrl().$path, $payload);

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePosition);
                $buffer = substr($buffer, $newlinePosition + 1);

                $chunk = $this->extractChunk($line, $extractor);

                if ($chunk !== null && $chunk !== '') {
                    yield $chunk;
                }
            }
        }

        $chunk = $this->extractChunk($buffer, $extractor);

        if ($chunk !== null && $chunk !== '') {
            yield $chunk;
        }
    }

    /**
     * Decode a single NDJSON line and pull out the text it carries, if any.
     *
     * @param  callable(array<string, mixed>): ?string  $extractor
     */
    protected function extractChunk(string $line, callable $extractor): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $extractor($decoded);
    }

    /**
     * Pull the timing and token counters out of a completed Ollama response.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, int|float|null>
     */
    protected function metrics(array $decoded): array
    {
        $evalCount = isset($decoded['eval_count']) ? (int) $decoded['eval_count'] : null;
        $evalDuration = isset($decoded['eval_duration']) ? (int) $decoded['eval_duration'] : null;

        return [
            'total_duration_ms' => isset($decoded['total_duration'])
                ? (int) round($decoded['total_duration'] / 1_000_000)
                : null,
            'load_duration_ms' => isset($decoded['load_duration'])
                ? (int) round($decoded['load_duration'] / 1_000_000)
                : null,
            'prompt_eval_count' => isset($decoded['prompt_eval_count'])
                ? (int) $decoded['prompt_eval_count']
                : null,
            'eval_count' => $evalCount,
            'tokens_per_second' => $evalCount && $evalDuration
                ? round($evalCount / ($evalDuration / 1_000_000_000), 2)
                : null,
        ];
    }

    /**
     * Narrow a decoded JSON value to a string, or null when it is anything else.
     */
    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * A pre-configured HTTP client with our timeout, TLS verification and UA.
     */
    protected function request(int $timeout): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => config('services.ollama.user_agent'),
        ])
            ->withOptions(['verify' => true])
            ->connectTimeout(min($timeout, 10))
            ->timeout($timeout);
    }
}
