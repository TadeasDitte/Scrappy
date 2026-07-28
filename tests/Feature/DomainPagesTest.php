<?php

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('the landing page shows how many endpoints are live', function () {
    Domain::factory()->active()->count(2)->create(['model_count' => 3]);
    Domain::factory()->inactive()->create(['model_count' => 5]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('stats.active', 2)
            ->where('stats.models', 6),
        );
});

test('guests cannot view the domains page', function () {
    $this->get(route('domains.index'))->assertRedirect(route('login'));
});

test('the domains page renders with stats', function () {
    Domain::factory()->active()->count(3)->create();
    Domain::factory()->inactive()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Domains')
            ->where('stats.total', 4)
            ->where('stats.active', 3)
            ->where('sort', 'speed'),
        );
});

test('the chat page lists active domains with their available models', function () {
    $domain = Domain::factory()->active()->create();
    $domain->models()->create(['name' => 'llama3:latest', 'available' => true]);
    $domain->models()->create(['name' => 'hidden:latest', 'available' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Chat')
            ->has('domains', 1)
            ->has('domains.0.models', 1),
        );
});

test('the refresh action queues background work and redirects back', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->from(route('domains.index'))
        ->post(route('domains.refresh'))
        ->assertRedirect(route('domains.index'));

    // The scrape + probe are queued, not run inline.
    Queue::assertPushed(QueuedCommand::class);
});
