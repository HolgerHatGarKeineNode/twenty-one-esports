<?php

namespace App\Games;

use App\Games\Contracts\Game;
use App\Support\Board\BoardRules;

/**
 * A board game other than chess, played move by move on our server (plan
 * "Mühle und Dame": nine men's morris, checkers). It names its kind, so
 * every switch in the league tells it from chess and from a series, and its
 * rules, which the board game core (App\Support\Board, P2) plays by.
 *
 * A board game is registered through `config('esports.board_games')`, never
 * through `esports.games`, so the switch there keeps it off the site.
 */
abstract class BoardGame implements Game
{
    /**
     * The slugs kept for the planned board games: nine men's morris is
     * `nine-mens-morris`, never `mill` (resources/js/millAuth.js is the
     * nostr-mill login; a game named "mill" would mix up search and names).
     */
    public const RESERVED_SLUGS = ['nine-mens-morris', 'checkers', 'blockli'];

    final public function kind(): GameKind
    {
        return GameKind::Board;
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    /**
     * The game's rules, which the board game core (App\Support\Board\BoardGameService)
     * asks about every move (P2).
     *
     * @return BoardRules<mixed>
     */
    abstract public function rules(): BoardRules;

    /** The credit line of the game's config entry (`credit`), null without one (plan "Blockli", P3). */
    private ?string $credit = null;

    /** Where the credit links to (`credit_url`), null for a credit without a link. */
    private ?string $creditUrl = null;

    /**
     * Takes the credit from the game's config entry when the registry builds
     * it (AppServiceProvider::boardGames()); a blank value is no credit.
     */
    public function credited(?string $credit, ?string $creditUrl = null): static
    {
        $this->credit = trim((string) $credit) === '' ? null : trim((string) $credit);
        $this->creditUrl = trim((string) $creditUrl) === '' ? null : trim((string) $creditUrl);

        return $this;
    }

    public function credit(): ?string
    {
        return $this->credit;
    }

    public function creditUrl(): ?string
    {
        return $this->credit === null ? null : $this->creditUrl;
    }
}
