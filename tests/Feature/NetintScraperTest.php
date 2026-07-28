<?php

use App\Models\Domain;
use App\Services\NetintScraper;
use Illuminate\Support\Facades\Http;

test('it parses hostnames out of the netint results table', function () {
    $html = file_get_contents(base_path('tests/Fixtures/netint.html'));

    $hosts = (new NetintScraper)->parse($html);

    expect($hosts->all())->toBe([
        'ollama.primedhome.net',
        'ollama.oa1.trudax.tech',
        'ollama.example.com',
    ]);
});

test('it lowercases, dedupes and rejects non-hostname cells', function () {
    $html = file_get_contents(base_path('tests/Fixtures/netint.html'));

    $hosts = (new NetintScraper)->parse($html);

    expect($hosts)->toHaveCount(3)
        ->and($hosts->contains('not a host!!!'))->toBeFalse();
});

test('syncDomains upserts scraped hosts without flipping activity', function () {
    Http::fake([
        '*' => Http::response(file_get_contents(base_path('tests/Fixtures/netint.html'))),
    ]);

    // Pre-existing active domain should keep its active flag after a re-sync.
    $existing = Domain::factory()->active()->create(['host' => 'ollama.primedhome.net']);

    $synced = (new NetintScraper)->syncDomains();

    expect($synced)->toHaveCount(3)
        ->and(Domain::count())->toBe(3);

    $existing->refresh();
    expect($existing->is_active)->toBeTrue()
        ->and(Domain::where('host', 'ollama.example.com')->exists())->toBeTrue();
});
