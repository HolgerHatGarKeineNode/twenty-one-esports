<?php

namespace App\Support\Hyper;

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\HyperRatingChange;
use App\Models\TournamentMatch;
use App\Support\GameChat\GameChannels;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonChains;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the league signs about a Hyperbitcoinization match (plan "Hyperbitcoinization", P5; NIP rev. 9.24, draft),
 * only while `esports.hyper.publish` is on (off by default) and the league key is set, and only for a rated or
 * tournament match:
 *
 * - {@see poll()}: the spectators' "Who wins?" (NIP-88 kind 1068, HyperPoll::event()), at the start. The league
 *   signs it only when it is the channel creator: a poll by another key would have another id than the one the
 *   page counts votes for.
 * - {@see result()}: the result as a League Attestation (`2154`) of its own shape, at the end: `game`, `mode`,
 *   `format` (`ffa`, `duel`, `team`), the season of a rated match, one `place` row per seat (a bot's with an empty
 *   pubkey), a `forfeit` row per forfeited player, `points` (free-for-all) or `elo` (1v1, team) per rated player,
 *   the table chat's channel, and a tournament match's `31923` address. No ladder `a`, no `block`: a
 *   Hyperbitcoinization result is in no ladder event and mines nothing.
 *
 * Each is signed once per match (`poll_event_id`, `result_event_id`, written under the match's lock). Fail open:
 * the match never waits for Nostr, a failure is reported and the event is simply missing.
 */
final class HyperPublisher
{
    public static function on(): bool
    {
        return (bool) config('esports.hyper.enabled') && (bool) config('esports.hyper.publish');
    }

    public function poll(int $matchId): void
    {
        $this->once($matchId, 'poll_event_id', function (HyperMatch $match, LeagueKey $key): ?string {
            $event = HyperPoll::event($match);

            if ($event === null || $event['pubkey'] !== $key->pubkey()) {
                return null;
            }

            return $key->publish($event['kind'], $event['tags'], $event['content'], $event['created_at'])->event_id;
        });
    }

    public function result(int $matchId): void
    {
        $this->once($matchId, 'result_event_id', function (HyperMatch $match, LeagueKey $key): ?string {
            if ($match->status !== HyperMatchStatus::Finished) {
                return null;
            }

            return $key->publish(SeasonChains::ATTESTATION, self::resultTags($match), self::resultContent($match), (int) $match->ended_at?->getTimestamp())->event_id;
        });
    }

    /**
     * The tags of a match's result (NIP rev. 9.24).
     *
     * @return list<list<string>>
     */
    public static function resultTags(HyperMatch $match): array
    {
        $match->loadMissing('seats.user');
        $tags = [
            ['game', 'hyperbitcoinization'],
            ['mode', $match->mode],
            ['format', HyperSeason::kindOf($match)],
            ['hyper', $match->ulid],
        ];

        if ($match->rated && $match->season !== null) {
            $tags[] = ['season', $match->season];
        }

        foreach ($match->seats as $seat) {
            $tags[] = ['place', (string) $seat->user?->pubkey, (string) $seat->place, $seat->faction, $seat->team === null ? '' : (string) $seat->team];
        }

        foreach ($match->seats->filter(HyperSeason::forfeited(...)) as $seat) {
            if ($seat->user !== null) {
                $tags[] = ['forfeit', $seat->user->pubkey];
            }
        }

        foreach ($match->seats->whereNotNull('points') as $seat) {
            if ($seat->user !== null) {
                $tags[] = ['points', $seat->user->pubkey, (string) $seat->points];
            }
        }

        $changes = HyperRatingChange::query()->with('rating.user')->where('hyper_match_id', $match->id)->orderBy('id')->get();

        foreach ($changes as $change) {
            $tags[] = ['elo', $change->rating->user->pubkey, (string) $change->before, (string) $change->after];
        }

        $channel = GameChannels::matchChannelId($match->ulid);

        if ($channel !== null) {
            $tags[] = ['e', $channel, '', 'root'];
        }

        $address = $match->tournament_match_id === null ? null : TournamentMatch::query()->with('tournament.event')->find($match->tournament_match_id)?->tournament->address();

        if ($address !== null) {
            $tags[] = ['a', $address, ''];
        }

        $tags[] = ['alt', 'Esports league attestation: Hyperbitcoinization '.$match->mode.', '.$match->seats->count().' seats'];

        return $tags;
    }

    /** NIP: a forfeit states its public reason in the content; otherwise none. */
    private static function resultContent(HyperMatch $match): string
    {
        return $match->seats->contains(HyperSeason::forfeited(...)) ? 'Forfeit: a player left the match or let their turns run out.' : '';
    }

    /**
     * Signs one event of a match once, under the match's lock; `$sign` returns the event id or null for none.
     *
     * @param  'poll_event_id'|'result_event_id'  $column
     * @param  callable(HyperMatch, LeagueKey): ?string  $sign
     */
    private function once(int $matchId, string $column, callable $sign): void
    {
        $key = self::on() ? LeagueKey::fromConfig() : null;

        if ($key === null) {
            return;
        }

        try {
            DB::transaction(function () use ($matchId, $column, $sign, $key): void {
                $match = HyperMatch::query()->lockForUpdate()->find($matchId);

                if ($match === null || $match->{$column} !== null || (! $match->rated && $match->tournament_match_id === null)) {
                    return;
                }

                $id = $sign($match, $key);

                if ($id !== null) {
                    $match->forceFill([$column => $id])->save();
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
