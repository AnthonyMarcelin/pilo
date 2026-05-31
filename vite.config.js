import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            registerType: 'autoUpdate',
            injectRegister: 'auto',
            manifest: {
                name: 'Pilo',
                short_name: 'Pilo',
                description: 'Suivi de traitement médicamenteux',
                theme_color: '#f8fafc',
                background_color: '#ffffff',
                display: 'standalone',
                orientation: 'portrait',
                start_url: '/today',
                id: '/today',
                scope: '/',
                icons: [
                    { src: '/icons/pwa-192x192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
                    { src: '/icons/pwa-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
                    { src: '/icons/pwa-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            workbox: {
                // Précache les assets statiques générés par Vite (JS, CSS, images, fonts)
                globPatterns: ['**/*.{js,css,ico,png,svg,woff2}'],
                navigateFallback: null,
                runtimeCaching: [
                    {
                        // Consultation hors-ligne : pages HTML (navigations directes)
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pilo-pages',
                            networkTimeoutSeconds: 5,
                            expiration: { maxEntries: 10, maxAgeSeconds: 86400 },
                            cacheableResponse: { statuses: [200] },
                        },
                    },
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'pilo-fonts',
                            expiration: { maxEntries: 10, maxAgeSeconds: 31536000 },
                            cacheableResponse: { statuses: [0, 200] },
                        },
                    },
                ],
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
});
