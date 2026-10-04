<?php

namespace App\Support\Lightning;

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Cards\SharePosts;
use App\Support\Nostr\NostrBar;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\QrCode;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Zap the winner" (P47, NIP-57): on a finished chess game, series or
 * tournament, anyone may tip the winner. A voluntary tip between players,
 * never a fee: the sats go from the zapper's wallet straight to the winner's
 * Lightning address, and the league keeps nothing and stores nothing.
 *
 * Offered only for a winner whose Nostr profile names a Lightning address
 * (`lud16`) that makes an LNURL ({@see Lnurl::fromAddress()}), never to the
 * viewer themselves, at most {@see MAX_WINNERS} players of a winning side.
 * The address is never shown as text: the page shows the LNURL as a QR code,
 * or, for a signed zap, the invoice as a QR code.
 *
 * A signed zap: {@see template()} is the kind 9734 zap request (`relays`,
 * `amount`, `lnurl`, `p` the winner, and what was won: the league's record
 * (`64`) of a rated game, the challenge (`2150`) of a series, the
 * tournament's `31923` by address and its current version by id); the
 * player signs it in the browser after seeing it, and {@see invoice()}
 * checks the signed event against the template ({@see matching()}) and
 * asks the winner's LNURL server for the invoice
 * ({@see LightningAddress::zapInvoice()}).
 */
final class WinnerZaps
{
    public const TYPES = ['game', 'series', 'tournament'];

    public const MAX_WINNERS = 5;

    /** A finished tournament offers everyone who played, best place first. */
    public const MAX_TOURNAMENT_PLAYERS = 16;

    /** The amounts offered, in sats. */
    public const AMOUNTS = [21, 210, 2100, 21000];

    public const MAX_SATS = 1_000_000;

    public const MAX_COMMENT = 280;

    public function __construct(
        private TournamentChampion $champions,
        private LightningAddress $addresses,
    ) {}

    /**
     * The winners that can be zapped, for this viewer (null: a guest).
     *
     * @return list<array{user: User, lnurl: string, qr: string}>
     */
    public function winners(string $type, string $id, ?User $viewer): array
    {
        $ids = $this->winnerIds($type, $id);

        if ($ids === []) {
            return [];
        }

        // One query for the whole winning side (a tournament lineup, a series roster).
        // In the order given (a tournament's best place first), never more than MAX_WINNERS.
        $users = User::query()->whereKey($ids)->whereNotNull('lud16')->get()->sortBy(fn (User $user): int => (int) array_search($user->id, $ids, true))->values();
        $zappable = [];

        foreach ($users as $user) {
            $lnurl = Lnurl::fromAddress($user->lud16);

            if ($lnurl === null || LightningAddress::target((string) $user->lud16) === null || ($viewer !== null && $viewer->id === $user->id)) {
                continue;
            }

            $zappable[] = [
                'user' => $user,
                'lnurl' => $lnurl,
                'qr' => QrCode::svg('lightning:'.strtoupper($lnurl), label: __('QR code to zap :name', ['name' => $user->displayName()])),
            ];

            if (count($zappable) === ($type === 'tournament' ? self::MAX_TOURNAMENT_PLAYERS : self::MAX_WINNERS)) {
                break;
            }
        }

        return $zappable;
    }

    /**
     * The kind 9734 zap request the viewer signs.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws ZapRefused
     */
    public function template(User $zapper, string $type, string $id, int $winnerId, int $sats, string $comment): array
    {
        $winner = collect($this->winners($type, $id, $zapper))->first(fn (array $row): bool => $row['user']->id === $winnerId);

        if ($winner === null) {
            throw new ZapRefused(__('This player cannot be zapped here.'));
        }

        if ($sats < 1 || $sats > self::MAX_SATS) {
            throw new ZapRefused(__('Pick an amount between 1 and :max sats.', ['max' => number_format(self::MAX_SATS)]));
        }

        $comment = trim($comment);

        if (mb_strlen($comment) > self::MAX_COMMENT) {
            throw new ZapRefused(__('The comment can be at most :max characters.', ['max' => self::MAX_COMMENT]));
        }

        $relays = array_slice(array_filter(NostrBar::browserRelays(), fn (string $url): bool => str_starts_with($url, 'wss://') || str_starts_with($url, 'ws://')), 0, 5);

        return [
            'kind' => 9734,
            'tags' => [
                ['relays', ...$relays],
                ['amount', (string) ($sats * 1000)],
                ['lnurl', $winner['lnurl']],
                ['p', (string) $winner['user']->pubkey],
                ...$this->referenceTags($type, $id),
            ],
            'content' => $comment,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * The signed zap request, checked against the template, turned into the
     * winner's invoice. Returns the invoice and its QR code.
     *
     * @return array{invoice: string, qr: string}
     *
     * @throws ZapRefused
     */
    public function invoice(User $zapper, string $type, string $id, int $winnerId, int $sats, string $comment, mixed $signed): array
    {
        // Every attempt counts, before anything else: each one makes the league ask a stranger's server.
        if (RateLimiter::hit('winner-zaps:'.$zapper->id, 3600) > max(1, (int) config('esports.zaps.invoices_per_hour', 20))) {
            throw new ZapRefused(__('You asked for many invoices this hour. Try again later.'));
        }

        $template = $this->template($zapper, $type, $id, $winnerId, $sats, $comment);
        $event = self::matching($signed, $template, $zapper) ?? throw new ZapRefused(__('The signed zap request did not match. Please try again.'));

        $winner = User::query()->findOrFail($winnerId);
        $lnurl = (string) collect($template['tags'])->first(fn (array $tag): bool => $tag[0] === 'lnurl')[1];

        try {
            $invoice = $this->addresses->zapInvoice((string) $winner->lud16, $sats, $event->toJson(), $lnurl);
        } catch (LightningAddressFailure $failure) {
            throw new ZapRefused(match ($failure->reason) {
                'no_zaps' => __(':name’s wallet takes no Nostr zaps. Scan the QR code instead: a plain payment, without a zap receipt.', ['name' => $winner->displayName()]),
                'amount_out_of_range' => __(':name’s wallet does not take this amount. Try another one.', ['name' => $winner->displayName()]),
                default => __(':name’s wallet did not answer with a matching invoice. Try again later, or scan the QR code instead.', ['name' => $winner->displayName()]),
            });
        }

        return [
            'invoice' => $invoice->invoice,
            'qr' => QrCode::svg('lightning:'.strtoupper($invoice->invoice), label: __('QR code of the zap invoice')),
        ];
    }

    /**
     * The signed zap request when it is exactly the template: kind, author,
     * tags and content as prepared, `created_at` inside the league's usual
     * window, a valid id and signature. Not {@see SignedEventGate::check()}:
     * a zap request is no league event (the league's rules refuse kind 9734
     * rightly) and is neither stored nor published by the league; the
     * winner's LNURL server takes it.
     *
     * @param  array{kind: int, tags: list<list<string>>, content: string, created_at: int}  $template
     */
    public static function matching(mixed $signed, array $template, User $zapper): ?SignedEvent
    {
        $event = SignedEvent::fromInput(is_string($signed) ? json_decode($signed, true) : $signed);
        $now = now()->getTimestamp();

        if ($event === null || $event->kind !== $template['kind'] || $event->pubkey !== $zapper->pubkey
            || $event->tags !== $template['tags'] || $event->content !== $template['content']
            || $event->createdAt < $now - SignedEventGate::MAX_AGE_SECONDS || $event->createdAt > $now + SignedEventGate::MAX_AHEAD_SECONDS
            || ! $event->hasValidSignature()) {
            return null;
        }

        return $event;
    }

    /**
     * The winners' user ids, or [] while there is no winner.
     *
     * @return list<int>
     */
    private function winnerIds(string $type, string $id): array
    {
        if (! ctype_digit($id)) {
            return [];
        }

        if ($type === 'game') {
            $game = ChessGame::query()->find((int) $id);

            if ($game === null || $game->status !== ChessGameStatus::Finished || $game->ply === 0) {
                return [];
            }

            $winner = match ($game->result) {
                '1-0' => $game->white_id,
                '0-1' => $game->black_id,
                default => null,
            };

            return $winner === null ? [] : [$winner];
        }

        if ($type === 'series') {
            $match = SeriesMatch::query()->with('latestReport')->where('number', (int) $id)->first();

            if ($match === null || ! $match->status->hasResult() || ! in_array($match->winner, ['challenger', 'challenged'], true)) {
                return [];
            }

            return app(SharePosts::class)->playersOf($match, $match->winner);
        }

        if ($type === 'tournament') {
            $tournament = Tournament::query()->find((int) $id);
            // A Blockfill week (plan "Blockfill", P6) is a game's weekly board, not a tournament won: no zap.
            $champion = $tournament === null || $tournament->isLeagueWeek() ? null : $this->champions->of($tournament);

            if ($champion === null) {
                return [];
            }

            // Everyone who really played, best place first (user, 2026-10-04: "unfair nur den Sieger hier anzuzeigen …
            // außer no-show oder disqualified"): the final places without the excluded entries (PayoutPlan::excluded()).
            $excluded = PayoutPlan::excluded($tournament);
            $places = app(TournamentPlacements::class)->of($tournament) ?? [['place' => 1, 'participants' => [$champion->id]]];
            $order = array_values(array_filter(array_merge(...array_map(fn (array $place): array => $place['participants'], $places)), fn (int $id): bool => ! isset($excluded[$id])));
            $participants = TournamentParticipant::query()->whereKey($order)->get()->keyBy('id');

            return array_values(array_unique(array_merge(...array_map(fn (int $id): array => $participants->get($id)?->memberIds() ?? [], $order ?: [0]))));
        }

        return [];
    }

    /**
     * What was won, as NIP-57 `e`/`a` (and `k`) tags; none for a casual game
     * or series, which has no league event.
     *
     * @return list<list<string>>
     */
    private function referenceTags(string $type, string $id): array
    {
        if ($type === 'game') {
            $record = ChessGame::query()->with('recordEvent')->find((int) $id)?->recordEvent;

            return $record === null ? [] : [['e', $record->event_id], ['k', (string) $record->kind]];
        }

        if ($type === 'series') {
            $challenge = SeriesMatch::query()->with('challengeEvent')->where('number', (int) $id)->first()?->challengeEvent;

            return $challenge === null ? [] : [['e', $challenge->event_id], ['k', (string) $challenge->kind]];
        }

        $tournament = Tournament::query()->with('event')->find((int) $id);
        $address = $tournament?->address();

        if ($tournament === null || $address === null || $tournament->event === null || ! NostrKeys::isHexPubkey($tournament->event->pubkey)) {
            return [];
        }

        return [['e', $tournament->event->event_id], ['a', $address], ['k', (string) Tournament::CALENDAR_EVENT]];
    }
}
