import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            // SolaStock SPA entry + the ported design-system CSS. Built assets are
            // referenced via @viteReactRefresh + @vite in the solastock Blade shell.
            input: [
                'resources/js/solastock/app.jsx',
                'resources/js/solastock/styles/solastock.css',
            ],
            refresh: true,
        }),
        react(),
    ],
    // Explicit input so the build entry is defined regardless of the Laravel
    // plugin's input handling across vite versions.
    build: {
        rollupOptions: {
            input: [
                'resources/js/solastock/app.jsx',
                'resources/js/solastock/styles/solastock.css',
            ],
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
