<?php

namespace Tests\Support;

/**
 * The console and network collector of tests/Browser/ClanEditTest.php, for
 * the browser files that measure a page the same way: console.error,
 * uncaught errors, rejected promises, fetch and XHR answers >= 400, and the
 * resource timing entries (document, images, scripts) >= 400. Install
 * COLLECTOR with addInitScript() before the page loads, then read
 * `window.__errors` and evaluate BAD_RESPONSES. Pair every "empty" with a
 * positive control that throws and breaks an image on purpose.
 */
final class BrowserConsole
{
    public const COLLECTOR = <<<'JS'
        window.__errors = [];
        const push = (entry) => window.__errors.push(entry);
        const originalError = console.error;
        console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
        window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
        window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
        const originalFetch = window.fetch;
        window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
        const originalOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url, ...rest) {
            this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
            return originalOpen.call(this, method, url, ...rest);
        };
        JS;

    public const BAD_RESPONSES = <<<'JS'
        () => performance.getEntries()
            .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
            .map((e) => e.responseStatus + ' ' + e.name)
        JS;

    /** [scrollWidth, clientWidth] of the document: overflow when the first is larger. */
    public const WIDTHS = '() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]';
}
