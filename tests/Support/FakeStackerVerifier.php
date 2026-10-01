<?php

namespace Tests\Support;

use App\Models\StackerRun;
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stands in for the Node verifier (App\Support\Stacker\NodeVerifier) in the
 * feature tests: answers `verdict` (verified by default), or throws when
 * `throws` is set, and records the runs it was asked about and the
 * transaction level it was asked at (it must never run inside one).
 */
final class FakeStackerVerifier implements Verifier
{
    public ?StackerVerdict $verdict = null;

    public bool $throws = false;

    /** @var list<int> */
    public array $asked = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function verify(StackerRun $run): StackerVerdict
    {
        $this->asked[] = $run->id;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->throws) {
            throw new RuntimeException('verifier exploded');
        }

        return $this->verdict ?? StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20]);
    }
}
