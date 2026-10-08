<?php

namespace App\Support\StreamBot;

use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The stream bot's free-places reminders on its own profile (P49): while a
 * published tournament is open for sign-up and has places left, a kind-1
 * note naming them ("3 of 8 places left") at fixed slots before sign-up
 * closes. Special tournaments get `special_slots_hours` (7 d, 3 d, 24 h,
 * 3 h), casual cups `cup_slots_hours` (24 h, 3 h); their capacity is read
 * at posting time, so a cup that grew counts its current size.
 *
 * Slots: a slot of h hours is due from `close − h` until the next (smaller)
 * slot's moment, or until `stop_before_close_minutes` before the close,
 * whichever comes first. The windows do not overlap, so at most one slot
 * of a tournament is due at a time, and a slot whose window passed (the
 * scheduler was down, the tournament was full then) is skipped, never
 * posted late. A slot whose moment lies before the tournament was published
 * is skipped too: the tournament's own note (TournamentNotes) announces it
 * then. Nothing within `stop_before_close_minutes` of the close.
 *
 * Exactly once per tournament and slot (BotPost, subject
 * SUBJECT_FREE_PLACES, one row per slot), claimed before signing like
 * TournamentNotes: a rival run at the same time skips it, a failed send is
 * retried with the same signed event after `retry_minutes`, while its slot
 * is still due and places are still left. At most `per_run` notes per run
 * across all tournaments, the special tournaments first, then the casual
 * cups, each the soonest sign-up close first; and no more than the note
 * type's cooldown and daily cap allow (ProfileNotes, "free_places"). A
 * reminder held back by them goes out later while its slot is still due,
 * else it is skipped. Each reminder takes the wording after the last one.
 *
 * Fail closed: without `esports.stream_bot.enabled`, the bot key or a
 * stream relay, nothing is claimed, signed or sent (TournamentNotes::setup).
 */
class FreePlaceNotes
{
    /** The note type of these notes for ProfileNotes (cooldown, daily cap, wording). */
    public const NOTE_TYPE = 'free_places';

    /** Names get this many characters in a note, as in TournamentNotes. */
    private const NAME_LENGTH = 80;

    /** Notes are written in this locale, whatever the process runs in. */
    private const LOCALE = 'en';

    public function __construct(
        private TournamentNotes $notes,
        private StreamBotPublisher $publisher,
        private TournamentSignups $signups,
    ) {}

    /**
     * One scheduler tick. Returns what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        $setup = $this->notes->setup();

        if (is_string($setup)) {
            return $setup;
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->due($now) as $due) {
            if (($hold = ProfileNotes::hold($now)) !== null) {
                $lines[] = 'held: '.$hold;

                break;
            }

            try {
                $lines[] = $this->post($key, $due, $relays, $now);
            } catch (Throwable $e) {
                // One broken note must not hold back the others; its claim expires and it is tried again while its slot is due.
                report($e);
                $lines[] = 'tournament '.$due['tournament']->id.' slot '.$due['slot'].': failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no free-places notes: no tournament has a due slot with places left' : implode("\n", $lines);
    }

    /**
     * The notes this run would post: published tournaments in sign-up with
     * a due slot, places left and no delivered or freshly claimed note for
     * that slot, the special tournaments first, each soonest close first, at
     * most `per_run` and what the type's cooldown and daily cap allow.
     *
     * @return list<array{tournament: Tournament, slot: string, free: int, places: int}>
     */
    public function due(CarbonImmutable $now): array
    {
        $perRun = min(max(0, (int) LeagueSettings::get('esports.stream_bot.free_places.per_run')), ProfileNotes::allowance(self::NOTE_TYPE, $now));
        $longest = max([0, ...$this->slotHours(false), ...$this->slotHours(true)]);

        if ($perRun === 0 || $longest === 0) {
            return [];
        }

        $candidates = Tournament::query()
            ->with('event')
            ->where('status', TournamentStatus::Signup)
            ->whereNotNull('event_id')
            ->whereNotNull('slug')
            ->where('signup_closes_at', '>', $now->addMinutes($this->stopMinutes()))
            ->where('signup_closes_at', '<=', $now->addHours($longest))
            ->orderByRaw('case when cup_series is null then 0 else 1 end')
            ->orderBy('signup_closes_at')
            ->orderBy('id')
            ->get();

        $due = [];

        foreach ($candidates as $tournament) {
            $hours = $this->dueSlot($tournament, $now);

            if ($hours === null) {
                continue;
            }

            $slot = $hours.'h';
            $places = $this->signups->places($tournament);
            $free = $places['places'] - $places['taken'];

            if ($free <= 0 || $this->taken($tournament, $slot, $now) || ProfileNotes::subjectSpokeToday('tournament_champion', $tournament->id, $now)) {
                continue;
            }

            $due[] = ['tournament' => $tournament, 'slot' => $slot, 'free' => $free, 'places' => $places['places']];

            if (count($due) >= $perRun) {
                break;
            }
        }

        return $due;
    }

    /**
     * The slot (in hours before the close) due for this tournament now, or
     * null: none, its window passed, it lies before the tournament was
     * published, or the close is within `stop_before_close_minutes`.
     */
    public function dueSlot(Tournament $tournament, CarbonImmutable $now): ?int
    {
        if ($tournament->signup_closes_at === null) {
            return null;
        }

        $closes = $tournament->signup_closes_at->toImmutable();
        $stop = $closes->subMinutes($this->stopMinutes());
        $hours = $this->slotHours($tournament->isCasualCup());

        foreach ($hours as $index => $slot) {
            $moment = $closes->subHours($slot);
            $next = isset($hours[$index + 1]) ? $closes->subHours($hours[$index + 1]) : $stop;
            $end = $next->lessThan($stop) ? $next : $stop;

            if ($now->lessThan($moment) || ! $now->lessThan($end)) {
                continue;
            }

            // Published after this slot's moment: the tournament's own note announced it.
            if ($tournament->published_at !== null && $moment->lessThan($tournament->published_at)) {
                return null;
            }

            return $slot;
        }

        return null;
    }

    /**
     * The note's text in the wording `$variant` (null: the one the next
     * reminder takes): the free places, the game, the start (a casual cup on
     * its region's clock, everything else on the league's), the time left to
     * sign up and the link, then the calendar event as `nostr:naddr1…`.
     */
    public function content(Tournament $tournament, int $free, int $places, CarbonImmutable $now, ?int $variant = null): string
    {
        $previous = app()->getLocale();
        app()->setLocale(self::LOCALE);

        try {
            $body = StreamBotCopy::render('tournament_note_places', $variant ?? self::nextVariant(), [
                'free' => $free,
                'places' => $places,
                'name' => StreamBotCopy::clean($tournament->name, self::NAME_LENGTH),
                'game' => $this->notes->gameLine($tournament),
                'starts' => LeagueTime::stamp($tournament->starts_at, $tournament->isCasualCup() ? CasualCups::timezoneOf($tournament) : null),
                'left' => StreamBotCopy::duration((int) $tournament->signup_closes_at?->getTimestamp() - $now->getTimestamp()),
                'url' => route('tournaments.show', $tournament),
            ]);
        } finally {
            app()->setLocale($previous);
        }

        return $body."\n\nnostr:".$this->notes->naddr($tournament);
    }

    /**
     * The kind-1 note in the wording `$variant` (null: the next one): the
     * content and one NIP-18 `q` tag on the calendar event's address with
     * its relay hint. No `t` tag, no `#`.
     */
    public function event(LeagueKey $key, Tournament $tournament, int $free, int $places, CarbonImmutable $now, ?int $variant = null): SignedEvent
    {
        $address = $tournament->address() ?? throw new LogicException('An unpublished tournament has no free-places note.');
        $content = $this->content($tournament, $free, $places, $now, $variant);
        $tags = [['q', $address, $this->notes->relayHint() ?? '']];
        $body = explode("\n\nnostr:", $content, 2)[0];
        $problems = StreamBotCopy::violations($body, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own free-places note: '.implode(', ', $problems));
        }

        return $key->sign(TournamentNotes::KIND_NOTE, $tags, $content, $now->getTimestamp());
    }

    /**
     * Claim, sign once, send; a line for the log.
     *
     * @param  array{tournament: Tournament, slot: string, free: int, places: int}  $due
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, array $due, array $relays, CarbonImmutable $now): string
    {
        $tournament = $due['tournament'];
        $subject = $this->subject($tournament, $due['slot']);
        $label = 'tournament '.$tournament->id.' slot '.$due['slot'];

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);

        // The claim: only one run gets the row, and only when nobody tried within retry_minutes.
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return $label.': taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();

        // Signed once; a retry sends the stored event again, never a new one.
        if ($post->event === null) {
            $variant = self::nextVariant();
            $signed = $this->event($key, $tournament, $due['free'], $due['places'], $now, $variant);
            $post->forceFill([
                'event_id' => $signed->id,
                'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'note_type' => self::NOTE_TYPE,
                'variant' => $variant,
            ])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored free-places note of '.$label.' is not a valid event.');

        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));

        $post->forceFill([
            'relays_accepted' => $accepted,
            'relays_total' => count($results),
            'published_at' => $accepted > 0 ? $now : null,
        ])->save();

        Log::info('Stream bot free-places note', [
            'tournament' => $tournament->id,
            'slot' => $due['slot'],
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('%s: %s id=%s to %d/%d relays', $label, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /** The wording the next reminder takes (ProfileNotes). */
    private static function nextVariant(): int
    {
        return ProfileNotes::nextVariant(self::NOTE_TYPE, StreamBotCopy::variants('tournament_note_places'));
    }

    /**
     * Delivered, or claimed by a run within `retry_minutes`.
     */
    private function taken(Tournament $tournament, string $slot, CarbonImmutable $now): bool
    {
        return BotPost::query()->where($this->subject($tournament, $slot))
            ->where(fn ($query) => $query->whereNotNull('published_at')->orWhere('attempted_at', '>', $this->claimCutoff($now)))
            ->exists();
    }

    /**
     * @return array{subject_type: string, subject_id: int, kind: int, slot: string}
     */
    private function subject(Tournament $tournament, string $slot): array
    {
        return ['subject_type' => BotPost::SUBJECT_FREE_PLACES, 'subject_id' => $tournament->id, 'kind' => TournamentNotes::KIND_NOTE, 'slot' => $slot];
    }

    /**
     * The slots of a casual cup or a special tournament, in hours before
     * the close, longest first; slots that are not positive are dropped.
     *
     * @return list<int>
     */
    private function slotHours(bool $cup): array
    {
        $hours = array_map('intval', (array) LeagueSettings::get('esports.stream_bot.free_places.'.($cup ? 'cup_slots_hours' : 'special_slots_hours')));
        $hours = array_values(array_unique(array_filter($hours, fn (int $slot): bool => $slot > 0)));
        rsort($hours);

        return $hours;
    }

    private function stopMinutes(): int
    {
        return max(0, (int) LeagueSettings::get('esports.stream_bot.free_places.stop_before_close_minutes'));
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.stream_bot.free_places.retry_minutes', 10)));
    }
}
