<?php

use App\Support\Cards\Canvas;

/** A picture of the given size: blue, with a red band along its bottom edge (a logo there). */
function coverPicture(int $width, int $height): string
{
    $picture = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagefill($picture, 0, 0, (int) imagecolorallocate($picture, 0, 0, 255));
    imagefilledrectangle($picture, 0, $height - 10, $width - 1, $height - 1, (int) imagecolorallocate($picture, 255, 0, 0));
    $path = tempnam(sys_get_temp_dir(), 'cover').'.png';
    imagepng($picture, $path);

    return $path;
}

test('a cover keeps its edges in a box of any aspect ratio', function (int $width, int $height) {
    $canvas = new Canvas(200, 100, 1);
    expect($canvas->cover(coverPicture($width, $height), 0, 0, 200, 100))->toBeTrue();

    $image = imagecreatefromstring($canvas->png());
    expect($image)->toBeInstanceOf(GdImage::class);

    if (! $image instanceof GdImage) {
        return;
    }

    $reds = 0;
    for ($x = 0; $x < 200; $x++) {
        for ($y = 0; $y < 100; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $reds += (($rgb >> 16) & 0xFF) > 200 && ($rgb & 0xFF) < 60 ? 1 : 0;
        }
    }

    expect($reds)->toBeGreaterThan(0);
})->with([
    'wide 16:9 into 2:1' => [1600, 900],
    'tall portrait' => [300, 900],
    'very wide' => [2000, 400],
]);
