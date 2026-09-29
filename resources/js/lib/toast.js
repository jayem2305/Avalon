import { reactive } from 'vue';

export const toasts = reactive([]);
let nextId = 1;

export function toast(message, isError = false) {
    const id = nextId++;
    toasts.push({ id, message, isError });
    setTimeout(() => {
        const i = toasts.findIndex((t) => t.id === id);
        if (i !== -1) toasts.splice(i, 1);
    }, 4000);
}
