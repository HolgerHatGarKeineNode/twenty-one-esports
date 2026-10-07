{{--
    Play now: every game as its cover with its main action (the actions of
    the navigation, App\Support\Navigation\ShellNavigation, so home never
    promises what the menus do not reach), the live count on chess, and the
    invite by link under the tiles. With no tournament open it is home's
    hero (`stage`), right under the header.

    $games: ShellNavigation::games(); $live: HomeHub::live(); $stage: bool.
--}}
@php
    $liveChess = $live['live'] + $live['daily'];
@endphp

<section aria-labelledby="play-h" @class(['flex flex-col gap-4 px-4 lg:gap-5 lg:px-12', 'pt-5 lg:pt-8' => $stage]) data-test="play-now" @if ($stage) data-stage @endif>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="play-h" @class(['m-0 font-display font-bold', 'text-2xl lg:text-4xl' => $stage, 'text-xl lg:text-2xl' => ! $stage])>{{ __('Play now') }}</h2>
        @guest
            <a href="{{ route('login', ['then' => 'play']) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold" data-test="play-login">{{ __('Log in to play') }}</a>
        @endguest
    </div>

    <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0 lg:grid-cols-4 lg:gap-5">
        @foreach ($games as $game)
            @php
                $challenge = collect($game['actions'])->firstWhere('key', 'challenge');
                $primary = $game['slug'] !== 'chess' && $challenge !== null ? $challenge : $game['actions'][0];
                $daily = collect($game['actions'])->firstWhere('key', 'daily');
            @endphp
            <li class="hh-tile flex min-w-0 flex-col overflow-hidden rounded-card bg-card" style="--game: {{ $game['colour'] }}" data-test="play-tile" data-game="{{ $game['slug'] }}">
                <a href="{{ $game['page'] }}" @navigate($game['page']) class="group flex flex-col text-ink hover:text-ink">
                    <x-game-cover :game="$game['slug']" size="card" :loading="$stage && $loop->index < 2 ? 'eager' : 'lazy'" class="w-full" />
                    <span class="flex min-w-0 flex-wrap items-center justify-between gap-x-2 gap-y-1 px-3 pt-3 lg:px-4">
                        <span class="flex min-w-0 flex-col"><b class="min-w-0 font-display text-sm leading-[1.25] break-words group-hover:text-btc-hi lg:text-base">{{ $game['name'] }}</b><x-game-credit :game="$game['slug']" :link="false" /></span>
                        @if ($game['slug'] === 'chess' && $liveChess > 0)
                            <span class="flex shrink-0 items-center gap-1.5 text-xs text-win" data-test="play-live"><span class="size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>{{ __(':count live', ['count' => $liveChess]) }}</span>
                        @endif
                    </span>
                </a>
                <span class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 p-3 lg:p-4">
                    <a href="{{ $primary['href'] }}" @navigate($primary['href']) class="btn-p inline-flex h-11 min-w-0 grow items-center justify-center gap-2 rounded-md bg-btc px-3 text-[13px] font-bold text-on-btc hover:text-on-btc" aria-label="{{ $primary['label'] }}, {{ $game['name'] }}" data-test="play-cta">
                        <x-icon :name="$primary['icon']" :size="16" class="shrink-0" /><span class="truncate sm:hidden">{{ $primary['short'] }}</span><span class="truncate max-sm:hidden">{{ $primary['label'] }}</span>
                    </a>
                    @if ($daily)
                        <a href="{{ $daily['href'] }}" @navigate($daily['href']) class="inline-flex min-h-11 items-center text-[13px] max-lg:hidden" data-test="play-daily">{{ $daily['short'] }}</a>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    {{-- Bring a friend: right under the games --}}
    <livewire:invite-link place="home" />
</section>
