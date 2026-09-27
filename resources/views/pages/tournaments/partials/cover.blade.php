{{--
    The game's cover art as the tournament's poster (tournament page hero,
    sign-up page). Without a cover the game-name tile stands in: the game's
    colour pair from app.css, its icon and its name set in the display face.

    $tournament: the tournament
    $class: size and placement from the caller

    Interface: App\Games\GameRegistry::cover() (URL or null). When the
    <x-game-cover> component lands, this partial becomes a call to it.
--}}
@php
    $coverUrl = app(\App\Games\GameRegistry::class)->cover($tournament->game);
    $chess = $tournament->game === 'chess';
    $gameName = $chess ? __('Chess') : (app(\App\Games\GameRegistry::class)->find($tournament->game)?->name() ?? $tournament->game);
    $modeName = $chess ? ($tournament->mode === 'correspondence' ? __('Daily') : __('Blitz 5+3')) : $tournament->mode;
@endphp
<figure class="tl-poster m-0 {{ $class ?? '' }}" data-test="game-cover" data-cover="{{ $coverUrl ? 'art' : 'tile' }}">
    @if ($coverUrl)
        <img src="{{ $coverUrl }}" alt="{{ __(':game cover art', ['game' => $gameName]) }}" width="460" height="215" decoding="async" fetchpriority="high"
             class="block aspect-[460/215] h-auto w-full rounded-card object-cover">
    @else
        <span role="img" aria-label="{{ $gameName }}" @class([
            'relative flex aspect-[460/215] w-full items-end overflow-hidden rounded-card p-5',
            'bg-linear-135 from-chess-deep to-chess-deep-2' => $chess,
            'bg-linear-135 from-rl-deep to-rl-deep-2' => ! $chess,
        ])>
            <x-icon :name="$chess ? 'pawn' : 'rocket-league'" :size="160" class="absolute -top-6 -right-6 text-white opacity-15" />
            <span class="relative flex flex-col gap-1">
                <span class="font-display text-[28px] leading-none font-bold text-white sm:text-[36px]">{{ $gameName }}</span>
                <span class="text-[13px] font-bold text-white">{{ $modeName }}</span>
            </span>
        </span>
    @endif
</figure>
