import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import axios from 'axios';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const pages = import.meta.glob('./Pages/*.vue', { eager: true });
createInertiaApp({
    title: (title) => `${title} · Pixelforge`,
    resolve: (name) => pages[`./Pages/${name}.vue`],
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: '#a8bd8c' },
});
