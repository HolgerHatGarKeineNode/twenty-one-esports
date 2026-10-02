<?php

namespace App\Console\Commands;

use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\ProfileNotes;
use App\Support\StreamBot\StreamBotCopy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's pride notes (App\Support\StreamBot\PrideNotes).
 * Scheduled every five minutes; a type posts in its slot, at most once a
 * day and only when its text changed.
 *
 * `--dry-run` prints the note of every type as it would read now, in the
 * wording its next note takes, whatever the slot; it claims, renders, signs, sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:pride
    {--dry-run : Print every type\'s note as it would read now; claim, sign, send and store nothing}')]
#[Description('Post the dynamic stream slides as notes on the stream bot profile, the players they name tagged')]
class TwentyOneStreamBotPrideCommand extends Command
{
    public function handle(PrideNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        foreach (PrideNotes::TYPES as $type => [$name]) {
            $note = $notes->compose($type, ProfileNotes::nextVariant(PrideNotes::noteType($type), StreamBotCopy::variants(PrideNotes::TYPES[$type][2])));
            $this->line('--- '.$name.($note === null ? ': nothing to show' : ', tags: '.count($note['tags'])));

            if ($note !== null) {
                $this->line($note['body']);
            }

            $this->newLine();
        }

        $due = $notes->due($now);
        $this->info('Dry run: open slots now: '.($due === [] ? 'none' : implode(', ', array_map(fn (int $type): string => PrideNotes::TYPES[$type][0], array_keys($due)))).'. Nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
