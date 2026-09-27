<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Rating\Ratings;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\Entrant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the public tournament page (pages::tournaments.show) and its sign-up
 * page show besides the bracket: the call to action for the viewer, the
 * deadline to count down to, the places, who plays (with the seed each entry
 * would get if sign-up closed now), and the first round those seeds would
 * play. Everything is read from the tournament as it stands; nothing here is
 * estimated beyond the seeding rule the draw itself applies
 * (TournamentDraws::createParticipants: Elo, equal Elo by earlier sign-up,
 * mix teams after the lineups).
 */
final class TournamentLanding
{
    /** Open seats drawn as their own tiles; the rest is one "+N" tile. */
    public const OPEN_TILES = 6;

    /** @var list<array{key: string, kind: string, name: string, users: list<User>, clan: Clan|null, rating: int|null, seed: int|null, you: bool, href: string|null}>|null */
    private ?array $roster = null;

    /** @var array{taken: int, places: int, lineups: int, solos: int}|null */
    private ?array $places = null;

    private ?TournamentSignup $entry = null;

    private bool $entryLoaded = false;

    public function __construct(private readonly Tournament $tournament, private readonly ?User $viewer) {}

    public function drawn(): bool
    {
        return in_array($this->tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true);
    }

    /**
     * @return array{taken: int, places: int, lineups: int, solos: int}
     */
    public function places(): array
    {
        return $this->places ??= app(TournamentSignups::class)->places($this->tournament);
    }

    public function isFull(): bool
    {
        $places = $this->places();

        return $places['taken'] >= $places['places'];
    }

    /** The viewer's active entry before the draw. */
    public function entry(): ?TournamentSignup
    {
        if (! $this->entryLoaded) {
            $this->entry = $this->viewer === null ? null : app(TournamentSignups::class)->entryOf($this->tournament, $this->viewer);
            $this->entryLoaded = true;
        }

        return $this->entry;
    }

    /**
     * The viewer's call to action, by the state of the tournament and of the
     * viewer: open, entered, full, closed, live, finished, cancelled or draft.
     */
    public function cta(): string
    {
        $tournament = $this->tournament;

        return match ($tournament->status) {
            TournamentStatus::Draft => 'draft',
            TournamentStatus::Signup => match (true) {
                $this->entry() !== null => 'entered',
                ! $tournament->isSignupOpen() => 'closed',
                $this->isFull() => 'full',
                default => 'open',
            },
            TournamentStatus::Drawing => $this->entry() !== null ? 'entered' : 'closed',
            TournamentStatus::Running => 'live',
            TournamentStatus::Finished => 'finished',
            TournamentStatus::Cancelled => 'cancelled',
        };
    }

    /**
     * The moment the page counts down to: sign-up close while it is open,
     * else the start while it is ahead; null once the tournament runs.
     *
     * @return array{at: CarbonInterface, kind: 'signup'|'start'}|null
     */
    public function deadline(): ?array
    {
        $tournament = $this->tournament;

        if ($tournament->isSignupOpen() && $tournament->signup_closes_at !== null) {
            return ['at' => $tournament->signup_closes_at, 'kind' => 'signup'];
        }

        if (in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing], true) && $tournament->starts_at->isFuture()) {
            return ['at' => $tournament->starts_at, 'kind' => 'start'];
        }

        return null;
    }

    /**
     * An online tournament has only a start (P18): when it is expected to
     * end and the latest, never promised. Null on site and in daily chess.
     *
     * @return array{typical: CarbonImmutable, latest: CarbonImmutable}|null
     */
    public function expectedEnd(): ?array
    {
        return $this->tournament->expectedEnd();
    }

    /**
     * "Open end, expected around 0:30" in `$zone`; null without an open end.
     */
    public function openEndLine(string $zone): ?string
    {
        return self::openEnd($this->tournament, $zone);
    }

    /**
     * The open-end line of any tournament (the list's cards have no landing).
     */
    public static function openEnd(Tournament $tournament, string $zone): ?string
    {
        $end = $tournament->expectedEnd();

        if ($end === null) {
            return null;
        }

        return __('Open end, expected around :time', ['time' => $end['typical']->setTimezone($zone)->format('G:i')]);
    }

    /**
     * The countdown's first frame as the server draws it, in the format the
     * page script (resources/js/tournamentLanding.js) then ticks in.
     *
     * @return array{ms: int, label: string, text: string, when: string}|null
     */
    public function countdown(): ?array
    {
        $deadline = $this->deadline();

        if ($deadline === null) {
            return null;
        }

        $seconds = max(0, (int) now()->diffInSeconds($deadline['at'], false));
        $days = intdiv($seconds, 86400);

        return [
            'ms' => (int) $deadline['at']->getTimestampMs(),
            'label' => $deadline['kind'] === 'signup' ? __('Sign-up closes in') : __('Starts in'),
            'text' => ($days > 0 ? trans_choice(':count day|:count days', $days).' ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60),
            'when' => LeagueTime::stamp($deadline['at']),
        ];
    }

    /**
     * How long until the start, coarse ("starts in 5 d 3 h"), while the start
     * is ahead and nothing has been played; the page script
     * (resources/js/tournamentLanding.js, startsIn) keeps it current.
     *
     * @return array{ms: int, text: string}|null
     */
    public function startsIn(): ?array
    {
        $tournament = $this->tournament;

        if (! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Signup, TournamentStatus::Drawing], true) || ! $tournament->starts_at->isFuture()) {
            return null;
        }

        return [
            'ms' => (int) $tournament->starts_at->getTimestampMs(),
            'text' => self::startsInText((int) now()->diffInSeconds($tournament->starts_at, false)),
        ];
    }

    /** "starts in 5 d 3 h", "starts in 3 h 20 min", "starts in 12 min", "starts in under a minute". */
    public static function startsInText(int $seconds): string
    {
        $days = intdiv(max(0, $seconds), 86400);
        $hours = intdiv(max(0, $seconds) % 86400, 3600);
        $minutes = intdiv(max(0, $seconds) % 3600, 60);

        return match (true) {
            $days > 0 => __('starts in :d d :h h', ['d' => $days, 'h' => $hours]),
            $hours > 0 => __('starts in :h h :m min', ['h' => $hours, 'm' => $minutes]),
            $minutes > 0 => __('starts in :m min', ['m' => $minutes]),
            default => __('starts in under a minute'),
        };
    }

    /**
     * Who plays, best seed first. Before the draw: every active entry with
     * the seed it would get now (solo players of a team mode have none: they
     * are drawn into mix teams); after it: the participants with their seed.
     *
     * @return list<array{key: string, kind: string, name: string, users: list<User>, clan: Clan|null, rating: int|null, seed: int|null, you: bool, href: string|null}>
     */
    public function roster(): array
    {
        return $this->roster ??= $this->drawn() ? $this->participantRoster() : $this->signupRoster();
    }

    /** Player places still free (a lineup takes a team's places, a solo player one). */
    public function openSeats(): int
    {
        $places = $this->places();

        return max(0, $places['places'] - $places['taken']);
    }

    /**
     * The viewer's seed if sign-up closed now (or their real seed after the draw).
     */
    public function yourSeed(): ?int
    {
        foreach ($this->roster() as $row) {
            if ($row['you']) {
                return $row['seed'];
            }
        }

        return null;
    }

    /**
     * Round 1 as it would be drawn if sign-up closed now, from the same
     * bracket builder the draw uses, with an open seat for every place not
     * yet taken. Null once the tournament is drawn (the real bracket shows).
     *
     * @return array{matches: list<array{key: string, group: int|null, sides: list<array{seed: int, name: string|null, mix: bool, you: bool}>}>, byes: list<int>, groups: array<int, list<array{seed: int, name: string|null, mix: bool, you: bool}>>}|null
     */
    public function projection(): ?array
    {
        if ($this->drawn() || $this->tournament->status === TournamentStatus::Cancelled) {
            return null;
        }

        $tournament = $this->tournament;
        $capacity = max(2, $tournament->capacity);
        $known = array_values(array_filter($this->roster(), fn (array $row): bool => $row['seed'] !== null));
        $mixTeams = $tournament->profile()->entersTeams() ? intdiv($this->places()['solos'], max(1, $tournament->teamSize())) : 0;

        // Entrant id = projected seed: known entries first, then mix teams, then open seats.
        $seats = [];

        foreach (range(1, $capacity) as $seed) {
            $row = $known[$seed - 1] ?? null;
            $seats[$seed] = [
                'seed' => $seed,
                'name' => $row['name'] ?? null,
                'mix' => $row === null && $seed <= count($known) + $mixTeams,
                'you' => $row['you'] ?? false,
            ];
        }

        $entrants = array_map(fn (int $seed): Entrant => new Entrant($seed, $capacity - $seed, $seed), array_keys($seats));
        $options = $tournament->formatOptions();

        if ($tournament->format === TournamentFormat::Swiss && $options->swissRounds === null) {
            $options = $options->withSwissRounds(Estimator::swissDefault($capacity));
        }

        $bracket = BracketBuilder::build($tournament->format, $entrants, $options, 'projection');
        $firstRound = array_values(array_filter($bracket->matches, fn (BracketMatch $match): bool => $match->stage === 1 && $match->round === 1 && $match->bracket !== 'bye'
            && array_reduce($match->slots, fn (bool $all, $slot): bool => $all && $slot->isKnown(), true)));

        $playing = [];
        $matches = [];

        foreach ($firstRound as $match) {
            $sides = [];

            foreach ($match->slots as $slot) {
                $playing[(int) $slot->entrant] = true;
                $sides[] = $seats[(int) $slot->entrant];
            }

            $matches[] = ['key' => $match->key, 'group' => $match->group, 'sides' => $sides];
        }

        $groups = [];

        foreach ($bracket->groups as $number => $members) {
            $groups[$number] = array_map(fn (int $seed): array => $seats[$seed], $members);
        }

        $byes = $tournament->format === TournamentFormat::SingleElimination || $tournament->format === TournamentFormat::DoubleElimination
            ? array_values(array_diff(array_keys($seats), array_keys($playing)))
            : [];

        return ['matches' => $matches, 'byes' => $byes, 'groups' => $groups];
    }

    /**
     * @return list<array{key: string, kind: string, name: string, users: list<User>, clan: Clan|null, rating: int|null, seed: int|null, you: bool, href: string|null}>
     */
    private function signupRoster(): array
    {
        $tournament = $this->tournament;
        $signups = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->with('lineup.clan')->orderBy('id')->get();

        if ($signups->isEmpty()) {
            return [];
        }

        $teams = $tournament->profile()->entersTeams();
        $pool = Ratings::pool($tournament->openLadder() !== null);
        $users = $this->users($signups->pluck('members')->flatten()->all());
        $userRatings = Ratings::forUsers($signups->pluck('members')->flatten()->all(), $tournament->game, $tournament->mode, $pool);
        $lineupRatings = Ratings::forLineups($signups->pluck('lineup_id')->filter()->all(), $tournament->game, $tournament->mode, $pool);
        $start = (int) config('season.rating.start', 1000);
        $rows = [];

        foreach ($signups as $signup) {
            $first = (int) ($signup->members[0] ?? 0);
            $rating = match (true) {
                $signup->lineup_id !== null && $tournament->teamSize() === 1 => $userRatings[$first]['rating'] ?? $start,
                $signup->lineup_id !== null => $lineupRatings[$signup->lineup_id]['rating'] ?? $start,
                ! $teams => $userRatings[$first]['rating'] ?? $start,
                default => null,
            };
            $members = $this->members($users, $signup->members);
            $solo = $signup->lineup_id === null;

            $rows[] = [
                'key' => 'entry-'.$signup->id,
                'kind' => $solo ? ($teams ? 'solo' : 'player') : 'lineup',
                'name' => $signup->name,
                'users' => $members,
                'clan' => $signup->lineup->clan ?? ($solo ? ($members[0] ?? null)?->clanMember?->clan : null),
                'rating' => $rating,
                'seed' => null,
                'you' => $this->viewer !== null && in_array($this->viewer->id, array_map(intval(...), $signup->members), true),
                'href' => $solo && isset($members[0]) ? route('players.show', $members[0]->npub) : ($signup->lineup?->clan ? route('clans.show', $signup->lineup->clan) : null),
            ];
        }

        // The draw's order: rated entries by Elo, equal Elo by earlier sign-up (the rows are in sign-up order and
        // usort is stable); solo players of a team mode last, unseeded.
        usort($rows, fn (array $a, array $b): int => ($b['rating'] ?? PHP_INT_MIN) <=> ($a['rating'] ?? PHP_INT_MIN));
        $seeded = [];
        $seed = 0;

        foreach ($rows as $row) {
            $row['seed'] = $row['rating'] === null ? null : ++$seed;
            $seeded[] = $row;
        }

        return $seeded;
    }

    /**
     * @return list<array{key: string, kind: string, name: string, users: list<User>, clan: Clan|null, rating: int|null, seed: int|null, you: bool, href: string|null}>
     */
    private function participantRoster(): array
    {
        $participants = TournamentParticipant::query()->where('tournament_id', $this->tournament->id)->with('lineup.clan')->get();
        $users = $this->users($participants->flatMap(fn (TournamentParticipant $participant): array => $participant->memberIds())->all());
        $rows = [];

        foreach ($participants->sortBy(fn (TournamentParticipant $participant): int => $participant->seed ?? PHP_INT_MAX)->values() as $participant) {
            $members = $this->members($users, $participant->memberIds());
            $solo = $participant->lineup_id === null && ! $participant->isMixTeam();

            $rows[] = [
                'key' => 'participant-'.$participant->id,
                'kind' => $participant->isMixTeam() ? 'mix' : ($solo ? 'player' : 'lineup'),
                'name' => $participant->name,
                'users' => $members,
                'clan' => $participant->lineup->clan ?? ($solo ? ($members[0] ?? null)?->clanMember?->clan : null),
                'rating' => $participant->isMixTeam() ? null : $participant->rating,
                'seed' => $participant->seed,
                'you' => $this->viewer !== null && in_array($this->viewer->id, $participant->memberIds(), true),
                'href' => $solo && isset($members[0]) ? route('players.show', $members[0]->npub) : ($participant->lineup?->clan ? route('clans.show', $participant->lineup->clan) : null),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, User>
     */
    private function users(array $ids): Collection
    {
        return User::query()->whereKey(array_values(array_unique(array_map(intval(...), $ids))))->with('clanMember.clan')->get()->keyBy('id');
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int, mixed>|null  $ids
     * @return list<User>
     */
    private function members(Collection $users, ?array $ids): array
    {
        $members = [];

        foreach ($ids ?? [] as $id) {
            $user = $users->get((int) $id);

            if ($user !== null) {
                $members[] = $user;
            }
        }

        return $members;
    }
}
