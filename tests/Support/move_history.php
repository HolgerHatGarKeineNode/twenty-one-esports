<?php

use App\Models\ChessGame;
use Pest\Browser\Playwright\Page;

/*
| Helpers of the move-history browser tests (P55): tests/Browser/BlitzGameTest.php
| (live blitz, players and a spectator) and ChessCorrespondenceQuietTest.php (daily).
*/

/**
 * What a page's board shows and what its history bar says: the glyph on each
 * square compared with the position a FEN would give (`fen`), the bar's
 * state, the geometry that must not move between following and browsing,
 * and any bar text cut off by its box.
 */
const HISTORY_PROBE = <<<'JS'
    (fen) => {
        const root = document.querySelector('[data-test=chess-game]') ?? document.querySelector('[data-test=daily-game]');
        const g = Alpine.$data(root);
        const board = root.querySelector('[role=img][data-bleed]');
        const squares = [...board.querySelector('.grid').children].filter((el) => el.tagName === 'DIV');
        const glyphs = squares.map((sq) => sq.querySelector('text')?.textContent ?? '').join('|');
        const want = fen === null ? null : window.chessBoardCells(fen, { flip: g.flipped }).map((c) => c.solid).join('|');
        const bar = root.querySelector('[data-test=history-bar]');
        const shown = (sel) => { const el = bar.querySelector(sel); return !!el && el.checkVisibility(); };
        const rect = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.top + scrollY), Math.round(r.height), Math.round(r.width)]; };
        const clock = root.querySelector('[data-test=clock-bottom]') ?? root.querySelector('[data-test=daily-player-bottom]');
        const cut = [...bar.querySelectorAll('.truncate')].filter((el) => el.checkVisibility() && el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent.trim());
        const steps = [...bar.querySelectorAll('[data-test^=history-]')].filter((el) => el.tagName === 'BUTTON' && el.checkVisibility()).map((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
        return {
            ply: g.state.ply,
            shown: g.shownIndex,
            browsing: g.browsing,
            newMoves: g.newMoves,
            boardMatches: want === null ? null : glyphs === want,
            past: board.classList.contains('is-past'),
            live: shown('[data-test=history-live]'),
            back: shown('[data-test=history-back]'),
            fresh: shown('[data-test=history-new]'),
            barText: bar.innerText.replace(/\s+/g, ' ').trim(),
            bar: rect(bar),
            board: rect(board),
            clock: rect(clock),
            steps,
            cut,
            // The first viewport ends where what is fixed at the bottom starts (the tab bar, the chat sheet).
            floor: Math.round(Math.min(innerHeight, ...[...document.body.querySelectorAll("*")].filter((el) => getComputedStyle(el).position === "fixed" && el.checkVisibility() && el.getBoundingClientRect().top > innerHeight / 2).map((el) => el.getBoundingClientRect().top))),
            banner: !!document.querySelector("[data-test=first-steps]")?.checkVisibility(),
            doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        };
    }
    JS;

/** The position after `$ply` plies of the game, as the server stored it with that move. */
function fenAt(ChessGame $game, int $ply): string
{
    return $ply === 0 ? $game->startFen() : (string) $game->moves()->where('ply', $ply)->value('fen');
}

/** A board key, pressed on the page itself: the move field (or any field) would keep it for typing. */
function pressKey(Page $page, string $key): void
{
    $page->evaluate('() => document.activeElement?.blur()');
    $page->locator('body')->press($key);
}

function historyOf(Page $page, ?string $fen = null): array
{
    return $page->evaluate(HISTORY_PROBE, $fen);
}
