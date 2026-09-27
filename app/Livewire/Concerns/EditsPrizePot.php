<?php

namespace App\Livewire\Concerns;

use App\Models\Tournament;
use App\Models\User;
use App\Support\PreSeason;
use App\Support\Prizes\PotBalances;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The optional prize pot of the tournament create and edit pages and of the
 * pool page (P9, user 2026-09-27): off by default; on, the pot is the
 * tournament's own NWC wallet (never the league's), with its prizes set in
 * one of two modes: a share of the pot per place (presets or custom, 100 %
 * in total) or fixed sats per place, both previewed in sats. The rules live
 * in {@see PrizePool::configurePot()}.
 *
 * The connection string is typed into `$potUri` and nowhere else: it is
 * never filled from the database, and it is cleared after every save, so a
 * stored one never reaches the page or the Livewire snapshot. A stored
 * connection shows as "connected" with replace and remove.
 */
trait EditsPrizePot
{
    public bool $potEnabled = false;

    /** A newly pasted connection string; never a stored one. */
    public string $potUri = '';

    /** The stored connection is being replaced: the field shows. */
    public bool $potReplacing = false;

    public string $potTarget = '';

    /** `percent` or `fixed`. */
    public string $potMode = Tournament::PRIZES_PERCENT;

    /** @var list<int|string> percents per place */
    public array $potSplit = Tournament::DEFAULT_SPLIT;

    /** @var list<int|string> sats per place */
    public array $potFixed = [];

    /** Balance of the pasted wallet at its last check, in sats; null = not checked. */
    public ?int $potCheckedSats = null;

    public string $potNotice = '';

    public string $potError = '';

    /** The tournament whose pot this is; null on the create page. */
    abstract protected function potTournament(): ?Tournament;

    protected function fillPot(?Tournament $tournament): void
    {
        $this->potEnabled = $tournament?->hasOwnWallet() === true;
        $this->potUri = '';
        $this->potReplacing = false;
        $this->potTarget = $tournament?->prize_target_sats === null ? '' : (string) $tournament->prize_target_sats;
        $this->potMode = $tournament?->prizeMode() ?? Tournament::PRIZES_PERCENT;
        $this->potSplit = $tournament?->prizeSplit() ?? Tournament::DEFAULT_SPLIT;
        $this->potFixed = $tournament?->prizeFixed() ?: [];
        $this->potCheckedSats = null;
    }

    public function usePotMode(string $mode): void
    {
        if (in_array($mode, [Tournament::PRIZES_PERCENT, Tournament::PRIZES_FIXED], true)) {
            $this->potMode = $mode;
            $this->potFixed = $this->potFixed === [] ? [50_000, 30_000, 20_000] : $this->potFixed;
        }
    }

    public function usePotPreset(string $preset): void
    {
        if (isset(PrizePool::PRESETS[$preset])) {
            $this->potSplit = PrizePool::PRESETS[$preset];
        }
    }

    public function addPotPlace(): void
    {
        if ($this->potMode === Tournament::PRIZES_FIXED) {
            if (count($this->potFixed) < PrizePool::MAX_PLACES) {
                $this->potFixed[] = 1000;
            }

            return;
        }

        if (count($this->potSplit) < PrizePool::MAX_PLACES) {
            $this->potSplit[] = 1;
        }
    }

    public function removePotPlace(): void
    {
        if ($this->potMode === Tournament::PRIZES_FIXED) {
            if (count($this->potFixed) > 1) {
                array_pop($this->potFixed);
            }

            return;
        }

        if (count($this->potSplit) > 1) {
            array_pop($this->potSplit);
        }
    }

    public function replacePotWallet(): void
    {
        $this->potReplacing = true;
    }

    /**
     * Remove the stored wallet connection, and with it the pot (its sats stay in that wallet).
     */
    public function removePotWallet(): void
    {
        $tournament = $this->potTournament();

        if ($tournament === null) {
            return;
        }

        $this->potEnabled = false;

        if ($this->savePot($tournament)) {
            $this->fillPot($tournament->refresh());
            $this->potNotice = __('The wallet connection was removed; this tournament has no prize pot now.');
        } else {
            $this->potEnabled = true;
        }
    }

    /**
     * Ask the pasted wallet for its balance now (and whether it may pay).
     */
    public function checkPotConnection(PrizePool $pool): void
    {
        $this->potError = $this->potNotice = '';
        $this->potCheckedSats = null;
        $user = $this->potUser();

        if (! $this->mayCheckWallet($user)) {
            return;
        }

        if (trim($this->potUri) === '') {
            $this->potError = __('Paste the connection string of the pot’s wallet.');

            return;
        }

        try {
            $check = $pool->checkWallet($this->potUri, $this->potTournament()->id ?? 0);
        } catch (TournamentRuleViolation $violation) {
            $this->potError = $violation->getMessage();

            return;
        }

        $this->potCheckedSats = $check['balance'];
        $this->potNotice = __('Connected. The wallet holds :sats sats and may pay invoices.', ['sats' => PreSeason::formatSats($check['balance'])])
            .' '.($check['can_receive'] ? __('Anyone can add sats to it from the tournament page.') : __('It may not make invoices, so top-ups from the tournament page are off.'));
    }

    /**
     * Read the stored wallet's balance now (organizer or admin, once every 10 s per tournament).
     */
    public function readPotBalance(PotBalances $balances): void
    {
        $this->potError = $this->potNotice = '';
        $tournament = $this->potTournament();

        if ($tournament === null || ! $tournament->hasOwnWallet()) {
            return;
        }

        Gate::forUser($this->potUser())->authorize('manage-tournament', $tournament);
        $key = 'pot-read:'.$tournament->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->potError = __('Read a moment ago. Wait :seconds s and try again.', ['seconds' => RateLimiter::availableIn($key)]);

            return;
        }

        RateLimiter::hit($key, 10);

        if ($balances->read($tournament)) {
            $this->potNotice = __('Balance read: :sats sats.', ['sats' => PreSeason::formatSats((int) $tournament->pot_balance_sats)]);
        } else {
            $this->potError = __('The wallet did not tell its balance (:code). The last known balance stays.', ['code' => (string) $tournament->pot_balance_error]);
        }
    }

    /**
     * The sats the percent preview splits: a checked wallet's balance, the
     * stored pot's balance, else the target, each less the fee reserve; null
     * when there is none of these.
     */
    public function potPreviewSats(): ?int
    {
        if ($this->potCheckedSats !== null) {
            return PrizePool::afterFeeReserve($this->potCheckedSats);
        }

        $tournament = $this->potTournament();

        if ($tournament !== null && $tournament->hasOwnWallet() && $tournament->pot_balance_sats !== null && $tournament->pot_balance_sats > 0) {
            return PrizePool::afterFeeReserve($tournament->pot_balance_sats);
        }

        $target = trim($this->potTarget);

        return ctype_digit($target) && (int) $target > 0 ? PrizePool::afterFeeReserve((int) $target) : null;
    }

    /**
     * The balance the fixed-mode summary compares with: a checked wallet's,
     * else the stored pot's; null when unknown.
     */
    public function potKnownBalance(): ?int
    {
        return $this->potCheckedSats ?? ($this->potTournament()?->hasOwnWallet() === true ? $this->potTournament()->pot_balance_sats : null);
    }

    /**
     * Save the pot of `$tournament` as the form says; false with `$potError`
     * set when it was refused (the pasted string stays for a correction).
     */
    protected function savePot(Tournament $tournament): bool
    {
        $this->potError = $this->potNotice = '';
        $target = trim($this->potTarget);

        // A new connection string is checked live on save: the same budget as the check button, counted before (F1).
        if ($this->potEnabled && trim($this->potUri) !== '' && ! $this->mayCheckWallet($this->potUser())) {
            return false;
        }

        if ($this->potEnabled && $this->potMode === Tournament::PRIZES_PERCENT && $target !== '' && ! ctype_digit($target)) {
            $this->potError = __('The target is a whole number of sats.');

            return false;
        }

        try {
            app(PrizePool::class)->configurePot(
                $tournament,
                $this->potUser(),
                $this->potEnabled,
                $this->potEnabled ? $this->potUri : null,
                $this->potEnabled && $target !== '' && ctype_digit($target) ? (int) $target : null,
                $this->potMode,
                $this->potSplit,
                $this->potFixed,
            );
        } catch (TournamentRuleViolation $violation) {
            $this->potError = $violation->getMessage();

            return false;
        }

        // The pasted connection string never outlives the request that saved it.
        $this->potUri = '';
        $this->potReplacing = false;
        $this->potCheckedSats = null;

        return true;
    }

    /**
     * Every live wallet check (the button, and each save with a new string)
     * counts against one budget per user, before the check runs: six a minute.
     */
    private function mayCheckWallet(User $user): bool
    {
        $key = 'pot-check:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->potError = __('Too many checks. Wait :seconds s and try again.', ['seconds' => RateLimiter::availableIn($key)]);

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    private function potUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
