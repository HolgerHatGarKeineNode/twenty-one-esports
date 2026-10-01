// A verifier that never answers in time (tests/Feature/Stacker/VerifierCliTest.php): it sleeps, it does not spin.
await new Promise((resolve) => setTimeout(resolve, 5000));
