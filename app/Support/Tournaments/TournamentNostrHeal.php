<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\StreamBot\TournamentNotes;
use App\Support\TwentyOne\EventBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repairs the public copy of the tournaments after NIP-09 deletion requests
 * (kind 5) signed with the league or the stream bot key, e.g. from a client
 * logged in with that account (2026-09-27: three bot notes deleted from
 * Amethyst).
 *
 * Reads every kind 5 of the two keys from the league and stream relays and
 * decides per published tournament:
 *
 * - `resign`: the current 31923 is named by a deletion (its id in an `e`,
 *   or its address in an `a` whose deletion is not older than it). A relay
 *   honouring the request refuses that version and every older one, so the
 *   tournament gets a new version, signed now and after the deletion
 *   (TournamentPublisher::republish). Also when the current version still
 *   carries a `t` tag, which the league no longer posts (no hashtags, a
 *   standing rule of this project since 2026-09-28): the new version has none.
 * - `resend`: not deleted, but a league relay does not return the current
 *   version: the same stored event goes out again (same id, a duplicate for
 *   a relay that has it).
 * - `renew`: its delivered bot note is named by a deletion: a new note is
 *   signed and stored in its bot_posts row (TournamentNotes::renew). Only
 *   then; a note nobody deleted is never posted again.
 *
 * plan() reads only; apply() acts on a plan. Every step is idempotent: once
 * applied, the next plan finds nothing to do (a re-signed version is newer
 * than the deletion, a renewed note has a new id). A relay that fails to
 * answer is a failed read and counts as "does not have it", which at most
 * sends a duplicate.
 */
final class TournamentNostrHeal
{
    /** Most deletion requests read per key. */
    private const DELETIONS_PER_KEY = 500;

    /** Most calendar events read per relay (a relay may keep older versions next to the current one). */
    private const VERSIONS_READ = 1000;

    public function __construct(
        private RelayReader $reader,
        private TournamentPublisher $publisher,
        private TournamentNotes $notes,
    ) {}

    /**
     * @return array{
     *     league: string|null,
     *     relays: list<string>,
     *     deletions: list<array{id: string, pubkey: string, created_at: int, client: string|null, e: list<string>, a: list<string>, k: list<string>}>,
     *     tournaments: list<array{tournament: Tournament, action: 'resign'|'resend'|'ok', event_id: string, deleted_by: string|null, deleted_at: int|null, hashtags: bool, missing: list<string>}>,
     *     notes: list<array{tournament: Tournament, action: 'renew'|'skip', event_id: string, deleted_by: string}>,
     *     unmatched: list<string>
     * }
     */
    public function plan(): array
    {
        $league = LeagueKey::fromConfig()?->pubkey();
        $bot = LeagueKey::streamBot()?->pubkey();
        $leagueRelays = self::relayList(config('esports.relays', []));
        $relays = array_values(array_unique([...$leagueRelays, ...StreamCoordinates::relays()]));
        $authors = array_values(array_unique(array_filter([$league, $bot])));

        $deletions = $authors === [] || $relays === [] ? [] : $this->reader->fetch(
            [['kinds' => [5], 'authors' => $authors, 'limit' => self::DELETIONS_PER_KEY]],
            $relays,
            perAuthor: self::DELETIONS_PER_KEY,
        );

        // NIP-09: a request deletes only events of its own author, so both maps are keyed by it
        // (`<author>:<id>`, `<author>:<address>`); an `a` deletes every version up to its created_at.
        $deletedIds = [];
        $deletedAddresses = [];

        foreach ($deletions as $deletion) {
            foreach ($deletion->tagsNamed('e') as $tag) {
                $deletedIds[$deletion->pubkey.':'.($tag[0] ?? '')] ??= $deletion;
            }

            foreach ($deletion->tagsNamed('a') as $tag) {
                $key = $deletion->pubkey.':'.($tag[0] ?? '');
                $current = $deletedAddresses[$key] ?? null;
                $deletedAddresses[$key] = $current === null || $current->createdAt < $deletion->createdAt ? $deletion : $current;
            }
        }

        $matched = [];
        $tournaments = Tournament::query()->with('event')->whereNotNull('event_id')->whereNotNull('slug')->orderBy('id')->get();
        $present = $league === null ? [] : $this->present($league, array_values($tournaments->pluck('slug')->map(fn ($slug): string => (string) $slug)->all()), $leagueRelays);
        $plannedTournaments = [];

        foreach ($tournaments as $tournament) {
            $event = $tournament->event;

            if (! $event instanceof NostrEvent) {
                continue;
            }

            $address = Tournament::CALENDAR_EVENT.':'.$event->pubkey.':'.$tournament->slug;
            $byId = $deletedIds[$event->pubkey.':'.$event->event_id] ?? null;
            $byAddress = $deletedAddresses[$event->pubkey.':'.$address] ?? null;
            $byAddress = $byAddress !== null && $byAddress->createdAt >= $event->signed_at ? $byAddress : null;
            $deletion = $byId ?? $byAddress;
            $deletedAt = max($byId->createdAt ?? 0, $byAddress->createdAt ?? 0);
            $hashtags = (SignedEvent::fromInput(json_decode($event->raw, true))?->tagsNamed('t') ?? []) !== [];

            if ($byId !== null) {
                $matched[$event->pubkey.':'.$event->event_id] = true;
            }

            if (isset($deletedAddresses[$event->pubkey.':'.$address])) {
                $matched[$event->pubkey.':'.$address] = true;
            }

            $missing = array_values(array_filter($leagueRelays, fn (string $relay): bool => ! isset($present[$relay][$event->event_id])));

            $plannedTournaments[] = [
                'tournament' => $tournament,
                'action' => $deletion !== null || $hashtags ? 'resign' : ($missing !== [] ? 'resend' : 'ok'),
                'event_id' => $event->event_id,
                'deleted_by' => $deletion?->id,
                'deleted_at' => $deletion !== null ? $deletedAt : null,
                'hashtags' => $hashtags,
                'missing' => $missing,
            ];
        }

        $plannedNotes = [];
        $byTournament = $tournaments->keyBy('id');

        $posts = BotPost::query()->where('subject_type', BotPost::SUBJECT_TOURNAMENT)->where('kind', TournamentNotes::KIND_NOTE)
            ->whereNotNull('event_id')->orderBy('subject_id')->get();

        foreach ($posts as $post) {
            $note = SignedEvent::fromInput(json_decode((string) $post->event, true));
            $deletion = $note === null ? null : ($deletedIds[$note->pubkey.':'.$note->id] ?? null);
            $tournament = $byTournament->get($post->subject_id);

            if ($note === null || $deletion === null || ! $tournament instanceof Tournament) {
                continue;
            }

            $matched[$note->pubkey.':'.$note->id] = true;
            $plannedNotes[] = [
                'tournament' => $tournament,
                'action' => $tournament->status === TournamentStatus::Cancelled ? 'skip' : 'renew',
                'event_id' => $note->id,
                'deleted_by' => $deletion->id,
            ];
        }

        $unmatched = [];

        foreach (array_keys($deletedIds + $deletedAddresses) as $reference) {
            if (! isset($matched[$reference])) {
                $unmatched[] = $reference;
            }
        }

        return [
            'league' => $league,
            'relays' => $relays,
            'deletions' => array_map(fn (SignedEvent $deletion): array => [
                'id' => $deletion->id,
                'pubkey' => $deletion->pubkey,
                'created_at' => $deletion->createdAt,
                'client' => $deletion->tag('client'),
                'e' => array_map(fn (array $tag): string => (string) ($tag[0] ?? ''), $deletion->tagsNamed('e')),
                'a' => array_map(fn (array $tag): string => (string) ($tag[0] ?? ''), $deletion->tagsNamed('a')),
                'k' => array_map(fn (array $tag): string => (string) ($tag[0] ?? ''), $deletion->tagsNamed('k')),
            ], $deletions),
            'tournaments' => $plannedTournaments,
            'notes' => $plannedNotes,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Carries out a plan; a line per step. One failing step never stops the
     * others.
     *
     * @param  array{tournaments: list<array{tournament: Tournament, action: string, event_id: string, deleted_at: int|null, missing: list<string>}>, notes: list<array{tournament: Tournament, action: string, event_id: string}>}  $plan
     * @return list<string>
     */
    public function apply(array $plan, CarbonImmutable $now): array
    {
        $lines = [];

        foreach ($plan['tournaments'] as $step) {
            $tournament = $step['tournament'];

            try {
                $lines[] = match ($step['action']) {
                    'resign' => $this->resign($tournament, $step['deleted_at'], $now),
                    'resend' => $this->resend($tournament, $step['event_id'], $step['missing']),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);
                $lines[] = 'tournament '.$tournament->id.': failed, '.$e->getMessage();
            }
        }

        foreach ($plan['notes'] as $step) {
            if ($step['action'] !== 'renew') {
                continue;
            }

            try {
                $lines[] = $this->notes->renew($step['tournament'], $step['event_id'], $now);
            } catch (Throwable $e) {
                report($e);
                $lines[] = 'tournament '.$step['tournament']->id.': note failed, '.$e->getMessage();
            }
        }

        return array_values(array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    /**
     * A new version, signed now (after the deletion, without `t`); queued for the league relays.
     */
    private function resign(Tournament $tournament, ?int $deletedAt, CarbonImmutable $now): string
    {
        // A deletion dated ahead of the clock would cover a version signed now as well.
        if ($deletedAt !== null && $deletedAt >= $now->getTimestamp()) {
            return 'tournament '.$tournament->id.': its deletion is dated '.$deletedAt.', not before now; run again after that second';
        }

        $id = DB::transaction(function () use ($tournament): string {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->publisher->republish($locked);

            return (string) $locked->fresh('event')?->event?->event_id;
        });

        return 'tournament '.$tournament->id.': re-signed as '.$id.', queued for the league relays';
    }

    /**
     * The stored current version once more (same id).
     *
     * @param  list<string>  $missing
     */
    private function resend(Tournament $tournament, string $eventId, array $missing): string
    {
        $event = NostrEvent::query()->where('event_id', $eventId)->firstOrFail();
        PublishNostrEvent::dispatch($event);

        return 'tournament '.$tournament->id.': '.$eventId.' queued again for the league relays (missing on '.implode(', ', $missing).')';
    }

    /**
     * Which current versions each league relay returns; a failed read returns none.
     *
     * @param  list<string>  $slugs
     * @param  list<string>  $relays
     * @return array<string, array<string, true>> relay => event ids
     */
    private function present(string $league, array $slugs, array $relays): array
    {
        if ($slugs === []) {
            return [];
        }

        $present = [];

        foreach ($relays as $relay) {
            $events = $this->reader->fetch(
                [['kinds' => [Tournament::CALENDAR_EVENT], 'authors' => [$league], '#d' => $slugs]],
                [$relay],
                perAuthor: self::VERSIONS_READ,
            );

            foreach ($events as $event) {
                $present[$relay][$event->id] = true;
            }
        }

        return $present;
    }

    /**
     * @return list<string>
     */
    private static function relayList(mixed $relays): array
    {
        return array_values(array_filter((array) $relays, fn (mixed $relay): bool => EventBuilder::isRelayUrl($relay)));
    }
}
