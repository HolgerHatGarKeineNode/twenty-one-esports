<?php

use App\Support\LobbyWords;

/*
 * Readable lobby names and passwords (user 2026-10-02: "ALLE generierten
 * Lobby Daten bitte mit für menschlich besser lesbaren Name und Passwort.
 * Also Wörter bitte", "Gerne an Bitcoin Wörter herangeführt"): one Bitcoin
 * word list in resources/data/lobby-words.json, read by PHP here and
 * imported by resources/js/lobbyCards.js (tests/js/lobbyCards.test.mjs).
 */

/** @return list<string> */
function lobbyWordFile(): array
{
    return json_decode((string) file_get_contents(resource_path('data/lobby-words.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('the word list holds at least 128 distinct short lowercase words, none of fees or faces', function () {
    $words = lobbyWordFile();

    expect($words)->toBe(LobbyWords::all())
        ->and(count($words))->toBeGreaterThanOrEqual(128)
        ->and(array_unique($words))->toHaveCount(count($words));

    foreach ($words as $word) {
        expect($word)->toMatch('/^[a-z]{3,8}$/')
            ->and(str_contains($word, 'fee') || str_contains($word, 'face'))->toBeFalse("'{$word}' names a fee or a face");
    }
});

test('a password is two words of the list and two digits, at most 20 characters, and fresh every time', function () {
    $words = lobbyWordFile();
    $passwords = array_map(fn (): string => LobbyWords::password(), range(1, 50));

    expect(array_unique($passwords))->toHaveCount(50);

    foreach ($passwords as $password) {
        expect($password)->toMatch('/^[a-z]{3,8}-[a-z]{3,8}-\d{2}$/')
            ->and(strlen($password))->toBeLessThanOrEqual(20);

        [$first, $second] = explode('-', $password);
        expect($words)->toContain($first)->toContain($second);
    }
});

test('a match\'s lobby name is 21, a word of the list and the match number, the same for the same match', function () {
    $words = lobbyWordFile();
    $names = array_map(fn (int $number): string => LobbyWords::matchName($number), range(1, 300));

    expect(LobbyWords::matchName(53))->toBe(LobbyWords::matchName(53))
        ->and(array_unique($names))->toHaveCount(300);

    foreach ($names as $i => $name) {
        expect($name)->toMatch('/^21-[a-z]{3,8}-'.($i + 1).'$/')
            ->and(strlen($name))->toBeLessThanOrEqual(20)
            ->and($words)->toContain(explode('-', $name)[1]);
    }

    // The word spreads over the list instead of repeating one.
    expect(count(array_unique(array_map(fn (string $name): string => explode('-', $name)[1], $names))))->toBeGreaterThan(50);

    // A number too long for a word drops the word, never a digit.
    expect(LobbyWords::matchName(12_345_678_901_234_567))->toBe('21-12345678901234567');
});

test('a tournament lobby\'s name is 21, a word, the tournament and the lobby, the same for the same lobby', function () {
    $words = lobbyWordFile();
    $names = [];

    foreach ([1, 42, 9_999] as $tournament) {
        foreach (range(1, 5) as $position) {
            $name = LobbyWords::lobbyName($tournament, $position);

            expect($name)->toMatch("/^21-[a-z]{3,8}-{$tournament}-{$position}$/")
                ->and(strlen($name))->toBeLessThanOrEqual(20)
                ->and($words)->toContain(explode('-', $name)[1])
                ->and(LobbyWords::lobbyName($tournament, $position))->toBe($name);
            $names[] = $name;
        }
    }

    expect(array_unique($names))->toHaveCount(15)
        ->and(LobbyWords::lobbyName(1_234_567_890_123, 4))->toBe('21-1234567890123-4');
});
