<?php

use App\Support\QrCode;

/*
 * The league's own QR encoder (P19, the TV's "follow on your phone" code):
 * every version it chooses reads back to the exact bytes with zbarimg, and a
 * code with its data area scrambled does not (the reader is not just
 * accepting anything). Needs rsvg-convert and zbarimg on the host.
 */

function qrDecode(string $svg): string
{
    $dir = sys_get_temp_dir().'/qr-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/code.svg', $svg);
    exec('rsvg-convert -w 600 '.escapeshellarg($dir.'/code.svg').' -o '.escapeshellarg($dir.'/code.png'));
    $text = rtrim((string) shell_exec('zbarimg -q --raw -Sbinary '.escapeshellarg($dir.'/code.png')), "\n");
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    return $text;
}

test('codes from version 1 to 20 read back to their text', function (string $text, int $size) {
    expect(count(QrCode::matrix($text)))->toBe($size)
        ->and(qrDecode(QrCode::svg($text)))->toBe($text);
})->with([
    'version 1' => ['a', 21],
    'version 4, a tournament URL' => ['https://esports.einundzwanzig.space/tournaments/12', 33],
    'version 5' => ['https://esports.einundzwanzig.space/en/tournaments/123456789/tv?x=1', 37],
    'version 6' => [str_repeat('Z', 100), 41],
    'version 8, with version bits' => [str_repeat('x', 150), 49],
    'UTF-8 bytes' => [str_repeat('äöü', 30), 53],
    'version 10' => [str_repeat('y', 213), 57],
    'version 13, blocks of two lengths' => [str_repeat('k', 330), 69],
    'version 17, a BOLT11 invoice' => ['lightning:lnbcrt21u1p'.str_repeat('q9x8w7v6', 55), 85],
    'version 20, the longest' => [str_repeat('z', 666), 97],
])->skip(fn (): bool => ! is_executable('/usr/bin/zbarimg') || ! is_executable('/usr/bin/rsvg-convert'), 'needs zbarimg and rsvg-convert');

test('a scrambled code does not read, and a text past version 20 is refused', function () {
    $matrix = QrCode::matrix('https://esports.einundzwanzig.space/tournaments/12');
    $size = count($matrix);
    $path = '';

    foreach ($matrix as $y => $row) {
        foreach ($row as $x => $on) {
            $scrambled = $x > 8 && $y > 8 && $x < $size - 8 && $y < $size - 8 && ($x * 7 + $y * 3) % 3 === 0;

            if ($on !== $scrambled) {
                $path .= 'M'.($x + 4).' '.($y + 4).'h1v1h-1z';
            }
        }
    }

    $view = $size + 8;

    expect(qrDecode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$view} {$view}'><rect width='{$view}' height='{$view}' fill='#fff'/><path d='{$path}'/></svg>"))->toBe('')
        ->and(fn () => QrCode::matrix(str_repeat('y', 667)))->toThrow(InvalidArgumentException::class);
})->skip(fn (): bool => ! is_executable('/usr/bin/zbarimg') || ! is_executable('/usr/bin/rsvg-convert'), 'needs zbarimg and rsvg-convert');
