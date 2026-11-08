import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/errors.css', 'resources/css/kit.css', 'resources/js/app.js', 'resources/js/ds.js'],
            refresh: true,
        }),
    ],
});
