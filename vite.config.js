import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/echo.js',
                'resources/js/chess.js',
            ],
            refresh: true,
            fonts: [
                google('Unbounded', {
                    weights: [500, 700, 800],
                    variable: '--font-face-display',
                    optimizedFallbacks: false,
                    subsets: ['latin', 'latin-ext'],
                }),
                google('JetBrains Mono', {
                    weights: [400, 500, 700],
                    variable: '--font-face-mono',
                    optimizedFallbacks: false,
                    subsets: ['latin', 'latin-ext'],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
