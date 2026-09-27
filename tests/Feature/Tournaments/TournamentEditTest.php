<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Jobs\PublishTournamentCalendar;
use App\Jobs\SendNostrDm;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentModeration;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Editing and moderating a tournament
|--------------------------------------------------------------------------
|
| Admins edit every tournament, an organizer their own. Before the draw the
| format, game and capacity change (republished, the ladder frozen at the
| first publish kept, or re-derived for a game correction); entries are
| removed with a reason, notified and logged, and players can be blocked.
| After the draw only name and start change and no entry moves.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret, 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

function editAdmin(): User
{
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** The tournament's 31923 versions, oldest first. */
function calendarVersions(Tournament $tournament): array
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $tournament->slug)->orderBy('id')->get()->all();
}

/**
 * @return list<array<int, string>>
 */
function latestTags(Tournament $tournament): array
{
    return $tournament->refresh()->event->payload()['tags'];
}

test('admins edit every tournament, an organizer only their own, players never', function () {
    $tournament = openTournament();
    $other = openTournament();
    $player = User::factory()->create();

    $this->get(route('admin.tournaments.edit', $tournament))->assertRedirect(route('login'));
    $this->actingAs(editAdmin())->get(route('admin.tournaments.edit', $tournament))->assertOk()->assertSee(__('Sign-ups'));
    $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $tournament))->assertOk();
    $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $other))->assertForbidden();
    $this->actingAs($player)->get(route('admin.tournaments.edit', $tournament))->assertForbidden();
    Livewire::actingAs($player)->test('pages::admin.tournament-edit', ['tournament' => $tournament])->assertForbidden();

    expect(fn () => app(TournamentModeration::class)->remove($tournament, $player, 1, 'no reason at all'))
        ->toThrow(TournamentRuleViolation::class)
        ->and(fn () => app(TournamentEditor::class)->update($tournament, $player, ['name' => 'Taken over']))
        ->toThrow(TournamentRuleViolation::class);
});

test('the Edit link shows on the list and the tournament page only with the gate', function () {
    $tournament = openTournament();
    $edit = route('admin.tournaments.edit', $tournament);

    $this->actingAs($tournament->creator)->get(route('admin.tournaments'))->assertSee($edit, false);
    $this->actingAs($tournament->creator)->get(route('tournaments.show', $tournament))->assertSee($edit, false);
    $this->actingAs(organizer())->get(route('tournaments.show', $tournament))->assertDontSee($edit, false);
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertDontSee($edit, false);
});

test('a format change before the draw is saved and republished, the ladder address unchanged', function () {
    openSeason(['slug' => 'season-1']);
    $tournament = openTournament(['capacity' => 12]);
    $ladder = $tournament->ladder_address;
    $address = $tournament->address();
    [$first] = calendarVersions($tournament);

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call('stepPlayers', 4)
        ->call('select', TournamentFormat::DoubleElimination->value)
        ->call('save')
        ->assertSet('error', '')
        ->call('$refresh')->assertOk();

    $tournament->refresh();
    $versions = calendarVersions($tournament);

    expect($tournament->format)->toBe(TournamentFormat::DoubleElimination)
        ->and($tournament->capacity)->toBe(16)
        ->and($ladder)->not->toBeNull()
        ->and($tournament->ladder_address)->toBe($ladder)
        ->and($tournament->address())->toBe($address)
        ->and($versions)->toHaveCount(2)
        ->and($versions[1]->signed_at)->toBeGreaterThan($first->signed_at)
        ->and(latestTags($tournament))->toContain(['d', $tournament->slug], ['a', $ladder, ''])
        // Publish and edit both queue the calendar; the second dispatch coalesces into the queued one.
        ->and(Queue::pushed(PublishTournamentCalendar::class))->toHaveCount(1)
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole()->details)->toHaveKeys(['format', 'capacity']);
});

test('after the draw the format and game are locked, only name and start change', function (TournamentStatus $status) {
    $tournament = openTournament();
    $tournament->forceFill(['status' => $status])->save();

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee(__('Format locked'))
        ->assertDontSee(__('Pick a format'))
        ->set('name', 'Renamed Cup')
        ->call('save')
        ->assertSet('error', '');

    expect($tournament->refresh()->name)->toBe('Renamed Cup')
        ->and(latestTags($tournament))->toContain(['title', 'Renamed Cup'])
        ->and(fn () => app(TournamentEditor::class)->update($tournament, $tournament->creator, ['format' => TournamentFormat::SingleElimination]))
        ->toThrow(TournamentRuleViolation::class, __('The draw has run: format, game, capacity and sign-up are locked. Only the name, the description and the start time can change.'))
        ->and(fn () => app(TournamentEditor::class)->update($tournament, $tournament->creator, ['game' => 'rocket-league', 'mode' => '3v3']))
        ->toThrow(TournamentRuleViolation::class)
        ->and($tournament->refresh()->format)->toBe(TournamentFormat::Swiss)
        ->and($tournament->game)->toBe('chess');
})->with([TournamentStatus::Drawing, TournamentStatus::Running]);

test('removing a sign-up frees its place, keeps the consent, notifies the player and is logged', function () {
    $tournament = openTournament(['capacity' => 2]);
    [$ana, $anaKey] = keyedPlayer();
    // A DM only for a player who switched DMs on: a removal asks nothing of the player (NotificationKind::dmByDefault).
    $ana->forceFill(['chess_settings' => ['dm' => true]])->save();
    [$bob, $bobKey] = keyedPlayer();
    [$cleo] = keyedPlayer();
    $removed = soloSignup($tournament, $ana, $anaKey);
    soloSignup($tournament, $bob, $bobKey);
    $signups = app(TournamentSignups::class);

    expect(fn () => $signups->prepareSolo($tournament, $cleo))->toThrow(TournamentRuleViolation::class, __('This tournament is full.'));

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee($ana->displayName())
        ->call('startRemove', $removed->id)
        ->set('removeReason', 'No-show at the last two events')
        ->call('remove')
        ->assertSet('moderationError', '')
        ->assertSee('No-show at the last two events');

    $removed->refresh();
    $notice = $ana->notifications()->sole();

    expect($removed->removed_at)->not->toBeNull()
        ->and($removed->removed_by_id)->toBe($tournament->creator->id)
        ->and($removed->event_id)->not->toBeNull()
        ->and($removed->withdrawn_at)->toBeNull()
        ->and($signups->places($tournament)['taken'])->toBe(1)
        ->and($signups->prepareSolo($tournament, $cleo))->toHaveCount(1)
        ->and($notice->data['kind'])->toBe(NotificationKind::TournamentEntryRemoved->value)
        ->and($notice->data['body'])->toContain('No-show at the last two events')
        ->and($bob->notifications()->count())->toBe(0)
        ->and(TournamentModerationEntry::query()->sole()->only(['action', 'subject', 'reason']))
        ->toBe(['action' => 'removed', 'subject' => $removed->name, 'reason' => 'No-show at the last two events'])
        // Only the tournament's 31923 of its publish: the removal signed and published nothing.
        ->and(NostrEvent::query()->where('kind', '!=', TournamentSignups::CONSENT)->count())->toBe(1);
    Queue::assertPushed(SendNostrDm::class, 1);
    Queue::assertPushed(SendNostrDm::class, fn (SendNostrDm $job) => $job->user->is($ana));
});

test('a blocked player cannot sign up again until unblocked', function () {
    $tournament = openTournament();
    [$ana, $anaKey] = keyedPlayer();
    $signup = soloSignup($tournament, $ana, $anaKey);
    $moderation = app(TournamentModeration::class);

    $moderation->remove($tournament, $tournament->creator, $signup->id, 'Abusive in chat', block: true);

    expect(fn () => app(TournamentSignups::class)->prepareSolo($tournament, $ana))
        ->toThrow(TournamentRuleViolation::class, __('A player here is blocked from this tournament by its organizer.'))
        ->and(fn () => soloSignup($tournament, $ana, $anaKey))->toThrow(TournamentRuleViolation::class);

    $moderation->unblock($tournament, editAdmin(), $tournament->bans()->sole()->id);
    // A new consent: the same second would sign the same event id, which the league takes only once.
    $this->travel(2)->seconds();

    expect(soloSignup($tournament, $ana, $anaKey)->removed_at)->toBeNull()
        ->and(TournamentModerationEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['removed', 'blocked', 'unblocked']);
});

test('a captain cannot enter a lineup that fields a blocked player', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    $signup = lineupSignup($tournament, $lineup, $captain, $signer);

    app(TournamentModeration::class)->remove($tournament, $tournament->creator, $signup->id, 'Wrong roster', block: true);

    expect(fn () => lineupSignup($tournament, $lineup, $captain, $signer))
        ->toThrow(TournamentRuleViolation::class, __('A player here is blocked from this tournament by its organizer.'))
        ->and($tournament->bans()->count())->toBe(count($signup->members));
});

test('capacity cannot drop below the places the entries take, and no entry is dropped', function () {
    $tournament = openTournament(['capacity' => 4]);

    foreach (range(1, 3) as $index) {
        [$player, $key] = keyedPlayer();
        soloSignup($tournament, $player, $key);
    }

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('players', '2')
        ->call('save')
        ->assertSet('error', __(':taken places are taken. Keep room for at least :min entries, or remove entries first.', ['taken' => 3, 'min' => 3]));

    expect($tournament->refresh()->capacity)->toBe(4)
        ->and(TournamentSignup::query()->active()->count())->toBe(3)
        ->and(calendarVersions($tournament))->toHaveCount(1);
});

test('no moderation works after the draw', function () {
    $tournament = openTournament();
    [$ana, $anaKey] = keyedPlayer();
    $signup = soloSignup($tournament, $ana, $anaKey);
    $moderation = app(TournamentModeration::class);
    $moderation->remove($tournament, $tournament->creator, soloSignup($tournament, ...keyedPlayer())->id, 'Duplicate account', block: true);
    $tournament->forceFill(['status' => TournamentStatus::Drawing])->save();

    expect(fn () => $moderation->remove($tournament, $tournament->creator, $signup->id, 'Too late now'))
        ->toThrow(TournamentRuleViolation::class, __('The draw has run: entries can no longer be changed.'))
        ->and(fn () => $moderation->unblock($tournament, $tournament->creator, $tournament->bans()->sole()->id))
        ->toThrow(TournamentRuleViolation::class);

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee(__('The draw has run: entries can no longer be changed.'))
        ->set('removing', $signup->id)->set('removeReason', 'Too late now')
        ->call('remove')
        ->assertSet('moderationError', __('The draw has run: entries can no longer be changed.'));

    expect($signup->refresh()->removed_at)->toBeNull()
        ->and($tournament->bans()->count())->toBe(1);
});

test('a game correction without entries resets the options the new game does not offer', function () {
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id]);

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call('pickGame', 'rl3')
        ->call('save')
        ->assertSet('error', '');

    $tournament->refresh();

    expect([$tournament->game, $tournament->mode])->toBe(['rocket-league', '3v3'])
        ->and($tournament->options['bestOf'])->toBe(3)
        ->and($tournament->ladder_address)->toBeNull()
        ->and(NostrEvent::query()->count())->toBe(0);

    // Stored options of the old game are normalized even when the chooser did not send new ones.
    app(TournamentEditor::class)->update($tournament, $tournament->creator, ['game' => 'chess', 'mode' => 'blitz']);

    expect($tournament->refresh()->options['bestOf'])->toBe(1);
});

test('a game correction lists the lineups it no longer fits and removes them only when confirmed', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    $lineupSignup = lineupSignup($tournament, $lineup, $captain, $signer);
    [$solo, $soloKey] = keyedPlayer();
    $soloSignup = soloSignup($tournament, $solo, $soloKey);

    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call('pickGame', 'rl2');

    // The warning renders in the summary island, which a Livewire test skips (the browser test shows it).
    expect($page->instance()->incompatible->pluck('id')->all())->toBe([$lineupSignup->id]);

    $page->call('save')
        ->assertSet('error', __('These lineups were entered for the old game or mode: :entries. Confirm their removal to change the game.', ['entries' => $lineupSignup->name]));

    expect($tournament->refresh()->mode)->toBe('3v3')
        ->and($lineupSignup->refresh()->removed_at)->toBeNull();

    $page->set('confirmRemoval', true)->call('save')->assertSet('error', '');

    expect($tournament->refresh()->mode)->toBe('2v2')
        ->and($lineupSignup->refresh()->removed_at)->not->toBeNull()
        ->and($lineupSignup->removal_reason)->toBe(TournamentEditor::GAME_CORRECTED)
        ->and($soloSignup->refresh()->removed_at)->toBeNull()
        ->and($captain->notifications()->sole()->data['kind'])->toBe(NotificationKind::TournamentEntryRemoved->value)
        ->and(User::query()->find($lineupSignup->members[1])->notifications()->count())->toBe(1)
        // The solo entry stays as it is: nothing to confirm, nothing to read.
        ->and($solo->notifications()->count())->toBe(0)
        ->and(TournamentModerationEntry::query()->pluck('action')->sort()->values()->all())->toBe(['edited', 'removed']);
});

test('a game correction re-derives the ladder from the first publish time: the new game\'s ladder while one was open then', function () {
    openSeason(['slug' => 'season-1']);
    $tournament = openTournament();
    $season = Season::query()->sole();

    expect($tournament->ladder_address)->toBe("32152:{$season->league_pubkey}:chess/blitz/season-1");

    // The season has ended since: the re-derivation reads the publish time, not now.
    $this->travel(2)->hours();
    $season->forceFill(['ends_at' => now()->subMinute()])->save();
    app(TournamentEditor::class)->update($tournament, $tournament->creator, ['mode' => 'correspondence']);

    expect($tournament->refresh()->ladder_address)->toBe("32152:{$season->league_pubkey}:chess/correspondence/season-1")
        ->and(latestTags($tournament))->toContain(['a', $tournament->ladder_address, ''])
        ->and(latestTags($tournament))->not->toContain(['a', "32152:{$season->league_pubkey}:chess/blitz/season-1", '']);
});

test('a game correction re-derives no ladder when none was open at the first publish: unrated for the whole run', function () {
    $tournament = openTournament();
    $leagueKey = config('esports.league.nsec');

    expect($tournament->ladder_address)->toBeNull();

    $this->travel(1)->hours();
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subMinute()]);
    config(['esports.league.nsec' => $leagueKey]);
    $address = $tournament->address();

    app(TournamentEditor::class)->update($tournament, $tournament->creator, ['mode' => 'correspondence']);

    expect($tournament->refresh()->ladder_address)->toBeNull()
        ->and($tournament->address())->toBe($address)
        ->and(collect(latestTags($tournament))->filter(fn (array $tag) => $tag[0] === 'a' && str_starts_with($tag[1], '32152:'))->all())->toBe([]);
});

test('start times are typed and shown in the display zone and stored as UTC, across the DST change, and survive an unchanged edit', function (string $date, string $utc) {
    // Before the dates below, so the create page accepts them as future starts.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'UTC'));
    config(['esports.preseason.display_timezone' => 'Europe/Berlin']);
    $organizer = organizer();

    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Zone Cup')->set('date', $date)->set('time', '19:00')
        ->call('create')->assertHasNoErrors();

    $tournament = Tournament::query()->where('name', 'Zone Cup')->sole();

    expect($tournament->starts_at->utc()->format('Y-m-d H:i'))->toBe($utc);

    $this->actingAs($organizer)->get(route('tournaments.show', $tournament))->assertSee("{$date} 19:00");

    Livewire::actingAs($organizer)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('date', $date)->assertSet('time', '19:00')
        ->call('save')
        ->assertSet('error', '')
        ->assertSet('notice', __('Nothing changed.'));

    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe($utc)
        ->and(TournamentModerationEntry::query()->count())->toBe(0);
})->with([
    'summer time, the day before the change' => ['2026-10-24', '2026-10-24 17:00'],
    'winter time, the day of the change' => ['2026-10-25', '2026-10-25 18:00'],
]);

test('the description is edited before and after the draw, republished in the 31923 content, and kept to 1000 characters', function () {
    $tournament = openTournament();
    $tournament->forceFill(['status' => TournamentStatus::Running])->save();

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('description', '')
        ->set('description', 'Stream on the big screen, pizza at the break.')
        ->call('save')
        ->assertSet('error', '');

    expect($tournament->refresh()->description)->toBe('Stream on the big screen, pizza at the break.')
        ->and($tournament->event->payload()['content'])->toStartWith('Stream on the big screen, pizza at the break.')
        ->and(calendarVersions($tournament))->toHaveCount(2)
        ->and(TournamentModerationEntry::query()->sole()->details)->toHaveKey('description');

    $this->travel(3)->seconds();

    expect(fn () => app(TournamentEditor::class)->update($tournament, $tournament->creator, ['description' => str_repeat('a', 1001)]))
        ->toThrow(TournamentRuleViolation::class, __('Keep the description to 1000 characters.'));
});
