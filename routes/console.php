<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessSettings;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\NotificationDm;
use App\Support\Notifications\WebPush;
use App\Support\SeasonChain\TrustJob;
use App\Support\SeasonChain\TrustJobRefused;
use App\Support\Series\SeriesService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Safety net for chess clocks: every live game whose deadline passed is
 * checked, in case the delayed App\Jobs\CheckChessClock did not run (worker
 * down, queue backed up). Only the server clock decides, so running it often
 * is harmless.
 */
Artisan::command('chess:check-clocks', function (ChessGameService $games) {
    $due = ChessGame::query()
        ->where('status', ChessGameStatus::Active)
        ->where('deadline_ms', '<=', (int) now()->getTimestampMs())
        ->get();

    foreach ($due as $game) {
        $games->checkClock($game);
    }

    $this->info("Checked {$due->count()} game(s).");
})->purpose('End live chess games whose clock ran out');

Schedule::command('chess:check-clocks')->everyTenSeconds()->withoutOverlapping();

/*
 * Daily chess deadline reminders (ChessSettings "Remind me when … are left").
 * Each turn gets at most one: the game's `reminded_ply` is claimed with a
 * conditional update first, so two sweeps running at once send it once.
 */
Artisan::command('chess:daily-reminders', function (ChessNotifications $notifications) {
    $now = (int) now()->getTimestampMs();
    $widest = max(ChessSettings::REMIND_HOURS) * 3_600_000;

    $candidates = ChessGame::query()
        ->daily()
        ->where('status', ChessGameStatus::Active)
        ->where('deadline_ms', '>', $now)
        ->where('deadline_ms', '<=', $now + $widest)
        ->where(fn ($query) => $query->whereNull('reminded_ply')->orWhereColumn('reminded_ply', '!=', 'ply'))
        ->with(['white', 'black'])
        ->get();

    $sent = 0;

    foreach ($candidates as $game) {
        $window = $game->player($game->turn())->chessSettings()->remindHours * 3_600_000;

        if ((int) $game->deadline_ms - $now > $window) {
            continue;
        }

        $claimed = ChessGame::query()
            ->whereKey($game->id)
            ->where('ply', $game->ply)
            ->where(fn ($query) => $query->whereNull('reminded_ply')->orWhere('reminded_ply', '!=', $game->ply))
            ->update(['reminded_ply' => $game->ply]);

        if ($claimed === 1) {
            $notifications->reminder($game);
            $sent++;
        }
    }

    $this->info("Sent {$sent} reminder(s).");
})->purpose('Remind players whose daily chess move is due soon');

Schedule::command('chess:daily-reminders')->everyFiveMinutes()->withoutOverlapping();

/*
 * A fresh VAPID key pair for Web Push, printed for `.env`. Nothing is written:
 * the keys never go into the repository.
 */
Artisan::command('esports:vapid-keys', function () {
    [$public, $private] = WebPush::generateKeyPair();

    $this->line('WEBPUSH_VAPID_PUBLIC_KEY='.WebPush::base64UrlEncode($public));
    $this->line('WEBPUSH_VAPID_PRIVATE_KEY='.WebPush::base64UrlEncode($private));
})->purpose('Generate a VAPID key pair for browser push');

/*
 * The notification key's profile (NIP "Notifications"): kind 0 with
 * `bot: true` (NIP-24) and the note that it reads no replies. No DM relay
 * list (10050) is published, so NIP-17 clients do not send replies.
 * Publishes to esports.relays (ndak locally); never run it against public
 * relays without the user's approval.
 */
Artisan::command('esports:notification-profile', function (RelayPublisher $publisher) {
    $dm = NotificationDm::fromConfig();
    $secret = NostrKeys::secretToHex(config('esports.notifications.nsec'));

    if (! $dm->isConfigured() || $secret === null) {
        $this->error('ESPORTS_NOTIFICATION_NSEC is not set.');

        return 1;
    }

    $event = (new Event)
        ->setKind(0)
        ->setTags([['alt', 'Profile of the TWENTY ONE esports notification account']])
        ->setContent((string) json_encode([
            'name' => config('esports.notifications.name'),
            'about' => 'Notifications from TWENTY ONE esports: your move, deadline reminders, challenges, results. This account reads no replies.',
            'website' => config('app.url'),
            'bot' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->setCreatedAt(now()->getTimestamp());
    (new Sign)->signEvent($event, $secret);

    $signed = SignedEvent::fromInput($event->toArray());
    $stored = NostrEvent::fromSigned($signed);

    foreach ($publisher->publish($stored) as $relay => $result) {
        $this->line($relay.': '.($result['accepted'] ? 'OK' : 'refused').' '.$result['message']);
    }

    $this->info('Notification key: '.$dm->pubkey());

    return 0;
})->purpose('Publish the notification key profile (kind 0, bot: true)');

/*
 * Retries the public copy (ChessStates "Public record delayed": "we retry on
 * our own"): signed events of the last day that some configured relay has
 * not accepted yet are sent again to exactly those relays. Gift wraps are
 * left alone; a late notification is worth less than none.
 */
Artisan::command('nostr:republish', function (RelayPublisher $publisher) {
    $relays = config('esports.relays', []);
    $sent = 0;

    if ($relays === []) {
        return;
    }

    $events = NostrEvent::query()
        ->where('kind', '!=', 1059)
        ->whereBetween('created_at', [now()->subDay(), now()->subMinute()])
        ->with('deliveries')
        ->oldest('id')
        ->limit(100)
        ->get();

    foreach ($events as $event) {
        $missing = array_values(array_filter($relays, fn (string $relay) => $event->deliveries->firstWhere('relay', $relay)?->accepted !== true));

        if ($missing !== []) {
            $publisher->publish($event, $missing);
            $sent++;
        }
    }

    $this->info("Republished {$sent} event(s).");
})->purpose('Send signed events again to relays that have not accepted them yet');

Schedule::command('nostr:republish')->everyFiveMinutes()->withoutOverlapping();

/*
 * Series challenges (P6a) whose reply deadline passed end as expired (NIP
 * state machine: "open | time | expired", no event is signed for it).
 * Answering an overdue challenge expires it on the spot as well.
 */
Artisan::command('series:expire-challenges', function (SeriesService $series) {
    $this->info('Expired '.$series->expireDue().' challenge(s).');
})->purpose('Expire series challenges nobody answered in time');

Schedule::command('series:expire-challenges')->everyMinute()->withoutOverlapping();

/*
 * The trust job (NIP "Trust", `anchored-trust-v1`): anchors from the
 * association's member lists and the admins, opponent lists and reports from
 * the league relays; publishes only the ranks that changed. Rated play opens
 * once it has run in the live season, so it runs often enough that a fresh
 * Block 0 or a newly added opponent does not wait long.
 */
Artisan::command('esports:trust-run', function (TrustJob $job) {
    try {
        $run = $job->run();
    } catch (TrustJobRefused $refused) {
        $this->warn($refused->getMessage());

        return 1;
    }

    $this->info("Trust run {$run->id}: {$run->anchors} anchor(s), {$run->lists} list(s), {$run->ranked} ranked, {$run->published} assertion(s) published.");

    return 0;
})->purpose('Compute and publish the trust ranks (anchored-trust-v1)');

Schedule::command('esports:trust-run')->everyFifteenMinutes()->withoutOverlapping();

/*
 * Horizon's metrics dashboard stays empty without regular snapshots.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();
