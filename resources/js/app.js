import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import { installBackGuard } from './Composables/backGuard';
import { installNavigationProgress } from './Composables/navigationProgress';

installBackGuard(); // antes que Inertia: ver backGuard.js
installNavigationProgress(); // sustituye a la barra de Inertia (role="bar" invalido): ver navigationProgress.js

const appName = import.meta.env.VITE_APP_NAME || 'Social Scheduler';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: false,
});
