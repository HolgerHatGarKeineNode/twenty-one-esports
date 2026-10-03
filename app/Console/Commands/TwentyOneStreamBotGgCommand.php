<?php

namespace App\Console\Commands;

use App\Support\StreamBot\ChampionChat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The GG in the stream chat the moment a tournament is decided
 * (App\Support\StreamBot\ChampionChat). Scheduled every minute; once per
 * tournament, only on air and outside the quiet hours. Off unless
 * ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints the GGs the next run would post; it claims, signs,
 * sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:gg
    {--dry-run : Print the GGs the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a GG to the live stream chat (NIP-53 kind 1311) for each tournament decided in the last minutes')]
class TwentyOneStreamBotGgCommand extends Command
{
    public function handle(ChampionChat $chat): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($chat->run($now));

            return self::SUCCESS;
        }

        $due = $chat->due($now);

        foreach ($due as $tournament) {
            $this->line('--- tournament '.$tournament->id);
            $this->line($chat->compose($tournament)['content'] ?? 'no place 1');
        }

        $this->info('Dry run: '.count($due).' GG(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
