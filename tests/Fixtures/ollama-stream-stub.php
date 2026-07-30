<?php

/**
 * A minimal stand-in for Ollama's streaming API, served by `php -S`.
 *
 * The chunked framing is written by hand so the reply reaches the client the way
 * a real endpoint delivers one: as a series of small NDJSON objects, separated in
 * time, under `Transfer-Encoding: chunked`. Streaming defects only show up
 * against a socket that behaves like this, which is what this exists for.
 */
$tokens = ['Prime ', 'numbers ', 'are ', 'interesting.'];
$gap = max(0, (int) ($_GET['gap'] ?? 200)) * 1000;

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/x-ndjson');
header('Transfer-Encoding: chunked');

/**
 * Write one NDJSON object as its own HTTP chunk, and push it out now.
 */
$emit = function (array $payload): void {
    $line = json_encode($payload)."\n";

    echo dechex(strlen($line))."\r\n".$line."\r\n";
    flush();
};

foreach ($tokens as $token) {
    $emit(['response' => $token, 'done' => false]);
    usleep($gap);
}

$emit(['response' => '', 'done' => true, 'done_reason' => 'stop']);

echo "0\r\n\r\n";
flush();
