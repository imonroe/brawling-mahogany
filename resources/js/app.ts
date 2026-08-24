import { createInertiaApp } from '@inertiajs/vue3';
import * as Sentry from '@sentry/vue';
import { createApp, h } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import DealLayout from '@/layouts/DealLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Brawling Mahogany';

/**
 * The pages that are *inside* one deal (IA §5.2), and so wear `DealLayout`.
 *
 * A list rather than a prefix test: `Deals/Index` and `Deals/Create` live
 * under the same folder and are not inside a deal. Adding S16–S22 means adding
 * a line here and a tab in `DealHeader`.
 */
const DEAL_TAB_PAGES = [
    'Deals/Overview',
    'Deals/Timeline',
    'Deals/Tasks',
    'Deals/People',
    'Deals/Properties',
];

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
                integrations: [
                    // `attachProps` defaults to true, which would ship every
                    // failing component's props — a DealRow's props are the
                    // client's name, the address, and the next key date.
                    Sentry.vueIntegration({ attachProps: false }),
                ],
                beforeBreadcrumb: (breadcrumb) => {
                    // A typed value is a client's address as often as not, and
                    // a console line is usually an interpolated record.
                    if (
                        breadcrumb.category === 'ui.input' ||
                        breadcrumb.category === 'console'
                    ) {
                        return null;
                    }

                    return breadcrumb;
                },
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
            /*
             * Inside a deal is a context, not a page (IA §5.2), so every deal
             * tab wears the §8.4 header and the tab row.
             *
             * Matched on the sub-pages by name rather than on the `Deals/`
             * prefix, because `Deals/Index` is the *list* of deals and
             * `Deals/Create` is the wizard — neither is inside a deal, and
             * neither has a `dealHeader` to render one from.
             */
            case DEAL_TAB_PAGES.includes(name):
                return [AppLayout, DealLayout];
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
