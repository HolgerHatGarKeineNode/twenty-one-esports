<?php

namespace App\Support\Stacker;

use RuntimeException;

/**
 * Thrown by StackerRuns::submit() when `replay_inflight_max` runs already
 * wait for the verifier with a replay: the submission is not taken (nothing
 * stored, the token stays usable) and the player is asked to try again
 * shortly.
 */
final class StackerBusy extends RuntimeException {}
