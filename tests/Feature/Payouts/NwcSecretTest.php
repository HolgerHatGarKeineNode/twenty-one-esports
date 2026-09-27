<?php

use App\Jobs\PayTournamentPayout;
use App\Models\Admin;
use App\Models\User;
use App\Support\Wallet\NwcCipher;
use App\Support\Wallet\NwcConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
| P9 DoD: the NWC secret is in no log and no response. Measured over a whole
| run with every path that talks about the wallet: approval, payments that
| succeed, fail, lose their answer and meet a broken Lightning address, a
| zap, the pages, the LNURL endpoint, an exception thrown with the secret
| as an argument (traces with arguments on), the Livewire snapshots, the
| queued job payload, the published events and every row of the database.
*/

afterEach(function () {
    ini_restore('zend.exception_ignore_args');
    ini_restore('zend.exception_string_param_max_len');
});

/**
 * The places the secrets could have leaked to in this run.
 *
 * @param  list<string>  $extra
 */
function leakSurface(string $logPath, array $extra): string
{
    $rows = '';

    foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
        $rows .= json_encode(DB::table($table->name)->get()->all());
    }

    return (File::exists($logPath) ? File::get($logPath) : '').$rows.implode("\n", $extra);
}

test('the NWC secrets reach no log, exception, response, Livewire payload, job, event or database row', function () {
    // Traces with their arguments, in full: as a server with these settings would log them.
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000000');
    $logPath = storage_path('framework/testing/nwc-secret-'.getmypid().'.log');
    File::delete($logPath);
    config(['logging.default' => 'nwc_secret', 'logging.channels.nwc_secret' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);

    $league = fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet, broken: ['player2']);
    // A 12-character prefix counts as a leak too: a trace may cut string arguments short.
    $secrets = [substr($wallet->clients['pay']['secret'], 0, 12), substr($league->clients['pay']['secret'], 0, 12), substr($league->clients['receive']['secret'], 0, 12)];
    $tournament = finishedPoolTournament($wallet, 40_000, 4);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $seen = [];

    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('approve');
    $payouts = $tournament->payouts()->orderBy('id')->get();

    // One refused, one without an answer, one to a broken address, one fine.
    $wallet->failNext = 'PAYMENT_FAILED';
    $page->call('pay', $payouts[0]->id);
    $wallet->loseNextAnswer = true;
    $page->call('pay', $payouts[2]->id);
    $page->call('pay', $payouts[1]->id);
    $page->call('pay', $payouts[3]->id);
    $page->call('pay', $payouts[0]->id);
    $seen[] = $page->html().json_encode($page->snapshot);

    $pool = Livewire::test('tournament-pool', ['tournament' => $tournament->refresh()]);
    $seen[] = $pool->html().json_encode($pool->snapshot);

    // An exception thrown with the secret as an argument, then reported with its trace.
    try {
        NwcCipher::encrypt(NwcCipher::NIP44, 'x', $wallet->clients['pay']['secret'], 'not-a-pubkey');
    } catch (RuntimeException $exception) {
        report($exception);
        $seen[] = $exception->getTraceAsString().$exception->getMessage();
    }

    // A connection that is dumped, cast or serialized shows no secret.
    $connection = NwcConnection::fromUri($wallet->uri('pay'));
    $seen[] = print_r($connection, true).var_export((array) $connection, true).json_encode($connection);
    expect(fn () => serialize($connection))->toThrow(LogicException::class);
    Log::warning('wallet', ['connection' => $connection]);

    // The job as it would sit in the queue.
    $seen[] = serialize(new PayTournamentPayout($payouts[0]->id));

    foreach ([route('admin.payouts', ['tournament' => $tournament->id]), route('tournaments.show', $tournament), route('lnurl.pay', ['username' => 'pool']), route('lnurl.callback', ['username' => 'pool', 'amount' => 1000])] as $url) {
        $seen[] = $this->actingAs($admin)->get($url)->assertOk()->getContent();
    }

    $surface = leakSurface($logPath, $seen);

    // The run did reach the paths it claims to measure.
    expect($tournament->payouts()->pluck('status')->map->value->sort()->values()->all())->toContain('paid', 'failed')
        ->and($surface)->toContain('Tournament payout refused by the wallet')
        ->and($surface)->toContain('NWC encryption failed.');

    foreach ($secrets as $secret) {
        expect(str_contains($surface, $secret))->toBeFalse('an NWC secret leaked');
    }

    // Positive control: a secret that does reach the log is found by the same check.
    Log::info('control '.$wallet->clients['pay']['secret']);
    expect(str_contains(leakSurface($logPath, []), $wallet->clients['pay']['secret']))->toBeTrue();

    File::delete($logPath);
});
