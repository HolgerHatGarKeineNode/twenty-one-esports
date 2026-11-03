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

    /**
     * The squeeze detector (user 2026-10-25, the chess tab: a ~200 px column broke every line into single words), the
     * one of tests/Browser/ProfileEditorTest.php for any scope: `evaluate(SQUEEZED, [$selector])` lists the visible text
     * of more than 25 characters inside a box narrower than 120 px that is allowed to wrap, within the elements the
     * selector matches. Empty is what it should be.
     */
    public const SQUEEZED = <<<'JS'
        ([scope]) => [...document.querySelectorAll(scope)].flatMap((root) => [...root.querySelectorAll('p, span, dt, dd, label, h2, h3, li, b, a')])
            .filter((el) => { const r = el.getBoundingClientRect(), own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ');
                return r.width > 0 && r.height > 0 && r.width < 120 && own.length > 25 && getComputedStyle(el).whiteSpace !== 'nowrap'; })
            .map((el) => Math.round(el.getBoundingClientRect().width) + 'px: ' + el.textContent.trim().slice(0, 40))
        JS;

    /**
     * Words that spill past their cell: `evaluate(SPILLED, [$selector])` lists every line of text, within the elements
     * the selector matches, that sticks out of the nearest block it stands in, unless that block cuts on purpose
     * (`overflow` clipped with an ellipsis).
     */
    public const SPILLED = <<<'JS'
        ([scope]) => [...document.querySelectorAll(scope)].flatMap((root) => [root, ...root.querySelectorAll('*')]).filter((el) => el.checkVisibility()).flatMap((el) => {
            const cell = el.getBoundingClientRect(), style = getComputedStyle(el), out = [];
            if (style.display === 'inline' || (style.overflowX !== 'visible' && style.textOverflow === 'ellipsis')) return out;
            for (const node of el.childNodes) {
                if (node.nodeType !== 3 || node.textContent.trim() === '') continue;
                const range = document.createRange();
                range.selectNodeContents(node);
                for (const r of range.getClientRects()) {
                    if (r.width > 0 && (r.right > cell.right + 1 || r.left < cell.left - 1)) out.push(Math.round(r.right - cell.right) + 'px: ' + node.textContent.trim().slice(0, 40));
                }
            }
            return out;
        })
        JS;

    /** [scrollWidth, clientWidth] of the document: overflow when the first is larger. */
    public const WIDTHS = '() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]';
}
