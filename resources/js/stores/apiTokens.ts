import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface ApiToken {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
}

export interface PlainToken {
    name: string;
    plainText: string;
}

/**
 * Client-side state for API token management. The plaintext value of a freshly
 * created token is only available once, so we hold it here until dismissed.
 */
export const useApiTokensStore = defineStore('apiTokens', () => {
    const justCreated = ref<PlainToken | null>(null);
    const copied = ref(false);

    function reveal(token: PlainToken) {
        justCreated.value = token;
        copied.value = false;
    }

    function dismiss() {
        justCreated.value = null;
        copied.value = false;
    }

    async function copyToClipboard() {
        if (!justCreated.value) {
            return;
        }

        await navigator.clipboard.writeText(justCreated.value.plainText);
        copied.value = true;
    }

    return { justCreated, copied, reveal, dismiss, copyToClipboard };
});
