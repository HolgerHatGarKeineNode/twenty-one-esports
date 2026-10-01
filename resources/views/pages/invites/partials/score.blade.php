{{--
    The landing of a "beat my time" link (InviteLinkType::Score): who
    challenges, the game's own art, their best of this week, and one button
    into the game. No seat and no opponent: nothing is taken here, the
    friend plays the game and the week's leaderboard compares the times.
    The inviter gets the share panel. Needs $link, $state, $openUntil.
--}}
@php
    $copy = new \App\Support\Invites\InviteCopy($link);
    $viewer = auth()->user();
    $inviter = $link->inviter;
    $name = $inviter->displayName();
    $slug = (string) $link->option('game');
    $best = $copy->best();
    $mine = $viewer !== null && $inviter->is($viewer);
    $closed = in_array($state, ['expired', 'revoked'], true);
    $play = \App\Support\GameNames::page($slug);
    $heading = match (true) {
        $state === 'own' => __('Your challenge is ready to share'),
        $closed => __('This challenge has ended'),
        default => $copy->headline(),
    };
    $primary = 'btn-p inline-flex min-h-14 w-full cursor-pointer items-center justify-center gap-2.5 rounded-lg bg-btc px-5 text-[15px] font-bold text-on-btc hover:text-on-btc';
    $secondary = 'btn-w inline-flex min-h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-lg border border-line bg-well px-4 text-[13px] text-ink hover:text-ink';
@endphp

<div class="mx-auto flex w-full max-w-[1080px] grow flex-col gap-6 px-4 pb-10 lg:pb-16" data-test="invite-landing" data-state="{{ $state }}" data-kind="score">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-12">
        <div class="flex min-w-0 flex-col gap-5 lg:gap-6">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex h-7 items-center gap-2 rounded-md border border-line bg-well px-2.5 text-xs">
                    <x-icon :name="app(\App\Games\GameRegistry::class)->find($slug)?->assets()->icon ?? 'trophy'" :size="14" />{{ $copy->gameChip() }}
                </span>
                @if ($closed)
                    <span class="inline-flex h-7 items-center gap-1.5 rounded-md border border-btc-deep bg-btc-chip px-2.5 text-xs font-bold text-btc-hi"><x-icon name="clock" :size="14" />{{ $state === 'revoked' ? __('Cancelled') : __('Expired') }}</span>
                @endif
                @if ($mine)
                    <span class="inline-flex h-7 items-center gap-1.5 rounded-md bg-btc-chip px-2.5 text-xs text-btc-hi"><x-icon name="link" :size="14" />{{ __('Your invite') }}</span>
                @endif
            </div>

            <h1 class="m-0 font-display text-[30px] leading-[1.08] font-bold break-words lg:text-[48px]" data-test="invite-heading">{{ $heading }}</h1>

            {{-- The game's own art and the time to beat; never a board or a seat. --}}
            <div @class(['overflow-hidden rounded-lg bg-card', 'opacity-45 grayscale' => $closed]) data-test="invite-stage">
                <x-game-cover :game="$slug" size="header" loading="eager" class="w-full" />
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-4 lg:px-6">
                    <span class="flex min-w-0 items-center gap-3">
                        <x-avatar :user="$inviter" :size="48" class="size-12! shrink-0 rounded-lg" />
                        <x-player-link :user="$inviter" class="inline-flex min-h-11 min-w-0 items-center font-display text-base font-bold"><span class="truncate">{{ $name }}</span></x-player-link>
                    </span>
                    <span class="flex flex-col items-end gap-0.5 text-right">
                        <span class="text-xs text-ink-2">{{ __('Best this week') }}</span>
                        <b class="font-display text-[28px] leading-none tabular-nums lg:text-[36px]" data-test="invite-time">{{ $best ?? '–' }}</b>
                    </span>
                </div>
            </div>
        </div>

        <aside class="flex flex-col gap-4 self-start rounded-lg bg-card px-4 py-6 lg:px-8 lg:py-8" data-test="invite-panel">
            @if ($state === 'own')
                @include('pages.invites.partials.share', [
                    'shareHeading' => __('Share your challenge'),
                    'shareText' => $copy->cardQuestion().' '.$copy->cardSubline(),
                    'note' => __('Open until :time.', ['time' => $openUntil]),
                ])
            @else
                <h2 class="m-0 font-display text-2xl font-bold">{{ $closed || $best === null ? __('Play :game', ['game' => $copy->game()]) : __('Beat :time', ['time' => $best]) }}</h2>
                <a href="{{ $play }}" class="{{ $primary }}" data-test="invite-play"><x-icon name="bolt" :size="18" />{{ __('Play :game', ['game' => $copy->game()]) }}</a>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ __('The fastest time of the week counts. Casual, nothing to sign.') }}</p>
                @if ($mine)
                    <a href="{{ route('invites.create', ['game' => $slug]) }}" class="{{ $secondary }}">{{ __('Make a new one') }}</a>
                @endif
            @endif
        </aside>
    </div>
</div>
