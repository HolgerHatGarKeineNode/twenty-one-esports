<?php

namespace App\Support\Series;

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\Rating\Ratings;
use Carbon\CarbonInterface;

/**
 * What the lists and pages show for a series (Matches.dc.html, MatchDetail,
 * MatchRoom, block strip): the status chip, the result bar, the score cell
 * and the time. Public fields only; never the lobby.
 */
final class SeriesPresenter
{
    /** Status chip colours and icons, 1:1 from Matches.dc.html (`S`). */
    private const CHIPS = [
        'waiting' => ['#1E1A12', '#F9B25F', 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'],
        'scheduled' => ['#1A1A1E', '#ADADB0', 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'],
        'live' => ['#1E1E24', '#FFFFFF', 'M8 5v14l11-7z'],
        'to_confirm' => ['#1E1A12', '#F9B25F', 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'],
        'done' => ['#0F2417', '#4ADE80', 'M5 12.5 10 17 19 7'],
        'disputed' => ['#2A1214', '#F87171', 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z'],
        'closed' => ['#1A1A1E', '#8B8B90', 'M6 6l12 12M18 6 6 18'],
    ];

    /**
     * The Elo lines of a series (P7b): both lineups' ratings before, what the
     * challenger's lineup wins or loses while the series is open, the change
     * once it is decided, and each side's line for the flow card. Casual
     * series show the casual Elo, which never has a tier.
     *
     * @return array{casual: bool, before: string, stake: string, expected: string, sides: array{challenger: string, challenged: string}}
     */
    public static function ratingFacts(SeriesMatch $match): array
    {
        $ratings = Ratings::forSeries($match);
        $casual = $ratings['pool'] === 'casual';
        $signed = fn (int $delta): string => $delta > 0 ? '+'.$delta : ($delta < 0 ? '−'.abs($delta) : '±0');
        $before = fn (string $side): int => $ratings[$side]['before'] ?? $ratings[$side]['rating'];
        $line = function (string $side) use ($ratings, $casual): string {
            $rating = $ratings[$side];

            if ($rating['delta'] === null) {
                return $casual ? __('casual Elo :elo', ['elo' => $rating['rating']]) : __('Elo :elo', ['elo' => $rating['rating']]);
            }

            $values = ['before' => $rating['before'], 'after' => $rating['rating']];

            return $casual ? __('casual Elo :before → :after', $values) : __('Elo :before → :after', $values);
        };

        return [
            'casual' => $casual,
            'before' => $match->challenger_tag.' '.$before('challenger').' · '.$match->challenged_tag.' '.$before('challenged'),
            'stake' => match (true) {
                $ratings['challenger']['delta'] !== null => $match->challenger_tag.' '.$signed($ratings['challenger']['delta']).' · '.$match->challenged_tag.' '.$signed($ratings['challenged']['delta']),
                $ratings['win'] !== null => __(':tag :win if it wins, :loss if it loses', ['tag' => $match->challenger_tag, 'win' => $signed($ratings['win']), 'loss' => $signed($ratings['loss'])]),
                default => __('no change'),
            },
            'expected' => __(':tag wins :pct %', ['tag' => $match->challenger_tag, 'pct' => (int) round($ratings['expected'] * 100)]),
            'sides' => ['challenger' => $line('challenger'), 'challenged' => $line('challenged')],
        ];
    }

    /**
     * @return array<string, string> list status key => label
     */
    public static function statusLabels(): array
    {
        return [
            'waiting' => __('waiting'),
            'scheduled' => __('scheduled'),
            'live' => __('live'),
            'to_confirm' => __('to confirm'),
            'disputed' => __('disputed'),
            'done' => __('done'),
            'closed' => __('closed'),
        ];
    }

    /**
     * @return array{key: string, label: string, bg: string, color: string, icon: string}
     */
    /**
     * A chip for a list status key with its own label (chess rows on /matches).
     *
     * @return array{key: string, label: string, bg: string, color: string, icon: string}
     */
    public static function chipFor(string $key, string $label): array
    {
        [$bg, $color, $icon] = self::CHIPS[$key];

        return ['key' => $key, 'label' => $label, 'bg' => $bg, 'color' => $color, 'icon' => $icon];
    }

    /**
     * @return array{key: string, label: string, bg: string, color: string, icon: string}
     */
    public static function chip(SeriesMatch $match): array
    {
        $key = $match->listStatus();
        [$bg, $color, $icon] = self::CHIPS[$key];

        $label = match ($match->status) {
            SeriesStatus::Declined => __('declined'),
            SeriesStatus::Withdrawn => __('withdrawn'),
            SeriesStatus::Expired => __('expired'),
            default => self::statusLabels()[$key],
        };

        return ['key' => $key, 'label' => $label, 'bg' => $bg, 'color' => $color, 'icon' => $icon];
    }

    /**
     * The "Result" bar: a text, how full (0, 50, 100 %) and its colour.
     *
     * @return array{text: string, width: string, color: string}
     */
    public static function result(SeriesMatch $match, ?User $viewer = null): array
    {
        $bar = fn (string $text, int $fill) => [
            'text' => $text,
            'width' => ($fill * 50).'%',
            'color' => $match->status === SeriesStatus::Disputed ? '#5A2A2E' : ($fill === 2 ? '#1F4D2E' : '#7A4A0E'),
        ];

        return match ($match->status) {
            SeriesStatus::Open => $bar(__('answer by :time', ['time' => self::time($match->respond_by, $viewer, 'D H:i')]), 0),
            SeriesStatus::Accepted => $match->noshow_reported_at !== null
                ? $bar(__('no-show reported'), 1)
                : ($match->start_at?->isFuture() ? $bar(__('not played yet'), 0) : $bar(__('playing'), 1)),
            SeriesStatus::Reported => $bar(__('1 of 2 captains'), 1),
            SeriesStatus::Disputed => $bar(__('admin reviewing'), 1),
            SeriesStatus::Confirmed => $bar($match->rated ? __('both captains') : __('casual, not rated'), 2),
            SeriesStatus::Resolved => $bar($match->resolution?->label() ?? __('decided by admin'), 2),
            default => $bar(self::chip($match)['label'], 0),
        };
    }

    /**
     * @return array{text: string, sub: string}
     */
    public static function score(SeriesMatch $match): array
    {
        if ($match->resolution === SeriesResolution::Void) {
            return ['text' => '–', 'sub' => __('void')];
        }

        $games = $match->currentGames();

        if ($games === []) {
            return ['text' => '–', 'sub' => $match->resolution === SeriesResolution::Forfeit ? __('forfeit') : ''];
        }

        $wins = SeriesMatch::seriesScore($games);
        $sub = match (true) {
            $match->status === SeriesStatus::Reported => __('reported'),
            $match->status->hasResult() => $match->rated ? '' : __('casual'),
            default => __('provisional'),
        };

        return ['text' => $wins['challenger'].' : '.$wins['challenged'], 'sub' => $sub];
    }

    public static function format(SeriesMatch $match): string
    {
        return $match->mode.' · BO'.$match->best_of;
    }

    /**
     * "When" column: relative to now, in the viewer's time zone.
     */
    public static function when(SeriesMatch $match, ?User $viewer = null): string
    {
        return match (true) {
            $match->status === SeriesStatus::Open => __('sent :time', ['time' => $match->created_at?->diffForHumans(['short' => true]) ?? '']),
            $match->status === SeriesStatus::Accepted && $match->start_at?->isFuture() => __('at :time', ['time' => self::time($match->start_at, $viewer, 'D H:i')]),
            $match->finished_at !== null => $match->finished_at->diffForHumans(),
            ($match->start_at ?? $match->created_at)?->isToday() === true => __('since :time', ['time' => self::time($match->start_at ?? $match->created_at ?? now(), $viewer, 'H:i')]),
            default => ($match->start_at ?? $match->created_at)?->diffForHumans() ?? '',
        };
    }

    public static function time(CarbonInterface $time, ?User $viewer, string $format = 'D, M j · H:i'): string
    {
        return $time->copy()->timezone(PreSeason::timezoneFor($viewer))->locale(app()->getLocale())->translatedFormat($format);
    }

    /**
     * Rows of the "Proof" block. A casual series has no match-flow events
     * (NIP "Game registry"), so it says so instead of showing ids.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function proofRows(SeriesMatch $match): array
    {
        $lineup = function (string $address): string {
            [$kind, $pubkey, $d] = array_pad(explode(':', $address, 3), 3, '');

            $naddr = NostrKeys::naddr((int) $kind, $pubkey, $d);

            return substr($naddr, 0, 12).'…'.substr($naddr, -4);
        };
        $event = fn (?NostrEvent $event, string $kind, string $waiting) => $event === null
            ? $waiting
            : NostrKeys::shortNevent($event->event_id, $event->pubkey, $event->kind).' · '.__('kind :kind', ['kind' => $kind]);

        if (! $match->rated) {
            return [
                [__('Record'), __('casual: no Nostr events, league data only')],
                [__('Match'), __(':number, reserved at the challenge', ['number' => $match->label()])],
                [__(':clan lineup', ['clan' => $match->challenger_name]), $lineup($match->challenger_lineup_address)],
                [__(':clan lineup', ['clan' => $match->challenged_name]), $lineup($match->challenged_lineup_address)],
            ];
        }

        $report = $match->latestReport;

        return [
            [__('Challenge'), $event($match->challengeEvent, '2150', '–')],
            [__('Accepted'), $event($match->answerEvent, '2151', __('waiting for the answer'))],
            [__('Result report'), $event($report?->event, '2152', __('once submitted'))],
            [__('Result response'), $event($report?->responseEvent, '2153', __('waiting for the other captain'))],
            [__('League record'), __('pending · kind 2154')],
            [__(':clan lineup', ['clan' => $match->challenger_name]), $lineup($match->challenger_lineup_address)],
            [__(':clan lineup', ['clan' => $match->challenged_name]), $lineup($match->challenged_lineup_address)],
        ];
    }

    /**
     * One cube of the global block strip (components/block-strip).
     *
     * @return array{height: string, game: string, mode: string, score: string, word: bool, who: string, when: string, a: string, b: string, href: string, aria: string, level: string, casual: bool, state: string, dot: bool, newest: bool}
     */
    public static function block(SeriesMatch $match, bool $newest = false, ?User $viewer = null): array
    {
        $score = self::score($match);
        $wins = SeriesMatch::seriesScore($match->currentGames());
        $state = match (true) {
            $match->status->hasResult() => 'fin',
            $match->status === SeriesStatus::Accepted && $match->start_at?->isFuture() => 'next',
            default => 'live',
        };
        $leader = $wins['challenger'] === $wins['challenged'] ? null : ($wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged');
        $who = match (true) {
            $match->winner === 'challenger' || $match->winner === 'challenged' => $match->sideName($match->winner),
            $state === 'next' => $match->rated ? __('Ladder') : __('Casual'),
            $leader !== null => $match->sideName($leader),
            default => __('playing'),
        };
        $when = match ($state) {
            'fin' => $match->finished_at?->diffForHumans() ?? '',
            'next' => __('at :time', ['time' => self::time($match->start_at ?? now(), $viewer, 'H:i')]),
            default => match ($match->status) {
                SeriesStatus::Reported => __('to confirm'),
                SeriesStatus::Disputed => __('disputed'),
                default => __('live'),
            },
        };
        $played = count($match->currentGames());

        return [
            'height' => $match->label(),
            'game' => 'rl',
            'mode' => 'RL '.$match->mode,
            'score' => $state === 'next' ? 'BO'.$match->best_of : $score['text'],
            'word' => false,
            'who' => $who,
            'when' => $when,
            'a' => $match->challenger_tag,
            'b' => __('vs :name', ['name' => $match->challenged_tag]),
            'href' => route('matches.show', $match),
            'aria' => __(':number, Rocket League :mode best of :bo, :status, :a vs :b', [
                'number' => $match->label(), 'mode' => $match->mode, 'bo' => $match->best_of,
                'status' => self::chip($match)['label'], 'a' => $match->challenger_name, 'b' => $match->challenged_name,
            ]),
            'level' => $state === 'fin' ? '100%' : ($state === 'next' ? '0%' : (int) round(min(1, $played / max(1, intdiv($match->best_of, 2) + 1)) * 100).'%'),
            'casual' => ! $match->rated,
            'state' => $state,
            'dot' => $state === 'live' && $match->status === SeriesStatus::Accepted,
            'newest' => $newest,
        ];
    }
}
