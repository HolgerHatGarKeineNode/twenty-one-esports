<?php

namespace App\Support\Caster\Programme;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Nostr\RelayReader;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live comments on a running organizer tournament (user 2026-10-14, Blockli Cup #1: "Live Comments über das Turnier
 * immer wieder mit Jingle … durch echte LIVE Turnierdaten. Lustiges Roasten der Spieler und Matches … Auslesen der
 * Spieler-Nostr-Profile"): every `every_minutes` while such a tournament runs, Markus and Sirius talk about it for a
 * minute from what the database knows right now (the boards on the go, who is to move, finished games, the field and
 * each player's own Nostr bio), queued as the rubric `turnier_live`, so it airs in a block behind the Anchor's
 * "breaking" jingle, also in the music-first live mode. Off unless switched on (`tournament_live.enabled`).
 */
final class TournamentLive
{
    public const TOPIC = 'turnier_live';

    public const CHANNEL = 'caster_tournament_live';

    private const LAST = 'caster:tournament_live:last';

    private const SAID = 'caster:tournament_live:said';

    private const INTRODUCED = 'caster:tournament_live:introduced';

    /** The finished games a comment already told (their ids), so the next one does not tell them again. */
    private const TOLD = 'caster:tournament_live:told';

    /** How many players one introduction comment presents (poker-style, from their Nostr bios). */
    private const INTRO_PLAYERS = 2;

    /** The tournament the last comment was about: the next comment takes the next one (tournament()). */
    private const TURN = 'caster:tournament_live:turn';

    public function __construct(private readonly DuetScripts $duets, private readonly VoiceUsage $usage) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.tournament_live.enabled') === true && CasterVoice::enabled();
    }

    /** The next tournament to comment on: configured running ids take turns; else the latest organizer tournament. */
    public static function tournament(): ?Tournament
    {
        $ids = array_values(array_filter(array_map('intval', (array) config('tv.commentary.tournament_live.tournaments', []))));
        $query = Tournament::query()->where('status', TournamentStatus::Running);

        if ($ids === []) {
            return $query->whereNotNull('created_by_id')->whereNull('cup_series')->where('opened_by_league', false)->orderByDesc('starts_at')->first();
        }

        $running = $query->whereIn('id', $ids)->orderBy('id')->get();
        $last = Cache::get(self::TURN);

        return $running->first(fn (Tournament $tournament): bool => is_int($last) && $tournament->id > $last) ?? $running->first();
    }

    /** Whether a comment waits: queued or being voiced, or voiced and ready in the spool, not aired yet. */
    public static function waiting(CarbonImmutable $now): bool
    {
        $scripts = CasterScript::query()->where('topic', self::TOPIC)->where('expires_at', '>', $now);

        return (clone $scripts)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->exists()
            || CasterClip::query()->where('status', CasterClip::READY)->whereIn('caster_script_id', (clone $scripts)->select('id'))->exists();
    }

    /**
     * Writes and queues the next comment when one is due; returns the scripts written (0 or 1).
     */
    public function fill(ScriptQueue $queue, CarbonImmutable $now): int
    {
        if (! self::enabled() || ($tournament = self::tournament()) === null) {
            return 0;
        }

        $every = 60 * max(1, (int) config('tv.commentary.tournament_live.every_minutes', 8));
        $last = Cache::get(self::LAST);

        // No daily cap any more (user 2026-10-15: "Tages-Cap? Was ein Blödsinn, weg damit bei opencode!"): the
        // texts cost nothing to write, the interval gate is the only clock, and a cap silently muted the stream
        // for the rest of the day once the morning's minute-tactic had spent it.
        if ((is_int($last) && $now->getTimestamp() - $last < $every)
            // The next only once the last one aired (user 2026-10-18: "Es darf nur nicht ein Stau passieren und lauter
            // Breakings fürs Turnier auf einmal hintereinander kommen"): none waits unvoiced, and none voiced but unaired
            // in the spool. Nothing expires any more, so this alone keeps the comments from piling up.
            || self::waiting($now)
        ) {
            return 0;
        }

        $since = is_int($last) ? $last : null;
        // First the field is introduced, three players a comment, like the table at a poker final (user 2026-10-14);
        // then the comments follow what happens on the boards.
        $introduced = array_values(array_filter((array) Cache::get(self::INTRODUCED.':'.$tournament->id, []), 'is_int'));
        $intro = TournamentParticipant::query()->where('tournament_id', $tournament->id)->whereNotIn('id', $introduced)->orderBy('group')->orderBy('id')
            ->limit(self::INTRO_PLAYERS)->pluck('id')->all();
        $intro = array_values(array_map(intval(...), $intro));
        $told = array_values(array_filter((array) Cache::get(self::TOLD.':'.$tournament->id, []), 'is_int'));
        $material = self::material($tournament, $now, $intro, $since, $told);
        $finishedIds = self::games($tournament)->where('finished', true)->pluck('id')->all();
        $said = array_values(array_filter((array) Cache::get(self::SAID.':'.$tournament->id, []), 'is_string'));

        try {
            $talk = $this->draft($material, $said, $intro !== []);
        } catch (Throwable $exception) {
            Log::warning('Tournament live comment failed', ['exception' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 300)]);

            $talk = null;
        }

        if ($talk === null) {
            // A comment every five minutes no matter what (user 2026-10-15: "Zwang! auch wenn nichts passiert
            // ist!"): when the model gives nothing usable, the standby duet carries the slot. A standby that
            // aired holds the whole interval like any comment (user 2026-10-17: "es kommen ständig Breakings" -
            // the minute retry made one come every minute while the model gave nothing); only a try that aired
            // nothing retries in a minute.
            $talk = ['text' => self::STANDBY[array_rand(self::STANDBY)], 'speakers' => [DuetDirector::MARKUS, DuetDirector::SIRIUS]];
            $queued = $queue->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $talk['text'], sha1('tlive:'.$tournament->id.':standby:'.$now->getTimestamp()), 74, $now,
                topic: self::TOPIC, speakers: $talk['speakers']);
            Cache::put(self::LAST, $queued !== null ? $now->getTimestamp() : $now->getTimestamp() - $every + 60, 6 * 3600);

            if ($queued !== null) {
                Cache::put(self::TURN, $tournament->id, 24 * 3600);
            }

            return $queued !== null ? 1 : 0;
        }

        Cache::put(self::SAID.':'.$tournament->id, array_slice([...$said, mb_substr(str_replace("\n", ' ', $talk['text']), 0, 400)], -4), 12 * 3600);
        Cache::put(self::INTRODUCED.':'.$tournament->id, [...$introduced, ...$intro], 24 * 3600);
        Cache::put(self::TOLD.':'.$tournament->id, array_values(array_unique([...$told, ...$finishedIds])), 24 * 3600);

        $queued = $queue->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $talk['text'], sha1('tlive:'.$tournament->id.':'.$now->getTimestamp()), 74, $now,
            topic: self::TOPIC, speakers: $talk['speakers']);

        if ($queued === null) {
            // The duet passed the guard but the queue's coherence emptied it (everything in it was said before):
            // the standby duet carries the interval anyway - a comment comes every interval, by force (user 2026-10-15).
            $queued = $queue->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, self::STANDBY[array_rand(self::STANDBY)], sha1('tlive:'.$tournament->id.':standby:'.$now->getTimestamp()), 74, $now,
                topic: self::TOPIC, speakers: [DuetDirector::MARKUS, DuetDirector::SIRIUS]);
        }

        Cache::put(self::LAST, $now->getTimestamp(), 6 * 3600);
        Cache::put(self::TURN, $tournament->id, 24 * 3600);

        return $queued !== null ? 1 : 0;
    }

    /** The standby duet when the model gives nothing usable: a comment comes anyway (user 2026-10-15). No speaker
     * prefixes in the text — the `speakers` array carries who says which line, and the voice reads the text. */
    private const STANDBY = [
        "Zwischenstand beim Turnier: die Bretter laufen, entschieden hat sich nichts, und genau so gefällt mir das.\nGeduld ist auch eine Disziplin, auch wenn sie dir so liegt wie ein Rucksack voll Blei.",
        "Keine neuen Ergebnisse, aber die Uhren laufen weiter, und irgendwer trödelt garantiert schon wieder.\nDas Trödeln ist taktisch, das nennt man Druck aufbauen, ohne selbst welchen zu haben.",
        "Weiter nichts Neues vom Turnierbrett, die Fans halten die Luft an, ich halte ein Bier bereit.\nDeine medizinische Versorgung ist beeindruckend, aber erzähl das lieber dem, der als Nächstes am Zug ist.",
    ];

    /**
     * The tournament as it stands, in plain German lines: the field with each player's Nostr bio, the boards on the go
     * and the finished games.
     */
    /**
     * @param  list<int>  $intro  participants to introduce in this comment
     * @param  int|null  $since  the last comment (Unix seconds): games finished after it are the news
     * @param  list<int>  $told  finished games an earlier comment already told
     */
    public static function material(Tournament $tournament, CarbonImmutable $now, array $intro = [], ?int $since = null, array $told = []): string
    {
        $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get();
        $users = User::query()->whereIn('id', $participants->pluck('user_id')->filter())->get()->keyBy('id');
        $pubkeys = array_values(array_filter($users->pluck('pubkey')->all(), 'is_string'));
        $bios = self::bios($tournament, $pubkeys);
        $posts = self::posts($tournament, $pubkeys, $now);
        // The bio from the relays (newest kind 0 over all of them), else the copy the player's browser handed in.
        foreach ($users as $user) {
            $user->about = $bios[$user->pubkey] ?? $user->about;
        }
        $name = fn (?int $id): string => $id !== null && $users->has($id) ? $users[$id]->displayName() : 'unbekannt';
        $groupOf = $participants->filter(fn ($p) => $p->user_id !== null && $p->group !== null)->mapWithKeys(fn ($p) => [$p->user_id => $p->group]);
        $options = (array) $tournament->options;

        $lines = [
            'Turnier: '.$tournament->title().' (Spiel '.$tournament->game.', Modus '.$tournament->mode.', Format '.$tournament->format->value
                .(isset($options['groupStage']) && $tournament->format === TournamentFormat::TwoStage ? ', Gruppenphase '.$options['groupStage'].', Gruppen zu '.($options['groupSize'] ?? '?').', die besten '.($options['advance'] ?? '?').' jeder Gruppe kommen weiter' : '').').',
            // No clock times in the material (user 2026-10-16: the breakings air too late for them - "liest du
            // lieber erst gar keine Uhrzeiten vor"): the day is all the model learns, never the hour.
            'Turnierstart: '.$tournament->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y').' (keine Uhrzeit nennen, niemals).',
        ];

        if ($tournament->game === 'chess') {
            $round = TournamentRunner::currentRound($tournament);
            $lines[] = 'Schach mit Uhr: jede Partie läuft live hier auf der Seite. Ein Sieg zählt einen Punkt, ein Remis einen halben.'
                .($round !== null ? ' Gerade läuft Runde '.$round->number.'.' : '');
        }

        if ($tournament->game === 'blockli') {
            $lines[] = 'Blockli ist digitales Quoridor: die eigene Figur als Erster auf die andere Seite bringen und dem Gegner Mauern in den Weg stellen, ohne ihn ganz einzusperren. Fernpartie heißt: bis zu 24 Stunden pro Zug, jeder spielt seine Gruppenpartien gleichzeitig.';
        }

        $lines[] = '';
        $lines[] = 'Spieler ('.$participants->count().'), mit Gruppe und eigener Nostr-Bio:';

        foreach ($participants->sortBy('group') as $participant) {
            $user = $participant->user_id !== null ? $users->get($participant->user_id) : null;
            $about = $user?->about !== null ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $user->about))) : '';
            $own = $user?->pubkey !== null ? ($posts[$user->pubkey] ?? []) : [];
            $lines[] = '- '.$participant->name.($participant->group !== null ? ' (Gruppe '.$participant->group.')' : '').($about !== '' ? ': „'.mb_substr($about, 0, 180).'“' : ': keine Bio gefunden')
                .($own !== [] ? '; letzter Nostr-Post: „'.$own[0].'“' : '');
        }

        if ($intro !== []) {
            $lines[] = '';
            $lines[] = 'DIESE Spieler stellt ihr jetzt vor (wie am Finaltisch beim Poker):';

            foreach ($participants->whereIn('id', $intro) as $participant) {
                $user = $participant->user_id !== null ? $users->get($participant->user_id) : null;
                $about = $user?->about !== null ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $user->about))) : '';
                $own = $user?->pubkey !== null ? ($posts[$user->pubkey] ?? []) : [];
                $lines[] = '- '.$participant->name.($participant->group !== null ? ', Gruppe '.$participant->group : '').', Wertung '.(int) $participant->rating.', Nostr-Bio: '.($about !== '' ? '„'.mb_substr($about, 0, 400).'“' : 'keine gefunden')
                    .($own !== [] ? ', letzte Nostr-Posts: „'.implode('“ / „', $own).'“' : ', keine Posts der letzten 30 Tage gefunden');
            }
        }

        $games = self::games($tournament);
        $active = $games->where('finished', false);
        $finished = $games->where('finished', true)->sortByDesc('ended_at');

        $lines[] = '';
        $lines[] = 'Bretter laufen gerade: '.$active->count().', fertig: '.$finished->count().'.';

        foreach ($active->sortByDesc('ply')->take(8) as $game) {
            $lines[] = '- '.$name($game['white_id']).' gegen '.$name($game['black_id']).($groupOf->has($game['white_id']) ? ' (Gruppe '.$groupOf[$game['white_id']].')' : '')
                .': '.($game['ply'] === 0 ? 'noch kein Zug' : $game['ply'].' Züge gespielt').', am Zug: '.$name($game['to_move']).'.';
        }

        $wins = [];

        foreach ($finished as $game) {
            $winnerId = $game['result'] === '1-0' ? $game['white_id'] : ($game['result'] === '0-1' ? $game['black_id'] : null);

            if ($winnerId !== null) {
                $wins[$winnerId] = ($wins[$winnerId] ?? 0) + 1;
            }
        }

        arsort($wins);
        $lines[] = 'Siege bisher: '.($wins === [] ? 'noch keine.' : implode(', ', array_map(fn ($id, $count): string => $name((int) $id).' '.$count, array_keys($wins), $wins)).'.');
        $fresh = $finished->reject(fn (array $game): bool => in_array($game['id'], $told, true));
        $lines[] = $fresh->isEmpty() ? 'NEUE Ergebnisse seit dem letzten Einwurf: keine. Erzählt keine alten Ergebnisse noch einmal.' : 'NEUE Ergebnisse seit dem letzten Einwurf (nur diese als Neuigkeit erzählen, ältere nicht wiederholen):';

        foreach ($fresh->take(6) as $game) {
            $winner = match ($game['result']) {
                '1-0' => $name($game['white_id']),
                '0-1' => $name($game['black_id']),
                default => null,
            };
            $lines[] = '- Fertig: '.$name($game['white_id']).' gegen '.$name($game['black_id']).': '.($winner !== null ? 'gewonnen hat '.$winner : ($game['result'] === '1/2-1/2' ? 'Remis' : 'Ergebnis offen')).'.';
        }

        $movers = $active->countBy(fn (array $game): int => (int) $game['to_move'])->sortDesc()->take(3);

        foreach ($movers as $id => $count) {
            $lines[] = 'Am meisten offene Züge: '.$name((int) $id).' ist an '.$count.' Brettern am Zug.';
        }

        return implode("\n", $lines);
    }

    /**
     * The tournament's games as plain rows: Blockli boards or chess games (side to move from the FEN), aborted ones left out.
     *
     * @return Collection<int, array{id: int, white_id: int|null, black_id: int|null, finished: bool, result: string, ply: int, to_move: int|null, ended_at: int}>
     */
    private static function games(Tournament $tournament): Collection
    {
        $matches = $tournament->matches()->select('id');

        if ($tournament->game === 'chess') {
            return ChessGame::query()->whereIn('tournament_match_id', $matches)->where('status', '!=', ChessGameStatus::Aborted)->get()
                ->map(fn (ChessGame $game): array => [
                    'id' => (int) $game->id, 'white_id' => $game->white_id, 'black_id' => $game->black_id,
                    'finished' => $game->status === ChessGameStatus::Finished, 'result' => self::result($game->result), 'ply' => (int) $game->ply,
                    'to_move' => (explode(' ', (string) $game->fen)[1] ?? 'w') === 'b' ? $game->black_id : $game->white_id,
                    'ended_at' => (int) $game->ended_at?->getTimestamp(),
                ])->values();
        }

        return BoardGame::query()->whereIn('tournament_match_id', $matches)->whereIn('status', [BoardGameStatus::Active, BoardGameStatus::Finished])->get()
            ->map(fn (BoardGame $game): array => [
                'id' => (int) $game->id, 'white_id' => $game->white_id, 'black_id' => $game->black_id,
                'finished' => $game->status === BoardGameStatus::Finished, 'result' => self::result($game->result), 'ply' => (int) $game->ply,
                'to_move' => $game->turn === 'w' ? $game->white_id : $game->black_id,
                'ended_at' => (int) $game->ended_at?->getTimestamp(),
            ])->values();
    }

    /** A game's result as the material reads it ("1-0", "0-1", "1/2-1/2", or empty while it runs). */
    private static function result(?string $result): string
    {
        return (string) $result;
    }

    /**
     * The players' Nostr bios read from the relays (user 2026-10-14: "kann eigentlich nicht sein, dass so viele Nostr
     * Profile leer sind": the league only kept the bio a player's own browser handed in): the newest kind 0 of each
     * pubkey over the knowledge relays, nos.lol, nostr.band and the league's relays, merged; kept six hours.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, string> bio by pubkey
     */
    public static function bios(Tournament $tournament, array $pubkeys): array
    {
        if ($pubkeys === []) {
            return [];
        }

        return Cache::remember('caster:tournament_live:bios:'.$tournament->id.':'.sha1(implode(',', $pubkeys)), 6 * 3600, function () use ($pubkeys): array {
            $bios = [];

            foreach (app(RelayReader::class)->fetchCounted([['kinds' => [0], 'authors' => array_slice($pubkeys, 0, 200), 'limit' => 200]], self::relays(), [], 1, microtime(true) + 12)['events'] as $event) {
                $meta = json_decode($event->content, true);
                $about = is_array($meta) && is_string($meta['about'] ?? null) ? trim($meta['about']) : '';

                if ($about !== '') {
                    $bios[$event->pubkey] = $about;
                }
            }

            return $bios;
        });
    }

    /**
     * Each player's newest own Nostr posts (user 2026-10-14: "auch immer die letzten Posts auf Nostr mit einbeziehen"):
     * kind 1 of the last thirty days without replies, at most three per pubkey, newest first, merged over the relays;
     * kept thirty minutes.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, list<string>> posts by pubkey
     */
    public static function posts(Tournament $tournament, array $pubkeys, CarbonImmutable $now): array
    {
        if ($pubkeys === []) {
            return [];
        }

        return Cache::remember('caster:tournament_live:posts:'.$tournament->id.':'.sha1(implode(',', $pubkeys)), 1800, function () use ($pubkeys, $now): array {
            $posts = [];
            $events = app(RelayReader::class)->fetchCounted([['kinds' => [1], 'authors' => array_slice($pubkeys, 0, 200), 'since' => $now->subDays(30)->getTimestamp(), 'limit' => 400]], self::relays(), [], 6, microtime(true) + 15)['events'];

            foreach ($events as $event) {
                $reply = collect($event->tags)->contains(fn (array $tag): bool => ($tag[0] ?? null) === 'e');
                $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('~(https?://|nostr:)\S+~u', '', $event->content)));

                if (! $reply && mb_strlen($text) >= 8 && count($posts[$event->pubkey] ?? []) < 3) {
                    $posts[$event->pubkey][] = mb_substr($text, 0, 200);
                }
            }

            return $posts;
        });
    }

    /**
     * The relays the players' profiles and posts are read from: the knowledge relays, the big public ones and the league's.
     *
     * @return list<string>
     */
    private static function relays(): array
    {
        return array_values(array_unique(array_filter([
            ...(array) config('tv.commentary.chat.knowledge_relays', []),
            ...(array) config('tv.commentary.tournament_live.relays', []),
            ...(array) config('esports.relays', []),
        ], 'is_string')));
    }

    /**
     * @param  list<string>  $said  the last comments, so the next one does not repeat them
     * @return array{text: string, speakers: list<string>}|null
     */
    private function draft(string $material, array $said, bool $intro = false): ?array
    {
        $this->usage->recordAttempt(self::CHANNEL);
        $model = config('tv.commentary.tournament_live.model');
        $response = (new VoiceAgent($this->duets->systemPrompt(gags: false), 0.9, 1500, cacheTtl: CasterVoice::staticCacheTtl(), modelOverride: is_string($model) && $model !== '' ? $model : null))
            ->prompt(self::prompt($material, $said, $intro));
        $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response->toArray();
        $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
        [$duet, $violations] = $this->duets->evaluate($raw, [], false, 8, 3);

        if ($duet === null || $violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations === [] ? ['empty'] : $violations);

            // Its one draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"); only what never goes on air leaves it.
            $draft = $this->duets->lastDraft();
            [$text, $speakers] = $draft === null ? ['', null] : ScriptGuard::airable($draft['text'], $draft['speakers']);

            if ($text === '' || $speakers === null) {
                $this->usage->recordOutcome(self::CHANNEL, 'rejected');

                return null;
            }

            $duet = ['text' => $text, 'speakers' => $speakers];
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return ['text' => $duet['text'], 'speakers' => $duet['speakers']];
    }

    /**
     * @param  list<string>  $said
     */
    public static function prompt(string $material, array $said, bool $intro = false): string
    {
        return implode("\n", [
            $intro
                ? 'Diesmal: stellt die markierten Spieler vor wie die Kommentatoren am Finaltisch eines Pokerturniers. Nur die markierten Spieler, keine anderen nennen. Pro Spieler: Name, was seine Nostr-Bio und seine letzten Nostr-Posts über ihn verraten (zitiert oder sinngemäß, aber nichts dazudichten), und was man daraus spielerisch von ihm erwarten darf (Spielstil, Schwächen, Chancen), frech geroastet.'
                : 'Diesmal: was gerade im Turnier passiert. Neue Ergebnisse zuerst, sonst wer trödelt, wer an vielen Brettern am Zug ist, welche Partie schon weit ist. Nenne keine Uhrzeiten und keine Tageszeiten wie "gerade eben" oder "vor einer Stunde": der Einwurf läuft erst spät, jede genannte Zeit wäre falsch, wenn er on air geht.',
            (int) config('tv.commentary.tournament_live.every_minutes', 8) <= 2
                // A comment every minute (user 2026-10-14: "ich will jede Minute einen"): short, so the voicing keeps up.
                ? 'Live-Kommentar im Radio zu einem laufenden Turnier: ein ganz kurzer Einwurf von Markus und Sirius, 25 bis 40 Sekunden, 2 bis 4 Wortwechsel, jede Zeile beginnt mit "Markus:" oder "Sirius:".'
                : 'Live-Kommentar im Radio zu einem laufenden Turnier: ein kurzer Einwurf von Markus und Sirius, etwa 45 bis 70 Sekunden, 4 bis 7 Wortwechsel, jede Zeile beginnt mit "Markus:" oder "Sirius:".',
            'Ton: wie ein Live-Ticker beim Sport, frech, sarkastisch, toxisch roastend, Schimpfwörter inklusive. Nehmt Spieler und Partien aufs Korn: wer trödelt, wer an vielen Brettern am Zug ist, wer laut seiner Nostr-Bio eigentlich ganz andere Hobbys hat, und wie gut (oder schlecht) seine Chancen stehen. Sprecht Spieler mit ihrem Namen an, als würden sie zuhören.',
            'Regeln: Nur Fakten aus dem Material unten verwenden, nichts erfinden (keine erfundenen Ergebnisse, Züge, Zahlen oder Zitate). Bio-Inhalte nur so verwenden, wie sie dastehen. Keine geschützten Merkmale, kein Selbstetikett. Schimpfwörter, derbe Roasts und Witze unter der Gürtellinie sind ausdrücklich erlaubt. Keine Uhrzeiten nennen außer der im Material.',
            'Rahmen: Der erste Satz macht klar, dass das ein Live-Einwurf aus dem Turnier ist (der Jingle davor ist "Breaking"). Ein roter Faden, eine Pointe, der letzte Satz gibt zurück an die Musik.',
            $said === [] ? '' : "Diese Einwürfe liefen schon, wiederholt keinen Witz und nehmt andere Spieler dran:\n- ".implode("\n- ", $said),
            '',
            'Material (Stand jetzt):',
            $material,
            '',
            'Antworte als JSON mit dem Feld "text" (der Dialog), "canon_id" null und "mood".',
        ]);
    }
}
