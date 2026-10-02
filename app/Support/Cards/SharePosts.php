<?php

namespace App\Support\Cards;

use App\Enums\ChessGameStatus;
use App\Enums\ReportStatus;
use App\Enums\TournamentStatus;
use App\Games\ScoreMetric;
use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\RankBadgeVersion;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Rating\RankTiers;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\TmnfMoments;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The share button (P11, NIP "Share posts", rev. 8; P46, rev. 9.7): a kind 1
 * note signed by the player in the browser, with a sentence about the
 * moment, the card's URL in the content and a NIP-92 `imeta` for it, so
 * clients show the card inline. The league builds the template, checks the
 * signed note against it ({@see SignedEventGate}, rule 37), archives it and
 * queues it for the league relays; the browser publishes it to the player's
 * write relays. The page shows the note before anything is signed.
 *
 * Moments (only the player's own, {@see post()}):
 * - rank up, block mined, tournament win, Season Wrapped (P11), with a share
 *   card of their own;
 * - a won chess game or series (P46), with the page's own card (P54), the
 *   opponents mentioned (`nostr:npub…` and `p`, NIP-27) and the league's
 *   record of the result quoted (`nostr:nevent…` and `q`, NIP-18) when there
 *   is one; a tournament win also quotes the tournament's `31923`;
 * - "I'm in" (P46): the player's entry in a tournament that is open for
 *   sign-up or waits for its draw, with the tournament's invite card, the
 *   player's personal tournament link (P47, the invite that credits them)
 *   and the tournament's `31923` quoted;
 * - a Blockfill moment (a verified run that is a personal best, a new first
 *   place of its week or holds the player's week place,
 *   {@see BlockfillMoments}), with its own share card and the moment's page
 *   as the link, last.
 *
 * At most `esports.badges.shares_per_hour` per player: the league relays carry them.
 */
final class SharePosts
{
    public const KIND = 1;

    public const FORMAT = 'wide';

    /** The moments a share post can be about. */
    public const TYPES = ['rank-up', 'block', 'tournament', 'wrapped', 'game', 'series', 'signup', 'blockfill', 'tmnf'];

    /** Opponents one post mentions at most: a team of five, never a whole bracket. */
    public const MAX_MENTIONS = 5;

    public function __construct(private SignedEventGate $gate, private TournamentChampion $champions, private TournamentSignups $signups) {}

    /**
     * The player's own post of a moment, or a refusal.
     *
     * @throws ShareRefused
     */
    public function post(User $user, string $type, string $id): SharePost
    {
        $post = match ($type) {
            'rank-up', 'block', 'tournament', 'wrapped' => $this->ofCard($user, $type, $id),
            'game' => $this->game($user, $id),
            'series' => $this->series($user, $id),
            'signup' => $this->signup($user, $id),
            'blockfill' => $this->blockfill($user, $id),
            'tmnf' => $this->tmnf($user, $id),
            default => null,
        };

        return $post ?? throw new ShareRefused(__('There is nothing of yours to share here.'));
    }

    /**
     * The player's own share card of a P11 moment, or a refusal.
     *
     * @param  'rank-up'|'block'|'tournament'|'wrapped'|string  $type
     *
     * @throws ShareRefused
     */
    public function card(User $user, string $type, string $id): ShareCard
    {
        $card = match ($type) {
            'rank-up' => $this->rankUp($user, (int) $id),
            'block' => $this->block($user, (int) $id),
            'tournament' => $this->tournament($user, (int) $id),
            'wrapped' => $this->wrapped($user, $id),
            default => null,
        };

        return $card ?? throw new ShareRefused(__('There is nothing of yours to share here.'));
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws ShareRefused
     */
    public function prepare(User $user, string $type, string $id): array
    {
        return $this->template($this->post($user, $type, $id));
    }

    /**
     * @throws ShareRefused|RejectedEvent
     */
    public function submit(User $user, string $type, string $id, mixed $signed): NostrEvent
    {
        // Counted before anything else (gate F3): hit() increments atomically in the cache store, so
        // parallel requests each get their own count and only the first N pass; a refused or
        // rejected attempt counts too.
        if (RateLimiter::hit('share-posts:'.$user->id, 3600) > max(1, (int) config('esports.badges.shares_per_hour'))) {
            throw new ShareRefused(__('You shared a lot this hour. Try again later.'));
        }

        $template = $this->template($this->post($user, $type, $id));
        $event = $this->gate->check($signed, $template, $user);

        return DB::transaction(function () use ($event): NostrEvent {
            $stored = NostrEvent::fromSigned($event);
            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /**
     * The note: the sentence (and for "I'm in" the invite link under it), a
     * "GG" line that mentions the opponents, the card, the page, and the
     * quoted event last. Without mentions and quote it is the rev. 8 note.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public function template(SharePost $post): array
    {
        [$width, $height] = $post->dimensions;
        $head = $post->linkInSentence ? $post->sentence."\n".$post->link : $post->sentence;

        if ($post->mentions !== []) {
            $head .= "\n".'GG '.implode(' ', array_map(fn (array $mention): string => 'nostr:'.NostrKeys::hexToNpub($mention['pubkey']), $post->mentions));
        }

        $content = $head."\n\n".$post->cardUrl.($post->linkInSentence ? '' : "\n".$post->link);

        if ($post->quote !== null) {
            $content .= "\n\n".$post->quote['uri'];
        }

        // NIP-18: an address carries no author, an event id carries its author after the relay hint.
        $quote = match (true) {
            $post->quote === null => [],
            $post->quote['pubkey'] === null => [['q', $post->quote['ref'], $post->quote['relay']]],
            default => [['q', $post->quote['ref'], $post->quote['relay'], $post->quote['pubkey']]],
        };

        return [
            'kind' => self::KIND,
            'tags' => [
                ['imeta', 'url '.$post->cardUrl, 'm image/png', 'dim '.$width.'x'.$height, 'alt '.$post->sentence],
                ['r', $post->link],
                ...array_map(fn (array $mention): array => ['p', $mention['pubkey']], $post->mentions),
                ...$quote,
                ['alt', 'Share post: '.$post->type.' in TWENTY ONE Esports'],
            ],
            'content' => $content,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /** The sentence of a P11 card post, in the player's language. */
    public function sentence(ShareCard $card): string
    {
        $f = $card->facts;

        return match ($card->type) {
            'rank-up' => __('Ranked up to :rank in :ladder on TWENTY ONE Esports.', ['rank' => RankTiers::label((string) $f['tier']), 'ladder' => $f['ladder']]),
            'block' => __('Mined block :height on the TWENTY ONE Esports season chain: +:sats sats.', ['height' => $f['height'], 'sats' => ShareCard::sats((int) $f['reward'])]),
            'tournament' => __('Won :tournament on TWENTY ONE Esports.', ['tournament' => $f['tournament']]),
            'blockfill' => $this->blockfillSentence($f),
            'tmnf' => $this->tmnfSentence($f),
            default => __('My :season on TWENTY ONE Esports: :blocks blocks mined, :sats sats.', ['season' => BadgeCopy::season((string) $f['season']), 'blocks' => $f['blocks'], 'sats' => ShareCard::sats((int) $f['sats'])]),
        };
    }

    /* ---------- The P11 moments: a share card each ---------------------------------------------------------- */

    /**
     * @throws ShareRefused
     */
    private function ofCard(User $user, string $type, string $id): SharePost
    {
        $card = $this->card($user, $type, $id);
        $quote = null;

        // A tournament win quotes the tournament's calendar event (rev. 9.7).
        if ($type === 'tournament' && ($tournament = Tournament::query()->with('event')->find((int) $id)) !== null) {
            $quote = $this->tournamentQuote($tournament);
        }

        return new SharePost(
            type: $type,
            sentence: $this->sentence($card),
            cardUrl: $card->url(self::FORMAT),
            dimensions: ShareCard::FORMATS[self::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute('/players/'.$user->npub),
            quote: $quote,
        );
    }

    private function rankUp(User $user, int $id): ?ShareCard
    {
        $version = RankBadgeVersion::query()->with('badge.user')->find($id);

        return $version !== null && $version->badge->pubkey === $user->pubkey && $version->isRankUp() ? ShareCard::rankUp($version) : null;
    }

    private function block(User $user, int $id): ?ShareCard
    {
        $block = SeasonAttestation::query()->with('season')->find($id);

        return $block !== null && $block->mines() && in_array($user->pubkey, $block->winners(), true) ? ShareCard::block($block, $user) : null;
    }

    private function tournament(User $user, int $id): ?ShareCard
    {
        $tournament = Tournament::query()->find($id);
        // A Blockfill week (plan "Blockfill", P6) is a game's weekly board, not a tournament win to share.
        $winner = $tournament === null || $tournament->isLeagueWeek() ? null : $this->champions->of($tournament);

        return $winner !== null && in_array($user->id, $winner->memberIds(), true) ? ShareCard::tournament($tournament, $winner) : null;
    }

    private function wrapped(User $user, string $slug): ?ShareCard
    {
        $season = Season::query()->where('slug', $slug)->first();

        return $season !== null && ShareMoments::hasWrapped($season, $user) ? ShareCard::wrapped($season, $user) : null;
    }

    /* ---------- A Blockfill moment ------------------------------------------------------------------------------ */

    /**
     * The player's own verified Blockfill run that is a moment: its share
     * card, and the moment's page as the link (the card is its preview).
     */
    private function blockfill(User $user, string $id): ?SharePost
    {
        $moments = app(BlockfillMoments::class);
        $run = $moments->ownedBy($user, $id);
        $moment = $run === null ? null : $moments->of($run);

        if ($run === null || $moment === null) {
            return null;
        }

        $card = ShareCard::blockfill($run, $moment);

        return new SharePost(
            type: 'blockfill',
            sentence: $this->sentence($card),
            cardUrl: $card->url(self::FORMAT),
            dimensions: ShareCard::FORMATS[self::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute(route('stacker.moment', $run->id, false)),
        );
    }

    /* ---------- A TMNF moment ----------------------------------------------------------------------------------- */

    /**
     * The player's own counted TMNF finish that is a moment: its share card,
     * and its week's page as the link (plan "Trackmania und Restposten", P2).
     */
    private function tmnf(User $user, string $id): ?SharePost
    {
        $moments = app(TmnfMoments::class);
        $run = $moments->ownedBy($user, $id);
        $moment = $run === null ? null : $moments->of($run);
        $week = $moment === null ? null : app(TmnfWeeks::class)->find(BlockfillWeeks::startOf($run->achieved_at));

        if ($run === null || $moment === null || $week === null) {
            return null;
        }

        $card = ShareCard::tmnf($run, $moment);

        return new SharePost(
            type: 'tmnf',
            sentence: $this->sentence($card),
            cardUrl: $card->url(self::FORMAT),
            dimensions: ShareCard::FORMATS[self::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute(route('tournaments.show', $week, false)),
        );
    }

    /**
     * One line, no `#`: "Place 1", never "#1"; the track and the week by name, never a login.
     *
     * @param  array<string, mixed>  $f
     */
    private function tmnfSentence(array $f): string
    {
        $local = CarbonImmutable::parse((string) $f['week'], BlockfillWeeks::TIMEZONE);
        $replace = ['time' => ScoreMetric::time()->format((int) $f['ms']), 'track' => (string) $f['track'], 'place' => (int) $f['place'],
            'week' => __('TMNF Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()])];

        return match ($f['kind']) {
            'final' => __('Finished :week in place :place: :track in :time on TWENTY ONE Esports.', $replace),
            'first' => __('New first place in :week: :track in :time on TWENTY ONE Esports.', $replace),
            'pb' => __('New personal best in TMNF: :track in :time on TWENTY ONE Esports.', $replace),
            default => __('Place :place so far in :week: :track in :time on TWENTY ONE Esports.', $replace),
        };
    }

    /**
     * One line, no `#` (it would be a hashtag): "Place 1", never "#1".
     *
     * @param  array<string, mixed>  $f
     */
    private function blockfillSentence(array $f): string
    {
        $replace = ['time' => BlockfillMoments::time((int) $f['ticks']), 'week' => BlockfillMoments::weekTitle((string) $f['week']), 'place' => (int) $f['place']];

        return match ($f['kind']) {
            'final' => __('Finished :week in place :place with :time on TWENTY ONE Esports.', $replace),
            'first' => __('New first place in :week: 40 blocks mined in :time on TWENTY ONE Esports.', $replace),
            'pb' => __('New personal best in Blockfill: 40 blocks mined in :time on TWENTY ONE Esports.', $replace),
            default => __('Place :place so far in :week with :time on TWENTY ONE Esports.', $replace),
        };
    }

    /* ---------- P46: a won game or series, "I'm in" --------------------------------------------------------- */

    /**
     * A finished chess game the player won: the game's page card (board,
     * faces, result), the opponent mentioned, and the league's record quoted
     * when the game has one (a rated game; a casual game has none).
     */
    private function game(User $user, string $id): ?SharePost
    {
        $game = ctype_digit($id) ? ChessGame::query()->with(['white', 'black', 'recordEvent'])->find((int) $id) : null;
        $color = $game?->colorOf($user);

        // Both players still there: a deleted account's game names no one to mention.
        if ($game === null || $color === null || $game->status !== ChessGameStatus::Finished || $game->ply === 0
            || $game->white_id === null || $game->black_id === null || $game->result !== ($color === 'w' ? '1-0' : '0-1')) {
            return null;
        }

        $opponent = $color === 'w' ? $game->black : $game->white;

        $record = $game->recordEvent;
        $name = $opponent->displayName();

        return new SharePost(
            type: 'game',
            sentence: $game->isCorrespondence()
                ? __('Won a daily chess game against :opponent on TWENTY ONE Esports.', ['opponent' => $name])
                : __('Won a blitz game against :opponent on TWENTY ONE Esports.', ['opponent' => $name]),
            cardUrl: PageCard::game($game)->url(),
            dimensions: [PageCard::WIDTH, PageCard::HEIGHT],
            storyPath: null,
            link: self::absolute(route('games.show', $game, false)),
            mentions: [['pubkey' => $opponent->pubkey, 'name' => $name]],
            quote: $record === null ? null : $this->eventQuote($record, __('the league’s record of the game')),
        );
    }

    /**
     * A series whose result stands (confirmed or decided) and that the
     * player's side won: the series' page card, the players of the other side
     * mentioned, and for a rated series the result report quoted.
     */
    private function series(User $user, string $number): ?SharePost
    {
        $match = ctype_digit($number) ? SeriesMatch::query()->with(['latestReport.event'])->where('number', (int) $number)->first() : null;

        if ($match === null || ! $match->status->hasResult() || ! in_array($match->winner, ['challenger', 'challenged'], true)) {
            return null;
        }

        $won = $match->winner;
        $lost = $won === 'challenger' ? 'challenged' : 'challenger';

        if (! in_array($user->id, $this->playersOf($match, $won), true)) {
            return null;
        }

        $opponents = User::query()->whereKey($this->playersOf($match, $lost))->whereNotNull('pubkey')->orderBy('id')->limit(self::MAX_MENTIONS)->get();
        $score = SeriesMatch::seriesScore($match->result_games);
        $report = $match->latestReport;
        $record = $match->rated && $report !== null && $report->status !== ReportStatus::Superseded ? $report->event : null;

        return new SharePost(
            type: 'series',
            sentence: __('Won :score against :side in :ladder on TWENTY ONE Esports.', [
                'score' => $score[$won].'–'.$score[$lost],
                'side' => $match->sideName($lost),
                'ladder' => BadgeCopy::ladder($match->game, $match->mode),
            ]),
            cardUrl: PageCard::series($match)->url(),
            dimensions: [PageCard::WIDTH, PageCard::HEIGHT],
            storyPath: null,
            link: self::absolute(route('matches.show', $match, false)),
            mentions: array_values($opponents->map(fn (User $opponent): array => ['pubkey' => (string) $opponent->pubkey, 'name' => $opponent->displayName()])->all()),
            quote: $record === null ? null : $this->eventQuote($record, __('the result report of the series')),
        );
    }

    /**
     * Who played a side of the series: the players it named when it was
     * played (`rosters`), else the roster of the report that stands or of an
     * admin decision, else a roster side's players (a mix team, an RL 1v1
     * player), else the lineup's accepted seats.
     *
     * @return list<int>
     */
    public function playersOf(SeriesMatch $match, string $side): array
    {
        $played = array_map(intval(...), $match->rosters[$side] ?? []);

        if ($played !== []) {
            return $played;
        }

        $report = $match->latestReport;
        $named = $match->resolved_roster ?? ($report !== null && $report->status !== ReportStatus::Superseded ? $report->roster : []);
        $played = array_values(array_map(fn (array $row): int => (int) $row['user_id'], array_filter($named, fn (array $row): bool => $row['side'] === $side)));

        if ($played !== []) {
            return $played;
        }

        $roster = $match->rosterSide($side);

        if ($roster !== []) {
            return $roster;
        }

        return array_values(array_map(intval(...), $match->lineup($side)?->seats()->whereNotNull('accepted_at')->pluck('user_id')->all() ?? []));
    }

    /**
     * "I'm in": the player's entry in a published tournament that is open
     * for sign-up or waits for its draw. The invite card, the player's
     * personal tournament link (P47) as the invite under the sentence, its
     * `31923` quoted.
     */
    private function signup(User $user, string $id): ?SharePost
    {
        $tournament = ctype_digit($id) ? Tournament::query()->with('event')->find((int) $id) : null;

        if ($tournament === null || $tournament->published_at === null || ! $tournament->isVisibleTo(null)
            || ! in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing], true)
            || $this->signups->entryOf($tournament, $user) === null) {
            return null;
        }

        $quote = $this->tournamentQuote($tournament);

        if ($quote === null) {
            return null;
        }

        $card = ShareCard::tournamentInvite($tournament);

        // P47: the entrant's personal tournament link, the same for every post and DM, so friends who sign up through it credit them.
        try {
            $invite = app(InviteLinks::class)->forTournament($user, $tournament);
        } catch (InviteLinkRefused) {
            return null;
        }

        return new SharePost(
            type: 'signup',
            sentence: __('I’m in :tournament on TWENTY ONE Esports (:game). Join me:', ['tournament' => $tournament->name, 'game' => BadgeCopy::ladder($tournament->game, $tournament->mode)]),
            cardUrl: $card->url(self::FORMAT),
            dimensions: ShareCard::FORMATS[self::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute(route('invites.link', $invite, false)),
            quote: $quote,
            linkInSentence: true,
        );
    }

    /* ---------- References --------------------------------------------------------------------------------- */

    /**
     * @return array{ref: string, relay: string, pubkey: string|null, uri: string, what: string}|null
     */
    private function tournamentQuote(Tournament $tournament): ?array
    {
        $address = $tournament->address();

        if ($address === null || $tournament->event === null) {
            return null;
        }

        return [
            'ref' => $address,
            'relay' => self::relayHint(),
            'pubkey' => null,
            'uri' => 'nostr:'.NostrKeys::naddr(Tournament::CALENDAR_EVENT, $tournament->event->pubkey, (string) $tournament->slug, self::relayHint()),
            'what' => __('the tournament’s calendar event'),
        ];
    }

    /**
     * @return array{ref: string, relay: string, pubkey: string|null, uri: string, what: string}
     */
    private function eventQuote(NostrEvent $event, string $what): array
    {
        return [
            'ref' => $event->event_id,
            'relay' => self::relayHint(),
            'pubkey' => $event->pubkey,
            'uri' => 'nostr:'.NostrKeys::nevent($event->event_id, $event->pubkey, $event->kind),
            'what' => $what,
        ];
    }

    /** The first league relay: where the quoted event is sent first. */
    private static function relayHint(): string
    {
        return (string) (config('esports.relays')[0] ?? '');
    }

    private static function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
