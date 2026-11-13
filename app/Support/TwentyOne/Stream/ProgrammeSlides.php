<?php

namespace App\Support\TwentyOne\Stream;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\StockFeeder;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\PodcastNight;
use App\Support\Caster\PodcastReplay;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\ProgrammePreview;
use App\Support\Caster\Programme\Roasts;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The radio programme on the stream (plan "TWENTY ONE Radio", P19; user 2026-10-13: "neue Slides für den Livestream,
 * welche das Programm der Radio-Show zeigen … Das, was wirklich vorbereitet wurde und fertig als Audio auch laufen
 * wird"). Three slides: the programme of the next hours with the station's hour clock (r1), the next show in the
 * spotlight with its countdown (r2) and the talk show's duel card while it is ready (r3).
 *
 * Only what is real (programme()):
 *
 * - on air: the clip the spool marks as playing (a podcast chunk with its part, a rubric, a bulletin, a stock block;
 *   a slide commentary or a chat reply is "Markus und Sirius am Mikro"); nothing playing is music;
 * - coming: the anchored clips READY in the spool (bulletins, rubrics and long formats, Sport und Wetter, the news
 *   talk, the podcast airings) at their slot, a later part of a rubric counted into its first;
 * - the daytime podcast replay of a source whose episode is stored in the library (the audio is there) and has not
 *   aired today, at its slot;
 * - the fixed line: the next bulletin slot of the broadcast clock, ready or not (the news at :00 and :30 always run),
 *   and the sentence about the news and Sport und Wetter; only while the workstation can voice them (voiceOnline():
 *   prod 2026-10-18, VoiceStudio down from 11:27 to 15:00, every bulletin from 12:30 on expired unvoiced while the
 *   slide promised "15:00 Nachrichten");
 * - at night (PodcastNight's window): the loop's stored episodes still to come tonight, in order;
 * - between the slots: the unanchored ready material (talk and stock blocks, news and history segments) as counts.
 *
 * A rubric slot that is only planned, a show whose script is not voiced yet: not shown, never as "in Vorbereitung".
 *
 * For the rotation (RotationPlanner, PROGRAMME): entries() lists the slides that have something to show and the key
 * of a show that starts within SOON_SECONDS (its spotlight comes next, once). Read from the database at most every
 * MEMO_SECONDS per instance (the daemon polls every second).
 *
 * Copy is German (the radio is German), roast tone, every tagline true to what its format does (Rubrics::all()).
 *
 * @phpstan-type Item array{key: string, family: string, title: string, tagline: string, at: int|null, seconds: float, ready: bool, format: string|null, part: string|null, left: int|null}
 * @phpstan-type Stock array{family: string, label: string, seconds: float}
 * @phpstan-type Programme array{onAir: Item|null, upcoming: list<Item>, loop: list<Item>, stock: list<Stock>, newsLine: string|null, night: bool, now: int}
 */
class ProgrammeSlides
{
    /** The programme of the next hours, with the hour clock. */
    public const RUNDOWN = 'r1';

    /** The next show in the spotlight, with its countdown. */
    public const SPOTLIGHT = 'r2';

    /** The talk show's duel card (Markus against Genossin Gisela, Sirius hosts) while it is ready. */
    public const DUEL = 'r3';

    public const SCENES = [self::RUNDOWN, self::SPOTLIGHT, self::DUEL];

    /** Scene id => view (resources/views/stream/rotation). */
    public const VIEWS = [self::RUNDOWN => 'stream.rotation.r1-programme', self::SPOTLIGHT => 'stream.rotation.r2-up-next', self::DUEL => 'stream.rotation.r3-duel'];

    /** The talk show's id (Shows, plan P16: "Turm und Genossin, der Talk", internal id "stammtisch"). */
    public const TALKSHOW = Shows::STAMMTISCH;

    /** A show that starts within this many seconds gets its spotlight next (RotationPlanner). */
    public const SOON_SECONDS = 120;

    /** How often an instance reads the spool at most. */
    public const MEMO_SECONDS = 10;

    public const NEWS = 'news';

    public const TALK = 'talk';

    public const ROAST = 'roast';

    public const SHOW = 'show';

    public const PODCAST = 'podcast';

    public const MUSIC = 'music';

    /**
     * The colour of each format family on the ground (#0A0A0B): news stays white (fact, no heat), the voices of the
     * house orange, the roasts red, the shows teal, the podcast violet; music grey. Colour is never alone: every
     * family has its own glyph (FAMILY_GLYPHS) and the title says what it is.
     */
    public const COLOURS = [self::NEWS => '#FFFFFF', self::TALK => '#F7931A', self::ROAST => '#F87171', self::SHOW => '#5EEAD4', self::PODCAST => '#A78BFA', self::MUSIC => '#ADADB0'];

    /** The family as a word (the spotlight names it next to the glyph). */
    public const FAMILY_NAMES = [self::NEWS => 'Nachrichten', self::TALK => 'Talk', self::ROAST => 'Roast', self::SHOW => 'Show', self::PODCAST => 'Podcast', self::MUSIC => 'Musik'];

    /** The formats by how the slides name them (Rubrics::all() names them with an article, as said on air). */
    private const TITLES = [
        Shows::TRIBUNAL => 'Chat-Tribunal', Shows::FRIEDHOF => 'Shitcoin-Friedhof', Shows::JAHRESTAG => 'Bitcoin-Zeitreise', Shows::MEETUPS => 'Meetup-Radar',
        Shows::PARTIE => 'Partie des Tages', Shows::HOTLINE => 'Satoshis Hotline', self::TALKSHOW => 'Turm und Genossin',
        Roasts::ZENTRALBANK => 'Zentralbank-Kläranlage', Roasts::SCHWARZBUCH => 'Schwarzbuch-Stunde', Roasts::ALTCOIN => 'Altcoin-Hype-Detektor', Roasts::LIGA_PRANGER => 'Liga-Pranger',
        Roasts::PHRASEN => 'Phrasendrescher der Woche', Roasts::NOSTR => 'Nostr-Fundbüro', Roasts::STACKER => 'Corporate-Stacker-Watch', Roasts::INFLATION => 'Inflationsbeichte',
        Rubrics::LIGA => 'Liga-Rubrik', Rubrics::PRESS => 'Presseschau', Rubrics::PODCAST => 'Podcast-Ecke', Rubrics::HISTORY => 'Geschichtsstunde', Rubrics::CHAT => 'Chat-Stunde',
        SportWeather::TOPIC => 'Sport und Wetter', NewsTalk::TOPIC => 'Nachrichten mit Meinung', ProgrammePreview::TOPIC => 'Programmvorschau',
        StockFeeder::HOUR_TOPIC => 'Stationsansage',
    ];

    /**
     * One line per format, true to what it brings (Rubrics::all() `what`), in the station's roast tone. The first
     * sentence stands alone (at most 42 characters: the programme rows show only it, short()), the rest is the punch
     * the on-air row and the spotlight add.
     */
    private const TAGLINES = [
        Shows::TRIBUNAL => 'Der Chat vor Gericht. Beweismittel: eure eigenen Nachrichten.',
        Shows::FRIEDHOF => 'Grabrede auf einen toten Shitcoin. Mit Fakten, ohne Trauer.',
        Shows::JAHRESTAG => 'Ein Kalendertag in der Bitcoin-Geschichte.',
        Shows::MEETUPS => 'Bitcoiner in echt treffen, diese Woche. Alle EINUNDZWANZIG-Meetups der nächsten sieben Tage.',
        Shows::PARTIE => 'Das Liga-Drama des Tages. Neu kommentiert, mit allen Fehlern.',
        Shows::HOTLINE => 'Satoshi geht nicht ran, wir schon. Eure offenen Fragen aus dem Chat, nachgeschlagen.',
        self::TALKSHOW => 'Markus gegen Genossin Gisela. Sirius moderiert, es geht ums Geld.',
        Roasts::ZENTRALBANK => 'EZB und Fed im Wortlaut. Die neueste Mitteilung, zitiert und zerlegt.',
        Roasts::SCHWARZBUCH => 'Steuergeld, verbrannt. Ein echter Fall aus dem Schwarzbuch, leider kein Einzelfall.',
        Roasts::ALTCOIN => 'Altcoin-Hype, gnadenlos geprüft. Die heißesten Schlagzeilen der Kryptopresse.',
        Roasts::LIGA_PRANGER => 'Die bittersten Niederlagen der Woche. Mitgefühl ausverkauft.',
        Roasts::PHRASEN => 'Leere Politikersätze, wörtlich. Die Phrasen der Woche aus den Schlagzeilen.',
        Roasts::NOSTR => 'Die schrägsten Posts auf Nostr. Öffentlich, von den EINUNDZWANZIG-Relays.',
        Roasts::STACKER => 'Wer stackt, wer verkauft, wer absäuft. Firmen mit Bitcoin in der Bilanz.',
        Roasts::INFLATION => 'Was dein Euro früher konnte. Die neueste Inflationsrate, mit echten Preisen.',
        Rubrics::LIGA => 'Ergebnisse und Tabellen der Liga. Alles aus den letzten 24 Stunden.',
        Rubrics::PRESS => 'Drei Schlagzeilen, drei Blätter. Bissig verglichen.',
        Rubrics::PODCAST => 'Das Beste der neuesten Folge. Aus dem EINUNDZWANZIG Podcast.',
        Rubrics::HISTORY => 'Ein Kapitel Bauernkrieg. Abgaben waren schon damals das Problem.',
        Rubrics::CHAT => 'Eure Fragen, beantwortet. Die besten aus dem Chat der letzten Stunden.',
        SportWeather::TOPIC => 'Ergebnisse und ob du nass wirst. Sport und Wetter nach den Nachrichten.',
        NewsTalk::TOPIC => 'Die Meldungen, jetzt mit Meinung.',
        ProgrammePreview::TOPIC => 'Was heute noch läuft, vorgelesen.',
        StockFeeder::HOUR_TOPIC => 'Zur vollen Stunde. Danach Musik und Wiederholungen.',
    ];

    /** The stock formats (StockScripts::FORMATS) as the slides count them. */
    private const STOCK_LABELS = ['talk' => 'Talk', 'tutorial' => 'Tutorial', 'geschichte' => 'Geschichte', 'podcast' => 'Podcast-Talk', 'liga' => 'Liga-Talk'];

    /** @var array{at: int, programme: array<string, mixed>}|null */
    private ?array $memo = null;

    /** @var array<string, array<string, mixed>|null> source => stored episode, for the memo's lifetime */
    private array $episodes = [];

    public function __construct(private readonly PodcastLibrary $library) {}

    /** Whether the slides may show: the commentary runs (the radio is on air). */
    public static function enabled(): bool
    {
        return config('tv.commentary.enabled') === true && config('twentyone.stream.rotation.programme_slides', true) !== false;
    }

    /**
     * The slides that have something to show now, and the key of a show (no bulletin) that starts within
     * SOON_SECONDS; nothing while the radio is off.
     *
     * @return array{scenes: list<string>, soon: string|null, soonScene: string|null} `soonScene` the slide for it: the duel card for the talk show, else the spotlight
     */
    public function entries(CarbonImmutable $now): array
    {
        if (! self::enabled()) {
            return ['scenes' => [], 'soon' => null, 'soonScene' => null];
        }

        $p = $this->programme($now);
        $scenes = [];

        if ($p['onAir'] !== null || $p['upcoming'] !== [] || $p['loop'] !== []) {
            $scenes[] = self::RUNDOWN;
        }

        if (self::spotlight($p) !== null) {
            $scenes[] = self::SPOTLIGHT;
        }

        if (self::duel($p) !== null) {
            $scenes[] = self::DUEL;
        }

        $soon = null;
        $soonScene = null;
        foreach ($p['upcoming'] as $item) {
            $in = ($item['at'] ?? 0) - $now->getTimestamp();

            if ($item['family'] !== self::NEWS && $item['format'] !== StockFeeder::HOUR_TOPIC && $item['ready'] && $in > 0 && $in <= self::SOON_SECONDS) {
                $soon = $item['key'];
                $soonScene = $item['format'] === self::TALKSHOW && in_array(self::DUEL, $scenes, true) ? self::DUEL : self::SPOTLIGHT;
                break;
            }
        }

        return ['scenes' => $scenes, 'soon' => $soon, 'soonScene' => $soonScene];
    }

    /**
     * A slide's data, as its view's docblock describes it.
     *
     * @return array{programme: Programme}
     */
    public function scene(CarbonImmutable $now): array
    {
        /** @var Programme $p */
        $p = $this->programme($now);

        return ['programme' => $p];
    }

    /**
     * The programme as it is in the spool now (see the class comment).
     *
     * @return Programme
     */
    public function programme(CarbonImmutable $now): array
    {
        $bucket = intdiv($now->getTimestamp(), self::MEMO_SECONDS);

        if ($this->memo !== null && $this->memo['at'] === $bucket) {
            /** @var Programme */
            return $this->memo['programme'];
        }

        $this->episodes = [];
        $programme = $this->read($now);
        $this->memo = ['at' => $bucket, 'programme' => $programme];

        return $programme;
    }

    /**
     * @return Programme
     */
    private function read(CarbonImmutable $now): array
    {
        $ts = $now->getTimestamp();
        $hours = max(1, (int) config('twentyone.stream.rotation.programme_hours', 12));
        $until = $ts + 3600 * $hours;
        $clips = CasterClip::query()->whereIn('status', [CasterClip::READY, CasterClip::PLAYING])->where('expires_at', '>', $now->utc())
            ->with('script')->orderBy('id')->get();

        /** @var array<string, Item> $anchored */
        $anchored = [];
        /** @var array<string, Stock> $stock */
        $stock = [];
        $onAir = null;
        $playing = null;

        foreach ($clips as $clip) {
            $script = $clip->script;

            if ($script === null) {
                continue;
            }

            if ($clip->status === CasterClip::PLAYING && ($playing === null || $clip->updated_at?->getTimestamp() >= $playing->updated_at?->getTimestamp())) {
                $playing = $clip;
            }

            if ($clip->status === CasterClip::READY && self::stranded($script, $clips, $now)) {
                continue;
            }

            $left = $clip->status === CasterClip::PLAYING
                ? max(0.0, $clip->duration_seconds - max(0, $ts - (int) $clip->updated_at?->getTimestamp()))
                : $clip->duration_seconds;

            $item = self::itemFor($clip, $script);

            if ($item !== null) {
                $key = $item['key'];

                if (isset($anchored[$key])) {
                    $anchored[$key]['seconds'] += $left;
                } else {
                    $anchored[$key] = [...$item, 'seconds' => $left];
                }

                continue;
            }

            $label = self::stockLabel($clip, $script);

            if ($label !== null && $clip->status === CasterClip::READY) {
                $stock[$label[1]] ??= ['family' => $label[0], 'label' => $label[1], 'seconds' => 0.0];
                $stock[$label[1]]['seconds'] += $clip->duration_seconds;
            }
        }

        if ($playing !== null && $playing->script !== null) {
            $item = self::itemFor($playing, $playing->script);
            $onAir = $item !== null ? $anchored[$item['key']] : self::moderation($playing, $playing->script);
            $onAir['left'] = (int) round($onAir['seconds']);

            if ($item !== null && $playing->kind === CasterScript::PODCAST) {
                $chunk = $playing->podcastChunk();
                $onAir['part'] = $chunk === null ? null : 'Teil '.$chunk['index'].' von '.$chunk['count'];
            }

            unset($anchored[$onAir['key']]);
        }

        $upcoming = array_values(array_filter($anchored, fn (array $item): bool => $item['at'] !== null && $item['at'] <= $until));
        $upcoming = [...$upcoming, ...$this->storedReplays($now, $until, $upcoming, $onAir)];

        $clock = BroadcastClock::fromConfig();
        $news = config('tv.commentary.news.enabled') === true && $clock->enabled() && self::voiceOnline($now);
        $bulletin = $news ? $clock->nextBulletin($now) : null;

        // The fixed line: the next bulletin slot always runs, ready or not.
        if ($bulletin !== null && $bulletin['key'] > $ts) {
            $taken = array_filter([...$upcoming, ...($onAir === null ? [] : [$onAir])], fn (array $item): bool => $item['family'] === self::NEWS && abs(($item['at'] ?? 0) - $bulletin['key']) < 120);

            if ($taken === []) {
                $upcoming[] = self::bulletinItem('fixed:'.$bulletin['key'], $bulletin['type'], $bulletin['key'], 0.0, false);
            }
        }

        usort($upcoming, fn (array $a, array $b): int => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);

        $night = PodcastNight::window($now) !== null && array_filter(array_keys(PodcastLibrary::sources()), fn (string $key): bool => PodcastNight::episodeCount($key, $now) > 0) !== [];

        return [
            'onAir' => $onAir,
            'upcoming' => $upcoming,
            'loop' => $night ? $this->loop($now, $onAir) : [],
            'stock' => array_values($stock),
            'newsLine' => $news ? self::newsLine($clock, $now) : null,
            'night' => $night || QuietHours::active($now),
            'now' => $ts,
        ];
    }

    /**
     * A later part of a show with no part before it left to lead to it (none ready or playing, none still to be voiced):
     * the spool plays a later part only right after the one before (ClipSpool::continuations()), so it never airs. Prod
     * 2026-11-13: part 41 of a talk of 2026-10-25 stood on the slide as "Turm und Genossin, gleich" for 19 days.
     *
     * @param  Collection<int, CasterClip>  $clips  the ready and playing clips, their scripts loaded
     */
    private static function stranded(CasterScript $script, Collection $clips, CarbonImmutable $now): bool
    {
        if ($script->part_group === null || (int) $script->part_index <= 1) {
            return false;
        }

        $before = fn (?CasterScript $other): bool => $other !== null && $other->part_group === $script->part_group && (int) $other->part_index < (int) $script->part_index;

        return ! $clips->contains(fn (CasterClip $clip): bool => $before($clip->script))
            && ! CasterScript::query()->where('part_group', $script->part_group)->where('part_index', '<', (int) $script->part_index)
                ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->where('expires_at', '>', $now->utc())->exists();
    }

    /**
     * Whether the hosts can speak: the workstation claims and its VoiceStudio answers (else the autopilot runs). Also the
     * on-air light of the now-playing column (TwentyOneStreamCommand).
     */
    public static function voiceOnline(CarbonImmutable $now): bool
    {
        return app(ClipSpool::class)->fetcherOnline($now) && ! AutoPilot::voiceDown($now);
    }

    /**
     * The anchored item a clip belongs to (a later part or a chunk adds its seconds to the same key), or null for a
     * clip that has no slot.
     *
     * @return Item|null
     */
    private static function itemFor(CasterClip $clip, CasterScript $script): ?array
    {
        $at = $script->airs_at?->getTimestamp();

        return match ($clip->kind) {
            CasterScript::PODCAST => self::podcastItem('podcast:'.$script->id, $script, $at),
            CasterScript::BULLETIN => $at === null ? null : self::bulletinItem('bulletin:'.$script->id, BroadcastClock::fromConfig()->slotAt(CarbonImmutable::createFromTimestamp($at))['type'], $at, $clip->duration_seconds, true),
            CasterScript::RUBRIC => $at === null ? null : self::formatItem('rubric:'.($script->part_group ?? $script->id), (string) $script->topic, $at),
            default => null,
        };
    }

    /**
     * @return Item
     */
    private static function bulletinItem(string $key, string $type, int $at, float $seconds, bool $ready): array
    {
        $half = $type === BroadcastClock::HEADLINES;

        return ['key' => $key, 'family' => self::NEWS, 'title' => $half ? 'Kurznachrichten' : 'Nachrichten',
            'tagline' => $half ? 'Die Kurzfassung zur halben Stunde.' : 'Fakten zuerst. Die Meinung kommt danach.',
            'at' => $at, 'seconds' => $seconds, 'ready' => $ready, 'format' => null, 'part' => null, 'left' => null];
    }

    /**
     * A rubric or long format by its topic.
     *
     * @return Item
     */
    private static function formatItem(string $key, string $topic, ?int $at): array
    {
        $what = Rubrics::all()[$topic]['what'] ?? null;
        $title = self::TITLES[$topic] ?? (isset(Rubrics::all()[$topic]) ? self::withoutArticle(Rubrics::all()[$topic]['name']) : 'Sendung');

        return ['key' => $key, 'family' => self::familyOf($topic), 'title' => $title,
            'tagline' => self::TAGLINES[$topic] ?? (is_string($what) ? self::sentence($what) : 'Markus und Sirius am Mikro.'),
            // A show that owns a window (P20, Shows::slotMinutes()) shows the window until its clip tells the real length.
            'at' => $at, 'seconds' => 60.0 * (Shows::slotMinutes($topic) ?? 0), 'ready' => true, 'format' => $topic, 'part' => null, 'left' => null];
    }

    /**
     * A podcast airing: its source's name and the episode title from the stand-in script ("Name: Title").
     *
     * @return Item
     */
    private static function podcastItem(string $key, CasterScript $script, ?int $at): array
    {
        [$name, $title] = array_pad(explode(': ', $script->text, 2), 2, '');
        $night = str_starts_with((string) $script->topic, 'n-');

        return ['key' => $key, 'family' => self::PODCAST, 'title' => trim($title) !== '' ? trim($title) : trim($name),
            'tagline' => ($night ? 'In der Nachtschleife. ' : 'Die ganze Folge, ungekürzt. ').trim($name).'.',
            'at' => $at, 'seconds' => 0.0, 'ready' => true, 'format' => 'podcast', 'part' => null, 'left' => null];
    }

    /**
     * What plays when no slot does: a stock block by its format, else the hosts on the microphone.
     *
     * @return Item
     */
    private static function moderation(CasterClip $clip, CasterScript $script): array
    {
        $label = self::stockLabel($clip, $script);
        $isStock = $label !== null && $clip->kind === CasterClip::BLOCK;

        return ['key' => 'air:'.$clip->id, 'family' => $isStock ? $label[0] : self::TALK, 'title' => $isStock ? $label[1] : 'Markus und Sirius am Mikro',
            'tagline' => $isStock ? 'Markus und Sirius am Mikro.' : 'Live zu dem, was gerade in der Liga passiert.',
            'at' => null, 'seconds' => max(0.0, $clip->duration_seconds - max(0, now()->getTimestamp() - (int) $clip->updated_at?->getTimestamp())),
            'ready' => true, 'format' => null, 'part' => null, 'left' => null];
    }

    /**
     * The family and the label of unanchored ready material (talk or stock block, news, history or Nostr segment), or
     * null for a clip that is no programme (a slide commentary, a reply, a filler, a teaser).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function stockLabel(CasterClip $clip, CasterScript $script): ?array
    {
        if ($clip->kind === CasterClip::BLOCK) {
            if ($clip->isReplay()) {
                return [self::TALK, 'Wiederholung'];
            }

            $topic = (string) $script->topic;

            if (str_starts_with($topic, StockScripts::TOPIC_PREFIX)) {
                $format = explode(':', substr($topic, strlen(StockScripts::TOPIC_PREFIX)))[0];

                if (isset(self::TITLES[$format])) {
                    return [self::familyOf($format), self::TITLES[$format]];
                }

                return [$format === 'podcast' ? self::PODCAST : self::TALK, self::STOCK_LABELS[$format] ?? 'Talk'];
            }

            return [self::TALK, 'Talk'];
        }

        return match ($clip->kind) {
            CasterScript::NEWS => [self::NEWS, 'Hintergrund'],
            CasterScript::HISTORY => [self::SHOW, 'Geschichtsstunde'],
            CasterScript::FOLLOWED => [self::TALK, 'Aus dem Nostr-Feed'],
            default => null,
        };
    }

    /** The family of a format id. */
    public static function familyOf(string $topic): string
    {
        return match (true) {
            $topic === self::TALKSHOW, Shows::isShow($topic), $topic === Rubrics::HISTORY => self::SHOW,
            Roasts::owns($topic) => self::ROAST,
            in_array($topic, [Rubrics::LIGA, SportWeather::TOPIC], true) => self::NEWS,
            $topic === Rubrics::PODCAST => self::PODCAST,
            default => self::TALK,
        };
    }

    /**
     * The daytime podcast replay of each source whose episode is stored (its audio is in the library) and has not
     * aired today, at its slot within the window; none that the spool holds already.
     *
     * @param  list<Item>  $upcoming
     * @param  Item|null  $onAir
     * @return list<Item>
     */
    private function storedReplays(CarbonImmutable $now, int $until, array $upcoming, ?array $onAir): array
    {
        $items = [];
        $podcastAts = array_map(fn (array $item): int => (int) $item['at'], array_filter([...$upcoming, ...($onAir === null ? [] : [$onAir])], fn (array $item): bool => $item['family'] === self::PODCAST));

        foreach (PodcastLibrary::sources() as $key => $source) {
            foreach ([PodcastReplay::slotStart($source['starts_at'], $now), PodcastReplay::slotStart($source['starts_at'], $now->addDay())] as $start) {
                $at = $start->getTimestamp();

                if ($at <= $now->getTimestamp() || $at > $until || in_array($at, $podcastAts, true) || ! PodcastLibrary::airsAt($start) || PodcastReplay::airedToday($key, $start)) {
                    continue;
                }

                $episode = $this->episode($key);

                if ($episode === null) {
                    continue;
                }

                $items[] = ['key' => 'replay:'.$key.':'.$at, 'family' => self::PODCAST, 'title' => RotationKit::clean((string) $episode['title']),
                    'tagline' => 'Die ganze Folge, ungekürzt. '.$source['name'].'.', 'at' => $at, 'seconds' => (float) $episode['seconds'], 'ready' => true,
                    'format' => 'podcast', 'part' => null, 'left' => null];
            }
        }

        return $items;
    }

    /**
     * The night loop's stored episodes still to come tonight, newest first, as PodcastNight picks them: not aired
     * tonight, not the one on air or queued.
     *
     * @param  Item|null  $onAir
     * @return list<Item>
     */
    private function loop(CarbonImmutable $now, ?array $onAir): array
    {
        $window = PodcastNight::window($now);

        if ($window === null) {
            return [];
        }

        $items = [];

        foreach (PodcastLibrary::sources() as $key => $source) {
            $count = PodcastNight::episodeCount($key, $now);

            if ($count === 0) {
                continue;
            }

            $tonight = CasterScript::query()->where('kind', CasterScript::PODCAST)->where('topic', PodcastNight::topic($key))
                ->where('airs_at', '>=', $window['from']->utc())->pluck('facts_hash')->all();

            foreach (array_slice($this->library->episodes($key), 0, $count) as $episode) {
                if (in_array(sha1('night:'.$episode['guid']), $tonight, true)) {
                    continue;
                }

                $items[] = ['key' => 'loop:'.$key.':'.sha1($episode['guid']), 'family' => self::PODCAST, 'title' => RotationKit::clean($episode['title']),
                    'tagline' => 'In der Nachtschleife. '.$source['name'].'.', 'at' => null, 'seconds' => (float) $episode['seconds'], 'ready' => true,
                    'format' => 'podcast', 'part' => null, 'left' => null];
            }
        }

        // The one on air already is not "to come".
        return array_values(array_filter($items, fn (array $item): bool => $onAir === null || $item['title'] !== $onAir['title']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function episode(string $key): ?array
    {
        if (! array_key_exists($key, $this->episodes)) {
            $this->episodes[$key] = $this->library->episode($key);
        }

        return $this->episodes[$key];
    }

    /** The sentence about the news that always runs, by the clock's slots and the quiet hours. */
    private static function newsLine(BroadcastClock $clock, CarbonImmutable $now): string
    {
        $quiet = QuietHours::active($now);
        $half = $clock->minutesOf(BroadcastClock::HEADLINES) !== [] && ! $quiet;
        // In the quiet hours Sport und Wetter runs by its `quiet` mode: the weather alone, both, or nothing.
        $mode = (string) config('tv.commentary.programme.sport_weather.quiet', 'weather');
        $after = match (true) {
            ! SportWeather::enabled() => '',
            ! $quiet || $mode === 'full' => ', danach Sport und Wetter',
            $mode === 'weather' => ', danach das Wetter',
            default => '',
        };

        return 'Zur vollen Stunde Nachrichten'.$after.'.'.($half ? ' Zur halben Stunde die Kurzfassung.' : '');
    }

    /**
     * The show the spotlight puts first: the next one that is not a bulletin, else the next bulletin, else (at night)
     * the loop's next episode, after the one on air.
     *
     * @param  Programme  $p
     * @return Item|null
     */
    public static function spotlight(array $p): ?array
    {
        foreach ($p['upcoming'] as $item) {
            if ($item['family'] !== self::NEWS) {
                return $item;
            }
        }

        if (($p['loop'][0] ?? null) !== null) {
            return [...$p['loop'][0], 'at' => $p['now'] + (int) ($p['onAir']['left'] ?? 0)];
        }

        return $p['upcoming'][0] ?? null;
    }

    /**
     * The talk show while it is on air or ready.
     *
     * @param  Programme  $p
     * @return Item|null
     */
    public static function duel(array $p): ?array
    {
        foreach ([...($p['onAir'] === null ? [] : [$p['onAir']]), ...$p['upcoming']] as $item) {
            if ($item['format'] === self::TALKSHOW) {
                return $item;
            }
        }

        return null;
    }

    /** "14:45" in Berlin. */
    public static function time(int $at): string
    {
        return CarbonImmutable::createFromTimestamp($at)->setTimezone((string) config('tv.commentary.programme.timezone', 'Europe/Berlin'))->format('H:i');
    }

    /**
     * The clock time of an item, or '' once it has passed: an item still waiting after its slot (the spool airs it
     * late) showed its old time, "22:15" at 23:28 (user 2026-10-25), and read as a wrong clock.
     */
    public static function at(int $at, int $now): string
    {
        return $at < $now - 60 ? '' : self::time($at);
    }

    /** "jetzt", "gleich" (past its slot, still waiting), "in 1 min", "in 12 min", "in 2 Std", "in 1 Std 5 min". */
    public static function countdown(int $at, int $now): string
    {
        $seconds = $at - $now;

        if ($seconds < -60) {
            return 'gleich';
        }

        if ($seconds <= 30) {
            return 'jetzt';
        }

        $minutes = (int) ceil($seconds / 60);

        if ($minutes < 60) {
            return 'in '.$minutes.' min';
        }

        return 'in '.intdiv($minutes, 60).' Std'.($minutes % 60 === 0 ? '' : ' '.($minutes % 60).' min');
    }

    /** "noch 6 min", "noch 1 Std 5 min", "gleich vorbei" under a minute. */
    public static function remaining(int $seconds): string
    {
        if ($seconds < 60) {
            return 'gleich vorbei';
        }

        $minutes = (int) round($seconds / 60);

        return 'noch '.($minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' Std'.($minutes % 60 === 0 ? '' : ' '.($minutes % 60).' min'));
    }

    /** "58 min", "1 Std 12 min". */
    public static function length(float $seconds): string
    {
        $minutes = max(1, (int) round($seconds / 60));

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' Std'.($minutes % 60 === 0 ? '' : ' '.($minutes % 60).' min');
    }

    /**
     * The rows of the programme slide (r1): the item on air, then the next ones (upcoming, then the night loop), each
     * with its group label ("Gleich" for the first, "Heute noch" or "Danach" for the rest), at most `$rows`.
     *
     * @param  Programme  $p
     * @return list<array{label: string, item: Item, when: string, right: string}>
     */
    public static function rows(array $p, int $rows): array
    {
        $out = [];
        $rest = $p['night'] ? 'Danach' : 'Heute noch';

        foreach ($p['upcoming'] as $item) {
            $out[] = ['order' => (int) $item['at'], 'label' => '', 'item' => $item, 'when' => self::at((int) $item['at'], $p['now']), 'right' => self::countdown((int) $item['at'], $p['now'])];
        }

        // The loop's episodes follow one another after the one on air: they sort in by that estimate (a bulletin cuts
        // into them, so they show no time of their own, only their length).
        $start = $p['now'] + (int) ($p['onAir']['left'] ?? 0);

        foreach ($p['loop'] as $item) {
            $out[] = ['order' => $start, 'label' => '', 'item' => $item, 'when' => '', 'right' => self::length($item['seconds'])];
            $start += (int) $item['seconds'];
        }

        usort($out, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $out = array_map(fn (array $row): array => ['label' => $row['label'], 'item' => $row['item'], 'when' => $row['when'], 'right' => $row['right']], array_slice($out, 0, max(0, $rows)));

        // The group labels: the first row is "Gleich", the first of the rest "Heute noch" (at night "Danach").
        foreach ($out as $i => $row) {
            $out[$i]['label'] = $i === 0 ? 'Gleich' : ($i === 1 ? $rest : '');
        }

        return $out;
    }

    /**
     * The hour clock of the programme slide, as a station plans its hour: the ring is the clock hour on air (the full
     * hour at the top), the part already played darker, the hand at now; an arc per show in this hour from its start
     * to its end (at least two minutes, so a short bulletin still shows), dimmed where it has played already; ticks
     * at :00, :15, :30, :45. A show in the next hour is in the list only.
     *
     * @param  Programme  $p
     * @return array{played: string|null, arcs: list<array{d: string, colour: string, past: bool}>, hand: array{x1: float, y1: float, x2: float, y2: float}, ticks: list<array{x1: float, y1: float, x2: float, y2: float, label: string, lx: float, ly: float}>}
     */
    public static function dial(array $p, float $cx, float $cy, float $r): array
    {
        $now = $p['now'];
        // Berlin's offsets are whole hours: the clock hour starts at a multiple of 3600 s in UTC too.
        $hourStart = $now - $now % 3600;
        $hourEnd = $hourStart + 3600;
        $arcs = [];
        $items = [];

        if ($p['onAir'] !== null) {
            $left = (float) ($p['onAir']['left'] ?? 0);
            $items[] = [$p['onAir']['family'], min($now, (int) ($p['onAir']['at'] ?? $now)), (int) round($now + $left)];
        }

        foreach ($p['upcoming'] as $item) {
            // A 20 s gap before the next show keeps two arcs apart.
            $items[] = [$item['family'], (int) $item['at'], (int) round($item['at'] + max(120.0, $item['seconds']) - 20)];
        }

        foreach ($items as [$family, $start, $end]) {
            $from = max($hourStart, $start);
            $to = min($hourEnd - 20, max($end, $from + 120));

            if ($from >= $hourEnd - 20 || $to <= $from) {
                continue;
            }

            $colour = self::COLOURS[$family] ?? '#FFFFFF';

            if ($from < $now) {
                $arcs[] = ['d' => self::arc($cx, $cy, $r, $from, min($to, $now)), 'colour' => $colour, 'past' => true];
            }

            if ($to > $now) {
                $arcs[] = ['d' => self::arc($cx, $cy, $r, max($from, $now), $to), 'colour' => $colour, 'past' => false];
            }
        }

        $inner = self::point($cx, $cy, $r - 24, self::angle($now));
        $outer = self::point($cx, $cy, $r + 24, self::angle($now));
        $ticks = [];

        foreach ([0 => '00', 15 => '15', 30 => '30', 45 => '45'] as $minute => $label) {
            $angle = $minute / 60 * 2 * M_PI - M_PI / 2;
            $tickOuter = self::point($cx, $cy, $r + 22, $angle);
            $tickInner = self::point($cx, $cy, $r + 10, $angle);
            $text = self::point($cx, $cy, $r + 44, $angle);
            $ticks[] = ['x1' => $tickInner['x'], 'y1' => $tickInner['y'], 'x2' => $tickOuter['x'], 'y2' => $tickOuter['y'], 'label' => $label, 'lx' => $text['x'], 'ly' => round($text['y'] + 7, 1)];
        }

        return [
            'played' => $now - $hourStart < 30 ? null : self::arc($cx, $cy, $r, $hourStart, $now),
            'arcs' => $arcs,
            'hand' => ['x1' => $inner['x'], 'y1' => $inner['y'], 'x2' => $outer['x'], 'y2' => $outer['y']],
            'ticks' => $ticks,
        ];
    }

    /** The angle (radians, 0 at 3 o'clock) of a moment on the hour clock: its minute of the hour (Berlin's offsets are whole hours). */
    private static function angle(int $ts): float
    {
        return ($ts % 3600) / 3600 * 2 * M_PI - M_PI / 2;
    }

    /** @return array{x: float, y: float} */
    private static function point(float $cx, float $cy, float $r, float $angle): array
    {
        return ['x' => round($cx + $r * cos($angle), 1), 'y' => round($cy + $r * sin($angle), 1)];
    }

    /** An SVG arc path on the ring from `$from` to `$to` (timestamps, clockwise). */
    private static function arc(float $cx, float $cy, float $r, int $from, int $to): string
    {
        $a = self::point($cx, $cy, $r, self::angle($from));
        $b = self::point($cx, $cy, $r, self::angle($to));
        $large = $to - $from > 1800 ? 1 : 0;

        return 'M'.$a['x'].' '.$a['y'].'A'.$r.' '.$r.' 0 '.$large.' 1 '.$b['x'].' '.$b['y'];
    }

    /** The first sentence of a tagline (the programme rows have room for it alone). */
    public static function short(string $tagline): string
    {
        return preg_match('/^.+?[.!?](?=\s|$)/u', trim($tagline), $match) === 1 ? $match[0] : trim($tagline);
    }

    /** A tagline without its first sentence (the part a podcast's "Teil 3 von 7" replaces), the whole one when it has one sentence. */
    public static function rest(string $tagline): string
    {
        $rest = trim(mb_substr(trim($tagline), mb_strlen(self::short($tagline))));

        return $rest === '' ? trim($tagline) : $rest;
    }

    /**
     * The spotlight's title: the largest size at which it fits whole in at most three lines of `$maxPx` and no taller
     * than `$maxHeight` (line pitch 1.12), else the smallest size, shortened.
     *
     * @return array{lines: list<string>, size: float, font: string}
     */
    public static function spotTitle(string $title, float $maxPx, float $maxHeight): array
    {
        $sizes = [88, 76, 64, 54, 46];
        $best = null;

        foreach ($sizes as $size) {
            $best = RotationKit::headline($title, [$size], $maxPx, 3);

            if (implode(' ', $best['lines']) === RotationKit::clean($title) && count($best['lines']) * $size * 1.12 <= $maxHeight) {
                return $best;
            }
        }

        return RotationKit::headline($title, [$sizes[count($sizes) - 1]], $maxPx, (int) max(1, floor($maxHeight / ($sizes[count($sizes) - 1] * 1.12))));
    }

    private static function withoutArticle(string $name): string
    {
        return preg_replace('/^(der|die|das)\s+/iu', '', $name) ?? $name;
    }

    private static function sentence(string $what): string
    {
        $what = trim($what);

        return mb_strtoupper(mb_substr($what, 0, 1)).mb_substr($what, 1).(str_ends_with($what, '.') ? '' : '.');
    }
}
