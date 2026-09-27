@props(['tournament', 'headingId' => 'poster-h'])

{{--
    A game's next tournament as a poster at the top of its page (user,
    2026-09-28: the block was "zu textlich", "lieblos hingeklatscht"). Built
    from home's hero (pages/home/hero, HomeHub::cupOf()): the game's cover
    with its chips on their own ground under it (never text on the art),
    the name big, a live countdown to the start, the pot as a big number,
    the seats with the faces of whoever signed up, and one call to action.
    The rules are a link, not a paragraph.
--}}
@php
    use App\Support\Cards\ShareCard;
    use App\Support\GameNames;
    use App\Support\LeagueTime;

    $cup = (new \App\Support\Engagement\HomeHub(auth()->user()))->cupOf($tournament);
    $places = $cup['places'];
    $seats = $cup['seats'];
    $cta = $cup['cta'];
    $pot = $cup['pot'];
    $show = route('tournaments.show', $tournament);
    $signup = route('tournaments.signup', $tournament);

    // Up to 16 tiles: the faces, then the open seats; a bigger field ends in one "+N" tile.
    $tiles = min($places['places'], 16);
    $overflow = $places['places'] > $tiles;
    $faces = array_slice($seats, 0, $overflow ? min(count($seats), $tiles - 1) : $tiles);
    $open = max(0, ($overflow ? $tiles - 1 : $tiles) - count($faces));
    $rest = $places['places'] - count($faces) - $open;

    // The live countdown to the start, first frame from the server (tournamentLanding.js countdown()).
    $seconds = max(0, (int) now()->diffInSeconds($tournament->starts_at, false));
    $days = intdiv($seconds, 86400);
    $clock = ($days > 0 ? trans_choice(':count day|:count days', $days).' ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
@endphp

<section aria-labelledby="{{ $headingId }}" {{ $attributes->class('grid gap-4 rounded-card bg-card p-4 shadow-ring lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-center lg:gap-8 lg:p-6') }} data-test="next-tournament" data-tournament="{{ $tournament->id }}">
    <div class="flex min-w-0 flex-col gap-2">
        <a href="{{ $show }}" class="hh-cover block" tabindex="-1" aria-hidden="true">
            <x-game-cover :game="$tournament->game" size="card" class="w-full rounded-md" data-test="next-tournament-cover" />
        </a>
        <p class="m-0 flex flex-wrap gap-1.5 text-xs font-bold">
            <span class="inline-flex h-7 items-center gap-1.5 rounded-tag bg-btc-chip px-2.5 text-btc-hi"><x-icon name="trophy" :size="14" />{{ __('Next tournament') }}</span>
            <span class="inline-flex h-7 items-center rounded-tag bg-raised px-2.5 text-ink-2">{{ GameNames::mode($tournament->game, $tournament->mode) }}</span>
            <span class="inline-flex h-7 items-center rounded-tag bg-raised px-2.5 text-ink-2">{{ $tournament->format->label() }}</span>
        </p>
    </div>

    <div class="flex min-w-0 flex-col gap-4 lg:gap-5">
        <h2 id="{{ $headingId }}" class="m-0 font-display text-[26px] leading-[1.1] font-bold break-words sm:text-[32px] xl:text-[40px]">
            <a href="{{ $show }}" class="text-ink hover:text-btc-hi" data-test="next-tournament-name">{{ $tournament->name }}</a>
        </h2>

        {{-- Two numbers: when it starts, and what there is to win --}}
        <div class="grid grid-cols-2 gap-3">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-xs text-ink-2">{{ __('Starts in') }}</span>
                <span class="font-display text-xl leading-none font-bold tabular-nums sm:text-2xl" role="timer" x-data="countdown({ at: {{ (int) $tournament->starts_at->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="next-tournament-countdown">{{ $clock }}</span>
                <x-league-time :at="$tournament->starts_at" class="text-xs text-ink-2" data-test="next-tournament-start" />
            </div>
            @if ($pot !== null)
                <div class="flex min-w-0 flex-col gap-1" data-test="next-tournament-pot">
                    <span class="text-xs text-ink-2">{{ __('Prize pool') }}</span>
                    <span class="flex flex-wrap items-baseline gap-x-1.5 gap-y-1 font-display leading-none font-bold text-btc tabular-nums"><span class="text-2xl whitespace-nowrap sm:text-[36px]">{{ ShareCard::sats($pot['sats']) }}</span><span class="text-sm">{{ __('sats') }}</span></span>
                    @if (($pot['left'] ?? $pot['sats']) !== $pot['sats'])
                        <span class="text-xs text-ink-2">{{ __(':left of :total sats still to be won', ['left' => ShareCard::sats($pot['left']), 'total' => ShareCard::sats($pot['sats'])]) }}</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- The seats: who is in, best seed first, then the open seats --}}
        <ul class="hh-seats is-wide m-0 list-none p-0" aria-label="{{ __(':taken of :places spots taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}" data-test="next-tournament-seats">
            @foreach ($faces as $index => $seat)
                <li @class(['hh-seat is-taken', 'is-you' => $seat['you']]) style="--i: {{ $index }}" title="{{ $seat['name'] }}" data-test="next-tournament-seat">
                    @if ($seat['user'])
                        <x-avatar :user="$seat['user']" :size="64" class="rounded-none" />
                    @elseif ($seat['clan'])
                        <x-clan-tag :clan="$seat['clan']" :tile="64" class="size-full" />
                    @else
                        <span class="flex size-full items-center justify-center text-xs text-ink-2">{{ mb_substr($seat['name'], 0, 2) }}</span>
                    @endif
                </li>
            @endforeach
            @for ($seat = 0; $seat < $open; $seat++)
                <li class="hh-seat is-open">
                    @if ($seat === 0 && $cta === 'open')
                        <a href="{{ auth()->check() ? $signup : route('login') }}" class="flex size-full items-center justify-center text-ink-2 hover:text-btc-hi" aria-label="{{ __('Take your seat') }}"><x-icon name="user" :size="16" /></a>
                    @endif
                </li>
            @endfor
            @if ($overflow)
                <li class="hh-seat is-open text-xs font-bold text-ink-2">+{{ $rest }}</li>
            @endif
        </ul>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            @switch($cta)
                @case('open')
                    <a href="{{ auth()->check() ? $signup : route('login') }}" class="btn-p inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="register">
                        <x-icon name="tournaments" :size="20" />{{ __('Sign up') }}
                    </a>
                    @break
                @case('entered')
                    <a href="{{ $show }}" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-win-tint px-6 font-display text-[15px] font-bold text-win shadow-ring-win hover:text-win" data-test="register">
                        <x-icon name="check" :size="20" />{{ __('You’re in') }}
                    </a>
                    @break
                @default
                    <a href="{{ $show }}" class="btn-p inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="register">
                        <x-icon name="eye" :size="20" />{{ __('See the tournament') }}
                    </a>
            @endswitch
            <p class="m-0 text-[13px] leading-tight text-ink-2" data-test="next-tournament-places">
                <b class="font-display text-xl text-ink tabular-nums" x-data="countUp({{ $places['taken'] }})">{{ $places['taken'] }}</b>
                {{ __('of :places in', ['places' => $places['places']]) }}
            </p>
            <a href="{{ $show }}#how-h" class="inline-flex min-h-11 items-center text-[13px] sm:ml-auto" data-test="next-tournament-rules">{{ __('Rules') }}</a>
        </div>
    </div>
</section>
