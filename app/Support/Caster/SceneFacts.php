<?php

namespace App\Support\Caster;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Caster\GameMoments\GameMoments;
use App\Support\Caster\GameMoments\MatchAnchor;
use App\Support\Tournaments\TournamentStartVote;
use App\Support\TwentyOne\Stream\BlockfillSlides;
use App\Support\TwentyOne\Stream\MempoolSlides;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TmnfSlides;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Carbon\CarbonImmutable;
use Normalizer;
use Throwable;

/**
 * The public facts a commentary script may speak about, read from the
 * league's own tables and from the stream's slide builders: per topic
 * (SceneTopic) the data its slides render (live games with their move,
 * results of the last day with winner, loser and how, tournaments open for
 * sign-up with start, places and pot, a tournament under way with its table
 * and results, the ladders' top rows, the clan spotlight, Trackmania's and
 * Blockfill's week), plus the league's size. Players are named by their
 * league display name only, never with a gamer tag, a game account or a
 * Lightning address; a name the voice cannot speak (a shortened npub) is
 * left out with its row.
 *
 * Every string is spoken German or a name made speakable (spoken()): "#1"
 * becomes "Nummer 1", brackets and other symbols go, so the guards never
 * reject a script for a character the facts brought in (the hashtag
 * rejections of 2026-10-11 came from tournament names like "… Cup EU #1").
 *
 * Only facts that change with the programme go in (no viewer count, no
 * clock, no countdown): `hash()` of the facts tells the queue when the
 * scripts of a topic were written for a state that is gone (superseded
 * scripts expire).
 *
 * A read that fails leaves its part out; the topic then has fewer facts.
 */
class SceneFacts
{
    /** Live games named in a live-match script. */
    private const LIVE_GAMES = 3;

    /** Results of this many hours count as fresh (the snapshot of 2026-10-11 had none within the old two hours). */
    private const RESULT_HOURS = 24;

    /** Results named in a script. */
    private const RESULTS = 4;

    /** Tournaments open for sign-up named in a script. */
    private const TOURNAMENTS = 3;

    /** Rows of a ladder, a table or a week's board. */
    private const ROWS = 3;

    /** Ladders named in a script. */
    private const LADDERS = 3;

    /** How a chess game ended, spoken ("… verliert durch Aufgabe"). */
    public const END_REASONS = [
        'checkmate' => 'Matt',
        'resignation' => 'Aufgabe',
        'timeout' => 'Zeitüberschreitung',
        'agreement' => 'Einigung',
        'stalemate' => 'Patt',
    ];

    /** Chess modes, spoken. */
    public const MODES = ['blitz' => 'Blitz', 'rapid' => 'Schnellschach', 'bullet' => 'Bullet', ChessGame::CORRESPONDENCE => 'Fernschach'];

    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** Game names the slides carry in English, spoken in German. */
    private const GAME_NAMES = ['Chess' => 'Schach'];

    /** @var list<array{game: ChessGame, white: string, black: string, moments: list<array<string, string|int>>}>|null the ranked chess games of the forTopic() call under way, null outside one */
    private ?array $ranked = null;

    /** @var array<string, mixed>|null MempoolSlides::read() of this object */
    private ?array $mempoolData = null;

    public function __construct(private TournamentSlides $tournaments) {}

    /**
     * The facts of a programme phase (a live moment, the voice preview).
     *
     * @return array<string, mixed>
     */
    public function for(string $phase): array
    {
        $facts = $this->basics();

        return match ($phase) {
            ScenePhase::LIVE_MATCH => [...$facts, 'live_games' => $this->liveGames()],
            ScenePhase::AFTER_RESULT => [...$facts, 'results' => $this->results()],
            default => [...$facts, 'tournaments_open' => $this->openTournaments()],
        };
    }

    /**
     * The facts of a topic: what its slides show, the league's size, and
     * what the slide is (`slide`). `$night` leaves every player's name out.
     *
     * @return array<string, mixed>
     */
    public function forTopic(string $topic, bool $night = false): array
    {
        $facts = [...$this->basics(), 'slide' => SceneTopic::SLIDES[$topic] ?? $topic];
        // The chess games worth talking about, ranked once for the live games and their moments alike.
        $chess = in_array($topic, [SceneTopic::LIVE_GAMES, SceneTopic::MEMPOOL], true) ? $this->rankedChess() : null;
        $this->ranked = $chess;

        try {
            $data = match ($topic) {
                SceneTopic::LIVE_GAMES => ['live_games' => $this->liveGames(), ...$this->liveCount()],
                SceneTopic::RESULTS => ['results' => $this->results(), ...$this->todayCount()],
                SceneTopic::TOURNAMENTS => ['tournaments_open' => $this->openTournaments($night)],
                SceneTopic::LIVE_TOURNAMENT => ['tournaments_under_way' => $this->tournamentsUnderWay()],
                SceneTopic::LADDERS => ['ladders' => $this->ladders()],
                SceneTopic::CLANS => ['clan_spotlight' => $this->clanSpotlight()],
                SceneTopic::MEMPOOL => ['live_games' => $this->liveGames(), 'results' => $this->results(), ...$this->liveCount(), ...$this->todayCount()],
                SceneTopic::TMNF => ['tmnf_week' => $this->tmnfWeek()],
                SceneTopic::BLOCKFILL => ['blockfill_week' => $this->blockfillWeek()],
                default => $this->leagueTotals(),
            };
        } finally {
            $this->ranked = null;
        }

        return [...$facts, ...$data, ...$this->moments($topic, $night, $chess)];
    }

    /**
     * What the topic's games are doing (GameMoments: material, race standing,
     * leader and gap, comebacks, each with a mood), under `moments`; none
     * when there are none or the read fails.
     *
     * @param  list<array{game: ChessGame, white: string, black: string, moments: list<array<string, string|int>>}>|null  $chess  rankedChess()
     * @return array{moments?: list<array<string, string|int>>}
     */
    protected function moments(string $topic, bool $night, ?array $chess = null): array
    {
        try {
            $moments = app(GameMoments::class)->forTopic($topic, $night, $chess);
        } catch (Throwable) {
            return [];
        }

        return $moments === [] ? [] : ['moments' => $moments];
    }

    /**
     * The running chess games with the most to talk about (GameMoments::rankedChess()), or null when they cannot be read.
     *
     * @return list<array{game: ChessGame, white: string, black: string, moments: list<array<string, string|int>>}>|null
     */
    protected function rankedChess(): ?array
    {
        try {
            return app(GameMoments::class)->rankedChess();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a topic has anything of its own to say (the league's size alone does not count).
     *
     * @param  array<string, mixed>  $facts  forTopic()
     */
    public static function hasData(string $topic, array $facts): bool
    {
        $own = array_diff_key($facts, array_flip(['sender', 'website_spoken', 'players', 'clans', 'slide']));

        if ($topic === SceneTopic::LEAGUE) {
            return ($facts['players'] ?? 0) > 0;
        }

        foreach ($own as $value) {
            if ($value !== [] && $value !== null && $value !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $facts
     */
    public static function hash(array $facts): string
    {
        return hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * A name or title as the voice can say it: compatibility forms folded
     * ("𝕞ptf" is "mptf"), "#1" as "Nummer 1", brackets, hashes and other
     * symbols gone. An empty string or a shortened npub is no speakable name (null).
     */
    public static function spoken(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = (string) (Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text);
        $text = (string) preg_replace('/#\s*(\d+)/u', 'Nummer $1', $text);
        $text = (string) preg_replace('/[#()\[\]{}*`|<>~^…]+/u', '', $text);
        $text = (string) preg_replace('/_+|\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}\x{FE0F}\x{200D}]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', $text));

        return $text === '' || str_starts_with($text, 'npub1') ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    protected function basics(): array
    {
        return [
            'sender' => 'TWENTY ONE TV',
            'website_spoken' => 'esports punkt einundzwanzig punkt space',
            ...$this->league(),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function league(): array
    {
        try {
            return ['players' => User::query()->count(), 'clans' => Clan::query()->count()];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The league's totals as the teaser scenes show them (StreamStats).
     *
     * @return array<string, int>
     */
    protected function leagueTotals(): array
    {
        try {
            $stats = app(StreamStats::class)->all();

            return array_filter(['games_played' => $stats['gamesPlayed'], 'games_today' => $stats['gamesToday']], fn (int $count): bool => $count > 0);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int>
     */
    protected function liveCount(): array
    {
        try {
            $live = app(StreamStats::class)->all()['liveNow'];

            return $live > 0 ? ['live_now' => $live] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, int>
     */
    protected function todayCount(): array
    {
        try {
            $today = app(StreamStats::class)->all()['gamesToday'];

            return $today > 0 ? ['games_today' => $today] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Chess games on now with their mode and move, then the other games the
     * mempool shows running. The chess games are those with the most to talk
     * about (GameMoments::rankedChess(), ranked once per forTopic() call);
     * when they cannot be ranked, the newest.
     *
     * @return list<array<string, mixed>>
     */
    protected function liveGames(): array
    {
        try {
            $games = [];
            $ranked = $this->ranked ?? $this->rankedChess();
            $chessGames = $ranked !== null ? array_map(fn (array $entry): array => [$entry['game'], $entry['white'], $entry['black']], $ranked) : [];

            if ($ranked === null) {
                foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Active)->whereNotNull('white_id')->whereNotNull('black_id')
                    ->orderByDesc('id')->limit(self::LIVE_GAMES * 2)->get() as $game) {
                    $white = self::spoken($game->white->displayName());
                    $black = self::spoken($game->black->displayName());

                    if ($white !== null && $black !== null) {
                        $chessGames[] = [$game, $white, $black];
                    }
                }
            }

            foreach (array_slice($chessGames, 0, self::LIVE_GAMES) as [$game, $white, $black]) {
                $games[] = ['game' => 'Schach', 'match' => MatchAnchor::of($game, 'Schach', self::MODES[$game->mode] ?? null), 'white' => $white, 'black' => $black, 'mode' => self::MODES[$game->mode] ?? ucfirst($game->mode), 'move' => intdiv((int) $game->ply, 2) + 1];
            }

            foreach ($this->mempool()['running'] ?? [] as $cube) {
                $sides = $this->sideNames($cube);

                if (($cube['game'] ?? null) !== 'chess' && count($sides) === 2 && count($games) < self::LIVE_GAMES + 1) {
                    $games[] = ['game' => self::gameName((string) ($cube['name'] ?? '')), 'a' => $sides[0], 'b' => $sides[1]];
                }
            }

            return $games;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Chess results of the last day (winner, loser or a draw, the mode, the
     * moves and how it ended), then the other games' results the mempool shows.
     *
     * @return list<array<string, mixed>>
     */
    protected function results(): array
    {
        try {
            $results = [];

            foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Finished)->whereNotNull('white_id')->whereNotNull('black_id')
                ->where('ended_at', '>=', now()->subHours(self::RESULT_HOURS))->orderByDesc('ended_at')->limit(self::RESULTS * 2)->get() as $game) {
                $white = self::spoken($game->white->displayName());
                $black = self::spoken($game->black->displayName());
                $reason = $game->end_reason instanceof ChessEndReason ? $game->end_reason->value : null;

                if ($white === null || $black === null || in_array($reason, ['aborted', 'voided'], true) || count($results) >= self::RESULTS) {
                    continue;
                }

                $common = array_filter(['game' => 'Schach', 'mode' => self::MODES[$game->mode] ?? ucfirst($game->mode), 'moves' => intdiv((int) $game->ply + 1, 2), 'how' => self::END_REASONS[$reason] ?? null], fn (mixed $value): bool => $value !== null && $value !== 0);
                $results[] = match ($game->result) {
                    '1-0' => ['winner' => $white, 'loser' => $black, ...$common],
                    '0-1' => ['winner' => $black, 'loser' => $white, ...$common],
                    default => ['draw' => [$white, $black], ...$common],
                };
            }

            foreach ($this->mempool()['finished'] ?? [] as $cube) {
                $sides = (array) ($cube['sides'] ?? []);

                if (($cube['game'] ?? null) === 'chess' || count($sides) !== 2 || count($results) >= self::RESULTS + 2) {
                    continue;
                }

                $winner = collect($sides)->firstWhere('won', true);
                $loser = collect($sides)->firstWhere('won', false);
                $winnerName = is_array($winner) ? self::spoken((string) ($winner['name'] ?? '')) : null;
                $loserName = is_array($loser) ? self::spoken((string) ($loser['name'] ?? '')) : null;

                if ($winnerName !== null && $loserName !== null) {
                    $results[] = array_filter(['game' => self::gameName((string) ($cube['name'] ?? '')), 'winner' => $winnerName, 'loser' => $loserName, 'score' => self::score((string) ($cube['score'] ?? ''))]);
                }
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Tournaments open for sign-up, as their hero slide shows them: name,
     * game and mode, the start (Berlin time), places taken and free, the pot,
     * the first names on the roster (not at night).
     *
     * @return list<array<string, mixed>>
     */
    protected function openTournaments(bool $night = false): array
    {
        try {
            $open = [];
            $nowMs = (int) (microtime(true) * 1000);

            foreach ($this->tournaments->upcoming()->take(self::TOURNAMENTS) as $tournament) {
                $data = $this->tournaments->data($tournament, $nowMs);
                $roster = $night ? [] : array_values(array_filter(array_map(fn (mixed $row): ?string => is_array($row) ? self::spoken((string) ($row['name'] ?? '')) : null, (array) ($data['roster'] ?? []))));

                $open[] = array_filter([
                    'name' => self::spoken($tournament->name),
                    'game' => self::gameName((string) ($data['game'] ?? $tournament->game)),
                    'mode' => self::spoken((string) ($data['mode'] ?? '')),
                    'start' => self::spokenTournamentStart($tournament),
                    'places' => (int) ($data['places'] ?? 0),
                    'taken' => (int) ($data['taken'] ?? 0),
                    'spots_left' => (int) ($data['spotsLeft'] ?? 0),
                    'pot_sats' => is_numeric($data['pot'] ?? null) ? (int) $data['pot'] : null,
                    'roster' => array_slice($roster, 0, self::ROWS),
                ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
            }

            return $open;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Tournaments past their sign-up (TournamentLiveSlides): phase, game,
     * progress, the table's top rows, the latest results, the champion.
     *
     * @return list<array<string, mixed>>
     */
    protected function tournamentsUnderWay(): array
    {
        try {
            $under = [];

            foreach (array_slice(app(TournamentLiveSlides::class)->all((int) (microtime(true) * 1000)), 0, 2) as $frame) {
                $rows = [];
                foreach (array_slice((array) ($frame['board']['rows'] ?? []), 0, self::ROWS) as $row) {
                    $name = is_array($row) ? self::spoken((string) ($row['name'] ?? '')) : null;
                    if ($name !== null) {
                        $rows[] = ['rank' => (int) $row['rank'], 'name' => $name, 'points' => (int) ($row['points'] ?? 0)];
                    }
                }

                $results = [];
                foreach (array_slice((array) ($frame['results'] ?? []), 0, self::ROWS) as $result) {
                    $winner = self::spoken((string) ($result['winner']['name'] ?? ''));
                    $loser = self::spoken((string) ($result['loser']['name'] ?? ''));
                    if ($winner !== null && $loser !== null && ($result['draw'] ?? false) !== true) {
                        $results[] = array_filter(['winner' => $winner, 'loser' => $loser, 'score' => self::score((string) ($result['label'] ?? '')), 'round' => self::round((string) ($result['round'] ?? ''))]);
                    }
                }

                $under[] = array_filter([
                    'name' => self::spoken((string) ($frame['name'] ?? '')),
                    'state' => match ($frame['phase'] ?? null) {
                        'running' => 'im Gange',
                        'drawing' => 'in der Auslosung',
                        'finished' => 'beendet',
                        default => null,
                    },
                    'game' => self::gameName((string) ($frame['game'] ?? '')),
                    'entrants' => (int) ($frame['entrants'] ?? 0),
                    'played' => (int) ($frame['progress']['played'] ?? 0),
                    'total' => (int) ($frame['progress']['total'] ?? 0),
                    'table' => $rows,
                    'results' => $results,
                    'champion' => self::spoken(is_array($frame['champion'] ?? null) ? (string) ($frame['champion']['name'] ?? '') : null),
                ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== 0);
            }

            return $under;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The ladders' top rows as the ladder slides show them (StreamStats
     * `boards`), with the Elo gap between the first two.
     *
     * @return list<array<string, mixed>>
     */
    protected function ladders(): array
    {
        try {
            $ladders = [];

            foreach (app(StreamStats::class)->all()['boards'] as $board) {
                $rows = [];
                foreach ((array) ($board['rows'] ?? []) as $row) {
                    $name = self::spoken((string) ($row['name'] ?? ''));
                    if ($name !== null && count($rows) < self::ROWS) {
                        $rows[] = ['rank' => (int) $row['rank'], 'name' => $name, 'elo' => (int) $row['elo'], 'wins' => (int) $row['wins'], 'losses' => (int) $row['losses']];
                    }
                }

                if ($rows !== [] && count($ladders) < self::LADDERS) {
                    $ladder = ['ladder' => trim(self::gameName((string) ($board['gameName'] ?? '')).' '.self::spoken((string) ($board['modeName'] ?? ''))), 'top' => $rows];
                    // A gap only between the real first and second (a row the voice cannot name is left out, so the top row may be second).
                    if (count($rows) >= 2 && $rows[0]['rank'] === 1 && $rows[1]['rank'] === 2) {
                        $ladder['gap_first_second'] = $rows[0]['elo'] - $rows[1]['elo'];
                    }
                    $ladders[] = $ladder;
                }
            }

            return $ladders;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function clanSpotlight(): array
    {
        try {
            $clan = app(StreamStats::class)->all()['clan'];

            return $clan === null ? [] : array_filter(['name' => self::spoken($clan['name']), 'tag' => self::spoken($clan['tag']), 'members' => $clan['members'], 'games' => $clan['games']]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Trackmania's week (TmnfSlides): track, author time, the top of the board.
     *
     * @return array<string, mixed>
     */
    protected function tmnfWeek(): array
    {
        try {
            $data = app(TmnfSlides::class)->data();

            if ($data === null || ($data['state'] ?? null) !== 'running') {
                return [];
            }

            $board = [];
            foreach ((array) ($data['lanes'] ?? []) as $lane) {
                $name = self::spoken((string) ($lane['name'] ?? ''));
                if ($name !== null && count($board) < self::ROWS) {
                    $board[] = ['place' => (int) $lane['place'], 'name' => $name, 'time' => self::seconds((string) $lane['time']), 'under_author_time' => (bool) ($lane['under'] ?? false)];
                }
            }

            return array_filter([
                'track' => self::spoken((string) ($data['track'] ?? '')),
                'author_time' => self::seconds((string) ($data['authorTime'] ?? '')),
                'drivers' => (int) ($data['players'] ?? 0),
                'board' => $board,
            ]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Blockfill's week (BlockfillSlides): players and the top of the board.
     *
     * @return array<string, mixed>
     */
    protected function blockfillWeek(): array
    {
        try {
            $data = app(BlockfillSlides::class)->data();

            if ($data === null || ($data['week'] ?? null) !== BlockfillSlides::RUNNING) {
                return [];
            }

            $board = [];
            foreach ((array) ($data['board'] ?? []) as $row) {
                $name = self::spoken((string) ($row['name'] ?? ''));
                if ($name !== null && is_int($row['ms'] ?? null) && count($board) < self::ROWS) {
                    $board[] = ['place' => (int) $row['place'], 'name' => $name, 'time' => self::seconds(number_format($row['ms'] / 1000, 3, '.', ''))];
                }
            }

            return array_filter(['players' => (int) ($data['players'] ?? 0), 'board' => $board]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * MempoolSlides::read(), once per facts object.
     *
     * @return array<string, mixed>
     */
    private function mempool(): array
    {
        return $this->mempoolData ??= (function (): array {
            try {
                return app(MempoolSlides::class)->read();
            } catch (Throwable) {
                return [];
            }
        })();
    }

    /**
     * @param  array<string, mixed>  $cube
     * @return list<string>
     */
    private function sideNames(array $cube): array
    {
        return array_values(array_filter(array_map(fn (mixed $side): ?string => is_array($side) ? self::spoken((string) ($side['name'] ?? '')) : null, (array) ($cube['sides'] ?? []))));
    }

    private static function gameName(string $name): string
    {
        return self::GAME_NAMES[$name] ?? (self::spoken($name) ?? $name);
    }

    /** "2–0" spoken: "2 zu 0"; anything else is no score (null). */
    private static function score(string $label): ?string
    {
        return preg_match('/^\s*(\d+)\s*[–:-]\s*(\d+)\s*$/u', $label, $match) === 1 ? "{$match[1]} zu {$match[2]}" : null;
    }

    /** "Round 3" spoken: 3. */
    private static function round(string $label): ?int
    {
        return preg_match('/(\d+)/', $label, $match) === 1 ? (int) $match[1] : null;
    }

    /** "0:16.130" spoken: "16,13 Sekunden" (minutes when there are any). */
    private static function seconds(string $time): ?string
    {
        if (preg_match('/^(?:(\d+):)?(\d+)\.(\d+)$/', trim($time), $match) !== 1) {
            return null;
        }

        $fraction = rtrim($match[3], '0');
        $seconds = ((int) $match[2]).($fraction === '' ? '' : ','.$fraction).' Sekunden';

        return (int) $match[1] > 0 ? ((int) $match[1]).' Minuten '.$seconds : $seconds;
    }

    /**
     * A tournament's start as the voice says it; while its start is put to a
     * vote (TournamentStartVote) every offered start and the vote's close, so
     * the voice never names the earliest as the start: "per Abstimmung: am
     * Freitag den 20. November um 20 Uhr oder am Samstag …, die Abstimmung
     * endet am Mittwoch …".
     */
    private static function spokenTournamentStart(Tournament $tournament): string
    {
        if (! $tournament->hasOpenStartVote()) {
            return self::spokenStart(CarbonImmutable::instance($tournament->starts_at));
        }

        $options = array_map(self::spokenStart(...), TournamentStartVote::options($tournament));
        $last = array_pop($options);
        $until = CarbonImmutable::instance($tournament->start_vote_until ?? $tournament->starts_at);

        return 'per Abstimmung: '.($options === [] ? '' : implode(', ', $options).' oder ').$last.', die Abstimmung endet '.self::spokenStart($until);
    }

    /** A start in Berlin time, spoken: "am Sonntag den 11. Oktober um 20 Uhr" (no comma: a repair cut there split the date off). */
    private static function spokenStart(CarbonImmutable $start): string
    {
        $local = $start->setTimezone('Europe/Berlin');
        $time = $local->minute === 0 ? $local->hour.' Uhr' : $local->format('G:i').' Uhr';

        return 'am '.self::WEEKDAYS[$local->dayOfWeekIso - 1].' den '.$local->day.'. '.self::MONTHS[$local->month - 1].' um '.$time;
    }
}
