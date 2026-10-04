<?php

namespace App\Livewire\Concerns;

use App\Models\Tournament;
use App\Models\User;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Auth;

/**
 * The optional prize pot of the tournament create and edit pages and of the
 * pool page (P9, user 2026-09-27): off by default; on, the pot is booked in
 * the league wallet (user, 2026-10-02: no wallet of its own is asked for),
 * with its prizes set in one of two modes: a share of the pot per place
 * (presets or custom, 100 % in total) or fixed sats per place, both
 * previewed in sats. The rules live in {@see PrizePool::configurePot()}.
 */
trait EditsPrizePot
{
    public bool $potEnabled = false;

    public string $potTarget = '';

    /** `percent` or `fixed`. */
    public string $potMode = Tournament::PRIZES_PERCENT;

    /** @var list<int|string> percents per place */
    public array $potSplit = Tournament::DEFAULT_SPLIT;

    /** @var list<int|string> sats per place */
    public array $potFixed = [];

    public string $potNotice = '';

    public string $potError = '';

    /** The tournament whose pot this is; null on the create page. */
    abstract protected function potTournament(): ?Tournament;

    protected function fillPot(?Tournament $tournament): void
    {
        $this->potEnabled = $tournament?->hasPot() === true;
        $this->potTarget = $tournament?->prize_target_sats === null ? '' : (string) $tournament->prize_target_sats;
        $this->potMode = $tournament?->prizeMode() ?? Tournament::PRIZES_PERCENT;
        $this->potSplit = $tournament?->prizeSplit() ?? Tournament::DEFAULT_SPLIT;
        $this->potFixed = $tournament?->prizeFixed() ?: [];
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

    /**
     * The sats the percent preview splits: a typed target, else what came
     * into the stored pot, each less the fee reserve; null when there is
     * neither.
     */
    public function potPreviewSats(): ?int
    {
        // The pot as set comes first (user, 2026-09-28): a typed target, then what came in.
        $target = trim($this->potTarget);

        if (ctype_digit($target) && (int) $target > 0) {
            return (int) $target;
        }

        $tournament = $this->potTournament();
        $funded = $tournament !== null && $tournament->hasPot() ? app(PrizePool::class)->fundedSats($tournament) : 0;

        return $funded > 0 ? $funded : null;
    }

    /**
     * What came into the stored pot (the fixed-mode summary compares with
     * it); null on the create page or without a pot.
     */
    public function potKnownBalance(): ?int
    {
        $tournament = $this->potTournament();

        return $tournament !== null && $tournament->hasPot() ? app(PrizePool::class)->fundedSats($tournament) : null;
    }

    /**
     * The create page asked the pot's own wallet here before its transaction
     * (re-gate O1). No wallet is asked for any more, so there is nothing to
     * check: always true.
     */
    protected function checkNewPotWallet(): bool
    {
        $this->potError = $this->potNotice = '';

        return true;
    }

    /**
     * Save the pot of `$tournament` as the form says; false with `$potError`
     * set when it was refused.
     */
    protected function savePot(Tournament $tournament): bool
    {
        $this->potError = $this->potNotice = '';
        $target = trim($this->potTarget);

        if ($this->potEnabled && $this->potMode === Tournament::PRIZES_PERCENT && $target !== '' && ! ctype_digit($target)) {
            $this->potError = __('The target is a whole number of sats.');

            return false;
        }

        try {
            app(PrizePool::class)->configurePot(
                $tournament,
                $this->potUser(),
                $this->potEnabled,
                $this->potEnabled && $target !== '' && ctype_digit($target) ? (int) $target : null,
                $this->potMode,
                $this->potSplit,
                $this->potFixed,
            );
        } catch (TournamentRuleViolation $violation) {
            $this->potError = $violation->getMessage();

            return false;
        }

        return true;
    }

    private function potUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
