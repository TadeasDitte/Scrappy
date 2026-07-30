<?php

use App\Models\Conversation;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An active domain serving one model, ready to be chatted with.
 */
function chattableDomain(string $host = 'ollama.example.com'): Domain
{
    $domain = Domain::factory()->active()->create(['host' => $host]);
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);

    return $domain;
}

/**
 * Fake the multi-turn `/api/chat` endpoint with the given reply chunks.
 *
 * @param  array<int, string>  $chunks
 */
function fakeChat(string $host, array $chunks): void
{
    $ndjson = collect($chunks)
        ->map(fn (string $chunk) => json_encode([
            'message' => ['role' => 'assistant', 'content' => $chunk],
            'done' => false,
        ]))
        ->implode("\n");

    Http::fake(["https://{$host}/api/chat" => Http::response($ndjson)]);
}

test('the first prompt creates a conversation and stores both turns', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    fakeGenerate('ollama.example.com', ['Hello', ' world']);

    $response = $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'prompt' => 'What is a prime number?',
    ]);

    $response->assertOk();

    $conversation = $user->conversations()->sole();

    // The id is announced up front so a new thread can adopt it mid-stream.
    expect($response->headers->get('X-Conversation-Id'))->toBe((string) $conversation->id)
        ->and($conversation->title)->toBe('What is a prime number?')
        ->and($conversation->domain_id)->toBe($domain->id)
        ->and($conversation->model)->toBe('llama3:latest');

    // The reply is only written once the stream has actually been consumed.
    expect($response->streamedContent())->toBe('Hello world');

    $messages = $conversation->messages()->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('What is a prime number?')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toBe('Hello world')
        ->and($messages[1]->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('a long prompt is truncated into a readable title', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    fakeGenerate('ollama.example.com', ['ok']);

    $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'prompt' => str_repeat('a very wordy question ', 20),
    ])->assertOk();

    $title = $user->conversations()->sole()->title;

    expect(mb_strlen($title))->toBeLessThanOrEqual(61)
        ->and($title)->toEndWith('…');
});

test('continuing a conversation replays the stored transcript to the model', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Name a prime.']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => '7.']);

    fakeChat('ollama.example.com', ['11']);

    $response = $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'conversation_id' => $conversation->id,
        'prompt' => 'Another one?',
    ]);

    expect($response->streamedContent())->toBe('11');

    // The whole thread went upstream, in order, so the model has context.
    Http::assertSent(fn ($request) => $request['messages'] === [
        ['role' => 'user', 'content' => 'Name a prime.'],
        ['role' => 'assistant', 'content' => '7.'],
        ['role' => 'user', 'content' => 'Another one?'],
    ]);

    expect($conversation->messages()->count())->toBe(4);
});

test('a system prompt is prepended when continuing a conversation', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello']);

    fakeChat('ollama.example.com', ['ok']);

    $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'conversation_id' => $conversation->id,
        'prompt' => 'Again',
        'system' => 'Be terse.',
    ])->streamedContent();

    Http::assertSent(fn ($request) => $request['messages'][0] === [
        'role' => 'system',
        'content' => 'Be terse.',
    ]);
});

test('a prompt that produces nothing leaves no dangling turn behind', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    // The endpoint answers, but with nothing usable.
    Http::fake(['https://ollama.example.com/api/generate' => Http::response('', 500)]);

    $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'prompt' => 'Are you there?',
    ])->streamedContent();

    // Otherwise the transcript would end on a user turn, and the next message
    // would replay two questions in a row to the model.
    expect($user->conversations()->count())->toBe(0);
});

test('a failed follow-up keeps the conversation it could not answer', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Name a prime.']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => '7.']);

    Http::fake(['https://ollama.example.com/api/chat' => Http::response('', 500)]);

    $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'conversation_id' => $conversation->id,
        'prompt' => 'Another one?',
    ])->streamedContent();

    expect(Conversation::whereKey($conversation->id)->exists())->toBeTrue()
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages()->get()->last()->role)->toBe('assistant');
});

test('a long reply replayed by a temporary chat does not wedge the thread', function () {
    $domain = chattableDomain();

    fakeChat('ollama.example.com', ['ok']);

    // Replies are not length capped the way prompts are, so a long one must not
    // fail validation on every subsequent message.
    $this->actingAs(User::factory()->create())
        ->postJson(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'temporary' => true,
            'prompt' => 'Continue',
            'history' => [
                ['role' => 'user', 'content' => 'Write me a long list.'],
                ['role' => 'assistant', 'content' => str_repeat('a', 20000)],
            ],
        ])
        ->assertOk();
});

test('a temporary chat is never written to history', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();

    fakeChat('ollama.example.com', ['ephemeral']);

    $response = $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'temporary' => true,
        'prompt' => 'Forget this',
        'history' => [
            ['role' => 'user', 'content' => 'Earlier turn'],
            ['role' => 'assistant', 'content' => 'Earlier reply'],
        ],
    ]);

    $response->assertOk();
    expect($response->headers->has('X-Conversation-Id'))->toBeFalse()
        ->and($response->streamedContent())->toBe('ephemeral');

    // Context still reached the model, it just was not persisted.
    Http::assertSent(fn ($request) => count($request['messages']) === 3);

    expect($user->conversations()->count())->toBe(0);
});

test('a temporary chat ignores a conversation id it was handed', function () {
    $domain = chattableDomain();
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    fakeGenerate('ollama.example.com', ['nope']);

    $this->actingAs($user)->postJson(route('chat.stream'), [
        'domain_id' => $domain->id,
        'model' => 'llama3:latest',
        'conversation_id' => $conversation->id,
        'temporary' => true,
        'prompt' => 'Secret',
    ])->streamedContent();

    expect($conversation->messages()->count())->toBe(0);
});

test('a conversation belonging to someone else cannot be continued', function () {
    $domain = chattableDomain();
    $theirs = Conversation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('chat.stream'), [
            'domain_id' => $domain->id,
            'model' => 'llama3:latest',
            'conversation_id' => $theirs->id,
            'prompt' => 'Let me in',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('conversation_id');
});

test('the chat page lists saved conversations and opens one', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create(['title' => 'Primes']);
    $conversation->messages()->create(['role' => 'user', 'content' => 'Name a prime.']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => '7.']);

    Conversation::factory()->create(['title' => 'Not yours']);

    $this->actingAs($user)
        ->get(route('chat.index', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat')
            ->has('conversations', 1)
            ->where('conversations.0.title', 'Primes')
            ->where('activeConversation.id', $conversation->id)
            ->has('activeConversation.messages', 2)
            ->where('activeConversation.messages.1.content', '7.'),
        );
});

test('opening someone elses conversation yields no transcript', function () {
    $theirs = Conversation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('chat.index', ['conversation' => $theirs->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('activeConversation', null));
});

test('a conversation can be renamed and deleted by its owner', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create(['title' => 'Old']);

    $this->actingAs($user)
        ->from(route('chat.index'))
        ->patch(route('conversations.update', $conversation), ['title' => 'New'])
        ->assertRedirect(route('chat.index'));

    expect($conversation->fresh()->title)->toBe('New');

    $this->actingAs($user)
        ->from(route('chat.index'))
        ->delete(route('conversations.destroy', $conversation))
        ->assertRedirect(route('chat.index'));

    expect(Conversation::count())->toBe(0);
});

test('deleting a conversation takes its messages with it', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);

    $this->actingAs($user)
        ->from(route('chat.index'))
        ->delete(route('conversations.destroy', $conversation));

    expect(DB::table('conversation_messages')->count())->toBe(0);
});

test('conversations cannot be renamed or deleted by other users', function () {
    $theirs = Conversation::factory()->create(['title' => 'Theirs']);

    $this->actingAs(User::factory()->create())
        ->patch(route('conversations.update', $theirs), ['title' => 'Mine now'])
        ->assertNotFound();

    expect($theirs->fresh()->title)->toBe('Theirs');

    $this->actingAs(User::factory()->create())
        ->delete(route('conversations.destroy', $theirs))
        ->assertNotFound();

    expect(Conversation::whereKey($theirs->id)->exists())->toBeTrue();
});

test('clearing history removes only the users own conversations', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->count(3)->create();
    $theirs = Conversation::factory()->create();

    $this->actingAs($user)
        ->from(route('chat.index'))
        ->delete(route('conversations.clear'))
        ->assertRedirect(route('chat.index'));

    expect($user->conversations()->count())->toBe(0)
        ->and(Conversation::whereKey($theirs->id)->exists())->toBeTrue();
});
