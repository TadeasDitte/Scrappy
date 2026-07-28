<?php

use App\Models\Domain;

test('rankedBySpeed orders fastest first and untested last', function () {
    Domain::factory()->create(['host' => 'slow.test', 'response_time_ms' => 900]);
    Domain::factory()->create(['host' => 'fast.test', 'response_time_ms' => 100]);
    Domain::factory()->create(['host' => 'untested.test', 'response_time_ms' => null]);

    $order = Domain::query()->rankedBySpeed()->pluck('host')->all();

    expect($order)->toBe(['fast.test', 'slow.test', 'untested.test']);
});

test('rankedByModelCount orders by most models first', function () {
    Domain::factory()->create(['host' => 'few.test', 'model_count' => 2]);
    Domain::factory()->create(['host' => 'many.test', 'model_count' => 15]);

    $order = Domain::query()->rankedByModelCount()->pluck('host')->all();

    expect($order)->toBe(['many.test', 'few.test']);
});

test('active scope only returns active domains', function () {
    Domain::factory()->active()->create();
    Domain::factory()->inactive()->create();

    expect(Domain::query()->active()->count())->toBe(1);
});
