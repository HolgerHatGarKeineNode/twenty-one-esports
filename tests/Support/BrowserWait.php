<?php

namespace Tests\Support;

use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use RuntimeException;
use Throwable;

/**
 * Page::waitForFunction() does not actually wait: measured against this
 * plugin version, it returns in under a millisecond regardless of whether
 * the condition is true (tests/Browser/_SmokeTest.php reproduced this
 * before it was deleted — an 800ms-delayed flag flip resolved as "already
 * true"). This polls the same way `assertX()` helpers on the visit()-
 * returned proxy do internally (Pest\Browser\Execution::waitForExpectation),
 * but works on the raw Page too, which this app's browser tests use for
 * addInitScript()/context() access that the proxy does not expose.
 */
final class BrowserWait
{
    public static function until(Page $page, string $jsCondition, int $timeoutMs = 5000, int $intervalMs = 25): void
    {
        $deadline = microtime(true) + $timeoutMs / 1000;

        do {
            try {
                if ($page->evaluate($jsCondition) === true) {
                    return;
                }
            } catch (Throwable) {
                // A real navigation (window.location.assign, a form submit)
                // tears down the execution context mid-poll — that is the
                // condition resolving, not a failure. Retry.
            }

            // The app under test runs in this process: usleep() froze it, so a
            // request the page made waited for the next evaluate(). The plugin's
            // own wait keeps its event loop, and with it the server, running.
            Execution::instance()->wait($intervalMs / 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Condition did not become true within {$timeoutMs}ms: {$jsCondition}".self::consoleErrors($page));
    }

    /**
     * window.__errors from Tests\Support\BrowserConsole::COLLECTOR, when that
     * collector was installed on this page (tests/Integration's own
     * integrationPage(); most tests/Browser files install it too, some do
     * not) — feature-detected so a page without it still throws the plain
     * message above instead of a second, unrelated error.
     */
    private static function consoleErrors(Page $page): string
    {
        try {
            $errors = $page->evaluate('() => (typeof window.__errors !== "undefined" ? window.__errors : null)');
        } catch (Throwable) {
            return '';
        }

        if (! is_array($errors)) {
            return '';
        }

        if ($errors === []) {
            return ' (console/network collector installed, no errors captured)';
        }

        return ' — console/network errors captured on this page: '.json_encode($errors);
    }
}
