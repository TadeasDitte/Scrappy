<?php

use App\Models\Domain;
use App\Models\User;

test('the api tokens page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertOk();
});

test('a user can create a token and receive its plaintext once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'My CLI',
        'abilities' => ['domains:read'],
    ]);

    $response->assertSessionHasNoErrors();

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()->name)->toBe('My CLI')
        ->and($user->tokens()->first()->abilities)->toBe(['domains:read']);
});

test('a user can revoke their own token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('temp');

    $this->actingAs($user)
        ->delete(route('api-tokens.destroy', $token->accessToken->id))
        ->assertSessionHasNoErrors();

    expect($user->tokens()->count())->toBe(0);
});

test('the api rejects unauthenticated requests', function () {
    $this->getJson('/api/v1/domains')->assertUnauthorized();
});

test('a token with the domains:read ability can list active domains', function () {
    $user = User::factory()->create();
    Domain::factory()->active()->count(2)->create();
    Domain::factory()->inactive()->create();

    $token = $user->createToken('reader', ['domains:read'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/domains')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a token missing the ability is forbidden', function () {
    $user = User::factory()->create();
    $token = $user->createToken('weak', ['chat:generate'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/domains')
        ->assertForbidden();
});
