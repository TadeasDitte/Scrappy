<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const props = defineProps<{
    stats: { active: number; models: number };
}>();

const capabilities = [
    {
        term: 'Discovery',
        detail: 'Public Ollama hosts are scraped on a schedule.',
    },
    {
        term: 'Health checks',
        detail: 'Every host is probed on /api/tags; dead ones drop off the list.',
    },
    {
        term: 'Ranking',
        detail: 'Sorted by response time or by how many models they serve.',
    },
    {
        term: 'Chat',
        detail: 'Pick a host and model, then stream a completion in the browser.',
    },
    {
        term: 'API',
        detail: 'Scoped tokens, documented at /api/v1, with the same streaming.',
    },
];

const liveLabel = props.stats.active === 1 ? 'endpoint live' : 'endpoints live';
</script>

<template>
    <Head title="Scrappy — Ollama endpoint aggregator" />

    <div class="min-h-screen bg-background text-foreground">
        <div class="mx-auto flex min-h-screen max-w-2xl flex-col px-6">
            <header class="flex items-center justify-between py-8">
                <span class="text-sm font-medium tracking-tight">Scrappy</span>

                <nav class="flex items-center gap-1">
                    <Button
                        v-if="$page.props.auth.user"
                        variant="ghost"
                        as-child
                    >
                        <Link :href="dashboard()">Dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button variant="ghost" as-child>
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button variant="ghost" as-child>
                            <Link :href="register()">Sign up</Link>
                        </Button>
                    </template>
                </nav>
            </header>

            <main class="flex flex-1 flex-col justify-center py-16">
                <h1 class="text-3xl font-medium tracking-tight text-balance">
                    A directory of public Ollama endpoints.
                </h1>

                <p class="mt-4 max-w-lg text-muted-foreground">
                    Scrappy finds them, checks which are actually answering,
                    ranks them by speed, and gives you a chat window and an API
                    to use them.
                </p>

                <p
                    class="mt-6 flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <StatusDot :active="stats.active > 0" pulse />
                    <span class="text-foreground">{{ stats.active }}</span>
                    {{ liveLabel }}
                    <span aria-hidden="true">·</span>
                    <span class="text-foreground">{{ stats.models }}</span>
                    models
                </p>

                <div class="mt-8">
                    <Button as-child>
                        <Link
                            :href="
                                $page.props.auth.user ? dashboard() : register()
                            "
                        >
                            {{
                                $page.props.auth.user
                                    ? 'Open dashboard'
                                    : 'Get started'
                            }}
                        </Link>
                    </Button>
                </div>

                <dl class="mt-20 text-sm">
                    <div
                        v-for="capability in capabilities"
                        :key="capability.term"
                        class="grid grid-cols-1 gap-1 border-t border-border py-4 sm:grid-cols-[8rem_1fr] sm:gap-6"
                    >
                        <dt class="font-medium">{{ capability.term }}</dt>
                        <dd class="text-muted-foreground">
                            {{ capability.detail }}
                        </dd>
                    </div>
                </dl>
            </main>

            <footer
                class="border-t border-border py-6 text-sm text-muted-foreground"
            >
                Aggregates public Ollama endpoints for authorized use.
            </footer>
        </div>
    </div>
</template>
