<?php

namespace App\Support\SeasonChain;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
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
 * commit.
 *
 * The draft is config/season.php (the Pre-Season defaults) plus the genesis
 * message the admin types. Only the Pre-Season can be released here: later
 * seasons need a season planner that does not exist yet.
 *
 * Fail closed: without the league key or the trust key, for anyone not on the board, with a
 * wrong supply, while a season exists, or with a label that is not exactly
 * the prepared one, nothing is signed and nothing is written.
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

    /** Genesis tags that the parameter digest covers, in this order. */
    private const DIGEST_TAGS = ['season', 'supply', 'subsidy', 'weight', 'share', 'daily', 'pairlimit', 'subtree', 'moves', 'halving', 'ends', 'claim', 'consensus'];

    public const MESSAGE_MAX = 280;

    public function __construct(private SignedEventGate $gate) {}

    /**
     * The Pre-Season draft of config/season.php.
     *
     * @return array{slug: string, supply: int, subsidy: int, halving_seconds: int, eras: int, claim_seconds: int, minimum_trust: int, parameters: array{weights: array<string, int>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int}}
     */
    public static function draft(): array
    {
        /** @var array{supply: int, subsidy: int, weights: array<string, int>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, halving_seconds: int, eras: int, claim_seconds: int} $chain */
        $chain = config('season.chain');

        return [
            'slug' => self::SLUG,
            'supply' => $chain['supply'],
            'subsidy' => $chain['subsidy'],
            'halving_seconds' => $chain['halving_seconds'],
            'eras' => $chain['eras'],
            'claim_seconds' => $chain['claim_seconds'],
            'minimum_trust' => (int) config('season.trust_minimum'),
            'parameters' => [
                'weights' => $chain['weights'],
                'shares' => $chain['shares'],
                'daily' => $chain['daily'],
                'pairlimit' => $chain['pairlimit'],
                'subtree' => $chain['subtree'],
                'moves' => $chain['moves'],
            ],
        ];
    }

    /** The planned end of a season released now: every era in full. */
    public static function plannedEnd(CarbonImmutable $now): int
    {
        $draft = self::draft();

        return $now->getTimestamp() + $draft['eras'] * $draft['halving_seconds'];
    }

    /**
     * Why this admin cannot release now, or null.
     */
    public static function refusal(?User $admin): ?string
    {
        if ($admin === null || ! Board::contains($admin->pubkey)) {
            return __('Only a board member on the public admin list can release Block 0.');
        }

        if (Season::query()->exists()) {
            return __('The Pre-Season has been released. Only one chain runs at a time, and later seasons are not planned here.');
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
    public function prepare(User $admin, string $retypedSupply, string $message, int $endsAt): array
    {
        $league = $this->check($admin, $retypedSupply, $message, $endsAt);

        return $this->labelTemplate($league, $message, $endsAt);
    }

    /**
     * @param  mixed  $signed  the signed label, as the browser sent it
     *
     * @throws SeasonReleaseRefused|RejectedEvent
     */
    public function release(User $admin, string $retypedSupply, string $message, int $endsAt, mixed $signed): Season
    {
        $league = $this->check($admin, $retypedSupply, $message, $endsAt);
        $template = $this->labelTemplate($league, $message, $endsAt);
        $label = $this->gate->check(is_array($signed) && array_is_list($signed) ? ($signed[0] ?? null) : $signed, $template, $admin);
        $draft = self::draft();

        try {
            return DB::transaction(function () use ($league, $admin, $message, $endsAt, $label, $draft): Season {
                $labelEvent = NostrEvent::fromSigned($label);
                PublishNostrEvent::dispatch($labelEvent);

                $adminList = $this->adminList($league);
                $genesisAt = max(now()->getTimestamp(), $label->createdAt);
                $announcement = $league->publish(self::ANNOUNCEMENT, $this->announcementTags($genesisAt, $endsAt), 'Pre-Season: build your clan, find opponents, mine the first blocks.', $genesisAt);

                $tags = [
                    ...$this->parameterTags($endsAt),
                    ['a', $this->announcementAddress($league), ''],
                    ['e', $adminList->event_id, '', $adminList->pubkey],
                    ['e', $labelEvent->event_id, '', $labelEvent->pubkey],
                    ['p', $admin->pubkey, '', 'release'],
                    ['alt', 'Season genesis: TWENTY ONE Esports Pre-Season, Block 0'],
                ];

                if (self::digest($message, $tags) !== $label->tag('x')) {
                    throw new SeasonReleaseRefused(__('The signed release does not match these parameters. Please start again.'));
                }

                $genesis = $league->publish(SeasonChains::GENESIS, $tags, $message, $genesisAt);

                $season = Season::query()->create([
                    'slug' => $draft['slug'],
                    'league_pubkey' => $league->pubkey(),
                    'supply' => $draft['supply'],
                    'subsidy' => $draft['subsidy'],
                    'halving_seconds' => $draft['halving_seconds'],
                    'claim_seconds' => $draft['claim_seconds'],
                    'minimum_trust' => $draft['minimum_trust'],
                    'parameters' => $draft['parameters'],
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

                // The first version of every ladder, right after the genesis (NIP "Season transition").
                app(LadderEvents::class)->publish($season, $league, $this->trustKey());

                return $season;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SeasonReleaseRefused(__('The Pre-Season has been released. Only one chain runs at a time, and later seasons are not planned here.'));
        }
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
    private function check(User $admin, string $retypedSupply, string $message, int $endsAt): LeagueKey
    {
        $refusal = self::refusal($admin);

        if ($refusal !== null) {
            throw new SeasonReleaseRefused($refusal);
        }

        $supply = self::draft()['supply'];

        if (preg_replace('/[\s\x{00A0}\x{202F}.,_]/u', '', $retypedSupply) !== (string) $supply) {
            throw new SeasonReleaseRefused(__('Type the supply exactly as shown: :supply sats.', ['supply' => number_format($supply, 0, '', ' ')]));
        }

        $message = trim($message);

        if ($message === '' || mb_strlen($message) > self::MESSAGE_MAX) {
            throw new SeasonReleaseRefused(__('Write the genesis message, up to :max characters.', ['max' => self::MESSAGE_MAX]));
        }

        // The end is fixed when the admin starts the release (a locked value of
        // the page); a stale or tampered one is refused.
        $expected = self::plannedEnd(CarbonImmutable::now());

        if ($endsAt > $expected || $endsAt < $expected - 3600) {
            throw new SeasonReleaseRefused(__('This release has expired. Please start again.'));
        }

        return LeagueKey::required();
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private function labelTemplate(LeagueKey $league, string $message, int $endsAt): array
    {
        $supply = self::draft()['supply'];

        return [
            'kind' => self::LABEL,
            'tags' => [
                ['L', self::NAMESPACE],
                ['l', self::RELEASE_LABEL, self::NAMESPACE],
                ['a', $this->announcementAddress($league), ''],
                ['x', self::digest($message, $this->parameterTags($endsAt))],
                ['alt', 'Label: release of Block 0 of the TWENTY ONE Esports Pre-Season'],
            ],
            'content' => "Release Block 0 of the Pre-Season. Supply retyped: {$supply}.",
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * The genesis tags the digest covers, in NIP order.
     *
     * @return list<list<string>>
     */
    private function parameterTags(int $endsAt): array
    {
        $draft = self::draft();
        $p = $draft['parameters'];
        $tags = [['season', $draft['slug']], ['supply', (string) $draft['supply']], ['subsidy', (string) $draft['subsidy']]];

        foreach ($p['weights'] as $key => $milli) {
            $tags[] = ['weight', (string) $key, self::factor($milli)];
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
            ['halving', (string) $draft['halving_seconds']],
            ['ends', (string) $endsAt],
            ['claim', (string) $draft['claim_seconds']],
            ['consensus', self::CONSENSUS],
        ];
    }

    private function announcementAddress(LeagueKey $league): string
    {
        return self::ANNOUNCEMENT.':'.$league->pubkey().':season/'.self::SLUG;
    }

    /**
     * @return list<list<string>>
     */
    private function announcementTags(int $start, int $end): array
    {
        return [
            ['d', 'season/'.self::SLUG],
            ['title', 'TWENTY ONE Esports Pre-Season: Block 0'],
            ['summary', 'Pre-Season starts at Block 0. Rated play and mining run until the end of the season.'],
            ['start', (string) $start],
            ['end', (string) $end],
            ['start_tzid', (string) config('esports.preseason.display_timezone', 'UTC')],
            ['location', (string) config('app.url')],
            ['t', 'esports'],
            ['t', 'season'],
            ['alt', 'Calendar event: TWENTY ONE Esports Pre-Season, Block 0 at '.gmdate('Y-m-d H:i', $start).' UTC'],
        ];
    }
}
