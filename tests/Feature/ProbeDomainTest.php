<?php

use App\Jobs\ProbeDomain;
use App\Models\Domain;
use App\Services\OllamaClient;
use Illuminate\Support\Facades\Http;

test('a legit /api/tags response marks the domain active and syncs models', function () {
    $domain = Domain::factory()->create(['host' => 'ollama.example.com']);

    Http::fake([
        'https://ollama.example.com/api/tags' => Http::response([
            'models' => [
                [
                    'name' => 'llama3:latest',
                    'digest' => 'abc123',
                    'size' => 4_700_000_000,
                    'details' => [
                        'family' => 'llama',
                        'parameter_size' => '8B',
                        'quantization_level' => 'Q4_0',
                    ],
                ],
                ['name' => 'qwen2:7b'],
            ],
        ]),
    ]);

    (new ProbeDomain($domain))->handle(app(OllamaClient::class));

    $domain->refresh();

    expect($domain->is_active)->toBeTrue()
        ->and($domain->model_count)->toBe(2)
        ->and($domain->last_error)->toBeNull()
        ->and($domain->response_time_ms)->not->toBeNull()
        ->and($domain->models()->pluck('name')->all())->toContain('llama3:latest', 'qwen2:7b');

    $llama = $domain->models()->where('name', 'llama3:latest')->first();
    expect($llama->family)->toBe('llama')
        ->and($llama->parameter_size)->toBe('8B')
        ->and($llama->quantization)->toBe('Q4_0');
});

test('an unreachable host marks the domain inactive and counts the failure', function () {
    $domain = Domain::factory()->active()->create([
        'host' => 'ollama.dead.example',
        'consecutive_failures' => 1,
    ]);

    Http::fake([
        'https://ollama.dead.example/api/tags' => Http::response('nope', 500),
    ]);

    (new ProbeDomain($domain))->handle(app(OllamaClient::class));

    $domain->refresh();

    expect($domain->is_active)->toBeFalse()
        ->and($domain->model_count)->toBe(0)
        ->and($domain->consecutive_failures)->toBe(2)
        ->and($domain->last_error)->not->toBeNull();
});

test('models no longer advertised are flagged unavailable', function () {
    $domain = Domain::factory()->create(['host' => 'ollama.example.com']);
    $domain->models()->create(['name' => 'old-model:latest', 'available' => true]);

    Http::fake([
        'https://ollama.example.com/api/tags' => Http::response([
            'models' => [['name' => 'new-model:latest']],
        ]),
    ]);

    (new ProbeDomain($domain))->handle(app(OllamaClient::class));

    expect($domain->models()->where('name', 'old-model:latest')->first()->available)->toBeFalse()
        ->and($domain->models()->where('name', 'new-model:latest')->first()->available)->toBeTrue();
});
