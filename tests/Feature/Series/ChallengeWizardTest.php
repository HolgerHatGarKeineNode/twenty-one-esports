<?php

use App\Models\InviteLink;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The challenge wizard (plan "Canvas and live alignment", P4, ChallengeCreate.dc.html)
|--------------------------------------------------------------------------
|
| Five steps, one on screen. "Next" validates the step on the server with only
| that step's rules: invalid input keeps the step and names the field, valid
| input opens the next one. Back keeps every value; the step cannot be set from
| the client; sending validates every step again.
|
*/

/** A captain's ready RL 1v1 lineup and a ready one of another clan. */
function wizardPair(): array
{
    return [Lineup::factory()->mode('1v1')->ready()->create(), Lineup::factory()->mode('1v1')->ready()->create()];
}

test('step 1: an incomplete lineup keeps the wizard on step 1 with the reason; a ready one opens step 2', function () {
    [$mine] = wizardPair();
    $incomplete = Lineup::factory()->mode('3v3')->create(['clan_id' => $mine->clan_id]);

    Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->assertSet('step', 1)->assertSeeHtml('data-test="wizard-step-1"')
        ->call('pickLineup', $incomplete->id)
        ->call('next')
        ->assertHasErrors(['lineupId'])->assertSet('step', 1)
        ->assertSee('This lineup is not complete yet')
        ->call('pickLineup', $mine->id)
        ->call('next')
        ->assertHasNoErrors()->assertSet('step', 2)->assertSeeHtml('data-test="wizard-step-2"');
});

test('step 2: without an opponent, or with one whose lineup is not complete, the wizard stays; a ready opponent or an invite link opens step 3', function () {
    [$mine, $theirs] = wizardPair();
    $busy = Lineup::factory()->mode('1v1')->create();

    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')->call('next')
        ->call('next')
        ->assertHasErrors(['opponentId'])->assertSet('step', 2)
        ->assertSee('Pick an opponent, or invite one by link.')
        ->call('pickOpponent', $busy->id)
        ->call('next')
        ->assertHasErrors(['opponentId'])->assertSet('step', 2)
        ->call('pickOpponent', $theirs->id)
        ->call('next')
        ->assertHasNoErrors()->assertSet('step', 3);

    // Back on step 2, "Invite by link" instead of an opponent: on to step 3 without one, casual.
    $page->call('back')->assertSet('step', 2)->assertSet('opponentId', $theirs->id)
        ->call('inviteByLink')
        ->assertSet('step', 3)->assertSet('viaLink', true)->assertSet('opponentId', null)->assertSet('rated', false);
});

test('step 3: rated before Block 0 and a length the mode does not allow keep the wizard on step 3; casual Bo3 opens step 4', function () {
    [$mine, $theirs] = wizardPair();

    Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->assertSet('step', 3)
        ->assertSeeHtml('data-test="type-rated-locked"')
        ->set('rated', true)
        ->call('next')
        ->assertHasErrors(['rated'])->assertSet('step', 3)
        ->set('rated', false)->set('bestOf', 7)
        ->call('next')
        ->assertHasErrors(['bestOf'])->assertSet('step', 3)
        ->set('bestOf', 3)
        ->call('next')
        ->assertHasNoErrors()->assertSet('step', 4);
});

test('step 4: an impossible date, an unreadable time and a deadline after the first suggestion keep the wizard on step 4; German dates open step 5', function () {
    [$mine, $theirs] = wizardPair();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'Europe/Berlin'));

    Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->call('next')->assertSet('step', 4)
        // The defaults read the way the page writes dates: "10.11.2026" in German, "10 Nov 2026" in English.
        ->assertSet('times.0.date', '10 Nov 2026')->assertSet('times.0.time', '20:00')
        ->set('times.0.date', '31.02.2026')
        ->call('next')
        ->assertHasErrors(['times.0.date'])->assertSet('step', 4)
        ->set('times.0.date', '14.11.2026')->set('times.0.time', '25:00')
        ->call('next')
        ->assertHasErrors(['times.0.time'])->assertSet('step', 4)
        ->set('times.0.time', '20:00')->set('replyDate', '15.11.')->set('replyTime', '18:00')
        ->call('next')
        ->assertHasErrors(['replyTime'])->assertSet('step', 4)
        ->assertSee('The reply deadline has to be before the first suggested time.')
        ->set('replyDate', 'Sa. 14.11.2026')->set('replyTime', '18.00')
        ->call('next')
        ->assertHasNoErrors()->assertSet('step', 5)
        ->assertSeeHtml('data-test="summary-times"');
});

test('back keeps every value, the step is locked against the client, and sending checks every step again', function () {
    [$mine, $theirs] = wizardPair();

    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->set('bestOf', 5)->call('next')->call('next')->assertSet('step', 5);

    $page->call('backTo', 1)->assertSet('step', 1)
        ->assertSet('lineupId', $mine->id)->assertSet('opponentId', $theirs->id)->assertSet('bestOf', 5)
        // backTo never jumps ahead of the step on screen.
        ->call('backTo', 4)->assertSet('step', 1);

    expect(fn () => $page->set('step', 5))->toThrow(CannotUpdateLockedPropertyException::class);

    // A time broken after step 4 was passed: sending reopens step 4 with the error and nothing goes out.
    $page->call('next')->call('next')->call('next')->call('next')->assertSet('step', 5)
        ->set('times.0.date', 'tomorrow')
        ->call('prepareSend')->assertReturned(null)
        ->assertSet('step', 4)->assertHasErrors(['times.0.date']);

    expect(SeriesMatch::query()->count())->toBe(0);
});

test('the last step sends through the existing path: casual, nothing to sign, the room opens', function () {
    [$mine, $theirs] = wizardPair();

    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->call('next')->call('next')->assertSet('step', 5)
        ->call('prepareSend')->assertReturned([]);

    $page->call('send', '[]')->assertHasNoErrors();

    $match = SeriesMatch::query()->sole();
    $page->assertRedirect(route('matches.room', $match));
    expect($match->challenged_lineup_id)->toBe($theirs->id)->and($match->best_of)->toBe(3)->and($match->rated)->toBeFalse();
});

test('step 4: a month word reads in German or English, an unknown one or a day past the month end is a field error, not a crash; times past the planning window stop on step 4', function () {
    [$mine, $theirs] = wizardPair();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'Europe/Berlin'));

    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->call('next')->assertSet('step', 4);

    foreach (['17 abc 2026', '31 Feb 2026', '31 Nov 2026'] as $typed) {
        $page->set('times.0.date', $typed)->call('next')->assertOk()->assertHasErrors(['times.0.date'])->assertSet('step', 4);
    }

    $page->set('times.0.date', '20 Dez 2026')->call('next')
        ->assertHasErrors(['replyTime'])->assertSet('step', 4)
        ->assertSee('Every suggested time has to lie in the future, at most 14 days ahead.');

    $page->set('times.0.date', '14 Nov 2026')->set('replyDate', '13 Nov. 2026')->call('next')
        ->assertHasNoErrors()->assertSet('step', 5);
});

test('send, the invite link and "Invite by link" cannot skip the wizard: each checks the steps before it on the server', function () {
    [$mine, $theirs] = wizardPair();
    $incomplete = Lineup::factory()->mode('3v3')->create(['clan_id' => $mine->clan_id]);

    // Sending straight from step 1, without "Next": step 1 has no lineup yet, nothing goes out.
    Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->set('lineupId', null)->set('opponentId', $theirs->id)
        ->call('send', '[]')
        ->assertSet('step', 1)->assertHasErrors(['lineupId']);
    expect(SeriesMatch::query()->count())->toBe(0);

    // "Invite by link" on step 1 does nothing; on step 2 it re-checks step 1 (an incomplete lineup reopens it).
    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->call('pickLineup', $incomplete->id)
        ->call('inviteByLink')->assertSet('step', 1)->assertSet('viaLink', false);

    $page->call('pickLineup', $mine->id)->call('next')->assertSet('step', 2)
        ->call('pickLineup', $incomplete->id)
        ->call('inviteByLink')->assertSet('step', 1)->assertSet('viaLink', false)->assertHasErrors(['lineupId']);

    // The invite link without "Invite by link", or with a step that no longer holds, makes no link.
    $page->call('createLink')->assertSet('step', 1);
    expect(InviteLink::query()->count())->toBe(0);
});
