<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Which rotation scene the stream shows now: a pure state machine over time
 * and the games on show, deterministic and without ffmpeg.
 *
 * With games (active, daily included, or ended within the hysteresis the
 * caller applies) a round is: MATCH in this round's look (A, B, C in turn;
 * the next game in turn, blitz first as the caller orders them), GALLERY in
 * the same look when two or more games run, then the EVERY_ROUND teasers
 * (sats to win, casual cups or the mempool, a player's pride moment), then TEASERS from the pool of twelve,
 * continuing where the last round stopped. Without games a round is the
 * teasers alone, and every `loopEvery`-th such round (the first one
 * included, so the daemon starts on the loop) is one pass of the promo loop.
 *
 * Upcoming tournaments (open for sign-up, soonest close first, as the
 * caller orders them; the caller leaves the casual cups out, d2 shows them)
 * come in every round while there is one: a round with games is MATCH,
 * GALLERY, then TOURNAMENT hero, bracket preview and how it runs in the
 * round's look, then the teasers; a round without games that is not the loop
 * is those three, the EVERY_ROUND teasers and one teaser. Several
 * tournaments take turns, one per round.
 *
 * Tournaments past their sign-up (TournamentLiveSlides: running, drawing,
 * finished within its window; the caller orders them so) take the same turns,
 * before the upcoming ones: one tournament per round across both lists, so
 * several tournaments share the rounds fairly. Their slides, in the round's
 * look: a running tournament its live bracket (4), then in turn who is still
 * standing (5) or how it runs (3); a drawing one how it runs (3); a finished
 * one its champion (6), then in turn the final bracket (4) or its pride (5).
 * Each is followed by the call to sign up for the next tournament (7) while
 * one is open (the caller says so per tournament: `fomo`).
 *
 * Games have priority: a game that appears during a round without games
 * takes over at the end of the current teaser, and ends the loop at once.
 * A match whose game is gone, a gallery with fewer than two games, or a
 * tournament slide whose tournament closed or went away, ends early too.
 *
 * The board games next to chess (plan "Mühle und Dame", P7) have one scene,
 * BOARD (d5), as the caller reports them (BoardScene::state()): while a
 * board game is live it comes in every round, for `matchSeconds`, right
 * after match and gallery (first in a round without games); while board
 * games are switched on but none runs, every BOARD_IDLE_EVERY-th round
 * shows it as a teaser; switched off, never. The loop is not cut short for it.
 */
final class RotationPlanner
{
    public const MATCH = 'match';

    public const GALLERY = 'gallery';

    public const TEASER = 'teaser';

    public const LOOP = 'loop';

    public const TOURNAMENT = 'tournament';

    /** A board game live, or the board games' teaser (BoardScene). */
    public const BOARD = 'board';

    /** The scene of BOARD. */
    public const BOARD_SCENE = 'd5';

    /** Without a live board game, the board teaser comes every this many rounds. */
    public const BOARD_IDLE_EVERY = 3;

    public const LOOKS = ['a', 'b', 'c'];

    /** The pool, taken in turn: ladders, join, zaps, daily, clans, boards, scan, invites, Nostr, and the spotlight game (d6, GameSpotlight). */
    public const TEASERS = ['a3', 'a4', 'a5', 'b3', 'b4', 'b5', 'c3', 'c4', 'c5', 'd3', 'd4', 'd6'];

    /**
     * Teasers in every round, before the pool's: one of each group, the groups
     * taking turns from round to round: the sats to win (all pots d1, the
     * biggest pot's prizes e4), the casual cups (d2) or the mempool of every
     * game (m1, MempoolSlides) every second round, a player named for what
     * they did (latest win e1, climbers e2, new sign-ups e3, the block a win
     * mined e5, the strongest across all games e6, rank-ups e7, win streaks
     * e8, the season's payouts e9).
     */
    public const EVERY_ROUND = [['d1', 'e4'], ['d2', 'm1'], ['e1', 'e2', 'e3', 'e5', 'e6', 'e7', 'e8', 'e9']];

    /** The pride and prize slides (PrideSlides): latest win, climbers, new sign-ups, a pot's prizes, block mined, strongest, rank-ups, streaks, payouts. */
    public const PRIDE_SCENES = ['e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'e8', 'e9'];

    /** The feature teasers: prize pots (d1), casual cups (d2), invite links (d3), the league on Nostr (d4), the spotlight game (d6). */
    public const FEATURE_SCENES = ['d1', 'd2', 'd3', 'd4', 'd6'];

    /** Scene id => its view (resources/views/stream/rotation). */
    public const VIEWS = [
        'a1' => 'stream.rotation.a1-match', 'a2' => 'stream.rotation.a2-gallery', 'a3' => 'stream.rotation.a3-ladders', 'a4' => 'stream.rotation.a4-join', 'a5' => 'stream.rotation.a5-zap',
        'b1' => 'stream.rotation.b1-match', 'b2' => 'stream.rotation.b2-gallery', 'b3' => 'stream.rotation.b3-daily', 'b4' => 'stream.rotation.b4-clans', 'b5' => 'stream.rotation.b5-boards',
        'c1' => 'stream.rotation.c1-match', 'c2' => 'stream.rotation.c2-gallery', 'c3' => 'stream.rotation.c3-ladders', 'c4' => 'stream.rotation.c4-scan', 'c5' => 'stream.rotation.c5-zap',
        'ta1' => 'stream.rotation.ta1-hero', 'ta2' => 'stream.rotation.ta2-bracket',
        'tb1' => 'stream.rotation.tb1-hero', 'tb2' => 'stream.rotation.tb2-bracket',
        'tc1' => 'stream.rotation.tc1-hero', 'tc2' => 'stream.rotation.tc2-bracket',
        'ta3' => 'stream.rotation.ta3-how', 'ta4' => 'stream.rotation.ta4-live', 'ta5' => 'stream.rotation.ta5-standing', 'ta6' => 'stream.rotation.ta6-champion', 'ta7' => 'stream.rotation.ta7-next',
        'tb3' => 'stream.rotation.tb3-how', 'tb4' => 'stream.rotation.tb4-live', 'tb5' => 'stream.rotation.tb5-standing', 'tb6' => 'stream.rotation.tb6-champion', 'tb7' => 'stream.rotation.tb7-next',
        'tc3' => 'stream.rotation.tc3-how', 'tc4' => 'stream.rotation.tc4-live', 'tc5' => 'stream.rotation.tc5-standing', 'tc6' => 'stream.rotation.tc6-champion', 'tc7' => 'stream.rotation.tc7-next',
        'd1' => 'stream.rotation.d1-pots', 'd2' => 'stream.rotation.d2-cups', 'd3' => 'stream.rotation.d3-invite', 'd4' => 'stream.rotation.d4-nostr',
        'e1' => 'stream.rotation.e1-win', 'e2' => 'stream.rotation.e2-climbers', 'e3' => 'stream.rotation.e3-signups', 'e4' => 'stream.rotation.e4-prizes',
        'd5' => 'stream.rotation.d5-board', 'd6' => 'stream.rotation.d6-spotlight',
        'e5' => 'stream.rotation.e5-block', 'e6' => 'stream.rotation.e6-strongest', 'e7' => 'stream.rotation.e7-rank-up', 'e8' => 'stream.rotation.e8-streak', 'e9' => 'stream.rotation.e9-payouts',
        'm1' => 'stream.rotation.m1-mempool',
    ];

    /**
     * The tournament slides' scene ids per look: hero (1) and bracket preview (2) while sign-up is open, and past
     * sign-up (LIVE_TOURNAMENT_SCENES) how it runs (3), the live bracket (4), still standing (5), the champion (6),
     * the next tournament to sign up for (7).
     */
    public const TOURNAMENT_SCENES = ['ta1', 'ta2', 'tb1', 'tb2', 'tc1', 'tc2',
        'ta3', 'ta4', 'ta5', 'ta6', 'ta7', 'tb3', 'tb4', 'tb5', 'tb6', 'tb7', 'tc3', 'tc4', 'tc5', 'tc6', 'tc7'];

    /** The slides of a tournament past its sign-up (TournamentLiveSlides), by look. */
    public const LIVE_TOURNAMENT_SCENES = ['ta3', 'ta4', 'ta5', 'ta6', 'ta7', 'tb3', 'tb4', 'tb5', 'tb6', 'tb7', 'tc3', 'tc4', 'tc5', 'tc6', 'tc7'];

    /**
     * The slide parts of each phase past sign-up: the first every round, then one of the second group in turn
     * (per tournament), then the call to sign up for the next one (7) while one is open.
     */
    public const PHASE_PARTS = ['running' => [4, [5, 3]], 'drawing' => [3, []], 'finished' => [6, [4, 5]]];

    /** The part that points the audience to the next tournament's sign-up. */
    public const NEXT_PART = 7;

    /** @var array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}|null */
    private ?array $slot = null;

    /** "phase:id" of the tournament slide on show, null for any other slot. */
    private ?string $slotTournament = null;

    /** @var list<array{kind: string, look?: string, gameId?: int, tournamentId?: int, part?: int, scene?: string, key?: string}> the rest of the current round */
    private array $queue = [];

    private bool $roundWithGames = false;

    /** Rounds that carried a look (every round with games, every tournament round without). */
    private int $lookRounds = 0;

    private int $idleRounds = 0;

    private int $teaser = 0;

    private int $matchTurn = 0;

    private int $tournamentTurn = 0;

    /** Rounds that took their EVERY_ROUND teasers (each group takes turns by it). */
    private int $everyRoundTurn = 0;

    /** @var array<string, bool> "phase:id" of every tournament slide that may show now => its next-tournament call applies */
    private array $tournamentKeys = [];

    /** @var array<int, int> tournament id => its turns past sign-up so far (the second part takes turns by it) */
    private array $liveTurns = [];

    /** BoardScene::OFF, IDLE or LIVE, as the last call to at() reported it. */
    private string $boards = BoardScene::OFF;

    /** Rounds planned while board games were switched on (the idle teaser counts them). */
    private int $boardRounds = 0;

    public function __construct(
        private float $matchSeconds = 45,
        private float $blitzMatchSeconds = 60,
        private float $gallerySeconds = 20,
        private float $teaserSeconds = 12,
        private int $teasersPerRound = 3,
        private int $loopEvery = 3,
        private float $loopSeconds = 60,
        private float $tournamentSeconds = 15,
    ) {}

    public static function fromConfig(float $loopSeconds): self
    {
        return new self(
            (float) config('twentyone.stream.rotation.match_seconds', 45),
            (float) config('twentyone.stream.rotation.blitz_match_seconds', 60),
            (float) config('twentyone.stream.rotation.gallery_seconds', 20),
            (float) config('twentyone.stream.rotation.teaser_seconds', 12),
            (int) config('twentyone.stream.rotation.teasers_per_round', 3),
            (int) config('twentyone.stream.rotation.loop_every_rounds', 3),
            $loopSeconds,
            (float) config('twentyone.stream.rotation.tournament_seconds', 15),
        );
    }

    /**
     * The slot on show at `$now`.
     *
     * @param  list<array{id: int, blitz: bool}>  $games  the games on show, in display order
     * @param  list<int>  $tournaments  the upcoming tournaments' ids, soonest sign-up close first
     * @param  string  $boards  BoardScene::OFF, IDLE or LIVE
     * @param  list<array{id: int, phase: string, fomo: bool}>  $live  the tournaments past sign-up (TournamentLiveSlides::entries()), in turn order
     * @return array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}
     */
    public function at(float $now, array $games, array $tournaments = [], string $boards = BoardScene::OFF, array $live = []): array
    {
        $ids = array_column($games, 'id');
        $this->boards = $boards;
        $this->tournamentKeys = [];

        foreach ($live as $entry) {
            if (isset(self::PHASE_PARTS[$entry['phase']])) {
                $this->tournamentKeys[$entry['phase'].':'.$entry['id']] = $entry['fomo'];
            }
        }

        foreach ($tournaments as $id) {
            $this->tournamentKeys['signup:'.$id] = false;
        }

        // One list for the turns: past sign-up first, then the upcoming ones.
        $tournaments = array_keys($this->tournamentKeys);

        if ($this->slot === null || $now >= $this->slot['until'] || $this->endsEarly($ids, $tournaments)) {
            $this->advance($now, $games, $tournaments);
        }

        assert($this->slot !== null);

        return $this->slot;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $tournaments  "phase:id" of every tournament in turn
     */
    private function endsEarly(array $ids, array $tournaments): bool
    {
        assert($this->slot !== null);

        return match ($this->slot['kind']) {
            // Live priority: a game ends the loop at once.
            self::LOOP => $ids !== [],
            self::MATCH => ! in_array($this->slot['gameId'], $ids, true),
            self::GALLERY => count($ids) < 2,
            // Gone, or in another phase now (its slides would show the wrong one).
            self::TOURNAMENT => ! in_array($this->slotTournament, $tournaments, true),
            default => false,
        };
    }

    /**
     * @param  list<array{id: int, blitz: bool}>  $games
     * @param  list<string>  $tournaments
     */
    private function advance(float $now, array $games, array $tournaments): void
    {
        // Back to back on schedule; after a stall (or early end) from now.
        $start = $this->slot !== null && $now >= $this->slot['until'] && $now - $this->slot['until'] < 1 ? $this->slot['until'] : $now;

        // A round without games gives way as soon as a game is there.
        if (! $this->roundWithGames && $games !== []) {
            $this->queue = [];
        }

        while (true) {
            if ($this->queue === []) {
                $this->plan($games, $tournaments);
            }

            $next = array_shift($this->queue);
            $slot = $this->slotFor($next, $games, $tournaments, $start);

            if ($slot !== null) {
                $this->slot = $slot;

                return;
            }
        }
    }

    /**
     * @param  list<array{id: int, blitz: bool}>  $games
     * @param  list<string>  $tournaments
     */
    private function plan(array $games, array $tournaments): void
    {
        $teasers = array_fill(0, max(1, $this->teasersPerRound), ['kind' => self::TEASER]);

        if ($games === []) {
            $this->roundWithGames = false;

            if ($this->idleRounds++ % max(1, $this->loopEvery) === 0) {
                $this->queue = [['kind' => self::LOOP]];
            } elseif ($tournaments !== []) {
                $this->queue = [...$this->board(), ...$this->tournamentSlides($this->nextLook(), $tournaments), ...$this->everyRound(), ['kind' => self::TEASER]];
            } else {
                $this->queue = [...$this->board(), ...$this->everyRound(), ...$teasers];
            }

            return;
        }

        $this->roundWithGames = true;
        $look = $this->nextLook();
        $game = $games[$this->matchTurn++ % count($games)];
        $this->queue = [
            ['kind' => self::MATCH, 'look' => $look, 'gameId' => $game['id']],
            // Skipped when fewer than two games are on show by then (slotFor()).
            ['kind' => self::GALLERY, 'look' => $look],
            ...$this->board(),
            ...($tournaments === [] ? [] : $this->tournamentSlides($look, $tournaments)),
            ...$this->everyRound(),
            ...$teasers,
        ];
    }

    /**
     * This round's EVERY_ROUND teasers: one of each group, in turn.
     *
     * @return list<array{kind: string, scene: string}>
     */
    private function everyRound(): array
    {
        $entries = [];

        foreach (self::EVERY_ROUND as $group) {
            $entries[] = ['kind' => self::TEASER, 'scene' => $group[$this->everyRoundTurn % count($group)]];
        }

        $this->everyRoundTurn++;

        return $entries;
    }

    /**
     * This round's board scene: every round while a board game is live, every
     * BOARD_IDLE_EVERY-th round (the first included) while none runs, never
     * while board games are switched off.
     *
     * @return list<array{kind: string}>
     */
    private function board(): array
    {
        if ($this->boards === BoardScene::OFF) {
            return [];
        }

        $turn = $this->boardRounds++;

        return $this->boards === BoardScene::LIVE || $turn % self::BOARD_IDLE_EVERY === 0 ? [['kind' => self::BOARD]] : [];
    }

    private function nextLook(): string
    {
        return self::LOOKS[$this->lookRounds++ % count(self::LOOKS)];
    }

    /**
     * This round's tournament (in turn): an upcoming one as hero, bracket
     * preview and how it runs, one past sign-up as its phase's parts (PHASE_PARTS) and the call
     * to sign up for the next one.
     *
     * @param  list<string>  $tournaments  "phase:id"
     * @return list<array{kind: string, look: string, tournamentId: int, part: int, key: string}>
     */
    private function tournamentSlides(string $look, array $tournaments): array
    {
        $key = $tournaments[$this->tournamentTurn++ % count($tournaments)];
        [$phase, $id] = explode(':', $key);
        $id = (int) $id;
        $entry = fn (int $part): array => ['kind' => self::TOURNAMENT, 'look' => $look, 'tournamentId' => $id, 'part' => $part, 'key' => $key];

        if (! isset(self::PHASE_PARTS[$phase])) {
            // Sign-up open: the hero, the bracket preview and how it runs (the user asked for the run of an upcoming one too).
            return [$entry(1), $entry(2), $entry(3)];
        }

        [$first, $turns] = self::PHASE_PARTS[$phase];
        $turn = $this->liveTurns[$id] = ($this->liveTurns[$id] ?? -1) + 1;

        return [
            $entry($first),
            ...($turns === [] ? [] : [$entry($turns[$turn % count($turns)])]),
            // Skipped when no next tournament is open by then (slotFor()).
            $entry(self::NEXT_PART),
        ];
    }

    /**
     * @param  array{kind: string, look?: string, gameId?: int, tournamentId?: int, part?: int, scene?: string, key?: string}  $entry
     * @param  list<array{id: int, blitz: bool}>  $games
     * @param  list<string>  $tournaments
     * @return array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}|null null when it no longer applies
     */
    private function slotFor(array $entry, array $games, array $tournaments, float $start): ?array
    {
        switch ($entry['kind']) {
            case self::MATCH:
                $game = collect($games)->firstWhere('id', $entry['gameId'] ?? null);

                return $game === null ? null : $this->slot(self::MATCH, ($entry['look'] ?? 'a').'1', $game['id'], $start + ($game['blitz'] ? $this->blitzMatchSeconds : $this->matchSeconds));
            case self::GALLERY:
                return count($games) < 2 ? null : $this->slot(self::GALLERY, ($entry['look'] ?? 'a').'2', null, $start + $this->gallerySeconds);
            case self::TOURNAMENT:
                $key = $entry['key'] ?? '';
                $part = $entry['part'] ?? 1;

                if (! in_array($key, $tournaments, true) || ($part === self::NEXT_PART && ! ($this->tournamentKeys[$key] ?? false))) {
                    return null;
                }

                $slot = $this->slot(self::TOURNAMENT, 't'.($entry['look'] ?? 'a').$part, null, $start + $this->tournamentSeconds, $entry['tournamentId'] ?? null);
                $this->slotTournament = $key;

                return $slot;
            case self::LOOP:
                return $this->slot(self::LOOP, null, null, $start + $this->loopSeconds);
            case self::BOARD:
                // Switched off since the round was planned: skipped.
                return $this->boards === BoardScene::OFF ? null
                    : $this->slot(self::BOARD, self::BOARD_SCENE, null, $start + ($this->boards === BoardScene::LIVE ? $this->matchSeconds : $this->teaserSeconds));
            default:
                $scene = $entry['scene'] ?? self::TEASERS[$this->teaser++ % count(self::TEASERS)];

                return $this->slot(self::TEASER, $scene, null, $start + $this->teaserSeconds);
        }
    }

    /**
     * @return array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}
     */
    private function slot(string $kind, ?string $scene, ?int $gameId, float $until, ?int $tournamentId = null): array
    {
        return ['kind' => $kind, 'scene' => $scene, 'gameId' => $gameId, 'tournamentId' => $tournamentId, 'until' => $until];
    }
}
