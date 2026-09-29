import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import NavSkeleton from './Components/NavSkeleton.vue';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => [h(App, props), h(NavSkeleton)] }).use(plugin).mount(el);
        // The page is live: remove the placeholder shown while scripts loaded.
        document.getElementById('boot-skeleton')?.remove();
    },
    progress: { color: '#d8ad4f' },
});
