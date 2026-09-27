<?php

namespace App\Console\Commands;

use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamBot;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\StreamCoordinates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream chat bot (P22, App\Support\StreamBot\StreamBot). Scheduled
 * every minute; each run decides itself whether to post, so the schedule
 * is not the cadence. Off unless ESPORTS_STREAM_BOT_ENABLED and
 * ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints why it would or would not post now and the next
 * messages it would post with the current data; it signs and sends nothing
 * and writes nothing, with or without the flag and the key.
 * `--profile` publishes the bot's kind 0 (`bot: true`, NIP-24) to the
 * stream relays; with `--dry-run` it only prints it.
 */
#[Signature('twentyone:stream-bot
    {--dry-run : Print the verdict for now and the next messages; send and store nothing}
    {--count=10 : How many messages the dry run prints}
    {--profile : Publish the bot profile (kind 0, bot: true) to the stream relays instead of chatting}')]
#[Description('Post the next engagement message to the live stream chat (NIP-53 kind 1311) when the cadence allows it')]
class TwentyOneStreamBotCommand extends Command
{
    public function handle(StreamBot $bot, StreamBotPublisher $publisher): int
    {
        if ($this->option('profile')) {
            return $this->profile($publisher);
        }

        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($bot->run($now));

            return self::SUCCESS;
        }

        $key = LeagueKey::streamBot();
        $stream = StreamCoordinates::fromConfig();
        $blocker = $bot->blocker($now, $key, $stream, readChat: false);

        $this->line('Stream: '.($stream?->address() ?? 'none'));
        $this->line('Bot key: '.($key === null ? 'none' : NostrKeys::hexToNpub($key->pubkey())));
        $this->line('Now: '.($blocker === null ? 'would post' : 'would not post, '.$blocker));
        $this->line('Posts today: '.$bot->postsToday($now).' of '.(int) config('esports.stream_bot.daily_cap', 24));
        $this->newLine();

        $messages = $bot->preview($now, max(1, (int) $this->option('count')));

        foreach ($messages as $index => $message) {
            $this->line(sprintf('--- %d. %s (%s)', $index + 1, $message->builder, $message->factKey));
            $this->line($message->content);
            $this->newLine();
        }

        $this->info('Dry run: '.count($messages).' message(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }

    private function profile(StreamBotPublisher $publisher): int
    {
        $key = LeagueKey::streamBot();
        $stream = StreamCoordinates::fromConfig();

        if ($key === null) {
            $this->error('ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key.');

            return self::FAILURE;
        }

        // The bot may chat under the account's own key; then its kind 0 would replace the
        // account's profile, so the account keeps the one `twentyone:profile` publishes.
        if ($key->pubkey() === NostrKeys::toHex((string) config('twentyone.nostr.npub'))) {
            $this->error('The bot signs with the account key; its profile would replace the account profile. Publish that one with twentyone:profile.');

            return self::FAILURE;
        }

        /** @var array{name: string, about: string, picture: string} $profile */
        $profile = config('esports.stream_bot.profile');
        $content = json_encode([
            'name' => $profile['name'],
            'display_name' => $profile['name'],
            'about' => $profile['about'],
            'picture' => $profile['picture'],
            'website' => rtrim((string) config('app.url'), '/'),
            'bot' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $event = $key->sign(0, [['alt', 'Profile of the TWENTY ONE Esports stream chat bot']], $content, CarbonImmutable::now()->getTimestamp());

        $this->line('Bot key: '.NostrKeys::hexToNpub($key->pubkey()));

        if ($this->option('dry-run')) {
            $this->line($event->toJson());
            $this->info('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        if ($stream === null) {
            $this->error('The stream has no relays to publish to (twentyone.stream.relays).');

            return self::FAILURE;
        }

        $taken = false;

        foreach ($publisher->publish($event->toArray(), $stream->relays) as $result) {
            $taken = $taken || $result->accepted;
            $this->line($result->relay.' '.($result->accepted ? 'ok' : 'failed: '.$result->message));
        }

        return $taken ? self::SUCCESS : self::FAILURE;
    }
}
