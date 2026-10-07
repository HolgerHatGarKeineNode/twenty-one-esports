<?php

namespace App\Console\Commands;

use App\Enums\TournamentStatus;
use App\Games\BoardGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-off (user, 2026-10-07): nine men's morris, checkers and Blockli are
 * correspondence only and run no casual cups any more, so their blitz cups
 * still open for sign-up are called off. Each goes through the admin's
 * "Call off" (TournamentControl::abort): the cup is cancelled, its Nostr
 * event republished, the call-off logged under the acting admin, and every
 * player signed up is told with the reason. The cup tick then gives back the
 * series' open place. Idempotent: a cup called off already is no longer
 * listed, and a second run finds nothing.
 */
#[Signature('esports:cancel-board-blitz-cups
    {--dry-run : List the cups that would be called off and change nothing}
    {--actor= : Hex pubkey or npub of the admin the call-off is logged under (default: the first board member with an account)}
    {--reason= : The reason the players read (3 to 500 characters)}')]
#[Description('Call off the open blitz casual cups of nine men\'s morris, checkers and Blockli (one-off, notifies the players)')]
class CancelBoardBlitzCupsCommand extends Command
{
    public const REASON = 'Nine Men\'s Morris, Checkers and Blockli are now played one move a day only, so their blitz cups are called off. Challenge a player on the game\'s page instead.';

    public function handle(TournamentControl $control): int
    {
        $cups = Tournament::query()->casualCup()->whereIn('game', BoardGame::RESERVED_SLUGS)->where('mode', 'blitz')
            ->where('status', TournamentStatus::Signup)->orderBy('id')->get();
        $dryRun = (bool) $this->option('dry-run');

        if ($cups->isEmpty()) {
            $this->info('No open blitz cup of a board game: nothing to call off.');

            return self::SUCCESS;
        }

        $this->table(['id', 'name', 'game', 'series', 'signups', 'closes (UTC)'], $cups->map(fn (Tournament $cup): array => [
            $cup->id, $cup->name, $cup->game, $cup->cup_series, $cup->signups()->active()->count(), $cup->signup_closes_at?->utc()->format('Y-m-d H:i'),
        ])->all());

        if ($dryRun) {
            $this->line('Dry run: '.$cups->count().' cup(s) would be called off. Nothing changed.');

            return self::SUCCESS;
        }

        $actor = $this->actor();

        if ($actor === null) {
            $this->error('No admin to log the call-off under: pass --actor=<hex pubkey or npub> of an admin with an account.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: self::REASON));
        $failed = 0;

        foreach ($cups as $cup) {
            try {
                $this->line(($control->abort($cup, $actor, $reason) ? 'Called off' : 'Already called off').": #{$cup->id} {$cup->name}");
            } catch (TournamentRuleViolation $violation) {
                $failed++;
                $this->error("Not called off: #{$cup->id} {$cup->name}: {$violation->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function actor(): ?User
    {
        $given = trim((string) $this->option('actor'));

        if ($given !== '') {
            $hex = str_starts_with($given, 'npub') ? NostrKeys::npubToHex($given) : $given;
            $user = $hex === null ? null : User::query()->where('pubkey', $hex)->first();

            return $user?->isAdmin() ? $user : null;
        }

        return User::query()->whereIn('pubkey', Board::pubkeys())->orderBy('id')->first();
    }
}
