<?php

namespace App\Console\Commands;

use App\Support\StreamBot\FreePlaceNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's free-places reminders on its own profile
 * (App\Support\StreamBot\FreePlaceNotes, P49). Scheduled every five
 * minutes; each run posts at most `esports.stream_bot.free_places.per_run`
 * notes. Off unless ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC
 * are set.
 *
 * `--dry-run` prints the notes the next run would post; it claims, signs,
 * sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:free-places
    {--dry-run : Print the notes the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a kind-1 note on the stream bot profile naming the free places of tournaments still open for sign-up')]
class TwentyOneStreamBotFreePlacesCommand extends Command
{
    public function handle(FreePlaceNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        $due = $notes->due($now);

        foreach ($due as $item) {
            $this->line('--- tournament '.$item['tournament']->id.' slot '.$item['slot']);
            $this->line($notes->content($item['tournament'], $item['free'], $item['places'], $now));
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
