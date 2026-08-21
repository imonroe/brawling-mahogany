import { createInertiaApp } from '@inertiajs/vue3';
import * as Sentry from '@sentry/vue';
import { createApp, h } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Brawling Mahogany';

createInertiaApp({
    /**
     * Client-side error reporting.
     *
     * PRD §9 applies here as much as on the server: no PII leaves the
     * browser either. `sendDefaultPii` stays off, and request bodies and
     * input values are never captured — this product's forms hold client
     * addresses and transaction values.
     */
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) }).use(plugin);

        const dsn = import.meta.env.VITE_SENTRY_DSN;

        if (dsn) {
            Sentry.init({
                app,
                dsn,
                sendDefaultPii: false,
                environment: import.meta.env.MODE,
                beforeBreadcrumb: (breadcrumb) =>
                    // A typed value is a client's address as often as not.
                    breadcrumb.category === 'ui.input' ? null : breadcrumb,
            });
        }

        if (el) {
            app.mount(el);
        }
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Page components mirror routes in PascalCase (IA §6).
            case name === 'Welcome':
                return null;
            // System pages carry their own frame: they render for signed-out
            // people, and the client-facing 403/404 must not show app chrome.
            case name.startsWith('System/'):
                return null;
            case name.startsWith('Status/'):
                return null;
            case name.startsWith('Auth/'):
                return AuthLayout;
            case name.startsWith('Admin/'):
                return AdminLayout;
            case name.startsWith('Settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        // Inertia takes a literal colour rather than a class, so the token is
        // read from the stylesheet instead of being duplicated here. No
        // component, and nothing else, carries a raw colour value.
        color: getComputedStyle(document.documentElement)
            .getPropertyValue('--primary')
            .trim(),
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
