<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessSettings;
use App\Support\Engagement\WeeklySlots;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\NotificationDm;
use App\Support\Notifications\WebPush;
use App\Support\SeasonChain\TrustJob;
use App\Support\SeasonChain\TrustJobRefused;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentScheduler;
use App\Support\Tournaments\TournamentSignups;
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
 * our own"): signed events of the last day that were queued for the relays
 * and that some configured relay has not accepted yet are sent again to
 * exactly those relays, and so is the current version of every replaceable
 * and addressable event of any age (tournaments, profile, ladders). Gift wraps are
 * left alone; a late notification is worth less than none.
 */
Artisan::command('nostr:republish', function (RelayPublisher $publisher) {
    $relays = config('esports.relays', []);
    $sent = 0;

    if ($relays === []) {
        return;
    }

    // Only events that were queued for the relays (flag or a delivery attempt): a stored-only
    // event never goes out, and a tournament consent (22150) never, whatever its rows say.
    $events = NostrEvent::query()
        ->where(fn ($query) => $query->whereNotNull('queued_at')->orWhereHas('deliveries'))
        ->whereNotIn('kind', [1059, TournamentSignups::CONSENT])
        ->whereBetween('created_at', [now()->subDay(), now()->subMinute()])
        ->with('deliveries')
        ->oldest('id')
        ->limit(100)
        ->get();

    // The current version of every replaceable (0, 3, 10000–19999) and addressable
    // (30000–39999) event, whatever its age: a relay added later, or a list that was
    // empty when the event was signed (2026-09-28: no calendar event reached any
    // relay), still gets the tournament, the profile and the ladders. Older versions
    // are superseded and never sent.
    $current = NostrEvent::query()
        ->whereNotNull('queued_at')
        ->where(fn ($query) => $query->whereIn('kind', [0, 3])->orWhereBetween('kind', [10000, 19999])->orWhereBetween('kind', [30000, 39999]))
        ->whereNotIn('kind', [1059, TournamentSignups::CONSENT])
        ->where('created_at', '<', now()->subMinute())
        ->whereNotIn('id', $events->pluck('id'))
        ->with('deliveries')
        ->orderByDesc('id')
        ->get()
        ->unique(fn (NostrEvent $event): string => $event->kind.':'.$event->pubkey.':'.($event->kind >= 30000 ? (string) $event->d : ''))
        ->reverse()
        ->take(100);

    foreach ($events->concat($current) as $event) {
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
 * Tournaments (P8b): close sign-ups whose deadline passed (the draw commits
 * to the next Bitcoin block), draw once that block is mined, and start the
 * normal matches of every ready tournament match that has none yet (a chess
 * player who was busy in another game gets theirs on a later run). The
 * scheduler runs this as the first step of `tournaments:tick`.
 */
Artisan::command('tournaments:advance', function (TournamentDraws $draws) {
    $done = $draws->advanceDue();

    $this->info("Closed {$done['closed']} sign-up(s), drew {$done['drawn']} tournament(s).");
})->purpose('Close tournament sign-ups, draw from the Bitcoin block, start ready matches');

/*
 * The tournament clock (P18, TournamentScheduler): the casual cups (P25,
 * CasualCups: one open cup per enabled game, their sign-ups and round
 * deadlines), `tournaments:advance`,
 * then the deadlines that are due (no-show forfeit, report overdue to the
 * admin queue, auto-confirm), each once; ends with the heartbeat the admin
 * pages check (TournamentScheduler::health()).
 */
Artisan::command('tournaments:tick', function (TournamentScheduler $scheduler) {
    $done = $scheduler->tick();

    $cups = $done['cups'];
    $this->info("Casual cups: opened {$cups['opened']}, grew {$cups['grown']}, extended {$cups['extended']}, switched {$cups['evenings']} to a live evening, called off {$cups['cancelled']}, opened {$cups['rounds']} round(s), decided {$cups['decided']} overdue match(es).");
    $this->info("Closed {$done['closed']} sign-up(s), drew {$done['drawn']} tournament(s), forfeited {$done['forfeited']} no-show(s), moved {$done['overdue']} overdue series to the admin queue, confirmed {$done['confirmed']} unanswered report(s).");
})->purpose('Move tournaments on and apply their due deadlines');

Schedule::command('tournaments:tick')->everyMinute()->withoutOverlapping()->onOneServer();

/*
 * The casual 1v1 clock (P23, CasualScheduler): a ready check that ran out
 * (void, the ready player back to the front of the queue), an uncontested
 * no-show claim (forfeit), a match nobody reported (void) and a report
 * nobody answered (confirmed), each once.
 */
Artisan::command('casual:tick', function (CasualScheduler $scheduler) {
    $done = $scheduler->tick();

    $this->info("Voided {$done['unready']} unready and {$done['unreported']} unreported match(es), forfeited {$done['forfeited']} no-show(s), confirmed {$done['confirmed']} unanswered report(s).");
})->purpose('Apply the due deadlines of casual 1v1 matches');

Schedule::command('casual:tick')->everyMinute()->withoutOverlapping()->onOneServer();

/*
 * Weekly events (P10): every active weekly slot gets its dated events for the
 * next week. Idempotent (unique slot + start), so running it every hour, or
 * twice at once, makes each event once.
 */
Artisan::command('events:schedule-weekly', function (WeeklySlots $slots) {
    $this->info('Scheduled '.$slots->schedule().' event(s).');
})->purpose('Create the dated events of the weekly slots');

Schedule::command('events:schedule-weekly')->hourly()->withoutOverlapping();

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
 * The stream's pictures (App\Console\Commands\TwentyOneStreamImagesCommand):
 * avatars older than a day are fetched again, a new picture right away;
 * backdrops only when a cover changed. The daemon reads the files.
 */
// The last run's output, failing avatar hosts included: prod logs errors only.
Schedule::command('twentyone:stream:images')->everyTenMinutes()->withoutOverlapping()->sendOutputTo(storage_path('logs/stream-images.log'));

/*
 * Horizon's metrics dashboard stays empty without regular snapshots.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();

/*
 * The league wallet (P9): paid pool invoices are settled and receipted, and
 * unfinished payouts continued (never started) every minute; once a day the
 * wallet's balance is compared with the pots. Both do nothing without the
 * wallet connections. Every two minutes the pots held in tournaments' own
 * wallets are read (their balance is the pot).
 */
Schedule::command('wallet:sync')->everyMinute()->withoutOverlapping();
Schedule::command('wallet:reconcile')->dailyAt('04:21')->withoutOverlapping();
Schedule::command('wallet:read-pots')->everyTwoMinutes()->withoutOverlapping();

/*
 * The stream chat bot (P22): every minute it checks its own cadence (live
 * stream, interval, the human rule, the daily cap) and posts at most one
 * message. Does nothing unless ESPORTS_STREAM_BOT_ENABLED and its key are set.
 */
Schedule::command('twentyone:stream-bot')->everyMinute()->withoutOverlapping()->onOneServer();

/*
 * The same bot posts a note on its own profile for every published tournament
 * (the backlog too), a few per run, each exactly once. Same flag and key.
 */
Schedule::command('twentyone:stream-bot:tournaments')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
