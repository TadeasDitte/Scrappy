<script setup lang="ts">
import { Bot, Check, Copy, User } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';

export interface ChatMessageData {
    role: 'user' | 'assistant';
    content: string;
    status: 'pending' | 'streaming' | 'done' | 'error';
    startedAt: number;
    finishedAt: number | null;
}

const props = defineProps<{
    message: ChatMessageData;
}>();

const copied = ref(false);
const now = ref(Date.now());

// The clock lives here rather than in the thread: ticking a parent ref would
// re-render every message in the conversation ten times a second.
let ticker: ReturnType<typeof setInterval> | null = null;

const isLive = computed(
    () =>
        props.message.status === 'pending' ||
        props.message.status === 'streaming',
);

watch(
    isLive,
    (live) => {
        if (live && !ticker) {
            ticker = setInterval(() => (now.value = Date.now()), 200);
        }

        if (!live && ticker) {
            clearInterval(ticker);
            ticker = null;
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    if (ticker) {
        clearInterval(ticker);
    }
});

const elapsed = computed(() => {
    // A replayed message has no start time, only the latency we recorded.
    if (props.message.startedAt === 0) {
        return (props.message.finishedAt ?? 0) / 1000;
    }

    return (
        ((props.message.finishedAt ?? now.value) - props.message.startedAt) /
        1000
    );
});

const statusLine = computed(() => {
    if (props.message.status === 'pending') {
        return `waiting for first token… ${elapsed.value.toFixed(1)}s`;
    }

    if (props.message.status === 'error') {
        return 'failed';
    }

    const parts: string[] = [];

    // A duration we never measured — a replayed turn saved without one, or a
    // reply that failed instantly — is not worth reporting as "0.0s".
    if (elapsed.value >= 0.05) {
        parts.push(`${elapsed.value.toFixed(1)}s`);
    }

    if (elapsed.value > 0.2 && props.message.content.length > 0) {
        parts.push(
            `${Math.round(props.message.content.length / elapsed.value)} char/s`,
        );
    }

    if (props.message.status === 'streaming') {
        parts.push('streaming');
    }

    return parts.join(' · ');
});

/**
 * `navigator.clipboard` only exists on secure origins, and this app is
 * routinely reached over plain HTTP by LAN address, so fall back to a hidden
 * textarea instead of throwing — and only claim success when it worked.
 */
async function copy() {
    if (!(await writeToClipboard(props.message.content))) {
        return;
    }

    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

async function writeToClipboard(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);

            return true;
        }

        const scratch = document.createElement('textarea');
        scratch.value = text;
        scratch.setAttribute('readonly', '');
        scratch.style.position = 'fixed';
        scratch.style.opacity = '0';
        document.body.appendChild(scratch);
        scratch.select();

        const succeeded = document.execCommand('copy');
        document.body.removeChild(scratch);

        return succeeded;
    } catch {
        return false;
    }
}
</script>

<template>
    <div
        class="flex gap-3"
        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
    >
        <div
            v-if="message.role === 'assistant'"
            class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted"
        >
            <Bot class="h-4 w-4" />
        </div>

        <div class="max-w-[80%] space-y-1">
            <div
                class="rounded-2xl px-4 py-2 text-sm whitespace-pre-wrap"
                :class="[
                    message.role === 'user'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted',
                    message.status === 'error' ? 'text-destructive' : '',
                ]"
            >
                {{ message.content
                }}<span
                    v-if="isLive"
                    class="ml-0.5 inline-block w-[0.5em] animate-pulse bg-current align-text-bottom"
                    aria-hidden="true"
                    >&nbsp;</span
                >
            </div>

            <div
                v-if="
                    message.role === 'assistant' &&
                    (statusLine || message.content)
                "
                class="flex items-center gap-2 px-1 text-[11px] text-muted-foreground"
            >
                <span v-if="statusLine">{{ statusLine }}</span>
                <button
                    v-if="message.content && message.status !== 'pending'"
                    type="button"
                    class="inline-flex items-center gap-1 hover:text-foreground"
                    @click="copy"
                >
                    <Check v-if="copied" class="h-3 w-3" />
                    <Copy v-else class="h-3 w-3" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </div>

        <div
            v-if="message.role === 'user'"
            class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
        >
            <User class="h-4 w-4" />
        </div>
    </div>
</template>
