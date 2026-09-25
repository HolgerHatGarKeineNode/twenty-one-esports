<?php

use App\Support\Nostr\Blockpile;

/*
| GeneratedAvatar.dc.html: "An implementation must produce exactly these
| values." The fixture holds the spec's eight sample keys, one key per
| palette and light, three keys that take the "fewer than 5 bits" flip, and
| boundary keys, each with the SVG of the reference implementation
| (scratchpad p10a/genav.py, which matched all 502 vectors of ref.json).
*/

/**
 * @return array<string, array{string, array{why: string, palette: string, mask: int, swap: bool, svg: string}}>
 */
function blockpileVectors(): array
{
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/blockpile-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    $cases = [];
    foreach ($vectors as $pubkey => $vector) {
        $cases[$vector['why'].' '.substr($pubkey, 0, 8)] = [$pubkey, $vector];
    }

    return $cases;
}

test('Blockpile draws exactly the reference SVG', function (string $pubkey, array $vector) {
    expect(Blockpile::svg($pubkey))->toBe($vector['svg']);
})->with(blockpileVectors());

test('Blockpile picks the reference palette, mask and light', function (string $pubkey, array $vector) {
    expect(Blockpile::describe($pubkey))->toBe(['palette' => $vector['palette'], 'mask' => $vector['mask'], 'swap' => $vector['swap']]);
})->with(blockpileVectors());

test('Blockpile refuses anything but a lowercase hex pubkey', function (string $key) {
    Blockpile::svg($key);
})->with([
    'too short' => [str_repeat('a', 63)],
    'uppercase' => [str_repeat('A', 64)],
    'npub' => ['npub1qe7xr9shkgqyzyvqvzq9ccsfysj2mqjrnwppc7gq0ql8qvnp8t0qwpk7q2'],
])->throws(InvalidArgumentException::class);
