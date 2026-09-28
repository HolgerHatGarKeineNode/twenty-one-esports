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

    public function __construct(public User $user, public string $text, public ?int $match = null, public bool $test = false)
    {
        $this->afterCommit();
    }

    public static function testResultKey(User $user): string
    {
        return 'dm-test:'.$user->id;
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
