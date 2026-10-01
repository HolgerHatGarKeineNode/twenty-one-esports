<?php

namespace App\Support\SeasonChain;

use App\Games\GameRegistry;
use App\Jobs\NotifyBlockZero;
use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Rating\RatingSettings;
use App\Support\Rating\SoftReset;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Release of Block 0 (NIP rev. 5, "Season Genesis", decisions 11 and 13):
 * ONE board admin (the admin list), after retyping the supply, signs a
 * release label (`1985`, `l` = `release-block-0`) that carries the parameter
 * digest. The league then signs, in one transaction, the admin list
 * (`30000`) if the board changed, the season announcement (`31923`) and the
 * Season Genesis (`2156`) with the label, the admin list and the admin as
 * `p` with role `release`, writes the season row and opens the ladders
 * (`32152`, LadderEvents). Everything goes to the league relays after the
 * commit, and every player who asked to be told hears it (NotifyBlockZero).
 *
 * The draft is the chain draft of the board (ChainDraft, P43: supply,
 * subsidy, weights, share groups, shares, daily limits, eras, claim window,
 * trust minimum and the genesis message) and the rating draft
 * (RatingSettings). The admin page carries the hash of both from the moment
 * the admin reads them to the signed release: a draft that changed in
 * between is refused, so Block 0 signs exactly what was on the screen. The
 * supply is the most the season pays out after it ends, not money held now:
 * nothing checks a balance (user decision 2026-09-28). The first release is
 * the Pre-Season; every later
 * one follows the board's plan (SeasonPlans, P38): its slug, name and
 * length, from its planned Block 0 on and only once the season before it has
 * ended. Such a release is a season transition (NIP "Season transition"):
 * the league closes the ladders of the season before (a last version with
 * `ends`), seeds the new rated ratings with the soft reset (SoftReset) and
 * opens the new ladders with `reset` and `seed`. The ratings, attestations
 * and signed events of the season before are never changed; casual ratings
 * have no season and are not touched.
 *
 * Fail closed: without the league key or the trust key, for anyone not on the board, with a
 * wrong supply, without a genesis message, with a draft that changed, while
 * a season is live, without a plan, before the planned Block 0, or with a
 * label that is not exactly the prepared one, nothing is signed and nothing
 * is written.
 */
final class SeasonRelease
{
    public const LABEL = 1985;

    public const ADMIN_LIST = 30000;

    public const ANNOUNCEMENT = 31923;

    public const NAMESPACE = 'space.einundzwanzig.esports';

    public const RELEASE_LABEL = 'release-block-0';

    public const SLUG = 'pre-season';

    public const CONSENSUS = 'season-chain-v1';

    /** Genesis tags that the parameter digest covers (`group` from NIP rev. 9.5 on, `solo` from rev. 9.18 on). */
    private const DIGEST_TAGS = ['season', 'supply', 'subsidy', 'weight', 'group', 'share', 'daily', 'pairlimit', 'subtree', 'moves', 'solo', 'halving', 'ends', 'claim', 'consensus'];

    public const MESSAGE_MAX = 280;

    public function __construct(private SignedEventGate $gate) {}

    /**
     * The chain draft (ChainDraft) as the genesis signs it, for the
     * Pre-Season or, once a season exists, for the planned next season (its
     * slug).
     *
     * @return array{slug: string, supply: int, subsidy: int, halving_seconds: int, weeks: int, claim_seconds: int, minimum_trust: int, message: string|null, parameters: array{weights: array<string, int>, groups: array<string, list<string>>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, solo?: array{0: int, 1: int, 2: int}}}
     */
    public static function draft(): array
    {
        $chain = ChainDraft::current();

        return [
            'slug' => SeasonPlans::current()->slug ?? self::SLUG,
            'supply' => $chain['supply'],
            'subsidy' => $chain['subsidy'],
            'halving_seconds' => $chain['halving_days'] * 86400,
            'weeks' => ChainDraft::weeks($chain),
            'claim_seconds' => $chain['claim_days'] * 86400,
            'minimum_trust' => $chain['trust_minimum'],
            'message' => $chain['message'],
            'parameters' => [
                'weights' => $chain['weights'],
                'groups' => $chain['groups'],
                'shares' => $chain['shares'],
                'daily' => $chain['daily'],
                'pairlimit' => $chain['pairlimit'],
                'subtree' => $chain['subtree'],
                'moves' => $chain['moves'],
                // NIP rev. 9.18: the solo rules are signed only when a score game mines in this season (a weight).
                ...(array_any(array_keys($chain['weights']), fn (string $key): bool => app(GameRegistry::class)->isScore(explode('/', $key, 2)[0]))
                    ? ['solo' => [ConsensusParameters::SOLO_ENTRANTS, ConsensusParameters::SOLO_WINS, ConsensusParameters::SOLO_REVIEW]] : []),
            ],
        ];
    }

    /** What the admin page carries from reading the draft to the release (ChainDraft::hash()). */
    public static function draftHash(): string
    {
        return ChainDraft::hash(ChainDraft::current());
    }

    /**
     * The planned end of a season released now: the Pre-Season runs the
     * draft's weeks, a planned season its planned weeks.
     */
    public static function plannedEnd(CarbonImmutable $now): int
    {
        return $now->getTimestamp() + self::draft()['weeks'] * 604800;
    }

    /** "Pre-Season", or the planned season's name. */
    public static function seasonName(): string
    {
        return SeasonPlans::current()->name ?? 'Pre-Season';
    }

    /**
     * Why this admin cannot release now, or null.
     */
    public static function refusal(?User $admin): ?string
    {
        if ($admin === null || ! Board::contains($admin->pubkey)) {
            return __('Only a board member on the public admin list can release Block 0.');
        }

        $latest = Seasons::latest();

        if ($latest !== null) {
            if ($latest->ends_at->isFuture()) {
                return __('A season is live. Only one chain runs at a time: the next season can be released once :season has ended.', ['season' => $latest->slug]);
            }

            $plan = SeasonPlans::current();

            if ($plan === null) {
                return __('Plan the next season first: its name, Block 0, length and carry-over factor.');
            }

            if ($plan->starts_at->isFuture()) {
                return __(':season is planned for Block 0 on :when (UTC). It can be released from then on.', ['season' => $plan->name, 'when' => $plan->starts_at->utc()->format('Y-m-d H:i')]);
            }
        }

        if (LeagueKey::fromConfig() === null) {
            return __('The league key is not set on this server, so Block 0 cannot be released. Rated play stays closed.');
        }

        // The ladders carry `trust` from their first version on, and its presence is frozen for the season.
        if (LeagueKey::trust() === null) {
            return __('The trust key is not set on this server, so the ladders cannot name their trust gate and Block 0 cannot be released. Rated play stays closed.');
        }

        return null;
    }

    /** @throws SeasonReleaseRefused */
    private function trustKey(): string
    {
        return LeagueKey::trust()?->pubkey() ?? throw new SeasonReleaseRefused(__('The trust key is not set on this server, so the ladders cannot name their trust gate and Block 0 cannot be released. Rated play stays closed.'));
    }

    /**
     * The unsigned release label for the admin to sign.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws SeasonReleaseRefused
     */
    public function prepare(User $admin, string $retypedSupply, int $endsAt, string $draftHash): array
    {
        $league = $this->check($admin, $retypedSupply, $endsAt, $draftHash);

        return $this->labelTemplate($league, $endsAt);
    }

    /**
     * @param  mixed  $signed  the signed label, as the browser sent it
     *
     * @throws SeasonReleaseRefused|RejectedEvent
     */
    public function release(User $admin, string $retypedSupply, int $endsAt, string $draftHash, mixed $signed): Season
    {
        $league = $this->check($admin, $retypedSupply, $endsAt, $draftHash);
        $template = $this->labelTemplate($league, $endsAt);
        $label = $this->gate->check(is_array($signed) && array_is_list($signed) ? ($signed[0] ?? null) : $signed, $template, $admin);
        $draft = self::draft();
        $message = (string) $draft['message'];
        $rating = RatingSettings::draft();
        $plan = SeasonPlans::current();
        $name = self::seasonName();

        try {
            return DB::transaction(function () use ($league, $admin, $message, $endsAt, $draftHash, $label, $draft, $rating, $plan, $name): Season {
                // The draft may not change between the admin's check and the genesis: read it again, not from the request memo.
                RatingSettings::forget();

                if (! hash_equals(self::draftHash(), $draftHash)) {
                    throw new SeasonReleaseRefused(self::draftChanged());
                }

                // A planned season continues the one it was planned after; lock it, so two releases serialize.
                $previous = $plan === null ? null : Season::query()->whereKey($plan->after_season_id)->lockForUpdate()->first();

                if ($plan !== null && ($previous === null || $previous->ends_at->isFuture() || Season::query()->where('previous_season_id', $previous->id)->exists())) {
                    throw new SeasonReleaseRefused(__('This season has been released already. Only one chain runs at a time.'));
                }

                $labelEvent = NostrEvent::fromSigned($label);
                PublishNostrEvent::dispatch($labelEvent);

                $adminList = $this->adminList($league);
                $genesisAt = max(now()->getTimestamp(), $label->createdAt);

                // NIP "Season transition", step 2: the ladders of the season before close with `ends` first.
                if ($previous !== null) {
                    app(LadderEvents::class)->close($previous, $league, $this->trustKey(), 'Season closed for '.$draft['slug'].'.');
                }

                $announcement = $plan === null
                    ? $league->publish(self::ANNOUNCEMENT, $this->announcementTags(self::SLUG, 'Pre-Season', $genesisAt, $endsAt), 'Pre-Season: build your clan, find opponents, mine the first blocks.', $genesisAt)
                    : $this->announce($league, $plan->slug, $name, $genesisAt, $endsAt);

                $tags = [
                    ...self::parameterTags($draft, $endsAt),
                    ['a', $this->announcementAddress($league), ''],
                    ['e', $adminList->event_id, '', $adminList->pubkey],
                    ['e', $labelEvent->event_id, '', $labelEvent->pubkey],
                    ['p', $admin->pubkey, '', 'release'],
                    ['alt', 'Season genesis: TWENTY ONE Esports '.$name.', Block 0'],
                ];

                if (self::digest($message, $tags) !== $label->tag('x')) {
                    throw new SeasonReleaseRefused(__('The signed release does not match these parameters. Please start again.'));
                }

                $genesis = $league->publish(SeasonChains::GENESIS, $tags, $message, $genesisAt);

                $season = Season::query()->create([
                    'slug' => $draft['slug'],
                    'previous_season_id' => $previous?->id,
                    'reset_factor_milli' => $plan?->reset_factor_milli,
                    'league_pubkey' => $league->pubkey(),
                    'supply' => $draft['supply'],
                    'subsidy' => $draft['subsidy'],
                    'halving_seconds' => $draft['halving_seconds'],
                    'claim_seconds' => $draft['claim_seconds'],
                    'minimum_trust' => $draft['minimum_trust'],
                    'parameters' => $draft['parameters'],
                    // The admin's rating draft, frozen here as the ladders below freeze it in their tags.
                    'rating_parameters' => $rating,
                    'genesis_message' => $message,
                    'digest' => (string) $label->tag('x'),
                    'genesis_at' => CarbonImmutable::createFromTimestamp($genesisAt),
                    'ends_at' => CarbonImmutable::createFromTimestamp($endsAt),
                    'genesis_event_id' => $genesis->id,
                    'release_event_id' => $labelEvent->id,
                    'admin_list_event_id' => $adminList->id,
                    'announcement_event_id' => $announcement->id,
                    'released_by_id' => $admin->id,
                    'released_by_pubkey' => $admin->pubkey,
                ]);

                // The soft reset: every entity with a rated result in the season before starts at its seed.
                if ($previous !== null) {
                    SoftReset::apply($previous, $season);
                }

                // The first version of every ladder, right after the genesis (NIP "Season transition"),
                // with `reset` and `seed` when it continues a season.
                app(LadderEvents::class)->publish($season, $league, $this->trustKey());

                // "Notify me at Block 0" is the Pre-Season's: everyone who asked, after the commit.
                if ($previous === null) {
                    NotifyBlockZero::dispatch(NotifyBlockZero::RELEASED);
                }

                return $season;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SeasonReleaseRefused(__('This season has been released already. Only one chain runs at a time.'));
        }
    }

    /**
     * Publish a version of a season's announcement (`31923`, NIP-52): the
     * countdown to its planned Block 0 while it is planned, the real times
     * once it is released. A new version is always newer than the stored one.
     */
    public function announce(LeagueKey $league, string $slug, string $name, int $start, int $end): NostrEvent
    {
        $latest = NostrEvent::query()->where(['kind' => self::ANNOUNCEMENT, 'pubkey' => $league->pubkey(), 'd' => 'season/'.$slug])->max('signed_at');
        $createdAt = max(now()->getTimestamp(), is_numeric($latest) ? (int) $latest + 1 : 0);

        return $league->publish(self::ANNOUNCEMENT, $this->announcementTags($slug, $name, $start, $end), $name.': the next season of TWENTY ONE Esports. Rated play and mining start at Block 0.', $createdAt);
    }

    /**
     * NIP "Parameter digest": SHA-256 of the JSON of [content, P], P the
     * digest tags of the genesis in event order.
     *
     * @param  list<list<string>>  $tags
     */
    public static function digest(string $content, array $tags): string
    {
        $covered = array_values(array_filter($tags, fn (array $tag): bool => in_array($tag[0] ?? '', self::DIGEST_TAGS, true)));

        return hash('sha256', (string) json_encode([$content, $covered], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** `1000` thousandths = "1", `2500` = "2.5". */
    public static function factor(int $milli): string
    {
        return rtrim(rtrim(sprintf('%d.%03d', intdiv($milli, 1000), $milli % 1000), '0'), '.');
    }

    /**
     * The newest admin list, or a new version when the board changed.
     */
    public function adminList(LeagueKey $league): NostrEvent
    {
        $d = 'esports/'.$league->pubkey().'/admins';
        $board = Board::pubkeys();
        sort($board);

        $latest = NostrEvent::query()->where(['kind' => self::ADMIN_LIST, 'pubkey' => $league->pubkey(), 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->first();

        if ($latest !== null) {
            $listed = array_values(array_map(fn (array $tag): string => (string) ($tag[1] ?? ''), array_filter($latest->payload()['tags'] ?? [], fn (array $tag): bool => ($tag[0] ?? '') === 'p')));
            sort($listed);

            if ($listed === $board) {
                return $latest;
            }
        }

        $tags = [['d', $d], ['title', 'TWENTY ONE Esports: admins'], ['description', 'The board of the association. Each may release Block 0 and change season parameters.']];

        foreach ($board as $pubkey) {
            $tags[] = ['p', $pubkey];
        }

        $tags[] = ['alt', 'Follow set: admins of TWENTY ONE Esports'];

        // A new version must be newer than the one relays keep.
        $createdAt = max(now()->getTimestamp(), ($latest->signed_at ?? 0) + 1);

        return $league->publish(self::ADMIN_LIST, $tags, '', $createdAt);
    }

    /**
     * @throws SeasonReleaseRefused
     */
    private function check(User $admin, string $retypedSupply, int $endsAt, string $draftHash): LeagueKey
    {
        $refusal = self::refusal($admin);

        if ($refusal !== null) {
            throw new SeasonReleaseRefused($refusal);
        }

        if (! hash_equals(self::draftHash(), $draftHash)) {
            throw new SeasonReleaseRefused(self::draftChanged());
        }

        $draft = self::draft();
        $supply = $draft['supply'];

        if (preg_replace('/[\s\x{00A0}\x{202F}.,_]/u', '', $retypedSupply) !== (string) $supply) {
            throw new SeasonReleaseRefused(__('Type the supply exactly as shown: :supply sats.', ['supply' => number_format($supply, 0, '', ' ')]));
        }

        if ($draft['message'] === null) {
            throw new SeasonReleaseRefused(__('Write the genesis message in the chain draft first, up to :max characters.', ['max' => self::MESSAGE_MAX]));
        }

        // The end is fixed when the admin starts the release (a locked value of
        // the page); a stale or tampered one is refused.
        $expected = self::plannedEnd(CarbonImmutable::now());

        if ($endsAt > $expected || $endsAt < $expected - 3600) {
            throw new SeasonReleaseRefused(__('This release has expired. Please start again.'));
        }

        // A plan can shorten the season after the draft was saved.
        if ($draft['halving_seconds'] > $draft['weeks'] * 604800) {
            throw new SeasonReleaseRefused(__('An era of :days days is longer than the season of :weeks weeks.', ['days' => intdiv($draft['halving_seconds'], 86400), 'weeks' => $draft['weeks']]));
        }

        return LeagueKey::required();
    }

    private static function draftChanged(): string
    {
        return __('The draft changed since you opened this page. Check the numbers again, then release.');
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private function labelTemplate(LeagueKey $league, int $endsAt): array
    {
        $draft = self::draft();
        $supply = $draft['supply'];

        return [
            'kind' => self::LABEL,
            'tags' => [
                ['L', self::NAMESPACE],
                ['l', self::RELEASE_LABEL, self::NAMESPACE],
                ['a', $this->announcementAddress($league), ''],
                ['x', self::digest((string) $draft['message'], self::parameterTags($draft, $endsAt))],
                ['alt', 'Label: release of Block 0 of the TWENTY ONE Esports '.self::seasonName()],
            ],
            'content' => 'Release Block 0 of the '.self::seasonName().". Supply retyped: {$supply}.",
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * The genesis tags the digest covers, in NIP order, for a draft
     * (self::draft()): what Block 0 signs, and what the admin page shows
     * before the release ("What Block 0 signs"). A share group is a `group`
     * row before the shares; a draft without one signs exactly the tags of
     * NIP rev. 5. `solo` (NIP rev. 9.18) follows `moves` only when a score
     * game mines.
     *
     * @param  array{slug: string, supply: int, subsidy: int, halving_seconds: int, claim_seconds: int, parameters: array{weights: array<string, int>, groups?: array<string, list<string>>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, solo?: array{0: int, 1: int, 2: int}}}  $draft
     * @return list<list<string>>
     */
    public static function parameterTags(array $draft, int $endsAt): array
    {
        $p = $draft['parameters'];
        $tags = [['season', $draft['slug']], ['supply', (string) $draft['supply']], ['subsidy', (string) $draft['subsidy']]];

        foreach ($p['weights'] as $key => $milli) {
            $tags[] = ['weight', (string) $key, self::factor($milli)];
        }

        foreach ($p['groups'] ?? [] as $group => $games) {
            $tags[] = ['group', (string) $group, ...$games];
        }

        foreach ($p['shares'] as $game => $percent) {
            $tags[] = ['share', (string) $game, (string) $percent];
        }

        foreach ($p['daily'] as $game => $blocks) {
            $tags[] = ['daily', (string) $game, (string) $blocks];
        }

        return [
            ...$tags,
            ['pairlimit', (string) $p['pairlimit'][0], (string) $p['pairlimit'][1]],
            ['subtree', (string) $p['subtree']],
            ['moves', (string) $p['moves']],
            ...(isset($p['solo']) ? [['solo', ...array_map(strval(...), $p['solo'])]] : []),
            ['halving', (string) $draft['halving_seconds']],
            ['ends', (string) $endsAt],
            ['claim', (string) $draft['claim_seconds']],
            ['consensus', self::CONSENSUS],
        ];
    }

    private function announcementAddress(LeagueKey $league): string
    {
        return self::ANNOUNCEMENT.':'.$league->pubkey().':season/'.self::draft()['slug'];
    }

    /**
     * @return list<list<string>>
     */
    private function announcementTags(string $slug, string $name, int $start, int $end): array
    {
        return [
            ['d', 'season/'.$slug],
            ['title', 'TWENTY ONE Esports '.$name.': Block 0'],
            ['summary', $name.' starts at Block 0. Rated play and mining run until the end of the season.'],
            ['start', (string) $start],
            ['end', (string) $end],
            ['start_tzid', (string) config('esports.preseason.display_timezone', 'UTC')],
            ['location', (string) config('app.url')],
            ['alt', 'Calendar event: TWENTY ONE Esports '.$name.', Block 0 at '.gmdate('Y-m-d H:i', $start).' UTC'],
        ];
    }
}
