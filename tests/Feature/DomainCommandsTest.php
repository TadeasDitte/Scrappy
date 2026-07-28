<?php

use App\Jobs\ProbeDomain;
use App\Models\Domain;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

test('scrape:netint syncs domains and queues probes for new ones', function () {
    Bus::fake();

    Http::fake([
        'netint.xyz*' => Http::response(file_get_contents(base_path('tests/Fixtures/netint.html'))),
    ]);

    $this->artisan('scrape:netint')->assertSuccessful();

    expect(Domain::count())->toBe(3);

    Bus::assertDispatchedTimes(ProbeDomain::class, 3);
});

test('scrape:netint with --no-probe skips dispatching jobs', function () {
    Bus::fake();

    Http::fake([
        'netint.xyz*' => Http::response(file_get_contents(base_path('tests/Fixtures/netint.html'))),
    ]);

    $this->artisan('scrape:netint --no-probe')->assertSuccessful();

    Bus::assertNothingDispatched();
});

test('domains:probe --only-active queues only active domains', function () {
    Bus::fake();

    Domain::factory()->active()->count(2)->create();
    Domain::factory()->inactive()->count(3)->create();

    $this->artisan('domains:probe --only-active')->assertSuccessful();

    Bus::assertDispatchedTimes(ProbeDomain::class, 2);
});
