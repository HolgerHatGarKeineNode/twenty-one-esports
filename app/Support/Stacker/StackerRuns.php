<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The life of a Blockfill run (plan "Blockfill", P2): issue, start, submit,
 * verify.
 *
 * - issue(): a one-time token (shown once, stored as sha256) and a fresh
 *   128-bit seed on the current engine. A player has one active run: a new
 *   issue abandons every run of theirs still `issued`.
 * - start(): the browser says the run starts now. A token not started within
 *   `start_seconds` (10 s) expires (abandoned, reason `expired`), so the
 *   page asks for a run right before its countdown and the seed is known at
 *   most that long before the clock runs. The browser starts its clock only
 *   once this call has been answered, so the bracket below never undercuts
 *   an honest run.
 * - submit(): single-use (a compare-and-set out of `issued`). Checked here,
 *   in this order: body size (before the replay is looked at), played with
 *   the keyboard (P7: `input`), shape of replay/ticks/hash, the wall-clock bracket (time from start, or issue if
 *   never started, to submission at least the played time and at most
 *   `slack_seconds` more). Every run that passes is `verifying` and goes
 *   to the verifier's own queue (VerifyStackerRun), a run slower than the
 *   player's best of the week too: a verified slower run is an attempt on
 *   /matches and counts for the weekly quest, and the week's leaderboard
 *   keeps each player's best (ScoreRuns::standings()). Runs stored as
 *   `practice` come only from before 2026-10-01, when a run that did not
 *   beat the week's best was kept unverified.
 * - finish(): the verifier's verdict; an unavailable verifier leaves the run
 *   `pending` (fail-closed: no score until it is checked). A verified run
 *   with cheat hints (P5) that would place in its week's first HELD_PLACES
 *   (wouldPlace()) is held as `review` with its replay (keepHeld() bounds
 *   them) until an admin approves it (approve(): verified, on the board) or
 *   rejects it (reject()); a run with hints that would not place there is
 *   verified as any other, as a look at it could change nothing. A held run
 *   that no longer qualifies is released by releaseHeld()
 *   (`blockfill:release-held`).
 *
 * Storage stays bounded (security audits F1, N1, R1, round 3). Before
 * anything is stored the whole replay is read (replayInputCount(): canonical
 * base64url and varints, a header within the limits, exactly as many inputs
 * as it declares and not one byte more), and it may carry at most
 * ceil(ticks * limits.inputs_per_tick) + limits.input_slack inputs. That
 * bounds a replay by its played time, which the player chooses: up to the
 * 36,000-tick limit a run can still be ~33 KB, padded with inputs that
 * change nothing. The hard bound is therefore on what is kept: the
 * submitted replay only while a run is verifying or pending, and for a
 * verified run the replay the verifier answered with, but only while the
 * run is among the `replay_keep_top` fastest of its week (keepWeekTop()):
 * that many replays per week, plus at most `replay_keep_shared` runs that
 * are moments or were shared (a moment's page plays them). Rejected runs
 * keep none. Runs
 * without a verified time are pruned after `prune_days`
 * (StackerRun::prunable()). sweepStale() gives up verifications that never
 * came back, reverifyPending() sends pending runs again (console only).
 */
final class StackerRuns
{
    public const TOKEN_LENGTH = 40;

    /**
     * The only way a ranked run may be played (plan "Blockfill", P7: ranked
     * needs a keyboard, touch is practice). The page says it; it proves
     * nothing, as the input log proves no human.
     */
    public const INPUT_KEYBOARD = 'keyboard';

    /**
     * The places of a week's board a run with cheat hints is held for (P5):
     * the first ten are public and the first three can mine, so only there a
     * hint is worth an admin's look.
     */
    public const HELD_PLACES = 10;

    /** Cache key of the newest verdict: when, and whether the verifier answered. */
    public const LAST_VERDICT_KEY = 'stacker:last-verdict';

    /**
     * @return array{0: StackerRun, 1: string} the run and its plain token
     */
    public function issue(User $user, CarbonInterface $now): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $run = DB::transaction(function () use ($user, $token, $now): StackerRun {
            StackerRun::query()
                ->where('user_id', $user->id)
                ->where('status', StackerRunStatus::Issued)
                ->update($this->stored(['status' => StackerRunStatus::Abandoned, 'reason' => 'replaced', 'updated_at' => $now]));

            return StackerRun::query()->create([
                'user_id' => $user->id,
                'token_hash' => self::hashToken($token),
                'seed' => self::freshSeed(),
                'engine' => (string) config('esports.blockfill.engine'),
                'status' => StackerRunStatus::Issued,
                'issued_at' => $now,
            ]);
        });

        return [$run, $token];
    }

    /**
     * A random 128-bit seed. Only the test environment may pin it
     * (`esports.blockfill.testing_seed`), so a browser test can play a
     * recorded reference run as a ranked run.
     */
    public static function freshSeed(): string
    {
        $pinned = config('esports.blockfill.testing_seed');

        if (app()->environment('testing') && is_string($pinned) && preg_match('/^[0-9a-f]{32}$/', $pinned) === 1) {
            return $pinned;
        }

        return bin2hex(random_bytes(16));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The player's run behind a token, whatever its state; null for a token
     * that is not theirs.
     */
    public function find(User $user, string $token): ?StackerRun
    {
        return StackerRun::query()
            ->where('token_hash', self::hashToken($token))
            ->where('user_id', $user->id)
            ->first();
    }

    public function expiresAt(StackerRun $run): CarbonInterface
    {
        return $run->issued_at->copy()->addSeconds((int) config('esports.blockfill.start_seconds'));
    }

    /**
     * Marks the start; false if the run is no longer issued, was started
     * already, or its token expired (then it is abandoned).
     */
    public function start(StackerRun $run, CarbonInterface $now): bool
    {
        if ($this->expireIfDue($run, $now)) {
            return false;
        }

        return StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Issued)
            ->whereNull('started_at')
            ->update($this->stored(['started_at' => $now, 'updated_at' => $now])) === 1;
    }

    /**
     * Takes the submission; null if the token was used already (or the run
     * is otherwise no longer issued). The returned run carries the outcome.
     * `$input` is how the page says the run was played: a ranked run counts
     * only played with the keyboard (plan "Blockfill", P7, INPUT_KEYBOARD);
     * touch, or no answer, is rejected (`input`) before the verifier.
     */
    public function submit(StackerRun $run, string $body, mixed $replay, mixed $ticks, mixed $hash, mixed $input, CarbonInterface $now, ?string $network = null): ?StackerRun
    {
        if ($this->expireIfDue($run, $now)) {
            return $run->refresh();
        }

        $limits = (array) config('esports.blockfill.limits');
        $fields = ['submitted_at' => $now, 'updated_at' => $now];

        if (strlen($body) > (int) $limits['bytes']) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'oversize'];
        } elseif ($input !== self::INPUT_KEYBOARD) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'input'];
        } elseif (! is_string($replay) || $replay === '' || preg_match('/^[A-Za-z0-9_-]+$/', $replay) !== 1
            || ! is_int($ticks) || $ticks < 1 || $ticks > (int) $limits['ticks']
            || ! is_string($hash) || preg_match('/^[0-9a-f]{8}$/', $hash) !== 1
            || ($count = self::replayInputCount($replay)) === null) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'malformed'];
        } elseif ($count > self::maxInputs($ticks)) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'oversize'];
        } else {
            $network = $network === null ? null : self::networkKey($network);
            $fields += ['ticks' => $ticks, 'state_hash' => $hash];
            $fields += $this->outcome($run, $ticks, $now);

            if ($fields['status'] === StackerRunStatus::Verifying) {
                if ($this->inflightFull($run->user_id, $network)) {
                    throw new StackerBusy('Too many runs are waiting for the verifier.');
                }

                $fields['replay'] = $replay;
                $fields['network'] = $network;
            }
        }

        $taken = StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Issued)
            ->update($this->stored($fields)) === 1;

        if (! $taken) {
            return null;
        }

        $run->refresh();

        if ($run->status === StackerRunStatus::Verifying) {
            VerifyStackerRun::dispatch($run->id);
        }

        return $run;
    }

    /**
     * Applies the verifier's verdict to a run still `verifying`.
     */
    public function finish(StackerRun $run, StackerVerdict $verdict, CarbonInterface $now): void
    {
        // P5: a run with cheat hints is held for an admin's look (Review) and counts nowhere until approved, but only
        // when it would place among its week's first HELD_PLACES: below them the look could change nothing
        $held = $verdict->outcome === StackerVerdict::VERIFIED && ($verdict->hints['flags'] ?? []) !== []
            && $this->wouldPlace($run, self::weekOf($run->submitted_at ?? $now));
        $fields = match ($verdict->outcome) {
            StackerVerdict::VERIFIED => [
                'status' => $held ? StackerRunStatus::Review : StackerRunStatus::Verified,
                'settings' => json_encode($verdict->settings),
                'verified_at' => $held ? null : $now,
                'reason' => null,
                'replay' => $verdict->replay,
                'week' => self::weekOf($run->submitted_at ?? $now),
                'network' => null,
                'flags' => json_encode(['hints' => $verdict->hints ?? ['flags' => []]]),
            ],
            StackerVerdict::REJECTED => ['status' => StackerRunStatus::Rejected, 'reason' => $verdict->reason, 'replay' => null, 'network' => null],
            default => ['status' => StackerRunStatus::Pending, 'reason' => $verdict->reason],
        };

        // a run the sweep gave up as stale is still decided when its job comes late
        $finished = StackerRun::query()
            ->whereKey($run->id)
            ->where(fn ($open) => $open->where('status', StackerRunStatus::Verifying)
                ->orWhere(fn ($stale) => $stale->where('status', StackerRunStatus::Pending)->where('reason', 'verifier-stale')))
            ->whereNotNull('replay')
            ->update($this->stored($fields + ['updated_at' => $now])) === 1;

        if ($finished && $verdict->outcome === StackerVerdict::VERIFIED) {
            $held ? $this->keepHeld($run->refresh(), $now) : $this->keepWeekTop(self::weekOf($run->submitted_at ?? $now));
        }

        // what the verifier said last: the sweep re-sends waiting runs only to a verifier that answers
        Cache::put(self::LAST_VERDICT_KEY, [
            'at' => $now->getTimestamp(),
            'answered' => $verdict->outcome !== StackerVerdict::UNAVAILABLE,
        ], now()->addDay());
    }

    /**
     * Whether `$run`'s time would give its player one of the first
     * HELD_PLACES of week `$week`: the player has no verified time as fast
     * in that week yet, and fewer than HELD_PLACES other players have one
     * strictly faster. A tie counts for the run (it may take the place), and
     * runs still held count for nobody, so in doubt the run is held.
     */
    public function wouldPlace(StackerRun $run, string $week): bool
    {
        $ticks = (int) $run->ticks;
        $own = $this->best((int) $run->user_id, $week);

        if ($own !== null && $own <= $ticks) {
            return false;
        }

        return StackerRun::query()
            ->where('week', $week)
            ->where('status', StackerRunStatus::Verified)
            ->where('user_id', '!=', $run->user_id)
            ->where('ticks', '<', $ticks)
            ->distinct()
            ->count('user_id') < self::HELD_PLACES;
    }

    /**
     * Re-runs the hold rules of today over the runs held for a check,
     * fastest first (a released run may push a slower one out of the first
     * places): a held run that would no longer place (wouldPlace()) is
     * released as it is; one that would goes to the verifier again and is
     * released when today's hints.js finds no hint in it, else it stays held
     * with the new numbers. A verifier that does not answer, or rejects, and a
     * run that would place without a replay to check, leave the run held as
     * it was. Released runs are verified from now on, marked
     * `review.decision` = `released` (the review list shows it), keep their
     * replay among their week's fastest and join their week's board. Safe to
     * run again: only runs still `review` are read. Returns how many were
     * released.
     */
    public function releaseHeld(CarbonInterface $now, ?Verifier $verifier = null): int
    {
        $verifier ??= app(Verifier::class);
        $released = 0;

        foreach (StackerRun::query()->where('status', StackerRunStatus::Review)->orderBy('ticks')->orderBy('id')->pluck('id') as $id) {
            $run = StackerRun::query()->whereKey($id)->first();

            if ($run === null || $run->status !== StackerRunStatus::Review) {
                continue;
            }

            $flags = (array) $run->flags;

            if ($this->wouldPlace($run, (string) $run->week)) {
                // without its replay nothing can be checked again: it stays held
                $verdict = $run->replay === null ? StackerVerdict::unavailable('replay-dropped') : $verifier->verify($run);

                if ($verdict->outcome !== StackerVerdict::VERIFIED) {
                    continue;
                }

                $flags['hints'] = $verdict->hints ?? ['flags' => []];

                if ($flags['hints']['flags'] !== []) {
                    StackerRun::query()->whereKey($run->id)->where('status', StackerRunStatus::Review)
                        ->update(['flags' => json_encode($flags), 'updated_at' => $now->format('Y-m-d H:i:s.v')]);

                    continue;
                }
            }

            $taken = StackerRun::query()
                ->whereKey($run->id)
                ->where('status', StackerRunStatus::Review)
                ->update($this->stored([
                    'status' => StackerRunStatus::Verified,
                    'verified_at' => $now,
                    'flags' => json_encode([...$flags, 'review' => ['decision' => 'released', 'at' => $now->toIso8601ZuluString()]]),
                    'updated_at' => $now,
                ])) === 1;

            if ($taken) {
                $run->refresh();
                $this->keepWeekTop((string) $run->week);
                $released++;

                try {
                    app(BlockfillWeeks::class)->record($run, $now);
                } catch (Throwable $exception) {
                    // the release stands; the hourly `blockfill:weeks` sweep joins the run to its board
                    report($exception);
                }
            }
        }

        return $released;
    }

    /**
     * Pending runs that still hold a replay go to the verifier again, oldest
     * first, from the sweep: `redrive_batch` of them when the newest verdict
     * of the last 10 minutes was a real answer (the verifier is up), one as a
     * probe otherwise (no verdict lately, or the newest one was "unavailable"):
     * after an outage nobody can submit while the slots are full, so without
     * the probe there would be no verdict to learn from. Returns how many.
     */
    public function redriveWaiting(CarbonInterface $now): int
    {
        $last = Cache::get(self::LAST_VERDICT_KEY);
        $answering = is_array($last) && ($last['answered'] ?? false) === true
            && (int) ($last['at'] ?? 0) >= $now->copy()->subMinutes(10)->getTimestamp();

        return $this->reverifyPending($answering ? max(1, (int) config('esports.blockfill.redrive_batch')) : 1, $now);
    }

    /**
     * Held runs stay bounded like verified replays (P5): per player and week
     * only the fastest held run waits (an earlier tie stays), and per week
     * only the `replay_keep_top` fastest held runs. Every other one becomes
     * practice without its replay (reason `review-superseded`): it could not
     * change a standing the kept one would not.
     */
    public function keepHeld(StackerRun $run, CarbonInterface $now): void
    {
        DB::transaction(function () use ($run, $now): void {
            $week = (string) $run->week;
            $held = fn () => StackerRun::query()->where('week', $week)->where('status', StackerRunStatus::Review);
            $mine = $held()->where('user_id', $run->user_id)->orderBy('ticks')->orderBy('id')->pluck('id')->all();
            $keep = $held()->orderBy('ticks')->orderBy('id')->limit(max(0, (int) config('esports.blockfill.replay_keep_top')))->pluck('id')->all();
            $drop = [...array_slice($mine, 1), ...$held()->whereNotIn('id', $keep)->pluck('id')->all()];

            if ($drop !== []) {
                StackerRun::query()->whereIn('id', array_unique($drop))->where('status', StackerRunStatus::Review)
                    ->update($this->stored(['status' => StackerRunStatus::Practice, 'reason' => 'review-superseded', 'replay' => null, 'updated_at' => $now]));
            }
        });
    }

    /**
     * An admin approves a held run (P5): it is verified from now on, keeps its
     * replay while among its week's fastest (keepWeekTop()) and joins its
     * week's leaderboard (BlockfillWeeks::record()). False when the run is
     * no longer held (another admin decided first).
     */
    public function approve(StackerRun $run, User $admin, CarbonInterface $now): bool
    {
        $approved = StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Review)
            ->update($this->stored([
                'status' => StackerRunStatus::Verified,
                'verified_at' => $now,
                'flags' => json_encode([...(array) $run->flags, 'review' => ['decision' => 'approved', 'by' => $admin->id, 'at' => $now->toIso8601ZuluString()]]),
                'updated_at' => $now,
            ])) === 1;

        if ($approved) {
            $run->refresh();
            $this->keepWeekTop((string) $run->week);
            app(BlockfillWeeks::class)->record($run, $now);
        }

        return $approved;
    }

    /**
     * An admin rejects a held run (P5): it never counts, its replay goes
     * (reason `review`). False when the run is no longer held.
     */
    public function reject(StackerRun $run, User $admin, CarbonInterface $now): bool
    {
        return StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Review)
            ->update($this->stored([
                'status' => StackerRunStatus::Rejected,
                'reason' => 'review',
                'replay' => null,
                'flags' => json_encode([...(array) $run->flags, 'review' => ['decision' => 'rejected', 'by' => $admin->id, 'at' => $now->toIso8601ZuluString()]]),
                'updated_at' => $now,
            ])) === 1;
    }

    /**
     * The most inputs a run of `$ticks` may carry.
     */
    public static function maxInputs(int $ticks): int
    {
        $limits = (array) config('esports.blockfill.limits');

        return (int) ceil($ticks * (float) $limits['inputs_per_tick']) + (int) $limits['input_slack'];
    }

    /**
     * The number of inputs in a replay (resources/js/stacker/replay.js), read
     * to the last byte: canonical base64url and varints, an engine name and
     * settings within the limits, every input's tick within limits.ticks, and
     * exactly the declared number of inputs with no byte after them. Null
     * when any of that fails: a header that lies about its count included.
     */
    public static function replayInputCount(string $replay): ?int
    {
        $bytes = base64_decode(strtr($replay, '-_', '+/'), true);

        if ($bytes === false || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $replay) {
            return null;
        }

        $at = 0;
        $length = strlen($bytes);
        $varint = function () use ($bytes, $length, &$at): ?int {
            $value = 0;

            for ($shift = 0; $shift < 28; $shift += 7) {
                if ($at >= $length) {
                    return null;
                }

                $byte = ord($bytes[$at++]);
                $value |= ($byte & 0x7F) << $shift;

                if (($byte & 0x80) === 0) {
                    // canonical only, as the decoder: a last byte of 0 after others is the same number written longer
                    return $byte === 0 && $shift > 0 ? null : $value;
                }
            }

            return null;
        };

        $limits = (array) config('esports.blockfill.limits');
        $engineLength = $varint() === 1 ? $varint() : null;

        if ($engineLength === null || $engineLength < 1 || $engineLength > 16 || $at + $engineLength + 16 > $length
            || preg_match('/^[a-z0-9]+$/', substr($bytes, $at, $engineLength)) !== 1) {
            return null;
        }

        $at += $engineLength + 16;

        foreach (StackerSettings::LIMITS as [$low, $high]) {
            $value = $varint();

            if ($value === null || $value < $low || $value > $high) {
                return null;
            }
        }

        $count = $varint();

        if ($count === null || $count > (int) $limits['inputs']) {
            return null;
        }

        $tick = 0;

        for ($i = 0; $i < $count; $i++) {
            $packed = $varint();

            if ($packed === null) {
                return null;
            }

            $tick += $packed >> 4;

            if ($tick > (int) $limits['ticks']) {
                return null;
            }
        }

        return $at === $length ? $count : null;
    }

    /**
     * The week a run counts in: the date of its Monday, 00:00 Europe/Berlin.
     */
    public static function weekOf(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone('Europe/Berlin')->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * Only the `replay_keep_top` fastest verified runs of a week keep their
     * replay, and the `replay_keep_shared` fastest that are moments
     * (BlockfillMoments::of(), marked `flags.moment` when first judged) or
     * were shared (`flags.shared`, markShared()): a moment's page plays its
     * replay to whoever follows a shared link, so the replay has to be there
     * when its player shares it and stay there after. Every other verified
     * run of that week drops it. Both lists are bounded per week, so
     * storage stays bounded however many accounts play. The runs outside
     * the fastest not judged yet are a few (the rest dropped theirs or were
     * marked before); then one indexed query per keep list (week, status,
     * ticks) and one update, in one transaction.
     */
    public function keepWeekTop(string $week): void
    {
        // one transaction: a second worker's verification cannot fall between the top and the update
        DB::transaction(function () use ($week): void {
            $fastest = fn (int $limit) => StackerRun::query()
                ->where('week', $week)
                ->where('status', StackerRunStatus::Verified)
                ->orderBy('ticks')
                ->orderBy('id')
                ->limit(max(0, $limit));
            $top = $fastest((int) config('esports.blockfill.replay_keep_top'))->pluck('id')->all();
            $moments = app(BlockfillMoments::class);

            $unjudged = StackerRun::query()
                ->whereIn('id', $this->weekReplayHolders($week))
                ->when($top !== [], fn ($outside) => $outside->whereNotIn('id', $top))
                ->whereNull('flags->moment')->whereNull('flags->shared')
                ->get(['id', 'user_id', 'status', 'ticks', 'submitted_at', 'week', 'flags']);

            foreach ($unjudged as $run) {
                if ($moments->of($run) !== null) {
                    StackerRun::query()->whereKey($run->id)->update(['flags' => json_encode([...(array) $run->flags, 'moment' => true])]);
                }
            }

            $keep = [
                ...$top,
                ...$fastest((int) config('esports.blockfill.replay_keep_shared'))
                    ->where(fn ($kept) => $kept->whereNotNull('flags->moment')->orWhereNotNull('flags->shared'))->pluck('id')->all(),
            ];

            StackerRun::query()
                ->whereIn('id', $this->weekReplayHolders($week))
                ->when($keep !== [], fn ($outside) => $outside->whereNotIn('id', $keep))
                ->update(['replay' => null]);
        });
    }

    /**
     * Its owner opened the share sheet of this moment: the run keeps its
     * replay from now on (keepWeekTop()), as its link may be out there.
     */
    public function markShared(StackerRun $run): void
    {
        $flags = (array) $run->flags;

        if (! isset($flags['shared'])) {
            StackerRun::query()->whereKey($run->id)->update(['flags' => json_encode([...$flags, 'shared' => now()->getTimestamp()])]);
            $run->flags = [...$flags, 'shared' => now()->getTimestamp()];
        }
    }

    /**
     * The ids of a week's verified runs that still hold a replay. On SQLite
     * the partial index (week where replay is not null) is named, as the
     * planner otherwise takes (week, status, ticks) and reads the whole week.
     */
    public function weekReplayHolders(string $week): QueryBuilder
    {
        $from = DB::connection()->getDriverName() === 'sqlite'
            ? DB::raw('"stacker_runs" INDEXED BY "stacker_runs_week_replay_index"')
            : 'stacker_runs';

        return DB::query()
            ->from($from)
            ->select('id')
            ->where('week', $week)
            ->where('status', StackerRunStatus::Verified->value)
            ->whereNotNull('replay');
    }

    /**
     * Verifications still running `verifier.stale_minutes` after submission
     * (a lost job, a stopped worker) become pending; returns how many.
     */
    public function sweepStale(CarbonInterface $now): int
    {
        $before = $now->copy()->subMinutes((int) config('esports.blockfill.verifier.stale_minutes'));

        // measured from when the run went to the verifier (submission or reverifyPending()), kept in updated_at
        $stale = StackerRun::query()
            ->where('status', StackerRunStatus::Verifying)
            ->where('updated_at', '<', $before->format('Y-m-d H:i:s.v'))
            ->update($this->stored(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-stale', 'updated_at' => $now]));

        $this->dropOldPendingReplays($now);

        return $stale;
    }

    /**
     * A run pending longer than `pending_replay_hours` after its submission
     * drops its replay and its network key (reason `replay-dropped`): it is
     * never verified, the player plays again. Keeps what an outage leaves
     * behind small.
     */
    public function dropOldPendingReplays(CarbonInterface $now): int
    {
        $before = $now->copy()->subHours((int) config('esports.blockfill.pending_replay_hours'));

        return StackerRun::query()
            ->where('status', StackerRunStatus::Pending)
            ->whereNotNull('replay')
            ->where('submitted_at', '<', $before->format('Y-m-d H:i:s.v'))
            ->update($this->stored(['replay' => null, 'network' => null, 'reason' => 'replay-dropped', 'updated_at' => $now]));
    }

    /**
     * Whether a run of this player from this network may not wait for the
     * verifier now: `replay_inflight_max` runs hold a replay already, or
     * this account holds `inflight_per_account` of them, or this network
     * `inflight_per_network` (`$network` is a key from networkKey()). The
     * shares keep one account or network from taking every slot.
     */
    public function inflightFull(?int $userId = null, ?string $network = null): bool
    {
        $waiting = fn () => StackerRun::query()
            ->whereIn('status', [StackerRunStatus::Verifying, StackerRunStatus::Pending])
            ->whereNotNull('replay');

        $config = (array) config('esports.blockfill');

        return ($userId !== null && $waiting()->where('user_id', $userId)->count() >= (int) $config['inflight_per_account'])
            || ($network !== null && $waiting()->where('network', $network)->count() >= (int) $config['inflight_per_network'])
            || $waiting()->count() >= (int) $config['replay_inflight_max'];
    }

    /**
     * Sends up to `$limit` pending runs, oldest first, to the verifier again;
     * returns how many. Console only (`stacker:reverify`).
     */
    public function reverifyPending(int $limit, CarbonInterface $now): int
    {
        $sent = 0;

        // submitted_at stays: it is the time of play and decides the week the run counts in
        foreach (StackerRun::query()->where('status', StackerRunStatus::Pending)->whereNotNull('replay')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $taken = StackerRun::query()
                ->whereKey($id)
                ->where('status', StackerRunStatus::Pending)
                ->whereNotNull('replay')
                ->update($this->stored(['status' => StackerRunStatus::Verifying, 'reason' => null, 'updated_at' => $now])) === 1;

            if ($taken) {
                VerifyStackerRun::dispatch((int) $id);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The rate-limit key of a client address: an IPv4 address as it is, an
     * IPv6 address by its /64 (one household or one server gets a whole /64).
     */
    public static function network(?string $ip): string
    {
        $packed = $ip === null ? false : @inet_pton($ip);

        if ($packed === false) {
            return 'unknown';
        }

        // an IPv4 address written as IPv6 (::ffff:a.b.c.d) is that IPv4 address
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return strlen($packed) === 16 ? bin2hex(substr($packed, 0, 8)).'::/64' : (string) inet_ntop($packed);
    }

    /**
     * What a waiting run stores of its network (StackerRuns::network()): a
     * keyed hash, the same convention as InvoiceCaps::ipHash(), so the
     * per-network share can count it without the table holding an address
     * next to an account. The run drops it once it is decided, or with its
     * replay after `pending_replay_hours`.
     */
    public static function networkKey(string $network): string
    {
        return hash_hmac('sha256', $network, (string) config('app.key'));
    }

    /**
     * The player's best verified time in ticks, or null: of all time, or of
     * one week (`$week` as weekOf() gives it, P4: the weekly hunt starts
     * every player afresh on Monday).
     */
    public function best(User|int $user, ?string $week = null): ?int
    {
        $best = StackerRun::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->where('status', StackerRunStatus::Verified)
            ->when($week !== null, fn ($query) => $query->where('week', $week))
            ->min('ticks');

        return $best === null ? null : (int) $best;
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(StackerRun $run, int $ticks, CarbonInterface $now): array
    {
        $from = $run->started_at ?? $run->issued_at;
        $elapsedMs = (int) floor($from->diffInMilliseconds($now, false));
        $playedMs = intdiv($ticks * 1000, 60);

        if ($elapsedMs < $playedMs || $elapsedMs > $playedMs + 1000 * (int) config('esports.blockfill.slack_seconds')) {
            return ['status' => StackerRunStatus::Rejected, 'reason' => 'clock'];
        }

        // Every ranked run is verified, a slower one too: it is an attempt on /matches and counts for the quest,
        // while the week's leaderboard keeps each player's best (ScoreRuns::standings()).
        return ['status' => StackerRunStatus::Verifying];
    }

    private function expireIfDue(StackerRun $run, CarbonInterface $now): bool
    {
        if ($run->status !== StackerRunStatus::Issued || $run->started_at !== null || $now->lessThanOrEqualTo($this->expiresAt($run))) {
            return false;
        }

        StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Issued)
            ->whereNull('started_at')
            ->update($this->stored(['status' => StackerRunStatus::Abandoned, 'reason' => 'expired', 'updated_at' => $now]));

        return true;
    }

    /**
     * Query-builder updates skip the model's casts: enums to their value,
     * times in the model's millisecond format.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function stored(array $fields): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof StackerRunStatus => $value->value,
            $value instanceof CarbonInterface => $value->format('Y-m-d H:i:s.v'),
            default => $value,
        }, $fields);
    }
}
