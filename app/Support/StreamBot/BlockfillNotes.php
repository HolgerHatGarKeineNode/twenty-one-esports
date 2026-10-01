<?php

namespace App\Support\StreamBot;

use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Nostr\SignedEvent;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The stream bot's Blockfill week notes on its own profile (plan
 * "Blockfill", P6): a kind-1 note when a week is open (SLOT_OPEN), and one
 * when it is finished with its winner and top 3 (SLOT_WINNER). A Blockfill
 * week is no organizer's tournament, so TournamentNotes leaves it out.
 *
 * - The week note goes out while the week runs, only for the week the clock
 *   is in (a missed week is not announced late).
 * - The winner note goes out once the score kind finished the week (its
 *   review time is over, ScoreLeaderboards), within WINNER_DAYS of its end,
 *   and only when somebody is on the board.
 *
 * Exactly once per week and slot (BotPost, subject SUBJECT, one row per
 * slot), claimed before signing as in FreePlaceNotes: a rival run at the
 * same time skips it, a failed send is retried with the same signed event
 * after `retry_minutes`. With the week's 31923 published, the note carries
 * its `nostr:naddr1…` and a NIP-18 `q` tag; without it the note stands on
 * its link alone. No `t` tag, no `#`, no `p` (players are named, not
 * pinged), and nothing about fees: the copy rules of StreamBotCopy.
 *
 * Fail closed: without `esports.stream_bot.enabled`, the bot key or a
 * stream relay (TournamentNotes::setup), or while Blockfill is not
 * registered, nothing is claimed, signed or sent.
 */
final class BlockfillNotes
{
    public const SUBJECT = 'blockfill_week';

    public const SLOT_OPEN = 'open';

    public const SLOT_WINNER = 'winner';

    /** A finished week's winner is announced within this many days of the week's end, never later. */
    public const WINNER_DAYS = 7;

    /** The top places a winner note names. */
    public const PODIUM = 3;

    /** Names get this many characters in a note, as in TournamentNotes. */
    private const NAME_LENGTH = 40;

    /** Notes are written in this locale, whatever the process runs in. */
    private const LOCALE = 'en';

    public function __construct(
        private TournamentNotes $notes,
        private StreamBotPublisher $publisher,
        private BlockfillWeeks $weeks,
        private ScoreRuns $runs,
    ) {}

    /**
     * One scheduler tick. Returns what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        if ($this->weeks->game() === null) {
            return 'no Blockfill notes: Blockfill is off';
        }

        $setup = $this->notes->setup();

        if (is_string($setup)) {
            return $setup;
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->due($now) as $due) {
            try {
                $lines[] = $this->post($key, $due['week'], $due['slot'], $relays, $now);
            } catch (Throwable $e) {
                // One broken note must not hold back the other; its claim expires and it is tried again.
                report($e);
                $lines[] = 'blockfill week '.$due['week']->id.' '.$due['slot'].': failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no Blockfill notes: every week has its notes' : implode("\n", $lines);
    }

    /**
     * The notes this run would post: the winners of weeks finished within
     * WINNER_DAYS, oldest first, then the running week of `$now`; none that
     * was delivered or freshly claimed. Empty while Blockfill is off.
     *
     * @return list<array{week: Tournament, slot: string}>
     */
    public function due(CarbonImmutable $now): array
    {
        if ($this->weeks->game() === null) {
            return [];
        }

        $due = [];
        $finished = Tournament::query()->where(['game' => Blockfill::SLUG, 'status' => TournamentStatus::Finished])
            ->where('starts_at', '>=', $now->subDays(7 + self::WINNER_DAYS + 1))->orderBy('starts_at')->get();

        foreach ($finished as $week) {
            if ($week->isBlockfillWeek() && ScoreWindow::of($week)->end->greaterThanOrEqualTo($now->subDays(self::WINNER_DAYS))
                && $this->runs->standings($week) !== [] && ! $this->taken($week, self::SLOT_WINNER, $now)) {
                $due[] = ['week' => $week, 'slot' => self::SLOT_WINNER];
            }
        }

        $current = $this->weeks->current($now);

        if ($current !== null && $current->status === TournamentStatus::Running && ScoreWindow::of($current)->contains($now) && ! $this->taken($current, self::SLOT_OPEN, $now)) {
            $due[] = ['week' => $current, 'slot' => self::SLOT_OPEN];
        }

        return $due;
    }

    /**
     * The note's text, in English: the week and how to play it, or its
     * winner and top 3; then the week's calendar event as `nostr:naddr1…`
     * after a blank line, once it is published.
     */
    public function content(Tournament $week, string $slot): string
    {
        $previous = app()->getLocale();
        app()->setLocale(self::LOCALE);

        try {
            $body = $slot === self::SLOT_WINNER ? $this->winner($week) : StreamBotCopy::render('blockfill_note_week', 0, [
                'name' => StreamBotCopy::clean($week->title(), self::NAME_LENGTH),
                'ends' => LeagueTime::stamp(ScoreWindow::of($week)->end),
                'url' => route('stacker.play'),
            ]);
        } finally {
            app()->setLocale($previous);
        }

        return $week->address() === null ? $body : $body."\n\nnostr:".$this->notes->naddr($week);
    }

    /**
     * The kind-1 note: the content and, once the week's 31923 is published,
     * one NIP-18 `q` tag on its address. Refused when the text breaks the
     * copy rules.
     */
    public function event(LeagueKey $key, Tournament $week, string $slot, int $createdAt): SignedEvent
    {
        $content = $this->content($week, $slot);
        $address = $week->address();
        $tags = $address === null ? [] : [['q', $address, $this->notes->relayHint() ?? '']];
        $problems = StreamBotCopy::violations(explode("\n\nnostr:", $content, 2)[0], $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own Blockfill note: '.implode(', ', $problems));
        }

        return $key->sign(TournamentNotes::KIND_NOTE, $tags, $content, $createdAt);
    }

    private function winner(Tournament $week): string
    {
        $standings = array_values(array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null));
        $first = $standings[0] ?? throw new LogicException('A week without a placed player has no winner note.');
        $metric = ScoreMetric::time();
        $podium = array_map(fn (ScoreStanding $row): string => $row->place.'. '.StreamBotCopy::clean($row->participant->name, self::NAME_LENGTH).' '.$metric->format((int) $row->value),
            array_slice($standings, 0, self::PODIUM));

        return StreamBotCopy::render('blockfill_note_winner', 0, [
            'name' => StreamBotCopy::clean($week->title(), self::NAME_LENGTH),
            'winner' => StreamBotCopy::clean($first->participant->name, self::NAME_LENGTH),
            'time' => $metric->format((int) $first->value),
            'podium' => implode(' · ', $podium),
            'url' => route('scores.show', Blockfill::SLUG),
        ]);
    }

    /**
     * Claim, sign once, send; a line for the log.
     *
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, Tournament $week, string $slot, array $relays, CarbonImmutable $now): string
    {
        $subject = $this->subject($week, $slot);
        $label = 'blockfill week '.$week->id.' '.$slot;

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
            $signed = $this->event($key, $week, $slot, $now->getTimestamp());
            $post->forceFill(['event_id' => $signed->id, 'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored note of '.$label.' is not a valid event.');

        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));

        $post->forceFill([
            'relays_accepted' => $accepted,
            'relays_total' => count($results),
            'published_at' => $accepted > 0 ? $now : null,
        ])->save();

        Log::info('Stream bot Blockfill note', [
            'week' => $week->id,
            'slot' => $slot,
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('%s: %s id=%s to %d/%d relays', $label, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /**
     * Delivered, or claimed by a run within `retry_minutes`.
     */
    private function taken(Tournament $week, string $slot, CarbonImmutable $now): bool
    {
        return BotPost::query()->where($this->subject($week, $slot))
            ->where(fn ($query) => $query->whereNotNull('published_at')->orWhere('attempted_at', '>', $this->claimCutoff($now)))
            ->exists();
    }

    /**
     * @return array{subject_type: string, subject_id: int, kind: int, slot: string}
     */
    private function subject(Tournament $week, string $slot): array
    {
        return ['subject_type' => self::SUBJECT, 'subject_id' => $week->id, 'kind' => TournamentNotes::KIND_NOTE, 'slot' => $slot];
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.stream_bot.tournament_notes.retry_minutes', 10)));
    }
}
