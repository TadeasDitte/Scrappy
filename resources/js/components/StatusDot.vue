<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    active?: boolean;
    pulse?: boolean;
    label?: string;
};

const props = withDefaults(defineProps<Props>(), {
    active: true,
    pulse: false,
    label: undefined,
});

const title = computed(
    () => props.label ?? (props.active ? 'Active' : 'Offline'),
);
</script>

<template>
    <span
        class="relative flex size-2 shrink-0"
        role="img"
        :aria-label="title"
        :title="title"
    >
        <span
            v-if="active && pulse"
            class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"
        />
        <span
            class="relative inline-flex size-2 rounded-full"
            :class="active ? 'bg-success' : 'bg-muted-foreground/40'"
        />
    </span>
</template>
