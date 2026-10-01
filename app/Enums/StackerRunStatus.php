<?php

namespace App\Enums;

/**
 * State of a Blockfill run (plan "Blockfill", P2; App\Support\Stacker\StackerRuns).
 *
 * Issued: token and seed handed out, not yet submitted. Verifying: submitted,
 * its job is queued. Verified: the verifier replayed exactly the claimed
 * time. Rejected: the submission or the replay failed a check (`reason`).
 * Abandoned: a newer run replaced it, or its token expired unstarted.
 * Pending: the verifier was not available; no score until it is checked.
 * Practice: only runs from before 2026-10-01, when a valid submission
 * that did not beat the player's verified best of the week was kept but
 * not verified. Since then every valid submission is verified.
 */
enum StackerRunStatus: string
{
    case Issued = 'issued';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Abandoned = 'abandoned';
    case Pending = 'pending';
    case Practice = 'practice';
}
