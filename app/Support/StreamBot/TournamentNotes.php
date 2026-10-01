<?php

namespace App\Support\StreamBot;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Lobbies;
use App\Support\TwentyOne\EventBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The stream bot's notes on its own profile: one kind-1 note per published
 * tournament (its NIP-52 31923 exists), casual cups included, the ones
 * published before this existed too. Signed with the bot key, sent to the
 * stream relays (where its profile is), with the calendar event as a
 * `nostr:naddr1…` (NIP-21/27) and a NIP-18 `q` tag on its address.
 *
 * Exactly once per tournament (BotPost, unique per subject and kind): a
 * run claims the row before it signs; the claim holds for `retry_minutes`,
 * so a second run at the same time skips it. The signed event is stored
 * before it is sent, and a retry after a failed send sends that same event
 * again, so a relay that took the first try sees a duplicate, never a
 * second note. At most `per_run` notes per run, oldest published first, so
 * the backlog goes out a few at a time.
 *
 * A start that changes after the note went out (a cup extended or moved to
 * its game's slot, an organizer's edit), or a tournament switched to lobbies
 * (P10: the game line names "one lobby match"), makes the note wrong: every run
 * first looks for notes of tournaments in sign-up whose text lacks the
 * current start (stale()), sends a NIP-09 deletion of each (kind 5, `e` on
 * the note, `k` 1) and posts a fresh note in its place, with the same copy
 * path and the new id in the same bot_posts row (correct()). The text is the
 * record of the announced start: it carries LeagueTime::stamp() of
 * starts_at, rendered here the same way, so no column is needed; a stored
 * note without the current stamp announced another start.
 *
 * The deletion has its own bot_posts row (kind 5, slot: the first 16
 * characters of the deleted note's id). It is the claim of the correction
 * (one run at a time, as for a note) and keeps the signed deletion: a relay
 * that did not take it gets the same event again on later runs, until every
 * relay took it or DELETION_ATTEMPTS ran out (logged); a run that stopped
 * after some relay took the deletion leaves the new note to the next run,
 * which posts it without waiting for the claim. Before it signs, a
 * run reads the note's row again and goes on only if the row still holds
 * the stale note, so it never deletes a note another run just posted. A
 * note signed with another key than the configured bot key is left alone
 * (a deletion by this key would not delete it), with a warning.
 *
 * A tournament called off before its note went out gets none. Fail
 * closed: without `esports.stream_bot.enabled`, the bot key or a stream
 * relay, nothing is claimed, signed or sent.
 */
class TournamentNotes
{
    public const KIND_NOTE = 1;

    /** NIP-09 deletion request. */
    public const KIND_DELETION = 5;

    /** Sends of one deletion before the relays that never took it are given up (logged). */
    private const DELETION_ATTEMPTS = 12;

    /** Names get this many characters in a note (the chat gets 40). */
    private const NAME_LENGTH = 80;

    /** Notes are written in this locale, whatever the process runs in. */
    private const LOCALE = 'en';

    public function __construct(
        private StreamBotPublisher $publisher,
        private GameRegistry $games,
        private PrizePool $pools,
    ) {}

    /**
     * One scheduler tick. Returns what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        $setup = $this->setup();

        if (is_string($setup)) {
            return $setup;
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->stale($this->perRun(), $key) as $tournament) {
            try {
                $lines[] = $this->correct($key, $tournament, $relays, $now);
            } catch (Throwable $e) {
                // The old note stays in the row, so the next run finds it stale again and retries.
                report($e);
                $lines[] = 'tournament '.$tournament->id.': start correction failed, '.$e->getMessage();
            }
        }

        foreach ($this->pendingDeletions($now) as $deletion) {
            try {
                $lines[] = $this->resendDeletion($deletion, $relays, $now);
            } catch (Throwable $e) {
                report($e);
                $lines[] = 'tournament '.$deletion->subject_id.': deletion resend failed, '.$e->getMessage();
            }
        }

        foreach ($this->due($now) as $tournament) {
            try {
                $lines[] = $this->post($key, $tournament, $relays, $now);
            } catch (Throwable $e) {
                // One broken note must not hold back the others; its claim expires and it is tried again.
                report($e);
                $lines[] = 'tournament '.$tournament->id.': failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no notes: every published tournament has its note' : implode("\n", $lines);
    }

    /**
     * A new note for a tournament whose delivered note its author deleted
     * (a NIP-09 kind-5 request naming the note's id, e.g. sent from a
     * client logged in with the bot key). Relays that honour the request
     * refuse that id for good, so the note is signed anew from the
     * tournament's current state and stored in place of the deleted one.
     *
     * Compare-and-set on the deleted id: a second call, or one after the
     * note was renewed already, changes nothing. A called-off tournament gets
     * no new note, as in due().
     */
    public function renew(Tournament $tournament, string $deletedId, CarbonImmutable $now): string
    {
        $setup = $this->setup();

        if (is_string($setup)) {
            return 'tournament '.$tournament->id.': '.$setup;
        }

        if ($tournament->status === TournamentStatus::Cancelled || $tournament->address() === null) {
            return 'tournament '.$tournament->id.': called off or unpublished, its deleted note is not renewed';
        }

        [$key, $relays] = $setup;

        if (! $this->release($tournament, $deletedId, $now)) {
            return 'tournament '.$tournament->id.': its note is not '.$deletedId.' (renewed already)';
        }

        Log::info('Stream bot tournament note deleted by its author, renewing', ['tournament' => $tournament->id, 'deleted' => $deletedId]);

        return $this->post($key, $tournament, $relays, $now);
    }

    /**
     * Tournaments in sign-up whose stored note announces another start
     * than the current one, by id, at most `$limit` (null: all). With the
     * bot key, a note signed by another key is left out with a warning:
     * this key cannot delete it, and it must not hold up the others.
     *
     * @return list<Tournament>
     */
    public function stale(?int $limit = null, ?LeagueKey $key = null): array
    {
        $posts = BotPost::query()
            ->where(['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'kind' => self::KIND_NOTE])
            ->whereNotNull('event')
            ->whereIn('subject_id', Tournament::query()->select('id')->where('status', TournamentStatus::Signup))
            ->orderBy('subject_id')
            ->get(['subject_id', 'event']);
        $tournaments = Tournament::query()->with('event')->whereKey($posts->pluck('subject_id')->all())
            ->whereNotNull('event_id')->whereNotNull('slug')->get()->keyBy('id');

        $stale = [];

        foreach ($posts as $post) {
            $tournament = $tournaments->get($post->subject_id);

            if ($tournament instanceof Tournament && ! $this->announcesStart((string) $post->event, $tournament)
                && ($key === null || $this->signedBy((string) $post->event, $key, $tournament))) {
                $stale[] = $tournament;
            }

            if ($limit !== null && count($stale) >= $limit) {
                break;
            }
        }

        return $stale;
    }

    /**
     * Whether a stored note (the signed event as JSON) names the
     * tournament's current start. A note that cannot be read counts as
     * current: it is never replaced on a guess.
     */
    public function announcesStart(string $storedEvent, Tournament $tournament): bool
    {
        $note = SignedEvent::fromInput(json_decode($storedEvent, true));

        if ($note === null) {
            return true;
        }

        $previous = app()->getLocale();
        app()->setLocale(self::LOCALE);

        try {
            // A lobby tournament's note (P10) also names the format in its game line ("one lobby match"): a note from
            // before the switch to lobbies announced another format.
            return str_contains($note->content, $this->startStamp($tournament))
                && (! Lobbies::isLobby($tournament) || str_contains($note->content, $this->gameLine($tournament)));
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The NIP-09 deletion of a note: kind 5 with the note's id in an `e`
     * and its kind in a `k` tag, signed with the bot key.
     */
    public function deletion(LeagueKey $key, string $noteId, int $createdAt): SignedEvent
    {
        return $key->sign(self::KIND_DELETION, [['e', $noteId], ['k', (string) self::KIND_NOTE]], 'The start changed, a corrected note follows.', $createdAt);
    }

    /**
     * Delete the stale note, then post the fresh one in its row; a line
     * for the log. The deletion's row is claimed first and the note's row
     * read again: only a run that holds the claim and still finds the stale
     * note in the row signs (once; a retry sends the stored deletion) and
     * sends. Throws when no relay took the deletion (the old note stays in
     * its row, so it is tried again after the claim expires).
     *
     * @param  list<string>  $relays
     */
    private function correct(LeagueKey $key, Tournament $tournament, array $relays, CarbonImmutable $now): string
    {
        $post = BotPost::query()->where($this->noteSubject($tournament))->firstOrFail();
        $old = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored note of tournament '.$tournament->id.' is not a valid event.');

        if (! $this->signedBy((string) $post->event, $key, $tournament)) {
            return 'tournament '.$tournament->id.': note '.$old->id.' is not signed by the bot key, left alone';
        }

        $subject = $this->deletionSubject($tournament, $old->id);
        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);
        $sent = BotPost::query()->where($subject)->firstOrFail();

        // A relay took this deletion already, but the run that sent it stopped before the note's row was
        // released (a crash, a locked database): the old note is deleted out there, so the new note is
        // due now, whatever the claim says. release() is a compare-and-set on the old id, so one run posts it;
        // the relays that missed the deletion get it from pendingDeletions().
        if ($sent->relays_accepted > 0) {
            $this->storedDeletion($sent, $old->id);

            if (! $this->release($tournament, $old->id, $now)) {
                return 'tournament '.$tournament->id.': note '.$old->id.' deleted, renewed by another run already';
            }

            return 'tournament '.$tournament->id.': note '.$old->id.' deleted before, '.$this->post($key, $tournament, $relays, $now);
        }

        if (! $this->claim($subject, $now)) {
            return 'tournament '.$tournament->id.': correction of note '.$old->id.' taken by another run';
        }

        // Read again under the claim: another run may have corrected the note since stale() read it.
        $fresh = BotPost::query()->where($this->noteSubject($tournament))->first();

        if ($fresh === null || $fresh->event_id !== $old->id || $this->announcesStart((string) $fresh->event, $tournament->refresh())) {
            return 'tournament '.$tournament->id.': note '.$old->id.' is no longer in its row or names the current start, nothing deleted';
        }

        $row = BotPost::query()->where($subject)->firstOrFail();

        if ($row->event === null) {
            $signed = $this->deletion($key, $old->id, $now->getTimestamp());
            $row->forceFill(['event_id' => $signed->id, 'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->save();
        }

        $this->storedDeletion($row, $old->id);
        [$deletion, $accepted, $total] = $this->sendDeletion($row, $relays, $now);

        if ($accepted === 0) {
            throw new RuntimeException('no relay took the deletion of note '.$old->id);
        }

        Log::info('Stream bot tournament note announced an old start, deleted and renewed', ['tournament' => $tournament->id, 'deleted' => $old->id, 'deletion' => $deletion->id, 'relays' => $accepted.'/'.$total, 'starts_at' => $tournament->starts_at->toIso8601String()]);

        if (! $this->release($tournament, $old->id, $now)) {
            return 'tournament '.$tournament->id.': note '.$old->id.' deleted, renewed by another run already';
        }

        return 'tournament '.$tournament->id.': note '.$old->id.' deleted (deletion '.$deletion->id.' to '.$accepted.'/'.$total.' relays), '.$this->post($key, $tournament, $relays, $now);
    }

    /**
     * The stored deletion of a row, refused unless it names the note it is
     * meant to delete (the row's slot is only the id's first 16 characters).
     */
    private function storedDeletion(BotPost $row, string $noteId): SignedEvent
    {
        $deletion = SignedEvent::fromInput(json_decode((string) $row->event, true))
            ?? throw new LogicException('The stored deletion '.$row->id.' is not a valid event.');

        if (array_column($deletion->tagsNamed('e'), 0) !== [$noteId]) {
            throw new LogicException('The stored deletion '.$deletion->id.' does not name note '.$noteId.'.');
        }

        return $deletion;
    }

    /**
     * Deletions some relay took but not every relay, still within their
     * attempts and not claimed within `retry_minutes`.
     *
     * @return list<BotPost>
     */
    private function pendingDeletions(CarbonImmutable $now): array
    {
        return array_values(BotPost::query()
            ->where(['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'kind' => self::KIND_DELETION])
            ->whereNotNull('event')
            ->whereNull('published_at')
            ->where('relays_accepted', '>', 0)
            ->where('attempts', '<', self::DELETION_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * The same signed deletion once more, to every relay (a relay that has
     * it answers with a duplicate); a line for the log.
     *
     * @param  list<string>  $relays
     */
    private function resendDeletion(BotPost $row, array $relays, CarbonImmutable $now): string
    {
        $subject = ['subject_type' => $row->subject_type, 'subject_id' => $row->subject_id, 'kind' => $row->kind, 'slot' => $row->slot];

        if (! $this->claim($subject, $now)) {
            return 'tournament '.$row->subject_id.': deletion '.$row->event_id.' taken by another run';
        }

        [$deletion, $accepted, $total] = $this->sendDeletion($row->refresh(), $relays, $now);

        return 'tournament '.$row->subject_id.': deletion '.$deletion->id.' sent again to '.$accepted.'/'.$total.' relays';
    }

    /**
     * Send a deletion row's stored event and record the answer: done once
     * every relay took it (a relay that has it answers with a duplicate).
     * `relays_accepted` never goes down: once some relay took the deletion,
     * a send that reached none (an outage) leaves it pending, not dropped.
     * `attempts` restarts at the first send some relay took, so the claims
     * of corrections no relay answered do not use up the resends. A warning
     * when the last attempt still missed a relay, whatever that send reached.
     *
     * @param  list<string>  $relays
     * @return array{SignedEvent, int, int}
     */
    private function sendDeletion(BotPost $row, array $relays, CarbonImmutable $now): array
    {
        $deletion = SignedEvent::fromInput(json_decode((string) $row->event, true))
            ?? throw new LogicException('The stored deletion '.$row->id.' is not a valid event.');
        $results = $this->publisher->publish($deletion->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));
        $everywhere = $accepted > 0 && $accepted === count($results);

        $firstTaken = $row->relays_accepted === 0 && $accepted > 0;

        $row->forceFill([
            'relays_accepted' => max($row->relays_accepted, $accepted),
            'relays_total' => count($results),
            'published_at' => $everywhere ? $now : null,
            'attempts' => $firstTaken ? 1 : $row->attempts,
        ])->save();

        if (! $everywhere && $row->relays_accepted > 0 && $row->attempts >= self::DELETION_ATTEMPTS) {
            Log::warning('Stream bot deletion given up for the relays that did not take it', [
                'tournament' => $row->subject_id,
                'deletion' => $deletion->id,
                'missing' => array_values(array_map(fn ($result): string => $result->relay.': '.$result->message, array_filter($results, fn ($result): bool => ! $result->accepted))),
            ]);
        }

        return [$deletion, $accepted, count($results)];
    }

    /**
     * Claim a row for this run: nobody delivered it and nobody claimed it
     * within `retry_minutes`.
     *
     * @param  array<string, mixed>  $subject
     */
    private function claim(array $subject, CarbonImmutable $now): bool
    {
        return BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]) === 1;
    }

    /**
     * Whether the stored note is signed by the bot key; a warning with both
     * public keys when it is not.
     */
    private function signedBy(string $storedEvent, LeagueKey $key, Tournament $tournament): bool
    {
        $note = SignedEvent::fromInput(json_decode($storedEvent, true));

        if ($note === null || $note->pubkey === $key->pubkey()) {
            return true;
        }

        Log::warning('Stream bot note announces an old start but is signed by another key, not deleted or renewed', ['tournament' => $tournament->id, 'note' => $note->id, 'note_pubkey' => $note->pubkey, 'bot_pubkey' => $key->pubkey()]);

        return false;
    }

    /**
     * @return array{subject_type: string, subject_id: int, kind: int}
     */
    private function noteSubject(Tournament $tournament): array
    {
        return ['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $tournament->id, 'kind' => self::KIND_NOTE];
    }

    /**
     * @return array{subject_type: string, subject_id: int, kind: int, slot: string}
     */
    private function deletionSubject(Tournament $tournament, string $noteId): array
    {
        return ['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $tournament->id, 'kind' => self::KIND_DELETION, 'slot' => substr($noteId, 0, 16)];
    }

    /**
     * Empty the note's row for a new note, compare-and-set on the old id:
     * false when the row holds another note already.
     */
    private function release(Tournament $tournament, string $oldId, CarbonImmutable $now): bool
    {
        return BotPost::query()
            ->where(['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $tournament->id, 'kind' => self::KIND_NOTE])
            ->where('event_id', $oldId)
            ->update(['event_id' => null, 'event' => null, 'published_at' => null, 'attempted_at' => null, 'relays_accepted' => 0, 'relays_total' => 0, 'updated_at' => $now]) === 1;
    }

    /**
     * The bot key and the stream relays, or why there are none (fail closed).
     *
     * @return array{LeagueKey, non-empty-list<string>}|string
     */
    public function setup(): array|string
    {
        if (! (bool) config('esports.stream_bot.enabled', false)) {
            return 'no notes: ESPORTS_STREAM_BOT_ENABLED is off';
        }

        $key = LeagueKey::streamBot();

        if ($key === null) {
            return 'no notes: ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key';
        }

        $relays = StreamCoordinates::relays();

        if ($relays === []) {
            return 'no notes: no stream relay to publish to (twentyone.stream.relays)';
        }

        return [$key, $relays];
    }

    /**
     * Published tournaments without a delivered note and not claimed by a
     * run within `retry_minutes`, oldest published first, at most `per_run`.
     *
     * @return list<Tournament>
     */
    public function due(CarbonImmutable $now): array
    {
        return array_values(Tournament::query()
            ->with('event')
            ->whereNotNull('event_id')
            ->whereNotNull('slug')
            ->whereNotIn('status', [TournamentStatus::Draft, TournamentStatus::Cancelled])
            // A Blockfill week has notes of its own (BlockfillNotes, plan "Blockfill", P6).
            ->exceptBlockfillWeeks()
            ->whereNotExists(fn (Builder $query) => $query->from('bot_posts')
                ->where('bot_posts.subject_type', BotPost::SUBJECT_TOURNAMENT)
                ->whereColumn('bot_posts.subject_id', 'tournaments.id')
                ->where('bot_posts.kind', self::KIND_NOTE)
                ->where(fn (Builder $taken) => $taken->whereNotNull('bot_posts.published_at')
                    ->orWhere('bot_posts.attempted_at', '>', $this->claimCutoff($now))))
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit($this->perRun())
            ->get()
            ->all());
    }

    /**
     * The note's text: the copy for the tournament's status, then the
     * calendar event as `nostr:naddr1…` after a blank line.
     */
    public function content(Tournament $tournament): string
    {
        $previous = app()->getLocale();
        app()->setLocale(self::LOCALE);

        try {
            $template = match ($tournament->status) {
                TournamentStatus::Signup => 'tournament_note_open',
                TournamentStatus::Drawing, TournamentStatus::Running => 'tournament_note_running',
                default => 'tournament_note_finished',
            };

            $body = StreamBotCopy::render($template, 0, [
                'name' => StreamBotCopy::clean($tournament->name, self::NAME_LENGTH),
                'game' => $this->gameLine($tournament),
                'starts' => $this->startStamp($tournament),
                'pot' => $this->pot($tournament),
                'url' => route('tournaments.show', $tournament),
            ]);
        } finally {
            app()->setLocale($previous);
        }

        return $body."\n\nnostr:".$this->naddr($tournament);
    }

    /**
     * The kind-1 note: the content and one NIP-18 `q` tag on the calendar
     * event's address with its relay hint. No `t` tag, no `#` (a standing
     * rule of this project), no `p` (the league account is not pinged).
     */
    public function event(LeagueKey $key, Tournament $tournament, int $createdAt): SignedEvent
    {
        $address = $tournament->address() ?? throw new LogicException('An unpublished tournament has no note.');
        $content = $this->content($tournament);
        $tags = [['q', $address, $this->relayHint() ?? '']];
        $body = explode("\n\nnostr:", $content, 2)[0];
        $problems = StreamBotCopy::violations($body, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own note: '.implode(', ', $problems));
        }

        return $key->sign(self::KIND_NOTE, $tags, $content, $createdAt);
    }

    /**
     * The calendar event's NIP-19 `naddr` with the league relay it was
     * published to as hint.
     */
    public function naddr(Tournament $tournament): string
    {
        $event = $tournament->event ?? throw new LogicException('An unpublished tournament has no naddr.');

        return NostrKeys::naddr(Tournament::CALENDAR_EVENT, $event->pubkey, (string) $tournament->slug, $this->relayHint());
    }

    /**
     * Claim, sign once, send; a line for the log.
     *
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, Tournament $tournament, array $relays, CarbonImmutable $now): string
    {
        $subject = ['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $tournament->id, 'kind' => self::KIND_NOTE];

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);

        // The claim: only one run gets the row, and only when nobody tried within retry_minutes.
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return 'tournament '.$tournament->id.': taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();

        // Signed once; a retry sends the stored event again, never a new one.
        if ($post->event === null) {
            $signed = $this->event($key, $tournament, $now->getTimestamp());
            $post->forceFill(['event_id' => $signed->id, 'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored note of tournament '.$tournament->id.' is not a valid event.');

        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));

        $post->forceFill([
            'relays_accepted' => $accepted,
            'relays_total' => count($results),
            'published_at' => $accepted > 0 ? $now : null,
        ])->save();

        Log::info('Stream bot tournament note', [
            'tournament' => $tournament->id,
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('tournament %d: %s id=%s to %d/%d relays', $tournament->id, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /** A casual cup's start on its region's clock (a US cup in New York time), everything else in the league's. */
    private function startStamp(Tournament $tournament): string
    {
        return LeagueTime::stamp($tournament->starts_at, $tournament->isCasualCup() ? CasualCups::timezoneOf($tournament) : null);
    }

    private function perRun(): int
    {
        return max(0, (int) config('esports.stream_bot.tournament_notes.per_run', 3));
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.stream_bot.tournament_notes.retry_minutes', 10)));
    }

    /** The first league relay (`esports.relays`), where the calendar event is published; null without one. */
    public function relayHint(): ?string
    {
        foreach ((array) config('esports.relays', []) as $relay) {
            if (EventBuilder::isRelayUrl($relay)) {
                return $relay;
            }
        }

        return null;
    }

    /**
     * The pot as the tournament sets it (fixed prizes' sum or the target),
     * never a wallet balance; null without one.
     */
    private function pot(Tournament $tournament): ?string
    {
        $configured = $tournament->prizeMode() === Tournament::PRIZES_FIXED || $tournament->prize_target_sats !== null;
        $pot = $configured ? $this->pools->potSats($tournament) : null;

        return $pot !== null && $pot > 0 ? number_format($pot) : null;
    }

    public function gameLine(Tournament $tournament): string
    {
        // A lobby tournament (P10): no 1v1, one lobby match of everyone.
        if (Lobbies::isLobby($tournament)) {
            return $this->games->name($tournament->game).', one lobby match';
        }

        $mode = $this->games->mode($tournament->game, $tournament->mode)->name ?? $tournament->mode;

        return trim($this->games->name($tournament->game).' '.$mode);
    }
}
