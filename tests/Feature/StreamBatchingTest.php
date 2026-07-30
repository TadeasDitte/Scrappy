<?php

use App\Models\Domain;
use App\Models\User;
use App\Services\OllamaClient;
use Illuminate\Support\Facades\Http;

/**
 * An NDJSON body of one `response` object per token, as Ollama emits.
 *
 * @param  array<int, string>  $tokens
 */
function ndjsonTokens(array $tokens): string
{
    return collect($tokens)
        ->map(fn (string $token) => json_encode(['response' => $token, 'done' => false]))
        ->implode("\n");
}

test('batching forwards the reply byte for byte in far fewer chunks', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);

    $tokens = collect(range(1, 400))->map(fn (int $i): string => "token{$i} ")->all();

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(ndjsonTokens($tokens)),
    ]);

    $chunks = iterator_to_array(
        app(OllamaClient::class)->generateStream($domain, 'llama3:latest', 'go'),
    );

    // Nothing is lost or reordered...
    expect(implode('', $chunks))->toBe(implode('', $tokens))
        // ...but the 400 token-sized writes collapse into a handful.
        ->and(count($chunks))->toBeLessThan(50);
});

test('the first token is never held back by batching', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(
            ndjsonTokens(['first ', 'second ', 'third']),
        ),
    ]);

    $chunks = iterator_to_array(
        app(OllamaClient::class)->generateStream($domain, 'llama3:latest', 'go'),
    );

    expect($chunks[0])->toBe('first ');
});

test('batching survives a reply that arrives as a single token', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(ndjsonTokens(['only'])),
    ]);

    $chunks = iterator_to_array(
        app(OllamaClient::class)->generateStream($domain, 'llama3:latest', 'go'),
    );

    expect($chunks)->toBe(['only']);
});

test('keep_alive is forwarded so the host holds the model in memory', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(ndjsonTokens(['ok'])),
    ]);

    $token = User::factory()->create()->createToken('t', ['chat:generate'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'keep_alive' => '10m',
        ])
        ->streamedContent();

    Http::assertSent(fn ($request) => $request['keep_alive'] === '10m');
});

test('keep_alive rejects anything that is not a duration', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    $token = User::factory()->create()->createToken('t', ['chat:generate'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'keep_alive' => 'forever please',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('keep_alive');
});

test('surrounding whitespace never reaches the endpoint as part of keep_alive', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(ndjsonTokens(['ok'])),
    ]);

    $token = User::factory()->create()->createToken('t', ['chat:generate'])->plainTextToken;

    // TrimStrings normalises this before validation; the regex anchors with \z
    // so that a stray newline could not slip through even if it did not.
    $this->withToken($token)
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'keep_alive' => "  10m\n",
        ])
        ->assertOk()
        ->streamedContent();

    Http::assertSent(fn ($request) => $request['keep_alive'] === '10m');
});

test('no keep_alive is sent when the caller did not ask for one', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    Http::fake([
        'https://ollama.example.com/api/generate' => Http::response(ndjsonTokens(['ok'])),
    ]);

    $token = User::factory()->create()->createToken('t', ['chat:generate'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/chat/generate', [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
        ])
        ->streamedContent();

    // Left unset, the endpoint keeps its own default rather than ours.
    Http::assertSent(fn ($request) => ! isset($request['keep_alive']));
});
