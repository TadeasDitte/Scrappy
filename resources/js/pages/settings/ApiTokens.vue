<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { onMounted, onUnmounted } from 'vue';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as apiTokens } from '@/routes/api-tokens';
import { useApiTokensStore } from '@/stores/apiTokens';
import type { ApiToken, PlainToken } from '@/stores/apiTokens';

defineProps<{
    tokens: ApiToken[];
    availableAbilities: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API Tokens', href: apiTokens().url }],
    },
});

const store = useApiTokensStore();

let stopListening: (() => void) | null = null;

onMounted(() => {
    // Capture the one-time plaintext token flashed after creation.
    stopListening = router.on('flash', (event) => {
        const token = (event as CustomEvent).detail?.flash?.token as
            PlainToken | undefined;

        if (token) {
            store.reveal(token);
        }
    });
});

onUnmounted(() => stopListening?.());
</script>

<template>
    <Head title="API Tokens" />

    <h1 class="sr-only">API Tokens</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="API Tokens"
            description="Generate personal access tokens to use the Scrappy API."
        />

        <!-- Freshly created token (shown once) -->
        <div
            v-if="store.justCreated"
            class="space-y-3 rounded-lg border border-green-500/40 bg-green-500/5 p-4"
        >
            <p class="text-sm font-medium">
                Copy your new token “{{ store.justCreated.name }}” now — you
                won’t be able to see it again.
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-xs"
                >
                    {{ store.justCreated.plainText }}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="store.copyToClipboard()"
                >
                    <Check v-if="store.copied" class="h-4 w-4" />
                    <Copy v-else class="h-4 w-4" />
                    {{ store.copied ? 'Copied' : 'Copy' }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="store.dismiss()"
                >
                    Done
                </Button>
            </div>
        </div>

        <!-- Create form -->
        <Form
            v-bind="ApiTokenController.store.form()"
            :reset-on-success="['name']"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Token name</Label>
                <Input
                    id="name"
                    name="name"
                    placeholder="e.g. CLI on my laptop"
                    required
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label>Abilities</Label>
                <label
                    v-for="ability in availableAbilities"
                    :key="ability"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name="abilities[]"
                        :value="ability"
                        checked
                        class="h-4 w-4 rounded border-input"
                    />
                    <code class="font-mono text-xs">{{ ability }}</code>
                </label>
            </div>

            <Button type="submit" :disabled="processing">
                <KeyRound class="h-4 w-4" />
                Create token
            </Button>
        </Form>

        <!-- Existing tokens -->
        <div class="space-y-3">
            <h3 class="text-sm font-medium">Active tokens</h3>

            <p v-if="!tokens.length" class="text-sm text-muted-foreground">
                You haven’t created any tokens yet.
            </p>

            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between gap-4 rounded-lg border p-4"
            >
                <div class="min-w-0 space-y-1">
                    <p class="truncate text-sm font-medium">{{ token.name }}</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="ability in token.abilities"
                            :key="ability"
                            variant="secondary"
                        >
                            {{ ability }}
                        </Badge>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Created {{ token.created_at }} · Last used
                        {{ token.last_used_at ?? 'never' }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Revoke token"
                    @click="
                        router.delete(
                            ApiTokenController.destroy.url({
                                tokenId: token.id,
                            }),
                            { preserveScroll: true },
                        )
                    "
                >
                    <Trash2 class="h-4 w-4 text-destructive" />
                </Button>
            </div>
        </div>
    </div>
</template>
