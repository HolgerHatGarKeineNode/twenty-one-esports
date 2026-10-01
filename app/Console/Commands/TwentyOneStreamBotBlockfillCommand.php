<?php

namespace App\Console\Commands;

use App\Support\StreamBot\BlockfillNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's Blockfill week notes on its own profile
 * (App\Support\StreamBot\BlockfillNotes, plan "Blockfill", P6): a new week
 * once it is open, its winner and top 3 once it is finished. Scheduled every
 * five minutes while Blockfill is registered. Off unless
 * ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints the notes the next run would post; it claims, signs,
 * sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:blockfill
    {--dry-run : Print the notes the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a kind-1 note on the stream bot profile for a new Blockfill week and for the winner of the last one')]
class TwentyOneStreamBotBlockfillCommand extends Command
{
    public function handle(BlockfillNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        $due = $notes->due($now);

        foreach ($due as $item) {
            $this->line('--- blockfill week '.$item['week']->id.' '.$item['slot']);
            $this->line($notes->content($item['week'], $item['slot']));
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
