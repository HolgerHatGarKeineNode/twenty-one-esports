<?php

/*
 * config/esports.php read under a given environment. phpunit.xml forces the
 * relay variables empty, so each case sets (or removes) them itself and puts
 * the old values back; `null` means "not set at all".
 */

/**
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function esportsConfigUnder(array $env): array
{
    $saved = [];

    foreach ($env as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    try {
        return require config_path('esports.php');
    } finally {
        foreach ($saved as $key => [$server, $envConst, $process]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            if ($envConst === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envConst;
            }

            putenv($process === false ? $key : $key.'='.$process);
        }
    }
}

// relay.damus.io is left out of the chat on purpose: it takes gift wraps but serves them to no one (P5d relay smoke).
const PUBLIC_CHAT_RELAYS = ['wss://nos.lol', 'wss://relay.primal.net', 'wss://nostr.mom'];
const PUBLIC_LEAGUE_RELAYS = ['wss://relay.primal.net', 'wss://nostr.mom', 'wss://relay.damus.io', 'wss://nos.lol', 'wss://relay.snort.social', 'wss://offchain.pub', 'wss://nostr.bitcoiner.social', 'wss://nostr.oxtr.dev'];
const NDAK_TEST_BED = ['ws://127.0.0.1:7777', 'ws://127.0.0.1:7780', 'ws://127.0.0.1:7782'];

test('an unset or empty relay key takes the environment default: public sets in production, the test bed locally, nothing in testing', function (string $environment, ?string $value, array $chat, array $league) {
    $config = esportsConfigUnder(['APP_ENV' => $environment, 'ESPORTS_CHAT_RELAYS' => $value, 'ESPORTS_RELAYS' => $value]);

    expect($config['chat']['relays'])->toBe($chat)
        ->and($config['relays'])->toBe($league);
})->with([
    // 2026-09-28: ESPORTS_RELAYS left empty on prod sent no calendar event to any relay.
    'production, unset' => ['production', null, PUBLIC_CHAT_RELAYS, PUBLIC_LEAGUE_RELAYS],
    'production, empty' => ['production', '', PUBLIC_CHAT_RELAYS, PUBLIC_LEAGUE_RELAYS],
    'staging, empty' => ['staging', '', PUBLIC_CHAT_RELAYS, PUBLIC_LEAGUE_RELAYS],
    'local, unset' => ['local', null, NDAK_TEST_BED, NDAK_TEST_BED],
    'testing, empty' => ['testing', '', [], []],
]);

test('a set relay key wins over the defaults, and ESPORTS_CHAT_RELAYS=off switches the chat off', function () {
    $league = esportsConfigUnder(['APP_ENV' => 'production', 'ESPORTS_CHAT_RELAYS' => null, 'ESPORTS_RELAYS' => 'wss://relay.league.example']);
    $own = esportsConfigUnder(['APP_ENV' => 'production', 'ESPORTS_CHAT_RELAYS' => ' wss://chat.example , wss://two.example', 'ESPORTS_RELAYS' => null]);
    $off = esportsConfigUnder(['APP_ENV' => 'production', 'ESPORTS_CHAT_RELAYS' => 'off', 'ESPORTS_RELAYS' => null]);

    expect($league['chat']['relays'])->toBe(PUBLIC_CHAT_RELAYS)
        ->and($league['relays'])->toBe(['wss://relay.league.example'])
        ->and($own['chat']['relays'])->toBe(['wss://chat.example', 'wss://two.example'])
        ->and($own['relays'])->toBe(PUBLIC_LEAGUE_RELAYS)
        ->and($off['chat']['relays'])->toBe([]);
});
