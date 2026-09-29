<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentLanding;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The upcoming tournaments the stream's tournament slides (T-hero,
 * T-bracket; resources/views/stream/rotation/t*) show: every tournament
 * open for sign-up, soonest sign-up close first, as the index page lists
 * its "next" one.
 *
 * Everything is read the way the public tournament page reads it
 * (TournamentLanding: places, seeded roster, projected first round,
 * deadline), so the stream never shows a number the page does not. The
 * database part is cached for `twentyone.stream.stats.cache_seconds`;
 * the countdown is computed from the frame's clock every second, and a
 * tournament whose sign-up closed drops out at once, not at the next read.
 * Covers are read once per game for the process lifetime.
 *
 * Every roster row says how many places it takes (`seats`: a lineup its
 * team size, a player one), so a seat map can give each taken seat its face.
 * A team tournament's solo sign-ups have no seed before the draw (they are
 * drawn into mix teams), so they stay out of the roster and the preview;
 * they take one place each and are listed apart, in sign-up order, as
 * `solos` (`name`, `avatar`), for the seat map only.
 *
 * Pictures join in frame(), from plain refs the snapshot keeps per seed
 * (StreamImages): every roster row and every preview side with a name gets
 * `avatar` (a player's picture or Blockpile; null for a lineup, a mix team
 * or an open spot) and `logo` (a lineup's clan logo, if we redrew it; else
 * null); the slide gets `backdrop`, its game's blurred cover, and
 * `coverTile`, its cover at the size of d2's tile (null until built).
 */
class TournamentSlides
{
    public const CACHE_KEY = 'twentyone.stream.tournaments';

    /** Seeds the "Who plays" list shows. */
    public const ROSTER = 8;

    /** Solo sign-ups kept for the seat map, which draws at most this many places (RotationKit::seats()). */
    public const SOLOS = 32;

    /** @var array<string, string|null> game slug => cover as a data URI, null without a file */
    private static array $covers = [];

    public function __construct(private GameRegistry $games, private StreamImages $images) {}

    /**
     * Tournaments open for sign-up, soonest sign-up close first
     * (the query of pages::tournaments.index `next()`), of games switched on.
     *
     * @return Collection<int, Tournament>
     */
    public function upcoming(): Collection
    {
        // A game the league switched off (the board games while they are off) is unknown to the registry: no slides.
        return Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->orderBy('signup_closes_at')->orderBy('id')->get()
            ->filter(fn (Tournament $tournament): bool => $this->games->find($tournament->game) !== null)->values();
    }

    /**
     * The slide data of every upcoming tournament at `$nowMs`, in order;
     * a tournament whose sign-up has closed by then is left out.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $nowMs): array
    {
        return $this->frames($this->snapshots(), $nowMs);
    }

    /**
     * frames of snapshots() read earlier (the supervisor keeps the last
     * ones while the database fails), open ones only.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, mixed>>
     */
    public function frames(array $snapshots, int $nowMs): array
    {
        $slides = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot['closesMs'] !== null && $snapshot['closesMs'] > $nowMs) {
                $slides[] = $this->frame($snapshot, $nowMs);
            }
        }

        return $slides;
    }

    /**
     * The database part of all(), cached; counted directly when the cache store fails.
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
     * One tournament as the slides' data contract describes it
     * (the docblock of resources/views/stream/rotation/ta1-hero.blade.php).
     *
     * @return array<string, mixed>
     */
    public function data(Tournament $tournament, int $nowMs): array
    {
        return $this->frame($this->snapshot($tournament), $nowMs);
    }

    /**
     * The ids of the tournaments that get their own slides (hero, bracket,
     * the cover): every one but the casual cups, which d2 shows together.
     *
     * @param  list<array<string, mixed>>  $frames  frames() of this poll
     * @return list<int>
     */
    public static function featured(array $frames): array
    {
        $ids = [];

        foreach ($frames as $frame) {
            if (($frame['cup'] ?? false) !== true && is_int($frame['id'] ?? null)) {
                $ids[] = $frame['id'];
            }
        }

        return $ids;
    }

    /**
     * "6d 06:05:01" up to the deadline, "06:05:01" below a day, "00:00:00" once it passed.
     */
    public static function countdown(int $deadlineMs, int $nowMs): string
    {
        $seconds = max(0, intdiv($deadlineMs - $nowMs, 1000));
        $days = intdiv($seconds, 86400);

        return ($days > 0 ? $days.'d ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        return array_values($this->upcoming()->map(fn (Tournament $tournament): array => $this->snapshot($tournament))->all());
    }

    /**
     * The clock-free part of the contract, plus the deadline and the game
     * slug frame() needs; plain scalars, so the cache can hold it.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Tournament $tournament): array
    {
        // A casual cup shows its times in its region's zone (the US cups at 8 pm Eastern), every other tournament in the league's.
        $timezone = $tournament->isCasualCup() ? CasualCups::timezoneOf($tournament) : (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');
        $landing = new TournamentLanding($tournament, null);
        $places = $landing->places();
        $countdown = $landing->countdown();

        // "Who plays": the seeded entries, best first. A solo player shows the
        // public name of today, a lineup its clan's name as the roster has it.
        $names = [];
        $roster = [];
        $pictures = [];
        $solos = [];

        foreach ($landing->roster() as $row) {
            if ($row['seed'] === null) {
                // An unseeded solo sign-up of a team mode: one taken seat with the player's face, nothing else.
                if ($row['kind'] === 'solo' && count($solos) < self::SOLOS) {
                    $user = $row['users'][0] ?? null;
                    $solos[] = ['name' => PublicName::clean($user?->displayName() ?? $row['name']), 'avatar' => StreamImages::avatarRef($user)];
                }

                continue;
            }

            $user = in_array($row['kind'], ['player', 'solo'], true) ? ($row['users'][0] ?? null) : null;
            $names[$row['seed']] = PublicName::clean($user?->displayName() ?? $row['name']);
            $pictures[$row['seed']] = [
                'avatar' => StreamImages::avatarRef($user),
                'logo' => $row['kind'] === 'lineup' ? StreamImages::logoRef($row['clan']) : null,
            ];

            if (count($roster) < self::ROSTER) {
                // A lineup takes a team's places (TournamentLanding::places()), a player one.
                $roster[] = ['seed' => $row['seed'], 'name' => $names[$row['seed']], 'rating' => $row['rating'], 'seats' => $row['kind'] === 'lineup' ? $tournament->teamSize() : 1];
            }
        }

        $open = $landing->openSeats();
        $at = fn (?CarbonInterface $moment): ?string => $moment?->copy()->timezone($timezone)->format('D j M, H:i T');
        $description = PublicName::clean((string) $tournament->description);

        return [
            'id' => $tournament->id,
            'name' => PublicName::clean($tournament->name),
            'description' => $description === '' ? null : $description,
            'status' => 'Sign-up open',
            'game' => $this->games->name($tournament->game),
            'mode' => $this->games->mode($tournament->game, $tournament->mode)->name ?? $tournament->mode,
            'format' => $tournament->format->label(),
            'teamSize' => $tournament->teamSize(),
            'rated' => $tournament->openLadder() !== null,
            'where' => $tournament->on_site ? 'On site' : 'Online',
            'startsAt' => $at($tournament->starts_at),
            'signupClosesAt' => $at($tournament->signup_closes_at),
            'countdownLabel' => $countdown['label'] ?? 'Sign-up closes in',
            'taken' => $places['taken'],
            'places' => $places['places'],
            'spotsLeft' => $open,
            'roster' => $roster,
            'solos' => $solos,
            'openSpots' => $open,
            'preview' => $this->preview($landing->projection(), $names),
            // How it runs (TournamentPlaybook), for the places it was set up for; the next-tournament slide (t?7) reads it.
            'howItRuns' => TournamentPlaybook::of($tournament, $places['places'], $timezone),
            'url' => rtrim((string) config('twentyone.stream.scene.url'), '/').'/tournaments/'.$tournament->id,
            'deadlineMs' => $countdown['ms'] ?? null,
            'closesMs' => $tournament->signup_closes_at?->getTimestampMs(),
            'gameSlug' => $tournament->game,
            'pictures' => $pictures,
            // The feature slides (d1 prize pots, d2 casual cups) read these.
            'pot' => app(PrizePool::class)->shownPotSats($tournament),
            'cup' => $tournament->isCasualCup(),
            // "EU" / "US" for a casual cup (d2 lists every game's regions), null otherwise.
            'region' => $tournament->isCasualCup() ? CasualCups::regionLabel($tournament) : null,
        ];
    }

    /**
     * A snapshot at `$nowMs`: the countdown ticks, the cover joins, the
     * internal keys go.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function frame(array $snapshot, int $nowMs): array
    {
        $deadline = $snapshot['deadlineMs'];
        $slug = (string) $snapshot['gameSlug'];
        $pictures = is_array($snapshot['pictures'] ?? null) ? $snapshot['pictures'] : [];
        unset($snapshot['deadlineMs'], $snapshot['closesMs'], $snapshot['gameSlug'], $snapshot['pictures']);
        $snapshot['roster'] = array_map(fn (array $row): array => $this->pictured($row, $pictures), $snapshot['roster']);
        $snapshot['solos'] = array_map(fn (array $solo): array => [...$solo, 'avatar' => $this->images->avatar($solo['avatar'])], $snapshot['solos'] ?? []);

        if (is_array($snapshot['preview'])) {
            foreach ($snapshot['preview']['matches'] ?? [] as $index => $match) {
                $snapshot['preview']['matches'][$index]['sides'] = array_map(fn (array $side): array => $this->pictured($side, $pictures), $match['sides']);
            }

            foreach ($snapshot['preview']['groups'] ?? [] as $group => $sides) {
                $snapshot['preview']['groups'][$group] = array_map(fn (array $side): array => $this->pictured($side, $pictures), $sides);
            }
        }

        return [
            ...$snapshot,
            'countdown' => self::countdown(is_int($deadline) ? $deadline : $nowMs, $nowMs),
            'cover' => $this->cover($slug),
            'backdrop' => $this->images->backdrop($slug),
            'coverTile' => $this->images->coverTile($slug),
        ];
    }

    /**
     * A roster row or preview side with its seed's pictures; an open spot
     * (no name) or an unknown seed has none.
     *
     * @param  array<string, mixed>  $entry  with `seed` and `name`
     * @param  array<int, array{avatar: array{id: int, pubkey: string, source: string|null}|null, logo: string|null}>  $pictures
     * @return array<string, mixed>
     */
    private function pictured(array $entry, array $pictures): array
    {
        $picture = $entry['name'] === null ? null : ($pictures[$entry['seed']] ?? null);

        return [
            ...$entry,
            'avatar' => $this->images->avatar($picture['avatar'] ?? null),
            'logo' => $this->images->logo($picture['logo'] ?? null),
        ];
    }

    /**
     * The projected first round (TournamentLanding::projection()) in the
     * contract's shape; null names are open spots (or mix teams to come).
     *
     * @param  array{matches: list<array{key: string, group: int|null, sides: list<array{seed: int, name: string|null, mix: bool, you: bool}>}>, byes: list<int>, groups: array<int, list<array{seed: int, name: string|null, mix: bool, you: bool}>>}|null  $projection
     * @param  array<int, string>  $names  seed => public name
     * @return array<string, mixed>|null
     */
    private function preview(?array $projection, array $names): ?array
    {
        if ($projection === null) {
            return null;
        }

        if ($projection['groups'] !== []) {
            $groups = [];

            foreach ($projection['groups'] as $number => $members) {
                // Group 1 is "A", as the tournament page names them; past "Z" the number stays.
                $groups[$number >= 1 && $number <= 26 ? chr(64 + $number) : (string) $number] = array_map(fn (array $side): array => $this->side($side, $names), $members);
            }

            return ['kind' => 'groups', 'groups' => $groups, 'byes' => $projection['byes'], 'stageNote' => 'Groups if sign-up closed now'];
        }

        return [
            'kind' => 'bracket',
            'matches' => array_map(fn (array $match): array => ['sides' => array_map(fn (array $side): array => $this->side($side, $names), $match['sides'])], $projection['matches']),
            'byes' => $projection['byes'],
            'stageNote' => 'Round 1 if sign-up closed now',
        ];
    }

    /**
     * @param  array{seed: int, name: string|null, mix: bool, you: bool}  $side
     * @param  array<int, string>  $names
     * @return array{seed: int, name: string|null}
     */
    private function side(array $side, array $names): array
    {
        return ['seed' => $side['seed'], 'name' => $side['name'] === null ? null : ($names[$side['seed']] ?? PublicName::clean($side['name']))];
    }

    /**
     * The game's largest JPEG cover as a data URI, read once per slug.
     */
    private function cover(string $slug): ?string
    {
        if (! array_key_exists($slug, self::$covers)) {
            self::$covers[$slug] = self::coverUri($this->games->coverPath($slug));
        }

        return self::$covers[$slug];
    }

    /**
     * A JPEG file as a data URI; null when there is none or it cannot be read.
     * A file that exists but is unreadable makes file_get_contents throw (the
     * framework turns the warning into an ErrorException): that must cost this
     * one cover, not every tournament's slides.
     */
    public static function coverUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            $bytes = file_get_contents($path);
        } catch (Throwable) {
            return null;
        }

        return $bytes === false || $bytes === '' ? null : 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
}
