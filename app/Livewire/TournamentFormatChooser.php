<?php

namespace App\Livewire;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\User;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\Evaluation;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentDeadlines;
use App\Support\Tournaments\TournamentGames;
use Illuminate\Database\Eloquent\Collection;
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
 * @property-read Collection<int, User> $directors
 */
abstract class TournamentFormatChooser extends Component
{
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

        return GameProfile::for($game, $mode)->withTimes($number($this->gameLength), $number($this->setup), $number($this->break));
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
        if (TournamentGames::find($key) === null) {
            return;
        }

        $this->game = $key;
        $this->gameLength = $this->setup = $this->break = '';
        $this->window = $this->profile()->isDaily() ? 90 : 180;
        $this->options = FormatOptions::defaults($this->profile())->toArray();
        $this->selected = null;

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
     * The rules of the deadline fields: empty, or a whole number in range.
     *
     * @return array<string, list<string>>
     */
    protected function deadlineRules(): array
    {
        $rules = [];

        foreach (self::DEADLINE_FIELDS as $property => $column) {
            [$min, $max] = TournamentDeadlines::BOUNDS[$column];
            $rules[$property] = ['nullable', 'integer', "between:{$min},{$max}"];
        }

        return $rules;
    }

    /**
     * The deadlines to store, by column; null = the league default.
     *
     * @return array{checkin_minutes: int|null, noshow_minutes: int|null, report_hours: int|null, response_minutes: int|null}
     */
    protected function chosenDeadlines(): array
    {
        $value = fn (string $typed): ?int => trim($typed) === '' ? null : (int) $typed;

        return [
            'checkin_minutes' => $value($this->checkinMinutes),
            'noshow_minutes' => $value($this->noshowMinutes),
            'report_hours' => $value($this->reportHours),
            'response_minutes' => $value($this->responseMinutes),
        ];
    }

    /**
     * The chooser changed: the summary island shows the new choice too.
     */
    protected function changed(): void
    {
        unset($this->evaluation, $this->format);
        $this->renderIsland('summary');
    }
}
