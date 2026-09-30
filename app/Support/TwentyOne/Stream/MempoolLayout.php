<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\TwentyOne\Stream\RotationKit as K;

/**
 * Where everything of the mempool slide (m1) sits on the 1280x720 still, as
 * plain numbers and fitted strings: stream views define no closures (they
 * leak on every render without the CLI opcache), so the view only draws what
 * layout() hands it.
 *
 * One row of cubes, up to MempoolSlides::COLUMNS of them: the left group
 * (played games, or the season's mined blocks) oldest first up to a dashed
 * divider, the right group (running and scheduled games, the pending
 * mempool) after it. A cube is the game's colour and logo, its score big;
 * under it the people: a winner's face big and crowned with who they beat,
 * the miners of a block with the block's reward in sats, two faces for a
 * game still on. An empty side is an invitation, never a gap.
 */
final class MempoolLayout
{
    /** Front face of a cube. */
    public const W = 196;

    public const H = 152;

    /** Depth of the top and side faces. */
    public const D = 14;

    /** One column: the cube with its depth. */
    public const COL = self::W + self::D;

    /** Top edge of the cubes' front faces. */
    public const CUBE_Y = 250;

    /** Top edge of the faces under the cubes. */
    public const SIDES_Y = 430;

    /** Baselines of the two lines under the faces. */
    public const LINE1_Y = 540;

    public const LINE2_Y = 570;

    /** Baselines of the small lines under a mined block: what the reward is, and each winner's share of a team's. */
    public const LINE3_Y = 594;

    public const LINE4_Y = 618;

    /** Baseline of the last row: the call to act, and the legend of the games on screen after it. */
    public const FOOT_Y = 648;

    /** Orange of the league (the chain's colour on the site). */
    public const BTC = '#F7931A';

    /** Ink on a finished cube (app.css `--color-on-btc`, >= 4.5:1 on every family's gradient). */
    public const ON_CUBE = '#17120A';

    public const MUTED = '#ADADB0';

    /**
     * The colours of a game family (app.css `.g-*`): the front's gradient, the
     * top face and the side's gradient of a finished cube.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const FAMILIES = [
        'chess' => ['#22D3EE', '#0D9488', '#67E8F9', '#0E7490', '#115E59'],
        'rl' => ['#F97316', '#EC4899', '#FDBA74', '#C2410C', '#9D174D'],
        'fc' => ['#22C55E', '#16A34A', '#86EFAC', '#15803D', '#14532D'],
        'morris' => ['#D9B77E', '#A87A42', '#EBD3A8', '#6B4A22', '#4A3318'],
        'checkers' => ['#60A5FA', '#3B82F6', '#93C5FD', '#1E40AF', '#1E3A8A'],
        'aoe' => ['#F0ABFC', '#D946EF', '#F5D0FE', '#86198F', '#701A75'],
        'other' => ['#ADADB0', '#8A8A90', '#D4D4D6', '#3A3A42', '#2A2A30'],
    ];

    /**
     * The game logos (components/icon.blade.php, 24 px stroke icons; chess is
     * the knight of components/block-strip). Static markup, never user data.
     */
    private const LOGOS = [
        'chess' => '<path stroke="none" fill-rule="evenodd" d="M17 18C17.5 12 17 6.5 12.5 4L11.5 2L10 4.2C8 5.2 6 7.8 4.6 10.2C4.3 10.9 4.7 11.7 5.4 11.9L6.4 12.3C7.1 12.5 7.9 12.2 8.3 11.6L9.6 10.6C10.3 10.3 10.8 10.4 11.2 10.8C9.4 12.8 8 15 7.6 18ZM9.9 6.2a.9 .9 0 1 0 .01 0ZM5 19.5h14V22H5z"/>',
        'rocket-league' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3v4l-3.5 2.5M12 7l3.5 2.5M8.5 9.5 7 14l5 3 5-3-1.5-4.5M3.5 10.5 7 14M20.5 10.5 17 14M12 17v4"/></g>',
        'soccer' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 4.5h-5L8 10z"/><path d="M12 3v4M16 10l4.5-1.5M14.5 14.5l2.5 4M9.5 14.5 7 18.5M8 10 3.5 8.5"/></g>',
        'morris' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18"/><rect x="7.5" y="7.5" width="9" height="9"/><path d="M12 3v4.5M12 16.5V21M3 12h4.5M16.5 12H21"/></g>',
        'checkers' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="9" rx="8" ry="3.5"/><path d="M4 9v5c0 1.9 3.6 3.5 8 3.5s8-1.6 8-3.5V9"/><ellipse cx="12" cy="9" rx="4" ry="1.6"/></g>',
        'castle' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V8h3v3h2.5V8h5v3H17V8h3v13zM10 21v-4a2 2 0 0 1 4 0v4"/></g>',
        'trophy' => '<g fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/></g>',
    ];

    /**
     * The inner SVG of a game's logo on a 24 px grid (the registry's icon
     * names), the trophy for a game without one.
     */
    public static function logo(string $icon): string
    {
        return self::LOGOS[$icon] ?? self::LOGOS['trophy'];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public static function colours(string $family): array
    {
        return self::FAMILIES[$family] ?? self::FAMILIES['other'];
    }

    /**
     * Where the columns start: the left group, a gap for the divider, the
     * right group, all of it centred on the 1280 px width.
     *
     * @return array{left: list<float>, right: list<float>, divider: float|null}
     */
    public static function columns(int $left, int $right, float $width = self::COL, float $gap = 20, float $divider = 72): array
    {
        $total = ($left + $right) * $width + max(0, $left - 1) * $gap + max(0, $right - 1) * $gap + ($left > 0 && $right > 0 ? $divider : 0);
        $x = round((1280 - $total) / 2, 1);
        $out = ['left' => [], 'right' => [], 'divider' => null];

        for ($i = 0; $i < $left; $i++) {
            $out['left'][] = $x;
            $x += $width + ($i < $left - 1 ? $gap : 0);
        }

        if ($left > 0 && $right > 0) {
            $out['divider'] = round($x + $divider / 2, 1);
            $x += $divider;
        }

        for ($i = 0; $i < $right; $i++) {
            $out['right'][] = $x;
            $x += $width + $gap;
        }

        return $out;
    }

    /** The biggest size up to $max at which $s in Unbounded fits $maxPx (the kerning reserve of RotationKit::fit()). */
    public static function displaySize(string $s, float $max, float $maxPx, float $min = 16): float
    {
        $width = K::width($s, K::DISPLAY, 1000) / 1000 * 1.04;

        return $width <= 0 ? $max : max($min, min($max, floor($maxPx / $width)));
    }

    /**
     * Everything the view draws, from MempoolSlides::all().
     *
     * @param  array<string, mixed>  $m
     * @return array{season: bool, empty: bool, bugNote: string, headline: string, lead: string, cta: string, labels: list<array{text: string, x: float}>, divider: float|null, cubes: list<array<string, mixed>>, legend: list<array{name: string, colour: string, x: float, textX: float}>}
     */
    public static function layout(array $m): array
    {
        $season = ($m['mode'] ?? '') === 'season';
        $left = array_values(array_filter((array) ($m[$season ? 'blocks' : 'finished'] ?? []), 'is_array'));
        $right = array_values(array_filter((array) ($m['running'] ?? []), 'is_array'));
        $empty = $left === [] && $right === [];

        $leftItems = array_map(fn (array $item): array => ['kind' => $season ? 'mined' : 'cube', 'item' => $item], $left);
        $rightItems = array_map(fn (array $item): array => ['kind' => 'cube', 'item' => $item], $right);

        if ($season && $leftItems === []) {
            $leftItems = [['kind' => 'ghost-block', 'item' => []]];
        }

        if (! $season && $empty) {
            // Nothing played, nothing running: a row of open cubes, the first one yours.
            $rightItems = [['kind' => 'ghost-game', 'item' => []], ...array_fill(0, MempoolSlides::COLUMNS - 1, ['kind' => 'ghost', 'item' => []])];
        } elseif ($rightItems === []) {
            $rightItems = [['kind' => 'ghost-game', 'item' => []]];
        }

        $cols = self::columns(count($leftItems), count($rightItems));
        $cubes = [];
        $legend = [];

        foreach ([[$leftItems, $cols['left']], [$rightItems, $cols['right']]] as [$items, $xs]) {
            foreach ($items as $i => $entry) {
                $cube = self::cube($entry['kind'], $entry['item'], $xs[$i], 'm'.count($cubes));
                $cubes[] = $cube;

                if (isset($entry['item']['slug']) && is_string($entry['item']['slug'])) {
                    $legend[$entry['item']['slug']] ??= ['name' => K::clean((string) ($entry['item']['name'] ?? $entry['item']['slug'])), 'colour' => $cube['c'][0]];
                }
            }
        }

        // The chain: a block links to the next mined block right of it.
        foreach ($cubes as $i => $cube) {
            $next = $cubes[$i + 1] ?? null;
            $cubes[$i]['link'] = $cube['state'] === 'mined' && $next !== null && $next['state'] === 'mined'
                ? ['x' => $cube['x'] + self::COL - 2, 'w' => $next['x'] - $cube['x'] - self::COL + 4] : null;
        }

        $labels = [];

        if (! $empty || $season) {
            if ($cols['left'] !== []) {
                $labels[] = ['text' => $season ? 'Mined' : 'Played', 'x' => $cols['left'][0]];
            }

            if ($cols['right'] !== []) {
                $labels[] = ['text' => $season ? 'Mempool' : 'Up now', 'x' => $cols['right'][0]];
            }
        }

        $rest = (string) ($m['rest'] ?? 'pre-launch');
        $mining = $rest === 'between' ? 'Rated wins mine blocks again when a season runs.' : 'Rated wins mine blocks once the season starts.';

        return [
            'season' => $season,
            'empty' => $empty,
            'bugNote' => $season ? 'season chain live' : 'every game, live',
            'headline' => $season
                ? K::fit(K::clean((string) ($m['season'] ?? 'Season')).' chain', K::DISPLAY, 52, 1200)
                : ($empty ? 'The mempool is empty.' : 'Mempool'),
            'lead' => K::fit(match (true) {
                $season && $left === [] => 'Rated wins mine blocks. The first one is up for grabs.',
                $season => 'Rated wins mine blocks. Every block pays its winners in sats.',
                $empty => 'Start a game and it lands here first. '.$mining,
                default => 'Every game, played and running. '.$mining,
            }, K::MONO, 22, 1200),
            // The site's address is in the ticker below on every slide; this row says what to do.
            'cta' => $cta = $season ? 'Mine the next block.' : 'Your game lands here next.',
            'labels' => $labels,
            'divider' => $cols['divider'],
            'cubes' => $cubes,
            'legend' => self::legend(array_values($legend), 40 + K::width($cta, K::MONO, 22) + 48),
        ];
    }

    /**
     * The games on screen, each a colour chip and its name, left to right
     * after the call to act, as long as they fit the line.
     *
     * @param  list<array{name: string, colour: string}>  $games
     * @return list<array{name: string, colour: string, x: float, textX: float}>
     */
    private static function legend(array $games, float $x): array
    {
        $out = [];

        foreach ($games as $game) {
            $width = 24 + K::width($game['name'], K::MONO, 18);

            if ($x + $width > 1240) {
                break;
            }

            $out[] = [...$game, 'x' => $x, 'textX' => $x + 24];
            $x += $width + 40;
        }

        return $out;
    }

    /**
     * One column: the cube and the people under it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function cube(string $kind, array $item, float $x, string $id): array
    {
        $ghost = str_starts_with($kind, 'ghost');
        $state = match (true) {
            $ghost => 'ghost',
            $kind === 'mined' => 'mined',
            default => in_array($item['state'] ?? 'fin', ['fin', 'live', 'next'], true) ? (string) $item['state'] : 'fin',
        };
        $c = self::colours($ghost ? 'other' : (string) ($item['game'] ?? 'other'));
        $dark = in_array($state, ['fin', 'mined'], true);
        $casual = ! $ghost && $kind !== 'mined' && (bool) ($item['casual'] ?? false);
        // The casual chip takes 60 px and a gap from the right of the line.
        $whenMax = self::W - 32 - ($casual ? 66 : 0);

        $score = match ($state) {
            'ghost' => '',
            'mined' => 'Block '.(int) ($item['height'] ?? 0),
            default => K::clean((string) ($item['score'] ?? '')),
        };
        $scoreSize = match (true) {
            $state === 'mined' => self::displaySize($score, 36, self::W - 32),
            (bool) ($item['word'] ?? false) => self::displaySize($score, 30, self::W - 32),
            default => self::displaySize($score, 44, self::W - 32),
        };

        return [
            'id' => $id,
            'x' => $x,
            'state' => $state,
            'ghost' => $kind,
            'c' => $c,
            'ink' => $dark ? self::ON_CUBE : '#FFFFFF',
            'inkSoft' => $dark ? self::ON_CUBE : self::MUTED,
            'icon' => (string) ($item['icon'] ?? ''),
            'mode' => $ghost ? '' : K::fit((string) ($item['mode'] ?? ''), K::MONO, 18, self::W - 62),
            'score' => $score,
            'scoreSize' => $scoreSize,
            'when' => $ghost ? '' : K::fit((string) ($item['when'] ?? ''), K::MONO, 16, $whenMax),
            'casual' => $casual,
            'level' => max(0, min(100, (int) ($item['level'] ?? 0))),
            ...self::people($kind, $state, $item, $x, $id),
        ];
    }

    /**
     * The faces and the two lines under a cube.
     *
     * @param  array<string, mixed>  $item
     * @return array{faces: list<array<string, mixed>>, vs: array{x: float, y: float}|null, extra: list<array{text: string, y: int}>, line1: array{text: string, font: string, size: float}|null, line2: array{text: string, ink: string, size: float}|null}
     */
    private static function people(string $kind, string $state, array $item, float $x, string $id): array
    {
        $max = self::COL - 4;
        $sides = array_values(array_filter((array) ($item['sides'] ?? []), 'is_array'));

        if (str_starts_with($kind, 'ghost')) {
            return match ($kind) {
                'ghost-block' => ['faces' => [self::seat($x, $id.'s', self::BTC)], 'vs' => null, 'extra' => [], 'line1' => ['text' => 'Next block', 'font' => K::DISPLAY, 'size' => 22], 'line2' => ['text' => 'Win a rated game', 'ink' => self::BTC, 'size' => 18]],
                'ghost-game' => ['faces' => [self::seat($x, $id.'s', '#6B6B72')], 'vs' => null, 'extra' => [], 'line1' => ['text' => 'Your game', 'font' => K::DISPLAY, 'size' => 22], 'line2' => ['text' => 'Start one now', 'ink' => self::MUTED, 'size' => 18]],
                default => ['faces' => [], 'vs' => null, 'extra' => [], 'line1' => null, 'line2' => null],
            };
        }

        if ($state === 'mined') {
            $faces = [];

            // One miner big; a team side by side, every one of them crowned.
            $team = count($sides) > 1;

            foreach (array_slice($sides, 0, 3) as $i => $side) {
                $faces[] = $team
                    ? self::face($side, $x + $i * 66, self::SIDES_Y + 16, 56, $id.'w'.$i, self::BTC, self::BTC)
                    : self::face($side, $x, self::SIDES_Y, 72, $id.'w'.$i, self::BTC, self::BTC);
            }

            $first = $sides[0]['name'] ?? '';
            $more = count($sides) > 1 ? ' +'.(count($sides) - 1) : '';
            $name = K::name((string) $first, 'Winner', 22, $max - K::width($more, K::DISPLAY, 22) * 1.04);
            $reward = (int) ($item['reward'] ?? 0);
            $perPlayer = (int) ($item['perPlayer'] ?? 0);

            return [
                'faces' => $faces,
                'vs' => null,
                'line1' => ['text' => $name['text'].$more, 'font' => $name['font'], 'size' => 22],
                // The block's reward as /mining lists it, named as such; a team's share per winner under it. Nothing when the block carries none.
                'line2' => $reward > 0 ? ['text' => K::fit(K::sats($reward).' sats', K::MONO, 20, $max), 'ink' => self::BTC, 'size' => 20] : null,
                'extra' => match (true) {
                    $reward <= 0 => [],
                    $team && $perPlayer > 0 => [['text' => 'block reward', 'y' => self::LINE3_Y], ['text' => K::fit(K::sats($perPlayer).' sats each', K::MONO, 16, $max), 'y' => self::LINE4_Y]],
                    default => [['text' => 'block reward', 'y' => self::LINE3_Y]],
                },
            ];
        }

        $winner = null;

        foreach ($sides as $i => $side) {
            if (($side['won'] ?? false) === true) {
                $winner = $i;
            }
        }

        $a = $sides[0] ?? ['name' => ''];
        $b = $sides[1] ?? ['name' => ''];

        if ($state === 'fin' && $winner !== null) {
            $loser = $sides[1 - $winner] ?? ['name' => ''];
            $name = K::name((string) ($sides[$winner]['name'] ?? ''), 'Player', 22, $max);
            // A win nobody played for (a forfeit or no-show) stays on the record, but uncrowned and without "beat".
            $forfeit = (bool) ($item['forfeit'] ?? false);

            return [
                'faces' => [
                    $forfeit ? self::face($sides[$winner], $x, self::SIDES_Y + 16, 56, $id.'w', null, null) : self::face($sides[$winner], $x, self::SIDES_Y, 72, $id.'w', self::BTC, self::BTC),
                    self::face($loser, $x + 86, self::SIDES_Y + 32, 40, $id.'l', null, null),
                ],
                'vs' => null,
                'extra' => [],
                'line1' => ['text' => $name['text'], 'font' => $name['font'], 'size' => 22],
                'line2' => ['text' => $forfeit ? 'won by forfeit' : K::fit('beat '.K::clean((string) ($loser['name'] ?? '')), K::MONO, 18, $max), 'ink' => self::MUTED, 'size' => 18],
            ];
        }

        $name = K::name((string) ($a['name'] ?? ''), 'Player', 22, $max);

        return [
            'faces' => [
                self::face($a, $x, self::SIDES_Y + 16, 56, $id.'a', null, null),
                self::face($b, $x + 104, self::SIDES_Y + 16, 56, $id.'b', null, null),
            ],
            'vs' => ['x' => $x + 80, 'y' => self::SIDES_Y + 50],
            'extra' => [],
            'line1' => ['text' => $name['text'], 'font' => $name['font'], 'size' => 22],
            'line2' => ['text' => K::fit('vs '.K::clean((string) ($b['name'] ?? '')), K::MONO, 18, $max), 'ink' => self::MUTED, 'size' => 18],
        ];
    }

    /**
     * An open seat under an open cube: a dashed circle where the next player's face goes.
     *
     * @return array{face: array{uri: ?string, tag: ?string, fit: string}, x: float, y: float, d: float, id: string, ring: string|null, crown: string|null, open: string, openInk: string}
     */
    private static function seat(float $x, string $id, string $ink): array
    {
        return ['face' => ['uri' => null, 'tag' => null, 'fit' => 'slice'], 'x' => $x, 'y' => self::SIDES_Y, 'd' => 72, 'id' => $id, 'ring' => null, 'crown' => null, 'open' => 'open', 'openInk' => $ink];
    }

    /**
     * A face for partials/face: the player's avatar, else the clan's logo or its tag tile.
     *
     * @param  array<string, mixed>  $side
     * @return array{face: array{uri: ?string, tag: ?string, fit: string}, x: float, y: float, d: float, id: string, ring: string|null, crown: string|null}
     */
    private static function face(array $side, float $x, float $y, float $d, string $id, ?string $ring, ?string $crown): array
    {
        $clan = (string) ($side['tag'] ?? '') !== '' || ($side['logo'] ?? null) !== null;

        return ['face' => K::face($side, $clan), 'x' => $x, 'y' => $y, 'd' => $d, 'id' => $id, 'ring' => $ring, 'crown' => $crown];
    }
}
