<?php

namespace App\Http\Controllers;

use App\Enums\StackerRunStatus;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerBusy;
use App\Support\Stacker\StackerRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Blockfill runs over JSON (plan "Blockfill", P2; routes/stacker.php, only
 * registered while `esports.blockfill.enabled` is on): issue a run, mark its
 * start, submit its replay, read its status. Logged-in only, CSRF-checked like every web
 * route, throttled per player. The rules live in StackerRuns.
 *
 * A token that is not the player's answers 404 (whether it exists is none of
 * their business); a used token 409; an expired or replaced one 410.
 */
class StackerRunController extends Controller
{
    public function issue(Request $request, StackerRuns $runs): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        [$run, $token] = $runs->issue($user, now());

        return response()->json([
            'token' => $token,
            'seed' => $run->seed,
            'engine' => $run->engine,
            'expires_at' => $runs->expiresAt($run)->toIso8601ZuluString('millisecond'),
            'limits' => config('esports.blockfill.limits'),
            'best' => $runs->best($user, StackerRuns::weekOf(now())),
            'best_all_time' => $runs->best($user),
        ], 201);
    }

    public function start(Request $request, string $token, StackerRuns $runs): Response|JsonResponse
    {
        $run = $this->run($request, $token, $runs);

        if ($runs->start($run, now())) {
            return response()->noContent();
        }

        return $this->gone($run->refresh());
    }

    public function submit(Request $request, string $token, StackerRuns $runs): JsonResponse
    {
        $run = $this->run($request, $token, $runs);

        try {
            $submitted = $runs->submit($run, $request->getContent(), $request->json('replay'), $request->json('ticks'), $request->json('hash'), $request->json('input'), now(), StackerRuns::network($request->ip()));
        } catch (StackerBusy) {
            // below slack_seconds: a retry still fits the wall-clock bracket
            return response()->json(['status' => 'busy', 'reason' => 'busy'], 503)->header('Retry-After', '10');
        }

        if ($submitted === null) {
            return response()->json(['status' => $run->refresh()->status->value, 'reason' => 'used'], 409);
        }

        if ($submitted->status === StackerRunStatus::Abandoned) {
            return $this->gone($submitted);
        }

        return response()->json(['status' => $submitted->status->value, 'reason' => $submitted->reason], 202);
    }

    /**
     * A run's state for the result screen, which asks until the verdict is in.
     */
    public function show(Request $request, string $token, StackerRuns $runs): JsonResponse
    {
        $run = $this->run($request, $token, $runs);
        $user = $request->user();
        assert($user instanceof User);

        return response()->json([
            'status' => $run->status->value,
            'reason' => $run->reason,
            'ticks' => $run->ticks,
            // P4: the best of this week is the one a run has to beat; the all-time best is shown beside it
            'best' => $runs->best($user, StackerRuns::weekOf(now())),
            'best_all_time' => $runs->best($user),
        ]);
    }

    private function run(Request $request, string $token, StackerRuns $runs): StackerRun
    {
        $user = $request->user();
        assert($user instanceof User);

        $run = $runs->find($user, $token);
        abort_if($run === null, 404);

        return $run;
    }

    private function gone(StackerRun $run): JsonResponse
    {
        $status = $run->status === StackerRunStatus::Issued ? 409 : 410;

        return response()->json(['status' => $run->status->value, 'reason' => $run->reason ?? 'used'], $status);
    }
}
