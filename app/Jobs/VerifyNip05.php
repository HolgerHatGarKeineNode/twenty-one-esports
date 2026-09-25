<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Nostr\Nip05Verifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a player's NIP-05 address in the background, so a slow or hostile
 * domain never delays the request that cached the profile.
 */
class VerifyNip05 implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public User $user)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(Nip05Verifier $verifier): void
    {
        $verifier->verify($this->user);
    }
}
