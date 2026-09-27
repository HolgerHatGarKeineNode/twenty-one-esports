<?php

use App\Enums\ClanRole;
use App\Enums\LineupRole;
use App\Enums\NotificationKind;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Jobs\SendNostrDm;
use App\Models\ClanMember;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentModeration;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Tournament moderation, security gate round 2
|--------------------------------------------------------------------------
|
| F1: a player blocked from a tournament never plays in it through a clan
| lineup: sign-up checks every active seat, and a tournament series takes
| its players from the entry's recorded members, not from today's seats.
| F2: a rules change asks the entries for a new consent. F3: the shared
| calendar is never signed ahead of the clock by a burst of edits.
|
*/

beforeEach(function () {
    Queue::fake();
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

/** Run the tournament with its active entries as participants, as the draw would, and pair its one match. */
function f1Run($tournament): SeriesMatch
{
    $tournament->forceFill(['status' => TournamentStatus::Running, 'capacity' => 2])->save();

    foreach (TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->whereNotNull('lineup_id')->orderBy('id')->get() as $index => $signup) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'lineup_id' => $signup->lineup_id, 'name' => $signup->name,
            'rating' => 1100 - $index, 'members' => $signup->members, 'tournament_signup_id' => $signup->id]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('9a', 32));
    app(TournamentRunner::class)->sync($tournament);

    return SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();
}

function f1Seat($lineup, User $player): void
{
    // One clan per player: he leaves the one he was in.
    ClanMember::query()->where('user_id', $player->id)->delete();
    ClanMember::query()->create(['clan_id' => $lineup->clan_id, 'user_id' => $player->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $player->id, 'role' => LineupRole::Substitute, 'accepted_at' => now()]);
    $lineup->unsetRelation('seats');
}

test('a blocked player does not play through the seats of a clan lineup entered without him', function () {
    openSeason(['slug' => 'season-1']);
    $tournament = openTournament(rocketLeague: true);
    [$blocked, $blockedKey] = keyedPlayer();
    app(TournamentModeration::class)->remove($tournament, $tournament->creator, soloSignup($tournament, $blocked, $blockedKey)->id, 'Cheating last season', block: true);

    // Sign-up checks every active seat: a lineup that seats him is refused even when he is not listed.
    [$seated, $seatedCaptain, $seatedSigner] = keyedLineup();
    f1Seat($seated, $blocked);
    $listed = array_values(array_diff(array_map(fn ($seat) => $seat->user_id, $seated->load('seats.user')->activeSeats()), [$blocked->id]));

    expect(fn () => lineupSignup($tournament, $seated, $seatedCaptain, $seatedSigner, $listed))
        ->toThrow(TournamentRuleViolation::class, __('A player here is blocked from this tournament by its organizer.'));

    // The reported path: lineup A is entered without him, he takes a seat afterwards, the draw runs.
    [$a, $captainA, $signerA] = keyedLineup();
    [$b, $captainB, $signerB] = keyedLineup();
    lineupSignup($tournament, $a, $captainA, $signerA);
    lineupSignup($tournament, $b, $captainB, $signerB);
    f1Seat($a, $blocked);

    $series = f1Run($tournament->refresh());
    $side = $series->captainSideOf($captainA);
    $service = app(SeriesService::class);
    $pinned = collect($series->gate_at_accept['sides'] ?? [])->flatten(1)->pluck('user_id')->all();
    $choices = array_map(fn ($seat) => $seat->user_id, $service->rosterChoices($series, $side));

    expect($series->rated)->toBeTrue()
        ->and($pinned)->not->toBeEmpty()
        ->and($pinned)->not->toContain($blocked->id)
        ->and($choices)->toContain($captainA->id)
        ->and($choices)->not->toContain($blocked->id)
        ->and(fn () => $service->setRoster($series, $captainA, [...array_slice($choices, 0, 2), $blocked->id]))
        ->toThrow(SeriesRuleViolation::class);
});

test('a casual tournament series also fields only the entry\'s players', function () {
    $tournament = openTournament(rocketLeague: true);
    [$a, $captainA, $signerA] = keyedLineup();
    [$b, $captainB, $signerB] = keyedLineup();
    lineupSignup($tournament, $a, $captainA, $signerA);
    lineupSignup($tournament, $b, $captainB, $signerB);
    $late = User::factory()->create();
    f1Seat($a, $late);

    $series = f1Run($tournament->refresh());
    $choices = array_map(fn ($seat) => $seat->user_id, app(SeriesService::class)->rosterChoices($series, $series->captainSideOf($captainA)));

    expect($series->rated)->toBeFalse()
        ->and($choices)->toHaveCount(3)
        ->and($choices)->not->toContain($late->id)
        ->and(fn () => app(SeriesService::class)->setRoster($series, $captainA, [$captainA->id, $late->id, $choices[1]]))
        ->toThrow(SeriesRuleViolation::class);
});

test('blocking a lineup entry blocks every active seat of the lineup, not only the players entered', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup(1);
    $entered = array_slice(array_map(fn ($seat) => $seat->user_id, $lineup->activeSeats()), 0, 3);
    $signup = lineupSignup($tournament, $lineup, $captain, $signer, $entered);

    app(TournamentModeration::class)->remove($tournament, $tournament->creator, $signup->id, 'Smurf accounts', block: true);

    expect($tournament->bans()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect($lineup->activeSeats())->pluck('user_id')->sort()->values()->all())
        ->and($tournament->bans()->count())->toBe(4);
});

/*
| F2: a change to what the entrants agreed to (results mode, directors, game
| or mode, format) asks every active entry for a new consent against the
| current version; the entrants hear what changed, and an entry not
| confirmed again by sign-up close is dropped, notified and logged.
*/

test('a rules change after sign-ups asks every entry to confirm again and tells its players what changed', function () {
    $tournament = openTournament();
    [$ana, $anaKey] = keyedPlayer();
    [$bob, $bobKey] = keyedPlayer();
    $anaEntry = soloSignup($tournament, $ana, $anaKey);
    soloSignup($tournament, $bob, $bobKey);
    $editor = app(TournamentEditor::class);

    // A name is no rule a consent agreed to: nothing to confirm.
    $editor->update($tournament, $tournament->creator, ['name' => 'Blitz Night Renamed']);

    expect(TournamentSignup::query()->whereNotNull('reconfirm_since')->count())->toBe(0);

    $this->travel(3)->seconds();
    $editor->update($tournament, $tournament->creator, ['results_mode' => TournamentResultsMode::Director]);
    $notice = $ana->notifications()->sole();

    expect(TournamentSignup::query()->active()->whereNull('reconfirm_since')->count())->toBe(0)
        ->and($notice->data['kind'])->toBe(NotificationKind::TournamentRulesChanged->value)
        ->and($notice->data['body'])->toContain('Results')
        ->and($bob->notifications()->count())->toBe(1)
        ->and(TournamentModerationEntry::query()->where('action', 'reconfirm')->sole()->reason)->toBe('Results');
    // A player has to act on it: a DM by default (NotificationKind::dmByDefault).
    Queue::assertPushed(SendNostrDm::class, 2);

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="needs-reconfirm"');
    Livewire::actingAs($ana)->test('pages::tournaments.signup', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="reconfirm-button"');

    // Ana confirms with one signed consent against the current version; her first consent stays on record.
    $signups = app(TournamentSignups::class);
    $signups->reconfirm($tournament->refresh(), $ana, $anaKey->signTemplates($signups->prepareReconfirm($tournament, $ana)));
    $anaEntry->refresh();
    $consent = NostrEvent::query()->findOrFail($anaEntry->reconfirm_event_id)->payload();

    expect($anaEntry->needsReconfirm())->toBeFalse()
        ->and($anaEntry->event_id)->not->toBeNull()->not->toBe($anaEntry->reconfirm_event_id)
        ->and($consent['tags'])->toContain(['e', $tournament->event->event_id, ''], ['action', 'signup'], ['p', $ana->pubkey, '', 'entrant'])
        ->and(fn () => $signups->prepareReconfirm($tournament, $ana))->toThrow(TournamentRuleViolation::class);
});

test('an entry not confirmed again by sign-up close is dropped at the close, notified and logged', function () {
    Http::fake(fn ($request) => str_ends_with($request->url(), '/blocks/tip/height') ? Http::response('900000') : Http::response('', 404));
    $tournament = openTournament();
    $players = collect(range(1, 3))->map(function () use ($tournament): array {
        [$player, $key] = keyedPlayer();
        soloSignup($tournament, $player, $key);

        return [$player, $key];
    });

    $this->travel(3)->seconds();
    app(TournamentEditor::class)->update($tournament, $tournament->creator, ['format' => TournamentFormat::RoundRobin]);
    $signups = app(TournamentSignups::class);

    foreach ($players->take(2) as [$player, $key]) {
        $signups->reconfirm($tournament->refresh(), $player, $key->signTemplates($signups->prepareReconfirm($tournament, $player)));
    }

    [$late] = $players->last();
    $this->travel(25)->hours();

    expect(app(TournamentDraws::class)->close($tournament->refresh()))->toBeTrue();

    $dropped = TournamentSignup::query()->where('user_id', $late->id)->sole();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and($dropped->removed_at)->not->toBeNull()
        ->and($dropped->removal_reason)->toBe(TournamentDraws::NOT_RECONFIRMED)
        ->and(TournamentSignup::query()->active()->count())->toBe(2)
        ->and($late->notifications()->get()->pluck('data.kind')->sort()->values()->all())
        ->toBe([NotificationKind::TournamentEntryRemoved->value, NotificationKind::TournamentRulesChanged->value])
        ->and(TournamentModerationEntry::query()->where('action', 'removed')->sole()->only(['user_id', 'user_name', 'subject']))
        ->toBe(['user_id' => null, 'user_name' => 'League', 'subject' => $dropped->name]);
});

/*
| F3: the shared calendar (31924) is signed on one floor by publish and
| republish, never more than two seconds ahead of the clock; the edit page
| saves one change per tournament every two seconds.
*/

test('publish and republish sign the shared calendar on one floor, never more than two seconds ahead', function () {
    $this->freezeTime();
    $first = openTournament();
    $editor = app(TournamentEditor::class);

    // Three versions in one clock second: now, now + 1, now + 2.
    $editor->update($first, $first->creator, ['name' => 'Second version']);
    $editor->update($first, $first->creator, ['name' => 'Third version']);

    // A fourth would be signed 3 s ahead: refused, nothing changed and nothing signed.
    expect(fn () => $editor->update($first, $first->creator, ['name' => 'Fourth version']))
        ->toThrow(TournamentRuleViolation::class, __('Too many changes in a row. Wait a few seconds and save again.'))
        ->and($first->refresh()->name)->toBe('Third version')
        ->and(NostrEvent::query()->where('kind', Tournament::CALENDAR)->count())->toBe(3);

    // Publishing another tournament in that same second takes the same floor: refused rather than future-dated.
    $draft = Tournament::factory()->create(['created_by_id' => $first->created_by_id]);

    expect(fn () => app(TournamentPublisher::class)->publish($draft, $draft->creator, CarbonImmutable::now()->addDay()))
        ->toThrow(TournamentRuleViolation::class)
        ->and($draft->refresh()->status)->toBe(TournamentStatus::Draft);

    // A second later it publishes, and its calendar follows the newest one (still ahead of the clock): listed, not dropped.
    $this->travel(1)->seconds();
    $published = app(TournamentPublisher::class)->publish($draft, $draft->creator, CarbonImmutable::now()->addDay());
    $calendars = NostrEvent::query()->where('kind', Tournament::CALENDAR)->orderBy('signed_at')->get();

    expect($calendars->pluck('signed_at')->map(fn ($at) => $at - $calendars->first()->signed_at)->all())->toBe([0, 1, 2, 3])
        ->and($calendars->last()->signed_at)->toBeLessThanOrEqual(now()->getTimestamp() + TournamentPublisher::MAX_AHEAD)
        ->and($calendars->last()->payload()['tags'])->toContain(['a', $published->address(), '']);
});

test('the edit page saves one change per tournament every two seconds', function () {
    $tournament = openTournament();
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament]);

    $page->set('name', 'First rename')->call('save')->assertSet('error', '');
    $page->set('name', 'Second rename')->call('save')->assertSet('error', __('Saved a moment ago. Wait :seconds s and save again.', ['seconds' => 2]));

    expect($tournament->refresh()->name)->toBe('First rename');

    $this->travel(3)->seconds();
    $page->call('save')->assertSet('error', '');

    expect($tournament->refresh()->name)->toBe('Second rename');
});
