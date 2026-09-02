import { createInertiaApp } from '@inertiajs/vue3';

import Aura from '@primeuix/themes/aura';
import PrimeVue from 'primevue/config';
import 'primeicons/primeicons.css';

import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'IRIS - SAM';

createInertiaApp({
    title: (title) => {
        return title
            ? `${title} - ${appName}`
            : appName;
    },

    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;

            case name.startsWith('auth/'):
                return AuthLayout;

            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];

            default:
                return AppLayout;
        }
    },

    /**
     * Register application plugins for client rendering and SSR.
     */
    withApp(app) {
        app.use(PrimeVue, {
            theme: {
                preset: Aura,

                options: {
                    /**
                     * PrimeVue will use dark mode only when the
                     * Laravel starter kit adds the .dark class.
                     */
                    darkModeSelector: '.dark',
                },
            },
        });
    },

    progress: {
        color: '#377EC0',
    },
});

/**
 * Browser-only initializers.
 *
 * These must not execute during Node SSR.
 */
if (typeof window !== 'undefined') {
    initializeTheme();
    initializeFlashToast();
}