<?php

namespace App\Support\StreamBot;

use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\StreamBotPost;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\TournamentPlacements;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The GG in the stream chat (user, 2026-10-03): the moment a published
 * tournament is decided, the stream bot says so in the 24/7 stream's live
 * chat (kind 1311 under its 30311), once:
 *
 *   🏆 GG! <mention> wins <tournament> 🎉 <url>
 *
 * The winner is named by the Nostr key of their account (`nostr:npub1…` and
 * a `p` tag, a team's members and a shared place 1 up to
 * PrideNotes::LOBBY_MENTIONS), never a game account. No hashtag, no `t` tag.
 *
 * It skips the rotation's cadence (StreamBot rules 4-6: the daily cap, the
 * interval, never two bot posts in a row) but keeps its air rules 1-3
 * (StreamBot::airBlocker(): the switch, the key, a live stream, the quiet
 * hours). Only tournaments decided within `esports.stream_bot.gg.window_minutes`
 * (TournamentLiveSlides::finishedAt()): a GG hours later is no GG, so one
 * missed in the quiet hours or off air is skipped, never posted late.
 *
 * Exactly once per tournament (BotPost, subject `tournament_gg`, kind 1311):
 * claimed before it is signed, signed once, a send no relay took retried
 * with the same event after `gg.retry_minutes`. A delivered GG is also a
 * StreamBotPost with the fact `tournament-winner:<id>`, so the rotation's
 * `tournament_winner` does not name the same winner again within its
 * repeat hours and the daily cap counts it.
 */
class ChampionChat
{
    public const SUBJECT = 'tournament_gg';

    public const TEMPLATE = 'tournament_gg';

    /** Names get this many characters in the chat, as in the rotation. */
    private const NAME_LENGTH = 60;

    public function __construct(
        private StreamBot $bot,
        private StreamBotPublisher $publisher,
        private TournamentChampion $champions,
        private TournamentPlacements $placements,
    ) {}

    /**
     * One scheduler tick; what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        $key = LeagueKey::streamBot();
        $stream = StreamCoordinates::fromConfig();
        $blocker = $this->bot->airBlocker($now, $key, $stream);

        if ($blocker !== null) {
            return 'no GG: '.$blocker;
        }

        assert($key !== null && $stream !== null);
        $lines = [];

        foreach ($this->due($now) as $tournament) {
            try {
                $lines[] = $this->post($key, $stream, $tournament, $now);
            } catch (Throwable $e) {
                // One broken GG must not hold back the others; its claim expires and it is tried again.
                report($e);
                $lines[] = 'tournament '.$tournament->id.': GG failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no GG: no newly decided tournament without its GG' : implode("\n", $lines);
    }

    /**
     * Published, finished tournaments (no league week) decided within the
     * window, with a place 1, without a delivered or freshly claimed GG;
     * oldest finish first.
     *
     * @return list<Tournament>
     */
    public function due(CarbonImmutable $now): array
    {
        $since = $now->subMinutes(max(1, (int) config('esports.stream_bot.gg.window_minutes', 30)));

        // A tournament is updated when it finishes, so `updated_at` before the window rules it out without reading its matches.
        $candidates = Tournament::query()
            ->where('status', TournamentStatus::Finished)
            ->whereNotNull('published_at')
            ->exceptLeagueWeeks()
            ->where('updated_at', '>=', $since)
            ->whereNotExists(fn (Builder $query) => $query->from('bot_posts')
                ->where('bot_posts.subject_type', self::SUBJECT)
                ->whereColumn('bot_posts.subject_id', 'tournaments.id')
                ->where('bot_posts.kind', StreamBot::KIND_LIVE_CHAT)
                ->where(fn (Builder $taken) => $taken->whereNotNull('bot_posts.published_at')
                    ->orWhere('bot_posts.attempted_at', '>', $this->claimCutoff($now))))
            ->orderBy('id')
            ->get();

        $due = [];

        foreach ($candidates as $tournament) {
            $finished = TournamentLiveSlides::finishedAt($tournament);

            if ($finished !== null && $finished->gte($since) && $this->winners($tournament) !== []) {
                $due[] = ['tournament' => $tournament, 'finished' => $finished->getTimestamp()];
            }
        }

        usort($due, fn (array $a, array $b): int => [$a['finished'], $a['tournament']->id] <=> [$b['finished'], $b['tournament']->id]);

        return array_map(fn (array $item): Tournament => $item['tournament'], $due);
    }

    /**
     * The GG's text and `p` tags; null without a place 1.
     *
     * @return array{content: string, tags: list<list<string>>}|null
     */
    public function compose(Tournament $tournament): ?array
    {
        $winners = $this->winners($tournament);
        $name = StreamBotCopy::clean($tournament->name, self::NAME_LENGTH);

        if ($winners === [] || $name === '') {
            return null;
        }

        $tags = [];
        $named = [];

        foreach ($winners as $winner) {
            $named[] = $this->named($winner, $tags);
        }

        $content = StreamBotCopy::render(self::TEMPLATE, count($named) > 1 ? 1 : 0, [
            'winner' => count($named) > 1 ? implode(', ', array_slice($named, 0, -1)).' & '.$named[count($named) - 1] : $named[0],
            'name' => $name,
            'url' => route('tournaments.show', $tournament),
        ]);
        $problems = StreamBotCopy::violations($content, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own GG: '.implode(', ', $problems));
        }

        return ['content' => $content, 'tags' => $tags];
    }

    /**
     * The single champion, else everybody on a shared place 1.
     *
     * @return list<TournamentParticipant>
     */
    private function winners(Tournament $tournament): array
    {
        $champion = $this->champions->of($tournament);

        if ($champion !== null) {
            return [$champion];
        }

        $first = $this->placements->of($tournament)[0] ?? null;

        if ($first === null || $first['place'] !== 1) {
            return [];
        }

        return array_values(TournamentParticipant::query()->whereKey($first['participants'])->where('tournament_id', $tournament->id)->orderBy('id')->get()->all());
    }

    /**
     * A winner as the chat names them: a solo player's `nostr:npub1…` (their
     * plain name without a key); a team's name with its members' mentions in
     * brackets. Each mention adds its `p` tag once, up to LOBBY_MENTIONS.
     *
     * @param  list<list<string>>  $tags
     */
    private function named(TournamentParticipant $winner, array &$tags): string
    {
        $ids = $winner->memberIds();
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

        $name = StreamBotCopy::clean($winner->name, 40);

        if ($winner->lineup_id === null && count($ids) <= 1) {
            return $mentions[0] ?? $name;
        }

        return $mentions === [] ? $name : $name.' ('.implode(', ', $mentions).')';
    }

    /**
     * Claim, sign once, send to the stream relays; a line for the log.
     */
    private function post(LeagueKey $key, StreamCoordinates $stream, Tournament $tournament, CarbonImmutable $now): string
    {
        $subject = ['subject_type' => self::SUBJECT, 'subject_id' => $tournament->id, 'kind' => StreamBot::KIND_LIVE_CHAT];

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);

        // The claim: only one run gets the row, and only when nobody tried within retry_minutes.
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return 'tournament '.$tournament->id.': GG taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();

        // Signed once; a retry sends the stored event again, never a new one.
        if ($post->event === null) {
            $message = $this->compose($tournament) ?? throw new LogicException('Tournament '.$tournament->id.' has no place 1.');
            $signed = $this->bot->event($key, $stream, $message['content'], $now->getTimestamp(), $message['tags']);
            $post->forceFill([
                'event_id' => $signed->id,
                'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored GG of tournament '.$tournament->id.' is not a valid event.');
        $results = $this->publisher->publish($event->toArray(), $stream->relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));
        $post->forceFill(['relays_accepted' => $accepted, 'relays_total' => count($results), 'published_at' => $accepted > 0 ? $now : null])->save();

        if ($accepted > 0) {
            // In the chat log: the cap counts it and the rotation does not name this winner again soon. It does not move the
            // rotation's own next post (that one's next_due_at stands): the GG came on top.
            StreamBotPost::query()->create([
                'builder' => self::TEMPLATE,
                'fact_key' => 'tournament-winner:'.$tournament->id,
                'event_id' => $event->id,
                'content' => $event->content,
                'relays_accepted' => $accepted,
                'relays_total' => count($results),
                'posted_at' => $now,
                'next_due_at' => StreamBotPost::query()->latest('posted_at')->latest('id')->first()->next_due_at ?? $now,
            ]);
        }

        Log::info('Stream bot GG', [
            'tournament' => $tournament->id,
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('tournament %d: GG %s id=%s to %d/%d relays', $tournament->id, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.stream_bot.gg.retry_minutes', 2)));
    }
}
