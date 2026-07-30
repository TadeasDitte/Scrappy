<script setup lang="ts">
import type { ChatCommand } from '@/lib/chatCommands';
import { commandLabel } from '@/lib/chatCommands';

defineProps<{
    commands: ChatCommand[];
    active: number;
}>();

const emit = defineEmits<{
    select: [command: ChatCommand];
    activate: [index: number];
}>();
</script>

<template>
    <div
        class="mb-2 overflow-hidden rounded-lg border bg-popover shadow-md"
        role="listbox"
        aria-label="Chat commands"
    >
        <button
            v-for="(command, index) in commands"
            :key="command.name"
            type="button"
            role="option"
            :aria-selected="index === active"
            class="flex w-full items-baseline gap-2 px-3 py-1.5 text-left"
            :class="
                index === active
                    ? 'bg-accent text-accent-foreground'
                    : 'hover:bg-muted/60'
            "
            @mouseenter="emit('activate', index)"
            @mousedown.prevent="emit('select', command)"
        >
            <span class="font-mono text-sm">{{ commandLabel(command) }}</span>
            <span class="truncate text-xs text-muted-foreground">{{
                command.description
            }}</span>
        </button>
    </div>
</template>
