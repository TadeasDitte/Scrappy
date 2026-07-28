<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useStream } from '@laravel/stream-vue';
import {
    Bot,
    ChevronDown,
    RotateCcw,
    SendHorizonal,
    Square,
    Trash2,
    User,
} from '@lucide/vue';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as chatIndex, stream as chatStream } from '@/routes/chat';

interface ModelOption {
    id: number;
    name: string;
    parameter_size: string | null;
}

interface DomainOption {
    id: number;
    host: string;
    response_time_ms: number | null;
    model_count: number;
    models: ModelOption[];
}

interface Message {
    role: 'user' | 'assistant';
    content: string;
    status: 'pending' | 'streaming' | 'done' | 'error';
    startedAt: number;
    finishedAt: number | null;
}

const props = defineProps<{
    domains: DomainOption[];
    selectedDomainId: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chat', href: chatIndex().url }],
    },
});

const defaultOptions = {
    system: '',
    temperature: '',
    top_p: '',
    num_predict: '',
    seed: '',
    stop: '',
};

const selectedDomainId = ref<number | null>(
    props.selectedDomainId ?? props.domains[0]?.id ?? null,
);
const selectedModel = ref<string | null>(null);
const prompt = ref('');
const messages = ref<Message[]>([]);
const threadEl = ref<HTMLElement | null>(null);
const pinnedToBottom = ref(true);
const options = reactive({ ...defaultOptions });
const now = ref(Date.now());

let ticker: ReturnType<typeof setInterval> | null = null;

const selectedDomain = computed(
    () => props.domains.find((d) => d.id === selectedDomainId.value) ?? null,
);

// Default the model whenever the domain changes.
watch(
    selectedDomain,
    (domain) => {
        selectedModel.value = domain?.models[0]?.name ?? null;
    },
    { immediate: true },
);

const { isStreaming, isFetching, send, cancel } = useStream(chatStream().url, {
    // Append each chunk the moment it lands rather than re-reading the whole
    // accumulated buffer, so the thread grows token by token.
    onData: (chunk: string) => {
        const message = pendingMessage();

        if (!message) {
            return;
        }

        message.status = 'streaming';
        message.content += chunk;
        stickToBottom();
    },
    onFinish: () => finishPending('done'),
    onCancel: () => finishPending('done'),
    onError: () => {
        const message = pendingMessage();

        if (message && message.content === '') {
            message.content = 'The endpoint did not respond.';
        }

        finishPending('error');
    },
});

const canSend = computed(
    () =>
        !isStreaming.value &&
        !isFetching.value &&
        !!selectedDomainId.value &&
        !!selectedModel.value &&
        prompt.value.trim().length > 0,
);

const busy = computed(() => isStreaming.value || isFetching.value);

/** The assistant message currently receiving tokens, if any. */
function pendingMessage(): Message | null {
    const last = messages.value[messages.value.length - 1];

    return last?.role === 'assistant' &&
        (last.status === 'pending' || last.status === 'streaming')
        ? last
        : null;
}

function finishPending(status: 'done' | 'error') {
    const message = pendingMessage();

    if (message) {
        message.status = status;
        message.finishedAt = Date.now();
    }

    stopTicker();
}

/** Live characters-per-second while a message streams, for the status line. */
function throughput(message: Message): string {
    const elapsed =
        ((message.finishedAt ?? now.value) - message.startedAt) / 1000;

    if (elapsed < 0.2 || message.content.length === 0) {
        return '';
    }

    return `${Math.round(message.content.length / elapsed)} char/s`;
}

function elapsedLabel(message: Message): string {
    const elapsed =
        ((message.finishedAt ?? now.value) - message.startedAt) / 1000;

    return `${elapsed.toFixed(1)}s`;
}

function startTicker() {
    stopTicker();
    ticker = setInterval(() => (now.value = Date.now()), 100);
}

function stopTicker() {
    if (ticker) {
        clearInterval(ticker);
        ticker = null;
    }
}

onUnmounted(stopTicker);

/**
 * The generation options to send, omitting anything left blank so the server
 * only receives the knobs the user actually turned.
 */
function generationPayload(): Record<string, unknown> {
    const numeric: Record<string, number> = {};

    for (const key of [
        'temperature',
        'top_p',
        'num_predict',
        'seed',
    ] as const) {
        const raw = options[key].trim();

        if (raw !== '' && !Number.isNaN(Number(raw))) {
            numeric[key] = Number(raw);
        }
    }

    const stop = options.stop
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== '')
        .slice(0, 4);

    const payload: Record<string, unknown> = {};
    const merged = stop.length ? { ...numeric, stop } : numeric;

    if (Object.keys(merged).length) {
        payload.options = merged;
    }

    if (options.system.trim() !== '') {
        payload.system = options.system.trim();
    }

    return payload;
}

function submit() {
    if (!canSend.value) {
        return;
    }

    const text = prompt.value.trim();
    const startedAt = Date.now();

    messages.value.push({
        role: 'user',
        content: text,
        status: 'done',
        startedAt,
        finishedAt: startedAt,
    });
    messages.value.push({
        role: 'assistant',
        content: '',
        status: 'pending',
        startedAt,
        finishedAt: null,
    });

    prompt.value = '';
    pinnedToBottom.value = true;
    startTicker();
    stickToBottom();

    send({
        domain_id: selectedDomainId.value,
        model: selectedModel.value,
        prompt: text,
        ...generationPayload(),
    });
}

function clearThread() {
    if (busy.value) {
        cancel();
    }

    messages.value = [];
}

function resetOptions() {
    Object.assign(options, defaultOptions);
}

function onPromptKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

/** Follow the stream only while the reader is already at the bottom. */
function onThreadScroll() {
    const el = threadEl.value;

    if (!el) {
        return;
    }

    pinnedToBottom.value =
        el.scrollHeight - el.scrollTop - el.clientHeight < 48;
}

async function stickToBottom() {
    if (!pinnedToBottom.value) {
        return;
    }

    await nextTick();
    threadEl.value?.scrollTo({ top: threadEl.value.scrollHeight });
}
</script>

<template>
    <Head title="Chat" />

    <div class="flex h-[calc(100vh-8rem)] flex-col gap-4 p-4">
        <Heading
            title="Chat"
            description="Pick an active domain and model, then prompt it directly."
        />

        <div class="grid flex-1 gap-4 overflow-hidden lg:grid-cols-[18rem_1fr]">
            <!-- Pickers -->
            <aside
                class="flex flex-col gap-4 overflow-y-auto rounded-xl border p-4"
            >
                <div class="space-y-2">
                    <Label>Domain</Label>
                    <Select v-model="selectedDomainId">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a domain" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="domain in domains"
                                :key="domain.id"
                                :value="domain.id"
                            >
                                <span class="flex items-center gap-2">
                                    <StatusDot />
                                    {{ domain.host }}
                                    <span class="text-muted-foreground">
                                        ({{ domain.response_time_ms ?? '—' }} ms
                                        · {{ domain.model_count }} models)
                                    </span>
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-2">
                    <Label>Model</Label>
                    <Select v-model="selectedModel" :disabled="!selectedDomain">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a model" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="model in selectedDomain?.models ?? []"
                                :key="model.id"
                                :value="model.name"
                            >
                                {{ model.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Generation options, mirroring what the API accepts -->
                <Collapsible class="space-y-3">
                    <CollapsibleTrigger
                        class="group flex w-full items-center justify-between text-sm font-medium"
                    >
                        Options
                        <ChevronDown
                            class="h-4 w-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
                        />
                    </CollapsibleTrigger>

                    <CollapsibleContent class="space-y-3">
                        <div class="space-y-1.5">
                            <Label for="system">System prompt</Label>
                            <textarea
                                id="system"
                                v-model="options.system"
                                rows="3"
                                placeholder="You are a terse assistant."
                                class="w-full resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <Label for="temperature">Temperature</Label>
                                <Input
                                    id="temperature"
                                    v-model="options.temperature"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    max="2"
                                    placeholder="0.8"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="top_p">Top P</Label>
                                <Input
                                    id="top_p"
                                    v-model="options.top_p"
                                    type="number"
                                    step="0.05"
                                    min="0"
                                    max="1"
                                    placeholder="0.9"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="num_predict">Max tokens</Label>
                                <Input
                                    id="num_predict"
                                    v-model="options.num_predict"
                                    type="number"
                                    step="1"
                                    min="-1"
                                    max="8192"
                                    placeholder="auto"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label for="seed">Seed</Label>
                                <Input
                                    id="seed"
                                    v-model="options.seed"
                                    type="number"
                                    step="1"
                                    placeholder="random"
                                />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="stop">Stop sequences</Label>
                            <Input
                                id="stop"
                                v-model="options.stop"
                                placeholder="comma, separated"
                            />
                        </div>

                        <Button
                            variant="ghost"
                            size="sm"
                            class="w-full"
                            @click="resetOptions"
                        >
                            <RotateCcw class="h-3.5 w-3.5" />
                            Reset options
                        </Button>
                    </CollapsibleContent>
                </Collapsible>

                <p v-if="!domains.length" class="text-sm text-muted-foreground">
                    No active domains yet. Visit the Domains page and refresh.
                </p>
            </aside>

            <!-- Thread -->
            <section class="flex min-h-0 flex-col rounded-xl border">
                <div
                    class="flex items-center justify-between border-b px-4 py-2 text-xs text-muted-foreground"
                >
                    <span class="flex items-center gap-2">
                        <StatusDot :active="!!selectedDomain" :pulse="busy" />
                        <span class="truncate">
                            {{ selectedDomain?.host ?? 'No domain selected' }}
                        </span>
                        <span v-if="selectedModel">· {{ selectedModel }}</span>
                    </span>
                    <Button
                        v-if="messages.length"
                        variant="ghost"
                        size="sm"
                        @click="clearThread"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        Clear
                    </Button>
                </div>

                <div
                    ref="threadEl"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @scroll="onThreadScroll"
                >
                    <div
                        v-if="!messages.length"
                        class="flex h-full items-center justify-center text-center text-muted-foreground"
                    >
                        Send a prompt to start the conversation.
                    </div>

                    <div
                        v-for="(message, index) in messages"
                        :key="index"
                        class="flex gap-3"
                        :class="
                            message.role === 'user'
                                ? 'justify-end'
                                : 'justify-start'
                        "
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
                                    message.status === 'error'
                                        ? 'text-destructive'
                                        : '',
                                ]"
                            >
                                {{ message.content
                                }}<span
                                    v-if="
                                        message.status === 'pending' ||
                                        message.status === 'streaming'
                                    "
                                    class="ml-0.5 inline-block w-[0.5em] animate-pulse bg-current align-text-bottom"
                                    aria-hidden="true"
                                    >&nbsp;</span
                                >
                            </div>

                            <p
                                v-if="
                                    message.role === 'assistant' &&
                                    message.status !== 'pending'
                                "
                                class="px-1 text-[11px] text-muted-foreground"
                            >
                                {{ elapsedLabel(message) }}
                                <template v-if="throughput(message)">
                                    · {{ throughput(message) }}
                                </template>
                                <template v-if="message.status === 'streaming'">
                                    · streaming
                                </template>
                            </p>
                        </div>

                        <div
                            v-if="message.role === 'user'"
                            class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        >
                            <User class="h-4 w-4" />
                        </div>
                    </div>
                </div>

                <!-- Composer -->
                <div class="border-t p-3">
                    <div class="flex items-end gap-2">
                        <textarea
                            v-model="prompt"
                            rows="1"
                            placeholder="Message the model…  (Enter to send, Shift+Enter for newline)"
                            class="max-h-40 min-h-11 flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            @keydown="onPromptKeydown"
                        />
                        <Button v-if="busy" variant="secondary" @click="cancel">
                            <Square class="h-4 w-4" />
                            Stop
                        </Button>
                        <Button v-else :disabled="!canSend" @click="submit">
                            <SendHorizonal class="h-4 w-4" />
                            Send
                        </Button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
