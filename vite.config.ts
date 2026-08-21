import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        server: {
            // 0.0.0.0 so the dev server is reachable from outside its container;
            // `origin` so the asset URLs it writes into public/hot point at the
            // host, which is where the browser actually is.
            host: '0.0.0.0',
            port: 5173,
            origin: 'http://localhost:5173',
            // laravel-vite-plugin derives its default `cors.origin` from
            // APP_URL, but only when `server.origin` above is unset — since
            // we set it, we must allow the app's own origin explicitly, or
            // the browser (serving the page from APP_URL) gets blocked
            // loading assets from the Vite origin.
            cors: {
                origin: [env.APP_URL, 'http://localhost:5173'].filter(Boolean),
            },
            hmr: { host: 'localhost' },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: true,
            }),
            inertia(),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            wayfinder({
                formVariants: true,
            }),
        ],
    };
});
