<?php

namespace App\Livewire;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\DurationRange;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\Evaluation;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\NightPause;
use App\Support\Tournaments\TournamentDeadlines;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The state and actions of the format chooser
 * (resources/views/pages/admin/partials/tournament-chooser.blade.php), shared
 * by the create page and the edit page: game and mode, participants, time,
 * where, the organizer's own times, the format with its options, and who
 * enters results. Every change re-renders the `summary` island of the page.
 *
 * @property-read Evaluation $evaluation
 * @property-read TournamentFormat $format
 * @property-read DurationRange|null $durationRange
 * @property-read Collection<int, User> $directors
 */
abstract class TournamentFormatChooser extends Component
{
    /** The islands the chooser stands in on the create and edit pages (pages.admin.partials.tournament-chooser, `$part`). */
    public const CHOOSER_ISLANDS = ['chooser-game', 'chooser-window', 'chooser-place', 'chooser'];

    /** The profile key of the game and mode ({@see TournamentGames}). */
    public string $game = 'blitz';

    /** Participants as typed; clamped to 2–64 for the estimate. */
    public string $players = '12';

    public int $window = 180;

    public bool $onSite = false;

    public int $stations = 6;

    /** The organizer's own planning values; '' = the league default. */
    public string $gameLength = '';

    public string $setup = '';

    public string $break = '';

    /** @var array<string, mixed> see FormatOptions */
    public array $options = [];

    /** The format the organizer picked; null = the recommended one. */
    public ?string $selected = null;

    public string $resultsMode = 'players';

    /** @var list<int> user ids of the named directors */
    public array $directorIds = [];

    /** The player picked in <x-player-picker>; null = nobody picked. */
    public ?int $directorId = null;

    public string $directorError = '';

    /**
     * The tournament's own deadlines as typed (P18,
     * pages/admin/partials/tournament-deadlines.blade.php); '' = the league
     * default ({@see TournamentDeadlines}).
     */
    public string $checkinMinutes = '';

    public string $noshowMinutes = '';

    public string $reportHours = '';

    public string $responseMinutes = '';

    /**
     * The night pause (NightPause): null = the league's switch, else the
     * organizer's; its times as typed, '' = the league's ("24:00" = midnight).
     */
    public ?bool $nightPause = null;

    public string $nightPauseUntil = '';

    public string $nightPauseResume = '';

    /**
     * How a drawn two-leg decider is settled (Tournament::DRAW_DECIDER_*): the race standing by default for a new
     * tournament (user, 2026-10-11); the edit page fills the stored rule (null = replay).
     */
    public string $drawDecider = Tournament::DRAW_DECIDER_STANDING;

    /** Form property => tournament column of each deadline. */
    public const DEADLINE_FIELDS = [
        'checkinMinutes' => 'checkin_minutes',
        'noshowMinutes' => 'noshow_minutes',
        'reportHours' => 'report_hours',
        'responseMinutes' => 'response_minutes',
    ];

    /**
     * Who directs without being named: the creator of the tournament.
     */
    abstract public function chooserCreator(): ?User;

    public function profile(): GameProfile
    {
        [$game, $mode] = TournamentGames::find($this->game) ?? ['chess', 'blitz'];
        $number = fn (string $value): ?float => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000 ? (float) $value : null;

        $profile = GameProfile::for($game, $mode)->withTimes($number($this->gameLength), $number($this->setup), $number($this->break));

        // Hyperbitcoinization (plan "Hyperbitcoinization", P5b): a clan bracket's team size is one of its options.
        return $profile->isHyper() ? $profile->withTeamSize(FormatOptions::hyperTeamSize($this->options)) : $profile;
    }

    public function count(): int
    {
        return is_numeric($this->players) ? max(2, min(64, (int) $this->players)) : 12;
    }

    public function formatOptions(): FormatOptions
    {
        return FormatOptions::fromArray($this->options, $this->profile());
    }

    public function stationLimit(): ?int
    {
        return $this->onSite && ! $this->profile()->isDaily() ? $this->stations : null;
    }

    #[Computed]
    public function evaluation(): Evaluation
    {
        return (new Estimator)->evaluate($this->count(), $this->profile(), $this->formatOptions(), $this->stationLimit(), $this->window);
    }

    /**
     * The honest range of the chosen format (P18): the plan, the typical
     * online duration and the latest on the round clock with the deadlines
     * typed so far. Null when the format cannot run.
     */
    #[Computed]
    public function durationRange(): ?DurationRange
    {
        $row = $this->evaluation->row($this->format);

        if (! $row->enabled || $row->structure === null) {
            return null;
        }

        $typed = array_filter($this->chosenDeadlines(), fn (mixed $value, string $column): bool => is_int($value) && isset(TournamentDeadlines::BOUNDS[$column])
            && $value >= TournamentDeadlines::BOUNDS[$column][0] && $value <= TournamentDeadlines::BOUNDS[$column][1], ARRAY_FILTER_USE_BOTH);

        return (new Estimator)->range($this->format, $row->structure, $this->profile(), $this->evaluation->options, $this->stationLimit(),
            TournamentDeadlines::clock($this->profile()->mode, $typed));
    }

    /**
     * Start, expected end and latest end as the chooser shows them, with the
     * warnings; null on site and in daily chess (they keep the plan) or when
     * the format cannot run. `start` is null while date or time are not valid.
     *
     * @return array{start: CarbonImmutable|null, typical: CarbonImmutable|null, latest: CarbonImmutable|null, warnings: list<string>}|null
     */
    public function expectedTimes(): ?array
    {
        $range = $this->durationRange;
        $profile = $this->profile();

        if ($range === null || $this->stationLimit() !== null || $profile->isDaily()) {
            return null;
        }

        $start = null;

        // The page's start field (`Y-m-d\TH:i`, typed in the league's zone, {@see LeagueTime}).
        $typed = property_exists($this, 'startsAt') ? (string) $this->startsAt : '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $typed) === 1) {
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $typed, $this->chooserZone()) ?: null;
        }

        // With the night pause on, an end past its start moves past the pause, to the next day (DerCaddy, 2026-11-04: "Ende
        // gegen 1:24" right above "at 24:00 the night pause holds the tournament"). About: a game under way at the pause
        // start still finishes, so the real end can come a little earlier.
        $window = $start !== null && $this->nightPauseApplies() && $this->nightPauseOn()
            ? NightPause::window($this->nightPauseTimes()['until'], $this->nightPauseTimes()['resume'])
            : null;
        $end = fn (float $minutes): ?CarbonImmutable => $start === null ? null
            : ($window !== null ? CarbonImmutable::instance(NightPause::addSeconds($window, $start, (int) ceil($minutes) * 60)) : $start->addMinutes((int) ceil($minutes)));

        return [
            'start' => $start,
            'typical' => $end($range->typical),
            'latest' => $end($range->latest),
            // Midnight in the league's zone (Berlin), whatever zone the organizer types in.
            'warnings' => $start !== null ? $range->warnings($start, $profile, (string) config('esports.preseason.display_timezone'))
                : ($range->latest > DurationRange::LATEST_MAX_MINUTES ? ['too-long'] : []),
        ];
    }

    /**
     * The zone the start is entered in: always the league's (Berlin), as on the tournament pages.
     */
    public function chooserZone(): string
    {
        return LeagueTime::zone();
    }

    /**
     * The selected format if it can run, else the recommended one.
     */
    #[Computed]
    public function format(): TournamentFormat
    {
        $picked = TournamentFormat::tryFrom((string) $this->selected);

        if ($picked !== null && $this->evaluation->row($picked)->enabled) {
            return $picked;
        }

        return $this->evaluation->recommended ?? TournamentFormat::SingleElimination;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function directors(): Collection
    {
        return User::query()->whereKey($this->directorIds)->orderBy('name')->get();
    }

    public function pickGame(string $key): void
    {
        // Hyperbitcoinization only while its tournaments are switched on (P5c); the game it has now stays pickable.
        if (! TournamentGames::offers($key, $this->game)) {
            return;
        }

        // Another time control of the same game keeps the format and its options where they stay valid (PR #19 review: a
        // Blockli blitz -> rapid switch before the draw reset "three times", one game per pairing and the ranking); the
        // times are the mode's own, and the window only carries over in the same unit.
        $before = $this->profile();
        $this->game = $key;
        $this->gameLength = $this->setup = $this->break = '';
        $sameGame = $this->profile()->game === $before->game;

        if (! $sameGame || $this->profile()->unit !== $before->unit) {
            $this->window = $this->profile()->isDaily() ? 90 : 180;
        }

        $this->options = ($sameGame ? FormatOptions::fromArray($this->options, $this->profile()) : FormatOptions::defaults($this->profile()))->toArray();

        if (! $sameGame) {
            $this->selected = null;
        }

        if ($this->profile()->isDaily()) {
            $this->onSite = false;
        }

        $this->changed();
    }

    public function stepPlayers(int $by): void
    {
        $this->players = (string) max(2, min(64, $this->count() + $by));
        $this->changed();
    }

    public function updatedPlayers(): void
    {
        if (is_numeric($this->players)) {
            $this->players = (string) max(2, min(64, (int) $this->players));
        }

        $this->changed();
    }

    public function pickWindow(int $window): void
    {
        if (in_array($window, array_column($this->windows(), 0), true)) {
            $this->window = $window;
        }

        $this->changed();
    }

    public function pickWhere(string $where): void
    {
        $this->onSite = $where === 'site' && ! $this->profile()->isDaily();

        // On site preselects the director mode (TOURNAMENT-FORMATS.md, section 6); the creator can switch back.
        if ($this->onSite) {
            $this->resultsMode = TournamentResultsMode::Director->value;
        }

        $this->changed();
    }

    public function stepStations(int $by): void
    {
        $this->stations = max(1, min(32, $this->stations + $by));
        $this->changed();
    }

    public function updatedGameLength(): void
    {
        $this->changed();
    }

    public function updatedSetup(): void
    {
        $this->changed();
    }

    public function updatedBreak(): void
    {
        $this->changed();
    }

    public function select(string $format): void
    {
        $this->selected = TournamentFormat::tryFrom($format)?->value;
        $this->changed();
    }

    public function useRecommended(): void
    {
        $this->selected = $this->evaluation->recommended?->value;
        $this->changed();
    }

    /**
     * One option of the selected format, normalized by FormatOptions.
     */
    public function option(string $key, mixed $value): void
    {
        if (array_key_exists($key, FormatOptions::defaults($this->profile())->toArray())) {
            $this->options = FormatOptions::fromArray([...$this->options, $key => $value], $this->profile())->toArray();
        }

        // A Hyperbitcoinization clan bracket (P5b) is played only as a knockout: picking 2v2 or 3v3 picks it too.
        if ($key === 'teamSize' && $this->profile()->entersTeams()) {
            $this->selected = TournamentFormat::SingleElimination->value;
        }

        $this->changed();
    }

    /**
     * A tie-break picked that another place already holds: the two swap, so
     * the list keeps three distinct entries.
     */
    public function updatingOptions(mixed $value, string $key): void
    {
        if (preg_match('/^(swissTieBreaks|roundRobinTieBreaks)\.([0-2])$/', $key, $found) !== 1 || ! is_array($this->options[$found[1]] ?? null)) {
            return;
        }

        $list = $this->options[$found[1]];
        $other = array_search($value, $list, true);

        if ($other !== false && $other !== (int) $found[2]) {
            $this->options[$found[1]][$other] = $list[(int) $found[2]] ?? null;
        }
    }

    public function pickGroupStage(string $stage): void
    {
        $this->option('groupStage', $stage);
    }

    public function pickFinalStage(string $stage): void
    {
        $this->option('finalStage', $stage);
    }

    /** One game per pairing or two in a two-leg game's round-robin table (FormatOptions::$legs); stored only on save. */
    public function pickLegs(int $legs): void
    {
        $this->option('legs', $legs);
    }

    /**
     * A combined tournament: '' for one game, else the second game each pairing plays one game of, after the
     * tournament's own (FormatOptions::$games, DerCaddy 2026-11-11); stored only on save.
     */
    public function pickPairGame(string $slug): void
    {
        $this->option('games', $slug === '' ? [] : [$this->profile()->game, $slug]);
    }

    /** The two legs count per pairing or game by game (FormatOptions::$legPoints); stored only on save. */
    public function pickLegPoints(bool $perGame): void
    {
        $this->option('legPoints', $perGame);
    }

    /** A tie for place 1 of the final table played off or shared (FormatOptions::$firstPlaceTie); stored only on save. */
    public function pickFirstPlaceTie(string $rule): void
    {
        if (in_array($rule, FormatOptions::FIRST_PLACE_TIES, true)) {
            $this->option('firstPlaceTie', $rule);
        }
    }

    public function pickBestOf(int $bestOf): void
    {
        $this->option('bestOf', $bestOf);
    }

    public function pickFinalBestOf(int $bestOf): void
    {
        $this->option('finalBestOf', $bestOf);
    }

    public function updatedOptions(): void
    {
        $this->options = FormatOptions::fromArray($this->options, $this->profile())->toArray();
        $this->changed();
    }

    public function stepOption(string $key, int $by): void
    {
        $current = match ($key) {
            'swissRounds' => $this->options['swissRounds'] ?? $this->evaluation->swissRounds,
            default => $this->options[$key] ?? 0,
        };

        $value = (int) $current + $by;

        if ($key === 'swissRounds') {
            $value = max(1, min(Estimator::swissMax($this->count()), $value));
        }

        $this->option($key, $value);
    }

    public function pickResultsMode(string $mode): void
    {
        $this->resultsMode = TournamentResultsMode::tryFrom($mode)->value ?? $this->resultsMode;
        $this->changed();
    }

    public function addDirector(): void
    {
        $this->directorError = '';
        $user = $this->directorId === null ? null : User::query()->find($this->directorId);

        if ($user === null) {
            $this->directorError = __('Pick a player from the suggestions.');

            return;
        }

        if ($user->id !== $this->chooserCreator()?->id && ! in_array($user->id, $this->directorIds, true)) {
            $this->directorIds[] = $user->id;
        }

        $this->directorId = null;
        unset($this->directors);
        $this->changed();
    }

    public function removeDirector(int $userId): void
    {
        $this->directorIds = array_values(array_filter($this->directorIds, fn (int $id): bool => $id !== $userId));
        unset($this->directors);
        $this->changed();
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    public function windows(): array
    {
        return $this->profile()->isDaily()
            ? [[30, __('1 month')], [90, __('3 months')], [180, __('6 months')]]
            : [[120, __('2 hours')], [180, __('One evening, 3 h')], [240, __('4 hours')], [480, __('A day, 8 h')]];
    }

    /**
     * The organizer's own times that differ from the league default of the
     * game, as stored in `tournaments.times`; null when none differs.
     *
     * @return array{game?: float, setup?: float, break?: float}|null
     */
    protected function chosenTimes(): ?array
    {
        $profile = $this->profile();
        $default = GameProfile::for($profile->game, $profile->mode);
        $times = array_filter([
            'game' => $profile->gameLength,
            'setup' => $profile->setup,
            'break' => $profile->break,
        ], fn (float $value, string $key): bool => $value !== ['game' => $default->gameLength, 'setup' => $default->setup, 'break' => $default->break][$key], ARRAY_FILTER_USE_BOTH);

        return $times === [] ? null : $times;
    }

    /**
     * The options to store: Swiss keeps the rounds the chooser planned with
     * when the organizer left them open.
     */
    protected function chosenOptions(): FormatOptions
    {
        $options = $this->formatOptions();

        return $this->format === TournamentFormat::Swiss && $options->swissRounds === null
            ? $options->withSwissRounds($this->evaluation->swissRounds)
            : $options;
    }

    /**
     * The selected format can run and the time window is one the chooser offers.
     */
    protected function chosenFormatRuns(): bool
    {
        return $this->evaluation->row($this->format)->enabled && in_array($this->window, array_column($this->windows(), 0), true);
    }

    /**
     * The deadline fields that reach the chosen game in the chosen results mode, as the league reads them: a lobby game
     * none (its own report deadline), Hyperbitcoinization none (its turn clock), a game the league server starts its
     * first move (chess and board games in players mode, Proof of Pong in either: TournamentDeadlines::checkinSeconds()),
     * directors none (no game starts, nobody reports), a series its no-show, report and answer
     * (TournamentDeadlines::forSeries()). The form shows, validates and stores these; any other is hidden and can never
     * block the save (a series no-show of 9999 typed before switching to Blockli failed on a field nobody saw).
     *
     * @return list<key-of<self::DEADLINE_FIELDS>>
     */
    public function applicableDeadlines(): array
    {
        $profile = $this->profile();
        $directors = $this->resultsMode === TournamentResultsMode::Director->value;

        return match (true) {
            Lobbies::isLobbyGame($profile->game), $profile->isHyper() => [],
            $profile->playedOnLeague($directors) => ['checkinMinutes'],
            $directors => [],
            default => ['noshowMinutes', 'reportHours', 'responseMinutes'],
        };
    }

    /**
     * The rules of the deadline fields: empty, or a whole number in range;
     * the night pause times empty or "HH:MM" ("24:00" for midnight), not equal.
     *
     * @return array<string, list<string>>
     */
    protected function deadlineRules(): array
    {
        $rules = [];

        foreach ($this->applicableDeadlines() as $property) {
            [$min, $max] = TournamentDeadlines::BOUNDS[self::DEADLINE_FIELDS[$property]];
            $rules[$property] = ['nullable', 'integer', "between:{$min},{$max}"];
        }

        $time = 'regex:/^(([01]\\d|2[0-3]):[0-5]\\d|24:00)$/';
        $rules['nightPauseUntil'] = ['nullable', $time];
        $rules['nightPauseResume'] = ['nullable', $time];

        if ($this->nightPauseTimes()['until'] === $this->nightPauseTimes()['resume']) {
            $rules['nightPauseResume'][] = 'different:nightPauseUntil';
        }

        return $rules;
    }

    /** Whether the night pause is on as chosen (the league's switch until the organizer flips it). */
    public function nightPauseOn(): bool
    {
        return $this->nightPause ?? NightPause::defaults()['enabled'];
    }

    public function toggleNightPause(): void
    {
        $this->nightPause = ! $this->nightPauseOn();
    }

    /**
     * Whether the night pause can apply to the tournament as chosen
     * (NightPause::applies()): online, a minute game, players mode, not a
     * score leaderboard. A casual cup has it too, on its region's clock.
     */
    public function nightPauseApplies(): bool
    {
        $profile = $this->profile();

        return $this->stationLimit() === null && ! $profile->isDaily() && ! $profile->isScore() && $this->resultsMode === 'players';
    }

    /**
     * The night pause times as they apply, typed or the league's ("24:00" typed = "00:00").
     *
     * @return array{until: string, resume: string, zone?: string}
     */
    public function nightPauseTimes(): array
    {
        $defaults = NightPause::defaults();
        $typed = fn (string $value): ?string => ($value = trim($value)) === '' ? null : ($value === '24:00' ? '00:00' : $value);

        return ['until' => $typed($this->nightPauseUntil) ?? $defaults['until'], 'resume' => $typed($this->nightPauseResume) ?? $defaults['resume']];
    }

    /** Whether the chosen game can settle a drawn decider by the race standing (Blockli). */
    public function drawDeciderApplies(): bool
    {
        return TournamentRunner::canDecideByStanding($this->profile()->game);
    }

    /**
     * Whether the chosen format ever plays a decider: only a knockout does (Swiss and round robin take a 1:1 as a draw,
     * TournamentRunner::stageAllowsDraw()), so only then the rule shows (DerCaddy, 2026-11-04: shown under a round robin,
     * where it never applies). The stored rule stays the game's either way: it is kept for a later switch to a knockout.
     */
    public function drawDeciderMatters(): bool
    {
        return $this->drawDeciderApplies() && in_array($this->format, [TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination, TournamentFormat::TwoStage], true);
    }

    /**
     * Whether the rule may still change: it is part of the rules players sign up under, so like the prizes only in a
     * draft or while sign-up is open (PrizePool::canChangeSplit). The create page always may.
     */
    public function drawDeciderEditable(): bool
    {
        $tournament = property_exists($this, 'tournament') ? $this->tournament : null;

        return ! $tournament instanceof Tournament
            || (! $tournament->isCasualCup() && ($tournament->status === TournamentStatus::Draft || $tournament->isSignupOpen()));
    }

    public function useDrawDecider(string $rule): void
    {
        if (in_array($rule, [Tournament::DRAW_DECIDER_STANDING, Tournament::DRAW_DECIDER_REPLAY], true) && $this->drawDeciderEditable()) {
            $this->drawDecider = $rule;
        }
    }

    /**
     * The rule to store: only for a game that can use it, and on the edit page only while it may change.
     *
     * @return array{draw_decider?: string|null}
     */
    protected function chosenDrawDecider(): array
    {
        if (! $this->drawDeciderEditable()) {
            return [];
        }

        // Replay is stored as null, as every tournament before the rule had it: an unchanged edit changes nothing.
        return ['draw_decider' => $this->drawDeciderApplies() && $this->drawDecider === Tournament::DRAW_DECIDER_STANDING ? Tournament::DRAW_DECIDER_STANDING : null];
    }

    /** The edit page fills the night pause from the stored tournament. */
    protected function fillNightPause(Tournament $tournament): void
    {
        $this->nightPause = $tournament->night_pause;
        $this->nightPauseUntil = $tournament->night_pause_until === null ? '' : NightPause::untilLabel($tournament->night_pause_until);
        $this->nightPauseResume = $tournament->night_pause_resume ?? '';
    }

    /**
     * The deadlines to store, by column; null = the league default.
     *
     * The night pause: null where it equals the league default, so a
     * tournament keeps following the league.
     *
     * @return array{checkin_minutes: int|null, noshow_minutes: int|null, report_hours: int|null, response_minutes: int|null, night_pause: bool|null, night_pause_until: string|null, night_pause_resume: string|null}
     */
    protected function chosenDeadlines(): array
    {
        // A deadline that does not reach the game is not validated ({@see applicableDeadlines()}): kept while it is in range
        // (a switch back finds it), else dropped.
        $applicable = $this->applicableDeadlines();
        $value = function (string $property) use ($applicable): ?int {
            $typed = trim($this->{$property});
            [$min, $max] = TournamentDeadlines::BOUNDS[self::DEADLINE_FIELDS[$property]];

            return match (true) {
                $typed === '' => null,
                in_array($property, $applicable, true) => (int) $typed,
                default => filter_var($typed, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false ? null : (int) $typed,
            };
        };
        $time = fn (string $typed): ?string => ($typed = trim($typed)) === '' ? null : ($typed === '24:00' ? '00:00' : $typed);

        return [
            'checkin_minutes' => $value('checkinMinutes'),
            'noshow_minutes' => $value('noshowMinutes'),
            'report_hours' => $value('reportHours'),
            'response_minutes' => $value('responseMinutes'),
            'night_pause' => $this->nightPause === null || $this->nightPause === NightPause::defaults()['enabled'] ? null : $this->nightPause,
            'night_pause_until' => $time($this->nightPauseUntil),
            'night_pause_resume' => $time($this->nightPauseResume),
        ];
    }

    /**
     * Validates the page's save (create or save changes). A failed one tells the browser so: the first field message on the
     * page (x-admin.field-error) brings itself into view and focuses its field on every failed save, also the second with
     * the same unfixed error, whose message the morph keeps in place without running its Alpine init again.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateForm(array $rules): array
    {
        try {
            return $this->validate($rules);
        } catch (ValidationException $exception) {
            $this->dispatch('admin-form-invalid');

            throw $exception;
        }
    }

    /**
     * The chooser changed: the summary island shows the new choice too, and
     * the deadlines island the deadlines of the game now picked (a lobby game
     * has none of a series, P10). The chooser itself stands in four islands
     * in the order an organizer decides (order E, jury 2026-11-05): a change
     * in one of them reaches the others (a game picked changes the systems,
     * a player count the recommendation, a results mode the night pause).
     */
    protected function changed(): void
    {
        unset($this->evaluation, $this->format, $this->durationRange);

        foreach (self::CHOOSER_ISLANDS as $island) {
            $this->renderIsland($island);
        }

        $this->renderIsland('summary');
        $this->renderIsland('deadlines');
    }
}
