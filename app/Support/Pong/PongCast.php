<?php

namespace App\Support\Pong;

/**
 * Proof of Pong's cast (plan "Proof of Pong", P3): the figures a player picks as their paddle and the bots of the
 * four levels, read from the one source both sides share, resources/js/pong/cast.json (the browser imports it in
 * resources/js/pong/cast.js). An entry: id, name and tagline (English keys, translated through __()), its paddle's
 * skin (two colours and a pattern), the soundboard clips of a goal and a win (files of Hyperbitcoinization's
 * soundboard, public/hyper/s), and for a bot its level and arena.
 *
 * The server only needs the ids (a live match takes a player's pick only if it is one of them) and the translated
 * texts for the pages.
 */
final class PongCast
{
    /** @var array{players: list<array<string, mixed>>, bots: list<array<string, mixed>>, ticker: list<string>, arenas: list<string>}|null */
    private static ?array $data = null;

    /**
     * @return array{players: list<array<string, mixed>>, bots: list<array<string, mixed>>, ticker: list<string>, arenas: list<string>}
     */
    public static function data(): array
    {
        return self::$data ??= json_decode((string) file_get_contents(resource_path('js/pong/cast.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The ids a player may pick.
     *
     * @return list<string>
     */
    public static function playerIds(): array
    {
        return array_column(self::data()['players'], 'id');
    }

    public static function isPlayer(?string $id): bool
    {
        return $id !== null && in_array($id, self::playerIds(), true);
    }

    /**
     * The players' figures for a page: id, translated name and tagline.
     *
     * @return list<array{id: string, name: string, tagline: string}>
     */
    public static function players(): array
    {
        return array_map(fn (array $figure): array => [
            'id' => $figure['id'],
            'name' => __($figure['name']),
            'tagline' => __($figure['tagline']),
        ], self::data()['players']);
    }

    /**
     * The bots, in the order of the lobby: id, level, translated name and tagline.
     *
     * @return list<array{id: string, level: int, name: string, tagline: string}>
     */
    public static function bots(): array
    {
        return array_map(fn (array $bot): array => [
            'id' => $bot['id'],
            'level' => (int) $bot['level'],
            'name' => __($bot['name']),
            'tagline' => __($bot['tagline']),
        ], self::data()['bots']);
    }

    /**
     * A bot by id, or the level's own bot (the last of that level: level 4 is Lagarde, the end boss).
     *
     * @return array{id: string, level: int, name: string, tagline: string}
     */
    public static function bot(?string $id, int $level = 1): array
    {
        $bots = self::bots();

        foreach ($bots as $bot) {
            if ($bot['id'] === $id) {
                return $bot;
            }
        }

        $ofLevel = array_values(array_filter($bots, fn (array $bot): bool => $bot['level'] === $level));

        return $ofLevel[count($ofLevel) - 1] ?? $bots[0];
    }

    /**
     * @return list<string>
     */
    public static function botIds(): array
    {
        return array_column(self::data()['bots'], 'id');
    }

    /**
     * Every text of the cast translated, keyed by its English key: names, taglines and the announcer's ticker lines.
     * The pages hand it to the scripts, which look a figure's texts up by their keys.
     *
     * @return array<string, string>
     */
    public static function texts(): array
    {
        $keys = [];

        foreach ([...self::data()['players'], ...self::data()['bots']] as $figure) {
            $keys[] = $figure['name'];
            $keys[] = $figure['tagline'];
        }

        $keys = [...$keys, ...self::data()['ticker']];

        return array_combine($keys, array_map(fn (string $key): string => __($key), $keys));
    }
}
