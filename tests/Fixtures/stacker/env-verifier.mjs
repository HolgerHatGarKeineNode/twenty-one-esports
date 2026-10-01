// Reports what the verifier process can see of its environment (tests/Feature/Stacker/VerifierCliTest.php):
// `engine` if a secret-looking variable reached it, `oversize` if a plain one did not (the positive control),
// `seed` if the secret was stripped and the rest passed.
let reason = 'seed';
if (process.env.STACKER_PROBE_TOKEN !== undefined) {
    reason = 'engine';
} else if (process.env.STACKER_PROBE_PLAIN !== 'visible') {
    reason = 'oversize';
}
process.stdout.write(`${JSON.stringify({ ok: false, reason })}\n`);
