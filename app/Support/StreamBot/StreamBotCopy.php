<?php

namespace App\Support\StreamBot;

use LogicException;

/**
 * Every word the stream chat bot says (P22), in one place, English only.
 *
 * Each template has one or more variants; a variant is a list of lines
 * (at most four). `:placeholder` values come from the builders
 * (StreamBotBuilders), already formatted. A line whose placeholder is null
 * is left out, so an optional fact (a prize pot, a clan count) needs no
 * extra variant. Links are always the last thing on their line: chat
 * clients take the URL up to the next space, and trailing punctuation
 * would end up inside it.
 *
 * Layout: zap.stream and zap.observer show chat text with `white-space:
 * normal`, so the line breaks collapse into spaces there (measured
 * 2026-09-27: zap.stream src/element/chat/chat-message.tsx renders the
 * content inside a plain <span>; zap.observer's chat line is
 * `whitespace-normal`). Every line therefore starts with an emoji, which
 * separates the lines when they run together. Clients that keep line
 * breaks show them as a small card.
 *
 * Rules (checked by StreamBotCopy::violations() on every message):
 * no `#` anywhere (no hashtags, a standing rule of this project), and at
 * least one direct http(s) link. Change the wording freely; keep the
 * placeholders of a template.
 */
final class StreamBotCopy
{
    /** Most lines one message may have. */
    public const MAX_LINES = 4;

    /**
     * @var array<string, list<list<string>>>
     */
    public const TEMPLATES = [
        // Upcoming tournaments, open for sign-up.
        'tournament_signup' => [
            ['🏆 Sign-up is open: :name', '🎮 :game · starts :starts', '🪑 :spots:pot', '👉 Grab your spot: :url'],
            ['📣 New on the board: :name', '🗓️ :starts · :game', '🪑 :spots:pot', '👉 :url'],
        ],
        'tournament_last_call' => [
            ['⏳ Last call for :name', '🚪 Sign-up closes in :left, :open still free', '👉 :url'],
            ['⏳ Only :left left to join :name', '🪑 :open still free · :game', '👉 Sign up: :url'],
        ],

        // Happening now.
        'tournament_live' => [
            ['🔴 :name is running right now', '📺 Brackets, boards and results live on the big-screen view', '👉 :url'],
            ['📺 :name is live: every match as it happens', '👉 :url'],
        ],
        'live_game' => [
            ['♟️ Live on the board: :white vs :black', '⏱️ :mode, in progress', '👀 Watch: :url'],
            ['👀 :white and :black are playing :mode right now', '👉 Spectate: :url'],
        ],
        'live_games' => [
            ['🔴 :count games are live right now', '👀 Pick a board and watch: :url'],
        ],
        'live_series' => [
            ['🏁 :game: :home vs :away, best of :best_of', '👉 Follow the match: :url'],
        ],

        // Results and pride.
        'tournament_winner' => [
            ['🥇 :winner won :name', '🎉 GG to everyone who played', '👉 Bracket and results: :url'],
            ['🏆 Champion of :name: :winner', '👉 See how it went: :url'],
        ],
        'rank_up' => [
            ['📈 Rank up! :player reached :tier in :game', '👉 :url'],
            ['🎖️ :player climbed to :tier (:game)', '👉 Profile and badges: :url'],
        ],
        'new_clan' => [
            ['🛡️ New clan: :name [:tag]', '🤝 Say hi and challenge them', '👉 :url'],
            ['🛡️ Welcome to the league, :name [:tag]!', '👉 Meet the clan: :url'],
        ],
        'ladder_top' => [
            ['🪜 Blitz ladder right now', ':podium', '👉 Full ladder: :url'],
        ],
        'season_live' => [
            ['⛏️ The season is live: rated wins mine blocks on the league chain', '👉 Watch the chain grow: :url'],
        ],
        'season_countdown' => [
            ['⛏️ Block 0 in :left', '🔓 Then rated play and mining start, until then every game is casual', '👉 :url'],
        ],
        'stats' => [
            ['📊 So far: :players players, :clans clans, :games games played', '👉 Join them: :url'],
        ],

        // What you can do.
        'play_blitz' => [
            ['⚡ Fancy a quick game? Blitz chess 5+3 in the lobby', '👉 :url'],
            ['♟️ One blitz game before bed? 5+3, right in the browser', '👉 :url'],
        ],
        'daily_chess' => [
            ['📬 No time for blitz? Daily chess: one move a day, whenever it suits you', '👉 Challenge someone: :url'],
        ],
        'clan_challenge' => [
            ['🚗 :games: clans challenge clans in best-of series', '👉 Send a challenge: :url'],
        ],
        'invite_friend' => [
            ['💌 Play a friend: make an invite link in the lobby and send it anywhere', '👉 :url'],
        ],
        'clans' => [
            ['🛡️ Better together: join a clan or start your own', '👥 :count clans are already in', '👉 :url'],
        ],
        'badges' => [
            ['🎖️ Every rank comes with a badge you can show on your Nostr profile', '👉 Start climbing: :url'],
        ],
        'all_games' => [
            ['🎮 :games: every game and mode on one page', '👉 :url'],
        ],
        'login' => [
            ['🔑 Log in with Nostr or Google and you are in', '👉 :url'],
        ],
        'zap' => [
            ['⚡ Enjoying the stream? Zap it right here, every sat says thanks', '🌐 More on the site: :url'],
        ],
    ];

    /**
     * One variant of a template with its values filled in, lines joined
     * with a newline; lines whose placeholder is null are left out.
     *
     * @param  array<string, string|int|null>  $values
     */
    public static function render(string $template, int $variant, array $values): string
    {
        $variants = self::TEMPLATES[$template] ?? throw new LogicException("Unknown stream bot template [{$template}].");
        $lines = $variants[$variant % count($variants)];
        $out = [];

        foreach ($lines as $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            $replace = [];
            $skip = false;

            foreach ($matches[1] as $name) {
                if (! array_key_exists($name, $values)) {
                    throw new LogicException("Stream bot template [{$template}] needs the value [{$name}].");
                }

                if ($values[$name] === null) {
                    $skip = true;

                    break;
                }

                $replace[':'.$name] = (string) $values[$name];
            }

            if (! $skip) {
                $out[] = strtr($line, $replace);
            }
        }

        return implode("\n", $out);
    }

    public static function variants(string $template): int
    {
        return count(self::TEMPLATES[$template] ?? []);
    }

    /**
     * A name from the database (a player, clan or tournament) made safe for
     * the chat: one line, no `#` (it would render as a hashtag), no links or
     * `nostr:` references of its own, at most `$max` characters. Empty when
     * nothing is left.
     */
    public static function clean(?string $name, int $max = 40): string
    {
        $name = (string) $name;
        $name = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S*|\bwww\.\S+|\bnostr:\S+~iu', ' ', $name);
        $name = str_replace('#', '', $name);
        $name = trim((string) preg_replace('/[\s\p{Cc}\p{Cf}]+/u', ' ', $name));

        return mb_strlen($name) > $max ? rtrim(mb_substr($name, 0, $max - 1)).'…' : $name;
    }

    /**
     * What is wrong with a message, empty when nothing: a `#` (hashtag), a
     * `t` tag, no http(s) link, too many lines, an empty text.
     *
     * @param  list<list<string>>  $tags
     * @return list<string>
     */
    public static function violations(string $content, array $tags = []): array
    {
        $problems = [];

        if (trim($content) === '') {
            $problems[] = 'empty';
        }

        if (str_contains($content, '#')) {
            $problems[] = 'contains # (hashtag)';
        }

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 't') {
                $problems[] = 't tag';
            }
        }

        if (preg_match('~https?://\S+~', $content) !== 1) {
            $problems[] = 'no link';
        }

        if (count(explode("\n", $content)) > self::MAX_LINES) {
            $problems[] = 'more than '.self::MAX_LINES.' lines';
        }

        return $problems;
    }
}
