<?php

namespace App\Support\Cards;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\RankBadgeVersion;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Rating\RankTiers;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The share button (P11, NIP "Share posts", rev. 8): a kind 1 note signed by
 * the player in the browser, with a sentence about the moment, the share
 * card's URL in the content and a NIP-92 `imeta` for it, so clients show the
 * card inline. The league builds the template, checks the signed note against
 * it ({@see SignedEventGate}, rule 37), archives it and queues it for the
 * league relays; the browser publishes it to the player's write relays.
 *
 * Only the player's own moments can be shared ({@see card()}), and at most
 * `esports.badges.shares_per_hour` per player: the league relays carry them.
 */
final class SharePosts
{
    public const KIND = 1;

    public const FORMAT = 'wide';

    public function __construct(private SignedEventGate $gate, private TournamentChampion $champions) {}

    /**
     * The player's own card of a moment, or a refusal.
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
        return $this->template($user, $this->card($user, $type, $id));
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

        $template = $this->template($user, $this->card($user, $type, $id));
        $event = $this->gate->check($signed, $template, $user);

        return DB::transaction(function () use ($event): NostrEvent {
            $stored = NostrEvent::fromSigned($event);
            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public function template(User $user, ShareCard $card): array
    {
        $url = $card->url(self::FORMAT);
        [$width, $height] = ShareCard::FORMATS[self::FORMAT];
        $page = rtrim((string) config('app.url'), '/').'/players/'.$user->npub;
        $text = $this->sentence($card);

        return [
            'kind' => self::KIND,
            'tags' => [
                ['imeta', 'url '.$url, 'm image/png', 'dim '.$width.'x'.$height, 'alt '.$text],
                ['r', $page],
                ['alt', 'Share post: '.$card->type.' in TWENTY ONE Esports'],
            ],
            'content' => $text."\n\n".$url."\n".$page,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /** The sentence of the post, in the player's language. */
    public function sentence(ShareCard $card): string
    {
        $f = $card->facts;

        return match ($card->type) {
            'rank-up' => __('Ranked up to :rank in :ladder on TWENTY ONE Esports.', ['rank' => RankTiers::label((string) $f['tier']), 'ladder' => $f['ladder']]),
            'block' => __('Mined block :height on the TWENTY ONE Esports season chain: +:sats sats.', ['height' => $f['height'], 'sats' => ShareCard::sats((int) $f['reward'])]),
            'tournament' => __('Won :tournament on TWENTY ONE Esports.', ['tournament' => $f['tournament']]),
            default => __('My :season on TWENTY ONE Esports: :blocks blocks mined, :sats sats.', ['season' => BadgeCopy::season((string) $f['season']), 'blocks' => $f['blocks'], 'sats' => ShareCard::sats((int) $f['sats'])]),
        };
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
        $winner = $tournament === null ? null : $this->champions->of($tournament);

        return $winner !== null && in_array($user->id, $winner->memberIds(), true) ? ShareCard::tournament($tournament, $winner) : null;
    }

    private function wrapped(User $user, string $slug): ?ShareCard
    {
        $season = Season::query()->where('slug', $slug)->first();

        return $season !== null && ShareMoments::hasWrapped($season, $user) ? ShareCard::wrapped($season, $user) : null;
    }
}
