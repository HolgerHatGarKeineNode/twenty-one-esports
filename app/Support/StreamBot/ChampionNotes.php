<?php

namespace App\Support\StreamBot;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The champion of a finished tournament on the stream bot's profile: one
 * kind-1 note per published special tournament or casual cup with a single
 * champion (TournamentChampion), the league weeks left out (they have
 * their own winner notes). The champion is named by the Nostr key of their
 * account (`nostr:npub1…` and a `p` tag per member of a team, at most
 * PrideNotes::LOBBY_MENTIONS), never a game account; a member without a key
 * is not tagged, a solo champion without one is named plainly. The prize
 * is named once the payout ledger has it paid (place 1, TournamentPayout);
 * the calendar event follows as `nostr:naddr1…` with a NIP-18 `q` tag, then
 * the champion slide (ta6) as the stream shows it, kept with the pride
 * slides and served at /stream/pride/<hash>.png with a NIP-92 `imeta` tag.
 *
 * Only tournaments finished (TournamentLiveSlides::finishedAt()) within
 * `esports.stream_bot.champion_notes.days`, so switching this on does not
 * post the whole history; at most `per_run` per run, oldest finish first.
 *
 * Exactly once per tournament (BotPost, subject `tournament_champion`): the
 * row is claimed before it is signed, signed once, and a failed send is
 * retried with the same event after `retry_minutes`. Fail closed like
 * TournamentNotes (flag, key, relays) and on `champion_notes.enabled`.
 */
class ChampionNotes
{
    public const KIND_NOTE = 1;

    public const SUBJECT = 'tournament_champion';

    /** The note type for ProfileNotes (wording, pacing). */
    public const NOTE_TYPE = 'champion';

    public const TEMPLATE = 'champion_note';

    /** The champion slide the note carries. */
    public const SCENE = 'ta6';

    /** Names get this many characters in a note, as in TournamentNotes. */
    private const NAME_LENGTH = 80;

    public function __construct(
        private StreamBotPublisher $publisher,
        private TournamentNotes $notes,
        private TournamentChampion $champions,
        private TournamentLiveSlides $slides,
        private StreamStats $stats,
    ) {}

    /**
     * One scheduler tick; what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        if (! (bool) config('esports.stream_bot.champion_notes.enabled', true)) {
            return 'no champion notes: esports.stream_bot.champion_notes.enabled is off';
        }

        $setup = $this->notes->setup();

        if (is_string($setup)) {
            return str_replace('no notes', 'no champion notes', $setup);
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->due($now) as $tournament) {
            if (($hold = ProfileNotes::hold($now)) !== null) {
                $lines[] = 'held: '.$hold;

                break;
            }

            try {
                $lines[] = $this->post($key, $tournament, $relays, $now);
            } catch (Throwable $e) {
                // One broken note must not hold back the others; its claim expires and it is tried again.
                report($e);
                $lines[] = 'tournament '.$tournament->id.': champion note failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no champion notes: no newly finished tournament without its note' : implode("\n", $lines);
    }

    /**
     * Published, finished tournaments (no league week) that ended within
     * `days`, have a single champion and no delivered or freshly claimed
     * note: oldest finish first, at most `per_run` and what the note type's
     * pacing allows (ProfileNotes).
     *
     * @return list<Tournament>
     */
    public function due(CarbonImmutable $now): array
    {
        $limit = min(max(0, (int) config('esports.stream_bot.champion_notes.per_run', 1)), ProfileNotes::allowance(self::NOTE_TYPE, $now));

        if ($limit <= 0) {
            return [];
        }

        $since = $now->subDays(max(1, (int) config('esports.stream_bot.champion_notes.days', 5)));

        // A tournament is updated when it finishes, so `updated_at` before the window rules it out without reading its matches.
        $candidates = Tournament::query()
            ->with('event')
            ->where('status', TournamentStatus::Finished)
            ->whereNotNull('event_id')
            ->whereNotNull('slug')
            ->exceptLeagueWeeks()
            ->where('updated_at', '>=', $since)
            ->whereNotExists(fn (Builder $query) => $query->from('bot_posts')
                ->where('bot_posts.subject_type', self::SUBJECT)
                ->whereColumn('bot_posts.subject_id', 'tournaments.id')
                ->where('bot_posts.kind', self::KIND_NOTE)
                ->where(fn (Builder $taken) => $taken->whereNotNull('bot_posts.published_at')
                    ->orWhere('bot_posts.attempted_at', '>', $this->claimCutoff($now))))
            ->orderBy('id')
            ->get();

        $due = [];

        foreach ($candidates as $tournament) {
            $finished = TournamentLiveSlides::finishedAt($tournament);

            if ($finished !== null && $finished->gte($since) && $this->champions->of($tournament) !== null
                && ! ProfileNotes::subjectSpokeToday(BotPost::SUBJECT_FREE_PLACES, $tournament->id, $now)) {
                $due[] = ['tournament' => $tournament, 'finished' => $finished->getTimestamp()];
            }
        }

        usort($due, fn (array $a, array $b): int => [$a['finished'], $a['tournament']->id] <=> [$b['finished'], $b['tournament']->id]);

        return array_map(fn (array $item): Tournament => $item['tournament'], array_slice($due, 0, $limit));
    }

    /**
     * The note's text and tags in the wording `$variant`: the copy, then
     * the calendar event as `nostr:naddr1…` after a blank line; null
     * without a single champion.
     *
     * @return array{content: string, tags: list<list<string>>}|null
     */
    public function compose(Tournament $tournament, int $variant): ?array
    {
        $champion = $this->champions->of($tournament);
        $address = $tournament->address();

        if ($champion === null || $address === null) {
            return null;
        }

        $tags = [];
        $previous = app()->getLocale();
        app()->setLocale('en');

        try {
            $body = StreamBotCopy::render(self::TEMPLATE, $variant, [
                'winner' => $this->winner($champion, $tags),
                'name' => StreamBotCopy::clean($tournament->name, self::NAME_LENGTH),
                'game' => $this->notes->gameLine($tournament),
                'prize' => $this->prize($tournament),
                'url' => route('tournaments.show', $tournament),
            ]);
        } finally {
            app()->setLocale($previous);
        }

        $tags[] = ['q', $address, $this->notes->relayHint() ?? ''];
        $problems = StreamBotCopy::violations($body, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own champion note: '.implode(', ', $problems));
        }

        return ['content' => $body."\n\nnostr:".$this->notes->naddr($tournament), 'tags' => $tags];
    }

    /**
     * The champion as named in the note: a solo player's `nostr:npub1…`
     * (their plain name without a key); a team's name with its members'
     * `nostr:npub1…` in brackets. Each mention adds its `p` tag once.
     *
     * @param  list<list<string>>  $tags
     */
    private function winner(TournamentParticipant $champion, array &$tags): string
    {
        $ids = $champion->memberIds();
        $users = User::query()->whereKey($ids)->get()->keyBy('id');
        $mentions = [];

        foreach ($ids as $id) {
            $pubkey = (string) $users->get($id)?->pubkey;

            if (preg_match('/^[0-9a-f]{64}$/', $pubkey) !== 1 || count($tags) >= PrideNotes::LOBBY_MENTIONS) {
                continue;
            }

            if (! in_array(['p', $pubkey], $tags, true)) {
                $tags[] = ['p', $pubkey];
                $mentions[] = 'nostr:'.NostrKeys::hexToNpub($pubkey);
            }
        }

        $name = StreamBotCopy::clean($champion->name, self::NAME_LENGTH);

        if ($champion->lineup_id === null && count($ids) <= 1) {
            return $mentions[0] ?? $name;
        }

        return $mentions === [] ? $name : $name.' ('.implode(', ', $mentions).')';
    }

    /** Place 1's paid prize in sats (every member's payout of a team), null while nothing is paid. */
    private function prize(Tournament $tournament): ?string
    {
        $sats = (int) TournamentPayout::query()->where('tournament_id', $tournament->id)->where('place', 1)
            ->where('status', PayoutStatus::Paid)->sum('amount_sats');

        return $sats > 0 ? number_format($sats) : null;
    }

    /**
     * Claim, render the slide, sign once, send; a line for the log.
     *
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, Tournament $tournament, array $relays, CarbonImmutable $now): string
    {
        $subject = ['subject_type' => self::SUBJECT, 'subject_id' => $tournament->id, 'kind' => self::KIND_NOTE];

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);

        // The claim: only one run gets the row, and only when nobody tried within retry_minutes.
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return 'tournament '.$tournament->id.': champion note taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();

        // Signed once; a retry sends the stored event again, never a new one.
        if ($post->event === null) {
            $variant = ProfileNotes::nextVariant(self::NOTE_TYPE, StreamBotCopy::variants(self::TEMPLATE));
            $note = $this->compose($tournament, $variant) ?? throw new LogicException('Tournament '.$tournament->id.' has no single champion.');
            [$url, $imeta] = $this->image($tournament, $now);
            $signed = $key->sign(self::KIND_NOTE, [...$note['tags'], $imeta], $note['content']."\n\n".$url, $now->getTimestamp());
            $post->forceFill([
                'event_id' => $signed->id,
                'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'note_type' => self::NOTE_TYPE,
                'variant' => $variant,
            ])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored champion note of tournament '.$tournament->id.' is not a valid event.');
        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));
        $post->forceFill(['relays_accepted' => $accepted, 'relays_total' => count($results), 'published_at' => $accepted > 0 ? $now : null])->save();

        Log::info('Stream bot champion note', [
            'tournament' => $tournament->id,
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('tournament %d: champion %s id=%s to %d/%d relays', $tournament->id, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /**
     * The champion slide rendered as the stream shows it (no viewer count),
     * kept by its hash with the pride slides; its URL and NIP-92 `imeta` tag.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function image(Tournament $tournament, CarbonImmutable $now): array
    {
        $frame = $this->slides->data($tournament, $now->getTimestampMs());
        $backdrop = $frame['backdrop'] ?? null;
        unset($frame['backdrop']);

        $png = SceneRenderer::fromConfig()->png([
            'tournament' => $frame,
            'stats' => $this->stats->all(),
            'backdrop' => is_string($backdrop) ? $backdrop : null,
            'viewers' => null,
        ], RotationPlanner::VIEWS[self::SCENE]);
        $hash = hash('sha256', $png);
        $path = PrideNotes::imagePath($hash);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $png);
        $url = route('stream.pride-image', ['hash' => $hash]);

        return [$url, ['imeta', 'url '.$url, 'm image/png', 'dim 1280x720', 'x '.$hash]];
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.stream_bot.champion_notes.retry_minutes', 10)));
    }
}
