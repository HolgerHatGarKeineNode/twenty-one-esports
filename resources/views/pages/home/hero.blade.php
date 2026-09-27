{{--
    The next tournament as home's hero: the game's cover, the name, when it
    starts in Berlin time with the time left, the pot when the league has one
    (TournamentPrizePool), and the seats: one tile per place, the face of
    whoever took it or a dashed open seat, best seed first. Up to two more
    open tournaments sit under it as small cards.

    $cup, $more: HomeHub::cups() entries.
--}}
@php
    use App\Support\Cards\ShareCard;
    use App\Support\GameNames;
    use App\Support\LeagueTime;

    $tournament = $cup['tournament'];
    $places = $cup['places'];
    $seats = $cup['seats'];
    $cta = $cup['cta'];
    $show = route('tournaments.show', $tournament);
    $signup = route('tournaments.signup', $tournament);
    $start = $tournament->starts_at;
    // Up to SEATS tiles: the faces, then the open seats; a bigger field ends in one "+N" tile.
    $tiles = min($places['places'], \App\Support\Engagement\HomeHub::SEATS);
    $overflow = $places['places'] > $tiles;
    $faces = array_slice($seats, 0, $overflow ? min(count($seats), $tiles - 1) : $tiles);
    $open = max(0, ($overflow ? $tiles - 1 : $tiles) - count($faces));
    $rest = $places['places'] - count($faces) - $open;
    $pot = $cup['pot'];
@endphp

<section aria-labelledby="hero-name" class="grid gap-3 px-4 pt-4 lg:grid-cols-[minmax(0,8fr)_minmax(0,7fr)] lg:items-center lg:gap-12 lg:px-12 lg:pt-8" data-test="home-hero" data-tournament="{{ $tournament->id }}">
    {{-- The poster, with the game and the pot on it (chips on their own ground, never text on the art) --}}
    <figure class="hh-cover relative m-0 lg:order-1" data-test="hero-cover">
        <x-game-cover :game="$tournament->game" size="hero" loading="eager" class="w-full rounded-card" />
        <figcaption class="absolute inset-x-2 bottom-2 flex flex-wrap items-center gap-1.5 text-xs font-bold lg:inset-x-4 lg:bottom-4">
            <span class="inline-flex h-7 items-center rounded-tag bg-ground/90 px-2.5 text-ink">{{ GameNames::mode($tournament->game, $tournament->mode) }}, {{ $tournament->format->label() }}</span>
            @if ($pot !== null)
                <span class="inline-flex h-7 items-center gap-1 rounded-tag bg-btc px-2.5 whitespace-nowrap text-on-btc" data-test="hero-pot">
                    <x-icon name="bolt" :size="14" />
                    {{ __(':sats sats pot', ['sats' => ShareCard::sats($pot['sats'])]) }}
                </span>
            @endif
        </figcaption>
    </figure>

    <div class="flex min-w-0 flex-col gap-3 lg:order-2 lg:gap-5">
        <div class="flex flex-col gap-1 lg:gap-3">
            <h2 id="hero-name" class="m-0 font-display text-[22px] leading-[1.15] font-bold break-words sm:text-3xl xl:text-[44px] xl:leading-[1.08]">
                <a href="{{ $show }}" class="text-ink hover:text-btc-hi" data-test="hero-name">{{ $tournament->name }}</a>
            </h2>

            <p class="m-0 flex flex-wrap items-baseline gap-x-3 text-xs leading-[1.5] text-ink-2 lg:text-sm" data-test="hero-when">
                <time datetime="{{ $start->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="text-ink" title="{{ LeagueTime::zoneName() }}">{{ LeagueTime::stamp($start) }}</time>
                @if ($cup['startsIn'])
                    <span x-data="startsIn({ at: {{ $cup['startsIn']['ms'] }}, labels: @js([
                        'dh' => __('starts in :d d :h h'), 'hm' => __('starts in :h h :m min'), 'm' => __('starts in :m min'), 'soon' => __('starts in under a minute'),
                    ]) })" x-text="text" data-test="hero-starts-in">{{ $cup['startsIn']['text'] }}</span>
                @endif
            </p>
        </div>

        {{-- The seats: who is in, best seed first, then the open seats --}}
        <ul class="hh-seats m-0 list-none p-0" aria-label="{{ __(':taken of :places spots taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}" data-test="hero-seats">
            @foreach ($faces as $index => $seat)
                <li @class(['hh-seat is-taken', 'is-you' => $seat['you']]) style="--i: {{ $index }}" title="{{ $seat['name'] }}" data-test="hero-seat">
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
                        <a href="{{ $signup }}" class="flex size-full items-center justify-center text-ink-2 hover:text-btc-hi" aria-label="{{ __('Take your seat') }}" data-test="hero-open-seat"><x-icon name="user" :size="16" /></a>
                    @endif
                </li>
            @endfor
            @if ($overflow)
                <li class="hh-seat is-open text-xs font-bold text-ink-2" data-test="hero-seats-rest">+{{ $rest }}</li>
            @endif
        </ul>

        <div class="flex items-center gap-4">
            @switch($cta)
                @case('open')
                    <a href="{{ $signup }}" class="btn-p inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="hero-cta">
                        <x-icon name="tournaments" :size="20" />{{ __('Sign up') }}
                    </a>
                    @break
                @case('entered')
                    <a href="{{ $show }}" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-win-tint px-6 font-display text-[15px] font-bold text-win shadow-ring-win hover:text-win" data-test="hero-cta">
                        <x-icon name="check" :size="20" />{{ __('You’re in') }}
                    </a>
                    @break
                @default
                    <a href="{{ $show }}" class="btn-p inline-flex min-h-12 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="hero-cta">
                        <x-icon name="eye" :size="20" />{{ __('See the tournament') }}
                    </a>
            @endswitch
            <p class="m-0 text-[13px] leading-tight text-ink-2" data-test="hero-count">
                <b class="font-display text-xl text-ink tabular-nums lg:text-2xl" x-data="countUp({{ $places['taken'] }})" data-count="{{ $places['taken'] }}" data-test="hero-taken">{{ $places['taken'] }}</b>
                {{ __('of :places in', ['places' => $places['places']]) }}
            </p>
        </div>
    </div>
</section>

@if ($more !== [])
    <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 px-4 sm:grid-cols-2 lg:gap-4 lg:px-12" data-test="hero-more">
        @foreach ($more as $next)
            @php($nextTournament = $next['tournament'])
            <li>
                <a href="{{ route('tournaments.show', $nextTournament) }}" class="grid grid-cols-[96px_minmax(0,1fr)] items-center gap-3 rounded-card bg-card p-2 pr-3 text-ink shadow-ring hover:bg-row-hover hover:text-ink" data-test="hero-more-cup">
                    <x-game-cover :game="$nextTournament->game" size="thumb" class="w-24 rounded-tag" />
                    <span class="flex min-w-0 flex-col gap-1">
                        <b class="truncate font-display text-sm">{{ $nextTournament->name }}</b>
                        <span class="flex min-w-0 items-center gap-2 text-xs text-ink-2">
                            <span class="truncate">{{ LeagueTime::stamp($nextTournament->starts_at) }}</span>
                        </span>
                        <span class="flex items-center gap-2 text-xs text-ink-2">
                            @if ($next['seats'] !== [])
                                <span class="flex shrink-0 -space-x-1.5" aria-hidden="true">
                                    @foreach (array_slice(array_filter(array_column($next['seats'], 'user')), 0, 4) as $face)
                                        <x-avatar :user="$face" :size="20" class="rounded-full ring-2 ring-card" />
                                    @endforeach
                                </span>
                            @endif
                            <span>{{ __(':taken of :places in', ['taken' => $next['places']['taken'], 'places' => $next['places']['places']]) }}</span>
                        </span>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
