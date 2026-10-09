<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\HyperMatchStatus;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Support\Hyper\HyperMap;
use App\Support\Hyper\HyperNames;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * The stream slide of a running Hyperbitcoinization match (h1, plan "Hyperbitcoinization", P6, Ansatz 9): the
 * world map with who owns what, the factions with their banks and territories, a chronicle of the latest battles
 * and how tense it is (who leads, a close duel, a central bank that just fell).
 *
 * For the rotation (RotationPlanner, HYPER): entries() lists the running matches with at least one player (a
 * bots-only table is no show), live ones first, and says per match whether it is tense; the planner holds a
 * tense match longer. Nothing while the game is switched off.
 *
 * Readability (user, 2026-10-08: nothing too fast): the frame renders once a second, but a new chronicle row and a
 * new tension caption come at most once every CAPTION_SECONDS; a bot chain that conquers ten territories in one
 * second fills the chronicle one row at a time, and only a backlog beyond the rows shown is skipped. That pacing
 * is the only state here, per match, dropped with the match. Bound as a singleton: the daemon keeps one.
 *
 * Names as the league's other pages outside the match show them (HyperNames): a player by display name, a bot by
 * its faction; faces as on every other slide (StreamImages, the cached picture or the Blockpile). No profile link,
 * no gamer tag, no address. The texts are English, as on every slide.
 */
class HyperScene
{
    public const SCENE = 'h1';

    /** Rows the chronicle shows. */
    public const CHRONICLE = 4;

    /** A new chronicle row or tension caption stands at least this long before the next one comes. */
    public const CAPTION_SECONDS = 3;

    /** A central bank that fell within this long makes the match tense. */
    public const BANK_TENSION_SECONDS = 90;

    /** In a duel the weaker side holding at least this share of the two sides' territories makes it close. */
    public const CLOSE_SHARE = 0.4;

    /** Actions read back for the chronicle. */
    public const ACTIONS = 60;

    /** A name in a chronicle row or the tension caption is cut to this many characters, so the row keeps its news. */
    public const NAME_CHARS = 22;

    /** Running matches the rotation takes turns with. */
    public const MAX_MATCHES = 6;

    /** A correspondence match is on the stream only while someone moved this recently: a day-long turn is no show. */
    public const CORRESPONDENCE_FRESH_MINUTES = 30;

    /** The factions' colours (resources/js/hyper/data.js FACTIONS). */
    public const COLORS = ['bitcoiner' => '#F7931A', 'fed' => '#43D17A', 'ezb' => '#57A6FF', 'goldbug' => '#A98BFF', 'shitcoiner' => '#FF5FB0', 'nocoiner' => '#D7DEEE'];

    /** @var array<int, array{shown: array<string, true>, at: float, caption: string|null, captionAt: float}> match id => what the slide revealed, and when */
    private array $paced = [];

    /** @var array<string, string|null> resources/stream/hyper file => its data URI, read once */
    private array $files = [];

    /** @var array<string, mixed>|null resources/stream/hyper/map.json */
    private ?array $map = null;

    public function __construct(private StreamImages $images) {}

    /** Whether the game is switched on (the registry, read each time, knows it only then). */
    public function on(): bool
    {
        return (bool) config('esports.hyper.enabled') && app(GameRegistry::class)->find(Hyperbitcoinization::SLUG) !== null;
    }

    /**
     * The running matches the rotation shows, in turn order: live ones oldest first, then correspondence ones
     * moved in within CORRESPONDENCE_FRESH_MINUTES, last moved first; none while the game is off.
     *
     * @return list<array{id: int, tense: bool}>
     */
    public function entries(int $nowMs): array
    {
        if (! $this->on()) {
            return [];
        }

        $matches = HyperMatch::query()->where('status', HyperMatchStatus::Active)
            // A human still at the table, as HyperMatches::capBotsOnly() reads it: a seat that left or was taken over keeps
            // its user_id but plays as a bot (reviewer 2026-10-09).
            ->whereHas('seats', fn ($seats) => $seats->whereNotNull('user_id')->where('bot', false))
            ->where(fn ($query) => $query->where('mode', HyperMatch::LIVE)
                ->orWhere('turn_started_ms', '>=', $nowMs - self::CORRESPONDENCE_FRESH_MINUTES * 60_000))
            ->with('seats')
            ->orderByRaw('case when mode = ? then 0 else 1 end', [HyperMatch::LIVE])
            ->orderByRaw('case when mode = ? then id else 0 end', [HyperMatch::LIVE])
            ->orderByDesc('turn_started_ms')->orderByDesc('id')
            ->limit(self::MAX_MATCHES)->get();
        $ids = $matches->modelKeys();
        // The pacing of matches that ended goes with them.
        $this->paced = array_intersect_key($this->paced, array_flip($ids));

        return array_values($matches->map(fn (HyperMatch $match): array => ['id' => (int) $match->id, 'tense' => $this->tension($match, $nowMs)['tense']])->all());
    }

    /**
     * How tense the match is: a central bank fell within BANK_TENSION_SECONDS, or two sides are left and the weaker
     * holds at least CLOSE_SHARE of their territories. `leader` is the seat ahead (most banks, then territories).
     *
     * @return array{tense: bool, reason: string|null, leader: int|null, duel: array{0: int, 1: int}|null, bank: array{seat: int, territory: string}|null}
     */
    public function tension(HyperMatch $match, int $nowMs): array
    {
        $standing = $this->standing($match);
        $alive = array_values(array_filter($standing, fn (array $s): bool => ! $s['out']));
        usort($alive, fn (array $a, array $b): int => [$b['banks'], $b['territories']] <=> [$a['banks'], $a['territories']]);
        $leader = $alive[0]['seat'] ?? null;

        // Two sides left: two seats, or the two teams of a team match.
        $sides = [];
        foreach ($alive as $s) {
            $key = $match->isTeamMatch() ? 'team'.$s['team'] : 'seat'.$s['seat'];
            $sides[$key] = ($sides[$key] ?? 0) + $s['territories'];
        }

        $duel = null;
        if (count($sides) === 2 && array_sum($sides) > 0 && min($sides) / array_sum($sides) >= self::CLOSE_SHARE) {
            $rival = collect($alive)->first(fn (array $s): bool => $match->isTeamMatch() ? $s['team'] !== $alive[0]['team'] : $s['seat'] !== $alive[0]['seat']);
            $duel = $rival === null ? null : [$alive[0]['seat'], $rival['seat']];
        }

        $bank = null;
        $since = now()->subSeconds(self::BANK_TENSION_SECONDS);
        foreach ($match->actions()->where('created_at', '>=', $since)->reorder('ply', 'desc')->limit(self::ACTIONS)->get() as $action) {
            foreach (array_reverse($action->events) as $event) {
                if (($event['type'] ?? null) === 'bank_fallen') {
                    $bank = ['seat' => (int) $event['seat'], 'territory' => (string) $event['territory']];

                    break 2;
                }
            }
        }

        $reason = match (true) {
            $bank !== null => 'bank',
            $duel !== null => 'duel',
            default => null,
        };

        return ['tense' => $reason !== null, 'reason' => $reason, 'leader' => $leader, 'duel' => $duel, 'bank' => $bank];
    }

    /**
     * The slide's data, as resources/views/stream/rotation/h1-hyper.blade.php describes it; `hyper` null when the
     * match is not there (or the game is off).
     *
     * @param  array<string, mixed>  $stats  StreamStats::all()
     * @return array<string, mixed>
     */
    public function data(?int $matchId, int $nowMs, array $stats): array
    {
        $previousLocale = App::getLocale();
        App::setLocale('en');

        try {
            $match = $matchId === null || ! $this->on() ? null : HyperMatch::query()->with('seats.user')->find($matchId);

            return [
                'hyper' => $match === null ? null : $this->live($match, $nowMs),
                'map' => $this->map(),
                'art' => ['plate' => $this->file('plate.jpg', 'image/jpeg'), 'ribbon' => $this->file('ribbon.png', 'image/png'), 'clash' => $this->file('clash.png', 'image/png')],
                'stats' => $stats,
                'backdrop' => $this->images->backdrop(Hyperbitcoinization::SLUG) ?? $this->images->backdrop(StreamImages::BRAND),
            ];
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function live(HyperMatch $match, int $nowMs): array
    {
        $tension = $this->tension($match, $nowMs);
        $standing = $this->standing($match);
        $names = [];
        $seats = [];

        foreach ($match->seats as $seat) {
            $names[$seat->seat] = HyperNames::seat($seat);
        }

        foreach ($standing as $s) {
            /** @var HyperSeat|null $seat */
            $seat = $match->seats->firstWhere('seat', $s['seat']);
            $seats[] = [
                ...$s,
                'name' => $names[$s['seat']] ?? '',
                'faction' => HyperNames::faction($s['faction']),
                'color' => self::COLORS[$s['faction']] ?? '#A1A1AA',
                'portrait' => $this->file('por-'.$s['faction'].'.jpg', 'image/jpeg'),
                // A player's own face as on every slide; a bot has none (its faction portrait stands for it).
                'avatar' => $seat?->user !== null ? $this->images->forUser($seat->user) : null,
                'toMove' => $match->isActive() && $match->current_seat === $s['seat'],
                'leader' => $tension['leader'] === $s['seat'],
            ];
        }

        $owners = [];
        foreach ((array) ($match->state['territories'] ?? []) as $id => $territory) {
            $owners[(string) $id] = isset($territory['owner']) ? (int) $territory['owner'] : null;
        }

        return [
            'id' => $match->id,
            'over' => ! $match->isActive(),
            'round' => (int) ($match->state['round'] ?? 1),
            'limit' => (int) ($match->state['limit'] ?? 0),
            'mode' => $match->isCorrespondence() ? 'Correspondence' : 'Live',
            'phase' => (string) ($match->state['phase'] ?? ''),
            'seats' => $seats,
            'owners' => $owners,
            'colors' => array_column(array_map(fn (array $s): array => ['seat' => $s['seat'], 'color' => $s['color']], $seats), 'color', 'seat'),
            'chronicle' => $this->chronicle($match, $names, $nowMs),
            'tension' => [...$tension, 'caption' => $this->caption($match, $tension, $names, $nowMs)],
        ];
    }

    /**
     * Per seat: faction, team, territories, banks, units and whether it is out, in seat order.
     *
     * @return list<array{seat: int, faction: string, team: int|null, territories: int, banks: int, units: int, out: bool, bot: bool}>
     */
    private function standing(HyperMatch $match): array
    {
        $state = (array) $match->state;
        $count = [];

        foreach ((array) ($state['territories'] ?? []) as $id => $territory) {
            $owner = $territory['owner'] ?? null;

            if ($owner === null) {
                continue;
            }

            $index = array_search((string) $id, HyperMap::IDS, true);
            $count[(int) $owner]['territories'] = ($count[(int) $owner]['territories'] ?? 0) + 1;
            $count[(int) $owner]['banks'] = ($count[(int) $owner]['banks'] ?? 0) + ($index !== false && HyperMap::BANK[$index] ? 1 : 0);
            $count[(int) $owner]['units'] = ($count[(int) $owner]['units'] ?? 0) + (int) ($territory['pleb'] ?? 0) + (int) ($territory['maxi'] ?? 0) + (int) ($territory['asic'] ?? 0);
        }

        $rows = [];
        foreach ((array) ($state['seats'] ?? []) as $index => $seat) {
            $rows[] = [
                'seat' => (int) $index,
                'faction' => (string) ($seat['faction'] ?? ''),
                'team' => isset($seat['team']) ? (int) $seat['team'] : null,
                'territories' => $count[(int) $index]['territories'] ?? 0,
                'banks' => $count[(int) $index]['banks'] ?? 0,
                'units' => $count[(int) $index]['units'] ?? 0,
                'out' => (bool) ($seat['out'] ?? false),
                'bot' => (bool) ($seat['bot'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * The latest battles, newest first, at most CHRONICLE rows, revealed one at a time (CAPTION_SECONDS apart).
     *
     * @param  array<int, string>  $names
     * @return list<array{key: string, seat: int, text: string, kind: string}>
     */
    private function chronicle(HyperMatch $match, array $names, int $nowMs): array
    {
        $entries = [];
        $actions = $match->actions()->reorder('ply', 'desc')->limit(self::ACTIONS)->get()->reverse();

        foreach ($actions as $action) {
            foreach ($this->entriesOf($action, $names) as $entry) {
                $entries[] = $entry;
            }
        }

        // Newest first.
        $entries = array_reverse($entries);
        $now = $nowMs / 1000;
        $paced = $this->paced[$match->id] ?? null;

        if ($paced === null) {
            // The slide just came up: what happened so far stands at once.
            $paced = ['shown' => array_fill_keys(array_column($entries, 'key'), true), 'at' => $now, 'caption' => null, 'captionAt' => -INF];
        } else {
            $pending = array_values(array_filter($entries, fn (array $e): bool => ! isset($paced['shown'][$e['key']])));

            // A backlog beyond the rows on show would never be read: all but the newest rows go at once.
            foreach (array_slice($pending, self::CHRONICLE) as $skipped) {
                $paced['shown'][$skipped['key']] = true;
            }

            $pending = array_slice($pending, 0, self::CHRONICLE);

            if ($pending !== [] && $now - $paced['at'] >= self::CAPTION_SECONDS) {
                $paced['shown'][end($pending)['key']] = true;
                $paced['at'] = $now;
            }
        }

        // Keep the memory to what can still show.
        $paced['shown'] = array_intersect_key($paced['shown'], array_flip(array_column($entries, 'key')));
        $this->paced[$match->id] = $paced;

        return array_slice(array_values(array_filter($entries, fn (array $e): bool => isset($paced['shown'][$e['key']]))), 0, self::CHRONICLE);
    }

    /**
     * The chronicle rows of one action: conquests (with a toppled bank or a completed currency space in the same
     * row), knock-outs and the win.
     *
     * @param  array<int, string>  $names
     * @return list<array{key: string, seat: int, text: string, kind: string}>
     */
    private function entriesOf(HyperAction $action, array $names): array
    {
        $rows = [];
        $name = fn (?int $seat): string => $seat === null ? 'the neutrals' : self::short($names[$seat] ?? 'Someone');
        $territory = fn (string $id): string => (string) ($this->map()['territories'][$id]['name'] ?? $id);

        foreach ($action->events as $index => $event) {
            $key = $action->ply.':'.$index;
            $seat = isset($event['seat']) ? (int) $event['seat'] : null;

            switch ($event['type'] ?? null) {
                case 'territory_conquered':
                    $rows[] = ['key' => $key, 'seat' => (int) $seat, 'kind' => 'conquest',
                        'text' => $name($seat).' took '.$territory((string) $event['territory']).' from '.$name(isset($event['previous_owner']) ? (int) $event['previous_owner'] : null)];
                    break;
                case 'bank_fallen':
                    $zone = array_search((string) $event['zone'], HyperMap::ZONE_KEYS, true);
                    $last = array_key_last($rows);
                    $bank = $zone === false ? 'a central bank' : 'the '.str_replace('EZB', 'ECB', HyperMap::ZONE_BANKS[$zone]);
                    // The conquest that toppled it is the row before: one row, "… and toppled the Fed".
                    if ($last !== null && $rows[$last]['kind'] === 'conquest' && $rows[$last]['seat'] === $seat) {
                        $rows[$last]['text'] .= ', toppled '.$bank;
                        $rows[$last]['kind'] = 'bank';
                    } else {
                        $rows[] = ['key' => $key, 'seat' => (int) $seat, 'kind' => 'bank', 'text' => $name($seat).' toppled '.$bank];
                    }
                    break;
                case 'zone_completed':
                    $zone = (string) ($this->map()['zones'][(string) $event['zone']]['name'] ?? $event['zone']);
                    $rows[] = ['key' => $key, 'seat' => (int) $seat, 'kind' => 'zone', 'text' => $name($seat).' holds the whole '.$zone];
                    break;
                case 'player_eliminated':
                    $rows[] = ['key' => $key, 'seat' => (int) ($event['by'] ?? 0), 'kind' => 'out', 'text' => $name($seat).' is out, knocked out by '.$name(isset($event['by']) ? (int) $event['by'] : null)];
                    break;
                case 'game_won':
                    $rows[] = ['key' => $key, 'seat' => (int) $seat, 'kind' => 'win', 'text' => $name($seat).' wins the match'];
                    break;
            }
        }

        return $rows;
    }

    /**
     * The tension line under the leader, held for CAPTION_SECONDS before it may change.
     *
     * @param  array{tense: bool, reason: string|null, leader: int|null, duel: array{0: int, 1: int}|null, bank: array{seat: int, territory: string}|null}  $tension
     * @param  array<int, string>  $names
     */
    private function caption(HyperMatch $match, array $tension, array $names, int $nowMs): string
    {
        $text = match ($tension['reason']) {
            'bank' => 'Central bank down: '.self::short($names[$tension['bank']['seat'] ?? -1] ?? 'Someone').' took '.($this->map()['territories'][$tension['bank']['territory'] ?? '']['name'] ?? 'it'),
            'duel' => 'Close duel: '.self::short($names[$tension['duel'][0] ?? -1] ?? '').' vs '.self::short($names[$tension['duel'][1] ?? -1] ?? ''),
            default => $tension['leader'] === null ? 'The match is on' : 'In the lead: '.self::short($names[$tension['leader']] ?? ''),
        };
        $now = $nowMs / 1000;
        $paced = $this->paced[$match->id] ?? ['shown' => [], 'at' => $now, 'caption' => null, 'captionAt' => -INF];

        if ($paced['caption'] === null || ($text !== $paced['caption'] && $now - $paced['captionAt'] >= self::CAPTION_SECONDS)) {
            $paced['caption'] = $text;
            $paced['captionAt'] = $now;
            $this->paced[$match->id] = $paced;
        }

        return (string) $paced['caption'];
    }

    /** A name cut to NAME_CHARS characters, with "…" when it was longer. */
    private static function short(string $name): string
    {
        return mb_strlen($name) > self::NAME_CHARS ? rtrim(mb_substr($name, 0, self::NAME_CHARS - 1)).'…' : $name;
    }

    /**
     * The thinned world map (tools/hyper-art/stream-map.mjs), read once.
     *
     * @return array<string, mixed>
     */
    public function map(): array
    {
        if ($this->map === null) {
            try {
                $this->map = (array) json_decode((string) file_get_contents(resource_path('stream/hyper/map.json')), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                // Without the map the slide shows the factions and the chronicle; the map area stays dark.
                $this->map = ['width' => 832, 'height' => 368, 'zones' => [], 'neutral' => '', 'territories' => []];
            }
        }

        return $this->map;
    }

    /** A picture of resources/stream/hyper as a data URI, read once; null when it is missing. */
    private function file(string $name, string $mime): ?string
    {
        if (! array_key_exists($name, $this->files)) {
            $path = resource_path('stream/hyper/'.$name);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            $this->files[$name] = $bytes === false || $bytes === '' ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        return $this->files[$name];
    }
}
