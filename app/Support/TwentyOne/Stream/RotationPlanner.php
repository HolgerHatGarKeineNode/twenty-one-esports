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
 * (prize pots, casual cups), then TEASERS from the pool of eleven,
 * continuing where the last round stopped. Without games a round is the
 * teasers alone, and every `loopEvery`-th such round (the first one
 * included, so the daemon starts on the loop) is one pass of the promo loop.
 *
 * Upcoming tournaments (open for sign-up, soonest close first, as the
 * caller orders them; the caller leaves the casual cups out, d2 shows them)
 * come in every round while there is one: a round with games is MATCH,
 * GALLERY, then TOURNAMENT hero and bracket in the round's look, then the
 * teasers; a round without games that is not the loop is the tournament's
 * hero and bracket, the EVERY_ROUND teasers and one teaser. Several
 * tournaments take turns, one per round.
 *
 * Games have priority: a game that appears during a round without games
 * takes over at the end of the current teaser, and ends the loop at once.
 * A match whose game is gone, a gallery with fewer than two games, or a
 * tournament slide whose tournament closed or went away, ends early too.
 */
final class RotationPlanner
{
    public const MATCH = 'match';

    public const GALLERY = 'gallery';

    public const TEASER = 'teaser';

    public const LOOP = 'loop';

    public const TOURNAMENT = 'tournament';

    public const LOOKS = ['a', 'b', 'c'];

    public const TEASERS = ['a3', 'a4', 'a5', 'b3', 'b4', 'b5', 'c3', 'c4', 'c5', 'd3', 'd4'];

    /** Teasers in every round, before the pool's: prize pots (d1), casual cups (d2). */
    public const EVERY_ROUND = ['d1', 'd2'];

    /** The feature teasers: prize pots (d1), casual cups (d2), invite links (d3), the league on Nostr (d4). */
    public const FEATURE_SCENES = ['d1', 'd2', 'd3', 'd4'];

    /** Scene id => its view (resources/views/stream/rotation). */
    public const VIEWS = [
        'a1' => 'stream.rotation.a1-match', 'a2' => 'stream.rotation.a2-gallery', 'a3' => 'stream.rotation.a3-ladders', 'a4' => 'stream.rotation.a4-join', 'a5' => 'stream.rotation.a5-zap',
        'b1' => 'stream.rotation.b1-match', 'b2' => 'stream.rotation.b2-gallery', 'b3' => 'stream.rotation.b3-daily', 'b4' => 'stream.rotation.b4-clans', 'b5' => 'stream.rotation.b5-boards',
        'c1' => 'stream.rotation.c1-match', 'c2' => 'stream.rotation.c2-gallery', 'c3' => 'stream.rotation.c3-ladders', 'c4' => 'stream.rotation.c4-scan', 'c5' => 'stream.rotation.c5-zap',
        'ta1' => 'stream.rotation.ta1-hero', 'ta2' => 'stream.rotation.ta2-bracket',
        'tb1' => 'stream.rotation.tb1-hero', 'tb2' => 'stream.rotation.tb2-bracket',
        'tc1' => 'stream.rotation.tc1-hero', 'tc2' => 'stream.rotation.tc2-bracket',
        'd1' => 'stream.rotation.d1-pots', 'd2' => 'stream.rotation.d2-cups', 'd3' => 'stream.rotation.d3-invite', 'd4' => 'stream.rotation.d4-nostr',
    ];

    /** The tournament slides' scene ids, one pair per look: hero (1), bracket preview (2). */
    public const TOURNAMENT_SCENES = ['ta1', 'ta2', 'tb1', 'tb2', 'tc1', 'tc2'];

    /** @var array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}|null */
    private ?array $slot = null;

    /** @var list<array{kind: string, look?: string, gameId?: int, tournamentId?: int, part?: int, scene?: string}> the rest of the current round */
    private array $queue = [];

    private bool $roundWithGames = false;

    /** Rounds that carried a look (every round with games, every tournament round without). */
    private int $lookRounds = 0;

    private int $idleRounds = 0;

    private int $teaser = 0;

    private int $matchTurn = 0;

    private int $tournamentTurn = 0;

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
     * @return array{kind: string, scene: string|null, gameId: int|null, tournamentId: int|null, until: float}
     */
    public function at(float $now, array $games, array $tournaments = []): array
    {
        $ids = array_column($games, 'id');

        if ($this->slot === null || $now >= $this->slot['until'] || $this->endsEarly($ids, $tournaments)) {
            $this->advance($now, $games, $tournaments);
        }

        assert($this->slot !== null);

        return $this->slot;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $tournaments
     */
    private function endsEarly(array $ids, array $tournaments): bool
    {
        assert($this->slot !== null);

        return match ($this->slot['kind']) {
            // Live priority: a game ends the loop at once.
            self::LOOP => $ids !== [],
            self::MATCH => ! in_array($this->slot['gameId'], $ids, true),
            self::GALLERY => count($ids) < 2,
            self::TOURNAMENT => ! in_array($this->slot['tournamentId'], $tournaments, true),
            default => false,
        };
    }

    /**
     * @param  list<array{id: int, blitz: bool}>  $games
     * @param  list<int>  $tournaments
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
     * @param  list<int>  $tournaments
     */
    private function plan(array $games, array $tournaments): void
    {
        $teasers = array_fill(0, max(1, $this->teasersPerRound), ['kind' => self::TEASER]);
        $everyRound = [];

        foreach (self::EVERY_ROUND as $scene) {
            $everyRound[] = ['kind' => self::TEASER, 'scene' => $scene];
        }

        if ($games === []) {
            $this->roundWithGames = false;

            if ($this->idleRounds++ % max(1, $this->loopEvery) === 0) {
                $this->queue = [['kind' => self::LOOP]];
            } elseif ($tournaments !== []) {
                $this->queue = [...$this->tournamentSlides($this->nextLook(), $tournaments), ...$everyRound, ['kind' => self::TEASER]];
            } else {
                $this->queue = [...$everyRound, ...$teasers];
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
            ...($tournaments === [] ? [] : $this->tournamentSlides($look, $tournaments)),
            ...$everyRound,
            ...$teasers,
        ];
    }

    private function nextLook(): string
    {
        return self::LOOKS[$this->lookRounds++ % count(self::LOOKS)];
    }

    /**
     * This round's tournament (in turn) as hero and bracket preview.
     *
     * @param  list<int>  $tournaments
     * @return list<array{kind: string, look: string, tournamentId: int, part: int}>
     */
    private function tournamentSlides(string $look, array $tournaments): array
    {
        $id = $tournaments[$this->tournamentTurn++ % count($tournaments)];

        return [
            ['kind' => self::TOURNAMENT, 'look' => $look, 'tournamentId' => $id, 'part' => 1],
            ['kind' => self::TOURNAMENT, 'look' => $look, 'tournamentId' => $id, 'part' => 2],
        ];
    }

    /**
     * @param  array{kind: string, look?: string, gameId?: int, tournamentId?: int, part?: int, scene?: string}  $entry
     * @param  list<array{id: int, blitz: bool}>  $games
     * @param  list<int>  $tournaments
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
                $id = $entry['tournamentId'] ?? null;

                return ! in_array($id, $tournaments, true) ? null : $this->slot(self::TOURNAMENT, 't'.($entry['look'] ?? 'a').($entry['part'] ?? 1), null, $start + $this->tournamentSeconds, $id);
            case self::LOOP:
                return $this->slot(self::LOOP, null, null, $start + $this->loopSeconds);
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
