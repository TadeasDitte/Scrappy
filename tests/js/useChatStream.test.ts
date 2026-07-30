import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import type { ChatStreamHandlers } from '../../resources/js/composables/useChatStream.ts';
import { useChatStream } from '../../resources/js/composables/useChatStream.ts';

const STREAM_URL = '/chat/stream';

const realFetch = globalThis.fetch;

beforeEach(() => {
    // The composable reads the CSRF token straight off the page.
    globalThis.document = {
        querySelector: () => ({ content: 'token' }),
    } as unknown as Document;
});

afterEach(() => {
    globalThis.fetch = realFetch;
});

function encode(text: string): Uint8Array {
    return new TextEncoder().encode(text);
}

/** Answer the next request with these byte chunks, in this order. */
function respondWith(chunks: Uint8Array[], init: ResponseInit = {}): void {
    globalThis.fetch = () =>
        Promise.resolve(
            new Response(
                new ReadableStream<Uint8Array>({
                    start(controller) {
                        chunks.forEach((chunk) => controller.enqueue(chunk));
                        controller.close();
                    },
                }),
                { status: 200, ...init },
            ),
        );
}

/**
 * `useChatStream` registers an unmount hook, and driving it without a component
 * instance makes Vue warn about that — expected here, and only noise.
 */
function build(handlers: ChatStreamHandlers) {
    const warn = console.warn;

    console.warn = () => {};

    try {
        return useChatStream(STREAM_URL, handlers);
    } finally {
        console.warn = warn;
    }
}

async function waitFor(condition: () => boolean): Promise<void> {
    for (let attempt = 0; attempt < 200; attempt += 1) {
        if (condition()) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 1));
    }

    assert.fail('the condition was never met');
}

test('the whole reply is delivered, in order, and the stream finishes once', async () => {
    respondWith([encode('Hello'), encode(' world')], {
        headers: { 'X-Conversation-Id': '7' },
    });

    const received: string[] = [];
    let finished = 0;
    let failed = 0;
    let announced: string | null = null;

    const stream = build({
        onResponse: (response) => {
            announced = response.headers.get('X-Conversation-Id');
        },
        onData: (chunk) => received.push(chunk),
        onFinish: () => {
            finished += 1;
        },
        onError: () => {
            failed += 1;
        },
    });

    stream.send({ prompt: 'hi' });

    await waitFor(() => finished === 1);

    assert.equal(received.join(''), 'Hello world');
    assert.equal(failed, 0);
    // The id is read off the headers so a new thread can adopt it mid-stream.
    assert.equal(announced, '7');
    assert.equal(stream.isStreaming.value, false);
    assert.equal(stream.isFetching.value, false);
});

test('a multi-byte character split across two chunks is not mangled', async () => {
    const reply = 'Příliš žluťoučký kůň — 😀';
    const bytes = encode(reply);

    // Cut the body inside the emoji's four bytes: a decoder that starts afresh
    // on every chunk turns each half into a replacement character.
    const split = bytes.length - 2;

    respondWith([bytes.slice(0, split), bytes.slice(split)]);

    const received: string[] = [];
    let finished = 0;

    const stream = build({
        onData: (chunk) => received.push(chunk),
        onFinish: () => {
            finished += 1;
        },
    });

    stream.send({ prompt: 'hi' });

    await waitFor(() => finished === 1);

    assert.equal(received.join(''), reply);
    assert.ok(!received.join('').includes('�'));
});

test('a second send delivers its own reply in full', async () => {
    const received: string[] = [];
    let finished = 0;

    const stream = build({
        onData: (chunk) => received.push(chunk),
        onFinish: () => {
            finished += 1;
        },
    });

    respondWith([encode('first')]);
    stream.send({ prompt: 'one' });
    await waitFor(() => finished === 1);

    respondWith([encode('second')]);
    stream.send({ prompt: 'two' });
    await waitFor(() => finished === 2);

    assert.equal(received.join(''), 'firstsecond');
});

test('a cancelled reply keeps what arrived and reports neither finish nor failure', async () => {
    let body!: ReadableStreamDefaultController<Uint8Array>;

    globalThis.fetch = () =>
        Promise.resolve(
            new Response(
                new ReadableStream<Uint8Array>({
                    start(controller) {
                        body = controller;
                        controller.enqueue(encode('half a th'));
                    },
                }),
                { status: 200 },
            ),
        );

    const received: string[] = [];
    let finished = 0;
    let failed = 0;

    const stream = build({
        onData: (chunk) => received.push(chunk),
        onFinish: () => {
            finished += 1;
        },
        onError: () => {
            failed += 1;
        },
    });

    stream.send({ prompt: 'hi' });

    await waitFor(() => received.length === 1);

    stream.cancel();

    try {
        body.enqueue(encode('ought'));
        body.close();
    } catch {
        // Aborting the request errors the body; there is no reader left to feed.
    }

    await waitFor(() => stream.isStreaming.value === false);

    assert.deepEqual(received, ['half a th']);
    // An abort is what cancelling *is*, so it is neither an end nor a failure.
    assert.equal(finished, 0);
    assert.equal(failed, 0);
});

test('a rejected request reports the message the server sent', async () => {
    globalThis.fetch = () =>
        Promise.resolve(
            new Response(
                JSON.stringify({ message: 'The prompt field is required.' }),
                { status: 422 },
            ),
        );

    const reported: string[] = [];
    let finished = 0;

    const stream = build({
        onData: () => assert.fail('no body should have been read'),
        onFinish: () => {
            finished += 1;
        },
        onError: (message) => reported.push(message),
    });

    stream.send({ prompt: '' });

    await waitFor(() => reported.length === 1);

    assert.deepEqual(reported, ['The prompt field is required.']);
    assert.equal(finished, 0);
    assert.equal(stream.isFetching.value, false);
});

test('an unreachable endpoint is reported without a message of its own', async () => {
    globalThis.fetch = () => Promise.reject(new Error('Failed to fetch'));

    const reported: string[] = [];

    const stream = build({ onError: (message) => reported.push(message) });

    stream.send({ prompt: 'hi' });

    await waitFor(() => reported.length === 1);

    assert.deepEqual(reported, ['The endpoint did not respond.']);
});

test('the prompt is posted as json, with the csrf token', async () => {
    const sent: RequestInit[] = [];

    globalThis.fetch = (_url: unknown, init?: RequestInit) => {
        sent.push(init ?? {});

        return Promise.resolve(new Response('ok', { status: 200 }));
    };

    const stream = build({});

    stream.send({ prompt: 'hi', temporary: true });

    await waitFor(() => sent.length === 1);

    const [request] = sent;
    const headers = request.headers as Record<string, string>;

    assert.equal(request.method, 'POST');
    assert.equal(request.body, '{"prompt":"hi","temporary":true}');
    assert.equal(headers['Content-Type'], 'application/json');
    assert.equal(headers['X-CSRF-TOKEN'], 'token');
    // Without this, a validation or session failure comes back as an HTML page.
    assert.match(headers.Accept, /application\/json/);
});
