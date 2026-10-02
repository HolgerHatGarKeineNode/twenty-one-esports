<?php

namespace App\Support\Tmnf;

use App\Support\TwentyOne\Stream\PublicName;

/**
 * The league's in-game overlay on its TMNF server as TMF ManiaLink XML (plan
 * "Trackmania und Restposten"), sent by TmnfOverlay with
 * SendDisplayManialinkPage(ToLogin). Kept light: plain quads and labels, the
 * game's own background style, no image from a URL, no sound.
 *
 * - board(): right edge below the stock Prev/Best box, the week's top 5.
 * - own(): the viewer's best and place, a box of its own under the board
 *   (per login, so a board for everyone never overwrites it).
 * - footer(): the site, small and faint, bottom left away from the timer.
 * - note(): top centre after a finish; TmnfOverlay removes it again.
 *
 * The syntax is Nadeo's: a page is `<manialinks>` with `<manialink id>`
 * children; a manialink replaces the shown one with the same id, an empty
 * one with an id removes it (manialink_dedicatedserver.txt in the dedicated
 * server archive, 2011-02-21). Coordinates are `posn` (x -64 left .. 64
 * right, y 48 top .. -48 bottom, z the layer) and `sizen`, as XAseco's
 * widgets write them (records_eyepiece, info_widget). Every id starts with
 * ID_PREFIX, so none clashes with another manialink on the client.
 *
 * Anything a player controls goes through text(): TMNF formatting codes
 * (`$o`, `$fff`, `$l[...]`, ...) are stripped and the rest is XML-escaped.
 */
final class TmnfManialinks
{
    public const ID_PREFIX = '2101';

    public const ID_BOARD = '2101001';

    public const ID_OWN = '2101002';

    public const ID_FOOTER = '2101003';

    public const ID_NOTE = '2101004';

    /** The rows the board shows at most. */
    public const ROWS = 5;

    /** The longest name shown, in characters (longer ones end in "…"). */
    public const NAME_LENGTH = 15;

    /** Left edge and width of the widget: aligned with the stock track box (x 44 .. 64 at 16:9), ending at the right edge as it does. */
    private const LEFT = 44.6;

    private const WIDTH = 19.4;

    /** Top of the board: under the stock Prev/Best box (y 39 .. 32.9 at 16:9), the same gap as between the stock boxes. */
    private const TOP = 31.8;

    private const ROW_HEIGHT = 1.9;

    private const BOARD_HEIGHT = 14.0;

    private const OWN_TOP = self::TOP - self::BOARD_HEIGHT - 1.0;

    private const OWN_HEIGHT = 2.8;

    /** The league's orange (#F7931A) in TMF's four-digit RGBA. */
    private const ACCENT = 'F91F';

    /**
     * A page of manialinks, as SendDisplayManialinkPage takes it.
     */
    public static function page(string ...$manialinks): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><manialinks>'.implode('', $manialinks).'</manialinks>';
    }

    /**
     * The week widget: "<server> · Week <n>" and the top rows (place, league name, time), best first.
     *
     * @param  list<array{place: int, name: string, time: string}>  $rows
     */
    public static function board(string $server, int $week, array $rows): string
    {
        $xml = self::box(self::WIDTH + 1.0, self::BOARD_HEIGHT)
            .self::label(0.9, -0.9, 17.6, 2.0, self::text("{$server} · Week {$week}", 40), 2, 'FFFF')
            .self::quad(0.9, -3.2, 17.6, 0.2, self::ACCENT);

        if ($rows === []) {
            $xml .= self::label(0.9, -3.8, 17.6, 1.8, 'No times yet this week', 1, 'EEEF');
        }

        foreach (array_slice($rows, 0, self::ROWS) as $index => $row) {
            $y = -3.8 - $index * self::ROW_HEIGHT;
            $xml .= self::label(0.9, $y, 1.7, 1.8, $row['place'].'.', 1, 'EEEF')
                .self::label(2.7, $y, 10.6, 1.8, self::text($row['name'], self::NAME_LENGTH), 1, 'FFFF')
                .self::label(18.5, $y, 5.0, 1.8, self::text($row['time'], 16), 1, 'FFFF', 'right');
        }

        return self::manialink(self::ID_BOARD, self::frame(self::LEFT, self::TOP, $xml));
    }

    /**
     * The viewer's own line: their place and best this week, or why there is none.
     */
    public static function own(?int $place, ?string $time, bool $linked): string
    {
        $xml = self::box(self::WIDTH + 1.0, self::OWN_HEIGHT).self::quad(0.9, -2.3, 1.8, 0.2, self::ACCENT)
            .self::label(0.9, -0.5, 3.0, 1.8, 'You', 1, 'FFFF');

        $xml .= match (true) {
            ! $linked => self::label(4.2, -0.5, 14.3, 1.8, 'not linked yet', 1, 'EEEF'),
            $place === null || $time === null => self::label(4.2, -0.5, 14.3, 1.8, 'no time this week', 1, 'EEEF'),
            default => self::label(4.2, -0.5, 4.0, 1.8, '#'.$place, 1, 'FFFF')
                .self::label(18.5, -0.5, 6.0, 1.8, self::text($time, 16), 1, 'FFFF', 'right'),
        };

        return self::manialink(self::ID_OWN, self::frame(self::LEFT, self::OWN_TOP, $xml));
    }

    /**
     * The site, small and faint in the bottom left corner.
     */
    public static function footer(string $site): string
    {
        return self::manialink(self::ID_FOOTER, self::label(-62.0, -45.9, 30.0, 1.6, self::text($site, 60), 1, 'FFF8'));
    }

    /**
     * A short line at the top centre ("New personal best").
     */
    public static function note(string $text): string
    {
        $xml = self::quad(-16.0, 0.0, 32.0, 4.2, null, 'Bgs1InRace', 'NavButton')
            .self::quad(-15.0, -3.6, 30.0, 0.2, self::ACCENT)
            .self::label(0.0, -0.8, 30.0, 2.0, self::text($text, 60), 2, 'FFFF', 'center');

        return self::manialink(self::ID_NOTE, self::frame(0.0, 44.6, $xml));
    }

    /**
     * An empty manialink with an id: the client removes the one it shows with that id, and only that one.
     */
    public static function remove(string $id): string
    {
        return '<manialink id="'.self::attribute($id).'"></manialink>';
    }

    /**
     * Text from outside (a player's name) made plain for a label: control
     * characters and line breaks out, TMNF formatting codes out, at most
     * `$max` characters, XML-escaped. No `$` survives, so no code can be formed.
     */
    public static function text(string $value, int $max): string
    {
        $value = PublicName::clean($value);
        // A link with a target: $l[url], $h[manialink], $p[...] (the target is dropped with it).
        $value = preg_replace('/\$[lhp]\[[^\]]*\]?/iu', '', $value) ?? '';
        // A colour ($f00, $0f, $f), any one-letter code ($o, $z, $l, ...), an escaped dollar ($$), a lone dollar at the end.
        $value = preg_replace('/\$(?:[0-9a-f]{1,3}|.)?/isu', '', $value) ?? '';

        return self::attribute(PublicName::limit($value, $max));
    }

    private static function manialink(string $id, string $content): string
    {
        return '<manialink id="'.self::attribute($id).'">'.$content.'</manialink>';
    }

    private static function frame(float $x, float $y, string $content): string
    {
        return '<frame posn="'.self::n($x).' '.self::n($y).' 0">'.$content.'</frame>';
    }

    /**
     * The rounded translucent grey of the stock in-race boxes (XAseco's widget default), running past the right edge.
     */
    private static function box(float $width, float $height): string
    {
        return self::quad(0.0, 0.0, $width, $height, null, 'Bgs1InRace', 'NavButton');
    }

    private static function quad(float $x, float $y, float $width, float $height, ?string $color, ?string $style = null, ?string $substyle = null): string
    {
        $look = $style === null ? ' bgcolor="'.$color.'"' : ' style="'.$style.'" substyle="'.$substyle.'"';

        return '<quad posn="'.self::n($x).' '.self::n($y).' '.($color === null ? '0' : '0.01').'" sizen="'.self::n($width).' '.self::n($height).'"'.$look.'/>';
    }

    /**
     * A label; `$text` is escaped already (text()) or the league's own.
     */
    private static function label(float $x, float $y, float $width, float $height, string $text, int $size, string $color, string $halign = 'left'): string
    {
        return '<label posn="'.self::n($x).' '.self::n($y).' 0.02" sizen="'.self::n($width).' '.self::n($height).'" halign="'.$halign.'"'
            .' textsize="'.$size.'" textcolor="'.$color.'" text="'.$text.'"/>';
    }

    private static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function n(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
