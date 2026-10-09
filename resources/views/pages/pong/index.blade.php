{{--
    Proof of Pong's start page in the league's shell (plan "Proof of Pong", P1). Since P9 (user, 2026-10-10: "zu weit
    unten, auf dem Desktop muss zu viel gescrollt werden") the first screen holds the whole way in: a slim title
    banner, the player's figure under it (P3, pong/partials/figures in its lobby form: the pick and a grid that opens
    over the page; resources/js/pong/picker.js keeps the pick in the browser, so it also plays a live match), and the
    two ways to play side by side: the live 1v1 (P2, components/⚡pong-lobby: Looking to play, who is online, invites,
    the running match) and the game against one of the cast's five bots (the form opens it in a new tab, full-screen,
    PongController::bot(); a guest logs in first). Below md the two are a segmented control; it starts on the live 1v1
    when something waits there for the viewer ($liveFirst) or the address asks for it (#pong-live-h, the dock's link).
    The game in short, the Elo ladder and the viewer's latest win to share (P4) sit under the fold. The game's chat
    (P5) follows the play section: a bar below xl, from xl the side column.
--}}
@php
    app(\App\Support\PageMeta::class)->describe('Proof of Pong', __('Proof of Pong, the arcade game of the TWENTY ONE esports league: classic Pong to 21 in Bitcoin meme culture. Play a bot or a live 1v1 for Elo, with meme events every 21st rally.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('pong'));
    $firstBot = $bots[0];
    $segment = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md px-3 text-[14px] font-bold transition-colors';
@endphp
<x-layouts::app :title="'Proof of Pong'" :scripts="['resources/css/pong-picker.css', 'resources/js/pong/picker.js', 'resources/js/gameChannel.js']">
    <div class="chat-rail-host flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="pong-index">
        <section class="flex flex-col gap-4" aria-labelledby="pong-h"
                 x-data="{ way: @js($liveFirst ? 'live' : 'bot') }" x-init="if (location.hash === '#pong-live-h') way = 'live'" data-test="pong-play">
            <h1 id="pong-h" class="sr-only">Proof of Pong</h1>

            {{-- The title is in the art: a slim crop around it, not a hero. --}}
            <div class="relative rounded-lg bg-card shadow-ring">
                <img src="/pong/art/key-title.webp" alt="" width="1600" height="900" class="block aspect-[6/1] max-h-40 w-full rounded-t-lg object-cover object-top sm:aspect-[8/1] sm:object-[50%_4%]" data-test="pong-key-art">
                <div class="px-4 sm:px-6">
                    @include('pong.partials.figures', ['figures' => $figures, 'selected' => null, 'lobby' => true, 'form' => 'pong-play-form'])
                </div>
            </div>

            {{-- Below md: one way at a time, both one tap away. --}}
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-card p-1 shadow-ring md:hidden" data-test="pong-ways">
                <button type="button" x-on:click="way = 'live'" aria-controls="pong-way-live" x-bind:aria-pressed="way === 'live'" aria-pressed="{{ $liveFirst ? 'true' : 'false' }}"
                        @class([$segment, 'bg-raised text-ink shadow-ring' => $liveFirst, 'text-ink-2' => ! $liveFirst]) x-bind:class="{ 'bg-raised text-ink shadow-ring': way === 'live', 'text-ink-2': way !== 'live' }" data-test="pong-way-live">
                    <span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('Live 1v1') }}
                </button>
                <button type="button" x-on:click="way = 'bot'" aria-controls="pong-way-bot" x-bind:aria-pressed="way === 'bot'" aria-pressed="{{ $liveFirst ? 'false' : 'true' }}"
                        @class([$segment, 'bg-raised text-ink shadow-ring' => ! $liveFirst, 'text-ink-2' => $liveFirst]) x-bind:class="{ 'bg-raised text-ink shadow-ring': way === 'bot', 'text-ink-2': way !== 'bot' }" data-test="pong-way-bot">
                    {{ __('Vs bot') }}
                </button>
            </div>

            <div class="grid gap-4 md:grid-cols-[minmax(0,3fr)_minmax(22rem,2fr)] md:items-start lg:gap-6">
                <div id="pong-way-live" @class(['min-w-0', 'max-md:hidden' => ! $liveFirst]) x-bind:class="{ 'max-md:hidden': way !== 'live' }">
                    <livewire:pong-lobby />
                </div>

                <form id="pong-play-form" method="get" action="{{ route('pong.bot') }}" target="_blank" aria-labelledby="pong-bot-h"
                      @class(['flex min-w-0 flex-col gap-4 rounded-lg bg-card p-5 shadow-ring', 'max-md:hidden' => $liveFirst]) x-bind:class="{ 'max-md:hidden': way !== 'bot' }"
                      x-data="{ bot: @js($firstBot['id']) }" data-test="pong-play-form">
                    <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h2 id="pong-bot-h" class="m-0 font-display text-xl font-bold max-md:sr-only">{{ __('Vs bot') }}</h2>
                        <span class="text-[13px] text-ink-2 max-md:hidden">{{ __('Right away, no Elo') }}</span>
                    </span>
                    <fieldset class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
                        {{-- Left to right from the easiest to the end boss; the line under the row names the picked one and its level. --}}
                        <legend class="sr-only">{{ __('Your opponent') }}</legend>
                        <span class="grid grid-cols-5 gap-2">
                            @foreach ($bots as $bot)
                                <label class="relative flex aspect-square min-h-11 cursor-pointer items-end justify-center overflow-hidden rounded-md bg-well shadow-ring transition-shadow has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_2px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-btc" data-test="pong-bot-{{ $bot['id'] }}">
                                    <input type="radio" name="bot" value="{{ $bot['id'] }}" x-model="bot" class="sr-only" @checked($loop->first)>
                                    <img src="/pong/art/por-{{ $bot['id'] }}.webp" alt="{{ $bot['name'] }}" width="72" height="72" class="size-full object-contain object-bottom">
                                </label>
                            @endforeach
                        </span>
                        {{-- The picked bot in words; every one is in the markup, so the first shows before any script. --}}
                        @foreach ($bots as $bot)
                            <span class="flex min-h-14 min-w-0 flex-col gap-0.5" x-show="bot === @js($bot['id'])" @if (! $loop->first) style="display: none" @endif data-test="pong-bot-detail">
                                <span class="flex flex-wrap items-baseline gap-x-2"><b class="text-[15px] text-ink">{{ $bot['name'] }}</b><span class="text-[12px] font-bold text-btc-hi">{{ match ($bot['id']) { 'lagarde' => __('End boss'), 'schnabel' => __('Pre-boss'), default => __('Level :level', ['level' => $bot['level']]) } }}</span></span>
                                <span class="text-[13px] text-ink-2">{{ $bot['tagline'] }}</span>
                            </span>
                        @endforeach
                    </fieldset>
                    <span class="flex flex-col gap-2">
                        <button type="submit" class="btn-p inline-flex min-h-12 w-full cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc" data-test="pong-play-bot">
                            <x-icon name="play" :size="18" />{{ __('Play vs bot') }}
                        </button>
                        <span class="text-center text-xs text-ink-2"><span class="md:hidden">{{ __('Right away, no Elo') }}. </span>{{ auth()->check() ? __('Opens in a new tab, full-screen.') : __('You log in first, then the game opens in a new tab.') }}</span>
                    </span>
                </form>
            </div>
        </section>

        {{-- The game's public chat (P5), right under the way to play as on every game page: a bar below xl, from xl the side column (.chat-rail). --}}
        <div class="chat-rail"><livewire:game-channel :game="\App\Games\ProofOfPong::SLUG" wire:key="game-channel-proof-of-pong" /></div>

        {{-- Under the fold: the game in short and the ladder. --}}
        <section class="flex flex-col gap-4 rounded-lg bg-card px-5 py-4 shadow-ring sm:flex-row sm:items-center sm:justify-between" aria-labelledby="pong-about-h" data-test="pong-about">
            <h2 id="pong-about-h" class="sr-only">{{ __('The game in short') }}</h2>
            <span class="flex min-w-0 flex-col gap-3">
                <p class="m-0 max-w-[60ch] text-[14px] text-ink-2">{{ __('Classic Pong with a Bitcoin twist: first to 21 points, and every 21st rally a meme event for both sides.') }}</p>
                <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2" aria-label="{{ __('The game in short') }}">
                    <li class="rounded-tag bg-well px-2 py-1 shadow-ring">{{ __('First to :points', ['points' => $rules->pointsToWin]) }}</li>
                    <li class="rounded-tag bg-well px-2 py-1 shadow-ring">{{ __('Two points ahead') }}</li>
                    <li class="rounded-tag bg-well px-2 py-1 shadow-ring">{{ __('Halving, Brrr, Pizza Day') }}</li>
                </ul>
            </span>
            <a href="{{ route('pong.ladder') }}" class="inline-flex min-h-11 shrink-0 items-center gap-2 self-start rounded-md bg-well px-3 text-[13px] font-bold text-ink shadow-ring hover:text-ink sm:self-auto" data-test="pong-ladder-link"><x-icon name="ladder" :size="16" />{{ __('Elo ladder') }}</a>
        </section>

        @if ($lastWin !== null)
            {{-- The viewer's latest won live match (P4): a moment to post on Nostr (SharePosts `pong`). --}}
            <section aria-labelledby="pong-win-h" class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg bg-card px-4 py-4 shadow-ring lg:px-6" data-test="pong-last-win">
                <x-game-cover :game="\App\Games\ProofOfPong::SLUG" size="thumb" class="w-16 shrink-0 rounded-tag" />
                <span class="flex min-w-0 grow flex-col gap-0.5">
                    <h2 id="pong-win-h" class="m-0 text-[15px] font-bold">{{ __('Your latest win') }}</h2>
                    <span class="text-[13px] text-ink-2">{{ __(':score against :name', ['score' => max($lastWin->score()).':'.min($lastWin->score()), 'name' => $lastWin->player(1 - (int) $lastWin->sideOf(auth()->user()))?->displayName() ?? __('Deleted account')]) }}</span>
                </span>
                <livewire:share-button type="pong" :moment="$lastWin->ulid" :label="__('Post the win')" :wire:key="'pong-share-'.$lastWin->ulid" />
            </section>
        @endif
    </div>
</x-layouts::app>
