import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/tv.css',
                'resources/js/app.js',
                'resources/js/echo.js',
                'resources/js/chess.js',
                'resources/js/push.js',
                'resources/js/matchRoom.js',
                'resources/js/liveChat.js',
            ],
            refresh: true,
            fonts: [
                // Metric-matched fallbacks (fontaine) hold the layout still while the
                // webfont loads, instead of shifting when it swaps in (font-display: swap).
                google('Unbounded', {
                    weights: [500, 700, 800],
                    variable: '--font-face-display',
                    subsets: ['latin', 'latin-ext'],
                }),
                google('JetBrains Mono', {
                    weights: [400, 500, 700],
                    variable: '--font-face-mono',
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
