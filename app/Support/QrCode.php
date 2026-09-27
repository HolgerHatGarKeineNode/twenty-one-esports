<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A QR code for a short text (a page URL), drawn as SVG, without a package
 * (the league adds no dependency for it; the stream scenes use static SVGs
 * made once with `qrencode`, which cannot follow a tournament's own URL).
 *
 * Byte mode, error correction level M, versions 1 to 10 (up to 213 bytes),
 * following ISO/IEC 18004 as Project Nayuki's reference encoder lays it out
 * (https://www.nayuki.io/page/qr-code-generator-library). The mask with the
 * lowest penalty of rules N1, N2 and N4 is taken; a reader decodes any of
 * the eight. tests/Unit/QrCodeTest.php reads codes back with zbarimg.
 */
final class QrCode
{
    /** ECC codewords per block, level M, by version. */
    private const ECC_PER_BLOCK = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26];

    /** Error correction blocks, level M, by version. */
    private const BLOCKS = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5];

    /** Format bits of level M (01). */
    private const LEVEL_BITS = 0;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $function = [];

    private int $size;

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->function = $this->modules;
    }

    /**
     * The dark modules, row by row (true = dark).
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = 0;

        for ($candidate = 1; $candidate <= 10; $candidate++) {
            $countBits = $candidate <= 9 ? 8 : 16;

            if (4 + $countBits + 8 * count($bytes) <= self::dataCodewords($candidate) * 8) {
                $version = $candidate;
                break;
            }
        }

        if ($version === 0) {
            throw new InvalidArgumentException('Text too long for a version 10 QR code.');
        }

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEcc($qr->dataBits($bytes)));

        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = clone $qr;
            $candidate->applyMask($mask);
            $candidate->drawFormatBits($mask);
            $penalty = $candidate->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best->modules;
    }

    /**
     * The code as an inline SVG: one path, a quiet zone of four modules.
     */
    public static function svg(string $text, string $dark = '#0A0A0B', string $light = '#FFFFFF', string $label = ''): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $view = $size + 8;
        $path = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $path .= 'M'.($x + 4).' '.($y + 4).'h1v1h-1z';
                }
            }
        }

        $title = $label === '' ? '' : '<title>'.e($label).'</title>';
        $role = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="'.e($label).'"';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$view.' '.$view.'" shape-rendering="crispEdges" '.$role.'>'.$title
            .'<rect width="'.$view.'" height="'.$view.'" fill="'.$light.'"/><path d="'.$path.'" fill="'.$dark.'"/></svg>';
    }

    private static function rawModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function dataCodewords(int $version): int
    {
        return intdiv(self::rawModules($version), 8) - self::ECC_PER_BLOCK[$version] * self::BLOCKS[$version];
    }

    /**
     * @param  list<int>  $bytes
     * @return list<int> data codewords
     */
    private function dataBits(array $bytes): array
    {
        $bits = [];
        $append = function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);
        $append(count($bytes), $this->version <= 9 ? 8 : 16);

        foreach ($bytes as $byte) {
            $append($byte, 8);
        }

        $capacity = self::dataCodewords($this->version) * 8;
        $append(0, min(4, $capacity - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);

        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $codewords = [];

        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = array_reduce($chunk, fn (int $carry, int $bit): int => ($carry << 1) | $bit, 0);
        }

        return $codewords;
    }

    /**
     * Split into blocks, add Reed-Solomon ECC to each and interleave.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private function addEcc(array $data): array
    {
        $blocks = self::BLOCKS[$this->version];
        $eccLength = self::ECC_PER_BLOCK[$this->version];
        $raw = intdiv(self::rawModules($this->version), 8);
        $short = $blocks - $raw % $blocks;
        $shortLength = intdiv($raw, $blocks);
        $divisor = self::rsDivisor($eccLength);
        $all = [];
        $offset = 0;

        for ($i = 0; $i < $blocks; $i++) {
            $length = $shortLength - $eccLength + ($i < $short ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = self::rsRemainder($block, $divisor);

            if ($i < $short) {
                $block[] = 0;
            }

            $all[] = [...$block, ...$ecc];
        }

        $result = [];

        for ($i = 0; $i < count($all[0]); $i++) {
            foreach ($all as $j => $block) {
                if ($i !== $shortLength - $eccLength || $j >= $short) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::rsMultiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::rsMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param  list<int>  $data
     * @param  array<int, int>  $divisor
     * @return array<int, int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::rsMultiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private static function rsMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->function[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $count = count($positions);

        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                $corner = ($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0);

                if (! $corner) {
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $this->set($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
                        }
                    }
                }
            }
        }

        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;

                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->set($xx, $yy, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    /**
     * @return list<int>
     */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $result = [6];

        for ($position = $this->size - 7; count($result) < $count; $position -= $step) {
            array_splice($result, 1, 0, [$position]);
        }

        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::LEVEL_BITS << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = ($data << 10 | $remainder) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }

        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));

        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }

        $this->set(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = $this->version << 12 | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $dark);
            $this->set($b, $a, $dark);
        }
    }

    /**
     * @param  list<int>  $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;

                    if (! $this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert && ! $this->function[$y][$x]) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty rules N1 (runs), N2 (2x2 blocks) and N4 (balance); N3 is left out. */
    private function penalty(): int
    {
        $result = 0;
        $dark = 0;

        for ($a = 0; $a < $this->size; $a++) {
            foreach ([true, false] as $rows) {
                $run = 0;
                $previous = null;

                for ($b = 0; $b < $this->size; $b++) {
                    $color = $rows ? $this->modules[$a][$b] : $this->modules[$b][$a];

                    if ($color === $previous) {
                        $run++;
                        $result += $run === 5 ? 3 : ($run > 5 ? 1 : 0);
                    } else {
                        $run = 1;
                        $previous = $color;
                    }
                }
            }
        }

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;

                if ($x < $this->size - 1 && $y < $this->size - 1) {
                    $color = $this->modules[$y][$x];

                    if ($color === $this->modules[$y][$x + 1] && $color === $this->modules[$y + 1][$x] && $color === $this->modules[$y + 1][$x + 1]) {
                        $result += 3;
                    }
                }
            }
        }

        $total = $this->size * $this->size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + max(0, $k) * 10;
    }
}
