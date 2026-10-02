<?php

use App\Support\Tmnf\TmnfManialinks;

/*
|--------------------------------------------------------------------------
| The in-game overlay as TMF ManiaLink XML
|--------------------------------------------------------------------------
|
| What the league sends with SendDisplayManialinkPage(ToLogin): the week
| widget (board and the viewer's own line), the footer and the finish note.
| Parsed back with DOM: well-formed, every element inside the screen
| (posn x -64..64, y -48..48, frames added up), names as plain text.
|
*/

/**
 * @return list<array{tag: string, x: float, y: float, w: float, h: float, text: string|null}>
 */
function tmnfOverlayElements(string $xml): array
{
    $document = new DOMDocument;
    expect(@$document->loadXML($xml))->toBeTrue();

    $elements = [];
    $walk = function (DOMElement $node, float $x, float $y) use (&$walk, &$elements): void {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            [$px, $py] = array_map(floatval(...), array_slice([...explode(' ', $child->getAttribute('posn') ?: '0 0 0'), 0, 0], 0, 2));
            [$w, $h] = array_map(floatval(...), array_slice([...explode(' ', $child->getAttribute('sizen') ?: '0 0'), 0, 0], 0, 2));

            if (in_array($child->tagName, ['quad', 'label'], true)) {
                $elements[] = ['tag' => $child->tagName, 'x' => $x + $px, 'y' => $y + $py, 'w' => $w, 'h' => $h, 'halign' => $child->getAttribute('halign') ?: 'left',
                    'text' => $child->tagName === 'label' ? $child->getAttribute('text') : null];
            }

            $walk($child, $x + $px, $y + $py);
        }
    };
    $walk($document->documentElement, 0.0, 0.0);

    return $elements;
}

function tmnfOverlayInside(array $element): bool
{
    $left = match ($element['halign']) {
        'right' => $element['x'] - $element['w'],
        'center' => $element['x'] - $element['w'] / 2,
        default => $element['x'],
    };

    // A background may run a little past the right edge, as the stock HUD boxes do (their rounded end off screen).
    return $element['x'] >= -64 && $element['x'] <= 64 && $element['y'] >= -48 && $element['y'] <= 48
        && $left >= -64 && $left + $element['w'] <= 65.5 && $element['y'] - $element['h'] >= -48;
}

function tmnfOverlayRows(int $count, string $name = 'Satoshi'): array
{
    return array_map(fn (int $place): array => ['place' => $place, 'name' => $name, 'time' => '0:25.'.(900 + $place)], range(1, $count));
}

test('the page is well-formed XML with the league\'s own manialink ids, never one of the stock HUD', function () {
    $xml = TmnfManialinks::page(
        TmnfManialinks::board('TWENTY ONE', 41, tmnfOverlayRows(3)),
        TmnfManialinks::own(7, '0:26.100', true),
        TmnfManialinks::footer('esports.einundzwanzig.space'),
        TmnfManialinks::note('New personal best'),
    );

    $document = new DOMDocument;

    expect(@$document->loadXML($xml))->toBeTrue()
        ->and($document->documentElement->tagName)->toBe('manialinks')
        ->and(array_map(fn (DOMElement $node): string => $node->getAttribute('id'), iterator_to_array($document->getElementsByTagName('manialink'))))
        ->toBe([TmnfManialinks::ID_BOARD, TmnfManialinks::ID_OWN, TmnfManialinks::ID_FOOTER, TmnfManialinks::ID_NOTE])
        ->and([TmnfManialinks::ID_BOARD, TmnfManialinks::ID_OWN, TmnfManialinks::ID_FOOTER, TmnfManialinks::ID_NOTE])->each->toStartWith(TmnfManialinks::ID_PREFIX);
});

test('the widget shows the server, the week and at most five rows, each with place, name and time', function () {
    $texts = array_column(tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::board('TWENTY ONE', 41, tmnfOverlayRows(8)))), 'text');

    expect($texts)->toContain('TWENTY ONE · Week 41')
        ->and(array_values(array_filter($texts, fn (?string $text): bool => $text === 'Satoshi')))->toHaveCount(5)
        ->and($texts)->toContain('5.', '0:25.905')
        ->not->toContain('6.', '0:25.906');
});

test('an empty board says so instead of rows', function () {
    $texts = array_column(tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::board('TWENTY ONE', 41, []))), 'text');

    expect($texts)->toContain('No times yet this week')->not->toContain('1.');
});

test('the own line: place and best, no time yet, or not linked', function (?int $place, ?string $time, bool $linked, string $line) {
    $texts = array_filter(array_column(tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::own($place, $time, $linked))), 'text'));

    expect(implode(' ', $texts))->toBe($line);
})->with([
    'placed' => [7, '0:26.100', true, 'You #7 0:26.100'],
    'no time yet' => [null, null, true, 'You no time this week'],
    'not linked' => [null, null, false, 'You not linked yet'],
]);

test('every element of widget, own line, footer and note lies on the screen', function () {
    $xml = TmnfManialinks::page(
        TmnfManialinks::board('TWENTY ONE', 41, tmnfOverlayRows(5, str_repeat('W', 40))),
        TmnfManialinks::own(12, '1:02:03.456', true),
        TmnfManialinks::footer('esports.einundzwanzig.space'),
        TmnfManialinks::note('Not linked yet – link your login on the site'),
    );
    $elements = tmnfOverlayElements($xml);

    expect($elements)->not->toBeEmpty()
        ->and(array_values(array_filter($elements, fn (array $element): bool => ! tmnfOverlayInside($element))))->toBe([]);
});

test('a name cannot inject markup: XML is escaped and stays the text of its label', function () {
    $name = '"/><quad posn="0 0 0" sizen="128 96" bgcolor="F00F"/><label text="&amp;';
    $xml = TmnfManialinks::page(TmnfManialinks::board('TWENTY ONE', 41, [['place' => 1, 'name' => $name, 'time' => '0:25.000']]));
    $elements = tmnfOverlayElements($xml);

    expect(array_column(array_filter($elements, fn (array $element): bool => $element['tag'] === 'quad'), 'w'))->not->toContain(128.0)
        ->and(count(array_filter($elements, fn (array $element): bool => $element['tag'] === 'label')))->toBe(1 + 3)
        ->and(array_column($elements, 'text'))->toContain(mb_substr($name, 0, 14).'…');
});

test('TMNF formatting codes in a name are stripped, so no colour, link or style reaches the client', function (string $name, string $shown) {
    $texts = array_column(tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::board('TWENTY ONE', 41, [['place' => 1, 'name' => $name, 'time' => '0:25.000']]))), 'text');

    expect($texts)->toContain($shown)
        ->and(implode('', array_filter($texts)))->not->toContain('$');
})->with([
    'bold and colour' => ['$o$f00Satoshi', 'Satoshi'],
    'short colour and reset' => ['$0fSat$zoshi', 'Satoshi'],
    'link with target' => ['$l[http://evil.example]Satoshi$l', 'Satoshi'],
    'manialink link' => ['$h[evil]Sat$hoshi', 'Satoshi'],
    'escaped dollar' => ['Sat$$oshi', 'Satoshi'],
    'trailing dollar' => ['Satoshi$', 'Satoshi'],
    'wide and shadow' => ['$w$sSa$ntoshi$i$t$g', 'Satoshi'],
]);

test('control characters and line breaks in a name become one space, long names end in an ellipsis', function () {
    $texts = array_column(tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::board('TWENTY ONE', 41, [
        ['place' => 1, 'name' => "Sato\u{202E}shi\nNakamoto", 'time' => '0:25.000'],
        ['place' => 2, 'name' => 'Hal Finney the first receiver', 'time' => '0:26.000'],
    ]))), 'text');

    expect($texts)->toContain('Sato shi Nakam…', 'Hal Finney the…');
});

test('the note is centred at the top and the footer sits small in the bottom left', function () {
    $note = tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::note('New personal best')));
    $footer = tmnfOverlayElements(TmnfManialinks::page(TmnfManialinks::footer('esports.einundzwanzig.space')));
    $noteLabel = array_values(array_filter($note, fn (array $element): bool => $element['text'] === 'New personal best'))[0];

    expect($noteLabel['x'])->toBe(0.0)->and($noteLabel['halign'])->toBe('center')->and($noteLabel['y'])->toBeGreaterThan(40.0)
        ->and($footer)->toHaveCount(1)
        ->and($footer[0]['text'])->toBe('esports.einundzwanzig.space')
        ->and($footer[0]['x'])->toBeLessThan(-60.0)->and($footer[0]['y'])->toBeLessThan(-44.0);
});

test('an empty manialink with an id removes that manialink only', function () {
    $document = new DOMDocument;
    $document->loadXML(TmnfManialinks::page(TmnfManialinks::remove(TmnfManialinks::ID_NOTE)));

    expect($document->getElementsByTagName('manialink')->item(0)?->getAttribute('id'))->toBe(TmnfManialinks::ID_NOTE)
        ->and($document->getElementsByTagName('manialink')->item(0)?->childNodes->length)->toBe(0);
});
