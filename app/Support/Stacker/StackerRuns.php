<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 *   in this order: body size (before the replay is looked at), shape of
 *   replay/ticks/hash, the wall-clock bracket (time from start, or issue if
 *   never started, to submission at least the played time and at most
 *   `slack_seconds` more), and whether the claim beats the player's
 *   verified best. A claim that does not is stored as `practice` and never
 *   verified: it could not change any standing. The rest is `verifying` and
 *   goes to the verifier's own queue (VerifyStackerRun).
 * - finish(): the verifier's verdict; an unavailable verifier leaves the run
 *   `pending` (fail-closed: no score until it is checked).
 *
 * Storage stays bounded (security audits F1, N1, R1). The size of a replay
 * is bounded by its played time: a submission with more inputs than
 * ceil(ticks * limits.inputs_per_tick) + limits.input_slack is refused here,
 * before the verifier (which checks the same on the inputs it replays), so
 * no-op inputs cannot pad a run. The submitted replay is kept only while a
 * run is verifying or pending; a verified run keeps the replay the verifier
 * answered with; rejected and practice runs keep none. Stored replays stay
 * within `replay_bytes_per_player` per player (enforceReplayBudget()) and
 * `replay_bytes_total` for the league (enforceReplayCeiling()), personal
 * bests always kept. Runs without a verified time are pruned after `prune_days`
 * (StackerRun::prunable()). sweepStale() gives up verifications that never
 * came back, reverifyPending() sends pending runs again (console only).
 */
final class StackerRuns
{
    public const TOKEN_LENGTH = 40;

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
     */
    public function submit(StackerRun $run, string $body, mixed $replay, mixed $ticks, mixed $hash, CarbonInterface $now): ?StackerRun
    {
        if ($this->expireIfDue($run, $now)) {
            return $run->refresh();
        }

        $limits = (array) config('esports.blockfill.limits');
        $fields = ['submitted_at' => $now, 'updated_at' => $now];

        if (strlen($body) > (int) $limits['bytes']) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'oversize'];
        } elseif (! is_string($replay) || $replay === '' || preg_match('/^[A-Za-z0-9_-]+$/', $replay) !== 1
            || ! is_int($ticks) || $ticks < 1 || $ticks > (int) $limits['ticks']
            || ! is_string($hash) || preg_match('/^[0-9a-f]{8}$/', $hash) !== 1
            || ($count = self::replayInputCount($replay)) === null) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'malformed'];
        } elseif ($count > self::maxInputs($ticks)) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'oversize'];
        } else {
            $fields += ['ticks' => $ticks, 'state_hash' => $hash];
            $fields += $this->outcome($run, $ticks, $now);

            if ($fields['status'] === StackerRunStatus::Verifying) {
                $fields['replay'] = $replay;
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
        $fields = match ($verdict->outcome) {
            StackerVerdict::VERIFIED => ['status' => StackerRunStatus::Verified, 'settings' => json_encode($verdict->settings), 'verified_at' => $now, 'reason' => null, 'replay' => $verdict->replay],
            StackerVerdict::REJECTED => ['status' => StackerRunStatus::Rejected, 'reason' => $verdict->reason, 'replay' => null],
            default => ['status' => StackerRunStatus::Pending, 'reason' => $verdict->reason],
        };

        $finished = StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Verifying)
            ->update($this->stored($fields + ['updated_at' => $now])) === 1;

        if ($finished && $verdict->outcome === StackerVerdict::VERIFIED) {
            $this->enforceReplayBudget($run->user_id);
            $this->enforceReplayCeiling();
        }
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
     * The input count a replay declares in its header (resources/js/stacker/replay.js:
     * version, engine, seed, das, arr, sdf, count), or null if the header does not parse.
     */
    public static function replayInputCount(string $replay): ?int
    {
        $bytes = base64_decode(strtr($replay, '-_', '+/'), true);

        if ($bytes === false) {
            return null;
        }

        $at = 0;
        $varint = function () use ($bytes, &$at): ?int {
            $value = 0;

            for ($shift = 0; $shift < 28; $shift += 7) {
                if ($at >= strlen($bytes)) {
                    return null;
                }

                $byte = ord($bytes[$at++]);
                $value |= ($byte & 0x7F) << $shift;

                if (($byte & 0x80) === 0) {
                    return $value;
                }
            }

            return null;
        };

        $version = $varint();
        $engineLength = $varint();

        if ($version === null || $engineLength === null || $engineLength > 16) {
            return null;
        }

        $at += $engineLength + 16;

        foreach (range(1, 3) as $setting) {
            if ($varint() === null) {
                return null;
            }
        }

        return $varint();
    }

    /**
     * Above `replay_bytes_total` for all stored replays together, only
     * personal bests keep theirs: the other verified replays go, oldest
     * first, until the total fits again. While the ceiling is reached a
     * warning goes to the log, once a day.
     */
    public function enforceReplayCeiling(): void
    {
        $ceiling = (int) config('esports.blockfill.replay_bytes_total');
        $total = (int) StackerRun::query()->whereNotNull('replay')->sum(DB::raw('length(replay)'));

        if ($total <= $ceiling) {
            return;
        }

        if (Cache::add('stacker:replay-ceiling-warned:'.now()->toDateString(), true, now()->addDay())) {
            Log::warning('Blockfill replays reached the league-wide ceiling; only personal bests keep their replay now.', ['bytes' => $total, 'ceiling' => $ceiling]);
        }

        $verified = StackerRunStatus::Verified->value;
        // a personal best: no verified run of the same player is faster, or as fast and older
        $candidates = StackerRun::query()
            ->from('stacker_runs as r')
            ->where('r.status', $verified)
            ->whereNotNull('r.replay')
            ->whereExists(fn ($better) => $better->from('stacker_runs as b')
                ->whereColumn('b.user_id', 'r.user_id')
                ->where('b.status', $verified)
                ->where(fn ($faster) => $faster->whereColumn('b.ticks', '<', 'r.ticks')
                    ->orWhere(fn ($tie) => $tie->whereColumn('b.ticks', 'r.ticks')->whereColumn('b.id', '<', 'r.id'))))
            ->orderBy('r.id')
            ->select(['r.id', DB::raw('length(r.replay) as bytes')])
            ->toBase()
            ->cursor();

        foreach ($candidates as $candidate) {
            if ($total <= $ceiling) {
                break;
            }

            StackerRun::query()->whereKey($candidate->id)->update(['replay' => null]);
            $total -= (int) $candidate->bytes;
        }
    }

    /**
     * Drops stored replays of the player's verified runs, oldest first, until
     * all their stored replays fit `replay_bytes_per_player`. The personal
     * best keeps its replay even when it alone is over the budget; replays
     * still waiting for the verifier are never dropped.
     */
    public function enforceReplayBudget(int $userId): void
    {
        $budget = (int) config('esports.blockfill.replay_bytes_per_player');
        $stored = StackerRun::query()
            ->where('user_id', $userId)
            ->whereNotNull('replay')
            ->get(['id', 'status', 'ticks', 'replay']);

        $total = $stored->sum(fn (StackerRun $run): int => strlen((string) $run->replay));

        if ($total <= $budget) {
            return;
        }

        $best = $stored->where('status', StackerRunStatus::Verified)->sortBy([['ticks', 'asc'], ['id', 'asc']])->first();

        foreach ($stored->where('status', StackerRunStatus::Verified)->sortBy('id') as $candidate) {
            if ($total <= $budget) {
                break;
            }

            if ($candidate->id === $best?->id) {
                continue;
            }

            StackerRun::query()->whereKey($candidate->id)->update(['replay' => null]);
            $total -= strlen((string) $candidate->replay);
        }
    }

    /**
     * Verifications still running `verifier.stale_minutes` after submission
     * (a lost job, a stopped worker) become pending; returns how many.
     */
    public function sweepStale(CarbonInterface $now): int
    {
        $before = $now->copy()->subMinutes((int) config('esports.blockfill.verifier.stale_minutes'));

        return StackerRun::query()
            ->where('status', StackerRunStatus::Verifying)
            ->where('submitted_at', '<', $before->format('Y-m-d H:i:s.v'))
            ->update($this->stored(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-stale', 'updated_at' => $now]));
    }

    /**
     * Sends up to `$limit` pending runs, oldest first, to the verifier again;
     * returns how many. Console only (`stacker:reverify`).
     */
    public function reverifyPending(int $limit, CarbonInterface $now): int
    {
        $sent = 0;

        foreach (StackerRun::query()->where('status', StackerRunStatus::Pending)->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $taken = StackerRun::query()
                ->whereKey($id)
                ->where('status', StackerRunStatus::Pending)
                ->update($this->stored(['status' => StackerRunStatus::Verifying, 'reason' => null, 'submitted_at' => $now, 'updated_at' => $now])) === 1;

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
     * The player's best verified time in ticks, or null.
     */
    public function best(User|int $user): ?int
    {
        $best = StackerRun::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->where('status', StackerRunStatus::Verified)
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

        $best = $this->best($run->user_id);

        if ($best !== null && $ticks >= $best) {
            return ['status' => StackerRunStatus::Practice];
        }

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
