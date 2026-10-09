<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Scores\LeagueWeekDrafts;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\Entrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The check before a tournament's close (P7 "Turnier-Betrieb absichern",
 * `tournaments:preflight` every ten minutes): on prod (2026-10-03) tournament
 * 1 sat in "Draw pending" for over an hour because an entry of a deleted
 * account broke the draw every minute, and nothing said so before the close.
 *
 * Every tournament in sign-up whose sign-up closes within {@see WINDOW_MINUTES}
 * and every one waiting for its draw is checked for what the close and the
 * draw need: the accounts of every active entry, a format that fits the
 * entries (a casual cup the one its sign-ups pick now,
 * CasualCups::plannedFormat()), the Bitcoin API (tip, hash, time), the league
 * key and a scheduler that ticked within {@see HEARTBEAT_MAX_SECONDS}. Then
 * the draw itself runs on the current tip's hash, rolled back
 * (TournamentDraws::rehearse()), so whatever it would throw shows now.
 *
 * Each finding is logged and rings every admin in the bell (`league_alert`),
 * at most once per tournament, finding and hour. A check that throws is a
 * finding of its own: the preflight never fails open by staying silent.
 */
final class TournamentPreflight
{
    public const WINDOW_MINUTES = 90;

    public const HEARTBEAT_MAX_SECONDS = 180;

    /** How many runs in a row the Bitcoin API may miss before the admins hear of it (a new block takes seconds to index). */
    public const BITCOIN_MISSES_BEFORE_ALERT = 2;

    public const BITCOIN_MISSES = 'tournament-preflight:bitcoin-misses';

    /** The checks, in the order the table lists them. */
    public const CHECKS = ['accounts', 'format', 'bitcoin', 'league_key', 'scheduler', 'dry_draw'];

    public function __construct(private BitcoinBlocks $blocks, private TournamentDraws $draws, private Notifier $notifier) {}

    /**
     * The tournaments the preflight looks at: sign-up closing within the
     * window (or already past it, not closed yet), and every draw pending.
     *
     * @return Collection<int, Tournament>
     */
    public function due(): Collection
    {
        return Tournament::query()
            ->where(fn ($query) => $query
                ->where(fn ($signup) => $signup->where('status', TournamentStatus::Signup)->whereNotNull('signup_closes_at')->where('signup_closes_at', '<=', now()->addMinutes(self::WINDOW_MINUTES)))
                ->orWhere('status', TournamentStatus::Drawing))
            ->orderBy('id')
            ->get();
    }

    /**
     * Check every due tournament, ring the admins for each finding.
     *
     * @return list<array{tournament: Tournament, check: string, ok: bool, detail: string}>
     */
    public function run(): array
    {
        $tournaments = $this->due();

        if ($tournaments->isEmpty()) {
            return [];
        }

        // The league-wide checks once per run; each due tournament carries their result.
        try {
            ['result' => $bitcoin, 'hash' => $hash] = $this->bitcoin();
        } catch (Throwable $e) {
            report($e);
            [$bitcoin, $hash] = [self::thrown($e), null];
        }

        $shared = [
            'bitcoin' => $bitcoin,
            'league_key' => $this->guard(fn (): array => LeagueKey::fromConfig() === null
                ? self::fail('The league key does not load (esports.league.nsec): no close or draw can be signed.')
                : self::pass('loads')),
            'scheduler' => $this->guard(fn (): array => $this->scheduler()),
        ];
        $rows = [];

        // A Bitcoin API that misses one run is a hiccup, not a finding (prod 2026-10-09: "no time for block 970662"
        // right after it was found): the admins hear of it from the second run in a row; the log has every miss.
        $bitcoinMisses = $bitcoin['ok'] ? 0 : $this->countBitcoinMiss();

        if ($bitcoin['ok']) {
            Cache::forget(self::BITCOIN_MISSES);
        }

        foreach ($tournaments as $tournament) {
            $results = [
                'accounts' => $this->guard(fn (): array => $this->accounts($tournament)),
                'format' => $this->guard(fn (): array => $this->format($tournament)),
                ...$shared,
                'dry_draw' => $hash === null
                    ? self::fail('Not run: no block hash to draw with.')
                    : $this->guard(fn (): array => self::pass($this->draws->rehearse($tournament, $hash).' participant(s), rolled back')),
            ];

            foreach (self::CHECKS as $check) {
                $result = $results[$check];
                $rows[] = ['tournament' => $tournament, 'check' => $check, ...$result];

                // A dry draw without a hash is the Bitcoin check's finding and rings only there.
                $quiet = ($check === 'bitcoin' && $bitcoinMisses < self::BITCOIN_MISSES_BEFORE_ALERT) || ($check === 'dry_draw' && $hash === null);

                if (! $result['ok']) {
                    $quiet
                        ? Log::info("Tournament preflight: {$tournament->name} (#{$tournament->id}) {$check} waits: {$result['detail']}")
                        : $this->alert($tournament, $check, $result['detail']);
                }
            }
        }

        return $rows;
    }

    /**
     * Tip, the tip's hash and its time, as the close and the draw read them.
     *
     * @return array{result: array{ok: bool, detail: string}, hash: string|null}
     */
    private function bitcoin(): array
    {
        $tip = $this->blocks->tipHeight();

        if ($tip === null) {
            return ['result' => self::fail('The Bitcoin API gives no tip height: no sign-up closes and no draw runs.'), 'hash' => null];
        }

        // A block found seconds ago may have its hash but not yet its details: the one before it serves the check.
        $missing = '';

        foreach ([$tip, $tip - 1] as $height) {
            $hash = $this->blocks->hashAt($height);
            $time = $hash === null ? null : $this->blocks->timeOf($hash);

            if ($hash !== null && $time !== null) {
                return ['result' => self::pass($height === $tip ? "block {$tip}" : "block {$height} (block {$tip} not indexed yet)"), 'hash' => $hash];
            }

            $missing = $missing !== '' ? $missing : ($hash === null ? "no hash for block {$height}" : "no time for block {$height}");
        }

        return ['result' => self::fail("The Bitcoin API gives {$missing} nor for the one before: no draw runs."), 'hash' => null];
    }

    /** One more run in a row without the Bitcoin API; how many now. */
    private function countBitcoinMiss(): int
    {
        try {
            Cache::add(self::BITCOIN_MISSES, 0, now()->addHour());

            return (int) Cache::increment(self::BITCOIN_MISSES);
        } catch (Throwable $e) {
            report($e);

            // Fails closed: without a counter every miss rings.
            return self::BITCOIN_MISSES_BEFORE_ALERT;
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function scheduler(): array
    {
        $last = TournamentScheduler::health()['last_run_at'];

        if ($last === null) {
            return self::fail('tournaments:tick has no heartbeat: sign-ups do not close and draws do not run.');
        }

        $age = (int) $last->diffInSeconds(now(), true);

        return $age > self::HEARTBEAT_MAX_SECONDS
            ? self::fail("tournaments:tick last ran {$age} s ago: sign-ups do not close and draws do not run.")
            : self::pass("last tick {$age} s ago");
    }

    /**
     * Every active entry's players still have an account (the prod fault of 2026-10-03).
     *
     * @return array{ok: bool, detail: string}
     */
    private function accounts(Tournament $tournament): array
    {
        $signups = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->orderBy('id')->get();
        $present = User::query()->whereKey($signups->pluck('members')->flatten()->map(intval(...))->unique()->all())->pluck('id')->all();
        $orphans = $signups->filter(function (TournamentSignup $signup) use ($present): bool {
            $members = array_map(intval(...), $signup->members);

            return $members === [] || array_diff($members, $present) !== [] || ($signup->lineup_id === null && $signup->user_id === null);
        });

        return $orphans->isEmpty()
            ? self::pass($signups->count().' entries')
            : self::fail('Entries without an account: '.$orphans->map(fn (TournamentSignup $signup): string => '#'.$signup->id)->implode(', ').'.');
    }

    /**
     * The format the close will draw (a casual cup's planned one) fits the
     * entries: enough of them, and a bracket with matches to play.
     *
     * @return array{ok: bool, detail: string}
     */
    private function format(Tournament $tournament): array
    {
        $signups = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->get();
        $entries = $signups->whereNotNull('lineup_id')->count() + intdiv($signups->whereNull('lineup_id')->count(), max(1, $tournament->teamSize()));
        $planned = CasualCups::asPlanned($tournament);
        $label = $planned->format->label();

        // A casual cup short of players is extended, switched or called off by design (CasualCups).
        if (! $tournament->isCasualCup() && $entries < Lobbies::minEntriesOf($tournament)) {
            return self::fail("{$label} needs at least ".Lobbies::minEntriesOf($tournament)." entries, it has {$entries}: the close calls it off.");
        }

        if ($entries < 2) {
            return self::pass("{$label}, {$entries} entries");
        }

        $entrants = array_map(fn (int $id): Entrant => new Entrant($id), range(1, $entries));
        $bracket = BracketBuilder::build($planned->format, $entrants, $planned->formatOptions(), str_repeat('0', 64));

        return $bracket->matches === []
            ? self::fail("{$label} builds no match for {$entries} entries.")
            : self::pass("{$label}, {$entries} entries");
    }

    /**
     * One bell entry per admin and a log line; at most once per tournament, check and hour.
     */
    private function alert(Tournament $tournament, string $check, string $detail): void
    {
        Log::warning("Tournament preflight: {$tournament->name} (#{$tournament->id}) failed {$check}: {$detail}");

        try {
            if (! Cache::add("tournament-preflight-alert:{$tournament->id}:{$check}", true, now()->addHour())) {
                return;
            }

            foreach (LeagueWeekDrafts::admins() as $admin) {
                $locale = $admin->locale ?? (string) config('app.locale');

                // The bell only (remote: false), as a failed draw (TournamentDraws): acted on at the edit page.
                $this->notifier->send($admin, NotificationKind::LeagueAlert, new Notice(
                    __('Tournament :name: the check before the draw failed', ['name' => $tournament->name], $locale),
                    mb_strimwidth($detail, 0, 200, '…'),
                    route('admin.tournaments.edit', $tournament),
                    null,
                    __('Open tournament', [], $locale),
                ), remote: false);
            }
        } catch (Throwable $alertFailed) {
            // The log line above still says it.
            report($alertFailed);
        }
    }

    /**
     * Runs one check; a check that throws is a finding, never a pass.
     *
     * @param  callable(): array{ok: bool, detail: string}  $check
     * @return array{ok: bool, detail: string}
     */
    private function guard(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            report($e);

            return self::thrown($e);
        }
    }

    /**
     * @return array{ok: false, detail: string}
     */
    private static function thrown(Throwable $e): array
    {
        return self::fail(class_basename($e).': '.trim(strtok($e->getMessage(), "\n") ?: ''));
    }

    /**
     * @return array{ok: true, detail: string}
     */
    private static function pass(string $detail): array
    {
        return ['ok' => true, 'detail' => $detail];
    }

    /**
     * @return array{ok: false, detail: string}
     */
    private static function fail(string $detail): array
    {
        return ['ok' => false, 'detail' => $detail];
    }
}
