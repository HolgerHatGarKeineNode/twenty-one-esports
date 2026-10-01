<?php

namespace App\Support\TwentyOne\Stream;

use App\Games\GameRegistry;
use App\Games\SeriesGame;
use App\Support\Matches\MatchBlocks;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Lobbies;
use InvalidArgumentException;
use Throwable;

/**
 * The spotlight teaser (d6): a series game the league added, on a slide of
 * its own, with what it is and how it is played here. The game is
 * `twentyone.stream.rotation.spotlight` (Age of Empires II since 813bf8f2),
 * else the newest series game (the last in the registry's order); none
 * without a series game.
 *
 * A lobby game (P10, Age of Empires II) sells its tournaments in the claim:
 * lobbies of 3 to 8, diplomacy, the time limit, shared wins; its cups line
 * says they are one lobby match. Under its cover it shows its lobby match
 * now (P8, now()): running, finished with its shared 1st place, or none
 * with the next start; the ladder's leader stays for the last.
 *
 * Every line is read from the code that runs the game: the casual 1v1 queue
 * (`esports.casual.games`), the host's room card (resources/js/lobbyCards.js
 * HOST_CARD: a lobby for Rocket League and Age of Empires II), the cups'
 * weekend slot (CasualCups::slotOf(), enabled games only), the ladders the
 * registry's modes rate (a player's 1v1, a clan's lineups). Nothing is
 * promised that the game's page does not offer. The ladder's leader comes
 * from StreamStats' boards, so the slide costs no query of its own.
 * Stream copy is English.
 */
final class GameSpotlight
{
    /**
     * The host's lobby card of a casual 1v1 room, per game (resources/js/lobbyCards.js HOST_CARD `lobby`;
     * the composer, resources/views/pages/matches/partials/card-composer.blade.php, suggests a fresh password).
     */
    public const LOBBY = [
        'age-of-empires-2' => 'The host sends the lobby name and password in the match chat.',
        'rocket-league' => 'The host sends the private match and its password in the match chat.',
    ];

    /** @var array<string, string|null> cover path => data URI, read once per path */
    private static array $covers = [];

    public function __construct(private GameRegistry $games) {}

    /** The series game in the spotlight, null without one. */
    public function game(): ?SeriesGame
    {
        $series = $this->games->series();
        $chosen = $series[(string) config('twentyone.stream.rotation.spotlight', '')] ?? null;

        return $chosen ?? ($series === [] ? null : $series[array_key_last($series)]);
    }

    /**
     * The slide's data; null without a series game (the view then shows the join line).
     * With `$nowMs`, a lobby game (P8) also gets `now`, the state of its lobby match (now()).
     *
     * @param  array<string, mixed>  $stats  StreamStats::all()
     * @param  list<array<string, mixed>>  $upcoming  TournamentSlides::frames() of this poll
     * @return array{slug: string, name: string, claim: string, cover: string|null, colour: string, colourDeep: string, facts: list<array{label: string, line: string}>, leader: array{name: string, elo: int, avatar: string|null, ladder: string}|null, url: string, now: array<string, mixed>|null}|null
     */
    public function data(array $stats, ?int $nowMs = null, array $upcoming = []): ?array
    {
        $game = $this->game();

        if ($game === null) {
            return null;
        }

        $slug = $game->slug();
        $path = $this->games->coverPath($slug);
        $colours = MempoolLayout::colours(MatchBlocks::family($slug));

        return [
            'slug' => $slug,
            'name' => GameTitle::short($game->name()),
            'claim' => $this->claim($game),
            'cover' => $path === null ? null : (self::$covers[$path] ??= TournamentSlides::coverUri($path)),
            'colour' => $colours[1],
            'colourDeep' => $colours[4],
            'facts' => $this->facts($game),
            'leader' => $this->leader($slug, $stats),
            'url' => rtrim(preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')) ?? '', '/').'/games/'.$slug,
            'now' => $nowMs !== null && Lobbies::isLobbyGame($slug) ? $this->now($slug, $nowMs, $upcoming) : null,
        ];
    }

    /**
     * The state of the game's lobby match on the stream (plan "AoE2 und
     * Trackmania", P8), from the live tournament slides' snapshots in the
     * cache (TournamentLiveSlides::cachedSnapshots(), what the stream's poll
     * read), so it costs no query of its own. Null while the cache holds
     * none or a frame fails: the slide then says nothing about the match
     * rather than claim that none is on.
     *
     * - `running`: the first running lobby tournament of the game, its
     *   lobbies decided and the first lobby still in play with its
     *   countdown or report state;
     * - `finished`: else the latest finished one within the live slides'
     *   window, with its place 1 (shared by the allies left standing);
     * - `none`: else that no lobby match is on, and when the game's next
     *   open tournament starts (a cup counts), when there is one.
     *
     * Names are the players' public (Nostr) names as the live slides show
     * them; nothing of a game account is read.
     *
     * @param  list<array<string, mixed>>  $upcoming
     * @return array{state: 'running'|'finished'|'none', label: string, name: string, lines: list<string>}|null
     */
    public function now(string $slug, int $nowMs, array $upcoming = []): ?array
    {
        $slides = app(TournamentLiveSlides::class);
        $snapshots = $slides->cachedSnapshots();

        if ($snapshots === null) {
            return null;
        }

        $mine = array_values(array_filter($snapshots, fn (array $snapshot): bool => ($snapshot['gameSlug'] ?? null) === $slug
            && in_array($snapshot['phase'] ?? null, ['running', 'finished'], true) && ($snapshot['board']['kind'] ?? null) === 'lobbies'));

        try {
            $frames = $slides->frames($mine, $nowMs);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $running = array_values(array_filter($frames, fn (array $frame): bool => $frame['phase'] === 'running'))[0] ?? null;

        if ($running !== null) {
            $played = $running['progress']['played'] ?? null;
            $total = $running['progress']['total'] ?? null;
            $lines = is_int($played) && is_int($total) ? [$played.' of '.$total.' '.($total === 1 ? 'lobby' : 'lobbies').' decided'] : [];

            foreach ($running['board']['groups'] ?? [] as $group) {
                $status = (string) ($group['status'] ?? '');

                if ($status !== '' && ! in_array($status, ['decided', 'no result'], true)) {
                    $lines[] = $group['title'].': '.$status;

                    break;
                }
            }

            return ['state' => 'running', 'label' => $running['status'] === 'Paused' ? 'Paused' : 'Live now', 'name' => (string) $running['name'], 'lines' => $lines];
        }

        $finished = array_values(array_filter($frames, fn (array $frame): bool => $frame['phase'] === 'finished' && ($frame['sharedFirst'] ?? []) !== []))[0] ?? null;

        if ($finished !== null) {
            return ['state' => 'finished', 'label' => count($finished['sharedFirst']) > 1 ? 'Shared 1st place' : '1st place', 'name' => (string) $finished['name'],
                'lines' => array_values(array_map(strval(...), $finished['sharedFirst']))];
        }

        $title = GameTitle::of($slug);
        $next = array_values(array_filter($upcoming, fn (array $t): bool => ($t['game'] ?? null) === $title && is_string($t['startsAt'] ?? null)))[0] ?? null;

        return ['state' => 'none', 'label' => '', 'name' => '',
            'lines' => ['No lobby match on right now.'.($next === null ? '' : ' Next: '.$next['startsAt'])]];
    }

    /**
     * The series lengths its modes allow and, without draws, that every game has a winner:
     * "Best of 1 or 3. Every game has a winner."
     */
    public function claim(SeriesGame $game): string
    {
        // A lobby game (P10): its tournaments and cups are one lobby match, and that is what the slide sells.
        if (Lobbies::isLobbyGame($game->slug())) {
            $slug = $game->slug();

            return 'Lobbies of '.min(Lobbies::minEntries($slug), Lobbies::maxPlayers($slug)).' to '.Lobbies::maxPlayers($slug).'. Diplomacy, '
                .(Lobbies::timeLimit($slug) % 60 === 0 ? intdiv(Lobbies::timeLimit($slug), 60).' h' : Lobbies::timeLimit($slug).' min').', shared wins.';
        }

        $bestOf = [];
        $draws = false;

        foreach ($game->modes() as $mode) {
            $bestOf = [...$bestOf, ...$mode->bestOf];
            $draws = $draws || $mode->allowsDraws;
        }

        $bestOf = array_values(array_unique($bestOf));
        sort($bestOf);

        return trim(($bestOf === [] ? '' : 'Best of '.implode(' or ', $bestOf).'.').($draws ? '' : ' Every game has a winner.'));
    }

    /**
     * How the game is played here, one line each, in the order a new player meets them.
     *
     * @return list<array{label: string, line: string}>
     */
    public function facts(SeriesGame $game): array
    {
        $slug = $game->slug();
        $facts = [];

        if (in_array($slug, (array) config('esports.casual.games', []), true)) {
            // The casual 1v1: the queue or a direct invite, then both players ready up (the ready check).
            $facts[] = ['label' => 'Casual 1v1', 'line' => 'Queue up or invite, then both ready up.'];
        }

        if (isset(self::LOBBY[$slug])) {
            $facts[] = ['label' => 'Lobby', 'line' => self::LOBBY[$slug]];
        }

        if (in_array($slug, CasualCups::enabledGames(), true)) {
            try {
                $slot = CasualCups::slotOf($slug);
                $regions = array_values(array_map(fn (array $region): string => (string) $region['label'], CasualCups::regions()));
                $facts[] = ['label' => 'Cups', 'line' => ucfirst($slot['weekday']).'s at '.sprintf('%02d:%02d', $slot['hour'], $slot['minute']).' local time'.($regions === [] ? '' : ', '.RotationKit::listing($regions, 3))
                    // A lobby game's cup (P10) is one lobby match, never a bracket.
                    .(Lobbies::isLobbyGame($slug) ? ': one lobby match.' : '.')];
            } catch (InvalidArgumentException) {
                // A game without a valid slot opens no cup: nothing to promise.
            }
        }

        $player = [];
        $lineup = [];

        foreach ($game->modes() as $mode) {
            if ($mode->rates === 'player') {
                $player[] = $mode->name;
            } else {
                $lineup[] = $mode->name;
            }
        }

        $ladder = array_filter([
            $player === [] ? null : RotationKit::listing($player, 3).' for you',
            $lineup === [] ? null : RotationKit::listing($lineup, 3).' for your clan',
        ]);

        if ($ladder !== []) {
            $facts[] = ['label' => 'Ladder', 'line' => ucfirst(implode(', ', $ladder)).'.'];
        }

        return $facts;
    }

    /**
     * The top of the game's ladder on the stream now: the first player ladder with a result (StreamStats' boards: the
     * season ladder once it has rows, else the casual one), else the first lineup ladder; null while nobody played.
     *
     * @param  array<string, mixed>  $stats
     * @return array{name: string, elo: int, avatar: string|null, ladder: string}|null
     */
    private function leader(string $slug, array $stats): ?array
    {
        $boards = array_values(array_filter(RotationKit::ladderBoards($stats), fn (array $board): bool => $board['game'] === $slug && $board['rows'] !== []));
        usort($boards, fn (array $a, array $b): int => ($this->games->mode($slug, $a['mode'])?->rates === 'player' ? 0 : 1) <=> ($this->games->mode($slug, $b['mode'])?->rates === 'player' ? 0 : 1));
        $board = $boards[0] ?? null;
        $row = $board['rows'][0] ?? null;

        return $board === null || $row === null ? null : [
            'name' => $row['name'],
            'elo' => $row['elo'],
            'avatar' => $row['avatar'],
            'ladder' => $board['modeName'].' '.($board['rated'] ? 'season ladder' : 'casual ladder'),
        ];
    }
}
