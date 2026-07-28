<?php

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A personal access token string with the given abilities.
 *
 * @param  array<int, string>  $abilities
 */
function apiToken(array $abilities): string
{
    return User::factory()->create()->createToken('test', $abilities)->plainTextToken;
}

/**
 * An active domain serving a single model.
 */
function domainServing(string $host, string $model = 'llama3:latest'): Domain
{
    $domain = Domain::factory()->active()->create(['host' => $host]);
    $domain->models()->create(['name' => $model, 'available' => true]);

    return $domain;
}

test('the api index documents every endpoint without authentication', function () {
    $this->getJson('/api/v1')
        ->assertOk()
        ->assertJsonPath('version', 'v1')
        ->assertJsonPath('authentication.scheme', 'Bearer')
        ->assertJsonCount(6, 'endpoints')
        ->assertJsonStructure([
            'endpoints' => [['method', 'url', 'ability', 'description', 'parameters']],
            'generation_options' => ['temperature', 'top_p', 'num_predict', 'seed', 'stop'],
        ]);
});

test('the models endpoint searches across active domains, fastest first', function () {
    $slow = Domain::factory()->active()->create(['host' => 'slow.example', 'response_time_ms' => 900]);
    $slow->models()->create(['name' => 'llama3:8b', 'available' => true]);

    $fast = Domain::factory()->active()->create(['host' => 'fast.example', 'response_time_ms' => 20]);
    $fast->models()->create(['name' => 'llama3:70b', 'available' => true]);
    $fast->models()->create(['name' => 'mistral:7b', 'available' => true]);

    $hidden = Domain::factory()->inactive()->create(['host' => 'dead.example']);
    $hidden->models()->create(['name' => 'llama3:hidden', 'available' => true]);

    $this->withToken(apiToken(['domains:read']))
        ->getJson('/api/v1/models?search=llama')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'llama3:70b')
        ->assertJsonPath('data.0.domain.host', 'fast.example')
        ->assertJsonPath('data.1.name', 'llama3:8b');
});

test('the models endpoint lists the models of a single domain', function () {
    $domain = domainServing('ollama.example.com');
    $domain->models()->create(['name' => 'gone:latest', 'available' => false]);

    $this->withToken(apiToken(['domains:read']))
        ->getJson("/api/v1/domains/{$domain->id}/models")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'llama3:latest');
});

test('generation options and the system prompt are forwarded to ollama', function () {
    $domain = domainServing('ollama.example.com');

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(
            json_encode(['response' => 'ok', 'done' => true]),
        ),
    ]);

    $response = $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'system' => 'Be terse.',
            'options' => ['temperature' => 0.2, 'num_predict' => 64, 'stop' => ['###']],
        ]);

    $response->assertOk();

    // The request only leaves once the streamed body is consumed.
    expect($response->streamedContent())->toBe('ok');

    Http::assertSent(fn ($request) => $request['system'] === 'Be terse.'
        && $request['options']['temperature'] === 0.2
        && $request['options']['num_predict'] === 64
        && $request['options']['stop'] === ['###']);
});

test('unsupported generation options are rejected', function () {
    $domain = domainServing('ollama.example.com');

    $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'options' => ['temperature' => 0.2, 'mirostat_wizardry' => 3],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('options');
});

test('generation options are range checked', function () {
    $domain = domainServing('ollama.example.com');

    $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'options' => ['temperature' => 9, 'top_p' => 4],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['options.temperature', 'options.top_p']);
});

test('a non streamed generation returns the completion with its metrics', function () {
    $domain = domainServing('ollama.example.com');

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response([
            'response' => 'Hello there',
            'done' => true,
            'done_reason' => 'stop',
            'total_duration' => 1_500_000_000,
            'prompt_eval_count' => 7,
            'eval_count' => 10,
            'eval_duration' => 500_000_000,
        ]),
    ]);

    $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'stream' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.response', 'Hello there')
        ->assertJsonPath('data.done_reason', 'stop')
        ->assertJsonPath('data.model', 'llama3:latest')
        ->assertJsonPath('data.domain.host', 'ollama.example.com')
        ->assertJsonPath('data.metrics.total_duration_ms', 1500)
        ->assertJsonPath('data.metrics.prompt_eval_count', 7)
        ->assertJsonPath('data.metrics.tokens_per_second', 20);

    Http::assertSent(fn ($request) => $request['stream'] === false);
});

test('the chat endpoint streams a multi turn conversation', function () {
    $domain = domainServing('ollama.example.com');

    $ndjson = collect(['Sure', ', ', 'here'])
        ->map(fn (string $chunk) => json_encode([
            'message' => ['role' => 'assistant', 'content' => $chunk],
            'done' => false,
        ]))
        ->implode("\n");

    Http::fake(['https://ollama.example.com/api/chat' => Http::response($ndjson)]);

    $response = $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'system' => 'Be terse.',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello'],
                ['role' => 'user', 'content' => 'Continue'],
            ],
        ]);

    $response->assertOk();
    expect($response->streamedContent())->toBe('Sure, here');

    // The system prompt is prepended to the conversation we send on.
    Http::assertSent(fn ($request) => $request['messages'][0] === ['role' => 'system', 'content' => 'Be terse.']
        && count($request['messages']) === 4);
});

test('the chat endpoint validates message roles', function () {
    $domain = domainServing('ollama.example.com');

    $this->withToken(apiToken(['chat:generate']))
        ->postJson('/api/v1/chat', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'messages' => [['role' => 'wizard', 'content' => 'Hi']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('messages.0.role');
});

// One token per test: within a single test the auth guard caches the first
// token it resolves, so a second token in the same test would not take effect.
test('the models endpoint requires the domains:read ability', function () {
    $this->withToken(apiToken(['chat:generate']))
        ->getJson('/api/v1/models')
        ->assertForbidden();
});

test('the chat endpoint requires the chat:generate ability', function () {
    $domain = domainServing('ollama.example.com');

    $this->withToken(apiToken(['domains:read']))
        ->postJson('/api/v1/chat', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ])
        ->assertForbidden();
});
