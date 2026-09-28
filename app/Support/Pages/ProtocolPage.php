<?php

namespace App\Support\Pages;

use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LeagueKey;
use swentel\nostr\Key\Key;

/**
 * What the open protocol page (P29, /protocol) says and shows, in one
 * place: the event kinds (read from the NIP itself, docs/nips/esports.md,
 * so the table is the document's), the league's public keys and relays
 * (from the config, as the server uses them), and commands to check it all
 * with `nak`. A key that is not set up here is shown as such, never
 * invented.
 */
final class ProtocolPage
{
    /** The NIP document of the league, in this repository. */
    public const NIP_PATH = 'docs/nips/esports.md';

    /** The public repository; the footer's "Open source" link. */
    public const SOURCE_URL = 'https://github.com/HolgerHatGarKeineNode/twenty-one-esports';

    public static function nipUrl(): string
    {
        return self::SOURCE_URL.'/blob/master/'.self::NIP_PATH;
    }

    /**
     * The two tables under "## Kinds" of the NIP: the league's own kinds and
     * the kinds of other NIPs it uses. Markdown is reduced to text (code
     * marks, bold, links); every cell stays as the document writes it.
     *
     * @return array{own: list<array{kind: string, class: string, name: string, signer: string}>, reused: list<array{kind: string, nip: string, use: string, signer: string}>}
     */
    public static function kinds(): array
    {
        $path = base_path(self::NIP_PATH);
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        $section = str($text)->after("\n## Kinds\n")->before("\n## ")->toString();
        $tables = [];
        $current = [];

        foreach (explode("\n", $section) as $line) {
            if (str_starts_with($line, '|')) {
                $current[] = $line;
            } elseif ($current !== []) {
                $tables[] = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $tables[] = $current;
        }

        $rows = fn (array $table): array => array_values(array_map(
            fn (string $line): array => array_map(self::plain(...), array_slice(array_map('trim', explode('|', $line)), 1, -1)),
            array_slice($table, 2),
        ));

        return [
            'own' => array_map(fn (array $cells) => ['kind' => $cells[0] ?? '', 'class' => $cells[1] ?? '', 'name' => $cells[2] ?? '', 'signer' => $cells[3] ?? ''], isset($tables[0]) ? $rows($tables[0]) : []),
            'reused' => array_map(fn (array $cells) => ['kind' => $cells[0] ?? '', 'nip' => $cells[1] ?? '', 'use' => $cells[2] ?? '', 'signer' => $cells[3] ?? ''], isset($tables[1]) ? $rows($tables[1]) : []),
        ];
    }

    /**
     * The league's public keys: what each signs, and its npub when this
     * server has it (null: not set up here).
     *
     * @return list<array{name: string, signs: string, npub: string|null}>
     */
    public static function keys(): array
    {
        $npub = fn (?string $hex): ?string => $hex === null ? null : NostrKeys::hexToNpub($hex);
        $fromSecret = function (mixed $secret): ?string {
            $hex = NostrKeys::secretToHex(is_string($secret) ? $secret : null);

            return $hex === null ? null : (new Key)->getPublicKey($hex);
        };
        $stream = config('twentyone.nostr.npub');

        return [
            ['name' => __('League key'), 'signs' => __('ladders, tournaments and their calendar, attestations of every result, the season chain'), 'npub' => $npub(LeagueKey::fromConfig()?->pubkey())],
            ['name' => __('Trust key'), 'signs' => __('trust ranks and the anchor list'), 'npub' => $npub(LeagueKey::trust()?->pubkey())],
            ['name' => __('Badge key'), 'signs' => __('rank badges and their awards'), 'npub' => $npub(LeagueKey::badge()?->pubkey())],
            ['name' => __('Notification key'), 'signs' => __('the encrypted notifications sent to players'), 'npub' => $npub($fromSecret(config('esports.notifications.nsec')))],
            ['name' => __('Stream key'), 'signs' => __('the 24/7 stream and its live events'), 'npub' => is_string($stream) && str_starts_with($stream, 'npub1') ? $stream : null],
            ['name' => __('Stream bot'), 'signs' => __('chat lines and tournament notes on the stream'), 'npub' => $npub(LeagueKey::streamBot()?->pubkey())],
        ];
    }

    /**
     * @return array{league: list<string>, chat: list<string>}
     */
    public static function relays(): array
    {
        return [
            'league' => array_values((array) config('esports.relays', [])),
            'chat' => array_values((array) config('esports.chat.relays', [])),
        ];
    }

    /**
     * `nak` commands against this league: its key and its first relay, with
     * placeholders where this server has none.
     *
     * @return list<array{0: string, 1: string}> what it shows, the command
     */
    public static function commands(): array
    {
        $league = LeagueKey::fromConfig()?->pubkey();
        $author = $league === null ? '<league-npub>' : NostrKeys::hexToNpub($league);
        $relay = self::relays()['league'][0] ?? 'wss://<relay>';

        return [
            [__('The tournaments as calendar events'), "nak req -k 31923 -a {$author} {$relay}"],
            [__('The ladders of the current season'), "nak req -k 32152 -a {$author} {$relay}"],
            [__('The latest attestations of results'), "nak req -k 2154 -a {$author} -l 5 {$relay}"],
            [__('Check every signature of what came back'), "nak req -k 2154 -a {$author} -l 5 {$relay} | nak verify"],
        ];
    }

    private static function plain(string $cell): string
    {
        $cell = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $cell);

        return trim(str_replace(['`', '**'], '', $cell));
    }
}
