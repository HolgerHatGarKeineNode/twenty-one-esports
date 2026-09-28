<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Collects one MatchWait for TournamentWaits: the match's names and link,
 * the players it waits on (by side, slot or user id; a series' challenger
 * is slot 0, TournamentMatchMaker), then make() with the state.
 */
final class WaitBuilder
{
    /** @var list<int> */
    private array $users = [];

    private ?string $subject = null;

    private string $url;

    private bool $paused = false;

    public function __construct(Tournament $tournament, private TournamentMatch $match)
    {
        $this->url = route('tournaments.show', $tournament);
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function paused(bool $paused): self
    {
        $this->paused = $paused;

        return $this;
    }

    /**
     * @param  list<int>  $userIds
     */
    public function waitOnUsers(array $userIds): self
    {
        $this->users = array_values(array_unique([...$this->users, ...$userIds]));

        return $this;
    }

    public function waitOnSlot(int $slot): self
    {
        return $this->waitOnUsers($this->match->slots[$slot]->participant?->memberIds() ?? []);
    }

    /** A series side: `challenger` plays slot 0, `challenged` slot 1. */
    public function waitOnSide(?string $side): self
    {
        return $side === null ? $this : $this->waitOnSlot($side === 'challenger' ? 0 : 1);
    }

    public function sideName(string $side): string
    {
        return $this->match->slots[$side === 'challenger' ? 0 : 1]->participant->name ?? '';
    }

    /** The entry's name of a player (a mix team's name for its member), else the account's. */
    public function nameOf(int $userId): string
    {
        foreach ($this->match->slots as $slot) {
            if ($slot->participant !== null && in_array($userId, $slot->participant->memberIds(), true)) {
                return $slot->participant->name;
            }
        }

        return User::query()->find($userId)?->displayName() ?? '';
    }

    /**
     * @param  array<string, string>  $params
     */
    public function make(string $state, ?CarbonImmutable $since = null, ?CarbonImmutable $decidesAt = null, ?string $consequence = null, ?string $action = null,
        array $params = [], bool $needsAdmin = false, bool $remindable = true, ?CarbonImmutable $timeAt = null): MatchWait
    {
        $names = User::query()->whereKey($this->users)->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->displayName()]);
        $key = strtoupper($this->match->key);

        return new MatchWait(
            $this->match->id,
            $this->subject !== null && str_starts_with($this->subject, 'series:') ? '#'.($this->match->seriesMatch->number ?? $key)
                : ($this->subject !== null && str_starts_with($this->subject, 'chess:') ? '#'.($this->match->chessGame->number ?? $key) : $key),
            [$this->match->slots[0]->participant->name ?? '', $this->match->slots[1]->participant->name ?? ''],
            array_values(array_unique([...$this->match->slots[0]->participant?->memberIds() ?? [], ...$this->match->slots[1]->participant?->memberIds() ?? []])),
            $state,
            array_values(array_map(fn (int $id): array => ['user_id' => $id, 'name' => (string) ($names[$id] ?? '')], array_filter($this->users, fn (int $id): bool => isset($names[$id])))),
            $since,
            $decidesAt,
            $consequence,
            $action,
            $params,
            $needsAdmin,
            $this->paused && $decidesAt !== null,
            $remindable && $action !== null && $this->users !== [],
            $this->subject,
            $this->url,
            $timeAt,
        );
    }
}
