<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Notifications\NotificationDm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Delivers one notification DM from the league's notification key: NIP-17,
 * or NIP-04 for a player without a DM relay list
 * (App\Support\Notifications\NotificationDm::deliver()).
 *
 * `test`: the settings page's "Send a test DM" (P45). The lookup of the
 * player's relays skips its cache, and the outcome (format, relays that took
 * it) is kept under testResultKey() for the page to show.
 */
class SendNostrDm implements ShouldQueue
{
    use Queueable;

    public const TEST_RESULT_MINUTES = 60;

    /**
     * P45 audit F1: one attempt, ended at 75 s, well under the queue's
     * retry_after (90 s), so a relay that holds the worker can never make the
     * DM go out twice. The lookup takes at most DmRelays::LOOKUP_SECONDS plus
     * one read, each relay of the delivery at most relay_timeout_seconds.
     */
    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public User $user, public string $text, public ?int $match = null, public bool $test = false)
    {
        $this->afterCommit();
    }

    public static function testResultKey(User $user): string
    {
        return 'dm-test:'.$user->id;
    }

    /**
     * Also after a timeout: the settings page does not wait on a test forever.
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->test) {
            Cache::put(self::testResultKey($this->user), ['state' => 'failed', 'format' => null, 'accepted' => 0, 'asked' => 0, 'at' => now()->getTimestamp()], now()->addMinutes(self::TEST_RESULT_MINUTES));
        }
    }

    public function handle(): void
    {
        try {
            $delivery = NotificationDm::fromConfig()->deliver($this->user, $this->text, $this->match, fresh: $this->test);
        } catch (Throwable $exception) {
            if ($this->test) {
                Cache::put(self::testResultKey($this->user), ['state' => 'failed', 'format' => null, 'accepted' => 0, 'asked' => 0, 'at' => now()->getTimestamp()], now()->addMinutes(self::TEST_RESULT_MINUTES));
            }

            throw $exception;
        }

        if ($this->test) {
            Cache::put(self::testResultKey($this->user), [
                'state' => $delivery === null ? 'failed' : 'done',
                'format' => $delivery?->format,
                'accepted' => $delivery?->accepted() ?? 0,
                'asked' => $delivery === null ? 0 : count($delivery->results),
                'at' => now()->getTimestamp(),
            ], now()->addMinutes(self::TEST_RESULT_MINUTES));
        }
    }
}
