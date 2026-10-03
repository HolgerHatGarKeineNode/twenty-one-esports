<?php

namespace App\Console\Commands;

use App\Support\StreamBot\ChampionNotes;
use App\Support\StreamBot\ProfileNotes;
use App\Support\StreamBot\StreamBotCopy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's champion notes on its own profile
 * (App\Support\StreamBot\ChampionNotes). Scheduled every five minutes;
 * each run posts at most `esports.stream_bot.champion_notes.per_run`
 * notes, one per newly finished tournament with a single champion. Off
 * unless ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints the notes the next run would post, without their
 * slide; it claims, renders, signs, sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:champions
    {--dry-run : Print the notes the next run would post; claim, render, sign, send and store nothing}')]
#[Description('Post a kind-1 note on the stream bot profile about the champion of each newly finished tournament')]
class TwentyOneStreamBotChampionsCommand extends Command
{
    public function handle(ChampionNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        $due = $notes->due($now);
        $variant = ProfileNotes::nextVariant(ChampionNotes::NOTE_TYPE, StreamBotCopy::variants(ChampionNotes::TEMPLATE));

        foreach ($due as $tournament) {
            $this->line('--- tournament '.$tournament->id);
            $this->line($notes->compose($tournament, $variant)['content'] ?? 'no single champion');
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was rendered, signed, sent or stored.');

        return self::SUCCESS;
    }
}
