<?php

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeGenerate(string $host, array $chunks): void
{
    $ndjson = collect($chunks)
        ->map(fn (string $c) => json_encode(['response' => $c, 'done' => false]))
        ->push(json_encode(['response' => '', 'done' => true]))
        ->implode("\n");

    Http::fake([
        "https://{$host}/api/generate" => Http::response($ndjson),
    ]);
}

test('the chat stream endpoint concatenates ollama chunks', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    fakeGenerate('ollama.example.com', ['Hello', ', ', 'world']);

    $response = $this->actingAs(User::factory()->create())
        ->post(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
        ]);

    $response->assertOk();
    expect($response->streamedContent())->toBe('Hello, world');
});

test('the chat stream forwards the options chosen in the UI', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    fakeGenerate('ollama.example.com', ['ok']);

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
            'system' => 'Be terse.',
            'options' => ['temperature' => 0.1, 'seed' => 7],
        ]);

    $response->assertOk();
    expect($response->streamedContent())->toBe('ok');

    Http::assertSent(fn ($request) => $request['system'] === 'Be terse.'
        && $request['options'] === ['temperature' => 0.1, 'seed' => 7]
        && $request['stream'] === true);
});

test('it rejects an inactive domain', function () {
    $domain = Domain::factory()->inactive()->create(['host' => 'ollama.dead.example']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'prompt' => 'Hi',
        ])
        ->assertSessionHasErrors('domain_id');
});

test('it rejects a model that does not belong to the domain', function () {
    $domain = Domain::factory()->active()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'ghost:latest',
            'prompt' => 'Hi',
        ])
        ->assertSessionHasErrors('model');
});

test('guests cannot reach the chat stream', function () {
    $this->post(route('chat.stream'), [])->assertRedirect(route('login'));
});
