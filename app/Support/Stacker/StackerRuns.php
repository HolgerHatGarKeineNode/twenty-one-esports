<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 * Storage stays bounded (security audit F1): a replay is kept only for a run
 * that is verifying, pending or verified, and for the latest
 * `practice_replays_kept` practice runs of a player; a rejected run keeps
 * none. Runs without a verified time are pruned after `prune_days`
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
                'seed' => bin2hex(random_bytes(16)),
                'engine' => (string) config('esports.blockfill.engine'),
                'status' => StackerRunStatus::Issued,
                'issued_at' => $now,
            ]);
        });

        return [$run, $token];
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
            || ! is_string($hash) || preg_match('/^[0-9a-f]{8}$/', $hash) !== 1) {
            $fields += ['status' => StackerRunStatus::Rejected, 'reason' => 'malformed'];
        } else {
            $fields += ['ticks' => $ticks, 'state_hash' => $hash];
            $fields += $this->outcome($run, $ticks, $now);

            if (in_array($fields['status'], [StackerRunStatus::Verifying, StackerRunStatus::Practice], true)) {
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

        if ($run->status === StackerRunStatus::Practice) {
            $this->dropOldPracticeReplays($run->user_id);
        }

        return $run;
    }

    /**
     * Applies the verifier's verdict to a run still `verifying`.
     */
    public function finish(StackerRun $run, StackerVerdict $verdict, CarbonInterface $now): void
    {
        $fields = match ($verdict->outcome) {
            StackerVerdict::VERIFIED => ['status' => StackerRunStatus::Verified, 'settings' => json_encode($verdict->settings), 'verified_at' => $now, 'reason' => null],
            StackerVerdict::REJECTED => ['status' => StackerRunStatus::Rejected, 'reason' => $verdict->reason, 'replay' => null],
            default => ['status' => StackerRunStatus::Pending, 'reason' => $verdict->reason],
        };

        StackerRun::query()
            ->whereKey($run->id)
            ->where('status', StackerRunStatus::Verifying)
            ->update($this->stored($fields + ['updated_at' => $now]));
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

        return strlen($packed) === 16 ? bin2hex(substr($packed, 0, 8)).'::/64' : (string) $ip;
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

    private function dropOldPracticeReplays(int $userId): void
    {
        $keep = StackerRun::query()
            ->where('user_id', $userId)
            ->where('status', StackerRunStatus::Practice)
            ->whereNotNull('replay')
            ->orderByDesc('id')
            ->limit(max(0, (int) config('esports.blockfill.practice_replays_kept')))
            ->pluck('id');

        StackerRun::query()
            ->where('user_id', $userId)
            ->where('status', StackerRunStatus::Practice)
            ->whereNotNull('replay')
            ->whereNotIn('id', $keep)
            ->update(['replay' => null]);
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
