<?php

namespace App\Support\Navigation;

use App\Support\RequestMemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Throwable;

/**
 * Which links switch pages with wire:navigate (performance plan P6b): the shell's pages swap their body
 * in the kept window, every other page loads in full, in both directions.
 *
 * A page is navigable only when everything it starts is torn down again by a swap: its Alpine components
 * remove their window and document listeners, intervals and Echo listeners in destroy(), and it joins no
 * presence channel. The game, board, room, lobby and chat pages do not (chess.js joins
 * `game.<id>.players` and never leaves it; the room keeps an inline interval), so they stay full loads.
 * tests/Feature/NavigateLinksTest.php renders every page listed here and fails on a component that is not
 * known to be navigate-safe; tests/Browser/NavigateSpikeTest.php walks them and counts what stays alive.
 *
 * A link gets `wire:navigate` (Blade: `@navigate($href)`) only when the switch is on, the page it sits on is
 * navigable and so is its target. The layout marks the navigable pages with `data-navigate-page` on <html>;
 * resources/js/navigateGuard.js turns any swap into or out of a page without the mark into a full load (the
 * back button and a redirect included), so a page outside this list fails closed.
 */
final class Navigate
{
    /**
     * Route names of the navigable pages: home, /play, the lists of the header and the tab bar, and the
     * player's own pages and settings tabs.
     *
     * @var list<string>
     */
    public const PAGES = [
        'home',
        'play',
        'matches.index',
        'tournaments.index',
        'clans.index',
        'games.index',
        'mining',
        'ladder.show',
        'ladder.strongest',
        'rules',
        'protocol',
        'dashboard',
        'me.correspondence',
        'settings.account',
        'gaming.edit',
        'settings.notifications',
        'settings.chess',
        'settings.badges',
        'settings.opponents',
        'settings.nip05',
    ];

    public static function enabled(): bool
    {
        return (bool) config('esports.navigate');
    }

    /** The `wire:navigate` attribute for a link to $href, or nothing. */
    public static function attribute(string $href): string
    {
        return self::link($href) ? 'wire:navigate' : '';
    }

    /** Whether a link to $href on the current page switches with wire:navigate. */
    public static function link(string $href): bool
    {
        return self::enabled() && self::here() && self::isNavigable($href);
    }

    /**
     * Whether the page this request renders (on a Livewire update: the page its component sits on) is one
     * of the navigable pages. False outside a routed request.
     */
    public static function here(): bool
    {
        return RequestMemo::remember('navigate.here', function (): bool {
            if (Livewire::isLivewireRequest()) {
                return self::isNavigable((string) Livewire::originalUrl());
            }

            return in_array(request()->route()?->getName(), self::PAGES, true);
        });
    }

    /** Whether $href (absolute on this host, or a path) leads to a navigable page. A fragment does not matter. */
    public static function isNavigable(string $href): bool
    {
        $routeName = self::routeName($href);

        return $routeName !== null && in_array($routeName, self::PAGES, true);
    }

    /** The name of the GET route $href leads to, or null (another host, no route, an error). */
    private static function routeName(string $href): ?string
    {
        $parts = parse_url($href);

        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if ($host !== null && $host !== request()->getHost()) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '/', '/');

        return RequestMemo::remember('navigate.route.'.$path, function () use ($path): ?string {
            try {
                return Route::getRoutes()->match(Request::create($path, 'GET'))->getName();
            } catch (Throwable) {
                return null;
            }
        });
    }
}
