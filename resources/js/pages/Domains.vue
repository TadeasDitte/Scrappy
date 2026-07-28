<script setup lang="ts">
import { Deferred, Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, Gauge, Layers, RefreshCw, Server } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { index as chatIndex } from '@/routes/chat';
import { index as domainsIndex, refresh } from '@/routes/domains';

interface DomainRow {
    id: number;
    host: string;
    response_time_ms: number | null;
    model_count: number;
    last_active_at: string | null;
}

const props = defineProps<{
    sort: 'speed' | 'models';
    stats: { total: number; active: number; models: number };
    domains?: DomainRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Domains', href: domainsIndex().url }],
    },
});

const refreshForm = useForm({});

function setSort(sort: 'speed' | 'models') {
    if (sort === props.sort) {
        return;
    }

    router.get(
        domainsIndex({ query: { sort } }).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function triggerRefresh() {
    refreshForm.post(refresh().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Domains" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Ollama domains"
                description="Live endpoints discovered from netint, ranked and health-checked."
            />
            <Button
                variant="outline"
                :disabled="refreshForm.processing"
                @click="triggerRefresh"
            >
                <RefreshCw
                    class="h-4 w-4"
                    :class="{ 'animate-spin': refreshForm.processing }"
                />
                Refresh now
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader
                    class="flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Known domains</CardTitle
                    >
                    <Server class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-bold">{{ stats.total }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    class="flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Active now</CardTitle
                    >
                    <Gauge class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-bold">{{ stats.active }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    class="flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Models available</CardTitle
                    >
                    <Layers class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-bold">{{ stats.models }}</p>
                </CardContent>
            </Card>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-sm text-muted-foreground">Rank by</span>
            <Button
                :variant="sort === 'speed' ? 'default' : 'outline'"
                size="sm"
                @click="setSort('speed')"
            >
                <Gauge class="h-4 w-4" />
                Response time
            </Button>
            <Button
                :variant="sort === 'models' ? 'default' : 'outline'"
                size="sm"
                @click="setSort('models')"
            >
                <Layers class="h-4 w-4" />
                Model count
            </Button>
        </div>

        <Deferred :data="'domains'">
            <template #fallback>
                <div class="space-y-2">
                    <Skeleton
                        v-for="n in 8"
                        :key="n"
                        class="h-14 w-full animate-pulse rounded-lg"
                    />
                </div>
            </template>

            <div
                v-if="domains && domains.length"
                class="overflow-hidden rounded-xl border"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Domain</th>
                            <th class="px-4 py-3 font-medium">Response</th>
                            <th class="px-4 py-3 font-medium">Models</th>
                            <th class="px-4 py-3 font-medium">Last active</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="domain in domains"
                            :key="domain.id"
                            class="group transition-colors hover:bg-muted/40"
                        >
                            <td class="px-4 py-3 font-medium">
                                <span class="flex items-center gap-2.5">
                                    <StatusDot pulse />
                                    {{ domain.host }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="domain.response_time_ms !== null"
                                    class="inline-block rounded-md bg-primary/15 px-2 py-0.5 font-medium text-primary"
                                >
                                    {{ domain.response_time_ms }} ms
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3">{{ domain.model_count }}</td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ domain.last_active_at ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="opacity-70 group-hover:opacity-100"
                                    as-child
                                >
                                    <a
                                        :href="
                                            chatIndex({
                                                query: { domain: domain.id },
                                            }).url
                                        "
                                    >
                                        Open in chat
                                        <ArrowRight class="h-3.5 w-3.5" />
                                    </a>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="rounded-xl border border-dashed p-10 text-center text-muted-foreground"
            >
                No active domains yet. Hit “Refresh now” to scrape and probe.
            </div>
        </Deferred>
    </div>
</template>
