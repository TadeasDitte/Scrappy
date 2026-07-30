<?php

use App\Models\Domain;
use App\Services\OllamaClient;

/**
 * The gap the stub leaves between tokens, in milliseconds.
 */
const TOKEN_GAP_MS = 200;

/**
 * Start the stub endpoint on a free port and return its host, plus a closer.
 *
 * @return array{0: string, 1: Closure(): void}
 */
function stubEndpoint(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

    if ($probe === false) {
        test()->markTestSkipped("a port could not be reserved: {$message}");
    }

    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Fixtures/ollama-stream-stub.php')],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        test()->markTestSkipped('the stub endpoint could not be started');
    }

    $close = function () use ($process): void {
        proc_terminate($process);
        proc_close($process);
    };

    // The server needs a moment before it will accept anything.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

        if (is_resource($socket)) {
            fclose($socket);

            return ["127.0.0.1:{$port}", $close];
        }

        usleep(50_000);
    }

    $close();
    test()->markTestSkipped('the stub endpoint never came up');
}

test('tokens are yielded as they arrive rather than all at the end', function () {
    [$host, $close] = stubEndpoint();

    $domain = Domain::factory()->active()->create(['host' => $host, 'scheme' => 'http']);

    /** @var array<int, float> $arrivals */
    $arrivals = [];
    $reply = '';

    try {
        foreach (app(OllamaClient::class)->generateStream($domain, 'stub:latest', 'hi') as $chunk) {
            $arrivals[] = microtime(true);
            $reply .= $chunk;
        }
    } finally {
        $close();
    }

    expect($reply)->toBe('Prime numbers are interesting.')
        ->and($arrivals)->toHaveCount(4);

    // Measured between the first and last token rather than from the start, so
    // the assertion holds regardless of how slow the machine or its network is.
    // A reply held back until the endpoint hangs up arrives all at once, making
    // this spread a fraction of a millisecond.
    $spreadMs = (end($arrivals) - $arrivals[0]) * 1000;

    expect($spreadMs)->toBeGreaterThan(TOKEN_GAP_MS * 1.5);
})->group('streaming');
