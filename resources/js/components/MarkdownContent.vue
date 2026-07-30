<script setup lang="ts">
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

const props = defineProps<{
    source: string;
    /** Whether tokens are still arriving, which shows the caret. */
    live?: boolean;
}>();

/**
 * Safe to hand to `v-html`: the renderer escapes everything before it writes a
 * single tag, so the only markup here is markup it produced itself.
 */
const html = computed(() => {
    const rendered = renderMarkdown(props.source);

    // The caret hangs off the last block, so a reply with nothing in it yet
    // still needs one block to hang it from.
    return rendered === '' && props.live ? '<p></p>' : rendered;
});
</script>

<template>
    <div class="markdown" :class="{ 'is-live': live }" v-html="html" />
</template>

<!--
    Not scoped: these rules style tags produced at runtime, which carry no scope
    attribute. Everything is namespaced under `.markdown` instead.
-->
<style>
.markdown > * {
    margin: 0;
}

.markdown > * + * {
    margin-top: 0.7em;
}

.markdown h1,
.markdown h2,
.markdown h3,
.markdown h4,
.markdown h5,
.markdown h6 {
    font-weight: 600;
    line-height: 1.3;
}

.markdown h1 {
    font-size: 1.3em;
}

.markdown h2 {
    font-size: 1.2em;
}

.markdown h3 {
    font-size: 1.1em;
}

.markdown h4,
.markdown h5,
.markdown h6 {
    font-size: 1em;
}

.markdown a {
    text-decoration: underline;
    text-underline-offset: 2px;
}

.markdown strong {
    font-weight: 600;
}

.markdown ul,
.markdown ol {
    padding-left: 1.35em;
}

.markdown ul {
    list-style: disc;
}

.markdown ol {
    list-style: decimal;
}

.markdown li + li {
    margin-top: 0.2em;
}

/* A nested list belongs to the line above it, not a paragraph of its own. */
.markdown li > ul,
.markdown li > ol {
    margin-top: 0.2em;
}

.markdown code {
    border-radius: calc(var(--radius) - 4px);
    background: color-mix(in oklab, currentColor 10%, transparent);
    padding: 0.1em 0.35em;
    font-family: ui-monospace, monospace;
    font-size: 0.9em;
}

.markdown pre {
    position: relative;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: calc(var(--radius) - 2px);
    background: var(--background);
    padding: 0.7em 0.8em;
}

.markdown pre[data-language] {
    padding-top: 1.6em;
}

.markdown pre[data-language]::before {
    content: attr(data-language);
    position: absolute;
    top: 0.35em;
    left: 0.8em;
    color: var(--muted-foreground);
    font-size: 0.7em;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

/* Inside a block the wrapper already provides the frame. */
.markdown pre code {
    display: block;
    border-radius: 0;
    background: none;
    padding: 0;
    line-height: 1.5;
    white-space: pre;
}

.markdown blockquote {
    border-left: 2px solid var(--border);
    padding-left: 0.8em;
    color: var(--muted-foreground);
}

.markdown hr {
    border: 0;
    border-top: 1px solid var(--border);
}

/* A wide table scrolls inside the message instead of stretching it. */
.markdown .md-scroll {
    overflow-x: auto;
}

.markdown table {
    border-collapse: collapse;
    font-size: 0.95em;
}

.markdown th,
.markdown td {
    border: 1px solid var(--border);
    padding: 0.3em 0.6em;
    text-align: left;
}

.markdown th {
    font-weight: 600;
}

/*
    While tokens are arriving the caret sits at the end of the last block, in
    line with the text, rather than on a line of its own underneath it.
*/
.markdown.is-live > *:last-child::after {
    content: '';
    display: inline-block;
    width: 0.5em;
    height: 1em;
    margin-left: 0.15em;
    background: currentColor;
    vertical-align: text-bottom;
    animation: markdown-caret 1.4s ease-in-out infinite;
}

@keyframes markdown-caret {
    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0.2;
    }
}
</style>
