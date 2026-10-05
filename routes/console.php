<?php

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Jobs\NotifyBlockZero;
use App\Models\BellNotification;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use App\Models\StackerRun;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessSettings;
use App\Support\Engagement\WeeklySlots;
use App\Support\Moderation\LeagueMuteList;
use App\Support\Moderation\MuteListUnreadable;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\BlockZeroNotifications;
use App\Support\Notifications\BoardNotifications;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\DmDigest;
use App\Support\Notifications\NotificationDm;
use App\Support\Notifications\WebPush;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreServers;
use App\Support\SeasonChain\TrustJob;
use App\Support\SeasonChain\TrustJobRefused;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesService;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentPreflight;
use App\Support\Tournaments\TournamentScheduler;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
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

    // Each game on its own: one that throws is reported and the others still end on time.
    foreach ($due as $game) {
        try {
            $games->checkClock($game);
        } catch (Throwable $e) {
            report($e);
        }
    }

    $this->info("Checked {$due->count()} game(s).");
})->purpose('End live chess games whose clock ran out');

Schedule::command('chess:check-clocks')->everyTenSeconds()->withoutOverlapping();

/*
 * The same safety net for the board games next to chess (plan "Mühle und
 * Dame", P2): every live board game whose deadline passed is checked, in
 * case the delayed App\Jobs\CheckBoardClock did not run. Only the server
 * clock decides, so running it often is harmless. It needs no rules, so it
 * also ends a game whose board game was switched off meanwhile.
 */
Artisan::command('board:check-clocks', function (BoardGameService $games) {
    $due = BoardGame::query()
        ->where('status', BoardGameStatus::Active)
        ->where('deadline_ms', '<=', (int) now()->getTimestampMs())
        ->get();

    // Each game on its own: one that throws is reported and the others still end on time.
    foreach ($due as $game) {
        try {
            $games->checkClock($game);
        } catch (Throwable $e) {
            report($e);
        }
    }

    $this->info("Checked {$due->count()} game(s).");
})->purpose('End live board games whose clock ran out');

Schedule::command('board:check-clocks')->everyTenSeconds()->withoutOverlapping();

/*
 * Correspondence board game deadline reminders (plan "Mühle und Dame", P8),
 * as `chess:daily-reminders`: the player to move is reminded when their
 * "Remind me when … are left" window (ChessSettings, one setting for daily
 * chess and the board games) is reached. Each turn gets at most one: the
 * game's `reminded_ply` is claimed with a conditional update first, so two
 * sweeps running at once send it once.
 */
Artisan::command('board:daily-reminders', function (BoardNotifications $notifications) {
    $now = (int) now()->getTimestampMs();
    $widest = max(ChessSettings::REMIND_HOURS) * 3_600_000;

    $candidates = BoardGame::query()
        ->correspondence()
        ->where('status', BoardGameStatus::Active)
        ->where('deadline_ms', '>', $now)
        ->where('deadline_ms', '<=', $now + $widest)
        ->where(fn ($query) => $query->whereNull('reminded_ply')->orWhereColumn('reminded_ply', '!=', 'ply'))
        ->with(['white', 'black'])
        ->get();

    $sent = 0;

    foreach ($candidates as $game) {
        $player = $game->player($game->turn);

        // A board game switched off meanwhile has no page to move on: no reminder (its clock still runs out).
        if ($player === null || ! app(GameRegistry::class)->isBoard($game->game) || (int) $game->deadline_ms - $now > $player->chessSettings()->remindHours * 3_600_000) {
            continue;
        }

        $claimed = BoardGame::query()
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
})->purpose('Remind players whose correspondence board game move is due soon');

Schedule::command('board:daily-reminders')->everyFiveMinutes()->withoutOverlapping();

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
 * P45: the daily DM digest. Every notification a player chose to get "once a
 * day" by Nostr DM waits until here; each player gets one DM listing them.
 */
Artisan::command('notifications:dm-digest', function (DmDigest $digest) {
    $this->info('Queued '.$digest->run().' digest DM(s).');
})->purpose('Send each player one Nostr DM with the notifications they chose to get once a day');

Schedule::command('notifications:dm-digest')->dailyAt('18:00')->timezone('Europe/Berlin')->withoutOverlapping()->onOneServer();

/*
 * Pruning (performance plan P2, S11), conservative: read bell notifications
 * older than 90 days beyond each player's newest 20 (App\Models\BellNotification),
 * and failed jobs older than 90 days (2160 hours). Not pruned: nostr_events
 * (the league's signed record, linked from attestations, reports, parameter
 * changes and clan pages) and relay_deliveries (a game's and a clan's page
 * show which relays accepted its event, at any age, and the republisher reads
 * the deliveries of every current replaceable event).
 */
Schedule::command('model:prune', ['--model' => [BellNotification::class]])->dailyAt('04:31')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 2160])->dailyAt('04:36')->withoutOverlapping()->onOneServer();

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
 * The league's public mute list (NIP-51 kind 10000, LeagueMuteList): read
 * the newest list from the league relays and republish it with the site's
 * keys when the relays lack them (a client edit that dropped them, a version
 * no relay took). Every change of a moderation runs it at once; this hourly
 * run reconciles the rest. A read no relay answered signs nothing and is
 * logged, never reported as a failed run. Nothing runs while
 * ESPORTS_PUBLISH_MUTE_LIST=false.
 */
Artisan::command('esports:mute-list', function (LeagueMuteList $list) {
    if (config('esports.league.mute_list') !== true) {
        $this->info('The league mute list is switched off (ESPORTS_PUBLISH_MUTE_LIST=false).');

        return;
    }

    try {
        $event = $list->publish();
    } catch (MuteListUnreadable $exception) {
        Log::warning('League mute list: no relay answered the read, nothing was signed');
        $this->warn($exception->getMessage());

        return;
    }

    $this->info($event === null ? 'The relays hold the current list; nothing was signed.' : "Signed and queued version {$event->event_id}.");
})->purpose('Republish the league mute list (NIP-51 kind 10000) when the relays lack the site\'s keys');

Schedule::command('esports:mute-list')->hourly()->withoutOverlapping()->onOneServer()->when(fn (): bool => config('esports.league.mute_list') === true);

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
 * admin queue, auto-confirm) and the reminders before them, each once; ends with the heartbeat the admin
 * pages check (TournamentScheduler::health()).
 */
Artisan::command('tournaments:tick', function (TournamentScheduler $scheduler) {
    $done = $scheduler->tick();

    $cups = $done['cups'];
    $this->info("Casual cups: opened {$cups['opened']}, grew {$cups['grown']}, extended {$cups['extended']}, switched {$cups['evenings']} to a live evening, called off {$cups['cancelled']}, opened {$cups['rounds']} round(s), decided {$cups['decided']} overdue match(es).");
    $this->info("Closed {$done['closed']} sign-up(s), drew {$done['drawn']} tournament(s), forfeited {$done['forfeited']} no-show(s), moved {$done['overdue']} overdue series to the admin queue, confirmed {$done['confirmed']} unanswered report(s), sent {$done['reminded']} reminder(s).");
})->purpose('Move tournaments on and apply their due deadlines');

Schedule::command('tournaments:tick')->everyMinute()->withoutOverlapping()->onOneServer();

/*
 * The check before the close (P7, TournamentPreflight): every tournament
 * whose sign-up closes within 90 minutes and every draw pending is checked
 * for the accounts of its entries, a format that fits them, the Bitcoin API,
 * the league key and the scheduler's heartbeat, and its draw is rehearsed on
 * the current tip and rolled back. Each finding is logged and rings the
 * admins, at most once per tournament, finding and hour.
 */
Artisan::command('tournaments:preflight', function (TournamentPreflight $preflight) {
    $rows = $preflight->run();

    if ($rows === []) {
        $this->info('No tournament closes within '.TournamentPreflight::WINDOW_MINUTES.' minutes or waits for its draw.');

        return 0;
    }

    $this->table(['Tournament', 'Status', 'Check', 'Result', 'Detail'], array_map(fn (array $row): array => [
        '#'.$row['tournament']->id.' '.$row['tournament']->name,
        $row['tournament']->status->value,
        $row['check'],
        $row['ok'] ? 'ok' : 'FAILED',
        $row['detail'],
    ], $rows));

    $failed = count(array_filter($rows, fn (array $row): bool => ! $row['ok']));
    $failed === 0 ? $this->info('Every check passed.') : $this->error("{$failed} check(s) failed: the admins were told.");

    return $failed === 0 ? 0 : 1;
})->purpose('Check the tournaments that close or draw soon, and rehearse their draw');

Schedule::command('tournaments:preflight')->everyTenMinutes()->withoutOverlapping()->onOneServer();

/*
 * Livewire's temporary uploads (livewire-tmp) older than a day (re-audit P10,
 * L2): Livewire deletes them only when a later upload finishes, so a file
 * nobody followed up would stay. Local disk only; S3 has its own lifecycle
 * rule (livewire:configure-s3-upload-cleanup).
 */
Artisan::command('uploads:prune-tmp', function () {
    if (FileUploadConfiguration::isUsingS3()) {
        return;
    }

    $storage = FileUploadConfiguration::storage();
    $before = now()->subDay()->getTimestamp();
    $deleted = 0;

    foreach ($storage->allFiles(FileUploadConfiguration::path()) as $file) {
        if ($storage->exists($file) && $storage->lastModified($file) < $before) {
            $storage->delete($file);
            $deleted++;
        }
    }

    $this->info("Deleted {$deleted} temporary upload(s) older than a day.");
})->purpose('Delete temporary Livewire uploads older than a day');

Schedule::command('uploads:prune-tmp')->hourly()->withoutOverlapping()->onOneServer();

/*
 * Score games (plan "AoE2 und Trackmania", P4, ScoreLeaderboards::tick()): the
 * snapshots of every running leaderboard's automatic sources, and the end of
 * those whose review time is over. Scheduled only while a score game is
 * registered, so with none nothing of it runs.
 */
Artisan::command('scores:tick', function (ScoreLeaderboards $leaderboards) {
    $done = $leaderboards->tick();

    $this->info("Stored {$done['snapshots']} new score run(s), finalized {$done['finalized']} leaderboard(s).");
})->purpose('Read the score sources and finalize the leaderboards that are due');

if (app(GameRegistry::class)->scores() !== []) {
    Schedule::command('scores:tick')->hourly()->withoutOverlapping()->onOneServer();
    // Round-4 F6: finishes of account ids nobody stored or confirmed, older than `prune_days`.
    Schedule::command('model:prune', ['--model' => [ScoreRun::class]])->daily()->withoutOverlapping()->onOneServer();
}

/*
 * A dedicated server that reports finishes to a score game (P4,
 * ScoreIngestController): its token is shown once here and stored hashed.
 */
Artisan::command('scores:server {game} {name}', function (string $game, string $name) {
    $issued = ScoreServers::issue($name, $game);

    $this->info("Server #{$issued['server']->id} ({$issued['server']->name}) for {$game}. Its token, shown only now:");
    $this->line($issued['token']);
})->purpose('Issue a token for a dedicated server that reports score finishes');

Artisan::command('scores:server-revoke {server}', function (string $server) {
    ScoreServers::revoke(ScoreServer::query()->findOrFail((int) $server));

    $this->info("Server #{$server} is revoked: its token is refused from now on.");
})->purpose('Revoke the token of a score server');

/*
 * The casual 1v1 clock (P23, CasualScheduler): a ready check that ran out
 * (void, the ready player back to the front of the queue), an uncontested
 * no-show claim (forfeit), a match nobody reported (void) and a report
 * nobody answered (confirmed), each once. Scheduled matches (S4): expired
 * challenges, the start reminder, the check-in call and the missed
 * check-in (forfeit, or void when neither side came).
 */
Artisan::command('casual:tick', function (CasualScheduler $scheduler) {
    $done = $scheduler->tick();

    $this->info("Expired {$done['expired']} challenge(s), sent {$done['reminded']} reminder(s) and {$done['checkin_opened']} check-in call(s), closed {$done['checkin_missed']} missed check-in(s). Voided {$done['unready']} unready and {$done['unreported']} unreported match(es), forfeited {$done['forfeited']} no-show(s), confirmed {$done['confirmed']} unanswered report(s).");
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
 * "Notify me at Block 0": once a planned Block 0 date is set (the chain
 * draft of the admin season page, ChainDraft) and lies ahead, every player who asked hears the date
 * once (BlockZeroNotifications::dated, queued). The release itself notifies
 * from SeasonRelease.
 */
Artisan::command('esports:block0-heads-up', function (BlockZeroNotifications $notifications) {
    if (! $notifications->datedPending()) {
        $this->info('Nobody waits for a Block 0 date.');

        return;
    }

    NotifyBlockZero::dispatch(NotifyBlockZero::DATED);
    $this->info('Queued the Block 0 date heads-up.');
})->purpose('Tell the players who asked about the planned Block 0 date');

Schedule::command('esports:block0-heads-up')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

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
 * unfinished payouts continued (never started) every minute. Nothing reads
 * or compares the wallet's balance (user, 2026-10-05). Does nothing without
 * the wallet connections.
 */
Schedule::command('wallet:sync')->everyMinute()->withoutOverlapping();

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

/*
 * While a published tournament is open for sign-up and has free places, the
 * same bot names them at fixed slots before sign-up closes (P49: specials at
 * 7 d, 3 d, 24 h, 3 h, casual cups at 24 h, 3 h), never within an hour of the
 * close, a few per run. Same flag and key.
 */
Schedule::command('twentyone:stream-bot:free-places')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

/*
 * Pride notes on the same profile: the dynamic stream slides (latest win,
 * climbers, sign-ups, the biggest pot's prizes) with their players tagged,
 * each type at most once a day in its EU or US slot, only when it changed.
 */
Schedule::command('twentyone:stream-bot:pride')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

/*
 * The champion of every newly finished tournament (special or casual cup,
 * finished within five days) on the same profile, once, with its champion
 * slide and the winner tagged. Same flag and key.
 */
Schedule::command('twentyone:stream-bot:champions')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

/*
 * The GG in the stream chat the moment a tournament is decided (ChampionChat):
 * every minute, so it lands within a minute or two of the finish; once per
 * tournament, on air and outside the quiet hours only. Same flag and key.
 */
Schedule::command('twentyone:stream-bot:gg')->everyMinute()->withoutOverlapping()->onOneServer();

/*
 * The game channels (P21, NIP "Game channels"): the fixed kind 40 of every
 * game and its kind 41 with the chat relays, republished daily so a relay
 * that lost them or a new chat relay gets them without a manual step. The
 * same ids every run (GameChannelsCommand::metadataTime()); without the
 * league key the run fails and publishes nothing; a relay that refuses
 * (rate limit, a newer kind 41, a timeout) is logged and never fails it
 * (user, 2026-10-05). A board game's channel
 * (rev. 9.15) only while it is switched on: the first run after that is
 * the first publish.
 */
Schedule::command('esports:game-channels')->dailyAt('03:21')->withoutOverlapping()->onOneServer();

/*
 * Blockfill runs (plan "Blockfill", P2 audit): a verification that never
 * came back (a lost job, a stopped worker) is given up as pending after
 * `esports.blockfill.verifier.stale_minutes`, and runs without a verified
 * time are pruned after `prune_days` (StackerRun::prunable()). The sweep
 * also sends pending runs that still hold a replay to the verifier again:
 * `redrive_batch` of them while it answers, a single probe otherwise, so a
 * broken verifier is not hammered (StackerRuns::redriveWaiting()).
 * `stacker:reverify` does the same by hand.
 */
Artisan::command('stacker:sweep', function (StackerRuns $runs) {
    $this->info('Gave up '.$runs->sweepStale(now()).' stale verification(s) as pending.');
    $this->info('Sent '.$runs->redriveWaiting(now()).' waiting run(s) to the verifier again.');
})->purpose('Move Blockfill runs stuck in verifying back to pending, and send waiting runs to the verifier again');

Artisan::command('stacker:reverify {--limit=50 : at most this many pending runs}', function (StackerRuns $runs) {
    $this->info('Sent '.$runs->reverifyPending(max(1, (int) $this->option('limit')), now()).' pending run(s) to the verifier again.');
})->purpose('Send pending Blockfill runs to the verifier again');

/*
 * Blockfill runs held for a check under older rules (plan "Blockfill", P5):
 * each is released once it would no longer place in its week's first ten,
 * or once today's hints find nothing in its replay (StackerRuns::releaseHeld()).
 * Run once by the migration that brought the rules; safe to run again.
 */
Artisan::command('blockfill:release-held', function (StackerRuns $runs) {
    $this->info('Released '.$runs->releaseHeld(now()).' held run(s).');
})->purpose('Release the Blockfill runs held for a check that today\'s rules would not hold');

/*
 * The casual weekly hunt of Blockfill (plan "Blockfill", P4, BlockfillWeeks):
 * opens this week's leaderboard (Monday 00:00 Europe/Berlin, which every
 * hourly run at minute 0 hits), joins verified players a run's own hook
 * missed, and reads the week's runs again. Its end is the score kind's own
 * (`scores:tick`). Scheduled only while Blockfill is registered, so with the
 * switch off nothing of it runs.
 */
Artisan::command('blockfill:weeks', function (BlockfillWeeks $weeks) {
    $done = $weeks->sweep();
    $announced = $weeks->announce();

    $this->info(($done['opened'] ? 'This week\'s leaderboard is open.' : 'No leaderboard opened: Blockfill is off, or this week is not approved yet.')." Joined {$done['joined']} player(s), read {$done['read']} best run(s). Signed {$announced} calendar event(s).");
})->purpose('Open this week\'s Blockfill leaderboard, join every verified player and sign its calendar event');

/*
 * Blockfill's jobs, scheduled only while it is registered (plan "Blockfill",
 * P6), so with the switch off none of them runs: the weeks (above), the runs'
 * sweep and prune (above), and the stream bot's week notes on its own profile
 * (a new week once it is open, a new first place at most once per
 * `blockfill_notes.top_minutes`, its winner and top 3 once it is finished,
 * each exactly once; App\Support\StreamBot\BlockfillNotes, same flag and
 * key as the other bot notes).
 */
if (app(GameRegistry::class)->find(Blockfill::SLUG) !== null) {
    Schedule::command('blockfill:weeks')->hourly()->withoutOverlapping()->onOneServer();
    Schedule::command('stacker:sweep')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
    Schedule::command('model:prune', ['--model' => [StackerRun::class]])->dailyAt('04:41')->withoutOverlapping()->onOneServer();
    Schedule::command('twentyone:stream-bot:blockfill')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
}

/*
 * The weekly time attack of TrackMania Nations Forever (plan "Trackmania und
 * Restposten", P2, TmnfWeeks): opens this week's leaderboard on its track
 * (Monday 00:00 Europe/Berlin), joins linked players a finish's own hook
 * missed, and signs its calendar event. Its end is the score kind's own
 * (`scores:tick`). The finishes come from `tmnf:listen`, a daemon of its own
 * (not scheduled). Scheduled only while TMNF is registered, with the stream
 * bot's week notes (TmnfNotes).
 */
Artisan::command('tmnf:weeks', function (TmnfWeeks $weeks) {
    $done = $weeks->sweep();
    $announced = $weeks->announce();

    $this->info(($done['opened'] ? 'This week\'s TMNF leaderboard is open.' : 'No TMNF leaderboard opened: TMNF is off, this week is not approved yet, or the server is not on its track yet.')." Joined {$done['joined']} player(s). Signed {$announced} calendar event(s).");
})->purpose('Open this week\'s TMNF leaderboard, join every linked player with a finish and sign its calendar event');

if (app(GameRegistry::class)->find(TrackmaniaNationsForever::SLUG) !== null) {
    Schedule::command('tmnf:weeks')->hourly()->withoutOverlapping()->onOneServer();
    Schedule::command('twentyone:stream-bot:tmnf')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
}
