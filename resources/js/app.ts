import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
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
        // The progress bar sits outside the token layer — Inertia takes a
        // literal colour. This is --primary from Design System §2.2.
        color: '#1A588F',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
