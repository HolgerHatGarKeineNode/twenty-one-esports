<?php

namespace App\Console\Commands;

use App\Support\StreamBot\TmnfNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's TMNF week notes on its own profile
 * (App\Support\StreamBot\TmnfNotes, plan "Trackmania und Restposten", P2): a
 * new week and its track once it is open, its winner and top 3 once it is
 * finished. New first places go to the stream chat; here only with
 * `esports.stream_bot.tmnf_notes.top_on_profile`. Scheduled every five minutes while TMNF is
 * registered. Off unless ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints the notes the next run would post; it claims, signs,
 * sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:tmnf
    {--dry-run : Print the notes the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a kind-1 note on the stream bot profile for a new TMNF week and the winner of the last one')]
class TwentyOneStreamBotTmnfCommand extends Command
{
    public function handle(TmnfNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        $due = $notes->due($now);

        foreach ($due as $item) {
            $this->line('--- tmnf week '.$item['week']->id.' '.$item['slot']);
            $this->line($notes->content($item['week'], $item['slot']));
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
