import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { tooltip } from './lib/tooltip';
import Icon from './Components/Icon.vue';

createInertiaApp({
    title: (title) => (title ? `${title} · StreamNet` : 'StreamNet'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).component('Icon', Icon).directive('tip', tooltip).mount(el);
    },
    progress: {
        color: '#3ee0a2',
    },
});
