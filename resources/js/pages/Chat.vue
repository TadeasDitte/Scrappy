<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useStream } from '@laravel/stream-vue';
import {
    Check,
    ChevronDown,
    Ghost,
    Pencil,
    Plus,
    RotateCcw,
    SendHorizonal,
    Square,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import ChatMessage from '@/components/ChatMessage.vue';
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
import {
    clear as clearConversations,
    destroy as destroyConversation,
    update as renameConversation,
} from '@/routes/conversations';

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

interface ConversationSummary {
    id: number;
    title: string;
    last_message_at: string | null;
}

interface StoredMessage {
    role: 'user' | 'assistant';
    content: string;
    latency_ms: number | null;
}

interface ActiveConversation {
    id: number;
    title: string;
    domain_id: number | null;
    model: string | null;
    messages: StoredMessage[];
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
    conversations: ConversationSummary[];
    activeConversation: ActiveConversation | null;
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
    keep_alive: '',
};

/** Replay a stored transcript into the local message shape. */
function fromStored(messages: StoredMessage[]): Message[] {
    return messages.map((message) => ({
        role: message.role,
        content: message.content,
        status: 'done' as const,
        startedAt: 0,
        finishedAt: message.latency_ms ?? 0,
    }));
}

const selectedDomainId = ref<number | null>(
    props.activeConversation?.domain_id ??
        props.selectedDomainId ??
        props.domains[0]?.id ??
        null,
);
const selectedModel = ref<string | null>(
    props.activeConversation?.model ?? null,
);
const conversationId = ref<number | null>(props.activeConversation?.id ?? null);
const messages = ref<Message[]>(
    fromStored(props.activeConversation?.messages ?? []),
);
const temporary = ref(false);
const prompt = ref('');
const threadEl = ref<HTMLElement | null>(null);
const pinnedToBottom = ref(true);
const options = reactive({ ...defaultOptions });
const renamingId = ref<number | null>(null);
const renameDraft = ref('');

const selectedDomain = computed(
    () => props.domains.find((d) => d.id === selectedDomainId.value) ?? null,
);

// Pick a model on load, and again whenever the domain changes to one that does
// not serve the current pick. Without `immediate` nothing is selected on a
// fresh visit and the composer stays disabled.
watch(
    selectedDomain,
    (domain) => {
        const names = domain?.models.map((model) => model.name) ?? [];

        if (!selectedModel.value || !names.includes(selectedModel.value)) {
            selectedModel.value = domain?.models[0]?.name ?? null;
        }
    },
    { immediate: true },
);

// Opening a conversation is a partial reload, so sync the thread when it lands.
watch(
    () => props.activeConversation,
    (conversation) => {
        conversationId.value = conversation?.id ?? null;
        messages.value = fromStored(conversation?.messages ?? []);

        if (conversation?.domain_id) {
            selectedDomainId.value = conversation.domain_id;
        }

        if (conversation?.model) {
            selectedModel.value = conversation.model;
        }

        pinnedToBottom.value = true;
        stickToBottom();
    },
);

const { isStreaming, isFetching, send, cancel } = useStream(chatStream().url, {
    // A brand new conversation announces its id in the response headers.
    onResponse: (response: Response) => {
        const id = response.headers.get('X-Conversation-Id');

        if (id) {
            conversationId.value = Number(id);
        }
    },
    // Chunks land in a plain buffer and are written to the reactive message
    // once per frame. Writing on every chunk means a re-render and a forced
    // layout hundreds of times a reply, which is what makes a fast model feel
    // slower in the browser than it does on the wire.
    onData: (chunk: string) => queueChunk(chunk),
    onFinish: () => {
        finishPending('done');

        // Pick up a newly created conversation (or an updated timestamp).
        if (!temporary.value) {
            router.reload({ only: ['conversations'] });
        }
    },
    onCancel: () => finishPending('done'),
    onError: () => {
        const message = pendingMessage();

        if (message && message.content === '') {
            message.content = 'The endpoint did not respond.';
        }

        finishPending('error');
    },
});

const busy = computed(() => isStreaming.value || isFetching.value);

const canSend = computed(
    () =>
        !busy.value &&
        !!selectedDomainId.value &&
        !!selectedModel.value &&
        prompt.value.trim().length > 0,
);

/** Text received but not yet written to the thread. Deliberately not reactive. */
let chunkBuffer = '';
let frame: number | null = null;

function queueChunk(chunk: string) {
    chunkBuffer += chunk;

    if (frame === null) {
        frame = requestAnimationFrame(flushChunks);
    }
}

/** Write everything buffered so far as a single reactive update. */
function flushChunks() {
    if (frame !== null) {
        cancelAnimationFrame(frame);
        frame = null;
    }

    if (chunkBuffer === '') {
        return;
    }

    const message = pendingMessage();

    if (!message) {
        return;
    }

    const pending = chunkBuffer;
    chunkBuffer = '';

    message.status = 'streaming';
    message.content += pending;
    stickToBottom();
}

/** The assistant message currently receiving tokens, if any. */
function pendingMessage(): Message | null {
    const last = messages.value[messages.value.length - 1];

    return last?.role === 'assistant' &&
        (last.status === 'pending' || last.status === 'streaming')
        ? last
        : null;
}

function finishPending(status: 'done' | 'error') {
    // Anything still buffered belongs in the thread before we mark it finished.
    flushChunks();

    const message = pendingMessage();

    if (message) {
        message.status = status;
        message.finishedAt = Date.now();
    }
}

onUnmounted(() => {
    if (frame !== null) {
        cancelAnimationFrame(frame);
    }
});

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

    // Holding the model in memory is the difference between a follow-up
    // answering immediately and the host loading it from disk all over again.
    if (options.keep_alive.trim() !== '') {
        payload.keep_alive = options.keep_alive.trim();
    }

    return payload;
}

function submit() {
    if (!canSend.value) {
        return;
    }

    const text = prompt.value.trim();
    const startedAt = Date.now();

    // A temporary chat keeps its context client-side; a saved one is replayed
    // from the database, so only the ephemeral case needs to send history.
    const history = temporary.value
        ? messages.value
              .filter(
                  (message) => message.status !== 'error' && message.content,
              )
              .map((message) => ({
                  role: message.role,
                  content: message.content,
              }))
        : [];

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
    stickToBottom();

    send({
        domain_id: selectedDomainId.value,
        model: selectedModel.value,
        prompt: text,
        conversation_id: temporary.value ? null : conversationId.value,
        temporary: temporary.value,
        history,
        ...generationPayload(),
    });
}

function newChat() {
    if (busy.value) {
        cancel();
    }

    messages.value = [];
    conversationId.value = null;
    prompt.value = '';

    router.get(
        chatIndex().url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['activeConversation', 'conversations'],
        },
    );
}

function openConversation(id: number) {
    if (busy.value) {
        cancel();
    }

    temporary.value = false;

    router.get(
        chatIndex({ query: { conversation: id } }).url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['activeConversation', 'conversations'],
        },
    );
}

function toggleTemporary() {
    temporary.value = !temporary.value;

    // Either mode starts from a clean slate: a temporary thread must not
    // inherit a saved transcript, and vice versa.
    if (busy.value) {
        cancel();
    }

    messages.value = [];
    conversationId.value = null;

    if (temporary.value && props.activeConversation) {
        router.get(
            chatIndex().url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['activeConversation'],
            },
        );
    }
}

function startRename(conversation: ConversationSummary) {
    renamingId.value = conversation.id;
    renameDraft.value = conversation.title;
}

function saveRename(id: number) {
    const title = renameDraft.value.trim();

    renamingId.value = null;

    if (title === '') {
        return;
    }

    router.patch(
        renameConversation(id).url,
        { title },
        { preserveScroll: true, preserveState: true, only: ['conversations'] },
    );
}

function removeConversation(id: number) {
    // Deleting the thread being streamed into would otherwise leave the reply
    // arriving into a bubble that is about to be replaced.
    if (busy.value && conversationId.value === id) {
        cancel();
    }

    router.delete(destroyConversation(id).url, {
        preserveScroll: true,
        preserveState: true,
        only: ['conversations', 'activeConversation'],
        onSuccess: () => {
            if (conversationId.value === id) {
                messages.value = [];
                conversationId.value = null;
            }
        },
    });
}

function removeAllConversations() {
    if (busy.value) {
        cancel();
    }

    router.delete(clearConversations().url, {
        preserveScroll: true,
        preserveState: true,
        only: ['conversations', 'activeConversation'],
        onSuccess: () => {
            messages.value = [];
            conversationId.value = null;
        },
    });
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

    <div class="flex h-[calc(100vh-6rem)] gap-4 p-4">
        <!-- History -->
        <aside
            class="hidden w-64 shrink-0 flex-col rounded-xl border md:flex"
            aria-label="Chat history"
        >
            <div class="flex items-center gap-2 border-b p-3">
                <Button size="sm" class="flex-1" @click="newChat">
                    <Plus class="h-4 w-4" />
                    New chat
                </Button>
                <Button
                    size="sm"
                    :variant="temporary ? 'default' : 'outline'"
                    :aria-pressed="temporary"
                    title="Temporary chat — nothing is saved"
                    @click="toggleTemporary"
                >
                    <Ghost class="h-4 w-4" />
                </Button>
            </div>

            <p
                v-if="temporary"
                class="border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
            >
                Temporary chat. This thread is not saved and won't appear here.
            </p>

            <div class="flex-1 overflow-y-auto p-2">
                <p
                    v-if="!conversations.length"
                    class="px-2 py-6 text-center text-xs text-muted-foreground"
                >
                    No saved chats yet.
                </p>

                <div
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    class="group flex items-center gap-1 rounded-md px-2 py-1.5 text-sm transition-colors"
                    :class="
                        conversation.id === conversationId && !temporary
                            ? 'bg-muted'
                            : 'hover:bg-muted/50'
                    "
                >
                    <template v-if="renamingId === conversation.id">
                        <Input
                            v-model="renameDraft"
                            class="h-7 text-sm"
                            autofocus
                            @keydown.enter="saveRename(conversation.id)"
                            @keydown.esc="renamingId = null"
                        />
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 shrink-0"
                            @click="saveRename(conversation.id)"
                        >
                            <Check class="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 shrink-0"
                            @click="renamingId = null"
                        >
                            <X class="h-3.5 w-3.5" />
                        </Button>
                    </template>

                    <template v-else>
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left"
                            @click="openConversation(conversation.id)"
                        >
                            <span class="block truncate">{{
                                conversation.title
                            }}</span>
                            <span
                                v-if="conversation.last_message_at"
                                class="block truncate text-[11px] text-muted-foreground"
                                >{{ conversation.last_message_at }}</span
                            >
                        </button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                            title="Rename"
                            @click="startRename(conversation)"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                            title="Delete"
                            @click="removeConversation(conversation.id)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </template>
                </div>
            </div>

            <div v-if="conversations.length" class="border-t p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="w-full text-muted-foreground"
                    @click="removeAllConversations"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                    Clear history
                </Button>
            </div>
        </aside>

        <!-- Thread -->
        <section class="flex min-w-0 flex-1 flex-col rounded-xl border">
            <Collapsible class="border-b">
                <div class="flex flex-wrap items-center gap-2 p-3">
                    <StatusDot
                        :active="!!selectedDomain"
                        :pulse="busy"
                        class="ml-1"
                    />

                    <Select v-model="selectedDomainId">
                        <SelectTrigger class="h-8 w-56">
                            <SelectValue placeholder="Select a domain" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="domain in domains"
                                :key="domain.id"
                                :value="domain.id"
                            >
                                {{ domain.host }}
                                <span class="text-muted-foreground">
                                    ({{ domain.response_time_ms ?? '—' }} ms)
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="selectedModel" :disabled="!selectedDomain">
                        <SelectTrigger class="h-8 w-48">
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

                    <span
                        v-if="temporary"
                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs text-muted-foreground"
                    >
                        <Ghost class="h-3 w-3" />
                        Temporary
                    </span>

                    <CollapsibleTrigger
                        class="group ml-auto inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        Options
                        <ChevronDown
                            class="h-4 w-4 transition-transform group-data-[state=open]:rotate-180"
                        />
                    </CollapsibleTrigger>
                </div>

                <CollapsibleContent>
                    <div class="grid gap-3 border-t p-3 sm:grid-cols-2">
                        <div class="space-y-1.5 sm:col-span-2">
                            <Label for="system">System prompt</Label>
                            <textarea
                                id="system"
                                v-model="options.system"
                                rows="2"
                                placeholder="You are a terse assistant."
                                class="w-full resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            />
                        </div>
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
                        <div class="space-y-1.5">
                            <Label for="stop">Stop sequences</Label>
                            <Input
                                id="stop"
                                v-model="options.stop"
                                placeholder="comma, separated"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="keep_alive">Keep model loaded</Label>
                            <Input
                                id="keep_alive"
                                v-model="options.keep_alive"
                                placeholder="e.g. 10m"
                            />
                        </div>
                        <div class="flex items-end">
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="resetOptions"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                Reset options
                            </Button>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <div
                ref="threadEl"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @scroll="onThreadScroll"
            >
                <div
                    v-if="!messages.length"
                    class="flex h-full flex-col items-center justify-center gap-1 text-center text-muted-foreground"
                >
                    <p>Send a prompt to start the conversation.</p>
                    <p v-if="!domains.length" class="text-sm">
                        No active domains yet — visit Domains and refresh.
                    </p>
                </div>

                <ChatMessage
                    v-for="(message, index) in messages"
                    :key="index"
                    :message="message"
                />
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
</template>
