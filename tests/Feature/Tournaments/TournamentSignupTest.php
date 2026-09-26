<?php

use App\Enums\TournamentStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Series\Ladders;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Publishing and sign-up (P8b)
|--------------------------------------------------------------------------
|
| Publishing signs the tournament (31923) and the league calendar (31924)
| with the league key; sign-up is a signed consent the league stores and
| never publishes. One entry per person, capacity, deadline, withdrawal.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);
});

test('publishing signs the tournament and the league calendar with the league key, nothing of the entries', function () {
    $tournament = openTournament();
    [$event, $calendar] = NostrEvent::query()->orderBy('id')->get()->all();
    $tags = $event->payload()['tags'];

    expect($tournament->status)->toBe(TournamentStatus::Signup)
        ->and([$event->kind, $calendar->kind])->toBe([31923, 31924])
        ->and([$event->pubkey, $calendar->pubkey])->toBe([$this->league->pubkey, $this->league->pubkey])
        ->and(SignedEvent::fromInput($event->payload())->hasValidSignature())->toBeTrue()
        ->and($tournament->address())->toBe('31923:'.$this->league->pubkey.':'.$tournament->slug)
        ->and($tags)->toContain(['d', $tournament->slug], ['title', $tournament->name], ['location', route('tournaments.show', $tournament)], ['a', '31924:'.$this->league->pubkey.':tournaments', ''])
        ->and(collect($tags)->pluck(0)->all())->toContain('start', 'end', 'D', 'start_tzid', 'alt')
        ->and($calendar->payload()['tags'])->toContain(['a', $tournament->address(), ''])
        ->and($event->raw)->not->toContain('bracket');
    Queue::assertPushed(PublishNostrEvent::class, 2);
});

test('only the organizer or an admin publishes, never without the league key, and a draft stays hidden', function () {
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id]);
    $member = User::factory()->create();

    $this->get(route('tournaments.show', $tournament))->assertNotFound();
    $this->actingAs($member)->get(route('tournaments.show', $tournament))->assertNotFound();

    expect(fn () => app(TournamentPublisher::class)->publish($tournament, $member, CarbonImmutable::now()->addDay()))
        ->toThrow(TournamentRuleViolation::class);

    config(['esports.league.nsec' => null]);
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('publish')->assertSet('error', __('The league key is not set up, so nothing can be published yet.'));

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Draft)
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('a solo sign-up is a signed 22150 consent that is stored, never published, and passes the NIP rules', function () {
    $tournament = openTournament();
    Queue::fake();
    [$player, $signer] = keyedPlayer();

    $signup = soloSignup($tournament, $player, $signer);
    $consent = SignedEvent::fromInput($signup->event->payload());

    expect($signup->members)->toBe([$player->id])
        ->and($consent->kind)->toBe(22150)
        ->and($consent->pubkey)->toBe($player->pubkey)
        ->and($consent->tags)->toBe([
            ['a', $tournament->address(), ''],
            ['action', 'signup'],
            ['e', $tournament->event->event_id, ''],
            ['p', $player->pubkey, '', 'entrant'],
            ['alt', 'Tournament sign-up: '.$tournament->name],
        ])
        ->and($consent->content)->toBe("I enter {$tournament->name} and accept its rules.")
        ->and(app(EsportsEventRules::class)->check($consent))->toBeNull();
    Queue::assertNothingPushed();
});

test('a lineup consent names the lineup and its players as entrants; its withdrawal answers it and copies them', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    $service = app(TournamentSignups::class);
    $signup = lineupSignup($tournament, $lineup, $captain, $signer);
    $consent = SignedEvent::fromInput($signup->event->payload());
    $players = array_map(fn ($seat) => $seat->user->pubkey, $lineup->activeSeats());

    $service->withdraw($tournament, $captain, $signer->signTemplates($service->prepareWithdraw($tournament, $captain)));
    $withdrawal = SignedEvent::fromInput(NostrEvent::query()->findOrFail($signup->refresh()->withdraw_event_id)->payload());
    $rules = app(EsportsEventRules::class);

    expect($consent->tagsNamed('a'))->toBe([[$tournament->address(), ''], [$lineup->address(), '', 'entrant']])
        ->and(array_column($consent->tagsNamed('p'), 0))->toBe($players)
        ->and($withdrawal->tag('action'))->toBe('withdraw')
        ->and($withdrawal->tagsNamed('e'))->toBe([[$consent->id, '']])
        ->and($withdrawal->tagsNamed('p'))->toBe($consent->tagsNamed('p'))
        ->and($withdrawal->tagsNamed('a'))->toBe($consent->tagsNamed('a'))
        ->and($rules->check($consent))->toBeNull()
        ->and($rules->check($withdrawal))->toBeNull();
});

test('the consent rule counts only the role-less tournament a and refuses the old NIP-98 form', function () {
    $signer = new TestSigner;
    $rules = app(EsportsEventRules::class);
    $base = [['a', '31923:'.str_repeat('a', 64).':cup', ''], ['action', 'signup'], ['e', str_repeat('b', 64), ''], ['p', $signer->pubkey, '', 'entrant'], ['alt', 'Tournament sign-up: Cup']];
    $check = fn (array $tags) => $rules->check(SignedEvent::fromInput($signer->sign(22150, $tags, 'I enter Cup and accept its rules.')));

    expect($check($base))->toBeNull()
        ->and($check([['a', '32151:'.str_repeat('c', 64).':x/rocket-league/3v3', '', 'entrant'], ...$base]))->toBeNull()
        ->and($check([['a', '31923:'.str_repeat('a', 64).':other', ''], ...$base]))->toBe('consent_tournament')
        ->and($check([['u', 'https://x'], ['method', 'POST'], ...$base]))->toBe('consent_tags')
        ->and($check(array_values(array_filter($base, fn ($tag) => $tag[0] !== 'e'))))->toBe('consent_tags')
        ->and($check([...array_slice($base, 0, 3), ['p', $signer->pubkey], $base[4]]))->toBe('consent_entrants')
        // 27235 stays the login's kind only: a consent in it is no event the rules accept.
        ->and($rules->check(SignedEvent::fromInput($signer->sign(27235, $base, ''))))->toBe('kind_not_allowed');
});

test('publishing freezes the open ladder, says whether matches are rated, and lists every UTC day', function () {
    openSeason(['slug' => 'season-1']);
    // 23:00 UTC plus a Swiss evening (about 1 h 39 min) crosses midnight UTC: two days.
    $rated = openTournament(['starts_at' => now()->utc()->addDays(2)->setTime(23, 0)]);
    $ratedEvent = $rated->event->payload();
    $rl = openTournament([], rocketLeague: true);

    expect($rated->ladder_address)->toBe(Ladders::address('chess', 'blitz'))
        ->and($ratedEvent['tags'])->toContain(['a', $rated->ladder_address, ''])
        ->and($ratedEvent['content'])->toContain('rated on the ladder')
        ->and(collect($ratedEvent['tags'])->where(0, 'D')->pluck(1)->values()->all())->toBe([(string) intdiv($rated->starts_at->getTimestamp(), 86400), (string) (intdiv($rated->starts_at->getTimestamp(), 86400) + 1)])
        ->and($rl->event->payload()['content'])->toContain('casual for now');
});

test('a tournament published before Block 0 stays unrated, even once a ladder opens', function () {
    $tournament = openTournament();

    expect($tournament->ladder_address)->toBeNull()
        ->and($tournament->event->payload()['content'])->toContain('unrated')
        ->and(collect($tournament->event->payload()['tags'])->where(0, 'a')->count())->toBe(1);

    openSeason(['slug' => 'season-1']);

    expect($tournament->refresh()->openLadder())->toBeNull();
});

test('a sign-up signed by another key is refused', function () {
    $tournament = openTournament();
    [$player] = keyedPlayer();
    $service = app(TournamentSignups::class);

    expect(fn () => $service->enterSolo($tournament, $player, (new TestSigner)->signTemplates($service->prepareSolo($tournament, $player))))
        ->toThrow(RejectedEvent::class);
    expect(TournamentSignup::query()->count())->toBe(0);
});

test('a captain enters a lineup with the team and up to two substitutes', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup(subs: 3);
    $seats = array_map(fn ($seat) => $seat->user_id, $lineup->activeSeats());
    $service = app(TournamentSignups::class);

    expect(fn () => $service->prepareLineup($tournament, $captain, $lineup->id, $seats))->toThrow(TournamentRuleViolation::class, 'up to 2 substitutes')
        ->and(fn () => $service->prepareLineup($tournament, $captain, $lineup->id, array_slice($seats, 0, 2)))->toThrow(TournamentRuleViolation::class, 'Enter 3 to 5');

    $signup = lineupSignup($tournament, $lineup, $captain, $signer, array_slice($seats, 0, 5));
    $member = $lineup->seats->firstWhere('user_id', $seats[1])->user;

    expect($signup->lineup_id)->toBe($lineup->id)
        ->and($signup->members)->toBe(array_slice($seats, 0, 5))
        ->and(fn () => $service->prepareLineup($tournament, $member, $lineup->id, array_slice($seats, 0, 3)))->toThrow(TournamentRuleViolation::class);
});

test('one entry per person: no solo entry next to your clan\'s lineup, and no lineup with a solo player of the clan', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    $service = app(TournamentSignups::class);

    // A clan member signs up solo first: the captain cannot enter the lineup until they pull out.
    $memberSigner = new TestSigner;
    $member = $lineup->seats->firstWhere('user_id', '!=', $captain->id)->user;
    $member->forceFill(['pubkey' => $memberSigner->pubkey])->save();
    soloSignup($tournament, $member->refresh(), $memberSigner);

    expect(fn () => lineupSignup($tournament, $lineup, $captain, $signer))->toThrow(TournamentRuleViolation::class, 'signed up solo');

    $service->withdraw($tournament, $member, $memberSigner->signTemplates($service->prepareWithdraw($tournament, $member)));
    lineupSignup($tournament, $lineup, $captain, $signer);

    expect(fn () => $service->prepareSolo($tournament, $member))->toThrow(TournamentRuleViolation::class, 'One entry per person')
        ->and(fn () => $service->prepareSolo($tournament, $captain))->toThrow(TournamentRuleViolation::class);
});

test('sign-up stops at capacity and at the deadline; pulling out works until then', function () {
    $tournament = openTournament(['capacity' => 2]);
    $service = app(TournamentSignups::class);
    [$a, $signerA] = keyedPlayer();
    [$b, $signerB] = keyedPlayer();
    [$c, $signerC] = keyedPlayer();

    soloSignup($tournament, $a, $signerA);
    soloSignup($tournament, $b, $signerB);

    expect(fn () => $service->prepareSolo($tournament, $c))->toThrow(TournamentRuleViolation::class, 'full');

    $service->withdraw($tournament, $b, $signerB->signTemplates($service->prepareWithdraw($tournament, $b)));
    soloSignup($tournament, $c, $signerC);

    $this->travel(2)->days();

    expect(fn () => $service->prepareWithdraw($tournament, $c))->toThrow(TournamentRuleViolation::class, 'closed')
        ->and(fn () => $service->prepareSolo($tournament, $b))->toThrow(TournamentRuleViolation::class, 'closed')
        ->and(TournamentSignup::query()->active()->pluck('user_id')->all())->toBe([$a->id, $c->id]);
});
