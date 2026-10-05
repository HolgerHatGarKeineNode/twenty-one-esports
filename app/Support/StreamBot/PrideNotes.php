<?php

namespace App\Support\StreamBot;

use App\Games\GameRegistry;
use App\Models\BotPost;
use App\Support\Chess\ChessModes;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\TwentyOne\Stream\StreamStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Pride notes on the stream bot's profile: the dynamic stream slides as
 * kind-1 notes with the rendered slide, the players they name tagged
 * (`p` and `nostr:npub1…` in the text) and congratulated.
 *
 * Four types, each at most once a day at its own slot, spread for EU and
 * US (`esports.stream_bot.pride_notes.slots`): the latest win (e1; chess or
 * a board game, a board game's tournament win as such, a lobby
 * tournament's shared 1st place with every player on it tagged), the
 * climbers of the week (e2), who just signed up (e3), the biggest pot's
 * prizes (e4). A type posts only when it has data and its text differs from
 * its last note, so a quiet day posts nothing. Each type takes the wording
 * after its last note's (ProfileNotes, note type "pride_<name>"), so the
 * same wording never follows itself, quiet days in between or not.
 *
 * Exactly once per type and day (BotPost, subject `pride`, the type as
 * subject id, the day as slot): claimed before it is signed, signed once,
 * a failed send retried with the same event. Fail closed like
 * TournamentNotes (flag, key, relays) and on `pride_notes.enabled`.
 */
class PrideNotes
{
    public const KIND_NOTE = 1;

    public const SUBJECT = 'pride';

    /** Type id => [name, slide, template]. */
    public const TYPES = [
        1 => ['win', 'e1', 'pride_note_win'],
        2 => ['climbers', 'e2', 'pride_note_climbers'],
        3 => ['signups', 'e3', 'pride_note_signups'],
        4 => ['prizes', 'e4', 'pride_note_prizes'],
    ];

    /** Players of a lobby tournament's shared place 1 tagged in one note (P8); the rest are counted. */
    public const LOBBY_MENTIONS = 8;

    /** Rendered slides older than this are deleted (days). */
    private const KEEP_IMAGES_DAYS = 30;

    public function __construct(
        private StreamBotPublisher $publisher,
        private TournamentNotes $notes,
        private PrideSlides $pride,
        private StreamImages $images,
        private StreamStats $stats,
    ) {}

    /**
     * One scheduler tick; what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        if (! (bool) config('esports.stream_bot.pride_notes.enabled', true)) {
            return 'no pride notes: esports.stream_bot.pride_notes.enabled is off';
        }

        $setup = $this->notes->setup();

        if (is_string($setup)) {
            return str_replace('no notes', 'no pride notes', $setup);
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->due($now) as $type => $day) {
            try {
                $lines[] = $this->post($key, $type, $day, $relays, $now);
            } catch (Throwable $e) {
                report($e);
                $lines[] = self::TYPES[$type][0].': failed, '.$e->getMessage();
            }
        }

        $this->pruneImages($now);

        return $lines === [] ? 'no pride notes: no slot open' : implode("\n", $lines);
    }

    /**
     * The types whose slot is open now and that have no note today: type => day (in the slot's zone).
     *
     * @return array<int, string>
     */
    public function due(CarbonImmutable $now): array
    {
        $window = max(1, (int) config('esports.stream_bot.pride_notes.window_hours', 3));
        $due = [];

        foreach (self::TYPES as $type => [$name]) {
            $slot = config('esports.stream_bot.pride_notes.slots.'.$name);

            if (! is_array($slot)) {
                continue;
            }

            $local = $now->setTimezone((string) ($slot['timezone'] ?? 'UTC'));
            [$hour, $minute] = array_map('intval', explode(':', (string) ($slot['time'] ?? '12:00')) + [1 => 0]);
            $start = $local->setTime($hour, $minute);

            if ($local->lt($start) || $local->gte($start->addHours($window))) {
                continue;
            }

            $day = $local->toDateString();
            $posted = BotPost::query()->where(['subject_type' => self::SUBJECT, 'subject_id' => $type, 'kind' => self::KIND_NOTE, 'slot' => $day])
                ->whereNotNull('published_at')->exists();

            if (! $posted) {
                $due[$type] = $day;
            }
        }

        return $due;
    }

    /**
     * The note of a type from the current data: text (body, blank line, the
     * slide's URL) and tags; null when the type has nothing to show.
     *
     * @return array{body: string, bodies: list<string>, tags: list<list<string>>, slide: array<string, mixed>}|null
     */
    public function compose(int $type, int $variant): ?array
    {
        [$name, , $template] = self::TYPES[$type];
        $data = $this->pride->read();

        // A board game that won its winner a tournament is told as the tournament win (plan "Mühle und Dame", P7):
        // "deciding game" only after a knockout's final; a name that cleans to nothing keeps the plain win.
        if ($name === 'win' && ($data['win']['kind'] ?? null) === 'lobby') {
            // A lobby tournament's place 1 (plan "AoE2 und Trackmania", P8): shared by the allies left standing, all tagged.
            $template = 'pride_note_lobby_win';
        } elseif ($name === 'win' && StreamBotCopy::clean((string) ($data['win']['tournament'] ?? ''), 80) !== '') {
            $template = ($data['win']['final'] ?? false) === true ? 'pride_note_tournament_win' : 'pride_note_tournament_table_win';
        } elseif ($name === 'win' && ($data['win']['kind'] ?? null) === 'series') {
            // A Rocket League / EA FC series (PrideSlides): no game to watch, the match page instead.
            $template = 'pride_note_series_win';
        }
        $tags = [];
        $mention = function (?array $ref) use (&$tags): ?string {
            $pubkey = is_array($ref) ? (string) ($ref['pubkey'] ?? '') : '';

            if (preg_match('/^[0-9a-f]{64}$/', $pubkey) !== 1) {
                return null;
            }

            if (! in_array(['p', $pubkey], $tags, true)) {
                $tags[] = ['p', $pubkey];
            }

            return 'nostr:'.NostrKeys::hexToNpub($pubkey);
        };

        $values = match ($name) {
            'win' => $this->winValues($data['win'], $mention),
            'climbers' => $this->climberValues($data['climbers'], $mention),
            'signups' => $this->signupValues($data['signups'], $mention),
            default => $this->prizeValues($data['prizes']),
        };

        if ($values === null) {
            return null;
        }

        $body = StreamBotCopy::render($template, $variant, $values);
        $problems = StreamBotCopy::violations($body, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own pride note: '.implode(', ', $problems));
        }

        // Every wording of the same facts: a note that only changed its wording is no news.
        $bodies = [];

        for ($other = 0; $other < StreamBotCopy::variants($template); $other++) {
            $bodies[] = StreamBotCopy::render($template, $other, $values);
        }

        return ['body' => $body, 'bodies' => $bodies, 'tags' => $tags, 'slide' => $data];
    }

    /**
     * @param  array<string, mixed>|null  $win
     * @return array<string, string|null>|null
     */
    private function winValues(?array $win, \Closure $mention): ?array
    {
        if (($win['kind'] ?? null) === 'lobby') {
            return $this->lobbyWinValues($win, $mention);
        }

        $winner = $win === null ? null : $mention($win['winnerRef'] ?? null);

        if ($winner === null) {
            return null;
        }

        $delta = $win['delta'] ?? null;

        return [
            'winner' => $winner,
            'loser' => StreamBotCopy::clean((string) ($win['loser'] ?? ''), 40),
            // "blitz chess"; a series keeps its game's name as written ("Rocket League 1v1").
            'mode' => ($win['kind'] ?? null) === 'series' ? (string) ($win['mode'] ?? '') : strtolower((string) ($win['mode'] ?? 'chess')),
            'elo' => is_int($delta) && $delta > 0 ? '+'.$delta.' casual Elo' : null,
            'tournament' => StreamBotCopy::resultName((string) ($win['tournament'] ?? ''), 80),
            // The game's page (a chess game, a board game) or the tournament a board game won (PrideSlides).
            'url' => is_string($win['url'] ?? null) && $win['url'] !== '' ? $win['url'] : route('games.show', (int) $win['gameId']),
        ];
    }

    /**
     * A lobby tournament's place 1 (PrideSlides `kind` lobby): every player on
     * it with a Nostr key tagged, at most LOBBY_MENTIONS, the rest counted;
     * null when none has a key (nobody to congratulate by name).
     *
     * @param  array<string, mixed>  $win
     * @return array<string, string|null>|null
     */
    private function lobbyWinValues(array $win, \Closure $mention): ?array
    {
        $winners = [];
        $more = 0;

        foreach (is_array($win['winners'] ?? null) ? $win['winners'] : [] as $winner) {
            $who = is_array($winner) ? $mention($winner['ref'] ?? null) : null;

            if ($who === null) {
                continue;
            }

            if (count($winners) < self::LOBBY_MENTIONS) {
                $winners[] = $who;
            } else {
                $more++;
            }
        }

        return $winners === [] ? null : [
            'first' => count($winners) + $more > 1 ? 'Shared 1st place' : '1st place',
            'winners' => implode(', ', $winners).($more > 0 ? ' +'.$more.' more' : ''),
            'tournament' => StreamBotCopy::resultName((string) ($win['tournament'] ?? ''), 80),
            'mode' => StreamBotCopy::clean((string) ($win['mode'] ?? ''), 60),
            'players' => (string) (int) ($win['players'] ?? 0),
            'url' => is_string($win['url'] ?? null) && $win['url'] !== '' ? $win['url'] : route('tournaments.index'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $climbers
     * @return array<string, string|null>|null
     */
    private function climberValues(array $climbers, \Closure $mention): ?array
    {
        $parts = [];

        foreach ($climbers as $climber) {
            $who = $mention($climber['ref'] ?? null);

            if ($who !== null) {
                $parts[] = $who.' +'.(int) $climber['gain'];
            }
        }

        return $parts === [] ? null : [
            'players' => implode(' · ', $parts),
            'days' => (string) PrideSlides::DAYS,
            'url' => $this->climbersUrl($climbers),
        ];
    }

    /**
     * Where the gains came from: the ladder of the top climber's biggest
     * gain (PrideSlides orders each climber's ladders by gain). A ladder of
     * a game no longer registered (a board game switched off) is no link;
     * data cached before the ladders were read (just after a deploy) falls
     * back to the default chess ladder (rapid).
     *
     * @param  list<array<string, mixed>>  $climbers
     */
    private function climbersUrl(array $climbers): string
    {
        $ladders = $climbers[0]['ladders'] ?? [];

        foreach (is_array($ladders) ? $ladders : [] as $ladder) {
            [$game, $mode] = is_string($ladder) ? [...explode('/', $ladder, 2), ''] : ['', ''];

            if (app(GameRegistry::class)->mode($game, $mode) !== null) {
                return route('ladder.show', ['game' => $game, 'mode' => $mode]);
            }
        }

        return route('ladder.show', ['game' => 'chess', 'mode' => ChessModes::DEFAULT]);
    }

    /**
     * @param  list<array<string, mixed>>  $signups
     * @return array<string, string|null>|null
     */
    private function signupValues(array $signups, \Closure $mention): ?array
    {
        $players = [];

        foreach ($signups as $signup) {
            $who = $mention($signup['ref'] ?? null);

            if ($who !== null && ! in_array($who, $players, true)) {
                $players[] = $who;
            }
        }

        return $players === [] ? null : [
            'players' => implode(', ', $players),
            'count' => (string) count($players),
            'url' => route('tournaments.index'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $prizes
     * @return array<string, string|null>|null
     */
    private function prizeValues(?array $prizes): ?array
    {
        if ($prizes === null || ! is_int($prizes['pot'] ?? null)) {
            return null;
        }

        $amounts = array_map(fn (array $place): string => number_format((int) $place['sats']), $prizes['places'] ?? []);
        $sponsors = array_values(array_filter(array_map(fn ($sponsor): string => StreamBotCopy::clean((string) $sponsor, 40), $prizes['sponsors'] ?? [])));

        return [
            'pot' => number_format($prizes['pot']),
            'name' => StreamBotCopy::clean((string) ($prizes['name'] ?? ''), 80),
            'places' => $amounts === [] ? null : implode(' / ', $amounts).' sats for places 1–'.count($amounts),
            'sponsors' => $sponsors === [] ? null : 'Sponsored by '.implode(', ', $sponsors),
            'url' => (string) ($prizes['url'] ?? route('tournaments.index')),
        ];
    }

    /**
     * Claim, compare with the last note, render the slide, sign once, send.
     *
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, int $type, string $day, array $relays, CarbonImmutable $now): string
    {
        [$name, $scene] = self::TYPES[$type];
        $subject = ['subject_type' => self::SUBJECT, 'subject_id' => $type, 'kind' => self::KIND_NOTE, 'slot' => $day];
        $post = BotPost::query()->where($subject)->first();

        $variant = ProfileNotes::nextVariant(self::noteType($type), StreamBotCopy::variants(self::TYPES[$type][2]));

        // Not signed yet: only when there is something new to say.
        if ($post === null || $post->event === null) {
            $note = $this->compose($type, $variant);

            if ($note === null) {
                return $name.': nothing to show';
            }

            if (in_array($this->lastBody($type), $note['bodies'], true)) {
                return $name.': unchanged since its last note';
            }
        }

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);
        $retry = max(1, (int) config('esports.stream_bot.pride_notes.retry_minutes', 10));
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $now->subMinutes($retry)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return $name.': taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();

        if ($post->event === null) {
            $note ??= $this->compose($type, $variant) ?? throw new LogicException('The pride note lost its data.');
            [$url, $imeta] = $this->image($scene, $note['slide']);
            $signed = $key->sign(self::KIND_NOTE, [...$note['tags'], $imeta], $note['body']."\n\n".$url, $now->getTimestamp());
            $post->forceFill([
                'event_id' => $signed->id,
                'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'note_type' => self::noteType($type),
                'variant' => $variant,
            ])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored pride note '.$name.' is not a valid event.');
        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));
        $post->forceFill(['relays_accepted' => $accepted, 'relays_total' => count($results), 'published_at' => $accepted > 0 ? $now : null])->save();

        Log::info('Stream bot pride note', ['type' => $name, 'id' => $event->id, 'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results)]);

        return sprintf('%s: %s id=%s to %d/%d relays', $name, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /** The ProfileNotes note type of a pride type: "pride_win", "pride_climbers", … */
    public static function noteType(int $type): string
    {
        return 'pride_'.self::TYPES[$type][0];
    }

    /**
     * The text of the type's last delivered note, without its image line.
     */
    private function lastBody(int $type): ?string
    {
        $event = BotPost::query()->where(['subject_type' => self::SUBJECT, 'subject_id' => $type, 'kind' => self::KIND_NOTE])
            ->whereNotNull('published_at')->latest('published_at')->value('event');
        $content = is_string($event) ? (json_decode($event, true)['content'] ?? null) : null;

        return is_string($content) ? explode("\n\n", $content, 2)[0] : null;
    }

    /**
     * The slide rendered as the stream shows it (no viewer count), kept by
     * its hash and served at /stream/pride/<hash>.png; its URL and NIP-92 `imeta` tag.
     *
     * @param  array<string, mixed>  $data  PrideSlides::read()
     * @return array{0: string, 1: list<string>}
     */
    private function image(string $scene, array $data): array
    {
        $png = SceneRenderer::fromConfig()->png([
            'pride' => $this->pride->framed($data),
            'stats' => $this->stats->all(),
            'backdrop' => $this->images->backdrop(StreamImages::BRAND),
            'viewers' => null,
        ], RotationPlanner::VIEWS[$scene]);
        $hash = hash('sha256', $png);
        $path = self::imagePath($hash);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $png);
        $url = route('stream.pride-image', ['hash' => $hash]);

        return [$url, ['imeta', 'url '.$url, 'm image/png', 'dim 1280x720', 'x '.$hash]];
    }

    public static function imagePath(string $hash): string
    {
        return rtrim((string) config('esports.stream_bot.pride_notes.image_dir'), '/').'/'.$hash.'.png';
    }

    private function pruneImages(CarbonImmutable $now): void
    {
        foreach (File::glob(rtrim((string) config('esports.stream_bot.pride_notes.image_dir'), '/').'/*.png') as $file) {
            if ((int) @filemtime($file) < $now->subDays(self::KEEP_IMAGES_DAYS)->getTimestamp()) {
                File::delete($file);
            }
        }
    }
}
