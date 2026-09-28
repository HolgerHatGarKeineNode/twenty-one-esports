<?php

namespace App\Console\Commands;

use App\Support\GameChat\GameChannels;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LeagueKey;
use App\Support\TwentyOne\RelayPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Signs and publishes the game channels (P21, NIP "Game channels") with the
 * league key: per game the kind 40 (fixed, its id is the channel) and a
 * kind 41 with the chat relays. With --mute or --hide it signs the
 * creator's moderation instead (NIP-28 kind 44 for a pubkey, kind 43 for a
 * message), which the app honours for every reader. Nothing is stored:
 * the events live on the chat relays.
 *
 * Runs daily (routes/console.php) and is safe to: the kind 40 are fixed
 * events, and a kind 41 keeps its `created_at` while it does not change, so
 * a repeat run republishes the same ids. Without the league key it refuses
 * and publishes nothing (fail closed).
 */
#[Signature('esports:game-channels
    {--mute= : npub or hex of a pubkey the channels hide for everyone (kind 44)}
    {--hide= : id of a message the channels hide for everyone (kind 43)}
    {--relays= : Comma-separated relay URLs instead of esports.chat.relays}
    {--dry-run : Print the signed events and send nothing}')]
#[Description('Publish the game channels (NIP-28 kind 40 and 41) or the league\'s moderation (kind 43, 44)')]
class GameChannelsCommand extends Command
{
    public function handle(RelayPublisher $publisher): int
    {
        $league = LeagueKey::fromConfig();

        if ($league === null) {
            $this->error('ESPORTS_LEAGUE_NSEC is not set or not a valid secret key.');

            return self::FAILURE;
        }

        if (GameChannels::creator() !== $league->pubkey()) {
            $this->error('esports.game_chat.creator names another key than the league key: the channels could not be signed.');

            return self::FAILURE;
        }

        $relays = $this->option('relays') !== null ? RelayPublisher::relayUrls($this->option('relays')) : GameChannels::relays();
        $now = now()->getTimestamp();
        $events = [];

        if ($this->option('mute') !== null || $this->option('hide') !== null) {
            $mute = $this->option('mute') !== null ? NostrKeys::toHex(trim((string) $this->option('mute'))) : null;
            $hide = $this->option('hide') !== null ? strtolower(trim((string) $this->option('hide'))) : null;

            if (($this->option('mute') !== null && $mute === null) || ($hide !== null && preg_match('/^[0-9a-f]{64}$/', $hide) !== 1)) {
                $this->error('--mute takes an npub or a hex pubkey, --hide a 64-character event id.');

                return self::FAILURE;
            }

            if ($mute !== null) {
                $events[] = $league->sign(44, [['p', $mute]], '', $now)->toArray();
            }

            if ($hide !== null) {
                $events[] = $league->sign(43, [['e', $hide]], '', $now)->toArray();
            }
        } else {
            foreach (array_keys(GameChannels::GAMES) as $game) {
                $create = GameChannels::createEvent($game);
                $signed = $league->sign(40, [], GameChannels::createContent($game), GameChannels::CREATED_AT)->toArray();

                // The id is the channel: a signer that changed anything would publish another channel.
                if ($create === null || $signed['id'] !== $create['id']) {
                    $this->error("The signed kind 40 of {$game} does not have the channel id.");

                    return self::FAILURE;
                }

                $meta = GameChannels::metadataTemplate($game);
                $events[] = $signed;
                $events[] = $league->sign(41, $meta['tags'] ?? [], $meta['content'] ?? '', $this->metadataTime($game, $meta, $now))->toArray();
                $this->line("{$game}: channel {$create['id']}");
            }
        }

        if ($this->option('dry-run')) {
            foreach ($events as $event) {
                $this->line((string) json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $this->info('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        if ($relays === []) {
            $this->error('No relays to publish to.');

            return self::FAILURE;
        }

        $everyEventTaken = true;

        foreach ($events as $event) {
            $taken = false;

            foreach ($publisher->publish($event, $relays, (float) config('esports.relay_timeout_seconds', 5)) as $result) {
                $taken = $taken || $result->accepted;
                $this->line(sprintf('kind %d %s %s', $event['kind'], $result->relay, $result->accepted ? 'ok'.($result->message !== '' ? ' ('.$result->message.')' : '') : 'failed: '.$result->message));
            }

            $everyEventTaken = $everyEventTaken && $taken;
        }

        return $everyEventTaken ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The `created_at` of a game's kind 41: the one it was first signed with
     * while its tags and content stay the same, so the daily run republishes
     * the same event (the same id; relays answer `duplicate:`) instead of a
     * new one each day; now once they change. Kept in the cache: a lost entry
     * costs one more kind 41, nothing else.
     *
     * @param  array{kind: int, tags: list<list<string>>, content: string}|null  $meta
     */
    private function metadataTime(string $game, ?array $meta, int $now): int
    {
        $key = 'game-channels:meta:'.$game;
        $hash = hash('sha256', (string) json_encode($meta));
        $held = Cache::get($key);

        if (is_array($held) && ($held['hash'] ?? null) === $hash && is_int($held['created_at'] ?? null)) {
            return $held['created_at'];
        }

        Cache::forever($key, ['hash' => $hash, 'created_at' => $now]);

        return $now;
    }
}
