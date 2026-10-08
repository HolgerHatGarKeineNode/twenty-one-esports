<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\ChampionNotes;
use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

/*
 * The stream bot's champion notes: one note per newly finished tournament
 * with a single champion, the champion tagged and mentioned, the champion
 * slide attached, never twice, a failed send retried with the same event;
 * none without a single champion, for one finished too long ago or for a
 * league week; nothing without the key. The relays are stood in for.
 */

beforeEach(function () {
    $this->league = new TestSigner;
    $this->botKey = new TestSigner;
    $this->dir = storage_path('framework/testing/champion-'.bin2hex(random_bytes(4)));

    config([
        'esports.league.nsec' => $this->league->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.pride_notes.image_dir' => $this->dir,
        'esports.stream_bot.profile_limits' => [],
        'esports.stream_bot.profile_gap_minutes' => 0,
        'esports.relays' => ['wss://league.test'],
        'twentyone.stream.relays' => ['wss://one.test', 'wss://two.test'],
    ]);

    $this->published = [];
    $this->accepts = true;
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = SignedEvent::fromInput($event);

            return collect($relays)->mapWithKeys(fn (string $relay): array => [$relay => new PublishResult($relay, $this->test->accepts, $this->test->accepts ? '' : 'blocked: no')])->all();
        }
    });

    $this->travelTo(Carbon::parse('2026-09-26 12:00:00', 'UTC'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** A published chess tournament of four, played out by the better seeds: "Player 1" is its champion. */
function championTournament(TestSigner $league, array $attributes = []): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $event = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(Tournament::CALENDAR_EVENT, [['d', $tournament->slug]])));
    $tournament->forceFill(['event_id' => $event->id, 'published_at' => now(), ...$attributes])->save();

    return $tournament->refresh();
}

test('a finished tournament gets one champion note with the winner tagged, its naddr and the champion slide, never twice', function () {
    $tournament = championTournament($this->league, ['name' => 'Friday #Blitz Cup']);
    $champion = app(TournamentChampion::class)->of($tournament);
    $winner = $champion->user;

    $log = app(ChampionNotes::class)->run(now()->toImmutable());
    $this->travel(5)->minutes();
    $again = app(ChampionNotes::class)->run(now()->toImmutable());

    $note = $this->published[0];
    $imeta = collect($note->tags)->firstWhere(0, 'imeta');
    $url = substr($imeta[1], 4);
    $hash = substr($imeta[4], 2);

    expect($champion->name)->toBe('Player 1')
        ->and($log)->toBe('tournament '.$tournament->id.': champion posted id='.$note->id.' to 2/2 relays')
        ->and($again)->toBe('no champion notes: no newly finished tournament without its note')
        ->and($this->published)->toHaveCount(1)
        ->and($note->kind)->toBe(1)
        ->and($note->pubkey)->toBe($this->botKey->pubkey)
        ->and($note->hasValidSignature())->toBeTrue()
        ->and($note->content)->toStartWith('🏆 nostr:'.NostrKeys::hexToNpub($winner->pubkey).' wins Friday Blitz Cup (Chess Blitz')
        ->and($note->content)->toContain(route('tournaments.show', $tournament)."\n\nnostr:naddr1", "\n\n".$url)
        ->and($note->content)->not->toContain('#', 'sats')
        ->and(collect($note->tags)->where(0, 'p')->pluck(1)->all())->toBe([$winner->pubkey])
        ->and(collect($note->tags)->firstWhere(0, 'q'))->toBe(['q', '31923:'.$this->league->pubkey.':'.$tournament->slug, 'wss://league.test'])
        ->and(collect($note->tags)->where(0, 't')->all())->toBe([])
        ->and(hash_file('sha256', PrideNotes::imagePath($hash)))->toBe($hash)
        ->and(BotPost::query()->where(['subject_type' => ChampionNotes::SUBJECT, 'subject_id' => $tournament->id])->count())->toBe(1);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('a note no relay took is sent again later as the same event', function () {
    $tournament = championTournament($this->league);
    $this->accepts = false;

    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->toContain('tournament '.$tournament->id.': champion not accepted, retried later');

    // Within the claim nobody touches it; after it, the stored event goes out again.
    $this->travel(5)->minutes();
    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->not->toContain('tournament '.$tournament->id);

    $this->accepts = true;
    $this->travel(6)->minutes();

    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->toContain('champion posted')
        ->and($this->published)->toHaveCount(2)
        ->and($this->published[1]->id)->toBe($this->published[0]->id);
});

test('a paid prize is named in sats, an unpaid one is not', function () {
    $tournament = championTournament($this->league);
    $winner = app(TournamentChampion::class)->of($tournament)->user;
    $payout = fn (string $pubkey, int $place, int $sats) => TournamentPayout::query()->create([
        'tournament_id' => $tournament->id, 'pubkey' => $pubkey, 'name' => 'Player '.$place,
        'place' => $place, 'amount_sats' => $sats, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $pubkey, $place), 'status' => PayoutStatus::Open,
    ]);
    $first = $payout($winner->pubkey, 1, 21000);
    $payout(bin2hex(random_bytes(32)), 2, 999)->forceFill(['status' => PayoutStatus::Paid])->save();

    expect(app(ChampionNotes::class)->compose($tournament, 0)['content'])->not->toContain('sats');

    $first->forceFill(['status' => PayoutStatus::Paid])->save();

    expect(app(ChampionNotes::class)->compose($tournament, 0)['content'])->toContain("\n⚡ 21,000 sats prize paid out\n");
});

test('no note for a tournament without a single champion, one finished more than five days ago, or a league week', function () {
    // Finished without a bracket: no champion can be read.
    $event = NostrEvent::fromSigned(SignedEvent::fromInput($this->league->sign(Tournament::CALENDAR_EVENT, [['d', 'no-champion-cup']])));
    Tournament::factory()->create(['status' => TournamentStatus::Finished, 'slug' => 'no-champion-cup', 'event_id' => $event->id, 'published_at' => now()]);

    // A Blockfill week whose stored bracket would name a champion.
    $week = championTournament($this->league);
    $week->forceFill(['game' => Blockfill::SLUG, 'slug' => 'blockfill-2026-09-21'])->save();
    expect(app(TournamentChampion::class)->of($week->refresh()))->not->toBeNull();

    $old = championTournament($this->league);
    $this->travel(4)->days();

    // Four days on, the old one is still within the window: the control.
    expect(app(ChampionNotes::class)->due(now()->toImmutable()))->toHaveCount(1);

    $this->travel(1)->days();
    $this->travel(1)->minutes();
    // An edit after the finish does not move it: the finish counts, not the last update.
    $old->touch();

    expect(app(ChampionNotes::class)->due(now()->toImmutable()))->toBe([])
        ->and(app(ChampionNotes::class)->run(now()->toImmutable()))->toBe('no champion notes: no newly finished tournament without its note')
        ->and($this->published)->toBe([])
        ->and($old->status)->toBe(TournamentStatus::Finished);
});

test('fails closed without the bot key, the flag or a relay; a dry run posts nothing', function () {
    championTournament($this->league);

    Artisan::call('twentyone:stream-bot:champions', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('🏆 nostr:npub1', 'Dry run: 1 note(s), nothing was rendered, signed, sent or stored.');

    config(['esports.stream_bot.nsec' => null]);
    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->toBe('no champion notes: ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key');

    config(['esports.stream_bot.nsec' => $this->botKey->secret, 'esports.stream_bot.enabled' => false]);
    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->toContain('ESPORTS_STREAM_BOT_ENABLED is off');

    config(['esports.stream_bot.enabled' => true, 'twentyone.stream.relays' => []]);
    expect(app(ChampionNotes::class)->run(now()->toImmutable()))->toContain('no stream relay')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
});

test('a result post never names a sats amount from the tournament name', function () {
    expect(StreamBotCopy::resultName('21,000 Sats, Zero Ball Control'))->toBe('Zero Ball Control')
        ->and(StreamBotCopy::resultName('21k sats Cup'))->toBe('Cup')
        ->and(StreamBotCopy::resultName('Blitz Night Berlin'))->toBe('Blitz Night Berlin')
        ->and(StreamBotCopy::resultName('Season 2 Finale'))->toBe('Season 2 Finale');
});
