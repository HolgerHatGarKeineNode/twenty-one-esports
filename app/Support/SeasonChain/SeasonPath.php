<?php

namespace App\Support\SeasonChain;

use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Board\RatedBoard;
use App\Support\Chess\ChessModes;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\ProfileStandard;
use App\Support\GameNames;
use App\Support\Hyper\HyperMining;
use App\Support\LeagueTime;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Your path to Season 1" (plan "Season vs. Casual", P4): the gate of rated
 * play turned into the steps a player can see and take. The rules stay
 * where they are (RatedChess, RatedBoard, RatedTrustGate, FairPlay,
 * ProfileStandard, Opponents); this class only reads them.
 *
 * Three answers:
 *
 * - `blocked`: something the player cannot fix here stops rated play first:
 *   no season live (`season`), the game or mode not in the running season
 *   yet (`later`), the rated queue off or trust ranks not computed
 *   (`closed`), a fair-play bar (`barred`) or an impersonation check
 *   (`held`). The view shows that message and no button.
 * - `steps`: every step of the way, done or not, each with one sentence and
 *   at most one button: the profile standard's marks (only while the
 *   standard applies to the player), the trust rank against the minimum,
 *   and a mutual opponent (with a concrete opponent in context: that one).
 * - `eligible`: every step done; the mutual opponents by name.
 *
 * Arrays only, except the opponent model on the opponent step (the view's
 * add button needs it); never cached.
 *
 * @phpstan-type Step array{key: 'known'|'nip05'|'profile'|'trust'|'opponents'|'opponent', done: bool, text: string, short: string, href: string|null, cta: string|null, player: User|null}
 * @phpstan-type Path array{state: 'blocked'|'steps'|'eligible', kind: string|null, message: string|null, season: string, steps: list<Step>, mutual: list<array{name: string, href: string}>, rank: int, minimum: int}
 */
final class SeasonPath
{
    public function __construct(private RatedTrustGate $gate, private TrustFacts $facts, private Opponents $opponents) {}

    /**
     * The path of this player to rated play in a game and mode (both null:
     * any game, as /mining and the hub ask), against a concrete opponent
     * when one is in context.
     *
     * @return Path
     */
    public function for(User $user, ?string $game = null, ?string $mode = null, ?User $opponent = null): array
    {
        $season = Ladders::season();
        $path = ['state' => 'blocked', 'kind' => null, 'message' => null, 'season' => $season === null ? __('Season') : BadgeCopy::season($season),
            'steps' => [], 'mutual' => [], 'rank' => 0, 'minimum' => RatedTrustGate::minimum()];

        if (($blocker = $this->blocker($user, $game, $mode)) !== null) {
            return [...$path, ...$blocker];
        }

        $steps = [];

        // The profile standard, only while it applies: off, met by a member, or exempt, it is no step at all.
        if (ProfileStandard::enabled() && ! ProfileStandard::isExempt($user)) {
            $missing = ProfileStandard::missing($user);
            $steps[] = in_array(ProfileStandard::KNOWN, $missing, true)
                ? $this->step('known', false, __('Wait until :time: an account plays rated from :days days after its first login here.', ['time' => LeagueTime::stamp(ProfileStandard::knownAt($user)), 'days' => ProfileStandard::knownDays()]), short: __('Rated from :time', ['time' => LeagueTime::stamp(ProfileStandard::knownAt($user))]))
                : $this->step('known', true, __('Known to the league for :days days', ['days' => ProfileStandard::knownDays()]));
            $steps[] = in_array(ProfileStandard::NIP05, $missing, true)
                ? $this->step('nip05', false, __('Set a NIP-05 address, for example the league\'s own name.'), route('settings.nip05'), __('Set your Nostr address'), __('No NIP-05 address'))
                : $this->step('nip05', true, __('NIP-05 address set'));
            $steps[] = in_array(ProfileStandard::PROFILE, $missing, true)
                ? $this->step('profile', false, __('Set a name and a profile picture.'), route('settings.profile'), __('Edit your profile'), __('No name or profile picture'))
                : $this->step('profile', true, __('Name and profile picture set'));
        }

        // The trust rank the gate reads. It comes only from anchors, opponent lists and confirmed reports (TrustJob):
        // games played are no input, so the step never says they raise it (design-lead review, finding 1).
        $rank = $this->trustRank($user);
        $minimum = RatedTrustGate::minimum();
        $steps[] = $rank >= $minimum
            ? $this->step('trust', true, __('Trust rank :rank, needs :minimum', ['rank' => $rank, 'minimum' => $minimum]))
            : $this->step('trust', false, __('Trust rank :rank, needs :minimum. It rises when members or Trusted players list you as an opponent.', ['rank' => $rank, 'minimum' => $minimum]), route('settings.opponents').'#trust', __('How it rises'), __('Trust rank :rank, needs :minimum', ['rank' => $rank, 'minimum' => $minimum]));

        $mutual = $this->opponents->mutual($user);

        // With a concrete opponent the step is the pair gate itself (third review, finding 2): the two list each other
        // AND the opponent passes the gate too, so "eligible" never shows for a pair the gate refuses.
        $pairRefused = false;

        if ($opponent !== null && $opponent->id !== $user->id) {
            $pin = $this->gate->pin([$user->pubkey, $opponent->pubkey], [$user->pubkey, $opponent->pubkey]);
            $pairRefused = $pin->refusal() !== null;
            $blocker = $this->opponentBlocker($opponent, $pin);

            if ($blocker !== null) {
                $steps[] = $this->step('opponent', false, $blocker);
            } elseif (! in_array($opponent->pubkey, $mutual, true)) {
                $steps[] = [...$this->step('opponent', false, __('Add :name as an opponent; they add you back.', ['name' => $opponent->displayName()])), 'player' => $opponent];
            } else {
                // The opponent's side holds; what still refuses the pair is the viewer's own step above.
                $steps[] = $this->step('opponent', true, __('You and :name list each other as opponents', ['name' => $opponent->displayName()]));
            }
        } else {
            $steps[] = $mutual !== []
                ? $this->step('opponents', true, trans_choice(':count mutual opponent|:count mutual opponents', count($mutual)))
                : $this->step('opponents', false, __('Add an opponent who adds you back.'), route('settings.opponents'), __('Your opponents'), __('No mutual opponent'));
        }

        $names = $mutual === [] ? [] : array_values(User::query()->whereIn('pubkey', array_slice($mutual, 0, 6))->orderBy('id')->get()
            ->map(fn (User $player): array => ['name' => $player->displayName(), 'href' => route('players.show', $player->npub)])->all());
        $eligible = ! $pairRefused && collect($steps)->every(fn (array $step): bool => $step['done']);

        return [...$path, 'state' => $eligible ? 'eligible' : 'steps', 'steps' => $steps, 'mutual' => $names, 'rank' => $rank, 'minimum' => $minimum];
    }

    /**
     * The trust rank the gate reads for this player (the raw fact: a barred
     * player is left out of a pin and would read 0 there); 0 before a run.
     */
    public function trustRank(User $user): int
    {
        return (int) ($this->facts->at([$user->pubkey], [$user->pubkey, $user->pubkey])['trust'][$user->pubkey] ?? 0);
    }

    /**
     * What keeps this player from rated play other than a mutual opponent
     * (design-lead review, finding 2: the end of a game promised "rated next
     * time" to a player below the trust minimum). The first open profile or
     * trust step as "You still need: …" with its fix (the trust step leads to
     * the opponents page's #trust), or the blocker's message without a link;
     * null when a mutual opponent is all that is missing, or nothing is.
     *
     * With an opponent in context (third review, finding 2), the gate's other
     * half too: when the viewer's own steps are done but the opponent would
     * refuse the pair ({@see opponentBlocker()}), that sentence with the key
     * `opponent`, so the end of a game never says "rated next time" to a
     * pair the gate refuses. Whether the two list each other is no part of
     * it: that is what the add at the end of a game is for.
     *
     * @return array{key: string, text: string, href: string|null}|null
     */
    public function missing(User $user, ?User $opponent = null): ?array
    {
        $path = $this->for($user);

        if ($path['state'] === 'blocked') {
            return ['key' => (string) $path['kind'], 'text' => (string) $path['message'], 'href' => null];
        }

        foreach ($path['steps'] as $step) {
            if (! $step['done'] && ! in_array($step['key'], ['opponents', 'opponent'], true)) {
                $what = $step['key'] === 'trust' ? __('Trust rank :rank/:minimum', ['rank' => $path['rank'], 'minimum' => $path['minimum']]) : $step['short'];

                return ['key' => $step['key'], 'text' => __('You still need: :what', ['what' => $what]), 'href' => $step['href']];
            }
        }

        return $opponent !== null ? $this->opponentMissing($user, $opponent) : null;
    }

    /**
     * Only the opponent's half of {@see missing()}: what the pair gate
     * refuses on this opponent's side, or null (also for the viewer). For a
     * view that already read missing() for the viewer and has several
     * opponents (a team match), one pin each.
     *
     * @return array{key: 'opponent', text: string, href: null}|null
     */
    public function opponentMissing(User $user, User $opponent): ?array
    {
        if ($opponent->id === $user->id || ! $this->gate->isAvailable()) {
            return null;
        }

        $blocker = $this->opponentBlocker($opponent, $this->gate->pin([$user->pubkey, $opponent->pubkey], [$user->pubkey, $opponent->pubkey]));

        return $blocker === null ? null : ['key' => 'opponent', 'text' => $blocker, 'href' => null];
    }

    /**
     * Why the gate refuses this opponent's side of the pair, in neutral words
     * (no rank number of another player): below the trust minimum, or left
     * out of the pin by a fair-play bar ({@see RatedTrustGate::pin()}); null
     * while the opponent is eligible.
     */
    private function opponentBlocker(User $opponent, GatePin $pin): ?string
    {
        if ($pin->isEligible($opponent->pubkey)) {
            return null;
        }

        return isset($pin->players[$opponent->pubkey])
            ? __(':name is not Trusted yet', ['name' => $opponent->displayName()])
            : __(':name cannot play rated at the moment.', ['name' => $opponent->displayName()]);
    }

    /**
     * The members this player has played (any game: chess, a board game,
     * Proof of Pong, a Hyperbitcoinization match, a team match), by name:
     * the people who can raise their trust rank by listing them as an
     * opponent (the opponents page's #trust section, design-lead review,
     * finding 1). At most `$limit`; the last 200 games per table are read.
     *
     * @return Collection<int, User>
     */
    public function playedMembers(User $user, int $limit = 12): Collection
    {
        $ids = collect();

        foreach ([[(new ChessGame)->getTable(), 'white_id', 'black_id'], [(new BoardGame)->getTable(), 'white_id', 'black_id'], [(new PongMatch)->getTable(), 'left_id', 'right_id']] as [$table, $a, $b]) {
            $rows = DB::table($table)->where(fn ($query) => $query->where($a, $user->id)->orWhere($b, $user->id))->latest('id')->limit(200)->get([$a, $b]);
            $ids = $ids->merge($rows->pluck($a))->merge($rows->pluck($b));
        }

        $ids = $ids->merge(DB::table('hyper_seats')->whereIn('hyper_match_id', DB::table('hyper_seats')->where('user_id', $user->id)->select('hyper_match_id'))->pluck('user_id'));
        $ids = $ids->merge(DB::table('series_match_players')->whereIn('series_match_id', DB::table('series_match_players')->where('user_id', $user->id)->select('series_match_id'))->pluck('user_id'));
        $ids = $ids->filter(fn ($id): bool => $id !== null && (int) $id !== $user->id)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids->all())->where('is_member', true)->whereNotNull('pubkey')
            ->orderBy('name')->orderBy('id')->limit($limit)->get();
    }

    /**
     * What stops rated play before any step the player can take, or null.
     *
     * @return array{kind: string, message: string}|null
     */
    private function blocker(User $user, ?string $game, ?string $mode): ?array
    {
        if (Seasons::live() === null) {
            return ['kind' => 'season', 'message' => Seasons::restMessage($user)];
        }

        $registry = app(GameRegistry::class);

        if ($game !== null) {
            $mode ??= array_key_first($registry->find($game)?->modes() ?? []) ?? '';

            if ($game === 'chess') {
                if (! Ladders::isOpen('chess', $mode)) {
                    return ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::full('chess', $mode)])];
                }

                if (! RatedChess::offered()) {
                    return ['kind' => 'closed', 'message' => __('Rated chess is not open yet. Rapid and blitz games are casual for now.')];
                }
            } elseif ($registry->isBoard($game)) {
                if (! Ladders::isOpen($game, $mode)) {
                    return ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::game($game)])];
                }

                if (! RatedBoard::offered()) {
                    return ['kind' => 'closed', 'message' => __('Rated :game is not open yet. Games are casual for now.', ['game' => GameNames::game($game)])];
                }
            } elseif ($game === HyperMining::GAME) {
                // Hyperbitcoinization mines by its weight in the running season (HyperMining), not by a ladder.
                if (HyperMining::weight($mode) === 0) {
                    return ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::game($game)])];
                }
            } elseif ($registry->find($game) === null || in_array($registry->find($game)->kind(), [GameKind::Arcade, GameKind::Score], true)) {
                return ['kind' => 'none', 'message' => __('This game has no rated play.')];
            } elseif (! Ladders::isOpen($game, $mode)) {
                // A series game (a clan lineup's): rated once its ladder is in the running season.
                return ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::game($game)])];
            }
        }

        if (! $this->gate->isAvailable()) {
            return ['kind' => 'closed', 'message' => RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED)];
        }

        $kind = FairPlay::barred([$user->pubkey])[$user->pubkey]['kind'] ?? null;

        // Below the profile standard is no bar: its marks are steps the player takes here.
        if ($kind !== null && $kind !== 'standard') {
            return ['kind' => $kind === 'held' ? 'held' : 'barred', 'message' => (string) FairPlay::message($user->pubkey, $user)];
        }

        return null;
    }

    /**
     * @param  'known'|'nip05'|'profile'|'trust'|'opponents'|'opponent'  $key
     * @return Step
     */
    private function step(string $key, bool $done, string $text, ?string $href = null, ?string $cta = null, ?string $short = null): array
    {
        return ['key' => $key, 'done' => $done, 'text' => $text, 'short' => $short ?? $text, 'href' => $done ? null : $href, 'cta' => $done ? null : $cta, 'player' => null];
    }

    /**
     * The game /mining's primary button leads to (plan "Season vs. Casual",
     * P4): of the games with an open rated ladder (chess, the board games),
     * the one this player played most; without a game of theirs, the one
     * played most league-wide in the last 14 days; else the first open one.
     * Null while no ladder of these games is open.
     *
     * @return array{slug: string, name: string, href: string, rated: bool}|null
     */
    public static function gameToMine(?User $user): ?array
    {
        $registry = app(GameRegistry::class);
        $open = [];

        if ($registry->find('chess') !== null && collect(ChessModes::live())->contains(fn (string $mode): bool => Ladders::isOpen('chess', $mode))) {
            $open['chess'] = RatedChess::offered();
        }

        foreach (array_keys($registry->all()) as $slug) {
            if ($registry->isBoard($slug) && collect(array_keys($registry->find($slug)->modes()))->contains(fn (string $mode): bool => Ladders::isOpen($slug, $mode))) {
                $open[$slug] = RatedBoard::offered();
            }
        }

        if ($open === []) {
            return null;
        }

        $counts = self::playedCounts(array_keys($open), $user, null);

        if (array_sum($counts) === 0) {
            $counts = self::playedCounts(array_keys($open), null, CarbonImmutable::now()->subDays(14));
        }

        arsort($counts);
        $slug = array_sum($counts) > 0 ? (string) array_key_first($counts) : (string) array_key_first($open);

        return ['slug' => $slug, 'name' => GameNames::game($slug), 'rated' => $open[$slug],
            'href' => $slug === 'chess' ? route('chess.lobby') : route('board.lobby', $slug)];
    }

    /**
     * Games played per slug, in the order given (a tie keeps that order).
     *
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    private static function playedCounts(array $slugs, ?User $user, ?CarbonImmutable $since): array
    {
        $counts = array_fill_keys($slugs, 0);
        $player = fn ($query) => $user === null ? $query : $query->where(fn ($q) => $q->where('white_id', $user->id)->orWhere('black_id', $user->id));

        if (array_key_exists('chess', $counts)) {
            $counts['chess'] = $player(ChessGame::query())->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))->count();
        }

        $board = $player(BoardGame::query())->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->whereIn('game', $slugs)->selectRaw('game, count(*) as played')->groupBy('game')->pluck('played', 'game');

        foreach ($board as $slug => $played) {
            $counts[(string) $slug] = (int) $played;
        }

        return $counts;
    }
}
