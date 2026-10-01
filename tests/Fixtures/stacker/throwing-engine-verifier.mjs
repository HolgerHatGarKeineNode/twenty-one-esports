// verify.mjs with an engine that throws while replaying (tests/Feature/Stacker/VerifierCliTest.php):
// verify.mjs must catch it and answer {ok: false, reason: 'crash'} itself.
import { main } from '../../../resources/js/stacker/verify.mjs';

await main({
    bf1: async () => ({
        run() {
            throw new Error('engine fault on purpose');
        },
    }),
});
