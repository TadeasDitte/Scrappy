import type { Ref } from 'vue';
import { onUnmounted, readonly, ref } from 'vue';

export type ChatStreamHandlers = {
    /** The response headers, in hand before any of the body has arrived. */
    onResponse?: (response: Response) => void;
    /** A decoded slice of the body, in the order it was received. */
    onData?: (chunk: string) => void;
    /** The body was read to the end. */
    onFinish?: () => void;
    /** The request failed. Never called for a cancel the caller asked for. */
    onError?: (message: string) => void;
};

export type UseChatStreamReturn = {
    isFetching: Readonly<Ref<boolean>>;
    isStreaming: Readonly<Ref<boolean>>;
    send: (body: Record<string, unknown>) => void;
    cancel: () => void;
};

const NO_RESPONSE = 'The endpoint did not respond.';

/**
 * POST a JSON body to a route and read its plain-text response as bytes arrive.
 *
 * Hand-rolled rather than `@laravel/stream-vue`'s `useStream` because that
 * composable (0.3.13) has two defects this page cannot live with: it invokes its
 * cancel handler at the top of every `send()` instead of only on a real abort,
 * and it decodes each network chunk with a fresh, non-streaming `TextDecoder`,
 * so any multi-byte character split across a chunk boundary arrives as `�`.
 */
export function useChatStream(
    url: string,
    handlers: ChatStreamHandlers = {},
): UseChatStreamReturn {
    const isFetching = ref(false);
    const isStreaming = ref(false);

    /** The request currently in flight, if there is one. */
    let inFlight: AbortController | null = null;

    function send(body: Record<string, unknown>): void {
        void post(body);
    }

    /** Abandon the request in flight; none of its handlers run again. */
    function cancel(): void {
        inFlight?.abort();
        inFlight = null;
        isFetching.value = false;
        isStreaming.value = false;
    }

    async function post(body: Record<string, unknown>): Promise<void> {
        cancel();

        const own = new AbortController();

        inFlight = own;
        isFetching.value = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                signal: own.signal,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    // Ask for JSON so a validation or session failure comes back
                    // as an envelope we can read a message out of, rather than
                    // as Laravel's HTML error page.
                    Accept: 'application/json, text/plain',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });

            handlers.onResponse?.(response);

            if (!response.ok || response.body === null) {
                handlers.onError?.(await failureMessage(response));

                return;
            }

            isFetching.value = false;
            isStreaming.value = true;

            await read(response.body.getReader(), own.signal);

            if (own.signal.aborted) {
                return;
            }

            handlers.onFinish?.();
        } catch {
            // An abort is how `cancel()` works, not a failure worth reporting.
            if (!own.signal.aborted) {
                handlers.onError?.(NO_RESPONSE);
            }
        } finally {
            // A newer request may already own these flags; only the request that
            // still holds them may put them down.
            if (inFlight === own) {
                inFlight = null;
                isFetching.value = false;
                isStreaming.value = false;
            }
        }
    }

    async function read(
        reader: ReadableStreamDefaultReader<Uint8Array>,
        signal: AbortSignal,
    ): Promise<void> {
        // One decoder for the whole body: a streaming decode holds on to a
        // partial character until the rest of its bytes turn up, instead of
        // emitting a replacement character for each half.
        const decoder = new TextDecoder('utf-8');

        for (;;) {
            const { done, value } = await reader.read();

            if (signal.aborted) {
                return;
            }

            const chunk = decoder.decode(value, { stream: !done });

            if (chunk !== '') {
                handlers.onData?.(chunk);
            }

            if (done) {
                return;
            }
        }
    }

    /**
     * The message a failed response carries, when it carries one at all.
     */
    async function failureMessage(response: Response): Promise<string> {
        const body = await response.text().catch(() => '');

        try {
            const parsed: unknown = JSON.parse(body);
            const message = (parsed as { message?: unknown } | null)?.message;

            if (typeof message === 'string' && message !== '') {
                return message;
            }
        } catch {
            // Not a JSON envelope — an HTML error page, or nothing at all.
        }

        return NO_RESPONSE;
    }

    function csrfToken(): string {
        return (
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? ''
        );
    }

    onUnmounted(cancel);

    return {
        isFetching: readonly(isFetching),
        isStreaming: readonly(isStreaming),
        send,
        cancel,
    };
}
