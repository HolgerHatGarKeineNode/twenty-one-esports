<?php

namespace App\Console\Commands;

use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\TournamentNostrHeal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Repairs the tournaments' public copy after NIP-09 deletion requests signed
 * with the league or the stream bot key (App\Support\Tournaments\TournamentNostrHeal):
 * a deleted current 31923, or one still carrying `t` tags (no hashtags since
 * 2026-09-28), gets a new version signed after the deletion, a
 * current 31923 a league relay does not return is sent again unchanged, a
 * deleted bot note is posted anew. Nothing else is posted again.
 *
 * Reads the relays and prints the plan; changes nothing without `--publish`.
 * Run it again after `--publish`: the plan is then empty.
 */
#[Signature('tournaments:heal-nostr
    {--publish : Carry out the plan: sign, store and send; without it nothing is changed}')]
#[Description('Re-sign deleted tournament calendar events, resend missing ones and renew deleted bot notes (dry run by default)')]
class TournamentsHealNostrCommand extends Command
{
    public function handle(TournamentNostrHeal $heal, TournamentNotes $notes): int
    {
        $plan = $heal->plan();

        if ($plan['league'] === null) {
            $this->error('ESPORTS_LEAGUE_NSEC is not set or not a valid secret key: nothing to check.');

            return self::FAILURE;
        }

        $this->line('Relays read: '.implode(', ', $plan['relays']));
        $this->line('Deletion requests (kind 5) by the league or bot key: '.count($plan['deletions']));

        foreach ($plan['deletions'] as $deletion) {
            $this->line(sprintf(
                '  %s at %s by %s%s: e=[%s] a=[%s] k=[%s]',
                $deletion['id'],
                CarbonImmutable::createFromTimestamp($deletion['created_at'], 'UTC')->format('Y-m-d H:i:s').' UTC',
                substr($deletion['pubkey'], 0, 12),
                $deletion['client'] !== null ? ' via '.$deletion['client'] : '',
                implode(', ', $deletion['e']),
                implode(', ', $deletion['a']),
                implode(', ', $deletion['k']),
            ));
        }

        $this->newLine();
        $this->line('Tournament calendar events (31923):');

        foreach ($plan['tournaments'] as $step) {
            $this->line(sprintf('  tournament %d %s: %s %s', $step['tournament']->id, $step['tournament']->slug, match ($step['action']) {
                'resign' => implode(' and ', array_filter([
                    $step['deleted_by'] !== null ? 'DELETED by '.$step['deleted_by'] : null,
                    $step['hashtags'] ? 'carries t tags' : null,
                ])).', re-sign',
                'resend' => 'missing on '.implode(', ', $step['missing']).', resend',
                default => 'present on every league relay',
            }, $step['event_id']));
        }

        $this->newLine();
        $this->line('Deleted bot notes (kind 1): '.count($plan['notes']));

        foreach ($plan['notes'] as $step) {
            $this->line(sprintf('  tournament %d: note %s deleted by %s, %s', $step['tournament']->id, $step['event_id'], $step['deleted_by'], $step['action'] === 'renew' ? 'renew' : 'called off, left deleted'));

            if ($step['action'] === 'renew' && ! $this->option('publish')) {
                $this->line('    '.str_replace("\n", "\n    ", $notes->content($step['tournament'])));
            }
        }

        if ($plan['unmatched'] !== []) {
            $this->newLine();
            $this->line('Deleted references that are no current tournament event and no bot note (left alone):');

            foreach ($plan['unmatched'] as $reference) {
                $this->line('  '.$reference);
            }
        }

        $this->newLine();

        if (! $this->option('publish')) {
            $this->info('Dry run: nothing was signed, stored or sent. Run with --publish to carry out the plan.');

            return self::SUCCESS;
        }

        $lines = $heal->apply($plan, CarbonImmutable::now());

        foreach ($lines as $line) {
            $this->line($line);
        }

        $this->info($lines === [] ? 'Nothing to do.' : 'Done. Run again without --publish: the plan should be empty.');

        return self::SUCCESS;
    }
}
