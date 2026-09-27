<?php

namespace Tests\Support;

use Pest\Browser\Playwright\Page;

/**
 * The chess lobby's quick-play panel for browser tests (lobby v2): "Find
 * opponent", Casual/Rated and the strength range sit in the panel the
 * Blitz tile opens, and the reasons behind its "?".
 */
final class ChessLobby
{
    /** Opens the blitz panel (a click on the Blitz tile) unless it is open already. */
    public static function openBlitz(Page $page): void
    {
        BrowserWait::until($page, '() => window.Alpine !== undefined && document.querySelector("[data-test=play-blitz]") !== null', 10_000);

        if ($page->evaluate('() => document.querySelector("[data-test=play-blitz]").getAttribute("aria-expanded")') !== 'true') {
            $page->locator('[data-test=play-blitz]')->click();
        }

        // A player's "Find opponent", or a guest's way to log in.
        BrowserWait::until($page, '() => document.querySelector("[data-test=find-opponent-button], [data-test=find-opponent-login]")?.checkVisibility() === true', 5_000);
    }

    /** Opens the panel and its "?", so the reasons are rendered text. */
    public static function openHelp(Page $page): void
    {
        self::openBlitz($page);

        if ($page->evaluate('() => document.querySelector("[data-test=kind-help]").getAttribute("aria-expanded")') !== 'true') {
            $page->locator('[data-test=kind-help]')->click();
        }

        BrowserWait::until($page, '() => document.querySelector("[data-test=kind-why]")?.checkVisibility() === true', 5_000);
    }
}
