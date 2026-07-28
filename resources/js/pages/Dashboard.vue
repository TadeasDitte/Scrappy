<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Gauge,
    Layers,
    MessagesSquare,
    Server,
    Zap,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as chatIndex } from '@/routes/chat';
import { index as domainsIndex } from '@/routes/domains';

interface FastestDomain {
    id: number;
    host: string;
    response_time_ms: number | null;
    model_count: number;
}

defineProps<{
    stats: {
        total: number;
        active: number;
        models: number;
        lastScrape: string | null;
    };
    fastest: FastestDomain[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard().url }],
    },
});

const statCards = [
    { key: 'active', label: 'Active domains', icon: Gauge },
    { key: 'total', label: 'Known domains', icon: Server },
    { key: 'models', label: 'Models available', icon: Layers },
] as const;
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Overview"
                description="Live Ollama endpoints discovered, probed, and ready to chat with."
            />
            <Button as-child>
                <Link :href="chatIndex()">
                    <MessagesSquare class="h-4 w-4" />
                    Start chatting
                </Link>
            </Button>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div
                v-for="card in statCards"
                :key="card.key"
                class="relative overflow-hidden rounded-xl border bg-card p-5"
            >
                <div
                    class="absolute -top-6 -right-6 h-20 w-20 rounded-full bg-primary/10 blur-xl"
                />
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">{{
                        card.label
                    }}</span>
                    <component :is="card.icon" class="h-4 w-4 text-primary" />
                </div>
                <p class="mt-3 text-3xl font-semibold tracking-tight">
                    {{ stats[card.key].toLocaleString() }}
                </p>
            </div>
        </div>

        <!-- Fastest domains + CTA -->
        <div class="grid flex-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border bg-card lg:col-span-2">
                <div
                    class="flex items-center justify-between border-b px-5 py-4"
                >
                    <div class="flex items-center gap-2">
                        <Zap class="h-4 w-4 text-primary" />
                        <h3 class="text-sm font-medium">Fastest endpoints</h3>
                    </div>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="domainsIndex()">
                            View all
                            <ArrowRight class="h-4 w-4" />
                        </Link>
                    </Button>
                </div>

                <ul v-if="fastest.length" class="divide-y">
                    <li
                        v-for="(domain, i) in fastest"
                        :key="domain.id"
                        class="flex items-center gap-4 px-5 py-3"
                    >
                        <span
                            class="w-5 text-sm font-semibold text-muted-foreground"
                            >{{ i + 1 }}</span
                        >
                        <StatusDot pulse />
                        <span class="flex-1 truncate font-medium">{{
                            domain.host
                        }}</span>
                        <span class="text-sm text-muted-foreground"
                            >{{ domain.model_count }} models</span
                        >
                        <span
                            class="rounded-md bg-primary/15 px-2 py-0.5 text-sm font-medium text-primary"
                        >
                            {{ domain.response_time_ms }} ms
                        </span>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <Server class="h-8 w-8 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        No active domains yet. Run a scrape to populate the
                        list.
                    </p>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="domainsIndex()">Go to Domains</Link>
                    </Button>
                </div>
            </div>

            <Link
                :href="chatIndex()"
                class="group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-gradient-to-br from-primary/20 via-card to-card p-6 transition-colors hover:border-primary/50"
            >
                <MessagesSquare class="h-8 w-8 text-primary" />
                <div class="space-y-1">
                    <h3 class="text-lg font-semibold">Open the chat</h3>
                    <p class="text-sm text-muted-foreground">
                        Pick a domain and model, then stream a completion in
                        real time.
                    </p>
                    <span
                        class="inline-flex items-center gap-1 pt-2 text-sm font-medium text-primary"
                    >
                        Launch
                        <ArrowRight
                            class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                        />
                    </span>
                </div>
            </Link>
        </div>
    </div>
</template>
