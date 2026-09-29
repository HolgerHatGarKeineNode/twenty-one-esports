{{--
    The viewer's open match in a casual cup (P25): opponent, deadline, the
    league's auto slot, and the one action that fits. Chess and the board
    games (plan "Mühle und Dame", P5): open the game,
    accept the opponent's invite, or invite them. Rocket League and EA
    Sports FC (S3): the agreed time and the way to the match room, or the
    times to propose or accept (partials/cup-schedule).
--}}
@php
    $tz = auth()->user()?->timezone ?? $cup['zone'];
    $format = fn ($at) => $at->copy()->setTimezone($tz)->translatedFormat('D j M, H:i');
@endphp
<section aria-labelledby="cup-match-h" class="mx-4 flex flex-col gap-3 rounded-card bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] lg:mx-12 lg:px-6" data-test="cup-match">
    <h2 id="cup-match-h" class="m-0 text-[15px] font-bold">{{ __('Your cup match: :opponent', ['opponent' => $cup['opponent']]) }}</h2>
    @if ($cup['series'])
        @include('pages.tournaments.partials.cup-schedule', ['cup' => $cup, 'error' => $error, 'format' => $format])
    @else
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2" data-test="cup-match-when">
            @if ($cup['evening'])
                {{ __('Tonight\'s round: the league starts your game on the board as soon as you are both free. Stay online.') }}
            @else
                {{ __('Play by :deadline. When you are both online, start it here; otherwise the league starts your game at :slot.', ['deadline' => $format($cup['endsAt']), 'slot' => $format($cup['slot'])]) }}
            @endif
        </p>
        @if ($error !== '')
            <p class="m-0 text-[13px] text-loss" role="alert" data-test="cup-match-error">{{ $error }}</p>
        @endif
        <div class="flex flex-wrap items-center gap-3">
            @if ($cup['game'])
                <x-button :href="$cup['gameUrl']" icon="pawn" data-test="cup-match-game">{{ __('Open your game') }}</x-button>
            @elseif ($cup['incoming'])
                <x-button type="button" wire:click="acceptCupInvite({{ $cup['incoming']->id }})" icon="pawn" data-test="cup-match-accept">{{ __(':name is ready: play now', ['name' => $cup['opponent']]) }}</x-button>
            @elseif ($cup['outgoing'])
                <span class="text-[13px] text-ink-2" data-test="cup-match-waiting">{{ __('Invite sent. Waiting for :name to accept.', ['name' => $cup['opponent']]) }}</span>
            @else
                <x-button type="button" wire:click="playCupMatch" icon="pawn" data-test="cup-match-play">{{ __('Play your cup match') }}</x-button>
            @endif
        </div>
    @endif
</section>
