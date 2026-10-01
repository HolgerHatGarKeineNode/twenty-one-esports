<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentTv;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The tournaments past their sign-up that the stream's live tournament slides
 * show (resources/views/stream/rotation/t{a,b,c}{3..7}), each in its phase:
 *
 * - `drawing`: sign-up closed, the draw waits for its Bitcoin block: the
 *   field (seeded as the draw will seed it), the block, the start;
 * - `running`: the real bracket or table of the current stage with its
 *   results (TournamentTv, the same stored bracket as the tournament page),
 *   the matches live now, who is still standing, the latest results and the
 *   biggest upset;
 * - `finished` within `twentyone.stream.rotation.finished_tournament_hours`
 *   of its last result: the champion, the podium, the champion's path, the
 *   final bracket, and the pot if there is one.
 *
 * Casual cups are tournaments here like any other once they run. A called-off
 * tournament never shows, nor one whose game is switched off (a board game
 * while the board games are off: GameRegistry no longer knows it). Nothing is
 * invented: a phase without its data (no champion readable, a stage of heats)
 * leaves that part null and the slide says less.
 *
 * Every tournament also carries `howItRuns` (TournamentPlaybook) and, while
 * running, `now`: the current round in words.
 *
 * Cached like TournamentSlides (`twentyone.stream.stats.cache_seconds`): the
 * bracket is read on the poll, never per frame. Pictures join in frame()
 * from the plain refs the snapshot keeps per participant, covers once per
 * game for the process lifetime.
 */
class TournamentLiveSlides
{
    public const CACHE_KEY = 'twentyone.stream.tournaments.live';

    /** Matches of the first bracket column shown at most; a bigger round is cropped around its live matches. */
    public const WINDOW = 4;

    /** Bracket columns shown at most (the current round and the next two). */
    public const COLUMNS = 3;

    /** Table rows shown at most. */
    public const TABLE_ROWS = 8;

    /** Faces of who is still standing, at most. */
    public const STANDING = 16;

    /** Latest results kept. */
    public const RESULTS = 5;

    /** A win counts as an upset from this many seeds apart. */
    public const UPSET_GAP = 2;

    /** @var array<string, string|null> game slug => cover as a data URI, read once per slug */
    private static array $covers = [];

    /** Phases in the order the rotation takes them. */
    public const PHASES = ['running', 'drawing', 'finished'];

    public function __construct(
        private GameRegistry $games,
        private StreamImages $images,
        private TournamentPlacements $placements,
        private PrizePool $pool,
    ) {}

    /**
     * Drawing and running tournaments and those finished within the window,
     * running first (earliest start first), then drawing, then finished
     * (latest update first); only games the league has switched on.
     *
     * @return Collection<int, Tournament>
     */
    public function tournaments(): Collection
    {
        $hours = max(1, (int) config('twentyone.stream.rotation.finished_tournament_hours', 48));

        // A Blockfill week (plan "Blockfill", P6) has a slide of its own (f1, BlockfillSlide), no bracket.
        return Tournament::query()->exceptBlockfillWeeks()
            ->where(fn ($query) => $query->whereIn('status', [TournamentStatus::Drawing, TournamentStatus::Running])
                ->orWhere(fn ($query) => $query->where('status', TournamentStatus::Finished)->where('updated_at', '>=', now()->subHours($hours))))
            ->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (Tournament $tournament): bool => $this->games->find($tournament->game) !== null)
            ->sortBy(fn (Tournament $tournament): array => [array_search(self::phase($tournament), self::PHASES, true), $tournament->status === TournamentStatus::Finished ? -$tournament->updated_at?->getTimestamp() : 0])
            ->values();
    }

    /**
     * The slide data of every live tournament at `$nowMs`, in rotation order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $nowMs): array
    {
        return $this->frames($this->snapshots(), $nowMs);
    }

    /**
     * The database part of all(), cached; read directly when the cache store fails.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshots(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        try {
            return Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->read());
        } catch (Throwable $e) {
            report($e);

            return $this->read();
        }
    }

    /**
     * What snapshots() holds in the cache now, without reading the database:
     * null on a miss or a failing cache store. For a slide that may only
     * reuse what the stream's poll read (the d6 spotlight, P8).
     *
     * @return list<array<string, mixed>>|null
     */
    public function cachedSnapshots(): ?array
    {
        try {
            $snapshots = Cache::get(self::CACHE_KEY);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_array($snapshots) ? array_values(array_filter($snapshots, is_array(...))) : null;
    }

    /**
     * frames of snapshots() read earlier; a finished tournament past the
     * window by `$nowMs` is left out.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, mixed>>
     */
    public function frames(array $snapshots, int $nowMs): array
    {
        $windowMs = max(1, (int) config('twentyone.stream.rotation.finished_tournament_hours', 48)) * 3_600_000;
        $slides = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot['phase'] === 'finished' && (! is_int($snapshot['finishedMs']) || $nowMs - $snapshot['finishedMs'] > $windowMs)) {
                continue;
            }

            $slides[] = $this->frame($snapshot, $nowMs);
        }

        return $slides;
    }

    /**
     * One tournament's frame, read now (tests and the sample renders).
     *
     * @return array<string, mixed>
     */
    public function data(Tournament $tournament, int $nowMs): array
    {
        return $this->frame($this->snapshot($tournament), $nowMs);
    }

    /**
     * What the planner needs of this poll's frames: id, phase, and whether a
     * next tournament is open for sign-up to point the audience to.
     *
     * @param  list<array<string, mixed>>  $frames  frames() of this poll
     * @param  list<array<string, mixed>>  $upcoming  TournamentSlides::frames() of this poll
     * @return list<array{id: int, phase: string, fomo: bool}>
     */
    public static function entries(array $frames, array $upcoming): array
    {
        $entries = [];

        foreach ($frames as $frame) {
            if (is_int($frame['id'] ?? null) && in_array($frame['phase'] ?? null, self::PHASES, true)) {
                $entries[] = ['id' => $frame['id'], 'phase' => $frame['phase'], 'fomo' => self::next($frame, $upcoming) !== null];
            }
        }

        return $entries;
    }

    /**
     * The tournament to sign up for next, for the audience of `$frame`: the
     * soonest-closing open one of the same game, else the soonest-closing
     * open one at all (a casual cup counts); null when none is open.
     *
     * @param  array<string, mixed>  $frame
     * @param  list<array<string, mixed>>  $upcoming  TournamentSlides::frames()
     * @return array<string, mixed>|null
     */
    public static function next(array $frame, array $upcoming): ?array
    {
        $open = array_values(array_filter($upcoming, fn (array $t): bool => ($t['id'] ?? null) !== ($frame['id'] ?? null) && ($t['spotsLeft'] ?? 0) > 0));

        foreach ($open as $t) {
            if (($t['game'] ?? null) === ($frame['game'] ?? null)) {
                return $t;
            }
        }

        return $open[0] ?? null;
    }

    public static function phase(Tournament $tournament): string
    {
        return match ($tournament->status) {
            TournamentStatus::Drawing => 'drawing',
            TournamentStatus::Finished => 'finished',
            default => 'running',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        return array_values($this->tournaments()->map(fn (Tournament $tournament): array => $this->snapshot($tournament))->all());
    }

    /**
     * The clock-free part of a frame: plain scalars and arrays, so the cache
     * can hold it; `pictures` keeps each participant's picture refs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Tournament $tournament): array
    {
        $timezone = $tournament->isCasualCup() ? CasualCups::timezoneOf($tournament) : (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');
        $phase = self::phase($tournament);
        $tv = new TournamentTv($tournament);
        $paused = $phase === 'running' && $tournament->isPaused();
        $stages = $phase === 'drawing' ? [] : $this->annotate($tournament, $tv->stages(), $paused);
        $pictures = [];
        $entrants = $phase === 'drawing' ? 0 : $tournament->participants()->count();
        $at = fn (?CarbonInterface $moment): ?string => $moment?->copy()->timezone($timezone)->format('D j M, H:i T');
        $snapshot = [
            'id' => $tournament->id,
            'phase' => $phase,
            'status' => match ($phase) {
                'drawing' => 'Draw pending',
                'finished' => 'Finished',
                // Paused by an organizer or an admin (TournamentControl): nothing is played now.
                default => $paused ? 'Paused' : 'Live now',
            },
            'name' => PublicName::clean($tournament->name),
            'game' => GameTitle::of($tournament->game),
            // A lobby tournament (P10) has no mode to name (no "1v1"), and its format is the lobby match.
            'mode' => Lobbies::isLobby($tournament) ? '' : ($this->games->mode($tournament->game, $tournament->mode)->name ?? $tournament->mode),
            'format' => Lobbies::isLobby($tournament) ? 'One lobby match' : $tournament->format->label(),
            'teamSize' => $tournament->teamSize(),
            'rated' => $tournament->openLadder() !== null,
            'where' => $tournament->on_site ? 'On site' : 'Online',
            'cup' => $tournament->isCasualCup(),
            'region' => $tournament->isCasualCup() ? CasualCups::regionLabel($tournament) : null,
            'url' => rtrim((string) config('twentyone.stream.scene.url'), '/').'/tournaments/'.$tournament->id,
            'startsAt' => $at($tournament->starts_at),
            'startsMs' => $tournament->starts_at->getTimestampMs(),
            'entrants' => $entrants,
            'gameSlug' => $tournament->game,
            'pot' => $this->pool->shownPotSats($tournament),
            'drawBlock' => $phase === 'drawing' ? $tournament->draw_height : null,
            'field' => [],
            'taken' => 0,
            'board' => null,
            'now' => null,
            'progress' => null,
            'standing' => null,
            'live' => [],
            'results' => [],
            'upset' => null,
            'champion' => null,
            'podium' => [],
            'path' => [],
            'finishedAt' => null,
            'finishedMs' => null,
            'sharedFirst' => [],
            'podiumMore' => 0,
        ];

        if ($phase === 'drawing') {
            // Who plays, as the draw will seed them (TournamentLanding, before the draw: the sign-ups).
            $landing = new TournamentLanding($tournament, null);
            $snapshot['taken'] = $landing->places()['taken'];

            foreach ($landing->roster() as $row) {
                if ($row['seed'] === null || count($snapshot['field']) >= self::STANDING) {
                    continue;
                }

                $user = in_array($row['kind'], ['player', 'solo'], true) ? ($row['users'][0] ?? null) : null;
                $key = 's'.$row['seed'];
                $pictures[$key] = ['avatar' => StreamImages::avatarRef($user), 'logo' => $row['kind'] === 'lineup' ? StreamImages::logoRef($row['clan']) : null];
                $snapshot['field'][] = ['pic' => $key, 'seed' => $row['seed'], 'name' => PublicName::clean($user?->displayName() ?? $row['name'])];
            }

            $snapshot['howItRuns'] = TournamentPlaybook::of($tournament, max($snapshot['taken'], count($snapshot['field'])), $timezone);

            return [...$snapshot, 'pictures' => $pictures];
        }

        $name = function (?array $entry) use (&$pictures): ?array {
            if ($entry === null) {
                return null;
            }

            $key = 'p'.$entry['id'];
            $pictures[$key] ??= ['avatar' => StreamImages::avatarRef($entry['user']), 'logo' => StreamImages::logoRef($entry['clan'])];

            return ['pic' => $key, 'seed' => $entry['seed'], 'name' => PublicName::clean($entry['user']?->displayName() ?? $entry['name'])];
        };

        $snapshot['progress'] = $tv->progress();
        $snapshot['board'] = $this->board($tournament, $stages, $phase, $tv, $name);
        $snapshot['now'] = $phase === 'running' ? ($snapshot['board']['now'] ?? null) : null;
        $snapshot['howItRuns'] = TournamentPlaybook::of($tournament, max(2, $entrants), $timezone);

        $boxes = [];
        foreach ($stages as $stage) {
            foreach (TournamentTv::boxesOf($stage) as $box) {
                $boxes[$box['key']] = $box;
            }
        }

        if ($phase === 'running') {
            $alive = $tv->contenders();
            $snapshot['standing'] = [
                'count' => count($alive),
                'of' => $entrants,
                'faces' => array_values(array_filter(array_map($name, array_slice($alive, 0, self::STANDING)))),
            ];
            $snapshot['live'] = Lobbies::isLobby($tournament) ? [] : array_map(fn (array $box): array => ['round' => $box['round'], 'sides' => array_values(array_filter(array_map(fn (array $side): ?array => $name($side['entry']), $box['sides'])))],
                TournamentTv::spotlight($stages, 2));
        }

        foreach ($tv->ticker(3 * self::RESULTS) as $item) {
            $box = $boxes[$item['key']] ?? null;
            $result = $box === null ? null : $this->result($box, $name);

            if ($result !== null && count($snapshot['results']) < self::RESULTS) {
                $snapshot['results'][] = $result;
            }
        }

        $snapshot['upset'] = $this->upset($boxes, $name);

        if ($phase === 'finished') {
            $finishedAt = TournamentMatch::query()->where('tournament_id', $tournament->id)->max('updated_at');
            $finished = $finishedAt === null ? ($tournament->cup_ended_at ?? $tournament->updated_at) : Carbon::parse((string) $finishedAt);
            $snapshot['finishedAt'] = $at($finished);
            $snapshot['finishedMs'] = $finished?->getTimestampMs();
            $champion = $tv->champion();
            $snapshot['champion'] = $name($champion);
            // A lobby tournament (P10): place 1 across all its lobbies, shared by the allies left standing.
            $snapshot['sharedFirst'] = Lobbies::isLobby($tournament) ? self::firstPlace($this->placements->of($tournament) ?? [], $tv, $name) : [];
            $snapshot['path'] = $champion === null ? [] : $this->path($boxes, $champion['id'], $name);

            foreach ($this->placements->of($tournament) ?? [] as $row) {
                if ($row['place'] > 3) {
                    break;
                }

                foreach ($row['participants'] as $id) {
                    if (count($snapshot['podium']) >= 4) {
                        // A shared place (P10: up to 8 allies per lobby share place 1) holds more than the slide shows.
                        $snapshot['podiumMore']++;

                        continue;
                    }

                    $entry = $name($tv->entry($id));

                    if ($entry !== null) {
                        $snapshot['podium'][] = ['place' => $row['place'], ...$entry];
                    }
                }
            }
        }

        return [...$snapshot, 'pictures' => $pictures];
    }

    /**
     * The stages with what the stream needs to tell a result honestly: every
     * box gets `outcome` from its stored result (RESULT_*), and `how` for a
     * match decided without a game ("by forfeit"); a paused tournament has no
     * live box. A double elimination names its last rounds (Upper final,
     * Lower final, Grand final, Grand final reset) where the page says
     * "Round N"; the page itself (TournamentView) is not changed.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function annotate(Tournament $tournament, array $stages, bool $paused): array
    {
        $results = TournamentMatch::query()->where('tournament_id', $tournament->id)->pluck('result', 'key')->all();

        foreach ($stages as &$stage) {
            foreach ($stage['parts'] as &$part) {
                if ($part['kind'] === 'bracket') {
                    $double = count($part['sections']) === 3;

                    foreach ($part['sections'] as $si => &$section) {
                        $last = count($section['columns']) - 1;

                        foreach ($section['columns'] as $ci => &$column) {
                            $label = $double ? self::finalName($column['matches'], $si, $ci === $last) : null;
                            $column['label'] = $label ?? $column['label'];

                            foreach ($column['matches'] as &$box) {
                                $box = self::told($box, $results[$box['key']] ?? null, $paused, $label);
                            }
                            unset($box);
                        }
                        unset($column);
                    }
                    unset($section);
                } elseif ($part['kind'] === 'table') {
                    foreach ($part['rounds'] as &$round) {
                        $round = array_map(fn (array $box): array => self::told($box, $results[$box['key']] ?? null, $paused, null), $round);
                    }
                    unset($round);
                } else {
                    $part['heats'] = array_map(fn (array $box): array => self::told($box, $results[$box['key']] ?? null, $paused, null), $part['heats']);
                }
            }
            unset($part);
        }
        unset($stage);

        return $stages;
    }

    /**
     * A double elimination's last rounds by name: the grand final and its reset by their boxes, the last round of the
     * upper (section 0) and the lower bracket (section 1) as their finals; null keeps the page's label.
     *
     * @param  list<array<string, mixed>>  $boxes
     */
    private static function finalName(array $boxes, int $section, bool $last): ?string
    {
        $brackets = array_values(array_unique(array_column($boxes, 'bracket')));

        return match (true) {
            $brackets === ['grand-final'] => 'Grand final',
            $brackets === ['reset'] => 'Grand final reset',
            $last && $section === 0 && $brackets === ['upper'] => 'Upper final',
            $last && $section === 1 && $brackets === ['lower'] => 'Lower final',
            default => null,
        };
    }

    /**
     * One box with its outcome as the stream may tell it, from the stored result:
     * - `void`: voided (FairPlay, linked accounts): kept in its place, told as nothing, no score, no winner;
     * - `double`: both sides missed it (a double no-show): no score, no winner, no draw;
     * - `decided`: a side advanced without a played result (forfeit, disqualification, no-show, on seeding, by lot,
     *   Armageddon): no played score; `how` says how;
     * - `played`: a played result.
     *
     * @param  array<string, mixed>  $box
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private static function told(array $box, ?array $result, bool $paused, ?string $round): array
    {
        $outcome = match (true) {
            $result === null => 'none',
            isset($result['void']) => 'void',
            ($result['double_loss'] ?? false) === true => 'double',
            ($result['forfeit'] ?? false) === true || in_array($result['decided'] ?? null, ['disqualified', 'withdrawn', 'noshow', 'seed', 'acted', 'lot', 'armageddon'], true) => 'decided',
            default => 'played',
        };
        $how = $outcome === 'decided' ? match ($result['decided'] ?? null) {
            'disqualified', 'withdrawn' => ' by forfeit',
            'seed' => ' on seeding',
            'lot' => ' by lot',
            'armageddon' => ' on Armageddon',
            default => ', no-show',
        } : null;

        if ($outcome !== 'played' && $outcome !== 'none') {
            foreach ($box['sides'] as &$side) {
                $side['score'] = null;
                // A voided match names no winner; a double no-show has none.
                $side['won'] = $outcome === 'decided' && $side['won'];
            }
            unset($side);
        }

        return [...$box, 'outcome' => $outcome, 'how' => $how, 'live' => $box['live'] && ! $paused, 'round' => $round ?? $box['round']];
    }

    /**
     * The part of the current stage a slide can draw: the bracket (a window of
     * up to COLUMNS rounds, the first cropped to WINDOW matches around the live
     * ones), the groups (each group's table), or one table with the current
     * round's pairings. Null for heats or no stage.
     *
     * @param  list<array<string, mixed>>  $stages
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array<string, mixed>|null
     */
    private function board(Tournament $tournament, array $stages, string $phase, TournamentTv $tv, callable $name): ?array
    {
        $stage = TournamentTv::currentStage($stages);

        if ($stage === null) {
            return null;
        }

        // A lobby tournament (P10): one panel per lobby with its players; never a pairing.
        if (Lobbies::isLobby($tournament)) {
            return $this->lobbies($tournament, $stage, $tv, $name);
        }

        $tables = array_values(array_filter($stage['parts'], fn (array $part): bool => $part['kind'] === 'table'));
        $brackets = array_values(array_filter($stage['parts'], fn (array $part): bool => $part['kind'] === 'bracket'));
        $twoStage = $tournament->format === TournamentFormat::TwoStage;

        if ($brackets !== []) {
            // Groups played as knockouts (Two Stage): the group with a match to play, else the last.
            $part = $brackets[count($brackets) - 1];

            foreach ($brackets as $candidate) {
                if (self::partOpen($candidate)) {
                    $part = $candidate;

                    break;
                }
            }

            return $this->bracket($part, $stage['title'], $phase, $name);
        }

        if ($tables === []) {
            return null;
        }

        $swiss = $stage['format'] === TournamentFormat::Swiss;
        $advance = $twoStage && $stage['number'] === 1 ? $tournament->formatOptions()->advance : null;

        if (count($tables) > 1) {
            $groups = [];
            $round = null;
            $of = 0;

            foreach ($tables as $part) {
                $current = TournamentTv::tableRound($part);
                $round = $round === null ? $current['number'] ?? null : min($round, $current['number'] ?? $round);
                $of = max($of, count($part['rounds']));
                $groups[] = ['title' => (string) ($part['title'] ?? ''), 'rows' => $this->rows($part['rows'], $advance, self::TABLE_ROWS, $name)];
            }

            return ['kind' => 'groups', 'title' => $stage['title'], 'groups' => $groups, 'advance' => $advance,
                'now' => $round === null ? null : $stage['title'].', round '.$round.' of '.$of];
        }

        $part = $tables[0];
        $current = TournamentTv::tableRound($part);
        $of = $swiss ? ($tournament->formatOptions()->swissRounds ?? Estimator::swissDefault(max(2, count($part['rows'])))) : count($part['rounds']);
        $of = max($of, $current['number'] ?? 0);
        $pairings = [];

        foreach (array_slice($current['boxes'] ?? [], 0, 6) as $box) {
            if ($box['bracket'] !== 'bye') {
                $pairings[] = $this->box($box, $name);
            }
        }

        return ['kind' => 'table', 'title' => $part['title'] ?? $stage['title'], 'rows' => $this->rows($part['rows'], $advance, self::TABLE_ROWS, $name),
            'more' => max(0, count($part['rows']) - self::TABLE_ROWS), 'round' => $current['number'] ?? null, 'of' => $of, 'pairings' => $pairings,
            'now' => $current === null ? null : 'Round '.$current['number'].' of '.$of];
    }

    /**
     * The lobbies of a lobby tournament (P10) as a groups-like board (kind
     * 'lobbies'): a box per lobby titled "Lobby N", its players in slot order
     * while it runs, by place once decided (place 1 shared by the allies left
     * standing, `through`). `now` names the time limit and counts the lobbies
     * decided.
     *
     * Each box keeps its `clock` (P8): when its time limit ends as planned
     * (the report deadline less `report_minutes`, Lobbies::reportBy()), the
     * report deadline, whether a report waits for the directors, whether it
     * is decided and with places; frame() words it as the box's `status`
     * (lobbyStatus()). A waiting report's places stay private: the stream
     * only says that one is in review.
     *
     * @param  array<string, mixed>  $stage
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array<string, mixed>
     */
    private function lobbies(Tournament $tournament, array $stage, TournamentTv $tv, callable $name): array
    {
        $groups = [];
        $decided = 0;
        $limit = null;
        $reportMinutes = max(0, (int) (Lobbies::config($tournament->game)['report_minutes'] ?? 60));

        foreach ($stage['parts'] as $part) {
            foreach ($part['heats'] ?? [] as $box) {
                $done = $box['status'] === 'done';
                $decided += $done ? 1 : 0;
                $rows = [];
                $placed = false;

                foreach (array_values($box['sides']) as $index => $side) {
                    $entry = $name($side['entry'] ?? null);
                    $score = (string) ($side['score'] ?? '');
                    $place = $done && str_starts_with($score, '#') ? (int) substr($score, 1) : null;
                    $placed = $placed || $place !== null;
                    $rows[] = ['pic' => $entry['pic'] ?? null, 'rank' => $place, 'live' => $place === null, 'index' => $index,
                        'name' => $entry['name'] ?? PublicName::clean((string) ($side['name'] ?? '')), 'points' => '', 'through' => $place === 1];
                }

                $match = $tv->match((string) $box['key']);
                $lobby = is_array($match?->lobby) ? $match->lobby : [];
                $limit ??= is_int($lobby['time_limit_minutes'] ?? null) ? $lobby['time_limit_minutes'] : null;
                $reportBy = is_string($lobby['report_by'] ?? null) ? Carbon::parse($lobby['report_by']) : null;

                usort($rows, fn (array $a, array $b): int => [$a['rank'] ?? 99, $a['index']] <=> [$b['rank'] ?? 99, $b['index']]);
                $groups[] = ['title' => (string) $box['round'], 'rows' => array_map(fn (array $row): array => array_diff_key($row, ['index' => true]), $rows),
                    'clock' => [
                        'live' => (bool) $box['live'],
                        'decided' => $done,
                        'placed' => $placed,
                        'reported' => ! $done && LobbyResults::currentReport($match?->lobby_report) !== null,
                        'endsMs' => $reportBy?->copy()->subMinutes($reportMinutes)->getTimestampMs(),
                        'reportByMs' => $reportBy?->getTimestampMs(),
                    ]];
            }
        }

        $limit ??= Lobbies::timeLimit($tournament->game);

        return ['kind' => 'lobbies', 'title' => $stage['title'], 'groups' => $groups,
            'now' => 'One lobby match, '.self::minutes($limit).' time limit, '.$decided.' of '.count($groups).' '.(count($groups) === 1 ? 'lobby' : 'lobbies').' decided',
            // The time limit alone, for a slide that counts the lobbies decided elsewhere (tb4).
            'limit' => self::minutes($limit).' time limit'];
    }

    /** "2 h", "90 min": a time limit in the stream's words. */
    private static function minutes(int $minutes): string
    {
        return $minutes % 60 === 0 ? intdiv($minutes, 60).' h' : $minutes.' min';
    }

    /**
     * A lobby's state at `$nowMs` in a few words (P8), from its `clock`:
     * "1:12 left" of the time limit as planned, then "report due 38:00"
     * until the deadline, "overdue" after it (the directors decide); a
     * report waiting for them "in review"; "decided", or "no result" for a
     * lobby closed without places. Empty for a lobby not in play; "paused"
     * while its tournament is.
     *
     * @param  array<string, mixed>  $clock
     */
    public static function lobbyStatus(array $clock, int $nowMs, bool $paused = false): string
    {
        if (($clock['decided'] ?? false) === true) {
            return ($clock['placed'] ?? false) === true ? 'decided' : 'no result';
        }

        if (($clock['reported'] ?? false) === true) {
            return 'in review';
        }

        if (($clock['live'] ?? false) !== true) {
            return '';
        }

        if ($paused) {
            return 'paused';
        }

        $ends = $clock['endsMs'] ?? null;
        $due = $clock['reportByMs'] ?? null;

        if (is_int($ends) && $nowMs < $ends) {
            return SceneLayout::clock($ends - $nowMs).' left';
        }

        if (is_int($due) && $nowMs < $due) {
            return 'report due '.SceneLayout::clock($due - $nowMs);
        }

        return is_int($due) ? 'overdue' : '';
    }

    /**
     * The names on place 1 of a finished lobby tournament, in placement order.
     *
     * @param  list<array{place: int, participants: list<int>}>  $placements
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return list<string>
     */
    private static function firstPlace(array $placements, TournamentTv $tv, callable $name): array
    {
        $names = [];

        foreach ($placements as $row) {
            if ($row['place'] !== 1) {
                continue;
            }

            foreach ($row['participants'] as $id) {
                $entry = $name($tv->entry($id));

                if ($entry !== null) {
                    $names[] = $entry['name'];
                }
            }
        }

        return $names;
    }

    /**
     * A bracket part as columns of boxes: the section with the most live
     * matches (the last one once finished), from its current round on.
     *
     * @param  array<string, mixed>  $part
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array<string, mixed>|null
     */
    private function bracket(array $part, string $stageTitle, string $phase, callable $name): ?array
    {
        $sections = $part['sections'];

        if ($sections === []) {
            return null;
        }

        $pick = count($sections) - 1;

        if ($phase === 'running') {
            $best = -1;

            foreach ($sections as $index => $section) {
                $live = 0;
                $open = false;

                foreach ($section['columns'] as $column) {
                    $live += count(array_filter($column['matches'], fn (array $box): bool => $box['live']));
                    $open = $open || self::open($column['matches']);
                }

                if ($open && $live > $best) {
                    [$best, $pick] = [$live, $index];
                }
            }
        }

        $section = $sections[$pick];
        // Columns without a match that is (or was) played: a grand-final reset that was not needed.
        $columns = array_values(array_filter(array_map(fn (array $column): array => [...$column, 'matches' => array_values(array_filter($column['matches'], fn (array $box): bool => $box['status'] !== 'skipped'))], $section['columns']),
            fn (array $column): bool => $column['matches'] !== []));

        // The grand final gets its feeders in front: the upper and the lower final.
        if (count($sections) === 3 && $columns !== [] && in_array($columns[0]['matches'][0]['bracket'] ?? null, ['grand-final', 'reset'], true)) {
            $finals = [];
            foreach ([0, 1] as $feeder) {
                $last = $sections[$feeder]['columns'][count($sections[$feeder]['columns']) - 1] ?? null;
                if ($last !== null && count($last['matches']) === 1) {
                    $finals[] = $last['matches'][0];
                }
            }
            if (count($finals) === 2) {
                array_unshift($columns, ['label' => 'Finals', 'matches' => $finals]);
            }
        }

        if ($columns === []) {
            return null;
        }

        $current = count($columns) - 1;
        foreach ($columns as $index => $column) {
            if (self::open($column['matches'])) {
                $current = $index;

                break;
            }
        }

        $start = max(0, min($current, count($columns) - self::COLUMNS));
        $shown = array_slice($columns, $start, self::COLUMNS);
        $first = $shown[0]['matches'];
        $size = min(count($first), self::WINDOW);
        $focus = 0;

        foreach ($first as $index => $box) {
            if ($box['live'] || in_array($box['status'], ['ready', 'waiting'], true)) {
                $focus = $index;

                break;
            }
        }

        $lo = intdiv($focus, $size) * $size;
        $hi = min(count($first), $lo + $size);
        $out = [];
        $previous = count($first);

        foreach ($shown as $index => $column) {
            $count = count($column['matches']);

            if ($index > 0) {
                // The same part of the tree in the next round: halving rounds halve the window, equal ones keep it.
                $lo = (int) floor($lo * $count / $previous);
                $hi = max($lo + 1, (int) ceil($hi * $count / $previous));
                $hi = min($hi, $count);
            }

            $out[] = [
                'label' => (string) $column['label'],
                'current' => $start + $index === $current && $phase === 'running',
                'total' => $count,
                'from' => $lo,
                'matches' => array_map(fn (array $box): array => $this->box($box, $name), array_slice($column['matches'], $lo, $hi - $lo)),
            ];
            $previous = $count;
        }

        $title = $section['title'] ?? ($part['title'] ?? null) ?? $stageTitle;

        return ['kind' => 'bracket', 'title' => (string) $title, 'columns' => $out,
            'now' => $phase === 'running' ? $columns[$current]['label'].(($section['title'] ?? null) !== null && ! str_contains(mb_strtolower($columns[$current]['label']), 'final') ? ', '.mb_strtolower((string) $section['title']) : '') : null];
    }

    /**
     * Whether a bracket part has a match still to play.
     *
     * @param  array<string, mixed>  $part
     */
    private static function partOpen(array $part): bool
    {
        foreach ($part['sections'] as $section) {
            foreach ($section['columns'] as $column) {
                if (self::open($column['matches'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $boxes
     */
    private static function open(array $boxes): bool
    {
        foreach ($boxes as $box) {
            if (in_array($box['status'], ['ready', 'waiting'], true) && $box['bracket'] !== 'bye') {
                return true;
            }
        }

        return false;
    }

    /**
     * One match as the slides draw it: its sides (a known one with its seed
     * and picture ref; an unknown one with where it comes from), their scores,
     * the winner, and its state.
     *
     * @param  array<string, mixed>  $box
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array{key: string, state: string, label: string|null, from: list<string|null>, sides: list<array{pic: string|null, seed: int|null, name: string, known: bool, score: string|null, won: bool}>}
     */
    private function box(array $box, callable $name): array
    {
        $sides = [];

        foreach ($box['sides'] as $side) {
            $entry = $name($side['entry'] ?? null);
            $sides[] = [
                'pic' => $entry['pic'] ?? null,
                'seed' => $entry['seed'] ?? null,
                'name' => $entry['name'] ?? PublicName::clean((string) $side['name']),
                'known' => $entry !== null,
                'score' => self::half($side['score']),
                'won' => (bool) $side['won'],
            ];
        }

        return [
            'key' => (string) $box['key'],
            'state' => match (true) {
                $box['bracket'] === 'bye' => 'bye',
                ($box['outcome'] ?? null) === 'void' => 'void',
                $box['live'] => 'live',
                $box['status'] === 'done' => 'done',
                default => 'waiting',
            },
            'label' => ($box['outcome'] ?? 'played') === 'played' ? $box['label'] : null,
            'how' => $box['how'] ?? null,
            'from' => array_values($box['from'] ?? []),
            'sides' => $sides,
        ];
    }

    /**
     * Table rows as the slides draw them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return list<array{pic: string|null, rank: int, name: string, points: string, record: string, through: bool}>
     */
    private function rows(array $rows, ?int $advance, int $limit, callable $name): array
    {
        $out = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $entry = $name($row['participant'] ?? null);
            $out[] = [
                'pic' => $entry['pic'] ?? null,
                'rank' => (int) $row['rank'],
                'name' => $entry['name'] ?? PublicName::clean((string) $row['name']),
                'points' => (string) self::half((string) $row['points']),
                'record' => $row['wins'].'-'.$row['ties'].'-'.$row['losses'],
                'through' => $advance !== null && $row['rank'] <= $advance,
            ];
        }

        return $out;
    }

    /**
     * A match as a result line: a played one with its score from the winner's view, one decided without a game with
     * `how` ("by forfeit") and no score, never an upset. Null for a bye, a voided match, a double no-show or a match
     * without two known sides.
     *
     * @param  array<string, mixed>  $box
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array{winner: array{pic: string, seed: int|null, name: string}, loser: array{pic: string, seed: int|null, name: string}, label: string|null, draw: bool, upset: bool, round: string}|null
     */
    private function result(array $box, callable $name): ?array
    {
        if ($box['bracket'] === 'bye' || $box['status'] !== 'done' || count($box['sides']) !== 2 || in_array($box['outcome'] ?? null, ['void', 'double'], true)) {
            return null;
        }

        $decided = ($box['outcome'] ?? null) === 'decided';
        $a = $name($box['sides'][0]['entry'] ?? null);
        $b = $name($box['sides'][1]['entry'] ?? null);

        if ($a === null || $b === null) {
            return null;
        }

        $draw = ! $box['sides'][0]['won'] && ! $box['sides'][1]['won'];
        $first = $box['sides'][1]['won'] ? 1 : 0;
        [$winner, $loser] = $first === 1 ? [$b, $a] : [$a, $b];

        return [
            'winner' => $winner, 'loser' => $loser, 'label' => $decided ? null : self::score($box, $first), 'draw' => $draw && ! $decided, 'round' => (string) ($box['round'] ?? ''),
            'how' => $decided ? $box['how'] : null,
            'upset' => ! $decided && ! $draw && is_int($winner['seed']) && is_int($loser['seed']) && $winner['seed'] - $loser['seed'] >= self::UPSET_GAP,
        ];
    }

    /**
     * The score from one side's view ("2–1" for the side that won 2 games),
     * the stored label when a side has no score.
     *
     * @param  array<string, mixed>  $box
     */
    private static function score(array $box, int $side): ?string
    {
        $own = $box['sides'][$side]['score'] ?? null;
        $other = $box['sides'][1 - $side]['score'] ?? null;

        return is_string($own) && is_string($other) ? self::half($own).'–'.self::half($other) : (is_string($box['label'] ?? null) ? $box['label'] : null);
    }

    /**
     * A half point as the stream's fonts can set it: "2½" is "2.5", "½" is "0.5" (the fonts' fold of "½" would read
     * as "1/2" and "2½" as "21/2").
     */
    private static function half(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '½')) {
            return $value;
        }

        $whole = str_replace('½', '', $value);

        return ($whole === '' ? '0' : $whole).'.5';
    }

    /**
     * The biggest upset so far: the played match whose winner was seeded
     * furthest below the loser (at least UPSET_GAP), the latest round first on a tie.
     *
     * @param  array<string, array<string, mixed>>  $boxes
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return array<string, mixed>|null
     */
    private function upset(array $boxes, callable $name): ?array
    {
        $best = null;
        $gap = self::UPSET_GAP - 1;

        foreach (array_reverse($boxes) as $box) {
            $result = $this->result($box, $name);

            if ($result !== null && $result['upset'] && $result['winner']['seed'] - $result['loser']['seed'] > $gap) {
                $gap = $result['winner']['seed'] - $result['loser']['seed'];
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * The champion's played matches in bracket order: round, opponent, score (voided and decided ones left out).
     *
     * @param  array<string, array<string, mixed>>  $boxes
     * @param  callable(array<string, mixed>|null): (array{pic: string, seed: int|null, name: string}|null)  $name
     * @return list<array{round: string, opponent: string, label: string|null, won: bool, draw: bool}>
     */
    private function path(array $boxes, int $champion, callable $name): array
    {
        $path = [];

        foreach ($boxes as $box) {
            $index = array_search($champion, $box['ids'] ?? [], true);

            // Only what was played: a voided match, a forfeit or a no-show is no step of the path.
            if ($index === false || $box['bracket'] === 'bye' || $box['status'] !== 'done' || count($box['sides']) !== 2 || ($box['outcome'] ?? 'played') !== 'played') {
                continue;
            }

            $other = $name($box['sides'][1 - (int) $index]['entry'] ?? null);

            if ($other === null) {
                continue;
            }

            $draw = ! $box['sides'][0]['won'] && ! $box['sides'][1]['won'];
            $path[] = ['round' => (string) ($box['round'] ?? ''), 'opponent' => $other['name'], 'label' => self::score($box, (int) $index), 'won' => (bool) $box['sides'][(int) $index]['won'], 'draw' => $draw];
        }

        return array_slice($path, -6);
    }

    /**
     * A snapshot at `$nowMs`: every picture ref becomes its data URI, the
     * start countdown ticks, the cover and backdrop join, internal keys go.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function frame(array $snapshot, int $nowMs): array
    {
        $pictures = is_array($snapshot['pictures'] ?? null) ? $snapshot['pictures'] : [];
        $slug = (string) $snapshot['gameSlug'];
        $face = fn (mixed $entry): mixed => $this->pictured($entry, $pictures);
        unset($snapshot['pictures'], $snapshot['gameSlug']);

        $snapshot['field'] = array_map($face, $snapshot['field']);
        $snapshot['live'] = array_map(fn (array $match): array => [...$match, 'sides' => array_map($face, $match['sides'])], $snapshot['live']);
        $snapshot['results'] = array_map(fn (array $result): array => [...$result, 'winner' => $face($result['winner']), 'loser' => $face($result['loser'])], $snapshot['results']);
        $snapshot['podium'] = array_map($face, $snapshot['podium']);
        $snapshot['champion'] = $face($snapshot['champion']);

        if (is_array($snapshot['upset'])) {
            $snapshot['upset'] = [...$snapshot['upset'], 'winner' => $face($snapshot['upset']['winner']), 'loser' => $face($snapshot['upset']['loser'])];
        }

        if (is_array($snapshot['standing'])) {
            $snapshot['standing']['faces'] = array_map($face, $snapshot['standing']['faces']);
        }

        $board = $snapshot['board'];

        if (is_array($board)) {
            if ($board['kind'] === 'bracket') {
                foreach ($board['columns'] as $c => $column) {
                    foreach ($column['matches'] as $m => $match) {
                        $board['columns'][$c]['matches'][$m]['sides'] = array_map($face, $match['sides']);
                    }
                }
            } elseif ($board['kind'] === 'groups' || $board['kind'] === 'lobbies') {
                foreach ($board['groups'] as $g => $group) {
                    $board['groups'][$g]['rows'] = array_map($face, $group['rows']);

                    // A lobby's countdown and report state at this frame (P8); a finished tournament's lobbies need none.
                    if (is_array($group['clock'] ?? null)) {
                        $board['groups'][$g]['status'] = $snapshot['phase'] === 'running' ? self::lobbyStatus($group['clock'], $nowMs, $snapshot['status'] === 'Paused') : '';
                        unset($board['groups'][$g]['clock']);
                    }
                }
            } else {
                $board['rows'] = array_map($face, $board['rows']);
                $board['pairings'] = array_map(fn (array $match): array => [...$match, 'sides' => array_map($face, $match['sides'])], $board['pairings']);
            }

            $snapshot['board'] = $board;
        }

        $startsMs = $snapshot['startsMs'];
        unset($snapshot['startsMs'], $snapshot['finishedMs']);

        return [
            ...$snapshot,
            // Only while it is ahead (a drawing tournament waits for its start): "Starts in".
            'countdown' => is_int($startsMs) && $startsMs > $nowMs && $snapshot['phase'] === 'drawing' ? TournamentSlides::countdown($startsMs, $nowMs) : null,
            'cover' => self::$covers[$slug] ??= TournamentSlides::coverUri($this->games->coverPath($slug)),
            'backdrop' => $this->images->backdrop($slug),
        ];
    }

    /**
     * An entry with its avatar and logo data URIs in place of its picture ref.
     *
     * @param  array<string, array{avatar: array{id: int, pubkey: string, source: string|null}|null, logo: string|null}>  $pictures
     */
    private function pictured(mixed $entry, array $pictures): mixed
    {
        if (! is_array($entry)) {
            return $entry;
        }

        $picture = is_string($entry['pic'] ?? null) ? ($pictures[$entry['pic']] ?? null) : null;
        unset($entry['pic']);

        return [...$entry, 'avatar' => $this->images->avatar($picture['avatar'] ?? null), 'logo' => $this->images->logo($picture['logo'] ?? null)];
    }
}
