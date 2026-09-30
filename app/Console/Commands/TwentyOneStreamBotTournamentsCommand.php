<?php

namespace App\Console\Commands;

use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\TournamentNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's tournament notes on its own profile
 * (App\Support\StreamBot\TournamentNotes). Scheduled every five minutes;
 * each run posts at most `esports.stream_bot.tournament_notes.per_run`
 * notes. Off unless ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC
 * are set.
 *
 * `--dry-run` prints the notes the next run would post; it claims, signs,
 * sends and stores nothing.
 */
#[Signature('twentyone:stream-bot:tournaments
    {--dry-run : Print the notes the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a kind-1 note on the stream bot profile for each published tournament that has none yet')]
class TwentyOneStreamBotTournamentsCommand extends Command
{
    public function handle(TournamentNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        foreach ($notes->stale(null, LeagueKey::streamBot()) as $tournament) {
            $this->line('--- tournament '.$tournament->id.': its note names an old start, deleted and replaced by');
            $this->line($notes->content($tournament));
            $this->newLine();
        }

        $due = $notes->due($now);

        foreach ($due as $tournament) {
            $this->line('--- tournament '.$tournament->id);
            $this->line($notes->content($tournament));
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
